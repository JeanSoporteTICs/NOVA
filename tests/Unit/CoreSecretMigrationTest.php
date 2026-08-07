<?php

namespace Tests\Unit;

use App\Modulos\Nova\Support\SecretValue;
use App\Modulos\RedmineMantencion\Services\MantencionUsuariosCentralService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the ETAPA A / Lote A5 migration of CORE credential handling onto
 * SecretValue — the generalization of A4's Nextcloud auto-rewrite to
 * type=core, plus dedicated coverage of core_credentials_for_user() and the
 * CORE caller of MantencionUsuariosCentralService::usuarios_central_save_integration_encrypted(). Runs
 * against the real usuarios_nova / integraciones_usuario tables inside a
 * rolled-back transaction.
 */
class CoreSecretMigrationTest extends TestCase
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
            'usuario' => 'a5_test_' . Str::random(10),
            'redmine_id' => $redmineId,
            'nombre' => 'A5',
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

    public function test_reads_laravel_encrypted_core_credential_without_rewrite(): void
    {
        $user = $this->makeNovaUser();
        $original = encrypt('core-secret');
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'core',
            'usuario_externo' => 'coreuser',
            'valor_secreto' => $original,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = core_credentials_for_user($user['redmine_id']);

        $this->assertSame('coreuser', $result['user']);
        $this->assertSame('core-secret', $result['pass']);
        $this->assertSame($original, $this->storedSecret($user['db_id'], 'core'));
    }

    public function test_reads_plaintext_legacy_and_auto_rewrites(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'core',
            'usuario_externo' => 'legacyuser',
            'valor_secreto' => 'legacy-plain-password',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = core_credentials_for_user($user['redmine_id']);
        $this->assertSame('legacy-plain-password', $result['pass']);

        $storedNow = $this->storedSecret($user['db_id'], 'core');
        $this->assertNotSame('legacy-plain-password', $storedNow);
        $this->assertSame('laravel_encrypted', SecretValue::inspect($storedNow)['status']);
        $this->assertSame('legacy-plain-password', SecretValue::decryptSecret($storedNow));
    }

    public function test_reads_legacy_enc_v1_via_existing_helper_and_auto_rewrites(): void
    {
        putenv('CORE_CREDENTIAL_KEY=a5-test-key');
        $legacy = core_credentials_encrypt('legacy-enc-v1-core-password');
        $this->assertStringStartsWith('enc:v1:', $legacy);

        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'core',
            'usuario_externo' => 'encv1user',
            'valor_secreto' => $legacy,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = core_credentials_for_user($user['redmine_id']);
        $this->assertSame('legacy-enc-v1-core-password', $result['pass']);

        $storedNow = $this->storedSecret($user['db_id'], 'core');
        $this->assertSame('laravel_encrypted', SecretValue::inspect($storedNow)['status']);
        $this->assertSame('legacy-enc-v1-core-password', SecretValue::decryptSecret($storedNow));

        putenv('CORE_CREDENTIAL_KEY');
    }

    public function test_corrupted_secret_is_never_returned_and_row_is_untouched(): void
    {
        $user = $this->makeNovaUser();
        $corrupted = $this->fakeCorruptedLaravelPayload();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'core',
            'usuario_externo' => 'brokenuser',
            'valor_secreto' => $corrupted,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = core_credentials_for_user($user['redmine_id']);

        $this->assertSame('', $result['pass']);
        $this->assertSame($corrupted, $this->storedSecret($user['db_id'], 'core'));
    }

    public function test_absence_of_credential_returns_empty_contract_unchanged(): void
    {
        $user = $this->makeNovaUser();

        $result = core_credentials_for_user($user['redmine_id']);

        $this->assertSame(['user' => '', 'pass' => ''], $result);
        $this->assertFalse(core_credentials_has_saved($user['redmine_id']));
    }

    public function test_saving_a_new_core_secret_stores_it_encrypted(): void
    {
        $user = $this->makeNovaUser();

        $ok = core_credentials_save_for_user($user['redmine_id'], 'newuser', 'brand-new-core-secret');

        $this->assertTrue($ok);
        $stored = $this->storedSecret($user['db_id'], 'core');
        $this->assertNotSame('brand-new-core-secret', $stored);
        $this->assertSame('laravel_encrypted', SecretValue::inspect($stored)['status']);
        $this->assertSame('brand-new-core-secret', SecretValue::decryptSecret($stored));
    }

    public function test_reading_an_already_encrypted_core_secret_repeatedly_does_not_double_encrypt(): void
    {
        $user = $this->makeNovaUser();
        $original = encrypt('stable-core-secret');
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'core',
            'usuario_externo' => 'stableuser',
            'valor_secreto' => $original,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        core_credentials_for_user($user['redmine_id']);
        core_credentials_for_user($user['redmine_id']);
        $result = core_credentials_for_user($user['redmine_id']);

        $this->assertSame($original, $this->storedSecret($user['db_id'], 'core'));
        $this->assertSame('stable-core-secret', $result['pass']);
    }

    public function test_nextcloud_type_still_auto_rewrites_after_generalization(): void
    {
        // Regression guard for Lote A5: generalizing the gate from
        // 'nextcloud' to 'nextcloud' || 'core' must not disable Nextcloud's
        // own A4 auto-rewrite.
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'nextcloud',
            'usuario_externo' => 'ncuser',
            'valor_secreto' => 'still-plain-legacy',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        nextcloud_credentials_for_user($user['redmine_id']);

        $stored = $this->storedSecret($user['db_id'], 'nextcloud');
        $this->assertSame('laravel_encrypted', SecretValue::inspect($stored)['status']);
    }

    public function test_usuarios_central_save_integration_encrypted_core_laravel_encrypted_is_intact(): void
    {
        $user = $this->makeNovaUser();
        $encrypted = encrypt('already-safe-core');

        app(MantencionUsuariosCentralService::class)->usuarios_central_save_integration_encrypted($user['db_id'], 'core', $encrypted, 'coreuser');

        $this->assertSame($encrypted, $this->storedSecret($user['db_id'], 'core'));
    }

    public function test_usuarios_central_save_integration_encrypted_core_plaintext_is_converted(): void
    {
        $user = $this->makeNovaUser();

        app(MantencionUsuariosCentralService::class)->usuarios_central_save_integration_encrypted($user['db_id'], 'core', 'plain-legacy-core-value', 'coreuser');

        $stored = $this->storedSecret($user['db_id'], 'core');
        $this->assertNotSame('plain-legacy-core-value', $stored);
        $this->assertSame('plain-legacy-core-value', SecretValue::decryptSecret($stored));
    }

    public function test_usuarios_central_save_integration_encrypted_core_enc_v1_is_converted(): void
    {
        putenv('CORE_CREDENTIAL_KEY=a5-test-key-2');
        $legacy = core_credentials_encrypt('enc-v1-via-usuarios-bridge');

        $user = $this->makeNovaUser();
        app(MantencionUsuariosCentralService::class)->usuarios_central_save_integration_encrypted($user['db_id'], 'core', $legacy, 'coreuser');

        $stored = $this->storedSecret($user['db_id'], 'core');
        $this->assertSame('laravel_encrypted', SecretValue::inspect($stored)['status']);
        $this->assertSame('enc-v1-via-usuarios-bridge', SecretValue::decryptSecret($stored));

        putenv('CORE_CREDENTIAL_KEY');
    }

    public function test_usuarios_central_save_integration_encrypted_core_invalid_does_not_overwrite(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'core',
            'usuario_externo' => 'coreuser',
            'valor_secreto' => encrypt('pre-existing-core'),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        app(MantencionUsuariosCentralService::class)->usuarios_central_save_integration_encrypted($user['db_id'], 'core', $this->fakeCorruptedLaravelPayload(), 'coreuser');

        $this->assertSame('pre-existing-core', SecretValue::decryptSecret($this->storedSecret($user['db_id'], 'core')));
    }

    public function test_type_and_user_scoping_does_not_leak_across_types_or_users(): void
    {
        $userA = $this->makeNovaUser();
        $userB = $this->makeNovaUser();

        core_credentials_save_for_user($userA['redmine_id'], 'coreuserA', 'secretA');
        nextcloud_credentials_save_for_user($userA['redmine_id'], 'ncuserA', 'secretNcA');
        core_credentials_save_for_user($userB['redmine_id'], 'coreuserB', 'secretB');

        $this->assertSame('secretA', core_credentials_for_user($userA['redmine_id'])['pass']);
        $this->assertSame('secretNcA', nextcloud_credentials_for_user($userA['redmine_id'])['pass']);
        $this->assertSame('secretB', core_credentials_for_user($userB['redmine_id'])['pass']);
        $this->assertSame('', nextcloud_credentials_for_user($userB['redmine_id'])['pass']);
    }
}
