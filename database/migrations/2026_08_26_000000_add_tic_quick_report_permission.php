<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY = 'reporte_rapido';

    private const LEGACY_KEY = 'simulador';

    public function up(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('redmine_tic_permisos_catalogo')) {
                DB::table('redmine_tic_permisos_catalogo')->updateOrInsert(
                    ['clave' => self::KEY],
                    [
                        'tipo' => 'bool',
                        'descripcion' => 'Acceso a la seccion Reporte rapido',
                        'orden' => 41,
                    ]
                );
            }

            if (Schema::hasTable('redmine_tic_permisos_rol')) {
                foreach (DB::table('redmine_tic_permisos_rol')->where('clave', self::LEGACY_KEY)->get(['modulo_id', 'rol', 'valor']) as $permission) {
                    DB::table('redmine_tic_permisos_rol')->updateOrInsert(
                        ['modulo_id' => $permission->modulo_id, 'rol' => $permission->rol, 'clave' => self::KEY],
                        ['valor' => $permission->valor, 'actualizado_at' => now()]
                    );
                }
            }

            if (Schema::hasTable('redmine_tic_permisos_usuario')) {
                foreach (DB::table('redmine_tic_permisos_usuario')->where('clave', self::LEGACY_KEY)->get(['perfil_id', 'valor']) as $permission) {
                    DB::table('redmine_tic_permisos_usuario')->updateOrInsert(
                        ['perfil_id' => $permission->perfil_id, 'clave' => self::KEY],
                        ['valor' => $permission->valor, 'actualizado_at' => now()]
                    );
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('redmine_tic_permisos_usuario')) {
                DB::table('redmine_tic_permisos_usuario')->where('clave', self::KEY)->delete();
            }
            if (Schema::hasTable('redmine_tic_permisos_rol')) {
                DB::table('redmine_tic_permisos_rol')->where('clave', self::KEY)->delete();
            }
            if (Schema::hasTable('redmine_tic_permisos_catalogo')) {
                DB::table('redmine_tic_permisos_catalogo')->where('clave', self::KEY)->delete();
            }
        });
    }
};
