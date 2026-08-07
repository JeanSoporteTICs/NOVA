<?php

namespace App\Modulos\RedmineMantencion\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MantencionConfiguracionRolesService
{
    public function saveRolePermissions(string $role, array $permissions): void
    {
        if (!class_exists(DB::class)) {
            return;
        }
        DB::transaction(function () use ($role, $permissions): void {
            DB::table('mantencion_permisos_rol')->where('rol', $role)->delete();
            foreach ($permissions as $permission => $value) {
                DB::table('mantencion_permisos_rol')->insert([
                    'rol' => $role,
                    'permiso' => (string) $permission,
                    'valor' => is_bool($value) ? ($value ? '1' : '') : (string) $value,
                ]);
            }
        });
    }

    public function deleteRolePermissions(string $role): void
    {
        if ($role === '' || !class_exists(DB::class)) {
            return;
        }
        DB::table('mantencion_permisos_rol')->where('rol', $role)->delete();
    }

    public function saveUserRole(string $userId, string $role): void
    {
        if ($userId === '' || $role === '' || !class_exists(DB::class)
            || !Schema::hasColumn('permisos_usuario_modulo', 'rol_modulo')) {
            return;
        }
        $novaId = (int) DB::table('usuarios_nova')
            ->where(function ($query) use ($userId): void {
                $query->where('redmine_id', $userId)
                    ->orWhere('uuid', $userId)
                    ->orWhere('usuario', $userId);
            })
            ->value('id');
        $moduleId = (int) DB::table('modulos_nova')
            ->where('clave_modulo', 'redmine-mantencion')
            ->value('id');
        if ($novaId <= 0 || $moduleId <= 0) {
            return;
        }
        DB::table('permisos_usuario_modulo')->updateOrInsert(
            ['usuario_id' => $novaId, 'modulo_id' => $moduleId],
            [
                'permitido' => 1,
                'rol_modulo' => function_exists('usuarios_normalize_module_role')
                    ? usuarios_normalize_module_role($role)
                    : $role,
                'actualizado_at' => now(),
            ]
        );
    }

    public function saveUserPermissions(string $userId, array $permissions): void
    {
        if ($userId === '' || !class_exists(DB::class)
            || !Schema::hasTable('mantencion_permisos_usuario')) {
            return;
        }
        $novaId = (int) DB::table('usuarios_nova')
            ->where(function ($query) use ($userId): void {
                $query->where('redmine_id', $userId)->orWhere('uuid', $userId)->orWhere('usuario', $userId);
            })->value('id');
        if ($novaId <= 0) {
            return;
        }
        DB::transaction(function () use ($novaId, $permissions): void {
            DB::table('mantencion_permisos_usuario')->where('usuario_id', $novaId)->delete();
            foreach ($permissions as $permission => $value) {
                DB::table('mantencion_permisos_usuario')->insert([
                    'usuario_id' => $novaId,
                    'permiso' => (string) $permission,
                    'valor' => is_bool($value) ? ($value ? '1' : '') : (string) $value,
                    'actualizado_at' => now(),
                ]);
            }
        });
    }
}
