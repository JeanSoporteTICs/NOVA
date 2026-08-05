<?php

namespace Tests\Unit;

use App\Modulos\Nova\Support\SecretValue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the ETAPA A / Lote A4 migration of Nextcloud (and, incidentally,
 * the shared CORE write path) credential handling in
 * RedmineMantencion/controllers/{core_credentials,usuarios}.php onto
 * SecretValue. Runs against the real usuarios_nova / integraciones_usuario
 * tables inside a rolled-back transaction.
 */
class NextcloudSecretMigrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('RedmineMantencion/controllers/storage.php');
        require_once base_path('RedmineMantencion/controllers/core_credentials.php');
        require_once base_path('RedmineMantencion/controllers/usuarios.php');
    }

    private function makeNovaUser(): array
    {
        $uuid = (string) Str::uuid();
        $redmineId = (string) random_int(900000, 999999);

        $id = DB::table('usuarios_nova')->insertGetId([
            'uuid' => $uuid,
            'usuario' => 'a4_test_' . Str::random(10),
            'redmine_id' => $redmineId,
            'nombre' => 'A4',
            'apellido' => 'Test',
            'rol' => 'usuario',
            'estado' => 'activo',
            'password' => bcrypt(Str::random(20)),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        return ['db_id' => (int) $id, 'redmine_id' => $redmineId];
    }

    private function storedSecret(int $userId, string $type): ?string
    {
        return DB::table('integraciones_usuario')
            ->where('usuario_id', $userId)
            ->where('tipo', $type)
            ->value('valor_secreto');
    }

    private function fakeCorruptedLaravelPayload(): string
    {
        return base64_encode(json_encode([
            'iv' => base64_encode(str_repeat('a', 16)),
            'value' => base64_encode('not-real-ciphertext'),
            'mac' => hash('sha256', 'wrong-mac'),
        ]));
    }

    public function test_reads_laravel_encrypted_nextcloud_credential(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'nextcloud',
            'usuario_externo' => 'ncuser',
            'valor_secreto' => encrypt('nc-secret'),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = nextcloud_credentials_for_user($user['redmine_id']);

        $this->assertSame('ncuser', $result['user']);
        $this->assertSame('nc-secret', $result['pass']);
    }

    public function test_reads_plaintext_legacy_and_auto_rewrites(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'nextcloud',
            'usuario_externo' => 'legacyuser',
            'valor_secreto' => 'legacy-plain-password',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = nextcloud_credentials_for_user($user['redmine_id']);
        $this->assertSame('legacy-plain-password', $result['pass']);

        $storedNow = $this->storedSecret($user['db_id'], 'nextcloud');
        $this->assertNotSame('legacy-plain-password', $storedNow);
        $this->assertSame('laravel_encrypted', SecretValue::inspect($storedNow)['status']);
        $this->assertSame('legacy-plain-password', SecretValue::decryptSecret($storedNow));
    }

    public function test_reads_legacy_enc_v1_via_existing_helper_and_auto_rewrites(): void
    {
        putenv('CORE_CREDENTIAL_KEY=a4-test-key');
        $legacy = core_credentials_encrypt('legacy-enc-v1-password');
        $this->assertStringStartsWith('enc:v1:', $legacy);

        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'nextcloud',
            'usuario_externo' => 'encv1user',
            'valor_secreto' => $legacy,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = nextcloud_credentials_for_user($user['redmine_id']);
        $this->assertSame('legacy-enc-v1-password', $result['pass']);

        $storedNow = $this->storedSecret($user['db_id'], 'nextcloud');
        $this->assertSame('laravel_encrypted', SecretValue::inspect($storedNow)['status']);
        $this->assertSame('legacy-enc-v1-password', SecretValue::decryptSecret($storedNow));

        putenv('CORE_CREDENTIAL_KEY');
    }

    public function test_corrupted_secret_is_never_returned_and_row_is_untouched(): void
    {
        $user = $this->makeNovaUser();
        $corrupted = $this->fakeCorruptedLaravelPayload();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'nextcloud',
            'usuario_externo' => 'brokenuser',
            'valor_secreto' => $corrupted,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = nextcloud_credentials_for_user($user['redmine_id']);

        $this->assertSame('', $result['pass']);
        $this->assertSame($corrupted, $this->storedSecret($user['db_id'], 'nextcloud'));
    }

    public function test_absence_of_credential_returns_empty(): void
    {
        $user = $this->makeNovaUser();

        $result = nextcloud_credentials_for_user($user['redmine_id']);

        $this->assertSame('', $result['user']);
        $this->assertSame('', $result['pass']);
        $this->assertFalse(nextcloud_credentials_has_saved($user['redmine_id']));
    }

    public function test_saving_a_new_nextcloud_secret_stores_it_encrypted(): void
    {
        $user = $this->makeNovaUser();

        $ok = nextcloud_credentials_save_for_user($user['redmine_id'], 'newuser', 'brand-new-secret');

        $this->assertTrue($ok);
        $stored = $this->storedSecret($user['db_id'], 'nextcloud');
        $this->assertNotSame('brand-new-secret', $stored);
        $this->assertSame('laravel_encrypted', SecretValue::inspect($stored)['status']);
        $this->assertSame('brand-new-secret', SecretValue::decryptSecret($stored));
    }

    public function test_reading_an_already_encrypted_secret_repeatedly_does_not_double_encrypt(): void
    {
        $user = $this->makeNovaUser();
        $original = encrypt('stable-secret');
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'nextcloud',
            'usuario_externo' => 'stableuser',
            'valor_secreto' => $original,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        nextcloud_credentials_for_user($user['redmine_id']);
        nextcloud_credentials_for_user($user['redmine_id']);
        $result = nextcloud_credentials_for_user($user['redmine_id']);

        $this->assertSame($original, $this->storedSecret($user['db_id'], 'nextcloud'));
        $this->assertSame('stable-secret', $result['pass']);
    }

    public function test_nextcloud_auto_rewrite_is_unaffected_by_other_types(): void
    {
        // As of Lote A5, core_credentials_maybe_rewrite_secret() also covers
        // 'core' (see tests/Unit/CoreSecretMigrationTest.php) — this test only
        // guards that Nextcloud's own A4 behavior stayed intact per-type.
        $nextcloudUser = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $nextcloudUser['db_id'],
            'tipo' => 'nextcloud',
            'usuario_externo' => 'ncuser',
            'valor_secreto' => 'nc-plain-legacy',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        nextcloud_credentials_for_user($nextcloudUser['redmine_id']);

        $this->assertSame('laravel_encrypted', SecretValue::inspect($this->storedSecret($nextcloudUser['db_id'], 'nextcloud'))['status']);
    }

    public function test_usuarios_central_save_integration_encrypted_does_not_double_encrypt(): void
    {
        $user = $this->makeNovaUser();
        $encrypted = encrypt('already-safe-nextcloud');

        usuarios_central_save_integration_encrypted($user['db_id'], 'nextcloud', $encrypted, 'ncuser');

        $this->assertSame($encrypted, $this->storedSecret($user['db_id'], 'nextcloud'));
    }

    public function test_usuarios_central_save_integration_encrypted_upgrades_plaintext_legacy(): void
    {
        $user = $this->makeNovaUser();

        usuarios_central_save_integration_encrypted($user['db_id'], 'nextcloud', 'plain-legacy-value', 'ncuser');

        $stored = $this->storedSecret($user['db_id'], 'nextcloud');
        $this->assertNotSame('plain-legacy-value', $stored);
        $this->assertSame('plain-legacy-value', SecretValue::decryptSecret($stored));
    }

    public function test_usuarios_central_save_integration_encrypted_skips_invalid_value(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'nextcloud',
            'usuario_externo' => 'ncuser',
            'valor_secreto' => encrypt('pre-existing'),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        usuarios_central_save_integration_encrypted($user['db_id'], 'nextcloud', $this->fakeCorruptedLaravelPayload(), 'ncuser');

        // Invalid input never overwrites a previously-good value.
        $this->assertSame('pre-existing', SecretValue::decryptSecret($this->storedSecret($user['db_id'], 'nextcloud')));
    }
}
