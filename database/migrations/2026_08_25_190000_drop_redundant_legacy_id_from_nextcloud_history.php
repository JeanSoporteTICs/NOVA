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
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'legacy_id')) {
            return;
        }

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->dropColumn('legacy_id');
        });
    }

    public function down(): void
    {
        $tableName = 'redmine_mantencion_nextcloud_historial_lotes';
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'legacy_id')) {
            return;
        }

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->string('legacy_id', 32)->nullable()->after('id');
        });

        DB::table($tableName)
            ->select('id', 'numero_lote', 'created_at_cl')
            ->orderBy('id')
            ->get()
            ->each(static function (object $row) use ($tableName): void {
                DB::table($tableName)
                    ->where('id', $row->id)
                    ->update([
                        'legacy_id' => (string) $row->numero_lote,
                        'created_at_cl' => $row->created_at_cl,
                    ]);
            });

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->unique('legacy_id', 'rm_nextcloud_lotes_legacy_id_unique');
        });
    }
};
