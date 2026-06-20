<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeArchiveColumns('redmine_tic_reportes');
        $this->normalizeArchiveColumns('redmine_mantencion_reportes');
        $this->ensureEstadoIndex('redmine_tic_reportes');
    }

    public function down(): void
    {
        $this->restoreArchiveColumns('redmine_tic_reportes');
        $this->restoreArchiveColumns('redmine_mantencion_reportes');
    }

    private function normalizeArchiveColumns(string $table): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        if (Schema::hasColumn($table, 'archivado_at')) {
            DB::table($table)
                ->whereNotNull('archivado_at')
                ->update(['estado' => 'archivado']);
        }

        $this->dropIndexIfExists($table, 'idx_reportes_modulo_archivado_fecha');

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $columns = [];
            if (Schema::hasColumn($table, 'archivado_por')) {
                $columns[] = 'archivado_por';
            }
            if (Schema::hasColumn($table, 'archivado_at')) {
                $columns[] = 'archivado_at';
            }

            if ($columns !== []) {
                $blueprint->dropColumn($columns);
            }
        });
    }

    private function restoreArchiveColumns(string $table): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (!Schema::hasColumn($table, 'archivado_por')) {
                $column = $blueprint->string('archivado_por', 255)->nullable();
                if (Schema::hasColumn($table, 'procesado_at')) {
                    $column->after('procesado_at');
                }
            }
            if (!Schema::hasColumn($table, 'archivado_at')) {
                $blueprint->dateTime('archivado_at')->nullable()->after('archivado_por');
            }
        });

        if (Schema::hasColumn($table, 'estado') && Schema::hasColumn($table, 'archivado_at')) {
            DB::table($table)
                ->where('estado', 'archivado')
                ->update([
                    'archivado_por' => 'estado',
                    'archivado_at' => DB::raw('COALESCE(actualizado_at, NOW())'),
                ]);
        }
    }

    private function ensureEstadoIndex(string $table): void
    {
        if (!Schema::hasTable($table) || $this->indexExists($table, 'idx_reportes_modulo_estado_fecha')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->index(['modulo_id', 'estado', 'fecha'], 'idx_reportes_modulo_estado_fecha');
        });
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (!$this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
        } catch (Throwable) {
            return false;
        }
    }
};
