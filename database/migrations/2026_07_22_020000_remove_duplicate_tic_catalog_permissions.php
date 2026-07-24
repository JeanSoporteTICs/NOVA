<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = ['categorias', 'unidades'];

    public function up(): void
    {
        if (Schema::hasTable('redmine_tic_permisos_usuario')) {
            DB::table('redmine_tic_permisos_usuario')->whereIn('clave', self::KEYS)->delete();
        }
        if (Schema::hasTable('redmine_tic_permisos_rol')) {
            DB::table('redmine_tic_permisos_rol')->whereIn('clave', self::KEYS)->delete();
        }
        if (Schema::hasTable('redmine_tic_permisos_catalogo')) {
            DB::table('redmine_tic_permisos_catalogo')->whereIn('clave', self::KEYS)->delete();
        }
    }

    public function down(): void
    {
        // No se recrean permisos duplicados sin consumidor runtime.
    }
};
