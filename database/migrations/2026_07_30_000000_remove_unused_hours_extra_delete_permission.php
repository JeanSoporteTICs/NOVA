<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY = 'horas_extra_eliminar';

    public function up(): void
    {
        if (Schema::hasTable('redmine_tic_permisos_usuario')) {
            DB::table('redmine_tic_permisos_usuario')->where('clave', self::KEY)->delete();
        }
        if (Schema::hasTable('redmine_tic_permisos_rol')) {
            DB::table('redmine_tic_permisos_rol')->where('clave', self::KEY)->delete();
        }
        if (Schema::hasTable('redmine_tic_permisos_catalogo')) {
            DB::table('redmine_tic_permisos_catalogo')->where('clave', self::KEY)->delete();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_tic_permisos_catalogo')) {
            DB::table('redmine_tic_permisos_catalogo')->updateOrInsert(
                ['clave' => self::KEY],
                [
                    'tipo' => 'bool',
                    'descripcion' => 'Puede eliminar horas extra',
                    'orden' => 18,
                ]
            );
        }

        if (Schema::hasTable('redmine_tic_permisos_rol')) {
            DB::table('redmine_tic_permisos_rol')
                ->where('clave', 'horas_extra_editar')
                ->get(['modulo_id', 'rol', 'valor'])
                ->each(static function (object $permission): void {
                    DB::table('redmine_tic_permisos_rol')->updateOrInsert(
                        [
                            'modulo_id' => $permission->modulo_id,
                            'rol' => $permission->rol,
                            'clave' => self::KEY,
                        ],
                        [
                            'valor' => $permission->valor,
                            'actualizado_at' => now(),
                        ]
                    );
                });
        }

        if (Schema::hasTable('redmine_tic_permisos_usuario')) {
            DB::table('redmine_tic_permisos_usuario')
                ->where('clave', 'horas_extra_editar')
                ->get(['perfil_id', 'valor'])
                ->each(static function (object $permission): void {
                    DB::table('redmine_tic_permisos_usuario')->updateOrInsert(
                        [
                            'perfil_id' => $permission->perfil_id,
                            'clave' => self::KEY,
                        ],
                        [
                            'valor' => $permission->valor,
                            'actualizado_at' => now(),
                        ]
                    );
                });
        }
    }
};
