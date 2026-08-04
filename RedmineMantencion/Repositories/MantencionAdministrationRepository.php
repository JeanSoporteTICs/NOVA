<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MantencionAdministrationRepository
{
    private const MODULE_KEY = 'redmine-mantencion';

    /** @return array<int,array<string,mixed>> */
    public function users(): array
    {
        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return [];
        }

        return DB::table('usuarios_nova as u')
            ->join('permisos_usuario_modulo as p', static function ($join) use ($moduleId): void {
                $join->on('p.usuario_id', '=', 'u.id')->where('p.modulo_id', '=', $moduleId);
            })
            ->where('p.permitido', 1)
            ->orderBy('u.nombre')->orderBy('u.apellido')
            ->get(['u.id', 'u.uuid', 'u.usuario', 'u.rut', 'u.redmine_id', 'u.nombre', 'u.apellido', 'u.usuario_core', 'u.rol', 'u.estado', 'p.rol_modulo'])
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    /** @param array<string,mixed> $values */
    public function updateUser(int $userId, array $values): bool
    {
        $moduleId = $this->moduleId();
        if ($userId <= 0 || $moduleId === null) {
            return false;
        }
        $allowed = array_intersect_key($values, array_flip(['nombre', 'apellido', 'rut', 'usuario_core', 'estado']));
        $allowed = array_map(static fn (mixed $value): string => trim((string) $value), $allowed);
        $role = strtolower(trim((string) ($values['rol_modulo'] ?? 'usuario')));
        if (! in_array($role, $this->roles(), true)) {
            $role = 'usuario';
        }

        return DB::transaction(function () use ($userId, $moduleId, $allowed, $role): bool {
            if (! DB::table('usuarios_nova')->where('id', $userId)->exists()) {
                return false;
            }
            if ($allowed !== []) {
                DB::table('usuarios_nova')->where('id', $userId)->update($allowed + ['actualizado_at' => now()]);
            }
            DB::table('permisos_usuario_modulo')->updateOrInsert(
                ['usuario_id' => $userId, 'modulo_id' => $moduleId],
                ['permitido' => 1, 'rol_modulo' => $role, 'actualizado_at' => now()],
            );

            return true;
        });
    }

    public function revokeUser(int $userId): bool
    {
        $moduleId = $this->moduleId();
        if ($userId <= 0 || $moduleId === null) {
            return false;
        }
        DB::table('mantencion_permisos_usuario')->where('usuario_id', $userId)->delete();

        return DB::table('permisos_usuario_modulo')->where(['usuario_id' => $userId, 'modulo_id' => $moduleId])->update(['permitido' => 0, 'actualizado_at' => now()]) > 0;
    }

    /** @return array<int,string> */
    public function roles(): array
    {
        $roles = DB::table('mantencion_permisos_rol')->distinct()->orderBy('rol')->pluck('rol')->map('strval')->all();

        return $roles !== [] ? $roles : ['administrador', 'gestor', 'root', 'usuario'];
    }

    /** @return array<int,string> */
    public function permissionKeys(): array
    {
        return DB::table('mantencion_permisos_rol')->distinct()->orderBy('permiso')->pluck('permiso')->map('strval')->all();
    }

    /** @return array<string,array<string,string>> */
    public function rolePermissions(): array
    {
        $result = [];
        foreach (DB::table('mantencion_permisos_rol')->orderBy('rol')->orderBy('permiso')->get() as $row) {
            $result[(string) $row->rol][(string) $row->permiso] = (string) $row->valor;
        }

        return $result;
    }

    /** @param array<string,mixed> $values */
    public function saveRolePermissions(string $role, array $values): void
    {
        $role = strtolower(trim($role));
        if ($role === '' || $role === 'root') {
            return;
        }
        foreach ($this->permissionKeys() as $key) {
            $value = $values[$key] ?? '';
            if (in_array($key, ['mensajes', 'horas_extra', 'historico_scope'], true)) {
                $value = in_array($value, ['todos', 'asignados'], true) ? $value : 'asignados';
            } else {
                $value = ! empty($value) ? '1' : '';
            }
            DB::table('mantencion_permisos_rol')->updateOrInsert(['rol' => $role, 'permiso' => $key], ['valor' => $value]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function nextcloudHistory(): array
    {
        if (! Schema::hasTable('redmine_mantencion_nextcloud_historial_lotes')) {
            return [];
        }

        return DB::table('redmine_mantencion_nextcloud_historial_lotes as l')
            ->leftJoin('redmine_mantencion_nextcloud_historial_usuarios as u', 'u.lote_id', '=', 'l.id')
            ->groupBy('l.id', 'l.legacy_id', 'l.created_at_cl')
            ->orderByDesc('l.created_at_cl')
            ->limit(100)
            ->get(['l.id', 'l.legacy_id', 'l.created_at_cl', DB::raw('COUNT(u.id) as total'), DB::raw("SUM(CASE WHEN u.status = 'created' THEN 1 ELSE 0 END) as creados"), DB::raw("SUM(CASE WHEN u.status = 'failed' THEN 1 ELSE 0 END) as fallidos")])
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    public function moduleId(): ?int
    {
        $id = DB::table('modulos_nova')->where('clave_modulo', self::MODULE_KEY)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
