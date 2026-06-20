<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropColumnIfExists('redmine_tic_horas_extra_grupos', 'report_ids');

        $this->dropCatalogOriginColumns('categorias', 'uq_categorias_modulo_nombre_origen', 'categorias_origen_index');
        $this->dropColumnIfExists('categorias', 'datos_extra');

        $this->dropCatalogOriginColumns('unidades', 'uq_unidades_modulo_nombre_origen', 'unidades_origen_index');
        $this->dropColumnIfExists('unidades', 'datos_extra');

        $this->dropColumnIfExists('catalogos_modulo', 'datos_extra');
    }

    public function down(): void
    {
        $this->addJsonColumnIfMissing('catalogos_modulo', 'datos_extra', 'activo');
        $this->addJsonColumnIfMissing('unidades', 'datos_extra', 'activo');
        $this->addOriginColumnIfMissing('unidades', 'normalizado', 'clave_externa');
        $this->addJsonColumnIfMissing('categorias', 'datos_extra', 'activo');
        $this->addOriginColumnIfMissing('categorias', 'normalizado', 'clave_externa');
        $this->addJsonColumnIfMissing('redmine_tic_horas_extra_grupos', 'report_ids', 'hora_fin', 'longText');
    }

    private function dropCatalogOriginColumns(string $table, string $uniqueIndex, string $plainIndex): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'origen')) {
            return;
        }

        $moduleIndex = $table . '_modulo_id_index';
        if (! $this->indexExists($table, $moduleIndex)) {
            Schema::table($table, function (Blueprint $blueprint) use ($moduleIndex): void {
                $blueprint->index('modulo_id', $moduleIndex);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table, $uniqueIndex, $plainIndex): void {
            if ($this->indexExists($table, $uniqueIndex)) {
                $blueprint->dropUnique($uniqueIndex);
            }

            if ($this->indexExists($table, $plainIndex)) {
                $blueprint->dropIndex($plainIndex);
            }

            $blueprint->dropColumn('origen');
        });
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }

    private function addOriginColumnIfMissing(string $table, string $default, string $after): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'origen')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($default, $after): void {
            $blueprint->string('origen', 40)->default($default)->index()->after($after);
        });
    }

    private function addJsonColumnIfMissing(string $table, string $column, string $after, string $type = 'json'): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $after, $type): void {
            if ($type === 'longText') {
                $blueprint->longText($column)->nullable()->after($after);
                return;
            }

            $blueprint->json($column)->nullable()->after($after);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $rows = DB::select('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '` WHERE Key_name = ?', [$index]);
        } catch (Throwable) {
            return false;
        }

        return $rows !== [];
    }
};
