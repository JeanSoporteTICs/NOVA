<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TIC_KEYS = [
        'estadisticas_manual', 'cfg_webhook', 'cfg_sesion', 'cfg_trackers',
        'cfg_prioridades', 'cfg_estados', 'cfg_catalogos',
    ];

    private const MANTENCION_KEYS = ['cfg_sesion', 'procedimientos_editar', 'unidades'];

    public function up(): void
    {
        if (Schema::hasTable('redmine_tic_permisos_usuario')) {
            DB::table('redmine_tic_permisos_usuario')->whereIn('clave', self::TIC_KEYS)->delete();
        }
        if (Schema::hasTable('redmine_tic_permisos_rol')) {
            DB::table('redmine_tic_permisos_rol')->whereIn('clave', self::TIC_KEYS)->delete();
        }
        if (Schema::hasTable('redmine_tic_permisos_catalogo')) {
            DB::table('redmine_tic_permisos_catalogo')->whereIn('clave', self::TIC_KEYS)->delete();
        }
        if (Schema::hasTable('mantencion_permisos_rol')) {
            DB::table('mantencion_permisos_rol')->whereIn('permiso', self::MANTENCION_KEYS)->delete();
        }
    }

    public function down(): void
    {
        // No se recrean permisos obsoletos sin consumidor runtime.
    }
};
