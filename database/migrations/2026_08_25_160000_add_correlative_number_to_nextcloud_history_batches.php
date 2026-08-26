<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'redmine_mantencion_nextcloud_historial_lotes';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'numero_lote')) {
            Schema::table($tableName, static function (Blueprint $table): void {
                $table->unsignedBigInteger('numero_lote')->nullable()->after('legacy_id');
            });
        }

        // Algunas instalaciones antiguas crearon esta columna como TIMESTAMP
        // con ON UPDATE. Se normaliza a DATETIME antes del backfill para que la
        // renumeración no cambie la fecha real de cada importación.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE {$tableName} MODIFY created_at_cl DATETIME NOT NULL");
        }

        $rows = DB::table($tableName)
            ->select('id', 'created_at_cl')
            ->orderBy('created_at_cl')
            ->orderBy('id')
            ->get();

        foreach ($rows as $index => $row) {
            DB::table($tableName)
                ->where('id', $row->id)
                ->update([
                    'numero_lote' => $index + 1,
                    'created_at_cl' => $row->created_at_cl,
                ]);
        }

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->unique('numero_lote', 'rm_nextcloud_lotes_numero_unique');
        });
    }

    public function down(): void
    {
        $tableName = 'redmine_mantencion_nextcloud_historial_lotes';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'numero_lote')) {
            return;
        }

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->dropUnique('rm_nextcloud_lotes_numero_unique');
            $table->dropColumn('numero_lote');
        });
    }
};
