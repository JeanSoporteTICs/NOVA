<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEY = 'cfg_informes';

    private const SOURCE_KEY = 'cfg_retencion';

    public function up(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('mantencion_permisos_rol')) {
                foreach (DB::table('mantencion_permisos_rol')->where('permiso', self::SOURCE_KEY)->get(['rol', 'valor']) as $permission) {
                    DB::table('mantencion_permisos_rol')->updateOrInsert(
                        ['rol' => $permission->rol, 'permiso' => self::KEY],
                        ['valor' => $permission->valor]
                    );
                }
            }

            if (Schema::hasTable('mantencion_permisos_usuario')) {
                foreach (DB::table('mantencion_permisos_usuario')->where('permiso', self::SOURCE_KEY)->get(['usuario_id', 'valor']) as $permission) {
                    DB::table('mantencion_permisos_usuario')->updateOrInsert(
                        ['usuario_id' => $permission->usuario_id, 'permiso' => self::KEY],
                        ['valor' => $permission->valor, 'actualizado_at' => now()]
                    );
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('mantencion_permisos_usuario')) {
                DB::table('mantencion_permisos_usuario')->where('permiso', self::KEY)->delete();
            }
            if (Schema::hasTable('mantencion_permisos_rol')) {
                DB::table('mantencion_permisos_rol')->where('permiso', self::KEY)->delete();
            }
        });
    }
};
