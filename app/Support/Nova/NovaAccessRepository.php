<?php

namespace App\Support\Nova;

use App\Support\Auth\NovaUserRepository;
use App\Support\Modules\ModuleRegistry;
use App\Support\RedmineMantencion\RedmineMantencionStorageRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RedmineTic\Support\Redmine\RedmineDataRepository;

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
        if ($this->isAdmin($user)) {
            return true;
        }

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
        if ($this->isAdmin($user)) {
            return true;
        }

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
        if ($this->isAdmin($user)) {
            return true;
        }

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
        if ($moduleKey === 'redmine_tic' && class_exists(RedmineDataRepository::class)) {
            try {
                return $this->projectUserExistsInRows(app(RedmineDataRepository::class)->forProject($moduleKey)->users(), $sessionUser);
            } catch (\Throwable) {
                return false;
            }
        }

        if ($this->novaProjectUserExists($moduleKey, $sessionUser)) {
            return true;
        }

        try {
            $module = $this->modules->get($moduleKey);
        } catch (\Throwable) {
            return false;
        }

        if ($moduleKey === 'redmine-mantencion' && class_exists(RedmineMantencionStorageRepository::class)) {
            try {
                $records = app(RedmineMantencionStorageRepository::class)->readJson('usuarios.json');
                return is_array($records) ? $this->projectUserExistsInRows($records, $sessionUser) : false;
            } catch (\Throwable) {
            }
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

        return $this->projectUserExistsInRows($records, $sessionUser);
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @param array<string,mixed> $sessionUser
     */
    private function projectUserExistsInRows(array $records, array $sessionUser): bool
    {
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
     * @param array<string,mixed> $sessionUser
     */
    private function novaProjectUserExists(string $moduleKey, array $sessionUser): bool
    {
        $rows = json_decode((string) @file_get_contents(storage_path('app/nova/users.json')), true);
        if (!is_array($rows)) {
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

        foreach ($rows as $row) {
            if (!is_array($row) || $this->isBlocked($row)) {
                continue;
            }

            $project = is_array(data_get($row, 'projects.' . $moduleKey)) ? data_get($row, 'projects.' . $moduleKey) : [];
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
        $databaseOverrides = $this->databaseOverrides();
        $raw = (string) @file_get_contents($this->path());
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $data = json_decode($raw, true);

        $fileOverrides = is_array($data) ? $data : [];

        return array_replace_recursive($fileOverrides, $databaseOverrides);
    }

    /**
     * @param array<string,array<string,bool>> $overrides
     */
    private function write(array $overrides): void
    {
        $this->writeDatabaseOverrides($overrides);

        $directory = dirname($this->path());
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->path(), json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * @return array<string,array<string,bool>>
     */
    private function databaseOverrides(): array
    {
        if (!$this->accessTablesAvailable()) {
            return [];
        }

        try {
            $rows = DB::table('permisos_usuario_modulo')
                ->join('usuarios_nova', 'usuarios_nova.id', '=', 'permisos_usuario_modulo.usuario_id')
                ->join('modulos_nova', 'modulos_nova.id', '=', 'permisos_usuario_modulo.modulo_id')
                ->select([
                    'usuarios_nova.uuid',
                    'usuarios_nova.usuario',
                    'usuarios_nova.rut',
                    'usuarios_nova.redmine_id',
                    'usuarios_nova.usuario_core',
                    'modulos_nova.clave_modulo',
                    'permisos_usuario_modulo.permitido',
                ])
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $overrides = [];
        foreach ($rows as $row) {
            $identity = $this->identity([
                'username' => $row->usuario ?? '',
                'rut' => $row->rut ?? '',
                'rut_sin_dv' => $row->usuario ?? '',
                'redmine_id' => $row->redmine_id ?? '',
                'id' => $row->uuid ?? '',
                'core_user' => $row->usuario_core ?? '',
            ]);
            $moduleKey = trim((string) ($row->clave_modulo ?? ''));
            if ($identity === '' || $moduleKey === '') {
                continue;
            }

            $overrides[$identity][$moduleKey] = (bool) ($row->permitido ?? false);
        }

        return $overrides;
    }

    /**
     * @param array<string,array<string,bool>> $overrides
     */
    private function writeDatabaseOverrides(array $overrides): void
    {
        if (!$this->accessTablesAvailable()) {
            return;
        }

        $users = $this->users->all();
        $modules = $this->manageableModules();

        foreach ($overrides as $identity => $moduleOverrides) {
            $userId = $this->databaseUserIdForIdentity((string) $identity, $users);
            if ($userId === null) {
                continue;
            }

            foreach ($moduleOverrides as $moduleKey => $allowed) {
                if (!array_key_exists((string) $moduleKey, $modules)) {
                    continue;
                }
                $moduleId = $this->databaseModuleId((string) $moduleKey, $modules[(string) $moduleKey]);
                if ($moduleId === null) {
                    continue;
                }

                DB::table('permisos_usuario_modulo')->updateOrInsert(
                    ['usuario_id' => $userId, 'modulo_id' => $moduleId],
                    [
                        'permitido' => (bool) $allowed ? 1 : 0,
                        'actualizado_at' => now(),
                    ]
                );
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function databaseUserIdForIdentity(string $identity, array $users): ?int
    {
        $identity = $this->normalize($identity);
        if ($identity === '') {
            return null;
        }

        foreach ($users as $user) {
            if ($this->identity($user) !== $identity) {
                continue;
            }

            return $this->databaseUserId($user);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function databaseUserId(array $user): ?int
    {
        try {
            foreach (['id' => 'uuid', 'username' => 'usuario', 'rut' => 'rut', 'redmine_id' => 'redmine_id', 'core_user' => 'usuario_core'] as $field => $column) {
                $value = trim((string) ($user[$field] ?? ''));
                if ($value === '') {
                    continue;
                }

                $id = DB::table('usuarios_nova')->where($column, $value)->value('id');
                if ($id !== null) {
                    return (int) $id;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @param array<string,mixed> $module
     */
    private function databaseModuleId(string $moduleKey, array $module): ?int
    {
        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', $moduleKey)->value('id');
            if ($id !== null) {
                return (int) $id;
            }

            DB::table('modulos_nova')->insert([
                'clave_modulo' => $moduleKey,
                'nombre' => (string) ($module['name'] ?? $moduleKey),
                'descripcion' => (string) ($module['description'] ?? ''),
                'icono' => (string) ($module['icon'] ?? ''),
                'tipo' => (string) ($module['type'] ?? 'native'),
                'ruta' => (string) ($module['route'] ?? $module['path'] ?? ''),
                'entrada' => (string) ($module['entry'] ?? ''),
                'activo' => !empty($module['enabled']) ? 1 : 0,
                'orden' => (int) ($module['order'] ?? 100),
                'creado_at' => now(),
                'actualizado_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function accessTablesAvailable(): bool
    {
        try {
            return Schema::hasTable('usuarios_nova')
                && Schema::hasTable('modulos_nova')
                && Schema::hasTable('permisos_usuario_modulo');
        } catch (\Throwable) {
            return false;
        }
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

    /**
     * @param array<string,mixed> $user
     */
    private function isAdmin(array $user): bool
    {
        $role = strtolower(trim((string) ($user['role'] ?? $user['rol'] ?? 'usuario')));

        return in_array($role, config('nova.module_admin_roles', []), true);
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
