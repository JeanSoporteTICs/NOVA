<?php

namespace Tests\Unit;

use App\Modulos\Nova\Repositories\NovaUserRepository;
use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Modulos\Nova\Support\SecretValue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the ETAPA A / Lote A3 migration of EMACH credential handling
 * (UserIntegrationRepository, NovaUserRepository::writeDatabaseIntegrations)
 * onto the central SecretValue helper. Runs against the real usuarios_nova /
 * integraciones_usuario tables inside a rolled-back transaction — nothing
 * written here survives the test.
 */
class EmachSecretMigrationTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{db_id:int, session: array<string,mixed>}
     */
    private function makeNovaUser(): array
    {
        $uuid = (string) Str::uuid();
        $username = 'a3_test_' . Str::random(10);

        $id = DB::table('usuarios_nova')->insertGetId([
            'uuid' => $uuid,
            'usuario' => $username,
            'nombre' => 'A3',
            'apellido' => 'Test',
            'rol' => 'usuario',
            'estado' => 'activo',
            'password' => bcrypt(Str::random(20)),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        return [
            'db_id' => (int) $id,
            'session' => ['id' => $uuid, 'username' => $username],
        ];
    }

    private function storedEmachSecret(int $userId): ?string
    {
        return DB::table('integraciones_usuario')
            ->where('usuario_id', $userId)
            ->where('tipo', 'emach')
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

    public function test_reads_laravel_encrypted_emach_credential(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'emach',
            'usuario_externo' => 'jdoe',
            'valor_secreto' => encrypt('s3cret-value'),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = app(UserIntegrationRepository::class)->emachForSession($user['session']);

        $this->assertSame('jdoe', $result['user']);
        $this->assertSame('s3cret-value', $result['password']);
        $this->assertTrue($result['stored']);
    }

    public function test_reads_plaintext_legacy_and_auto_rewrites_to_laravel_encrypted(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'emach',
            'usuario_externo' => 'legacyuser',
            'valor_secreto' => 'legacy-plain-password',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = app(UserIntegrationRepository::class)->emachForSession($user['session']);

        $this->assertSame('legacy-plain-password', $result['password']);
        $this->assertTrue($result['stored']);

        $storedNow = $this->storedEmachSecret($user['db_id']);
        $this->assertNotSame('legacy-plain-password', $storedNow);
        $this->assertSame('laravel_encrypted', SecretValue::inspect($storedNow)['status']);
        $this->assertSame('legacy-plain-password', SecretValue::decryptSecret($storedNow));
    }

    public function test_corrupted_secret_is_never_returned_and_is_left_untouched(): void
    {
        $user = $this->makeNovaUser();
        $corrupted = $this->fakeCorruptedLaravelPayload();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'emach',
            'usuario_externo' => 'brokenuser',
            'valor_secreto' => $corrupted,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $result = app(UserIntegrationRepository::class)->emachForSession($user['session']);

        $this->assertSame('', $result['password']);
        $this->assertFalse($result['stored']);
        $this->assertSame($corrupted, $this->storedEmachSecret($user['db_id']));
    }

    public function test_absence_of_credential_is_reported_as_not_stored(): void
    {
        $user = $this->makeNovaUser();

        $result = app(UserIntegrationRepository::class)->emachForSession($user['session']);

        $this->assertSame('', $result['user']);
        $this->assertSame('', $result['password']);
        $this->assertFalse($result['stored']);
    }

    public function test_saving_a_new_secret_stores_it_encrypted(): void
    {
        $user = $this->makeNovaUser();

        $ok = app(UserIntegrationRepository::class)->saveEmachForSession($user['session'], 'newuser', 'brand-new-secret');

        $this->assertTrue($ok);
        $stored = $this->storedEmachSecret($user['db_id']);
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
            'tipo' => 'emach',
            'usuario_externo' => 'stableuser',
            'valor_secreto' => $original,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $repo = app(UserIntegrationRepository::class);
        $repo->emachForSession($user['session']);
        $repo->emachForSession($user['session']);
        $result = $repo->emachForSession($user['session']);

        $this->assertSame($original, $this->storedEmachSecret($user['db_id']));
        $this->assertSame('stable-secret', $result['password']);
    }

    public function test_nova_user_repository_does_not_double_encrypt_an_already_encrypted_secret(): void
    {
        $values = [];
        $encrypted = encrypt('already-safe');

        $method = new \ReflectionMethod(NovaUserRepository::class, 'applyEmachSecret');
        $method->setAccessible(true);
        $method->invokeArgs(app(NovaUserRepository::class), [&$values, $encrypted]);

        $this->assertSame($encrypted, $values['valor_secreto']);
    }

    public function test_nova_user_repository_encrypts_plaintext_legacy_before_writing(): void
    {
        $values = [];

        $method = new \ReflectionMethod(NovaUserRepository::class, 'applyEmachSecret');
        $method->setAccessible(true);
        $method->invokeArgs(app(NovaUserRepository::class), [&$values, 'plain-old-value']);

        $this->assertArrayHasKey('valor_secreto', $values);
        $this->assertNotSame('plain-old-value', $values['valor_secreto']);
        $this->assertSame('plain-old-value', SecretValue::decryptSecret($values['valor_secreto']));
    }

    public function test_nova_user_repository_skips_writing_an_invalid_secret(): void
    {
        $values = [];

        $method = new \ReflectionMethod(NovaUserRepository::class, 'applyEmachSecret');
        $method->setAccessible(true);
        $method->invokeArgs(app(NovaUserRepository::class), [&$values, $this->fakeCorruptedLaravelPayload()]);

        $this->assertArrayNotHasKey('valor_secreto', $values);
    }

}
