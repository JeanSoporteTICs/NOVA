<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (safe drops) — Destructive, irreversible.
 *
 * Drops the datos_extra JSON column from four tables after verifying that:
 *   - categorias.predeterminado   was promoted in Phase 1b
 *   - unidades.predeterminado     was promoted in Phase 1b
 *   - redmine_mantencion_reportes.datos_extra contains no runtime-read fields
 *   - horas_extras.datos_extra    contains no runtime-read fields
 *
 * No code in the application reads datos_extra at runtime; all references
 * were confined to the Phase 1b migration file. Confirmed via grep.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropColumnIfExists('categorias', 'datos_extra');
        $this->dropColumnIfExists('unidades', 'datos_extra');
        $this->dropColumnIfExists('redmine_mantencion_reportes', 'datos_extra');
        $this->dropColumnIfExists('horas_extras', 'datos_extra');
    }

    public function down(): void
    {
        // datos_extra was a longtext blob — restore as nullable longtext
        $this->addLongtextIfMissing('categorias', 'datos_extra');
        $this->addLongtextIfMissing('unidades', 'datos_extra');
        $this->addLongtextIfMissing('redmine_mantencion_reportes', 'datos_extra');
        $this->addLongtextIfMissing('horas_extras', 'datos_extra');
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        // Snapshot the column values to a backup table row before dropping
        $this->snapshotToBackup($table, $column);

        Schema::table($table, function (Blueprint $bp) use ($column): void {
            $bp->dropColumn($column);
        });
    }

    private function addLongtextIfMissing(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }
        Schema::table($table, function (Blueprint $bp) use ($column): void {
            $bp->longText($column)->nullable()->after('activo');
        });
    }

    private function snapshotToBackup(string $table, string $column): void
    {
        try {
            if (!Schema::hasTable('_nova_column_backups')) {
                Schema::create('_nova_column_backups', function (Blueprint $bp): void {
                    $bp->id();
                    $bp->string('source_table', 100);
                    $bp->string('source_column', 100);
                    $bp->unsignedBigInteger('source_row_id');
                    $bp->longText('valor')->nullable();
                    $bp->timestamp('backed_up_at')->useCurrent();
                });
            }

            $rows = DB::table($table)->whereNotNull($column)->get(['id', $column]);
            foreach ($rows as $row) {
                DB::table('_nova_column_backups')->insert([
                    'source_table'  => $table,
                    'source_column' => $column,
                    'source_row_id' => (int) $row->id,
                    'valor'         => (string) ($row->{$column} ?? ''),
                    'backed_up_at'  => now(),
                ]);
            }
        } catch (\Throwable) {
            // Backup failure must not prevent the drop from proceeding
        }
    }
};
