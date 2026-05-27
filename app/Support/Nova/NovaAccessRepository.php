<?php

namespace App\Support\Nova;

use App\Support\Auth\NovaUserRepository;
use App\Support\Modules\ModuleRegistry;

final class NovaAccessRepository
{
    public function __construct(
        private NovaUserRepository $users,
        private ModuleRegistry $modules,
    ) {
    }

    /**
     * @return array{users:array<int,array<string,mixed>>,modules:array<string,array<string,mixed>>,overrides:array<string,array<string,bool>>,matrix:array<int,array<string,mixed>>}
     */
    public function matrix(): array
    {
        $users = $this->users->all();
        $modules = $this->manageableModules();
        $overrides = $this->overrides();
        $matrix = [];

        foreach ($users as $user) {
            $identity = $this->identity($user);
            if ($identity === '') {
                continue;
            }

            $row = [
                'identity' => $identity,
                'user' => $user,
                'access' => [],
            ];

            foreach ($modules as $key => $module) {
                $default = $this->defaultAccess($user, $key);
                $explicit = $overrides[$identity][$key] ?? null;
                $row['access'][$key] = [
                    'default' => $default,
                    'explicit' => $explicit,
                    'allowed' => is_bool($explicit) ? $explicit : $default,
                    'source' => is_bool($explicit) ? 'manual' : $this->defaultSource($key, $default),
                    'module' => $module,
                ];
            }

            $matrix[] = $row;
        }

        return [
            'users' => $users,
            'modules' => $modules,
            'overrides' => $overrides,
            'matrix' => $matrix,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function save(array $payload): void
    {
        $matrix = $this->matrix();
        $submitted = $this->submittedAccess($payload);
        $targetIdentity = $this->normalize((string) ($payload['selected_identity'] ?? ''));
        $overrides = $targetIdentity !== '' ? $this->overrides() : [];

        foreach ($matrix['matrix'] as $row) {
            $identity = (string) ($row['identity'] ?? '');
            if ($identity === '') {
                continue;
            }

            if ($targetIdentity !== '' && $identity !== $targetIdentity) {
                continue;
            }

            if ($targetIdentity !== '') {
                unset($overrides[$identity]);
            }

            foreach ($matrix['modules'] as $moduleKey => $module) {
                $allowed = !empty($submitted[$identity][$moduleKey]);
                $default = (bool) ($row['access'][$moduleKey]['default'] ?? false);

                if ($allowed !== $default) {
                    $overrides[$identity][$moduleKey] = $allowed;
                }
            }
        }

        $this->write($overrides);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,array<string,bool>>
     */
    private function submittedAccess(array $payload): array
    {
        if (isset($payload['module_users']) && is_array($payload['module_users'])) {
            $submitted = [];

            foreach ($payload['module_users'] as $moduleKey => $identities) {
                foreach ((array) $identities as $identity) {
                    $identity = $this->normalize((string) $identity);
                    if ($identity !== '') {
                        $submitted[$identity][(string) $moduleKey] = true;
                    }
                }
            }

            return $submitted;
        }

        return (array) ($payload['access'] ?? []);
    }

    /**
     * @param array<string,mixed> $user
     */
    public function explicitAccess(array $user, string $moduleKey): ?bool
    {
        if ($moduleKey === 'administracion') {
            return null;
        }

        $identity = $this->identity($user);
        if ($identity === '') {
            return null;
        }

        $value = $this->overrides()[$identity][$moduleKey] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * @param array<string,mixed> $user
     */
    public function canAccess(array $user, string $moduleKey): bool
    {
        if ($moduleKey === 'administracion') {
            return $this->defaultAccess($user, $moduleKey);
        }

        $explicit = $this->explicitAccess($user, $moduleKey);

        return is_bool($explicit) ? $explicit : $this->defaultAccess($user, $moduleKey);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function manageableModules(): array
    {
        return array_filter($this->modules->enabled(), static function (array $module, string $key): bool {
            return $key !== 'administracion';
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param array<string,mixed> $user
     */
    private function defaultAccess(array $user, string $moduleKey): bool
    {
        if ($moduleKey === 'administracion') {
            return in_array((string) ($user['role'] ?? 'usuario'), config('nova.module_admin_roles', []), true);
        }

        return $this->projectUserExists($moduleKey, $user);
    }

    private function defaultSource(string $moduleKey, bool $default): string
    {
        if (!$default) {
            return 'sin acceso';
        }

        return $moduleKey === 'administracion' ? 'admin' : 'redmine';
    }

    /**
     * @param array<string,mixed> $sessionUser
     */
    private function projectUserExists(string $moduleKey, array $sessionUser): bool
    {
        try {
            $module = $this->modules->get($moduleKey);
        } catch (\Throwable) {
            return false;
        }

        $path = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'usuarios.json';

        if (!is_file($path)) {
            return false;
        }

        $records = json_decode((string) file_get_contents($path), true);
        if (!is_array($records)) {
            return false;
        }

        $needles = array_filter(array_map([$this, 'normalize'], [
            $sessionUser['username'] ?? '',
            $sessionUser['redmine_id'] ?? '',
            $sessionUser['id'] ?? '',
            $sessionUser['rut'] ?? '',
            $sessionUser['rut_sin_dv'] ?? '',
            $sessionUser['core_user'] ?? '',
        ]));

        foreach ($records as $record) {
            if (!is_array($record) || $this->isBlocked($record)) {
                continue;
            }

            $candidates = array_filter(array_map([$this, 'normalize'], [
                $record['id'] ?? '',
                $record['rut'] ?? '',
                $record['rut_sin_dv'] ?? '',
                $record['core_user'] ?? '',
                $record['nextcloud_user'] ?? '',
            ]));

            if (array_intersect($needles, $candidates) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,array<string,bool>>
     */
    private function overrides(): array
    {
        $raw = (string) @file_get_contents($this->path());
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,array<string,bool>> $overrides
     */
    private function write(array $overrides): void
    {
        $directory = dirname($this->path());
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->path(), json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * @param array<string,mixed> $user
     */
    private function identity(array $user): string
    {
        foreach (['username', 'rut_sin_dv', 'rut', 'redmine_id', 'id'] as $field) {
            $value = trim((string) ($user[$field] ?? ''));
            if ($value !== '') {
                return $this->normalize($value);
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $user
     */
    private function isBlocked(array $user): bool
    {
        $state = strtolower(trim((string) ($user['status'] ?? $user['estado'] ?? $user['estado_usuario'] ?? 'activo')));

        return in_array($state, ['baneado', 'bloqueado', 'inactivo'], true);
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9a-z]/i', '', $value));
    }

    private function path(): string
    {
        return storage_path('app/nova/access.json');
    }
}
