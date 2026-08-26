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

        if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'modulo_id')) {
            Schema::table($tableName, static function (Blueprint $table): void {
                $table->dropConstrainedForeignId('modulo_id');
            });
        }
    }

    public function down(): void
    {
        $tableName = 'redmine_mantencion_nextcloud_historial_lotes';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'modulo_id')) {
            return;
        }

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->foreignId('modulo_id')
                ->nullable()
                ->after('id')
                ->constrained('modulos_nova')
                ->nullOnDelete();
        });

        $moduleId = DB::table('modulos_nova')
            ->where('clave_modulo', 'redmine-mantencion')
            ->value('id');

        if ($moduleId !== null) {
            DB::table($tableName)->update(['modulo_id' => (int) $moduleId]);
        }
    }
};
