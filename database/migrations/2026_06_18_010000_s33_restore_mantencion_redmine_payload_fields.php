<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redmine_mantencion_reportes')) {
            return;
        }

        Schema::table('redmine_mantencion_reportes', function (Blueprint $table): void {
            if (! Schema::hasColumn('redmine_mantencion_reportes', 'fuente')) {
                $table->string('fuente', 40)->nullable()->after('modulo_id');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'fuente_id')) {
                $table->string('fuente_id', 160)->nullable()->after('fuente');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'proyecto')) {
                $table->string('proyecto', 180)->nullable()->after('id_core');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'project_id')) {
                $table->string('project_id', 80)->nullable()->after('proyecto');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'tipo_id')) {
                $table->string('tipo_id', 80)->nullable()->after('tipo');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'estado_id')) {
                $table->string('estado_id', 80)->nullable()->after('estado_redmine');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'priority_id')) {
                $table->string('priority_id', 80)->nullable()->after('prioridad');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'unidad_texto')) {
                $table->string('unidad_texto', 255)->nullable()->after('unidad_id');
            }
        });

        if (! $this->indexExists('redmine_mantencion_reportes', 'idx_rm_reportes_fuente_id')) {
            Schema::table('redmine_mantencion_reportes', function (Blueprint $table): void {
                $table->index(['fuente', 'fuente_id'], 'idx_rm_reportes_fuente_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('redmine_mantencion_reportes')) {
            return;
        }

        Schema::table('redmine_mantencion_reportes', function (Blueprint $table): void {
            if ($this->indexExists('redmine_mantencion_reportes', 'idx_rm_reportes_fuente_id')) {
                $table->dropIndex('idx_rm_reportes_fuente_id');
            }

            foreach (['unidad_texto', 'priority_id', 'estado_id', 'tipo_id', 'project_id', 'proyecto', 'fuente_id', 'fuente'] as $column) {
                if (Schema::hasColumn('redmine_mantencion_reportes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return collect(\Illuminate\Support\Facades\DB::select(
                'SHOW INDEX FROM `' . str_replace('`', '``', $table) . '` WHERE Key_name = ?',
                [$index]
            ))->isNotEmpty();
        } catch (Throwable) {
            return false;
        }
    }
};
