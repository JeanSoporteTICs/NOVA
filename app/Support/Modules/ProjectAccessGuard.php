<?php

namespace App\Support\Modules;

use App\Support\Nova\NovaAccessRepository;

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
        try {
            if (app(NovaAccessRepository::class)->explicitAccess($sessionUser, $projectKey) === false) {
                return null;
            }
        } catch (\Throwable) {
        }

        $module = $this->modules->get($projectKey);
        $path = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'usuarios.json';

        if (!is_file($path)) {
            return false;
        }

        $users = json_decode((string) file_get_contents($path), true);
        if (!is_array($users)) {
            return false;
        }

        $needles = array_filter(array_map([$this, 'normalize'], [
            $sessionUser['username'] ?? '',
            $sessionUser['redmine_id'] ?? '',
            $sessionUser['id'] ?? '',
            $sessionUser['rut'] ?? '',
            $sessionUser['rut_sin_dv'] ?? '',
            $sessionUser['core_user'] ?? '',
            data_get($sessionUser, 'legacy.id', ''),
            data_get($sessionUser, 'legacy.rut', ''),
        ]));

        foreach ($users as $user) {
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

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9a-z]/i', '', $value));
    }
}
