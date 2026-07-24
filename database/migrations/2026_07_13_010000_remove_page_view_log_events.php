<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_log')) {
            DB::table('telegram_log')->where('evento', 'consulta_vista')->delete();
        }
        if (Schema::hasTable('emach_log')) {
            DB::table('emach_log')->where('evento', 'consulta_vista')->delete();
        }
        if (Schema::hasTable('tic_log')) {
            DB::table('tic_log')->where('evento', 'consulta_vista')->delete();
        }
        if (Schema::hasTable('mantencion_log')) {
            DB::table('mantencion_log')->where('canal', 'http')->where('tipo', 'CONSULTA')->delete();
        }
        if (Schema::hasTable('nova_audit_logs')) {
            DB::table('nova_audit_logs')
                ->where('event', 'movimiento_http')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(contexto, '$.metodo')) = ?", ['GET'])
                ->delete();
        }
    }

    public function down(): void
    {
        // Los eventos de navegación eliminados no representan datos de negocio restaurables.
    }
};
