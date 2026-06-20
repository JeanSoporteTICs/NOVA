<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('redmine_tic_reportes')
            || !Schema::hasTable('usuarios_nova')
            || !Schema::hasTable('modulos_nova')
            || !Schema::hasTable('permisos_usuario_modulo')
        ) {
            return;
        }

        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        if ($moduleId === null) {
            return;
        }

        $assignee = DB::table('usuarios_nova')
            ->join('permisos_usuario_modulo', 'permisos_usuario_modulo.usuario_id', '=', 'usuarios_nova.id')
            ->where('permisos_usuario_modulo.modulo_id', $moduleId)
            ->where('permisos_usuario_modulo.permitido', 1)
            ->whereNotNull('usuarios_nova.redmine_id')
            ->where('usuarios_nova.redmine_id', '<>', '')
            ->orderByRaw("CASE WHEN usuarios_nova.redmine_id = '42' THEN 0 WHEN usuarios_nova.rol IN ('admin', 'administrador', 'root') THEN 1 ELSE 2 END")
            ->orderBy('usuarios_nova.id')
            ->value('usuarios_nova.redmine_id');

        if ($assignee === null || !ctype_digit((string) $assignee)) {
            return;
        }

        DB::table('redmine_tic_reportes')
            ->where('modulo_id', $moduleId)
            ->where('origen', 'manual')
            ->whereNull('asignado_a')
            ->update([
                'asignado_a' => (int) $assignee,
                'actualizado_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data backfill only; do not erase assignments on rollback.
    }
};
