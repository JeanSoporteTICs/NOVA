<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permisos_usuario_modulo')) {
            return;
        }

        if (!Schema::hasColumn('permisos_usuario_modulo', 'rol_modulo')) {
            Schema::table('permisos_usuario_modulo', function (Blueprint $table): void {
                $table->string('rol_modulo', 40)->nullable()->after('permitido');
            });
        }

        if (!Schema::hasTable('modulos_nova') || !Schema::hasTable('usuarios_nova')) {
            return;
        }

        $moduleId = DB::table('modulos_nova')
            ->where('clave_modulo', 'redmine-mantencion')
            ->value('id');
        if ($moduleId === null) {
            return;
        }

        $rows = DB::table('permisos_usuario_modulo')
            ->join('usuarios_nova', 'usuarios_nova.id', '=', 'permisos_usuario_modulo.usuario_id')
            ->where('permisos_usuario_modulo.modulo_id', (int) $moduleId)
            ->whereNull('permisos_usuario_modulo.rol_modulo')
            ->get([
                'permisos_usuario_modulo.id',
                'usuarios_nova.rol',
            ]);

        foreach ($rows as $row) {
            $globalRole = strtolower(trim((string) ($row->rol ?? 'usuario')));
            $moduleRole = match ($globalRole) {
                'root' => 'root',
                'admin', 'administrador' => 'administrador',
                'gestor' => 'gestor',
                default => 'usuario',
            };

            DB::table('permisos_usuario_modulo')
                ->where('id', (int) $row->id)
                ->update(['rol_modulo' => $moduleRole]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permisos_usuario_modulo')
            && Schema::hasColumn('permisos_usuario_modulo', 'rol_modulo')) {
            Schema::table('permisos_usuario_modulo', function (Blueprint $table): void {
                $table->dropColumn('rol_modulo');
            });
        }
    }
};
