<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1b — Non-destructive.
 *
 * Promotes the `predeterminado` boolean field from the datos_extra JSON blob
 * to a proper column in `categorias` and `unidades`. The datos_extra column
 * is NOT dropped here; that happens in Phase 2 after verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('categorias') && !Schema::hasColumn('categorias', 'predeterminado')) {
            Schema::table('categorias', function (Blueprint $table): void {
                $table->boolean('predeterminado')->default(false)->after('activo');
            });
            $this->populatePredeterminado('categorias');
        }

        if (Schema::hasTable('unidades') && !Schema::hasColumn('unidades', 'predeterminado')) {
            Schema::table('unidades', function (Blueprint $table): void {
                $table->boolean('predeterminado')->default(false)->after('activo');
            });
            $this->populatePredeterminado('unidades');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('categorias') && Schema::hasColumn('categorias', 'predeterminado')) {
            Schema::table('categorias', function (Blueprint $table): void {
                $table->dropColumn('predeterminado');
            });
        }

        if (Schema::hasTable('unidades') && Schema::hasColumn('unidades', 'predeterminado')) {
            Schema::table('unidades', function (Blueprint $table): void {
                $table->dropColumn('predeterminado');
            });
        }
    }

    private function populatePredeterminado(string $tableName): void
    {
        $rows = DB::table($tableName)->whereNotNull('datos_extra')->get(['id', 'datos_extra']);

        foreach ($rows as $row) {
            $extra = json_decode((string) ($row->datos_extra ?? '{}'), true);
            if (!is_array($extra)) {
                continue;
            }

            $predeterminado = !empty($extra['predeterminado']) ? 1 : 0;

            try {
                DB::table($tableName)->where('id', $row->id)->update(['predeterminado' => $predeterminado]);
            } catch (\Throwable) {
                continue;
            }
        }
    }
};
