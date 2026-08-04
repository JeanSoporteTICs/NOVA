<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\Nova\Services\ProjectAccessGuard;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MantencionAccessService
{
    public function __construct(
        private readonly ProjectAccessGuard $projectAccess,
        private readonly MantencionConfigRepository $config,
    ) {}

    /**
     * Build the request context directly from the authenticated Laravel session.
     *
     * @return array<string,mixed>|null
     */
    public function context(Request $request): ?array
    {
        $novaUser = $request->session()->get('nova_user', []);
        if (! is_array($novaUser)) {
            return null;
        }

        $projectUser = $this->projectAccess->projectUser('redmine-mantencion', $novaUser);
        if (! is_array($projectUser)) {
            return null;
        }

        $centralUser = $this->centralUser($novaUser);
        $centralUserId = $centralUser !== null ? (int) $centralUser->id : null;
        $role = $this->moduleRole($novaUser, $centralUserId);
        $rolePermissions = $this->rolePermissions($role);
        $userPermissions = $centralUserId !== null ? $this->userPermissions($centralUserId) : [];
        $permissions = array_replace($this->permissionDefaults($role, $rolePermissions), $userPermissions);

        if ($role === 'root') {
            $permissions['all'] = true;
        }

        $config = $this->config->loadAll() ?? [];
        $until = trim((string) ($config['maintenance_until'] ?? ''));

        return [
            'nova_user' => $novaUser,
            'project_user' => $projectUser,
            'central_user_id' => $centralUserId,
            'role' => $role,
            'permissions' => $permissions,
            'viewer_id' => trim((string) ($projectUser['id'] ?? $novaUser['redmine_id'] ?? $novaUser['id'] ?? '')),
            'viewer_name' => trim((string) (($projectUser['nombre'] ?? $novaUser['name'] ?? '').' '.($projectUser['apellido'] ?? $novaUser['apellido'] ?? ''))),
            'viewer_core_user' => trim((string) ($centralUser->usuario_core ?? $novaUser['core_user'] ?? '')),
            'maintenance' => [
                'enabled' => ! empty($config['maintenance_mode']),
                'until' => $until,
                'until_text' => $this->maintenanceUntilText($until),
            ],
        ];
    }

    /** @param array<string,mixed> $context */
    public function can(array $context, string $permission): bool
    {
        $permissions = is_array($context['permissions'] ?? null) ? $context['permissions'] : [];

        if (array_key_exists($permission, $permissions)) {
            return ! empty($permissions[$permission]);
        }

        return ! empty($permissions['all']);
    }

    /** @param array<string,mixed> $novaUser */
    private function centralUser(array $novaUser): ?object
    {
        try {
            if (! Schema::hasTable('usuarios_nova')) {
                return null;
            }

            $candidates = [
                'uuid' => [$novaUser['id'] ?? '', $novaUser['_nova_user_id'] ?? ''],
                'usuario' => [$novaUser['username'] ?? '', $novaUser['rut_sin_dv'] ?? ''],
                'rut' => [$novaUser['rut'] ?? ''],
                'redmine_id' => [$novaUser['redmine_id'] ?? '', data_get($novaUser, 'legacy.id', '')],
            ];

            foreach ($candidates as $column => $values) {
                foreach ($values as $value) {
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue;
                    }

                    $user = DB::table('usuarios_nova')->where($column, $value)->first(['id', 'rol', 'usuario_core']);
                    if ($user !== null) {
                        return $user;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** @param array<string,mixed> $novaUser */
    private function moduleRole(array $novaUser, ?int $centralUserId): string
    {
        $globalRole = strtolower(trim((string) ($novaUser['role'] ?? 'usuario')));
        if ($globalRole === 'root') {
            return 'root';
        }

        if ($centralUserId !== null
            && Schema::hasTable('permisos_usuario_modulo')
            && Schema::hasTable('modulos_nova')
            && Schema::hasColumn('permisos_usuario_modulo', 'rol_modulo')) {
            $storedRole = DB::table('permisos_usuario_modulo')
                ->join('modulos_nova', 'modulos_nova.id', '=', 'permisos_usuario_modulo.modulo_id')
                ->where('permisos_usuario_modulo.usuario_id', $centralUserId)
                ->where('modulos_nova.clave_modulo', 'redmine-mantencion')
                ->value('permisos_usuario_modulo.rol_modulo');
            $storedRole = strtolower(trim((string) $storedRole));
            if ($storedRole !== '') {
                return $storedRole;
            }
        }

        return in_array($globalRole, ['admin', 'administrador'], true) ? 'administrador' : 'usuario';
    }

    /** @return array<string,mixed> */
    private function rolePermissions(string $role): array
    {
        if (! Schema::hasTable('mantencion_permisos_rol')) {
            return [];
        }

        return DB::table('mantencion_permisos_rol')
            ->where('rol', $role)
            ->get(['permiso', 'valor'])
            ->mapWithKeys(fn (object $row): array => [(string) $row->permiso => $this->decodePermission((string) $row->valor)])
            ->all();
    }

    /** @return array<string,mixed> */
    private function userPermissions(int $centralUserId): array
    {
        if (! Schema::hasTable('mantencion_permisos_usuario')) {
            return [];
        }

        return DB::table('mantencion_permisos_usuario')
            ->where('usuario_id', $centralUserId)
            ->get(['permiso', 'valor'])
            ->mapWithKeys(fn (object $row): array => [(string) $row->permiso => $this->decodePermission((string) $row->valor)])
            ->all();
    }

    private function decodePermission(string $value): mixed
    {
        return match ($value) {
            '1' => true,
            '' => false,
            default => $value,
        };
    }

    /**
     * Preserve the defaults currently applied by the procedural permission layer.
     *
     * @param  array<string,mixed>  $permissions
     * @return array<string,mixed>
     */
    private function permissionDefaults(string $role, array $permissions): array
    {
        $permissions += [
            'procedimientos' => true,
            'mis_integraciones' => true,
            'integraciones_nextcloud' => in_array($role, ['root', 'gestor'], true),
            'actividad_eliminar' => ! empty($permissions['actividad']),
            'actividad_todos' => ! empty($permissions['actividad']),
            'horas_extra_editar' => ! empty($permissions['horas_extra']),
            'reportes_editar' => ! empty($permissions['mensajes_acceso']),
            'reportes_eliminar' => ! empty($permissions['mensajes_acceso']),
            'reportes_importar_core' => ! empty($permissions['mensajes_acceso']),
        ];

        return $permissions;
    }

    private function maintenanceUntilText(string $until): string
    {
        if ($until === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $until, new \DateTimeZone('America/Santiago'));

        return $date ? $date->format('d-m-Y H:i') : $until;
    }
}
