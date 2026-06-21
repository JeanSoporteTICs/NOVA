<?php

namespace RedmineTic\Support\Redmine;

use App\Models\NovaUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Manages TIC project users: reading from usuarios_nova + redmine_tic_perfiles_usuario,
 * writing (upsert/delete/toggle), integration secrets, and Telegram chat IDs.
 *
 * Intentionally left in RedmineDataRepository:
 *   - syncUsersFromRedmine()  (Redmine HTTP + redmineUserName coupling)
 *   - assignedUserName()      (used by HIGH-risk databaseReportToArray)
 *   - userApiToken()          (used by all Redmine API callers in RDR)
 */
class RedmineUserRepository
{
    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {}

    // -------------------------------------------------------------------------
    // Public API (mirrors the facade on RedmineDataRepository)
    // -------------------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    public function projectUsers(): array
    {
        if (!$this->novaUsersTableAvailable()) {
            return [];
        }

        $profiles  = $this->redmineTicProfilesByUserId();
        $central   = [];
        foreach ($this->novaUsersWithProjectAccess() as $nova) {
            $central[(int) $nova->id] = $nova;
        }

        $allRelationalPerms = $this->permRepo()->allPermissionsFromRelational();

        $users = [];
        foreach ($central as $nova) {
            $profile      = $profiles[(int) $nova->id] ?? null;
            $redmineId    = trim((string) ($nova->redmine_id ?? ''));
            $projectId    = $redmineId !== '' ? $redmineId : trim((string) ($nova->uuid ?? $nova->usuario ?? ''));
            if ($projectId === '') {
                continue;
            }
            $telegramChatId = trim((string) ($nova->telegram_id_chat ?? ''));

            $perfilId    = (int) ($profile->id ?? 0);
            $permissions = ($allRelationalPerms !== null && $perfilId > 0 && isset($allRelationalPerms[$perfilId]))
                ? $allRelationalPerms[$perfilId]
                : $this->jsonArray($profile->permisos ?? null);

            $users[] = [
                'id'                    => $projectId,
                'redmine_id'            => $redmineId,
                'rut_sin_dv'            => trim((string) ($nova->usuario ?? '')),
                'nombre'                => trim((string) ($nova->nombre ?? '')),
                'apellido'              => trim((string) ($nova->apellido ?? '')),
                'rut'                   => trim((string) ($nova->rut ?? '')),
                'numero_celular'        => '',
                'telegram_chat_id'      => $telegramChatId,
                'telegram_source'       => $telegramChatId !== '' ? 'nova' : '',
                'api'                   => $this->integrationSecret((int) ($nova->id ?? 0), 'redmine_tic'),
                'rol'                   => trim((string) ($profile->rol ?? $nova->rol ?? 'usuario')) ?: 'usuario',
                'password'              => (string) ($nova->password ?? ''),
                'permisos'              => $permissions,
                'estado_usuario'        => trim((string) ($profile->estado_usuario ?? $nova->estado ?? 'activo')) ?: 'activo',
                'redmine_membership_id' => $profile->redmine_membership_id ?? null,
                '_nova_user_id'         => (string) ($nova->uuid ?? ''),
                '_central_only'         => $redmineId === '',
            ];
        }

        usort($users, static fn (array $a, array $b): int => strcasecmp(
            trim((string) ($a['nombre'] ?? '') . ' ' . (string) ($a['apellido'] ?? '')),
            trim((string) ($b['nombre'] ?? '') . ' ' . (string) ($b['apellido'] ?? ''))
        ));

        return array_values($users);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function activeUsersWithPhone(): array
    {
        return array_values(array_filter($this->projectUsers(), static function (array $user): bool {
            $state  = strtolower(trim((string) ($user['estado_usuario'] ?? $user['estado'] ?? 'activo')));
            $chatId = trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', '')));

            return $state === 'activo' && $chatId !== '';
        }));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,error:string,users:array<int,array<string,mixed>>}
     */
    public function saveUser(array $payload): array
    {
        $users           = $this->projectUsers();
        $id              = trim((string) ($payload['id'] ?? ''));
        $isExplicitCreate = filter_var($payload['_creating'] ?? false, FILTER_VALIDATE_BOOL);
        if ($id === '') {
            $id = trim((string) ($payload['rut_sin_dv'] ?? '')) ?: (string) Str::uuid();
        }

        if ($isExplicitCreate) {
            foreach ($users as $user) {
                if ((string) ($user['id'] ?? '') === $id) {
                    return ['ok' => false, 'error' => 'El ID ya esta asociado a otro usuario.', 'users' => $users];
                }
            }
        }

        $telegramChatId = trim((string) ($payload['telegram_chat_id'] ?? ''));
        if ($telegramChatId !== '') {
            foreach ($users as $user) {
                if ((string) ($user['id'] ?? '') === $id) {
                    continue;
                }
                $existingChatId = trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', '')));
                if ($existingChatId !== '' && $existingChatId === $telegramChatId) {
                    return ['ok' => false, 'error' => 'El Chat ID Telegram ya esta asociado a otro usuario.', 'users' => $users];
                }
            }
        }

        $row = [
            'id'            => $id,
            'rut'           => trim((string) ($payload['rut'] ?? '')),
            'rut_sin_dv'    => trim((string) ($payload['rut_sin_dv'] ?? $id)),
            'nombre'        => trim((string) ($payload['nombre'] ?? '')),
            'apellido'      => trim((string) ($payload['apellido'] ?? '')),
            'numero_celular' => '',
            'telegram_chat_id' => $telegramChatId,
            'rol'           => trim((string) ($payload['rol'] ?? 'usuario')) ?: 'usuario',
            'api'           => trim((string) ($payload['api'] ?? '')),
            'estado_usuario' => (($payload['estado_usuario'] ?? 'activo') === 'baneado') ? 'baneado' : 'activo',
        ];

        $updated = false;
        foreach ($users as $index => $user) {
            if ((string) ($user['id'] ?? '') !== $id) {
                continue;
            }
            $users[$index] = array_merge($user, $row);
            $updated = true;
            break;
        }

        if (!$updated) {
            $users[] = $row;
        }

        $this->persistUsers($users);

        return ['ok' => true, 'error' => '', 'users' => $users];
    }

    /**
     * Returns number of rows deleted (0 = not found / error, 1 = success).
     * Note: the caller (RedmineDataRepository) must clear activeReportsCache after a non-zero return.
     */
    public function deleteUser(string $id): int
    {
        $id = trim($id);
        if ($id === '') {
            return 0;
        }

        $users    = $this->projectUsers();
        $novaUuid = '';
        foreach ($users as $user) {
            if ((string) ($user['id'] ?? '') === $id) {
                $novaUuid = trim((string) ($user['_nova_user_id'] ?? ''));
                break;
            }
        }

        if ($novaUuid === '') {
            return 0;
        }

        try {
            $novaUserId = (int) DB::table('usuarios_nova')->where('uuid', $novaUuid)->value('id');
        } catch (\Throwable) {
            return 0;
        }

        if ($novaUserId <= 0) {
            return 0;
        }

        $moduleId = $this->moduleId();
        $changed  = 0;

        try {
            if ($this->redmineTicProfilesTableAvailable()) {
                DB::table('redmine_tic_perfiles_usuario')->where('usuario_id', $novaUserId)->delete();
                $changed = 1;
            }

            if ($moduleId !== null && $this->projectAccessTableAvailable()) {
                DB::table('permisos_usuario_modulo')
                    ->where('usuario_id', $novaUserId)
                    ->where('modulo_id', $moduleId)
                    ->delete();
                $changed = 1;
            }
        } catch (\Throwable) {
            return 0;
        }

        return $changed;
    }

    /**
     * @return array{ok:bool,nuevo_estado:string}
     * Note: the caller (RedmineDataRepository) must clear activeReportsCache when ok === true.
     */
    public function toggleUserStatus(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'nuevo_estado' => ''];
        }

