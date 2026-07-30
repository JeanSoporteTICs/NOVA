<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EDIT_KEY = 'horas_extra_editar';
    private const DELETE_KEY = 'horas_extra_eliminar';

    public function up(): void
    {
        if (Schema::hasTable('mantencion_permisos_rol')) {
            DB::table('mantencion_permisos_rol')->where('permiso', self::DELETE_KEY)->delete();
            foreach (DB::table('mantencion_permisos_rol')->where('permiso', 'horas_extra')->get(['rol', 'valor']) as $permission) {
                DB::table('mantencion_permisos_rol')->updateOrInsert(
                    ['rol' => $permission->rol, 'permiso' => self::EDIT_KEY],
                    ['valor' => trim((string) $permission->valor) !== '' ? '1' : '']
                );
            }
        }

        if (Schema::hasTable('mantencion_permisos_usuario')) {
            DB::table('mantencion_permisos_usuario')->where('permiso', self::DELETE_KEY)->delete();
            foreach (DB::table('mantencion_permisos_usuario')->where('permiso', 'horas_extra')->get(['usuario_id', 'valor']) as $permission) {
                DB::table('mantencion_permisos_usuario')->updateOrInsert(
                    ['usuario_id' => $permission->usuario_id, 'permiso' => self::EDIT_KEY],
                    [
                        'valor' => trim((string) $permission->valor) !== '' ? '1' : '',
                        'actualizado_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mantencion_permisos_usuario')) {
            DB::table('mantencion_permisos_usuario')->where('permiso', self::EDIT_KEY)->delete();
        }
        if (Schema::hasTable('mantencion_permisos_rol')) {
            DB::table('mantencion_permisos_rol')->where('permiso', self::EDIT_KEY)->delete();
        }
    }
};
