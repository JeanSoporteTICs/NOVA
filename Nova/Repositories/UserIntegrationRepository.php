<?php

namespace App\Modulos\Nova\Repositories;

use App\Modulos\Nova\Support\SecretValue;
use App\Support\StringNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class UserIntegrationRepository
{
    public const REDMINE_TYPE = 'redmine';

    private const LEGACY_REDMINE_TYPES = ['redmine_mantencion', 'redmine_tic'];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function users(): array
    {
        if (!$this->tablesAvailable()) {
            return [];
        }

        try {
            $integrations = DB::table('integraciones_usuario')->get()->groupBy('usuario_id');

            return DB::table('usuarios_nova')
                ->orderBy('nombre')
                ->orderBy('apellido')
                ->get()
                ->map(function ($row) use ($integrations): array {
                    $byType = ($integrations[(int) $row->id] ?? collect())->keyBy('tipo');
                    $emach = $byType['emach'] ?? null;
                    $telegramChatId = trim((string) ($row->telegram_id_chat ?? ''));

                    $user = [
                        'id' => (string) ($row->uuid ?? ''),
                        'username' => (string) ($row->usuario ?? ''),
                        'rut' => (string) ($row->rut ?? ''),
                        'rut_sin_dv' => (string) ($row->usuario ?? ''),
                        'core_user' => (string) ($row->usuario_core ?? ''),
                        'redmine_id' => (string) ($row->redmine_id ?? ''),
                    ];
                    if ($emach !== null) {
                        $user['emach_credentials'] = [
                            'user' => (string) ($emach->usuario_externo ?? ''),
                            'password' => (string) ($emach->valor_secreto ?? ''),
                            'updated_at' => (string) ($emach->actualizado_at ?? ''),
                        ];
                    }
                    if ($telegramChatId !== '') {
                        $user['telegram_settings'] = [
                            'chat_id' => $telegramChatId,
                            'updated_at' => (string) ($row->actualizado_at ?? ''),
                        ];
                    }

                    return $user;
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function userIndexForSession(array $sessionUser): ?int
    {
        $users = $this->users();
        $needles = $this->needles($sessionUser);
        if ($needles === []) {
            return null;
        }

        foreach ($users as $index => $user) {
            if (array_intersect($needles, $this->needles($user)) !== []) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function userForSession(array $sessionUser): array
    {
        $users = $this->users();
        $index = $this->userIndexForSession($sessionUser);

        return $index === null ? [] : ($users[$index] ?? []);
    }

    /**
     * @return array{user:string,password:string,stored:bool,updated_at:string}
     */
    public function emachForSession(array $sessionUser): array
    {
        return $this->emachForUser($this->userForSession($sessionUser));
    }

    /**
     * @param array<string,mixed> $user
     * @return array{user:string,password:string,stored:bool,updated_at:string}
     */
    public function emachForUser(array $user): array
    {
        $credentials = is_array($user['emach_credentials'] ?? null) ? $user['emach_credentials'] : [];
        $emachUser = trim((string) ($credentials['user'] ?? ''));
        $rawSecret = (string) ($credentials['password'] ?? '');
        $password = SecretValue::decryptSecret($rawSecret) ?? '';

        $this->maybeRewriteEmachSecret($user, $emachUser, $rawSecret, $password);

        return [
            'user' => $emachUser,
            'password' => $password,
            'stored' => $emachUser !== '' && $password !== '',
            'updated_at' => (string) ($credentials['updated_at'] ?? ''),
        ];
    }

    /**
     * Opportunistically upgrades a plaintext-legacy EMACH secret to Laravel
     * encrypt() the moment it's read in a flow that can resolve the owning
     * row (usuario_id). Never runs for already-encrypted, invalid, or empty
     * values, and never fails the read if the rewrite itself fails.
     *
     * @param array<string,mixed> $user
     */
    private function maybeRewriteEmachSecret(array $user, string $emachUser, string $rawSecret, string $decryptedPassword): void
    {
        if ($rawSecret === '' || $decryptedPassword === '') {
            return;
        }

        if (SecretValue::inspect($rawSecret)['status'] !== 'plaintext_legacy') {
            return;
        }

        $userId = $this->databaseUserIdForSession($user);
        if ($userId === null) {
            return;
        }

        try {
            $this->writeIntegration($userId, 'emach', $emachUser, SecretValue::encryptSecret($decryptedPassword), '');
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{chat_id:string,stored:bool,updated_at:string}
     */
    public function telegramForSession(array $sessionUser): array
    {
        return $this->telegramForUser($this->userForSession($sessionUser));
    }

    /**
     * @param array<string,mixed> $user
     * @return array{chat_id:string,stored:bool,updated_at:string}
     */
    public function telegramForUser(array $user): array
    {
        $settings = is_array($user['telegram_settings'] ?? null) ? $user['telegram_settings'] : [];
        $chatId = trim((string) ($settings['chat_id'] ?? ''));

        return [
            'chat_id' => $chatId,
            'stored' => $chatId !== '',
            'updated_at' => (string) ($settings['updated_at'] ?? ''),
        ];
    }

    public function saveEmachForSession(array $sessionUser, string $emachUser, string $password): bool
    {
        return $this->saveCredentialForSession($sessionUser, 'emach', $emachUser, $password);
    }

    public function saveTelegramForSession(array $sessionUser, string $chatId): bool
    {
        $userId = $this->databaseUserIdForSession($sessionUser);
        if ($userId === null || trim($chatId) === '') {
            return false;
        }

        return $this->writeTelegramChatId($userId, trim($chatId));
    }

    public function deleteTelegramForSession(array $sessionUser): bool
    {
        $userId = $this->databaseUserIdForSession($sessionUser);
        if ($userId === null) {
            return false;
        }

        return $this->clearTelegramChatId($userId);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function integrationsForSession(array $sessionUser, array $types): array
    {
        $userId = $this->databaseUserIdForSession($sessionUser);
        if ($userId === null || !$this->tablesAvailable()) {
            return [];
        }

        $types = array_values(array_unique(array_filter(array_map('strval', $types))));
        if ($types === []) {
            return [];
        }

        try {
            $result = [];
            foreach ($types as $type) {
                $row = $this->integrationRowForUser($userId, $type);
                $externalUser = trim((string) ($row->usuario_externo ?? ''));
                $secret = trim((string) ($row->valor_secreto ?? ''));
                $hasSecret = $secret !== '' && SecretValue::inspect($secret)['decryptable'];
                $stored = $this->isRedmineType($type)
                    ? $secret !== ''
                    : ($externalUser !== '' || $hasSecret);
                $result[$type] = [
                    'type' => $type,
                    'external_user' => $externalUser,
                    'has_external_user' => $externalUser !== '',
                    'has_secret' => $hasSecret,
                    'stored' => $stored,
                    'updated_at' => (string) ($row->actualizado_at ?? ''),
                    'masked_external_user' => $this->mask($externalUser),
                ];
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function integrationForSession(array $sessionUser, string $type): array
    {
        return $this->integrationsForSession($sessionUser, [$type])[$type] ?? [
            'type' => $type,
            'external_user' => '',
            'has_external_user' => false,
            'has_secret' => false,
            'stored' => false,
            'updated_at' => '',
            'masked_external_user' => '',
        ];
    }

    /**
     * Returns a decrypted credential only to server-side integration flows.
     *
     * @return array{user:string,secret:string,stored:bool}
     */
    public function credentialForSession(array $sessionUser, string $type): array
    {
        $userId = $this->databaseUserIdForSession($sessionUser);
        if ($userId === null || !$this->tablesAvailable()) {
            return ['user' => '', 'secret' => '', 'stored' => false];
        }

        return $this->credentialForUserId($userId, $type);
    }

    /**
     * Returns a decrypted credential for an already resolved NOVA user.
     * Redmine Mantencion and TIC intentionally share the same personal key.
     *
     * @return array{user:string,secret:string,stored:bool}
     */
    public function credentialForUserId(int $userId, string $type = self::REDMINE_TYPE): array
    {
        if ($userId <= 0 || !$this->tablesAvailable()) {
            return ['user' => '', 'secret' => '', 'stored' => false];
        }

        try {
            $row = $this->integrationRowForUser($userId, $type);
            $user = trim((string) ($row->usuario_externo ?? ''));
            $secret = SecretValue::decryptSecret((string) ($row->valor_secreto ?? '')) ?? '';
            $stored = $this->isRedmineType($type)
                ? $secret !== ''
                : ($user !== '' && $secret !== '');

            return ['user' => $user, 'secret' => $secret, 'stored' => $stored];
        } catch (\Throwable) {
            return ['user' => '', 'secret' => '', 'stored' => false];
        }
    }

    public function redmineTokenForRedmineId(string $redmineId): string
    {
        $redmineId = trim($redmineId);
        if ($redmineId === '' || !$this->tablesAvailable()) {
            return '';
        }

        try {
            $userId = (int) DB::table('usuarios_nova')->where('redmine_id', $redmineId)->value('id');

            return $this->credentialForUserId($userId, self::REDMINE_TYPE)['secret'];
        } catch (\Throwable) {
            return '';
        }
    }

    public function saveCredentialForSession(array $sessionUser, string $type, string $externalUser, string $secret): bool
    {
        $userId = $this->databaseUserIdForSession($sessionUser);
        $type = $this->canonicalType($type);
        $externalUser = trim($externalUser);
        if ($userId === null || $type === '' || !$this->tablesAvailable()) {
            return false;
        }

        $currentSecret = '';
        try {
            $currentSecret = (string) ($this->integrationRowForUser($userId, $type)->valor_secreto ?? '');
        } catch (\Throwable) {
            return false;
        }

        if ($secret === '') {
            $storedSecret = $currentSecret;
        } else {
            try {
                $storedSecret = SecretValue::encryptSecret($secret);
            } catch (\Throwable) {
                Log::warning('UserIntegrationRepository: fallo al cifrar una credencial nueva; se descarta sin guardar texto plano ni modificar la anterior.', ['type' => $type]);

                return false;
            }
        }

        if ($externalUser === '' && $storedSecret === '') {
            return false;
        }

        $saved = $this->writeIntegration($userId, $type, $externalUser, $storedSecret, '');
        if ($saved && $type === self::REDMINE_TYPE) {
            $this->deleteLegacyRedmineRows($userId);
        }

        return $saved;
    }

    public function deleteCredentialForSession(array $sessionUser, string $type): bool
    {
        $userId = $this->databaseUserIdForSession($sessionUser);
        $type = $this->canonicalType($type);
        if ($userId === null || $type === '' || !$this->tablesAvailable()) {
            return false;
        }

        try {
            $query = DB::table('integraciones_usuario')->where('usuario_id', $userId);
            $this->isRedmineType($type)
                ? $query->whereIn('tipo', $this->redmineTypes())->delete()
                : $query->where('tipo', $type)->delete();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $user
     */
    public function hasEmach(array $user): bool
    {
        return $this->emachForUser($user)['stored'];
    }

    /**
     * @param array<string,mixed> $user
     */
    public function hasTelegram(array $user): bool
    {
        return $this->telegramForUser($user)['stored'];
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function write(array $users): bool
    {
        return true;
    }

    /**
     * @param array<string,mixed> $user
     * @return array<int,string>
     */
    private function needles(array $user): array
    {
        return array_values(array_filter(array_map([$this, 'normalize'], [
            $user['id'] ?? '',
            $user['username'] ?? '',
            $user['rut'] ?? '',
            $user['rut_sin_dv'] ?? '',
            $user['core_user'] ?? '',
            $user['redmine_id'] ?? '',
            $user['legacy']['id'] ?? '',
        ])));
    }

    private function normalize(mixed $value): string
    {
        return StringNormalizer::normalize((string) $value);
    }

    private function tablesAvailable(): bool
    {
        try {
            return $this->usersTableAvailable() && Schema::hasTable('integraciones_usuario');
        } catch (\Throwable) {
            return false;
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

    private function databaseUserIdForSession(array $sessionUser): ?int
    {
        if (!$this->usersTableAvailable()) {
            return null;
        }

        try {
            $candidates = [
                'uuid' => [
                    $sessionUser['id'] ?? '',
                    $sessionUser['_nova_user_id'] ?? '',
                ],
                'usuario' => [
                    $sessionUser['username'] ?? '',
                    $sessionUser['usuario'] ?? '',
                    $sessionUser['rut_sin_dv'] ?? '',
                ],
                'rut' => [
                    $sessionUser['rut'] ?? '',
                ],
                'redmine_id' => [
                    $sessionUser['redmine_id'] ?? '',
                    $sessionUser['legacy']['id'] ?? '',
                ],
                'usuario_core' => [
                    $sessionUser['core_user'] ?? '',
                    $sessionUser['usuario_core'] ?? '',
                ],
            ];

            foreach ($candidates as $column => $values) {
                foreach ($values as $value) {
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue;
                    }
                    $id = DB::table('usuarios_nova')->where($column, $value)->value('id');
                    if ($id !== null) {
                        return (int) $id;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function writeIntegration(int $userId, string $type, string $externalUser, string $secret, string $chatId): bool
    {
        if ($userId <= 0 || !$this->tablesAvailable()) {
            return false;
        }

        $values = ['actualizado_at' => now()];
        if ($externalUser !== '') {
            $values['usuario_externo'] = $externalUser;
        }
        if ($secret !== '') {
            $values['valor_secreto'] = $secret;
        }
        if ($chatId !== '' && Schema::hasColumn('integraciones_usuario', 'chat_id')) {
            $values['chat_id'] = $chatId;
        }

        try {
            DB::table('integraciones_usuario')->updateOrInsert(
                ['usuario_id' => $userId, 'tipo' => $type],
                $values
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function canonicalType(string $type): string
    {
        $type = trim($type);

        return $this->isRedmineType($type) ? self::REDMINE_TYPE : $type;
    }

    private function isRedmineType(string $type): bool
    {
        return in_array(trim($type), $this->redmineTypes(), true);
    }

    /** @return array<int,string> */
    private function redmineTypes(): array
    {
        return array_merge([self::REDMINE_TYPE], self::LEGACY_REDMINE_TYPES);
    }

    private function integrationRowForUser(int $userId, string $type): ?object
    {
        $type = trim($type);
        $query = DB::table('integraciones_usuario')->where('usuario_id', $userId);
        if (!$this->isRedmineType($type)) {
            return $query->where('tipo', $type)->first();
        }

        $rows = $query->whereIn('tipo', $this->redmineTypes())->get();
        $canonical = $rows->firstWhere('tipo', self::REDMINE_TYPE);
        if ($canonical !== null && $this->rowHasDecryptableSecret($canonical)) {
            return $canonical;
        }

        return $rows
            ->filter(fn (object $row): bool => $this->rowHasDecryptableSecret($row))
            ->sortByDesc(fn (object $row): string => (string) ($row->actualizado_at ?? $row->creado_at ?? ''))
            ->first() ?? $canonical ?? $rows->first();
    }

    private function rowHasDecryptableSecret(object $row): bool
    {
        $secret = trim((string) ($row->valor_secreto ?? ''));

        return $secret !== '' && SecretValue::inspect($secret)['decryptable'];
    }

    private function deleteLegacyRedmineRows(int $userId): void
    {
        try {
            DB::table('integraciones_usuario')
                ->where('usuario_id', $userId)
                ->whereIn('tipo', self::LEGACY_REDMINE_TYPES)
                ->delete();
        } catch (\Throwable) {
        }
    }

    private function mask(string $value): string
    {
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length === 0) {
            return '';
        }
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return mb_substr($value, 0, 2) . str_repeat('*', max(3, $length - 4)) . mb_substr($value, -2);
    }

    private function writeTelegramChatId(int $userId, string $chatId): bool
    {
        if ($userId <= 0 || $chatId === '' || !Schema::hasTable('usuarios_nova') || !Schema::hasColumn('usuarios_nova', 'telegram_id_chat')) {
            return false;
        }

        try {
            DB::table('usuarios_nova')
                ->where('id', $userId)
                ->update([
                    'telegram_id_chat' => $chatId,
                    'actualizado_at' => now(),
                ]);

            if (Schema::hasTable('integraciones_usuario')) {
                DB::table('integraciones_usuario')
                    ->where('usuario_id', $userId)
                    ->where('tipo', 'telegram')
                    ->delete();
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function clearTelegramChatId(int $userId): bool
    {
        if ($userId <= 0 || !Schema::hasTable('usuarios_nova') || !Schema::hasColumn('usuarios_nova', 'telegram_id_chat')) {
            return false;
        }

        try {
            DB::table('usuarios_nova')
                ->where('id', $userId)
                ->update([
                    'telegram_id_chat' => null,
                    'actualizado_at' => now(),
                ]);

            if (Schema::hasTable('integraciones_usuario')) {
                DB::table('integraciones_usuario')
                    ->where('usuario_id', $userId)
                    ->where('tipo', 'telegram')
                    ->delete();
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
