<?php

namespace App\Modulos\Nova\Repositories;

use App\Modulos\Nova\Repositories\ModuleRegistry;
use App\Modulos\Nova\Services\NovaUserService;
use App\Modulos\Nova\Support\SecretValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class NovaUserRepository
{
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
            $this->write($deduplicated);
            $users = $deduplicated;
        }

        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
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
        $rut      = trim((string) ($payload['rut'] ?? $current['rut'] ?? ''));
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

        $redmineId = trim((string) ($payload['redmine_id'] ?? $current['redmine_id'] ?? ''));

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

        $users[$index]['password'] = $this->service->hashPassword($password);
        $this->write($users);

        return ['ok' => true, 'error' => ''];
    }

    public function activate(string $id): int
    {
        return $this->setStatus($id, 'activo');
    }

    // -------------------------------------------------------------------------
    // Private — data access only
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function write(array $users): void
    {
        $this->writeUsersToDatabase($users);
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

        try {
            $integrationsByUser = $this->databaseIntegrationsByUserId();
            $users              = DB::table('usuarios_nova')
                ->orderBy('nombre')
                ->orderBy('apellido')
                ->get()
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
                        'password'        => (string) $row->password,
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
        } catch (\Throwable) {
            return [];
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

            try {
                $values = [
                    'usuario'          => $username,
                    'rut'              => trim((string) ($user['rut'] ?? '')) ?: null,
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
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * @return array<int,array<string,array<string,string>>>
     */
    private function databaseIntegrationsByUserId(): array
    {
        if (!$this->integrationsTableAvailable()) {
            return [];
        }

        try {
            $rows = DB::table('integraciones_usuario')->get();
        } catch (\Throwable) {
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
