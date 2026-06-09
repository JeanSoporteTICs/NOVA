<?php

namespace App\Support\Integrations;

final class UserIntegrationRepository
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function users(): array
    {
        $raw = (string) @file_get_contents($this->path());
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $users = json_decode($raw, true);

        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
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
        $password = $this->decryptSecret((string) ($credentials['password'] ?? ''));

        return [
            'user' => $emachUser,
            'password' => $password,
            'stored' => $emachUser !== '' && $password !== '',
            'updated_at' => (string) ($credentials['updated_at'] ?? ''),
        ];
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
        $users = $this->users();
        $index = $this->userIndexForSession($sessionUser);
        if ($index === null || trim($emachUser) === '' || $password === '') {
            return false;
        }

        $users[$index]['emach_credentials'] = [
            'user' => trim($emachUser),
            'password' => $this->encryptSecret($password),
            'updated_at' => date(DATE_ATOM),
        ];

        return $this->write($users);
    }

    public function saveTelegramForSession(array $sessionUser, string $chatId): bool
    {
        $users = $this->users();
        $index = $this->userIndexForSession($sessionUser);
        if ($index === null || trim($chatId) === '') {
            return false;
        }

        $users[$index]['telegram_settings'] = [
            'chat_id' => trim($chatId),
            'updated_at' => date(DATE_ATOM),
        ];

        return $this->write($users);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $current
     * @return array<string,string>
     */
    public function emachFromPayload(array $payload, array $current): array
    {
        $currentCredentials = is_array($current['emach_credentials'] ?? null) ? $current['emach_credentials'] : [];
        $user = trim((string) ($payload['emach_user'] ?? $currentCredentials['user'] ?? ''));
        $password = (string) ($payload['emach_password'] ?? '');
        $passwordStored = (string) ($currentCredentials['password'] ?? '');

        if ($password !== '') {
            $passwordStored = $this->encryptSecret($password);
        }

        if ($user === '' && $passwordStored === '') {
            return [];
        }

        return [
            'user' => $user,
            'password' => $passwordStored,
            'updated_at' => date(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $current
     * @return array<string,string>
     */
    public function telegramFromPayload(array $payload, array $current): array
    {
        $currentSettings = is_array($current['telegram_settings'] ?? null) ? $current['telegram_settings'] : [];
        $chatId = trim((string) ($payload['telegram_chat_id'] ?? $currentSettings['chat_id'] ?? ''));

        if ($chatId === '') {
            return [];
        }

        return [
            'chat_id' => $chatId,
            'updated_at' => date(DATE_ATOM),
        ];
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
        $directory = dirname($this->path());
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return false;
        }

        $written = @file_put_contents($this->path(), json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL, LOCK_EX);
        if ($written !== false) {
            @chmod($this->path(), 0666);
        }

        return $written !== false;
    }

    private function encryptSecret(string $secret): string
    {
        return function_exists('encrypt') ? encrypt($secret) : $secret;
    }

    private function decryptSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        if (function_exists('decrypt')) {
            try {
                return (string) decrypt($secret);
            } catch (\Throwable) {
            }
        }

        return $secret;
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
        return strtolower((string) preg_replace('/[^0-9a-z]/i', '', (string) $value));
    }

    private function path(): string
    {
        return storage_path('app/nova/users.json');
    }
}
