<?php

namespace App\Support\Modules;

use App\Support\Nova\NovaAccessRepository;
use App\Support\RedmineMantencion\RedmineMantencionStorageRepository;
use RedmineTic\Support\Redmine\RedmineDataRepository;

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

        $module = $this->modules->get($projectKey);
        $needles = $this->sessionNeedles($sessionUser);

        if ($projectKey === 'redmine_tic' && class_exists(RedmineDataRepository::class)) {
            try {
                $user = $this->projectUserFromRows(app(RedmineDataRepository::class)->forProject($projectKey)->users(), $needles);
                if ($user !== null) {
                    return $user;
                }
            } catch (\Throwable) {
            }
        }

        if ($projectKey === 'redmine-mantencion' && class_exists(RedmineMantencionStorageRepository::class)) {
            try {
                $users = app(RedmineMantencionStorageRepository::class)->readJson('usuarios.json');
                if (is_array($users)) {
                    $user = $this->projectUserFromRows($users, $needles);
                    if ($user !== null) {
                        return $user;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $path = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'usuarios.json';

        if (is_file($path)) {
            $users = json_decode((string) file_get_contents($path), true);
            if (is_array($users)) {
                $user = $this->projectUserFromRows($users, $needles);
                if ($user !== null) {
                    return $user;
                }
            }
        }

        $novaProjectUser = $this->novaProjectUser($projectKey, $sessionUser, $needles);
        if ($novaProjectUser !== null) {
            return $novaProjectUser;
        }

        if ($explicitAccess === true) {
            return $this->sessionProjectUser($sessionUser);
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
            'id' => (string) ($sessionUser['redmine_id'] ?? $sessionUser['id'] ?? ''),
            'rut_sin_dv' => (string) ($sessionUser['rut_sin_dv'] ?? $sessionUser['username'] ?? ''),
            'nombre' => (string) ($sessionUser['name'] ?? ''),
            'apellido' => (string) ($sessionUser['apellido'] ?? ''),
            'rut' => (string) ($sessionUser['rut'] ?? ''),
            'api' => (string) ($sessionUser['api'] ?? ''),
            'rol' => $isAdmin ? 'root' : (string) ($sessionUser['role'] ?? 'usuario'),
            'estado_usuario' => (string) ($sessionUser['status'] ?? 'activo'),
            'permisos' => $isAdmin ? ['all' => true] : [],
            '_nova_user_id' => (string) ($sessionUser['id'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $sessionUser
     * @return array<string,mixed>
     */
    private function adminProjectUser(string $projectKey, array $sessionUser): array
    {
        $projectUser = $this->findProjectUser($projectKey, $this->sessionNeedles($sessionUser));
        $fallback = $this->sessionProjectUser($sessionUser);

        if ($projectUser === null) {
            return $fallback;
        }

        return array_merge($projectUser, [
            'rol' => 'root',
            'estado_usuario' => 'activo',
            'permisos' => array_merge(
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
     * @param array<string,mixed> $sessionUser
     * @return array<string,mixed>|null
     */
    private function novaProjectUser(string $projectKey, array $sessionUser, ?array $needles = null): ?array
    {
        $rows = json_decode((string) @file_get_contents(storage_path('app/nova/users.json')), true);
        if (!is_array($rows)) {
            return null;
        }

        $needles = $needles ?? $this->sessionNeedles($sessionUser);

        foreach ($rows as $row) {
            if (!is_array($row) || $this->isBlocked($row)) {
                continue;
            }

            $project = is_array(data_get($row, 'projects.' . $projectKey)) ? data_get($row, 'projects.' . $projectKey) : [];
            if ($project === [] || $this->isBlocked($project)) {
                continue;
            }

            $candidates = array_filter(array_map([$this, 'normalize'], [
                $row['username'] ?? '',
                $row['redmine_id'] ?? '',
                $row['id'] ?? '',
                $row['rut'] ?? '',
                $row['rut_sin_dv'] ?? '',
                $row['core_user'] ?? '',
                $project['id'] ?? '',
            ]));

            if (array_intersect($needles, $candidates) === []) {
                continue;
            }

            return [
                'id' => (string) ($project['id'] ?? $row['redmine_id'] ?? ''),
                'rut_sin_dv' => (string) ($row['rut_sin_dv'] ?? $row['username'] ?? ''),
                'nombre' => (string) ($row['name'] ?? ''),
                'apellido' => (string) ($row['apellido'] ?? ''),
                'rut' => (string) ($row['rut'] ?? ''),
                'api' => (string) ($project['api'] ?? $row['api'] ?? ''),
                'rol' => (string) ($project['rol'] ?? $row['role'] ?? 'usuario'),
                'estado_usuario' => (string) ($project['estado_usuario'] ?? $row['status'] ?? 'activo'),
                'permisos' => is_array($project['permisos'] ?? null) ? $project['permisos'] : [],
                '_nova_user_id' => (string) ($row['id'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * @param array<int,string> $needles
     * @return array<string,mixed>|null
     */
    private function findProjectUser(string $projectKey, array $needles): ?array
    {
        if ($projectKey === 'redmine_tic' && class_exists(RedmineDataRepository::class)) {
            try {
                $user = $this->projectUserFromRows(app(RedmineDataRepository::class)->forProject($projectKey)->users(), $needles);
                if ($user !== null) {
                    return $user;
                }
            } catch (\Throwable) {
            }
        }

        if ($projectKey === 'redmine-mantencion' && class_exists(RedmineMantencionStorageRepository::class)) {
            try {
                $users = app(RedmineMantencionStorageRepository::class)->readJson('usuarios.json');
                if (is_array($users)) {
                    $user = $this->projectUserFromRows($users, $needles);
                    if ($user !== null) {
                        return $user;
                    }
                }
            } catch (\Throwable) {
            }
        }

        try {
            $module = $this->modules->get($projectKey);
        } catch (\Throwable) {
            return null;
        }

        $path = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'usuarios.json';

        if (!is_file($path)) {
            return null;
        }

        $users = json_decode((string) file_get_contents($path), true);
        if (!is_array($users)) {
            return null;
        }

        return $this->projectUserFromRows($users, $needles);
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9a-z]/i', '', $value));
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
                $user['id'] ?? '',
                $user['rut'] ?? '',
                $user['rut_sin_dv'] ?? '',
                $user['core_user'] ?? '',
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
            $sessionUser['username'] ?? '',
            $sessionUser['redmine_id'] ?? '',
            $sessionUser['id'] ?? '',
            $sessionUser['rut'] ?? '',
            $sessionUser['rut_sin_dv'] ?? '',
            $sessionUser['core_user'] ?? '',
            data_get($sessionUser, 'legacy.id', ''),
            data_get($sessionUser, 'legacy.rut', ''),
        ]));
    }
}
