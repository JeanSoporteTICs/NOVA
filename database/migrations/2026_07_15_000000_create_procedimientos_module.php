<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('modulos_nova')) {
            return;
        }

        DB::table('modulos_nova')->updateOrInsert(
            ['clave_modulo' => 'procedimientos'],
            [
                'nombre' => 'Procedimientos',
                'descripcion' => 'Documentos Nextcloud con edicion OnlyOffice.',
                'icono' => 'bi-journal-richtext',
                'tipo' => 'native',
                'ruta' => '/procedimientos',
                'entrada' => 'laravel:procedimientos.index',
                'habilitado' => 1,
                'en_mantencion' => 0,
                'orden' => 40,
            ]
        );

        if (!Schema::hasTable('permisos_usuario_modulo')) {
            return;
        }
        $newId = DB::table('modulos_nova')->where('clave_modulo', 'procedimientos')->value('id');
        $oldId = DB::table('modulos_nova')->where('clave_modulo', 'redmine-mantencion')->value('id');
        if ($newId === null || $oldId === null) {
            return;
        }
        foreach (DB::table('permisos_usuario_modulo')->where('modulo_id', $oldId)->get(['usuario_id', 'permitido']) as $permission) {
            DB::table('permisos_usuario_modulo')->updateOrInsert(
                ['usuario_id' => $permission->usuario_id, 'modulo_id' => $newId],
                ['permitido' => $permission->permitido]
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('modulos_nova')) {
            return;
        }
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'procedimientos')->value('id');
        if ($moduleId !== null && Schema::hasTable('permisos_usuario_modulo')) {
            DB::table('permisos_usuario_modulo')->where('modulo_id', $moduleId)->delete();
        }
        DB::table('modulos_nova')->where('clave_modulo', 'procedimientos')->delete();
    }
};
