<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('configuraciones_modulo') || !Schema::hasTable('modulos_nova')) {
            return;
        }

        $moduleId = DB::table('modulos_nova')
            ->where('clave_modulo', 'redmine-mantencion')
            ->value('id');

        if ($moduleId !== null) {
            DB::table('configuraciones_modulo')
                ->where('modulo_id', $moduleId)
                ->whereIn('clave', ['procedures_storage', 'procedures_nextcloud_root'])
                ->delete();
        }
    }

    public function down(): void
    {
        // No se restauran configuraciones heredadas que el módulo ya no consume.
    }
};