        $users     = $this->projectUsers();
        $foundUser = null;
        foreach ($users as $user) {
            if ((string) ($user['id'] ?? '') === $id) {
                $foundUser = $user;
                break;
            }
        }

        if ($foundUser === null) {
            return ['ok' => false, 'nuevo_estado' => ''];
        }

        $currentStatus = strtolower(trim((string) ($foundUser['estado_usuario'] ?? 'activo')));
        $newStatus     = $currentStatus === 'baneado' ? 'activo' : 'baneado';
        $novaUuid      = trim((string) ($foundUser['_nova_user_id'] ?? ''));

        if ($novaUuid === '') {
            return ['ok' => false, 'nuevo_estado' => ''];
        }

        try {
            $novaUserId = (int) DB::table('usuarios_nova')->where('uuid', $novaUuid)->value('id');
            if ($novaUserId <= 0) {
                return ['ok' => false, 'nuevo_estado' => ''];
            }

            if ($this->redmineTicProfilesTableAvailable()) {
                DB::table('redmine_tic_perfiles_usuario')
                    ->where('usuario_id', $novaUserId)
                    ->update(['estado_usuario' => $newStatus, 'actualizado_at' => now()]);
            }

            if ($this->novaUsersTableAvailable()) {
                DB::table('usuarios_nova')
                    ->where('id', $novaUserId)
                    ->update(['estado' => $newStatus, 'actualizado_at' => now()]);
            }

            return ['ok' => true, 'nuevo_estado' => $newStatus];
        } catch (\Throwable) {
            return ['ok' => false, 'nuevo_estado' => ''];
        }
    }

    /**
     * @param array<string,mixed> $permissions
     */
    public function saveUserPermissions(string $id, string $role, array $permissions): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }

        $users = $this->projectUsers();
        foreach ($users as $index => $user) {
            if ((string) ($user['id'] ?? '') !== $id) {
                continue;
            }

            if (trim($role) !== '') {
                $users[$index]['rol'] = trim($role);
            }
            $users[$index]['permisos'] = $permissions;
            $this->persistUsers($users);

            return true;
        }

        return false;
    }

    /**
     * Persists a full project-user list to usuarios_nova + redmine_tic_perfiles_usuario.
     * Called by saveUser(), saveUserPermissions(), and syncUsersFromRedmine() in RDR.
     *
     * @param array<int,array<string,mixed>> $projectUsers
     */
    public function persistUsers(array $projectUsers, bool $preserveExistingStatus = false, string $defaultStatus = 'activo'): void
    {
        if (!$this->novaUsersTableAvailable()) {
            return;
        }

        foreach ($projectUsers as $projectUser) {
            if (!is_array($projectUser)) {
                continue;
            }

            $redmineId = trim((string) ($projectUser['id'] ?? ''));
            if ($redmineId === '') {
                continue;
            }

            $name     = trim((string) ($projectUser['nombre'] ?? ''));
            $lastName = trim((string) ($projectUser['apellido'] ?? ''));
            if ($lastName === '' && str_contains($name, ' ')) {
                [$first, $rest] = explode(' ', $name, 2);
                $name     = $first;
                $lastName = $rest;
            }

            $nova          = $this->upsertNovaUserFromProjectUser($projectUser, $name, $lastName, $preserveExistingStatus, $defaultStatus);
            $apiToken      = trim((string) ($projectUser['api'] ?? ''));
            $telegramChatId = trim((string) ($projectUser['telegram_chat_id'] ?? data_get($projectUser, 'telegram_settings.chat_id', '')));

            if ($nova instanceof NovaUser) {
                $this->saveUserIntegration((int) $nova->id, 'redmine_tic', $apiToken, (string) $redmineId);
                $this->saveTelegramChatId((int) $nova->id, $telegramChatId);
                $this->grantProjectAccess((int) $nova->id);
            }

            if (!$nova instanceof NovaUser || !$this->redmineTicProfilesTableAvailable()) {
                continue;
            }

            $currentProfile = DB::table('redmine_tic_perfiles_usuario')
                ->where('usuario_id', (int) $nova->id)
                ->first();
            $incomingStatus = array_key_exists('estado_usuario', $projectUser)
                ? trim((string) $projectUser['estado_usuario'])
                : '';
            $status = $preserveExistingStatus && $currentProfile !== null
                ? trim((string) ($currentProfile->estado_usuario ?? 'activo'))
                : ($incomingStatus !== '' ? $incomingStatus : $defaultStatus);

            $permsToSave = is_array($projectUser['permisos'] ?? null) ? $projectUser['permisos'] : [];

            // Phase 3c: 'permisos' column was dropped — write only relational columns
            DB::table('redmine_tic_perfiles_usuario')->updateOrInsert(
                ['usuario_id' => (int) $nova->id],
                [
                    'rol'                  => trim((string) ($projectUser['rol'] ?? 'usuario')) ?: 'usuario',
                    'estado_usuario'       => $this->normalizeProjectStatus($status),
                    'redmine_membership_id' => $this->unsignedIntegerOrNull($projectUser['redmine_membership_id'] ?? null),
                    'actualizado_at'       => now(),
                ]
            );

            if ($this->permRepo()->userPermissionsTableAvailable()) {
                $perfilId = (int) DB::table('redmine_tic_perfiles_usuario')
                    ->where('usuario_id', (int) $nova->id)
                    ->value('id');
                $this->permRepo()->savePermissionsToRelational($perfilId, $permsToSave);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private: user upsert helpers
    // -------------------------------------------------------------------------

    private function upsertNovaUserFromProjectUser(array $projectUser, string $name, string $lastName, bool $preserveExistingStatus = false, string $defaultStatus = 'activo'): ?NovaUser
    {
        if (!$this->novaUsersTableAvailable()) {
            return null;
        }

        $redmineId = trim((string) ($projectUser['id'] ?? ''));
        if ($redmineId === '') {
            return null;
        }

        $uuid = trim((string) ($projectUser['_nova_user_id'] ?? ''));
        if (!ctype_digit($redmineId)) {
            try {
                $user = $uuid !== ''
                    ? NovaUser::query()->where('uuid', $uuid)->first()
                    : null;
                if (!$user) {
                    return null;
                }
                if ($name !== '') {
                    $user->nombre = $name;
                }
                if ($lastName !== '') {
                    $user->apellido = $lastName;
                }
                if (!$preserveExistingStatus) {
                    $user->estado = $this->normalizeProjectStatus((string) ($projectUser['estado_usuario'] ?? $user->estado ?? 'activo'));
                }
                $user->save();

                return $user;
            } catch (\Throwable) {
                return null;
            }
        }

        $username = trim((string) ($projectUser['rut_sin_dv'] ?? $projectUser['username'] ?? '')) ?: $redmineId;
        $rut      = trim((string) ($projectUser['rut'] ?? ''));
        $name     = $name !== '' ? $name : 'Redmine';
        $lastName = $lastName !== '' ? $lastName : 'Usuario';
        $role     = $this->normalizeNovaRoleForProject((string) ($projectUser['rol'] ?? 'usuario'));

        $incomingStatus = array_key_exists('estado_usuario', $projectUser)
            ? trim((string) $projectUser['estado_usuario'])
            : '';
        $status = $this->normalizeProjectStatus($incomingStatus !== '' ? $incomingStatus : $defaultStatus);

        try {
            $user = NovaUser::query()->where('redmine_id', $redmineId)->first();
            if (!$user && $rut !== '') {
                $user = NovaUser::query()->where('rut', $rut)->first();
            }
            if (!$user && $username !== '') {
                $user = NovaUser::query()->where('usuario', $username)->first();
            }

            if (!$user) {
                $user           = new NovaUser();
                $user->uuid     = (string) Str::uuid();
                $user->password = Hash::make(Str::random(40));
            }

            $user->usuario   = $this->uniqueNovaUsername($username, $user->exists ? (int) $user->id : null);
            $user->rut       = $rut !== '' ? $rut : null;
            $user->redmine_id = $redmineId;
            $user->nombre    = $name;
            $user->apellido  = $lastName;
            $user->rol       = $role;
            if (!$user->exists || !$preserveExistingStatus) {
                $user->estado = $status;
            }
            $user->save();

            return $user;
        } catch (\Throwable) {
            return null;
        }
    }

    private function uniqueNovaUsername(string $username, ?int $currentId = null): string
    {
        $username  = trim($username) !== '' ? trim($username) : (string) Str::uuid();
        $candidate = $username;
        $suffix    = 2;

        while (true) {
            try {
                $query = NovaUser::query()->where('usuario', $candidate);
                if ($currentId !== null) {
                    $query->where('id', '<>', $currentId);
                }
                if (!$query->exists()) {
                    return $candidate;
                }
            } catch (\Throwable) {
                return $candidate;
            }

            $candidate = $username . '-' . $suffix;
            $suffix++;
        }
    }

    // -------------------------------------------------------------------------
    // Private: project access + integrations
    // -------------------------------------------------------------------------

    private function grantProjectAccess(int $novaUserId): void
    {
        $moduleId = $this->moduleId();
        if ($novaUserId <= 0 || $moduleId === null || !$this->projectAccessTableAvailable()) {
            return;
        }

        try {
            DB::table('permisos_usuario_modulo')->updateOrInsert(
                ['usuario_id' => $novaUserId, 'modulo_id' => $moduleId],
                ['permitido' => 1, 'actualizado_at' => now()]
            );
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<int,object>
     */
    private function novaUsersWithProjectAccess(): array
    {
        $moduleId = $this->moduleId();
        if ($moduleId === null || !$this->projectAccessTableAvailable() || !$this->novaUsersTableAvailable()) {
            return [];
        }

        try {
            return DB::table('usuarios_nova')
                ->join('permisos_usuario_modulo', 'permisos_usuario_modulo.usuario_id', '=', 'usuarios_nova.id')
                ->where('permisos_usuario_modulo.modulo_id', $moduleId)
                ->where('permisos_usuario_modulo.permitido', 1)
                ->select('usuarios_nova.*')
                ->get()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function integrationSecret(int $novaUserId, string $type): string
    {
        if ($novaUserId <= 0 || !$this->userIntegrationsTableAvailable()) {
            return '';
        }

        try {
            $secret = (string) DB::table('integraciones_usuario')
                ->where('usuario_id', $novaUserId)
                ->where('tipo', $type)
                ->value('valor_secreto');

            return $this->decryptIntegrationSecret($secret);
        } catch (\Throwable) {
            return '';
        }
    }

    private function saveUserIntegration(int $novaUserId, string $type, string $secret = '', string $externalUser = '', string $chatId = ''): void
    {
        if ($novaUserId <= 0 || !$this->userIntegrationsTableAvailable()) {
            return;
        }
        if ($secret === '' && $externalUser === '' && $chatId === '') {
            return;
        }

        $values = ['actualizado_at' => now()];
        if (Schema::hasColumn('integraciones_usuario', 'usuario_externo')) {
            $values['usuario_externo'] = $externalUser !== '' ? $externalUser : null;
        }
        if ($chatId !== '' && Schema::hasColumn('integraciones_usuario', 'chat_id')) {
            $values['chat_id'] = $chatId;
        }
        if ($secret !== '') {
            $values['valor_secreto'] = $this->encryptIntegrationSecret($secret);
        }

        try {
            DB::table('integraciones_usuario')->updateOrInsert(
                ['usuario_id' => $novaUserId, 'tipo' => $type],
                $values
            );
        } catch (\Throwable) {
        }
    }

    private function saveTelegramChatId(int $novaUserId, string $chatId): void
    {
        $chatId = trim($chatId);
        if ($novaUserId <= 0 || $chatId === '' || !$this->novaUsersTableAvailable()) {
            return;
        }

        try {
            DB::table('usuarios_nova')
                ->where('id', $novaUserId)
                ->update(['telegram_id_chat' => $chatId, 'actualizado_at' => now()]);
        } catch (\Throwable) {
        }
    }

    private function encryptIntegrationSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        try {
            return encrypt($secret);
        } catch (\Throwable) {
            return $secret;
        }
    }

    private function decryptIntegrationSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        try {
            return (string) decrypt($secret);
        } catch (\Throwable) {
            return $secret;
        }
    }

    // -------------------------------------------------------------------------
    // Private: Telegram lookup
    // -------------------------------------------------------------------------

    /**
     * @return array<string,string>  redmine_id => telegram_id_chat
     */
    private function novaTelegramByRedmineId(): array
    {
        if (!$this->novaUsersTableAvailable()) {
            return [];
        }

        $mapped = [];
        try {
            $rows = DB::table('usuarios_nova')
                ->whereNotNull('redmine_id')
                ->whereNotNull('telegram_id_chat')
                ->select('redmine_id', 'telegram_id_chat')
                ->get();

            foreach ($rows as $row) {
                $redmineId = trim((string) ($row->redmine_id ?? ''));
                $chatId    = trim((string) ($row->telegram_id_chat ?? ''));
                if ($redmineId !== '' && $chatId !== '') {
                    $mapped[$redmineId] = $chatId;
                }
            }
        } catch (\Throwable) {
        }

        return $mapped;
    }

    // -------------------------------------------------------------------------
    // Private: table checks
    // -------------------------------------------------------------------------

    private function novaUsersTableAvailable(): bool
    {
        try {
            return Schema::hasTable('usuarios_nova');
        } catch (\Throwable) {
            return false;
        }
    }

    private function redmineTicProfilesTableAvailable(): bool
    {
        try {
            return Schema::hasTable('redmine_tic_perfiles_usuario');
        } catch (\Throwable) {
            return false;
        }
    }

    private function projectAccessTableAvailable(): bool
    {
        try {
            return Schema::hasTable('permisos_usuario_modulo');
        } catch (\Throwable) {
            return false;
        }
    }

    private function userIntegrationsTableAvailable(): bool
    {
        try {
            return Schema::hasTable('integraciones_usuario');
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Private: profile loader
    // -------------------------------------------------------------------------

    /**
     * @return array<int,object>  keyed by usuario_id
     */
    private function redmineTicProfilesByUserId(): array
    {
        if (!$this->redmineTicProfilesTableAvailable()) {
            return [];
        }

        try {
            $profiles = [];
            foreach (DB::table('redmine_tic_perfiles_usuario')->get() as $profile) {
                $profiles[(int) ($profile->usuario_id ?? 0)] = $profile;
            }

            return array_filter($profiles, static fn (object $p): bool => (int) ($p->usuario_id ?? 0) > 0);
        } catch (\Throwable) {
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Private: normalisation utilities
    // -------------------------------------------------------------------------

    private function normalizeProjectStatus(string $status): string
    {
        return in_array(strtolower(trim($status)), ['baneado', 'bloqueado', 'inactivo'], true) ? 'baneado' : 'activo';
    }

    private function normalizeNovaRoleForProject(string $role): string
    {
        return in_array(strtolower(trim($role)), ['admin', 'administrador', 'gestor', 'root'], true) ? 'admin' : 'usuario';
    }

    private function normalizeUnifiedIdentity(string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9a-z]/i', '', $value));
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    // -------------------------------------------------------------------------
    // Private: shared numeric helper (duplicated from RDR for encapsulation)
    // -------------------------------------------------------------------------

    private function unsignedIntegerOrNull(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    // -------------------------------------------------------------------------
    // Private: module + permission repo
    // -------------------------------------------------------------------------

    private ?RedminePermissionRepository $permRepoInst = null;

    private function permRepo(): RedminePermissionRepository
    {
        return $this->permRepoInst ??= new RedminePermissionRepository($this->projectKey, $this->projectName);
    }

    private function moduleId(): ?int
    {
        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', $this->projectKey)->value('id');
            if ($id !== null) {
                return (int) $id;
            }

            DB::table('modulos_nova')->insert([
                'clave_modulo'   => $this->projectKey,
                'nombre'         => $this->projectName,
                'descripcion'    => '',
                'icono'          => '',
                'tipo'           => 'native',
                'ruta'           => $this->projectKey,
                'entrada'        => 'laravel:redmine.native.dashboard',
                'habilitado'     => 1,
                'orden'          => 100,
                'creado_at'      => now(),
                'actualizado_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }
}
