<?php

namespace App\Modulos\Nova\Repositories;

use App\Modulos\Nova\Repositories\ModuleRegistry;
use App\Modulos\Nova\Services\NovaUserService;
use App\Modulos\Nova\Support\SecretValue;
use App\Repositories\Database\SqlText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class NovaUserRepository
{
    private string $lastWriteError = '';

    public function __construct(
        private ModuleRegistry $modules,
        private NovaUserService $service,
    ) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $users = $this->usersFromDatabase([]);

        $deduplicated = $this->service->deduplicateUsers($users);
        if ($deduplicated !== $users) {
            try {
                $users = DB::transaction(function (): array {
                    // Re-read under locks: a projection captured before this transaction
                    // must never restore an older password, role or integration secret.
                    $fresh = $this->usersFromDatabase([], true);
                    $merged = $this->service->deduplicateUsers($fresh);
                    $originals = array_column($fresh, null, 'id');
                    $fields = array_flip(['redmine_id', 'username', 'name', 'apellido', 'rut',
                        'core_user', 'role', 'status', 'password', 'email', 'emach_credentials', 'telegram_settings']);
                    $changed = [];
                    foreach ($merged as $user) {
                        $original = $originals[$user['id']] ?? [];
                        $values = array_intersect_key($user, $fields);
                        $before = array_intersect_key($original, $fields);
                        foreach (['telegram_settings', 'emach_credentials'] as $field) {
                            unset($values[$field]['updated_at'], $before[$field]['updated_at']);
                        }
                        if ($values !== $before) {
                            $changed[] = $user;
                        }
                    }
                    $this->writeUsersToDatabase($changed);

                    return $merged;
                });
            } catch (\Throwable) {
                $users = $this->service->deduplicateUsers($this->usersFromDatabase([]));
            }
        }

        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
    }

    /** Display-only projection. Duplicate repairs still go through all(). */
    public function allForAdministration(): array
    {
        $users = null;
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                $users = DB::transaction(function (): ?array {
                    // Preserve the existing reader's tie order and merge precedence.
                    if (DB::table('usuarios_nova')->select('nombre', 'apellido')
                        ->groupBy('nombre', 'apellido')->havingRaw('COUNT(*) > 1')->exists()) {
                        return null;
                    }
                    $rows = $this->usersFromDatabase([], false, null, true);
                    $keys = [];
                    foreach ($rows as $row) {
                        $key = $this->service->dedupeKey($row);
                        if ($key !== '' && isset($keys[$key])) {
                            return null;
                        }
                        $keys[$key] = true;
                    }

                    return $rows;
                });
            }
        } catch (\Throwable) {
            // Unsupported schemas/drivers retain the complete reader.
        }

        // Finish the read transaction before the original repair/locking flow.
        return array_map(static function (array $user): array {
            $user['password'] = '';
            foreach (['emach', 'nextcloud'] as $type) {
                $field = $type.'_credentials';
                $credentials = $user[$field] ?? [];
                $user['has_'.$field] = trim((string) ($credentials['user'] ?? '')) !== ''
                    && trim((string) ($credentials['password'] ?? '')) !== '';
                if (is_array($user[$field] ?? null)) {
                    $user[$field]['password'] = '';
                }
            }

            return $user;
        }, $users ?? $this->all());
    }

    public function attempt(string $username, string $password, bool $allowApiToken = false): ?array
    {
        $user = $this->find($username);
        if ($user === null || $this->service->isBlocked($user)) {
            return null;
        }

        if (!$this->service->verifyCredentials($user, $password, $allowApiToken)) {
            return null;
        }

        if ($this->service->verifyPassword($user, $password) && $this->service->passwordNeedsRehash($user)) {
            $this->rehashPassword($user, $password);
        }

        $this->markLastLogin($user);

        return $this->service->toSessionUser($user);
    }

    public function find(string $username): ?array
    {
        $needle = $this->service->normalizeIdentity($username);
        if ($needle === '') {
            return null;
        }

        // SQL mirrors byte normalization and first-match ordering. A normalized
        // collision still uses all(), whose historical merge can persist repairs.
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                $lookup = app(NovaIdentityLookupRepository::class)->lookup($needle);
                if (! $lookup['ambiguous']) {
                    return $lookup['id'] === null ? null : ($this->usersFromDatabase([], false, [$lookup['id']])[0] ?? null);
                }
                // Keep the service's established duplicate merge and all identifiers.
                foreach ($this->all() as $user) {
                    foreach ($this->service->loginCandidates($user) as $candidate) {
                        if ($needle === $this->service->normalizeIdentity((string) $candidate)) {
                            return $user;
                        }
                    }
                }

                return null;
            }
            $identities = DB::table('usuarios_nova')->orderBy('nombre')->orderBy('apellido')
                ->get(['id', 'uuid', 'usuario', 'redmine_id', 'rut', 'usuario_core', 'nombre', 'apellido']);
            $keys = [];
            $targetId = null;
            $ambiguous = false;
            foreach ($identities as $row) {
                $identity = [
                    'id' => (string) $row->uuid, 'username' => trim((string) $row->usuario),
                    'rut_sin_dv' => trim((string) $row->usuario), 'redmine_id' => trim((string) $row->redmine_id),
                    'rut' => trim((string) $row->rut), 'core_user' => trim((string) $row->usuario_core),
                    'name' => trim((string) $row->nombre), 'apellido' => trim((string) $row->apellido),
                ];
                $key = $this->service->dedupeKey($identity);
                if ($key !== '' && isset($keys[$key])) { $ambiguous = true; break; }
                $keys[$key] = true;
                if ($targetId === null) {
                    foreach ($this->service->loginCandidates($identity) as $candidate) {
                        if ($needle === $this->service->normalizeIdentity((string) $candidate)) {
                            $targetId = (int) $row->id;
                            break;
                        }
                    }
                }
            }
            if (!$ambiguous) {
                return $targetId === null ? null : ($this->usersFromDatabase([], false, [$targetId])[0] ?? null);
            }
        } catch (\Throwable) {
            // Retain the established failure/merge path; never broaden matching.
        }

        foreach ($this->all() as $user) {
            foreach ($this->service->loginCandidates($user) as $candidate) {
                if ($needle === $this->service->normalizeIdentity((string) $candidate)) {
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
        // Capture the stored values before reading projections/validating the form.
        // They are used only to detect a conflicting edit, never to rewrite other accounts.
        $requestedId = trim((string) ($payload['id'] ?? ''));
        try {
            $snapshot = $requestedId !== ''
                ? DB::table('usuarios_nova')->where('uuid', $requestedId)->first()
                : null;
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'No fue posible consultar el usuario.'];
        }
        if ($requestedId !== '' && $snapshot === null) {
            return ['ok' => false, 'error' => 'Usuario no encontrado.'];
        }

        $users = $this->all();
        $id    = trim((string) ($payload['id'] ?? ''));
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

        $current  = $index !== null ? $users[$index] : [];
        $rut      = $this->service->canonicalRut((string) ($payload['rut'] ?? $current['rut'] ?? ''));
        $username = $this->service->normalizeRutUsername($rut);
        if ($username === '' && !$isNew) {
            $username = trim((string) ($payload['username'] ?? $current['username'] ?? $current['redmine_id'] ?? ''));
        }

        $name     = trim((string) ($payload['name']     ?? $current['name']     ?? ''));
        $apellido = trim((string) ($payload['apellido'] ?? $current['apellido'] ?? ''));
        if ($name === '' || $apellido === '' || $username === '') {
            return ['ok' => false, 'error' => 'Nombre, apellidos y usuario de acceso son obligatorios.'];
        }

        if ($isNew && $rut === '') {
            return ['ok' => false, 'error' => 'El RUT es obligatorio para usuarios nuevos.'];
        }

        if ($rut !== '' && !$this->service->isValidRut($rut)) {
            return ['ok' => false, 'error' => 'El RUT ingresado no es valido.'];
        }

        foreach ($users as $i => $user) {
            if ($index !== null && $i === $index) {
                continue;
            }

            if ($this->service->normalizeIdentity((string) ($user['username'] ?? '')) === $this->service->normalizeIdentity($username)) {
                return ['ok' => false, 'error' => 'Ya existe un usuario con ese acceso.'];
            }
        }

        // Redmine IDs are managed exclusively by the Redmine import flows.
        // Never accept this value from the NOVA administration form.
        $redmineId = $isNew ? '' : trim((string) ($current['redmine_id'] ?? ''));

        foreach ($users as $i => $user) {
            if ($index !== null && $i === $index) {
                continue;
            }

            if ($rut !== '' && $this->service->normalizeIdentity((string) ($user['rut'] ?? '')) === $this->service->normalizeIdentity($rut)) {
                return ['ok' => false, 'error' => 'Ya existe un usuario con ese RUT.'];
            }

            if ($redmineId !== '' && $this->service->normalizeIdentity((string) ($user['redmine_id'] ?? '')) === $this->service->normalizeIdentity($redmineId)) {
                return ['ok' => false, 'error' => 'Ya existe un usuario con ese ID Redmine.'];
            }
        }

        $password        = (string) ($payload['password']                                      ?? '');
        $passwordConfirm = (string) ($payload['password_confirmation'] ?? $payload['password_confirm'] ?? '');
        $passwordHash    = (string) ($current['password'] ?? '');
        if ($password !== '' || $passwordConfirm !== '') {
            if ($password === '' || $passwordConfirm === '' || !hash_equals($password, $passwordConfirm)) {
                return ['ok' => false, 'error' => 'La contrasena y su validacion no coinciden.'];
            }

            $passwordHash = $this->service->hashPassword($password);
        }

        if ($passwordHash === '') {
            return ['ok' => false, 'error' => 'La contrasena es obligatoria para usuarios nuevos.'];
        }

        $row = [
            'id'         => $id,
            'redmine_id' => $redmineId,
            'username'   => $username,
            'name'       => $name,
            'apellido'   => $apellido,
            'rut'        => $rut,
            'rut_sin_dv' => $rut !== '' ? $username : (string) ($current['rut_sin_dv'] ?? ''),
            'core_user'  => trim((string) ($payload['core_user'] ?? $current['core_user'] ?? '')),
            'role'       => $this->service->normalizeNovaRole((string) ($payload['role']   ?? 'usuario')),
            'status'     => $this->service->normalizeStatus((string)   ($payload['status'] ?? 'activo')),
            'password'   => $passwordHash,
        ];
        if (is_array($current['emach_credentials'] ?? null)) {
            $row['emach_credentials'] = $current['emach_credentials'];
        }
        if (is_array($current['telegram_settings'] ?? null)) {
            $row['telegram_settings'] = $current['telegram_settings'];
        }

        $saved = $isNew
            ? $this->write([$row])
            : $this->writeUserEdit($row, $snapshot, $password !== '');
        if (!$saved) {
            return [
                'ok' => false,
                'error' => $this->lastWriteError !== ''
                    ? $this->lastWriteError
                    : 'No fue posible guardar el usuario.',
            ];
        }

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

        try {
            $updated = DB::table('usuarios_nova')->where('uuid', $id)->update([
                'password' => $this->service->hashPassword($password),
                'actualizado_at' => now(),
            ]);
            if ($updated !== 1) {
                return ['ok' => false, 'error' => 'No fue posible guardar la contraseña.'];
            }
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => 'No fue posible guardar la contraseña. Intenta nuevamente.'];
        }

        return ['ok' => true, 'error' => ''];
    }

    public function activate(string $id): int
    {
        return $this->setStatus($id, 'activo');
    }

    // -------------------------------------------------------------------------
    // Private — data access only
    // -------------------------------------------------------------------------

    /** Persist only fields owned by the administration form that actually changed. */
    private function writeUserEdit(array $user, object $snapshot, bool $changePassword): bool
    {
        $this->lastWriteError = '';
        $values = [
            'usuario' => $user['username'],
            'rut' => $user['rut'] ?: null,
            'nombre' => $user['name'],
            'apellido' => $user['apellido'],
            'usuario_core' => $user['core_user'] ?: null,
            'rol' => $user['role'],
            'estado' => $user['status'],
        ];
        if ($changePassword) {
            $values['password'] = $user['password'];
        }
        $changes = [];
        foreach ($values as $column => $value) {
            if ($value !== ($snapshot->$column ?? null)) {
                $changes[$column] = $value;
            }
        }

        try {
            return DB::transaction(function () use ($user, $snapshot, $changes): bool {
                $query = DB::table('usuarios_nova')->where('uuid', $user['id']);
                $latest = (clone $query)->lockForUpdate()->first();
                if ($latest === null) {
                    $this->lastWriteError = 'Usuario no encontrado.';

                    return false;
                }
                foreach ($changes as $column => $value) {
                    if (($latest->$column ?? null) !== ($snapshot->$column ?? null)) {
                        $this->lastWriteError = 'El usuario fue modificado por otra operación. Recarga la ficha e intenta nuevamente.';

                        return false;
                    }
                }
                if ($changes !== []) {
                    $query->update($changes + ['actualizado_at' => now()]);
                }

                return true;
            });
        } catch (\Throwable) {
            $this->lastWriteError = 'No fue posible guardar el usuario. Intenta nuevamente.';

            return false;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function write(array $users): bool
    {
        $this->lastWriteError = '';
        if (!$this->usersTableAvailable()) {
            $this->lastWriteError = 'La tabla de usuarios no esta disponible.';

            return false;
        }

        try {
            DB::transaction(function () use ($users): void {
                $this->writeUsersToDatabase($users);
            });

            return true;
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());
            $this->lastWriteError = match (true) {
                str_contains($message, 'redmine') => 'Ya existe un usuario con ese ID Redmine.',
                str_contains($message, 'rut') => 'Ya existe un usuario con ese RUT.',
                str_contains($message, 'usuario') => 'Ya existe un usuario con ese acceso.',
                default => 'No fue posible guardar el usuario.',
            };

            return false;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $fileUsers
     * @return array<int,array<string,mixed>>
     */
    private function usersFromDatabase(array $fileUsers, bool $lock = false, ?array $userIds = null, bool $forAdministration = false): array
    {
        if (!$this->usersTableAvailable()) {
            return [];
        }

        try {
            $query = DB::table('usuarios_nova')
                ->orderBy('nombre')
                ->orderBy('apellido');
            if ($userIds !== null) $query->whereIn('id', $userIds);
            if ($lock) {
                $query->lockForUpdate();
            }
            $columns = ['*'];
            if ($forAdministration) {
                $columns = ['id', 'uuid', 'redmine_id', 'usuario', 'nombre', 'apellido', 'rut', 'usuario_core',
                    'rol', 'estado', 'telegram_id_chat', 'ultimo_login_at', 'creado_at', 'actualizado_at'];
                if (Schema::hasColumn('usuarios_nova', 'email')) {
                    $columns[] = 'email';
                }
            }
            $rows = $query->get($columns);
            $integrationsByUser = $this->databaseIntegrationsByUserId($lock, $userIds, $forAdministration);
            $users = $rows
                ->map(function (object $row) use ($integrationsByUser): array {
                    $current = [];

                    $integrations     = $integrationsByUser[(int) $row->id] ?? [];
                    $emachCredentials = $integrations['emach_credentials']  ?? ($current['emach_credentials'] ?? null);
                    $nextcloudCredentials = $integrations['nextcloud_credentials'] ?? ($current['nextcloud_credentials'] ?? null);
                    $telegramChatId   = trim((string) ($row->telegram_id_chat ?? ''));
                    $telegramSettings = $telegramChatId !== ''
                        ? ['chat_id' => $telegramChatId, 'updated_at' => (string) ($row->actualizado_at ?? '')]
                        : null;

                    $user = array_merge($current, [
                        'id'              => (string) $row->uuid,
                        'redmine_id'      => trim((string) $row->redmine_id),
                        'username'        => trim((string) $row->usuario),
                        'name'            => trim((string) $row->nombre),
                        'apellido'        => trim((string) $row->apellido),
                        'rut'             => trim((string) $row->rut),
                        'rut_sin_dv'      => trim((string) ($current['rut_sin_dv'] ?? $row->usuario)),
                        'core_user'       => trim((string) $row->usuario_core),
                        'email'           => trim((string) ($row->email ?? '')),
                        'role'            => $this->service->normalizeNovaRole((string) $row->rol),
                        'status'          => $this->service->normalizeStatus((string)   $row->estado),
                        'password'        => (string) ($row->password ?? ''),
                        'ultimo_login_at' => (string) ($row->ultimo_login_at ?? ''),
                        'creado_at'       => (string) ($row->creado_at ?? ''),
                    ]);
                    if (is_array($emachCredentials)) {
                        $user['emach_credentials'] = $emachCredentials;
                    }
                    if (is_array($nextcloudCredentials)) {
                        $user['nextcloud_credentials'] = $nextcloudCredentials;
                    }
                    if (is_array($telegramSettings)) {
                        $user['telegram_settings'] = $telegramSettings;
                    }

                    return $user;
                })
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            if ($lock || $forAdministration) {
                throw $exception;
            }
            return [];
        }

        return $users;
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function writeUsersToDatabase(array $users): void
    {
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $uuid     = trim((string) ($user['id']       ?? ''));
            $username = trim((string) ($user['username'] ?? $user['rut_sin_dv'] ?? $user['redmine_id'] ?? ''));
            $name     = trim((string) ($user['name']     ?? $user['nombre']     ?? ''));
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
                $name     = $firstName;
                $lastName = $remainingName;
            }

            $values = [
                'usuario'          => $username,
                'rut'              => $this->service->canonicalRut((string) ($user['rut'] ?? '')) ?: null,
                'redmine_id'       => $this->unsignedIntegerOrNull($user['redmine_id'] ?? null),
                'nombre'           => $name,
                'apellido'         => $lastName,
                'rol'              => $this->service->normalizeNovaRole((string) ($user['role']   ?? 'usuario')),
                'estado'           => $this->service->normalizeStatus((string)   ($user['status'] ?? 'activo')),
                'password'         => $password,
                'usuario_core'     => trim((string) ($user['core_user'] ?? '')) ?: null,
                'telegram_id_chat' => trim((string) data_get($user, 'telegram_settings.chat_id', '')) ?: null,
            ];
            if (Schema::hasColumn('usuarios_nova', 'email')) {
                $values['email'] = trim((string) ($user['email'] ?? '')) ?: null;
            }
            $values['actualizado_at'] = now();

            $existingId = DB::table('usuarios_nova')->where('uuid', $uuid)->value('id');
            if ($existingId !== null) {
                DB::table('usuarios_nova')->where('id', $existingId)->update($values);
                $userId = (int) $existingId;
            } else {
                $values['uuid']      = $uuid;
                $values['creado_at'] = now();
                $userId = (int) DB::table('usuarios_nova')->insertGetId($values);
            }

            $this->writeDatabaseIntegrations($userId, $user);
        }
    }

    /**
     * @return array<int,array<string,array<string,string>>>
     */
    private function databaseIntegrationsByUserId(bool $lock = false, ?array $userIds = null, bool $forAdministration = false): array
    {
        if (!$this->integrationsTableAvailable()) {
            return [];
        }

        try {
            $query = DB::table('integraciones_usuario')->orderBy('id');
            if ($userIds !== null) $query->whereIn('usuario_id', $userIds);
            if ($lock) {
                $query->lockForUpdate();
            }
            $columns = ['*'];
            if ($forAdministration) {
                $type = SqlText::trim('tipo');
                $secret = SqlText::trim('valor_secreto');
                $query->whereRaw("BINARY ($type) IN ('emach', 'nextcloud')");
                $columns = ['usuario_id', 'tipo', 'usuario_externo', 'actualizado_at',
                    DB::raw("CASE WHEN OCTET_LENGTH($secret) > 0 THEN '1' ELSE '' END as valor_secreto")];
            }
            $rows = $query->get($columns);
        } catch (\Throwable $exception) {
            if ($lock || $forAdministration) {
                throw $exception;
            }
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $userId = (int) ($row->usuario_id ?? 0);
            $type   = trim((string) ($row->tipo ?? ''));
            if ($userId <= 0 || $type === '') {
                continue;
            }
            if ($type === 'emach') {
                $result[$userId]['emach_credentials'] = [
                    'user'       => trim((string) ($row->usuario_externo ?? '')),
                    'password'   => (string) ($row->valor_secreto ?? ''),
                    'updated_at' => (string) ($row->actualizado_at ?? ''),
                ];
            } elseif ($type === 'nextcloud') {
                $result[$userId]['nextcloud_credentials'] = [
                    'user'       => trim((string) ($row->usuario_externo ?? '')),
                    'password'   => (string) ($row->valor_secreto ?? ''),
                    'updated_at' => (string) ($row->actualizado_at ?? ''),
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function writeDatabaseIntegrations(int $userId, array $user): void
    {
        if ($userId <= 0 || !$this->integrationsTableAvailable()) {
            return;
        }

        $emach         = is_array($user['emach_credentials'] ?? null) ? $user['emach_credentials'] : [];
        $emachUser     = trim((string) ($emach['user']     ?? ''));
        $emachPassword = (string) ($emach['password'] ?? '');
        if ($emachUser !== '' || $emachPassword !== '') {
            $values = [
                'usuario_externo' => $emachUser !== '' ? $emachUser : null,
                'actualizado_at'  => now(),
            ];
            if ($emachPassword !== '') {
                $this->applyEmachSecret($values, $emachPassword);
            }
            DB::table('integraciones_usuario')->updateOrInsert(
                ['usuario_id' => $userId, 'tipo' => 'emach'],
                $values
            );
        }

        DB::table('integraciones_usuario')
            ->where('usuario_id', $userId)
            ->where('tipo', 'telegram')
            ->delete();
    }

    /**
     * Decides whether the EMACH secret being round-tripped through save()
     * needs encrypting before it's written. Never double-encrypts an
     * already-encrypted value, never writes a value flagged invalid, and
     * never persists a plaintext-legacy value unencrypted.
     *
     * @param array<string,mixed> $values
     */
    private function applyEmachSecret(array &$values, string $emachPassword): void
    {
        $status = SecretValue::inspect($emachPassword)['status'];

        if ($status === 'invalid') {
            return;
        }

        if ($status !== 'plaintext_legacy') {
            $values['valor_secreto'] = $emachPassword;

            return;
        }

        try {
            $values['valor_secreto'] = SecretValue::encryptSecret($emachPassword);
        } catch (\Throwable) {
        }
    }

    private function usersTableAvailable(): bool
    {
        try {
            return Schema::hasTable('usuarios_nova');
        } catch (\Throwable) {
            return false;
        }
    }

    private function integrationsTableAvailable(): bool
    {
        try {
            return Schema::hasTable('integraciones_usuario');
        } catch (\Throwable) {
            return false;
        }
    }

    private function markLastLogin(array $user): void
    {
        if (!$this->usersTableAvailable()) {
            return;
        }

        $uuid      = trim((string) ($user['id']         ?? ''));
        $username  = trim((string) ($user['username']   ?? ''));
        $redmineId = trim((string) ($user['redmine_id'] ?? ''));

        try {
            $query = DB::table('usuarios_nova');
            if ($uuid !== '') {
                $query->where('uuid', $uuid);
            } elseif ($username !== '') {
                $query->where('usuario', $username);
            } elseif ($redmineId !== '') {
                $query->where('redmine_id', $redmineId);
            } else {
                return;
            }

            $query->update(['ultimo_login_at' => now(), 'actualizado_at' => now()]);
        } catch (\Throwable) {
        }
    }

    private function rehashPassword(array $user, string $password): void
    {
        if (!$this->usersTableAvailable()) {
            return;
        }

        $uuid      = trim((string) ($user['id'] ?? ''));
        $username  = trim((string) ($user['username'] ?? ''));
        $redmineId = trim((string) ($user['redmine_id'] ?? ''));

        try {
            $query = DB::table('usuarios_nova');
            if ($uuid !== '') {
                $query->where('uuid', $uuid);
            } elseif ($username !== '') {
                $query->where('usuario', $username);
            } elseif ($redmineId !== '') {
                $query->where('redmine_id', $redmineId);
            } else {
                return;
            }

            $query->update([
                'password' => $this->service->hashPassword($password),
                'actualizado_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }

    private function setStatus(string $id, string $status): int
    {
        $id = trim($id);
        if ($id === '' || !$this->usersTableAvailable()) {
            return 0;
        }

        try {
            return DB::table('usuarios_nova')
                ->where('uuid', $id)
                ->update([
                    'estado' => $this->service->normalizeStatus($status),
                    'actualizado_at' => now(),
                ]);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function unsignedIntegerOrNull(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }
}
