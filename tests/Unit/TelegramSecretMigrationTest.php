<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Verifica que TELEGRAM_BOT_TOKEN tenga una sola fuente de verdad: .env.
 * Todos los archivos usados por estas pruebas son temporales.
 */
class TelegramSecretMigrationTest extends TestCase
{
    /** @var array<int,string> */
    private array $scratchFiles = [];

    private string|false $originalProcessToken;

    private bool $hadEnvToken = false;

    private mixed $originalEnvToken = null;

    private bool $hadServerToken = false;

    private mixed $originalServerToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('telegram/lib/telegram.php');

        $this->originalProcessToken = getenv('TELEGRAM_BOT_TOKEN');
        $this->hadEnvToken = array_key_exists('TELEGRAM_BOT_TOKEN', $_ENV);
        $this->originalEnvToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? null;
        $this->hadServerToken = array_key_exists('TELEGRAM_BOT_TOKEN', $_SERVER);
        $this->originalServerToken = $_SERVER['TELEGRAM_BOT_TOKEN'] ?? null;
    }

    protected function tearDown(): void
    {
        foreach ($this->scratchFiles as $file) {
            @unlink($file);
        }

        if ($this->originalProcessToken === false) {
            putenv('TELEGRAM_BOT_TOKEN');
        } else {
            putenv('TELEGRAM_BOT_TOKEN=' . $this->originalProcessToken);
        }

        if ($this->hadEnvToken) {
            $_ENV['TELEGRAM_BOT_TOKEN'] = $this->originalEnvToken;
        } else {
            unset($_ENV['TELEGRAM_BOT_TOKEN']);
        }
        if ($this->hadServerToken) {
            $_SERVER['TELEGRAM_BOT_TOKEN'] = $this->originalServerToken;
        } else {
            unset($_SERVER['TELEGRAM_BOT_TOKEN']);
        }

        $this->scratchFiles = [];
        parent::tearDown();
    }

    private function scratchPath(string $extension): string
    {
        $path = sys_get_temp_dir() . '/nova_telegram_' . bin2hex(random_bytes(6)) . $extension;
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

    public function test_json_token_is_never_used_as_runtime_configuration(): void
    {
        $configPath = $this->scratchPath('.json');
        $envPath = $this->scratchPath('.env');
        $this->writeRawConfig($configPath, [
            'bot_token' => encrypt('123456:LEGACY-FILE-TOKEN'),
            'chat_id' => '999',
        ]);

        $config = telegram_read_config($configPath, $envPath);

        $this->assertSame('', $config['bot_token']);
        $this->assertSame('999', $config['chat_id']);
    }

    public function test_env_is_the_only_source_for_the_global_token(): void
    {
        $configPath = $this->scratchPath('.json');
        $envPath = $this->scratchPath('.env');
        $this->writeRawConfig($configPath, ['bot_token' => '123456:FILE-TOKEN']);
        file_put_contents($envPath, "APP_ENV=testing\nTELEGRAM_BOT_TOKEN=\"999999:ENV-TOKEN\"\n");

        $config = telegram_read_config($configPath, $envPath);

        $this->assertSame('999999:ENV-TOKEN', $config['bot_token']);
    }

    public function test_saving_writes_token_to_env_and_removes_it_from_json(): void
    {
        $configPath = $this->scratchPath('.json');
        $envPath = $this->scratchPath('.env');
        file_put_contents($envPath, "APP_ENV=testing\n");

        $ok = telegram_save_config([
            'bot_token' => '123456:BRAND-NEW-TOKEN',
            'chat_id' => '111',
            'proxy_url' => '10.0.0.1:3128',
        ], $configPath, $envPath);

        $this->assertTrue($ok);
        $this->assertSame('123456:BRAND-NEW-TOKEN', telegram_env_file_value('TELEGRAM_BOT_TOKEN', $envPath));

        $raw = json_decode((string) file_get_contents($configPath), true);
        $this->assertArrayNotHasKey('bot_token', $raw);
        $this->assertSame('111', $raw['chat_id']);
        $this->assertSame('10.0.0.1:3128', $raw['proxy_url']);

        $reread = telegram_read_config($configPath, $envPath);
        $this->assertSame('123456:BRAND-NEW-TOKEN', $reread['bot_token']);
    }

    public function test_saving_replaces_existing_env_value_without_duplicates(): void
    {
        $configPath = $this->scratchPath('.json');
        $envPath = $this->scratchPath('.env');
        file_put_contents($envPath, "TELEGRAM_BOT_TOKEN=\"111111:OLD-TOKEN\"\nAPP_ENV=testing\n");

        $ok = telegram_save_config([
            'bot_token' => '222222:NEW-TOKEN',
            'chat_id' => '',
            'proxy_url' => '',
        ], $configPath, $envPath);

        $this->assertTrue($ok);
        $contents = (string) file_get_contents($envPath);
        $this->assertSame(1, substr_count($contents, 'TELEGRAM_BOT_TOKEN='));
        $this->assertSame('222222:NEW-TOKEN', telegram_env_file_value('TELEGRAM_BOT_TOKEN', $envPath));
    }

    public function test_migrates_encrypted_legacy_token_and_cleans_json(): void
    {
        $configPath = $this->scratchPath('.json');
        $envPath = $this->scratchPath('.env');
        file_put_contents($envPath, "APP_ENV=testing\n");
        $this->writeRawConfig($configPath, [
            'bot_token' => encrypt('123456:MIGRATED-TOKEN'),
            'chat_id' => '999',
            'proxy_url' => '',
        ]);

        $result = telegram_migrate_legacy_token_to_env($configPath, $envPath);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['migrated']);
        $this->assertTrue($result['removed']);
        $this->assertSame('123456:MIGRATED-TOKEN', telegram_env_file_value('TELEGRAM_BOT_TOKEN', $envPath));
        $raw = json_decode((string) file_get_contents($configPath), true);
        $this->assertArrayNotHasKey('bot_token', $raw);
        $this->assertSame('999', $raw['chat_id']);
    }

    public function test_existing_env_token_wins_and_legacy_copy_is_removed(): void
    {
        $configPath = $this->scratchPath('.json');
        $envPath = $this->scratchPath('.env');
        file_put_contents($envPath, "TELEGRAM_BOT_TOKEN=\"999999:DEPLOYED-TOKEN\"\n");
        $this->writeRawConfig($configPath, [
            'bot_token' => encrypt('123456:OLD-FILE-TOKEN'),
            'chat_id' => '999',
        ]);

        $result = telegram_migrate_legacy_token_to_env($configPath, $envPath);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['migrated']);
        $this->assertTrue($result['removed']);
        $this->assertSame('999999:DEPLOYED-TOKEN', telegram_env_file_value('TELEGRAM_BOT_TOKEN', $envPath));
        $raw = json_decode((string) file_get_contents($configPath), true);
        $this->assertArrayNotHasKey('bot_token', $raw);
    }

    public function test_corrupted_legacy_token_is_preserved_when_migration_fails(): void
    {
        $configPath = $this->scratchPath('.json');
        $envPath = $this->scratchPath('.env');
        file_put_contents($envPath, "APP_ENV=testing\n");
        $corrupted = $this->fakeCorruptedLaravelPayload();
        $this->writeRawConfig($configPath, ['bot_token' => $corrupted]);

        $result = telegram_migrate_legacy_token_to_env($configPath, $envPath);

        $this->assertFalse($result['ok']);
        $raw = json_decode((string) file_get_contents($configPath), true);
        $this->assertSame($corrupted, $raw['bot_token']);
        $this->assertSame('', telegram_env_file_value('TELEGRAM_BOT_TOKEN', $envPath));
    }
}
