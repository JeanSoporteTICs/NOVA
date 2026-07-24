<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('redmine_mantencion_procedimientos');

        if (Schema::hasTable('configuraciones_modulo') && Schema::hasTable('modulos_nova')) {
            $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine-mantencion')->value('id');
            if ($moduleId !== null) {
                DB::table('configuraciones_modulo')
                    ->where('modulo_id', $moduleId)
                    ->whereIn('clave', ['onlyoffice_url', 'onlyoffice_app_url', 'onlyoffice_jwt_secret', 'onlyoffice_disabled'])
                    ->delete();
            }
        }
    }

    public function down(): void
    {
        // El flujo local fue retirado; los archivos continúan en Nextcloud.
    }
};
