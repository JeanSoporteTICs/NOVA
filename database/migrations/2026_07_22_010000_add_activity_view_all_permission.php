<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redmine_tic_permisos_catalogo')) {
            DB::table('redmine_tic_permisos_catalogo')->updateOrInsert(
                ['clave' => 'actividad_todos'],
                ['tipo' => 'bool', 'descripcion' => 'Puede ver la bitácora de todos los usuarios', 'orden' => 40]
            );
        }
        if (Schema::hasTable('redmine_tic_permisos_rol')) {
            foreach (DB::table('redmine_tic_permisos_rol')->where('clave', 'actividad')->get(['modulo_id', 'rol', 'valor']) as $row) {
                DB::table('redmine_tic_permisos_rol')->updateOrInsert(
                    ['modulo_id' => $row->modulo_id, 'rol' => $row->rol, 'clave' => 'actividad_todos'],
                    ['valor' => $row->valor, 'actualizado_at' => now()]
                );
            }
        }
        if (Schema::hasTable('redmine_tic_permisos_usuario')) {
            foreach (DB::table('redmine_tic_permisos_usuario')->where('clave', 'actividad')->get(['perfil_id', 'valor']) as $row) {
                DB::table('redmine_tic_permisos_usuario')->updateOrInsert(
                    ['perfil_id' => $row->perfil_id, 'clave' => 'actividad_todos'],
                    ['valor' => $row->valor, 'actualizado_at' => now()]
                );
            }
        }
        if (Schema::hasTable('mantencion_permisos_rol')) {
            foreach (DB::table('mantencion_permisos_rol')->where('permiso', 'actividad')->get(['rol', 'valor']) as $row) {
                DB::table('mantencion_permisos_rol')->updateOrInsert(
                    ['rol' => $row->rol, 'permiso' => 'actividad_todos'],
                    ['valor' => $row->valor]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_tic_permisos_usuario')) DB::table('redmine_tic_permisos_usuario')->where('clave', 'actividad_todos')->delete();
        if (Schema::hasTable('redmine_tic_permisos_rol')) DB::table('redmine_tic_permisos_rol')->where('clave', 'actividad_todos')->delete();
        if (Schema::hasTable('redmine_tic_permisos_catalogo')) DB::table('redmine_tic_permisos_catalogo')->where('clave', 'actividad_todos')->delete();
        if (Schema::hasTable('mantencion_permisos_rol')) DB::table('mantencion_permisos_rol')->where('permiso', 'actividad_todos')->delete();
    }
};
