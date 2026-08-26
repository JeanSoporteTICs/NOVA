<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'redmine_mantencion_nextcloud_historial_lotes';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'numero_lote')) {
            return;
        }

        DB::table($tableName)
            ->select('id', 'numero_lote', 'created_at_cl')
            ->whereNotNull('numero_lote')
            ->orderBy('numero_lote')
            ->get()
            ->each(static function (object $row) use ($tableName): void {
                DB::table($tableName)
                    ->where('id', $row->id)
                    ->update([
                        'legacy_id' => (string) $row->numero_lote,
                        'created_at_cl' => $row->created_at_cl,
                    ]);
            });
    }

    public function down(): void
    {
        $tableName = 'redmine_mantencion_nextcloud_historial_lotes';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        DB::table($tableName)
            ->select('id', 'created_at_cl')
            ->orderBy('id')
            ->get()
            ->each(static function (object $row) use ($tableName): void {
                DB::table($tableName)
                    ->where('id', $row->id)
                    ->update([
                        'legacy_id' => 'legacy-'.str_pad((string) $row->id, 24, '0', STR_PAD_LEFT),
                        'created_at_cl' => $row->created_at_cl,
                    ]);
            });
    }
};
