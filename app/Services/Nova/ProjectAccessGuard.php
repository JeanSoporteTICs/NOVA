<?php

namespace App\Services\Nova;

use App\Contracts\ProjectUserProviderInterface;
use App\Repositories\Modules\ModuleRegistry;
use App\Repositories\Nova\NovaAccessRepository;
use App\Support\StringNormalizer;

final class ProjectAccessGuard
{
    public function __construct(private ModuleRegistry $modules)
    {
    }

    /**
     * @param array<string,mixed> $sessionUser
     */
    public function canAccess(string $projectKey, array $sessionUser): bool
    {
        return $this->projectUser($projectKey, $sessionUser) !== null;
    }

    /**
     * @param array<string,mixed> $sessionUser
     * @return array<string,mixed>|null
     */
    public function projectUser(string $projectKey, array $sessionUser): ?array
    {
        if ($this->isAdmin($sessionUser)) {
            return $this->adminProjectUser($projectKey, $sessionUser);
        }

        $explicitAccess = null;
        try {
            $explicitAccess = app(NovaAccessRepository::class)->explicitAccess($sessionUser, $projectKey);
            if ($explicitAccess === false) {
                return null;
            }
        } catch (\Throwable) {
        }

        $module  = $this->modules->get($projectKey);
        $needles = $this->sessionNeedles($sessionUser);

        if ($explicitAccess === true) {
            return $this->sessionProjectUser($sessionUser);
        }

        $user = $this->findProjectUser($projectKey, $needles);
        if ($user !== null) {
            return $user;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $sessionUser
     * @return array<string,mixed>
     */
    private function sessionProjectUser(array $sessionUser): array
    {
        $isAdmin = $this->isAdmin($sessionUser);

        return [
            'id'             => (string) ($sessionUser['redmine_id']     ?? $sessionUser['id'] ?? ''),
            'rut_sin_dv'     => (string) ($sessionUser['rut_sin_dv']     ?? $sessionUser['username'] ?? ''),
            'nombre'         => (string) ($sessionUser['name']           ?? ''),
            'apellido'       => (string) ($sessionUser['apellido']       ?? ''),
            'rut'            => (string) ($sessionUser['rut']            ?? ''),
            'api'            => (string) ($sessionUser['api']            ?? ''),
            'core_user'      => (string) ($sessionUser['core_user']      ?? ''),
            'nextcloud_user' => (string) ($sessionUser['nextcloud_user'] ?? ''),
            'rol'            => $isAdmin ? 'root' : (string) ($sessionUser['role'] ?? 'usuario'),
            'estado_usuario' => (string) ($sessionUser['status']         ?? 'activo'),
            'estado'         => (string) ($sessionUser['status']         ?? 'activo'),
            'permisos'       => $isAdmin ? ['all' => true] : [],
            '_nova_user_id'  => (string) ($sessionUser['id']             ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $sessionUser
     * @return array<string,mixed>
     */
    private function adminProjectUser(string $projectKey, array $sessionUser): array
    {
        $projectUser = $this->findProjectUser($projectKey, $this->sessionNeedles($sessionUser));
        $fallback    = $this->sessionProjectUser($sessionUser);

        if ($projectUser === null) {
            return $fallback;
        }

        return array_merge($projectUser, [
            'rol'            => 'root',
            'estado_usuario' => 'activo',
            'permisos'       => array_merge(
                is_array($projectUser['permisos'] ?? null) ? $projectUser['permisos'] : [],
                ['all' => true]
            ),
            '_nova_user_id' => (string) ($sessionUser['id'] ?? $projectUser['_nova_user_id'] ?? ''),
        ]);
    }

    public function deniedMessage(string $projectName = 'Redmine'): string
    {
        return 'No tienes acceso a ' . $projectName . '. Debes contactar con el administrador del Redmine.';
    }

    /**
     * @param array<string,mixed> $user
     */
    private function isBlocked(array $user): bool
    {
        $state = strtolower(trim((string) ($user['estado'] ?? $user['estado_usuario'] ?? $user['status'] ?? 'activo')));

        return in_array($state, ['baneado', 'bloqueado', 'inactivo'], true);
    }

    /**
     * @param array<string,mixed> $user
     */
    private function isAdmin(array $user): bool
    {
        $role = strtolower(trim((string) ($user['role'] ?? $user['rol'] ?? 'usuario')));

        return in_array($role, config('nova.module_admin_roles', []), true);
    }

    /**
     * @param array<int,string> $needles
     * @return array<string,mixed>|null
     */
    private function findProjectUser(string $projectKey, array $needles): ?array
    {
        if (!app()->bound(ProjectUserProviderInterface::class)) {
            return null;
        }

        try {
            $provider = app(ProjectUserProviderInterface::class);
            return $this->projectUserFromRows($provider->projectUsers($projectKey), $needles);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalize(string $value): string
    {
        return StringNormalizer::normalize($value);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $needles
     * @return array<string,mixed>|null
     */
    private function projectUserFromRows(array $rows, array $needles): ?array
    {
        foreach ($rows as $user) {
            if (!is_array($user) || $this->isBlocked($user)) {
                continue;
            }

            $candidates = array_filter(array_map([$this, 'normalize'], [
                $user['id']             ?? '',
                $user['rut']            ?? '',
                $user['rut_sin_dv']     ?? '',
                $user['core_user']      ?? '',
                $user['nextcloud_user'] ?? '',
            ]));

            if (array_intersect($needles, $candidates) !== []) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $sessionUser
     * @return array<int,string>
     */
    private function sessionNeedles(array $sessionUser): array
    {
        return array_filter(array_map([$this, 'normalize'], [
            $sessionUser['username']   ?? '',
            $sessionUser['redmine_id'] ?? '',
            $sessionUser['id']         ?? '',
            $sessionUser['rut']        ?? '',
            $sessionUser['rut_sin_dv'] ?? '',
            $sessionUser['core_user']  ?? '',
            data_get($sessionUser, 'legacy.id', ''),
            data_get($sessionUser, 'legacy.rut', ''),
        ]));
    }
}
