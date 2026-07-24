<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S31 — Drop dead columns and migration-artifact tables identified in full DB audit.
 *
 * Dropped columns (evidence: 0/N populated, never queried by runtime code):
 *   - usuarios_nova.email          → 0/58 populated, never read in any DB query
 *   - integraciones_usuario.metadata → 0/69 populated, never written or read
 *   - integraciones_usuario.chat_id  → 0/69 populated (Telegram migrated to usuarios_nova.telegram_id_chat in S19)
 *   - modulos_nova.activo            → written on INSERT only, never read; habilitado is the read path
 *
 * Dropped tables:
 *   - _nova_column_backups → pure migration artifact (1456 rows from S25 Phase 2 column backups), no runtime reader
 *
 * Schema fixes:
 *   - configuraciones_modulo.actualizado_at → add ON UPDATE current_timestamp() (missing from original DDL)
 *   - nova_audit_logs.contexto              → change longtext → json for DB-level validation
 *
 * All down() operations restore the exact original structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. usuarios_nova.email — 0/58 populated, never queried
        if (Schema::hasColumn('usuarios_nova', 'email')) {
            Schema::table('usuarios_nova', function (Blueprint $table): void {
                $table->dropColumn('email');
            });
        }

        // 2. integraciones_usuario.metadata — 0/69 populated, never used
        if (Schema::hasColumn('integraciones_usuario', 'metadata')) {
            Schema::table('integraciones_usuario', function (Blueprint $table): void {
                $table->dropColumn('metadata');
            });
        }

        // 3. integraciones_usuario.chat_id — Telegram migrated to usuarios_nova.telegram_id_chat (S19); 0/69 populated
        if (Schema::hasColumn('integraciones_usuario', 'chat_id')) {
            // Existing databases may use the audited legacy name or Laravel's derived name.
            foreach (['idx_integraciones_chat_id', 'integraciones_usuario_chat_id_index'] as $index) {
                Schema::whenTableHasIndex('integraciones_usuario', $index, function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }

            Schema::table('integraciones_usuario', function (Blueprint $table): void {
                $table->dropColumn('chat_id');
            });
        }

        // 4. modulos_nova.activo — written on INSERT but never read; habilitado is the actual read/write path
        if (Schema::hasColumn('modulos_nova', 'activo')) {
            // The clean migration derives this name; legacy databases may use the audited name.
            foreach (['idx_modulos_nova_activo', 'modulos_nova_activo_index'] as $index) {
                Schema::whenTableHasIndex('modulos_nova', $index, function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }

            Schema::table('modulos_nova', function (Blueprint $table): void {
                $table->dropColumn('activo');
            });
        }

        // 5. _nova_column_backups — migration-time backup table, 1456 rows, no runtime reader
        Schema::dropIfExists('_nova_column_backups');

        // 6. configuraciones_modulo.actualizado_at — fix missing ON UPDATE
        DB::statement(
            'ALTER TABLE `configuraciones_modulo` MODIFY `actualizado_at`
             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );

        // 7. nova_audit_logs.contexto — change longtext → json for DB-level JSON validation
        if (Schema::hasColumn('nova_audit_logs', 'contexto')) {
            DB::statement('ALTER TABLE `nova_audit_logs` MODIFY `contexto` JSON NULL');
        }
    }

    public function down(): void
    {
        // Restore usuarios_nova.email
        if (!Schema::hasColumn('usuarios_nova', 'email')) {
            Schema::table('usuarios_nova', function (Blueprint $table): void {
                $table->string('email', 180)->nullable()->after('apellido');
            });
        }

        // Restore integraciones_usuario.metadata
        if (!Schema::hasColumn('integraciones_usuario', 'metadata')) {
            Schema::table('integraciones_usuario', function (Blueprint $table): void {
                $table->longText('metadata')->nullable()->after('valor_secreto');
            });
        }

        // Restore integraciones_usuario.chat_id
        if (!Schema::hasColumn('integraciones_usuario', 'chat_id')) {
            Schema::table('integraciones_usuario', function (Blueprint $table): void {
                $table->string('chat_id', 120)->nullable()->after('usuario_externo');
                $table->index('chat_id', 'idx_integraciones_chat_id');
            });
        }

        // Restore modulos_nova.activo
        if (!Schema::hasColumn('modulos_nova', 'activo')) {
            Schema::table('modulos_nova', function (Blueprint $table): void {
                $table->boolean('activo')->default(true)->after('entrada');
                $table->index('activo', 'idx_modulos_nova_activo');
            });
        }

        // Restore _nova_column_backups
        if (!Schema::hasTable('_nova_column_backups')) {
            Schema::create('_nova_column_backups', function (Blueprint $table): void {
                $table->id();
                $table->string('source_table', 100);
                $table->string('source_column', 100);
                $table->unsignedBigInteger('source_row_id');
                $table->longText('valor')->nullable();
                $table->timestamp('backed_up_at')->useCurrent();
            });
        }

        // Revert configuraciones_modulo.actualizado_at to no ON UPDATE
        DB::statement(
            'ALTER TABLE `configuraciones_modulo` MODIFY `actualizado_at`
             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );

        // Revert nova_audit_logs.contexto to longtext
        if (Schema::hasColumn('nova_audit_logs', 'contexto')) {
            DB::statement('ALTER TABLE `nova_audit_logs` MODIFY `contexto` LONGTEXT NULL');
        }
    }
};
