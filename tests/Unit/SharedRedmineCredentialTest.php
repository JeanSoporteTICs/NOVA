<?php

namespace Tests\Unit;

use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedRedmineCredentialTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{db_id:int,session:array<string,string>,redmine_id:string} */
    private function makeNovaUser(): array
    {
        $uuid = (string) Str::uuid();
        $redmineId = (string) random_int(900000, 999999);
        $username = 'shared_redmine_'.Str::random(8);
        $id = (int) DB::table('usuarios_nova')->insertGetId([
            'uuid' => $uuid,
            'usuario' => $username,
            'redmine_id' => $redmineId,
            'nombre' => 'API',
            'apellido' => 'Compartida',
            'rol' => 'usuario',
            'estado' => 'activo',
            'password' => bcrypt(Str::random(20)),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        return [
            'db_id' => $id,
            'session' => ['id' => $uuid, 'username' => $username],
            'redmine_id' => $redmineId,
        ];
    }

    public function test_tic_reads_an_existing_mantencion_token(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'redmine_mantencion',
            'usuario_externo' => $user['redmine_id'],
            'valor_secreto' => encrypt('shared-api-key'),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'redmine_tic',
            'usuario_externo' => $user['redmine_id'],
            'valor_secreto' => null,
            'creado_at' => now(),
            'actualizado_at' => now()->addSecond(),
        ]);

        $repository = app(UserIntegrationRepository::class);
        $state = $repository->integrationForSession($user['session'], 'redmine_tic');

        $this->assertTrue($state['stored']);
        $this->assertTrue($state['has_secret']);
        $this->assertSame(
            'shared-api-key',
            $repository->credentialForUserId($user['db_id'], 'redmine_tic')['secret']
        );
        $this->assertSame('shared-api-key', $repository->redmineTokenForRedmineId($user['redmine_id']));
    }

    public function test_saving_from_either_module_uses_one_canonical_redmine_row(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'redmine_mantencion',
            'usuario_externo' => $user['redmine_id'],
            'valor_secreto' => encrypt('old-api-key'),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $saved = app(UserIntegrationRepository::class)->saveCredentialForSession(
            $user['session'],
            'redmine_tic',
            '',
            'new-api-key'
        );

        $this->assertTrue($saved);
        $this->assertSame(
            ['redmine'],
            DB::table('integraciones_usuario')
                ->where('usuario_id', $user['db_id'])
                ->whereIn('tipo', ['redmine', 'redmine_mantencion', 'redmine_tic'])
                ->pluck('tipo')
                ->all()
        );
        $this->assertSame(
            'new-api-key',
            app(UserIntegrationRepository::class)->credentialForUserId($user['db_id'])['secret']
        );
    }

    public function test_redmine_identity_without_api_key_is_not_reported_as_configured(): void
    {
        $user = $this->makeNovaUser();
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'redmine_mantencion',
            'usuario_externo' => $user['redmine_id'],
            'valor_secreto' => null,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $state = app(UserIntegrationRepository::class)
            ->integrationForSession($user['session'], 'redmine');

        $this->assertFalse($state['stored']);
        $this->assertFalse($state['has_secret']);
    }

    public function test_core_username_without_a_decryptable_password_is_not_reported_as_configured(): void
    {
        $user = $this->makeNovaUser();
        $invalidSecret = base64_encode(json_encode([
            'iv' => base64_encode(str_repeat('x', 16)),
            'value' => base64_encode('invalid-ciphertext'),
            'mac' => hash('sha256', 'invalid-mac'),
        ]));
        DB::table('integraciones_usuario')->insert([
            'usuario_id' => $user['db_id'],
            'tipo' => 'core',
            'usuario_externo' => 'core-user',
            'valor_secreto' => $invalidSecret,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $repository = app(UserIntegrationRepository::class);
        $state = $repository->integrationForSession($user['session'], 'core');

        $this->assertFalse($state['stored']);
        $this->assertTrue($state['has_external_user']);
        $this->assertFalse($state['has_secret']);
        $this->assertFalse($repository->credentialForSession($user['session'], 'core')['stored']);
    }

    public function test_complete_core_credentials_are_reported_as_configured_and_can_be_reused(): void
    {
        $user = $this->makeNovaUser();
        $repository = app(UserIntegrationRepository::class);

        $this->assertTrue($repository->saveCredentialForSession(
            $user['session'],
            'core',
            'core-user',
            'core-password'
        ));

        $state = $repository->integrationForSession($user['session'], 'core');
        $credentials = $repository->credentialForSession($user['session'], 'core');

        $this->assertTrue($state['stored']);
        $this->assertTrue($state['has_external_user']);
        $this->assertTrue($state['has_secret']);
        $this->assertSame('core-user', $credentials['user']);
        $this->assertSame('core-password', $credentials['secret']);
        $this->assertTrue($credentials['stored']);
    }
}
