<?php

namespace App\Support\Auth;

use App\Models\NovaUser;
use App\Support\Modules\ModuleRegistry;
use App\Support\Integrations\UserIntegrationRepository;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class NovaUserRepository
{
    public function __construct(private ModuleRegistry $modules)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $this->ensureSeeded();
        $this->syncProjectUsers();
        $fileUsers = $this->readUsersFile();
        $users = $this->usersFromDatabase($fileUsers);
        if ($users === []) {
            $users = $fileUsers;
        }

        $deduplicated = $this->deduplicateUsers($users);
        if ($deduplicated !== $users) {
            $this->write($deduplicated);
            $users = $deduplicated;
        }

        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
    }

    public function attempt(string $username, string $password): ?array
    {
        $user = $this->find($username);
        if ($user === null || $this->isBlocked($user)) {
            return null;
        }

        $hash = (string) ($user['password'] ?? '');
        $api = (string) ($user['api'] ?? '');
        $valid = false;

        if ($hash !== '' && strlen($hash) > 20) {
            $valid = password_verify($password, $hash);
        }

        if (!$valid && $hash !== '' && strlen($hash) <= 20 && hash_equals($hash, $password)) {
            $valid = true;
        }

        if (!$valid && $api !== '' && hash_equals($api, $password)) {
            $valid = true;
        }

        if (!$valid) {
            return null;
        }

        $this->markLastLogin($user);

        return $this->toSessionUser($user);
    }

    public function find(string $username): ?array
    {
        $needle = $this->normalize($username);
        if ($needle === '') {
            return null;
        }

        foreach ($this->all() as $user) {
            foreach ($this->loginCandidates($user) as $candidate) {
                if ($needle === $this->normalize((string) $candidate)) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,error:string}
     */
    public function save(array $payload): array
    {
        $users = $this->all();
        $id = trim((string) ($payload['id'] ?? ''));
        $isNew = $id === '';

        if ($isNew) {
            $id = (string) Str::uuid();
        }

        $index = null;
        foreach ($users as $i => $user) {
            if ((string) ($user['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }

        $current = $index !== null ? $users[$index] : [];
        $rut = trim((string) ($payload['rut'] ?? $current['rut'] ?? ''));
        $username = $this->rutAccessUser($rut);
        if ($username === '' && !$isNew) {
            $username = trim((string) ($payload['username'] ?? $current['username'] ?? $current['redmine_id'] ?? ''));
        }

        $name = trim((string) ($payload['name'] ?? $current['name'] ?? ''));
        $apellido = trim((string) ($payload['apellido'] ?? $current['apellido'] ?? ''));
        if ($name === '' || $apellido === '' || $username === '') {
            return ['ok' => false, 'error' => 'Nombre, apellidos y usuario de acceso son obligatorios.'];
        }

        if ($isNew && $rut === '') {
            return ['ok' => false, 'error' => 'El RUT es obligatorio para usuarios nuevos.'];
        }

        if ($rut !== '' && !$this->isValidRut($rut)) {
            return ['ok' => false, 'error' => 'El RUT ingresado no es valido.'];
        }

        foreach ($users as $i => $user) {
            if ($index !== null && $i === $index) {
                continue;
            }

            if ($this->normalize((string) ($user['username'] ?? '')) === $this->normalize($username)) {
                return ['ok' => false, 'error' => 'Ya existe un usuario con ese acceso.'];
            }
        }

        $redmineId = trim((string) ($payload['redmine_id'] ?? $current['redmine_id'] ?? ''));

        foreach ($users as $i => $user) {
            if ($index !== null && $i === $index) {
                continue;
            }

            if ($rut !== '' && $this->normalize((string) ($user['rut'] ?? '')) === $this->normalize($rut)) {
                return ['ok' => false, 'error' => 'Ya existe un usuario con ese RUT.'];
            }

            if ($redmineId !== '' && $this->normalize((string) ($user['redmine_id'] ?? '')) === $this->normalize($redmineId)) {
                return ['ok' => false, 'error' => 'Ya existe un usuario con ese ID Redmine.'];
            }
        }

        $password = (string) ($payload['password'] ?? '');
        $passwordConfirm = (string) ($payload['password_confirmation'] ?? $payload['password_confirm'] ?? '');
        $passwordHash = (string) ($current['password'] ?? '');
        if ($password !== '' || $passwordConfirm !== '') {
            if ($password === '' || $passwordConfirm === '' || !hash_equals($password, $passwordConfirm)) {
                return ['ok' => false, 'error' => 'La contrasena y su validacion no coinciden.'];
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }

        if ($passwordHash === '') {
            return ['ok' => false, 'error' => 'La contrasena es obligatoria para usuarios nuevos.'];
        }

        $integrations = app(UserIntegrationRepository::class);
        $emachCredentials = $integrations->emachFromPayload($payload, $current);
        $telegramSettings = $integrations->telegramFromPayload($payload, $current);
        $row = [
            'id' => $id,
            'redmine_id' => $redmineId,
            'username' => $username,
            'name' => $name,
            'apellido' => $apellido,
            'rut' => $rut,
            'rut_sin_dv' => $rut !== '' ? $username : (string) ($current['rut_sin_dv'] ?? ''),
            'core_user' => trim((string) ($payload['core_user'] ?? $current['core_user'] ?? '')),
            'role' => $this->normalizeNovaRole((string) ($payload['role'] ?? 'usuario')),
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? 'activo')),
            'password' => $passwordHash,
        ];
        if ($emachCredentials !== []) {
            $row['emach_credentials'] = $emachCredentials;
        }
        if ($telegramSettings !== []) {
            $row['telegram_settings'] = $telegramSettings;
        }

        if ($index === null) {
            $users[] = $row;
        } else {
            $users[$index] = $row;
        }

        $this->write($users);

        return ['ok' => true, 'error' => ''];
    }

    public function delete(string $id): int
    {
        return $this->setStatus($id, 'baneado');
    }

    /**
     * @return array{ok:bool,error:string}
     */
    public function changePassword(string $id, string $password, string $passwordConfirm): array
    {
        $users = $this->all();
        $index = null;
        foreach ($users as $i => $user) {
            if ((string) ($user['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return ['ok' => false, 'error' => 'Usuario no encontrado.'];
        }

        if ($password === '' || $passwordConfirm === '' || !hash_equals($password, $passwordConfirm)) {
            return ['ok' => false, 'error' => 'La contrasena y su validacion no coinciden.'];
        }

        $users[$index]['password'] = password_hash($password, PASSWORD_DEFAULT);
        $this->write($users);

        return ['ok' => true, 'error' => ''];
    }

    public function activate(string $id): int
    {
        return $this->setStatus($id, 'activo');
    }

    /**
     * @return array<int,string>
     */
    private function loginCandidates(array $user): array
    {
        return array_values(array_filter([
            $user['username'] ?? null,
            $user['redmine_id'] ?? null,
            $user['id'] ?? null,
            $user['rut'] ?? null,
            $user['rut_sin_dv'] ?? null,
            $user['core_user'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }

    private function toSessionUser(array $user): array
    {
        return [
            'id' => (string) ($user['id'] ?? ''),
            'redmine_id' => (string) ($user['redmine_id'] ?? ''),
            'username' => (string) ($user['username'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'apellido' => (string) ($user['apellido'] ?? ''),
            'rut' => (string) ($user['rut'] ?? ''),
            'rut_sin_dv' => (string) ($user['rut_sin_dv'] ?? ''),
            'core_user' => (string) ($user['core_user'] ?? ''),
            'role' => $this->normalizeNovaRole((string) ($user['role'] ?? 'usuario')),
            'has_emach_credentials' => app(UserIntegrationRepository::class)->hasEmach($user),
            'has_telegram_settings' => app(UserIntegrationRepository::class)->hasTelegram($user),
            'source' => 'nova',
            'legacy' => [
                'id' => (string) ($user['redmine_id'] ?? $user['username'] ?? $user['id'] ?? ''),
                'nombre' => (string) ($user['name'] ?? ''),
                'rut' => (string) ($user['rut'] ?? ''),
                'rol' => $this->normalizeNovaRole((string) ($user['role'] ?? 'usuario')),
            ],
        ];
    }

    private function ensureSeeded(): void
    {
        if (is_file($this->path())) {
            return;
        }

        $this->write([]);
    }

    private function syncProjectUsers(): void
    {
        $users = $this->readUsersFile();
        $projectUsers = $this->projectUsers();

        $changed = false;
        foreach ($projectUsers as $projectUser) {
            if (!is_array($projectUser)) {
                continue;
            }

            $username = $this->projectUsername($projectUser);
            $name = $this->projectFirstName($projectUser);
            $apellido = $this->projectLastName($projectUser);
            $redmineId = trim((string) ($projectUser['id'] ?? ''));
            if ($username === '' || $name === '') {
                continue;
            }

            $index = $this->findIndexForProjectUser($users, $projectUser, $username);
            if ($index === null) {
                $users[] = [
                    'id' => (string) Str::uuid(),
                    'redmine_id' => $redmineId,
                    'source' => (string) ($projectUser['_nova_project'] ?? ''),
                    'username' => $username,
                    'name' => $name,
                    'apellido' => $apellido,
                    'rut' => (string) ($projectUser['rut'] ?? ''),
                    'rut_sin_dv' => (string) ($projectUser['rut_sin_dv'] ?? ''),
                    'core_user' => (string) ($projectUser['core_user'] ?? ''),
                    'role' => $this->projectRole($projectUser),
                    'status' => $this->projectStatus($projectUser),
                    'password' => (string) ($projectUser['password'] ?? ''),
                    'api' => (string) ($projectUser['api'] ?? ''),
                ];
                $changed = true;
                continue;
            }

            $updated = array_merge($users[$index], [
                'redmine_id' => $redmineId !== '' ? $redmineId : (string) ($users[$index]['redmine_id'] ?? ''),
                'source' => $this->mergeSources((string) ($users[$index]['source'] ?? ''), (string) ($projectUser['_nova_project'] ?? '')),
                'username' => (string) ($users[$index]['username'] ?? '') !== '' ? (string) $users[$index]['username'] : $username,
                'name' => $name,
                'apellido' => $apellido !== '' ? $apellido : (string) ($users[$index]['apellido'] ?? ''),
                'rut' => $this->preferFilled((string) ($projectUser['rut'] ?? ''), (string) ($users[$index]['rut'] ?? '')),
                'rut_sin_dv' => $this->preferFilled((string) ($projectUser['rut_sin_dv'] ?? ''), (string) ($users[$index]['rut_sin_dv'] ?? '')),
                'core_user' => $this->preferFilled((string) ($projectUser['core_user'] ?? ''), (string) ($users[$index]['core_user'] ?? '')),
                'role' => $this->projectRole($projectUser),
            ]);

            $updated['status'] = $this->projectStatus($projectUser);

            if ($updated !== $users[$index]) {
                $users[$index] = $updated;
                $changed = true;
            }
        }

        if ($this->syncNovaStatusesFromProjectUsers($users, $projectUsers)) {
            $changed = true;
        }

        $deduplicated = $this->deduplicateUsers($users);
        if ($deduplicated !== $users) {
            $users = $deduplicated;
            $changed = true;
        }

        if ($changed) {
            $this->write($users);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function projectUsers(): array
    {
        $users = [];
        foreach (['redmine_tic', 'redmine-mantencion'] as $moduleKey) {
            try {
                $module = $this->modules->get($moduleKey);
                $sourcePath = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'usuarios.json';
                $sourceUsers = json_decode((string) @file_get_contents($sourcePath), true);
                if (is_array($sourceUsers)) {
                    foreach ($sourceUsers as $user) {
                        if (is_array($user)) {
                            $user['_nova_project'] = $moduleKey;
                            $users[] = $user;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $users;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<int,array<string,mixed>> $legacyUsers
     * @return array<string,mixed>|null
     */
    private function findIndexForProjectUser(array $users, array $projectUser, string $username): ?int
    {
        $source = (string) ($projectUser['_nova_project'] ?? '');
        $redmineId = $this->normalize((string) ($projectUser['id'] ?? ''));
        $projectName = $this->normalize(trim($this->projectFirstName($projectUser) . ' ' . $this->projectLastName($projectUser)));

        foreach ($users as $index => $user) {
            if ($source === '' || !in_array($source, $this->splitSources((string) ($user['source'] ?? '')), true)) {
                continue;
            }

            if ($redmineId !== '' && $redmineId === $this->normalize((string) ($user['redmine_id'] ?? ''))) {
                return $index;
            }
        }

        foreach ($this->identityKeysForProjectUser($projectUser, $username) as $projectKey) {
            foreach ($users as $index => $user) {
                if (in_array($projectKey, $this->identityKeysForNovaUser($user), true)) {
                    return $index;
                }
            }
        }

        if ($projectName === '') {
            return null;
        }

        foreach ($users as $index => $user) {
            $userName = $this->normalize($this->fullName($user));
            if ($userName === '') {
                continue;
            }

            $userRedmineId = $this->normalize((string) ($user['redmine_id'] ?? ''));
            if ($redmineId !== '' && $userRedmineId !== '' && $redmineId === $userRedmineId && $projectName === $userName) {
                return $index;
            }

            $userNameKey = $this->normalize((string) ($user['username'] ?? ''));
            if ($username !== '' && $userNameKey !== '' && $this->normalize($username) === $userNameKey && $projectName === $userName) {
                return $index;
            }
        }

        return null;
    }

    private function projectUsername(array $user): string
    {
        $rutUser = $this->rutAccessUser((string) ($user['rut'] ?? ''));
        if ($rutUser !== '') {
            return $rutUser;
        }

        foreach (['rut_sin_dv', 'core_user', 'id'] as $field) {
            $value = trim((string) ($user[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function projectFirstName(array $user): string
    {
        return trim((string) ($user['nombre'] ?? ''));
    }

    private function projectLastName(array $user): string
    {
        return trim((string) ($user['apellido'] ?? ''));
    }

    private function projectRole(array $user): string
    {
        return $this->normalizeNovaRole((string) ($user['rol'] ?? $user['role'] ?? 'usuario'));
    }

    private function projectStatus(array $user): string
    {
        return $this->normalizeStatus((string) ($user['status'] ?? $user['estado'] ?? $user['estado_usuario'] ?? 'activo'));
    }

    /**
     * @param array<int,array<string,mixed>> $users
     * @param array<int,array<string,mixed>> $projectUsers
     */
    private function syncNovaStatusesFromProjectUsers(array &$users, array $projectUsers): bool
    {
        $changed = false;

        foreach ($users as $index => $user) {
            if (!is_array($user)) {
                continue;
            }

            $matchedProjectUsers = array_values(array_filter(
                $projectUsers,
                fn (array $projectUser): bool => $this->projectUserMatchesNovaUser($user, $projectUser)
            ));

            if ($matchedProjectUsers === []) {
                continue;
            }

            $hasActiveProject = false;
            foreach ($matchedProjectUsers as $projectUser) {
                if ($this->projectStatus($projectUser) === 'activo') {
                    $hasActiveProject = true;
                    break;
                }
            }

            $nextStatus = $hasActiveProject ? 'activo' : 'baneado';
            if (($users[$index]['status'] ?? 'activo') !== $nextStatus) {
                $users[$index]['status'] = $nextStatus;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $projectUser
     */
    private function projectUserMatchesNovaUser(array $user, array $projectUser): bool
    {
        foreach ($this->identityKeysForProjectUser($projectUser, $this->projectUsername($projectUser)) as $projectKey) {
            if (in_array($projectKey, $this->identityKeysForNovaUser($user), true)) {
                return true;
            }
        }

        $sameSource = (string) ($user['source'] ?? '') !== ''
            && in_array((string) ($projectUser['_nova_project'] ?? ''), $this->splitSources((string) ($user['source'] ?? '')), true);

        $userRedmineId = $this->normalize((string) ($user['redmine_id'] ?? ''));
        $projectRedmineId = $this->normalize((string) ($projectUser['id'] ?? ''));
        if ($sameSource && $userRedmineId !== '' && $userRedmineId === $projectRedmineId) {
            return true;
        }

        $userName = $this->normalize($this->fullName($user));
        $projectName = $this->normalize(trim($this->projectFirstName($projectUser) . ' ' . $this->projectLastName($projectUser)));

        return $userRedmineId !== ''
            && $projectRedmineId !== ''
            && $userRedmineId === $projectRedmineId
            && $userName !== ''
            && $projectName !== ''
            && $userName === $projectName;
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function deduplicateUsers(array $users): array
    {
        $result = [];
        $keys = [];

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $key = $this->dedupeKey($user);
            if ($key === '' || !isset($keys[$key])) {
                $keys[$key] = count($result);
                $result[] = $user;
                continue;
            }

            $index = $keys[$key];
            $result[$index] = $this->mergeDuplicateUsers($result[$index], $user);
        }

        return array_values($result);
    }

    /**
     * @param array<string,mixed> $user
     */
    private function dedupeKey(array $user): string
    {
        $identityKeys = $this->identityKeysForNovaUser($user);
        if ($identityKeys !== []) {
            return $identityKeys[0];
        }

        $username = $this->normalize((string) ($user['username'] ?? ''));
        $redmineId = $this->normalize((string) ($user['redmine_id'] ?? ''));
        $name = $this->normalize($this->fullName($user));
        if ($username !== '' && $name !== '') {
            return 'user-name:' . $username . ':' . $name;
        }
        if ($redmineId !== '' && $name !== '') {
            return 'redmine-name:' . $redmineId . ':' . $name;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $primary
     * @param array<string,mixed> $duplicate
     * @return array<string,mixed>
     */
    private function mergeDuplicateUsers(array $primary, array $duplicate): array
    {
        $merged = $primary;

        foreach (['redmine_id', 'username', 'name', 'apellido', 'rut', 'rut_sin_dv', 'core_user', 'api', 'password'] as $field) {
            if (trim((string) ($merged[$field] ?? '')) === '' && trim((string) ($duplicate[$field] ?? '')) !== '') {
                $merged[$field] = $duplicate[$field];
            }
        }
        $merged['source'] = $this->mergeSources((string) ($merged['source'] ?? ''), (string) ($duplicate['source'] ?? ''));

        if ($this->normalizeNovaRole((string) ($duplicate['role'] ?? 'usuario')) === 'admin') {
            $merged['role'] = 'admin';
        }
        if ($this->normalizeStatus((string) ($duplicate['status'] ?? 'activo')) === 'activo') {
            $merged['status'] = 'activo';
        }

        foreach (['projects', 'emach_credentials', 'telegram_settings'] as $field) {
            if (is_array($duplicate[$field] ?? null)) {
                $merged[$field] = array_replace_recursive(
                    is_array($merged[$field] ?? null) ? $merged[$field] : [],
                    $duplicate[$field]
                );
            }
        }

        return $merged;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function fullName(array $user): string
    {
        return trim((string) (($user['name'] ?? $user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')));
    }

    /**
     * @return array<int,string>
     */
    private function identityKeysForProjectUser(array $projectUser, string $username): array
    {
        return $this->identityKeys([
            $projectUser['rut'] ?? '',
            $projectUser['rut_sin_dv'] ?? '',
            $projectUser['core_user'] ?? '',
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function identityKeysForNovaUser(array $user): array
    {
        return $this->identityKeys([
            $user['rut'] ?? '',
            $user['rut_sin_dv'] ?? '',
            $user['core_user'] ?? '',
        ]);
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function identityKeys(array $values): array
    {
        $keys = [];
        foreach ($values as $value) {
            $normalized = $this->normalize((string) $value);
            if ($normalized !== '' && !in_array('identity:' . $normalized, $keys, true)) {
                $keys[] = 'identity:' . $normalized;
            }
        }

        return $keys;
    }

    private function preferFilled(string $preferred, string $fallback): string
    {
        $preferred = trim($preferred);

        return $preferred !== '' ? $preferred : $fallback;
    }

    private function mergeSources(string $current, string $next): string
    {
        $sources = $this->splitSources($current);
        $next = trim($next);
        if ($next !== '' && !in_array($next, $sources, true)) {
            $sources[] = $next;
        }

        return implode(',', $sources);
    }

    /**
     * @return array<int,string>
     */
    private function splitSources(string $source): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $source))));
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function write(array $users): void
    {
        $this->writeUsersToDatabase($users);

        $directory = dirname($this->path());
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->path(), json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($this->path(), 0666);
    }

    private function path(): string
    {
        return storage_path('app/nova/users.json');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readUsersFile(): array
    {
        $raw = (string) @file_get_contents($this->path());
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $users = json_decode($raw, true);

        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
    }

    /**
     * @param array<int,array<string,mixed>> $fileUsers
     * @return array<int,array<string,mixed>>
     */
    private function usersFromDatabase(array $fileUsers): array
    {
        if (!$this->usersTableAvailable()) {
            return [];
        }

        $fileByIdentity = [];
        foreach ($fileUsers as $fileUser) {
            foreach ($this->databaseMergeIdentities($fileUser) as $identity) {
                $identity = $this->normalize($identity);
                if ($identity !== '' && !isset($fileByIdentity[$identity])) {
                    $fileByIdentity[$identity] = $fileUser;
                }
            }
        }

        try {
            $users = NovaUser::query()
                ->orderBy('nombre')
                ->orderBy('apellido')
                ->get()
                ->map(function (NovaUser $row) use ($fileByIdentity): array {
                    $current = [];
                    foreach ([
                        (string) $row->uuid,
                        (string) $row->usuario,
                        (string) $row->rut,
                        (string) $row->redmine_id,
                        (string) $row->usuario_core,
                    ] as $identity) {
                        $identity = $this->normalize($identity);
                        if ($identity !== '' && isset($fileByIdentity[$identity])) {
                            $current = $fileByIdentity[$identity];
                            break;
                        }
                    }

                    return array_merge($current, [
                        'id' => (string) $row->uuid,
                        'redmine_id' => trim((string) $row->redmine_id),
                        'username' => trim((string) $row->usuario),
                        'name' => trim((string) $row->nombre),
                        'apellido' => trim((string) $row->apellido),
                        'rut' => trim((string) $row->rut),
                        'rut_sin_dv' => trim((string) ($current['rut_sin_dv'] ?? $row->usuario)),
                        'core_user' => trim((string) $row->usuario_core),
                        'role' => $this->normalizeNovaRole((string) $row->rol),
                        'status' => $this->normalizeStatus((string) $row->estado),
                        'password' => (string) $row->password,
                    ]);
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }

        $known = [];
        foreach ($users as $user) {
            foreach ($this->databaseMergeIdentities($user) as $identity) {
                $identity = $this->normalize($identity);
                if ($identity !== '') {
                    $known[$identity] = true;
                }
            }
        }

        foreach ($fileUsers as $fileUser) {
            $found = false;
            foreach ($this->databaseMergeIdentities($fileUser) as $identity) {
                $identity = $this->normalize($identity);
                if ($identity !== '' && isset($known[$identity])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $users[] = $fileUser;
            }
        }

        return $users;
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function writeUsersToDatabase(array $users): void
    {
        if (!$this->usersTableAvailable()) {
            return;
        }

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $uuid = trim((string) ($user['id'] ?? ''));
            $username = trim((string) ($user['username'] ?? $user['rut_sin_dv'] ?? $user['redmine_id'] ?? ''));
            $name = trim((string) ($user['name'] ?? $user['nombre'] ?? ''));
            $lastName = trim((string) ($user['apellido'] ?? ''));
            $password = (string) ($user['password'] ?? '');

            if ($uuid === '') {
                $uuid = (string) Str::uuid();
            }
            if ($username === '' || $name === '') {
                continue;
            }
            if ($lastName === '' && str_contains($name, ' ')) {
                [$firstName, $remainingName] = explode(' ', $name, 2);
                $name = $firstName;
                $lastName = $remainingName;
            }

            try {
                NovaUser::query()->updateOrCreate(
                    ['uuid' => $uuid],
                    [
                        'usuario' => $username,
                        'rut' => trim((string) ($user['rut'] ?? '')) ?: null,
                        'redmine_id' => trim((string) ($user['redmine_id'] ?? '')) ?: null,
                        'nombre' => $name,
                        'apellido' => $lastName,
                        'email' => trim((string) ($user['email'] ?? '')) ?: null,
                        'rol' => $this->normalizeNovaRole((string) ($user['role'] ?? 'usuario')),
                        'estado' => $this->normalizeStatus((string) ($user['status'] ?? 'activo')),
                        'password' => $password,
                        'usuario_core' => trim((string) ($user['core_user'] ?? '')) ?: null,
                    ]
                );
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function databaseMergeIdentities(array $user): array
    {
        return array_values(array_filter([
            (string) ($user['id'] ?? ''),
            (string) ($user['username'] ?? ''),
            (string) ($user['rut'] ?? ''),
            (string) ($user['rut_sin_dv'] ?? ''),
            (string) ($user['redmine_id'] ?? ''),
            (string) ($user['core_user'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== ''));
    }

    private function usersTableAvailable(): bool
    {
        try {
            return Schema::hasTable('usuarios_nova');
        } catch (\Throwable) {
            return false;
        }
    }

    private function markLastLogin(array $user): void
    {
        if (!$this->usersTableAvailable()) {
            return;
        }

        $uuid = trim((string) ($user['id'] ?? ''));
        $username = trim((string) ($user['username'] ?? ''));
        $redmineId = trim((string) ($user['redmine_id'] ?? ''));

        try {
            $query = NovaUser::query();
            if ($uuid !== '') {
                $query->where('uuid', $uuid);
            } elseif ($username !== '') {
                $query->where('usuario', $username);
            } elseif ($redmineId !== '') {
                $query->where('redmine_id', $redmineId);
            } else {
                return;
            }

            $query->update(['ultimo_login_at' => now()]);
        } catch (\Throwable) {
        }
    }

    private function isBlocked(array $user): bool
    {
        $state = strtolower(trim((string) ($user['status'] ?? $user['estado'] ?? $user['estado_usuario'] ?? 'activo')));

        return in_array($state, ['baneado', 'bloqueado', 'inactivo'], true);
    }

    private function setStatus(string $id, string $status): int
    {
        $users = $this->all();
        $changed = 0;

        foreach ($users as $index => $user) {
            if ((string) ($user['id'] ?? '') !== $id) {
                continue;
            }

            $users[$index]['status'] = $this->normalizeStatus($status);
            $changed = 1;
            break;
        }

        if ($changed === 1) {
            $this->write($users);
        }

        return $changed;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return $status === 'baneado' ? 'baneado' : 'activo';
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9a-z]/i', '', $value));
    }

    private function normalizeNovaRole(string $role): string
    {
        $role = strtolower(trim($role));

        return in_array($role, ['admin', 'administrador', 'gestor', 'root'], true) ? 'admin' : 'usuario';
    }

    private function rutAccessUser(string $rut): string
    {
        $raw = trim($rut);
        $clean = strtolower((string) preg_replace('/[^0-9k]/i', '', $raw));

        if ($clean === '') {
            return '';
        }

        if (str_contains($raw, '-') || str_ends_with($clean, 'k') || strlen($clean) > 8) {
            return substr($clean, 0, -1);
        }

        return $clean;
    }

    private function isValidRut(string $rut): bool
    {
        $clean = strtolower((string) preg_replace('/[^0-9k]/i', '', trim($rut)));
        if (!preg_match('/^\d{7,8}[0-9k]$/', $clean)) {
            return false;
        }

        $number = substr($clean, 0, -1);
        $dv = substr($clean, -1);
        $factor = 2;
        $sum = 0;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $sum += (int) $number[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $expected = 11 - ($sum % 11);
        $expectedDv = match ($expected) {
            11 => '0',
            10 => 'k',
            default => (string) $expected,
        };

        return hash_equals($expectedDv, $dv);
    }
}
