<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('redmine_tic_permisos_catalogo')) {
            return;
        }

        DB::table('redmine_tic_permisos_catalogo')->updateOrInsert(
            ['clave' => 'mis_integraciones'],
            ['tipo' => 'bool', 'descripcion' => 'Acceso a Mis integraciones', 'orden' => 38]
        );

        if (Schema::hasTable('redmine_tic_permisos_rol')) {
            foreach (DB::table('redmine_tic_permisos_rol')->select('modulo_id', 'rol')->distinct()->get() as $role) {
                DB::table('redmine_tic_permisos_rol')->updateOrInsert(
                    ['modulo_id' => $role->modulo_id, 'rol' => $role->rol, 'clave' => 'mis_integraciones'],
                    ['valor' => '1']
                );
            }
        }

        if (Schema::hasTable('redmine_tic_permisos_usuario')) {
            foreach (DB::table('redmine_tic_permisos_usuario')->distinct()->pluck('perfil_id') as $profileId) {
                DB::table('redmine_tic_permisos_usuario')->updateOrInsert(
                    ['perfil_id' => $profileId, 'clave' => 'mis_integraciones'],
                    ['valor' => '1']
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('redmine_tic_permisos_usuario')->where('clave', 'mis_integraciones')->delete();
        DB::table('redmine_tic_permisos_rol')->where('clave', 'mis_integraciones')->delete();
        DB::table('redmine_tic_permisos_catalogo')->where('clave', 'mis_integraciones')->delete();
    }
};
