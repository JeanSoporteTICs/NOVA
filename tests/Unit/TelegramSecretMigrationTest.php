<?php

namespace Tests\Unit;

use App\Modulos\Nova\Support\SecretValue;
use Tests\TestCase;

/**
 * Covers the ETAPA A / Lote A6 migration of the Telegram bot_token stored in
 * storage/app/telegram/config.json onto SecretValue. Every test uses a
 * throwaway path (telegram_read_config()/telegram_save_config() both accept
 * an explicit $path) — the real config.json is never read from or written
 * to by this suite.
 */
class TelegramSecretMigrationTest extends TestCase
{
    /** @var array<int,string> */
    private array $scratchFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('telegram/lib/telegram.php');
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchFiles as $file) {
            @unlink($file);
        }
        $this->scratchFiles = [];
        parent::tearDown();
    }

    private function scratchPath(): string
    {
        $path = sys_get_temp_dir() . '/nova_telegram_config_test_' . bin2hex(random_bytes(6)) . '.json';
        $this->scratchFiles[] = $path;

        return $path;
    }

    private function writeRawConfig(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    }

    private function fakeCorruptedLaravelPayload(): string
    {
        return base64_encode(json_encode([
            'iv' => base64_encode(str_repeat('a', 16)),
            'value' => base64_encode('not-real-ciphertext'),
            'mac' => hash('sha256', 'wrong-mac'),
        ]));
    }

    public function test_reads_laravel_encrypted_bot_token(): void
    {
        $path = $this->scratchPath();
        $this->writeRawConfig($path, [
            'bot_token' => encrypt('123456:REAL-TOKEN'),
            'chat_id' => '999',
            'proxy_url' => '',
            'default_parse_mode' => '',
            'updated_at' => date(DATE_ATOM),
        ]);

        $config = telegram_read_config($path);

        $this->assertSame('123456:REAL-TOKEN', $config['bot_token']);
        $this->assertSame('999', $config['chat_id']);
    }

    public function test_reads_plaintext_legacy_bot_token_without_rewriting_file(): void
    {
        $path = $this->scratchPath();
        $this->writeRawConfig($path, [
            'bot_token' => '123456:LEGACY-PLAINTEXT-TOKEN',
            'chat_id' => '999',
            'proxy_url' => '',
            'default_parse_mode' => '',
            'updated_at' => date(DATE_ATOM),
        ]);

        $config = telegram_read_config($path);
        $this->assertSame('123456:LEGACY-PLAINTEXT-TOKEN', $config['bot_token']);

        // Lote A6 explicitly rewrites only on the next save, never on read
        // (config.json is read from hot paths, including inside the bot's
        // polling loop — rewriting on every read would mean writing the
        // file constantly).
        $stillOnDisk = json_decode((string) file_get_contents($path), true);
        $this->assertSame('123456:LEGACY-PLAINTEXT-TOKEN', $stillOnDisk['bot_token']);
    }

    public function test_corrupted_bot_token_is_never_exposed(): void
    {
        $path = $this->scratchPath();
        $corrupted = $this->fakeCorruptedLaravelPayload();
        $this->writeRawConfig($path, [
            'bot_token' => $corrupted,
            'chat_id' => '999',
            'proxy_url' => '',
            'default_parse_mode' => '',
            'updated_at' => date(DATE_ATOM),
        ]);

        $config = telegram_read_config($path);

        $this->assertSame('', $config['bot_token']);
        $stillOnDisk = json_decode((string) file_get_contents($path), true);
        $this->assertSame($corrupted, $stillOnDisk['bot_token']);
    }

    public function test_absent_bot_token_reads_as_empty(): void
    {
        $path = $this->scratchPath();
        $this->writeRawConfig($path, [
            'bot_token' => '',
            'chat_id' => '',
            'proxy_url' => '',
            'default_parse_mode' => '',
            'updated_at' => date(DATE_ATOM),
        ]);

        $config = telegram_read_config($path);
        $this->assertSame('', $config['bot_token']);
        $this->assertFalse(telegram_is_configured($config));
    }

    public function test_missing_file_reads_as_empty_config(): void
    {
        $path = $this->scratchPath();
        // Deliberately never created.

        $config = telegram_read_config($path);
        $this->assertSame('', $config['bot_token'] ?? '');
    }

    public function test_saving_a_new_token_stores_it_encrypted_on_disk(): void
    {
        $path = $this->scratchPath();

        $ok = telegram_save_config([
            'bot_token' => '123456:BRAND-NEW-TOKEN',
            'chat_id' => '111',
            'proxy_url' => '10.0.0.1:3128',
        ], $path);

        $this->assertTrue($ok);

        $raw = json_decode((string) file_get_contents($path), true);
        $this->assertNotSame('123456:BRAND-NEW-TOKEN', $raw['bot_token']);
        $this->assertSame('laravel_encrypted', SecretValue::inspect($raw['bot_token'])['status']);
        $this->assertSame('123456:BRAND-NEW-TOKEN', SecretValue::decryptSecret($raw['bot_token']));

        // Non-secret fields stay readable on disk, unchanged structure.
        $this->assertSame('111', $raw['chat_id']);
        $this->assertSame('10.0.0.1:3128', $raw['proxy_url']);
        $this->assertArrayHasKey('updated_at', $raw);
        $this->assertArrayHasKey('default_parse_mode', $raw);

        $reread = telegram_read_config($path);
        $this->assertSame('123456:BRAND-NEW-TOKEN', $reread['bot_token']);
    }

    public function test_resaving_an_already_encrypted_token_does_not_double_encrypt(): void
    {
        $path = $this->scratchPath();
        $this->writeRawConfig($path, [
            'bot_token' => encrypt('123456:STABLE-TOKEN'),
            'chat_id' => '999',
            'proxy_url' => '',
            'default_parse_mode' => '',
            'updated_at' => date(DATE_ATOM),
        ]);

        // Simulates TelegramController::updateAdmin() leaving the token field
        // blank ("keep current") — it reads the config first, then saves the
        // (already-decrypted-in-memory) token back unchanged.
        $current = telegram_read_config($path);
        telegram_save_config(['bot_token' => $current['bot_token'], 'chat_id' => '999', 'proxy_url' => ''], $path);

        $raw = json_decode((string) file_get_contents($path), true);
        $this->assertSame('laravel_encrypted', SecretValue::inspect($raw['bot_token'])['status']);
        $this->assertSame('123456:STABLE-TOKEN', SecretValue::decryptSecret($raw['bot_token']));
    }

    public function test_saving_upgrades_a_plaintext_legacy_token_to_encrypted(): void
    {
        $path = $this->scratchPath();
        $this->writeRawConfig($path, [
            'bot_token' => '123456:LEGACY-PLAINTEXT-TOKEN',
            'chat_id' => '999',
            'proxy_url' => '',
            'default_parse_mode' => '',
            'updated_at' => date(DATE_ATOM),
        ]);

        // Admin only changes chat_id via the form, leaving bot_token blank
        // ("keep current") — TelegramController falls back to the decrypted
        // current value read a moment earlier, then saves.
        $current = telegram_read_config($path);
        telegram_save_config(['bot_token' => $current['bot_token'], 'chat_id' => '555', 'proxy_url' => ''], $path);

        $raw = json_decode((string) file_get_contents($path), true);
        $this->assertSame('laravel_encrypted', SecretValue::inspect($raw['bot_token'])['status']);
        $this->assertSame('123456:LEGACY-PLAINTEXT-TOKEN', SecretValue::decryptSecret($raw['bot_token']));
        $this->assertSame('555', $raw['chat_id']);
    }

    public function test_env_override_still_takes_priority_over_file(): void
    {
        $path = $this->scratchPath();
        $this->writeRawConfig($path, [
            'bot_token' => encrypt('123456:FILE-TOKEN'),
            'chat_id' => '999',
            'proxy_url' => '',
            'default_parse_mode' => '',
            'updated_at' => date(DATE_ATOM),
        ]);

        putenv('TELEGRAM_BOT_TOKEN=999999:ENV-OVERRIDE-TOKEN');
        $config = telegram_read_config($path);
        putenv('TELEGRAM_BOT_TOKEN');

        $this->assertSame('999999:ENV-OVERRIDE-TOKEN', $config['bot_token']);
    }
}
