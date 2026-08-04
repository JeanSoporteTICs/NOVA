<?php

namespace Tests\Unit;

use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Modulos\Nova\Support\SecretValue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NextcloudSecretMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private function makeNovaUser(): array
    {
        $uuid = (string) Str::uuid();
        $id = DB::table('usuarios_nova')->insertGetId([
            'uuid' => $uuid, 'usuario' => 'nc_test_'.Str::random(10), 'redmine_id' => (string) random_int(800000, 899999),
            'nombre' => 'Nextcloud', 'apellido' => 'Test', 'rol' => 'usuario', 'estado' => 'activo',
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
        return (string) DB::table('integraciones_usuario')->where('usuario_id', $id)->where('tipo', 'nextcloud')->value('valor_secreto');
    }

    public function test_native_repository_saves_and_reads_nextcloud_secrets_encrypted(): void
    {
        $user = $this->makeNovaUser();
        self::assertTrue($this->repository()->saveCredentialForSession($user['session'], 'nextcloud', 'ncuser', 'nc-secret'));
        self::assertNotSame('nc-secret', $this->stored($user['id']));
        self::assertSame('laravel_encrypted', SecretValue::inspect($this->stored($user['id']))['status']);
        self::assertSame('nc-secret', $this->repository()->credentialForSession($user['session'], 'nextcloud')['secret']);
    }

    public function test_native_repository_upgrades_plaintext_nextcloud_secret_when_read(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['id'], 'tipo' => 'nextcloud', 'usuario_externo' => 'legacy',
            'valor_secreto' => 'legacy-plain', 'creado_at' => now(), 'actualizado_at' => now(),
        ]);

        self::assertSame('legacy-plain', $this->repository()->credentialForSession($user['session'], 'nextcloud')['secret']);
        self::assertSame('laravel_encrypted', SecretValue::inspect($this->stored($user['id']))['status']);
    }

    public function test_credentials_remain_scoped_by_user_and_type(): void
    {
        $first = $this->makeNovaUser();
        $second = $this->makeNovaUser();
        $this->repository()->saveCredentialForSession($first['session'], 'nextcloud', 'nc-a', 'secret-a');
        $this->repository()->saveCredentialForSession($first['session'], 'core', 'core-a', 'core-secret-a');
        $this->repository()->saveCredentialForSession($second['session'], 'nextcloud', 'nc-b', 'secret-b');

        self::assertSame('secret-a', $this->repository()->credentialForSession($first['session'], 'nextcloud')['secret']);
        self::assertSame('core-secret-a', $this->repository()->credentialForSession($first['session'], 'core')['secret']);
        self::assertSame('secret-b', $this->repository()->credentialForSession($second['session'], 'nextcloud')['secret']);
    }
}
