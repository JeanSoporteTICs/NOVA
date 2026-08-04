<?php

namespace Tests\Unit;

use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Modulos\Nova\Support\SecretValue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CoreSecretMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private function makeNovaUser(): array
    {
        $uuid = (string) Str::uuid();
        $id = DB::table('usuarios_nova')->insertGetId([
            'uuid' => $uuid, 'usuario' => 'core_test_'.Str::random(10), 'redmine_id' => (string) random_int(900000, 999999),
            'nombre' => 'Core', 'apellido' => 'Test', 'rol' => 'usuario', 'estado' => 'activo',
            'password' => bcrypt(Str::random(20)), 'creado_at' => now(), 'actualizado_at' => now(),
        ]);

        return ['id' => (int) $id, 'session' => ['id' => $uuid]];
    }

    private function repository(): UserIntegrationRepository
    {
        return app(UserIntegrationRepository::class);
    }

    private function stored(int $id): string
    {
        return (string) DB::table('integraciones_usuario')->where('usuario_id', $id)->where('tipo', 'core')->value('valor_secreto');
    }

    public function test_native_repository_saves_and_reads_core_secrets_encrypted(): void
    {
        $user = $this->makeNovaUser();
        self::assertTrue($this->repository()->saveCredentialForSession($user['session'], 'core', 'coreuser', 'core-secret'));
        self::assertNotSame('core-secret', $this->stored($user['id']));
        self::assertSame('laravel_encrypted', SecretValue::inspect($this->stored($user['id']))['status']);
        self::assertSame('core-secret', $this->repository()->credentialForSession($user['session'], 'core')['secret']);
    }

    public function test_native_repository_upgrades_plaintext_core_secret_when_read(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['id'], 'tipo' => 'core', 'usuario_externo' => 'legacy',
            'valor_secreto' => 'legacy-plain', 'creado_at' => now(), 'actualizado_at' => now(),
        ]);

        self::assertSame('legacy-plain', $this->repository()->credentialForSession($user['session'], 'core')['secret']);
        self::assertSame('laravel_encrypted', SecretValue::inspect($this->stored($user['id']))['status']);
    }

    public function test_corrupted_core_secret_is_not_returned_or_overwritten(): void
    {
        $user = $this->makeNovaUser();
        $corrupted = base64_encode(json_encode(['iv' => base64_encode(str_repeat('a', 16)), 'value' => base64_encode('broken'), 'mac' => hash('sha256', 'wrong')]));
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['id'], 'tipo' => 'core', 'usuario_externo' => 'broken',
            'valor_secreto' => $corrupted, 'creado_at' => now(), 'actualizado_at' => now(),
        ]);

        self::assertSame('', $this->repository()->credentialForSession($user['session'], 'core')['secret']);
        self::assertSame($corrupted, $this->stored($user['id']));
    }
}
