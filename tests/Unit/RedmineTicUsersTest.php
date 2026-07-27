<?php

namespace Tests\Unit;

use App\Contracts\ProjectUserProviderInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RedmineTic\Repositories\RedmineDataRepository;
use RedmineTic\Repositories\RedmineUserRepository;
use RedmineTic\Services\RedmineProjectUserProvider;
use Tests\TestCase;

/**
 * Covers ETAPA B / Lote B3 — confirms the "local persistence" half of the
 * Usuarios family (already fully delegated to RedmineUserRepository before
 * this lote) behaves identically through the RedmineDataRepository facade,
 * and that ProjectUserProviderInterface's contract is intact. Runs against
 * the real usuarios_nova/redmine_tic_perfiles_usuario/permisos_usuario_modulo/
 * integraciones_usuario/redmine_tic_permisos_usuario tables inside a
 * rolled-back transaction.
 *
 * Test users use a numeric 'id' (like a real Redmine user id) because
 * RedmineUserRepository::upsertNovaUserFromProjectUser() only creates a new
 * usuarios_nova row when the incoming id is ctype_digit — a non-numeric id
 * is treated as an existing NOVA uuid lookup and silently no-ops if absent.
 */
class RedmineTicUsersTest extends TestCase
{
    use DatabaseTransactions;

    private function facade(): RedmineDataRepository
    {
        return (new RedmineDataRepository())->forProject('redmine_tic');
    }

    private function userRepo(): RedmineUserRepository
    {
        return new RedmineUserRepository('redmine_tic', 'Backlog Soporte TI');
    }

    private function findUser(array $users, string $id): ?array
    {
        foreach ($users as $user) {
            if ((string) ($user['id'] ?? '') === $id) {
                return $user;
            }
        }

        return null;
    }

    private function newRedmineId(): string
    {
        return (string) random_int(90000000, 99999999);
    }

    public function test_facade_users_matches_user_repository_directly(): void
    {
        $viaFacade = $this->facade()->users();
        $viaRepo = $this->userRepo()->projectUsers();

        $this->assertSame($viaRepo, $viaFacade);
    }

    public function test_creating_a_new_user_via_save_user(): void
    {
        $id = $this->newRedmineId();
        $facade = $this->facade();

        $result = $facade->saveUser([
            '_creating' => true,
            'id' => $id,
            'rut_sin_dv' => 'b3user' . $id,
            'nombre' => 'Usuario',
            'apellido' => 'PruebaB3',
            'rol' => 'usuario',
            'api' => 'token-plano-b3',
        ]);

        $this->assertTrue($result['ok']);

        $created = $this->findUser($facade->users(), $id);
        $this->assertNotNull($created);
        $this->assertSame('Usuario', $created['nombre']);
        $this->assertSame('PruebaB3', $created['apellido']);
        $this->assertSame('usuario', $created['rol']);
        $this->assertSame('activo', $created['estado_usuario']);
        // The deprecated 'email' field must never be (re)introduced by this flow.
        $this->assertSame('', $created['email']);

        // Real rows, not just the in-memory shape.
        $novaId = DB::table('usuarios_nova')->where('redmine_id', $id)->value('id');
        $this->assertNotNull($novaId);
        $this->assertTrue(DB::table('permisos_usuario_modulo')->where('usuario_id', $novaId)->exists());
        $this->assertTrue(DB::table('redmine_tic_perfiles_usuario')->where('usuario_id', $novaId)->exists());

        // The API token round-trips (stored encrypted, returned decrypted).
        $this->assertSame('token-plano-b3', $created['api']);
        $storedSecret = DB::table('integraciones_usuario')
            ->where('usuario_id', $novaId)->where('tipo', 'redmine_tic')->value('valor_secreto');
        $this->assertNotSame('token-plano-b3', $storedSecret);
    }

    public function test_creating_the_same_user_twice_is_rejected_not_duplicated(): void
    {
        $id = $this->newRedmineId();
        $facade = $this->facade();

        $facade->saveUser(['_creating' => true, 'id' => $id, 'rut_sin_dv' => 'b3dup' . $id, 'nombre' => 'Uno', 'apellido' => 'B3']);
        $result = $facade->saveUser(['_creating' => true, 'id' => $id, 'rut_sin_dv' => 'b3dup' . $id, 'nombre' => 'Dos', 'apellido' => 'B3']);

        $this->assertFalse($result['ok']);
        $this->assertSame('El ID ya esta asociado a otro usuario.', $result['error']);

        $matches = array_filter($facade->users(), fn (array $u) => (string) ($u['id'] ?? '') === $id);
        $this->assertCount(1, $matches);
        $this->assertSame('Uno', reset($matches)['nombre']);
    }

    public function test_updating_an_existing_user_changes_role_and_permissions(): void
    {
        $id = $this->newRedmineId();
        $facade = $this->facade();
        $facade->saveUser(['_creating' => true, 'id' => $id, 'rut_sin_dv' => 'b3edit' . $id, 'nombre' => 'Editable', 'apellido' => 'B3', 'rol' => 'usuario']);

        $result = $facade->saveUser(['id' => $id, 'rol' => 'gestor']);

        $this->assertTrue($result['ok']);
        $edited = $this->findUser($facade->users(), $id);
        $this->assertSame('gestor', $edited['rol']);
        // Changing roles applies the canonical relational role permissions.
        // Gestor may view statistics but cannot administer users.
        $this->assertTrue($edited['permisos']['estadisticas']);
        $this->assertFalse($edited['permisos']['usuarios']);
    }

    public function test_toggle_user_status_flips_and_reverts(): void
    {
        $id = $this->newRedmineId();
        $facade = $this->facade();
        $facade->saveUser(['_creating' => true, 'id' => $id, 'rut_sin_dv' => 'b3toggle' . $id, 'nombre' => 'Toggle', 'apellido' => 'B3']);

        $first = $facade->toggleUserStatus($id);
        $this->assertTrue($first['ok']);
        $this->assertSame('baneado', $first['nuevo_estado']);
        $this->assertSame('baneado', $this->findUser($facade->users(), $id)['estado_usuario']);

        $second = $facade->toggleUserStatus($id);
        $this->assertTrue($second['ok']);
        $this->assertSame('activo', $second['nuevo_estado']);
        $this->assertSame('activo', $this->findUser($facade->users(), $id)['estado_usuario']);
    }

    public function test_save_user_permissions_updates_relational_table_and_facade_read(): void
    {
        $id = $this->newRedmineId();
        $facade = $this->facade();
        $facade->saveUser(['_creating' => true, 'id' => $id, 'rut_sin_dv' => 'b3perms' . $id, 'nombre' => 'Permisos', 'apellido' => 'B3']);

        $ok = $facade->saveUserPermissions($id, 'usuario', ['estadisticas' => true, 'usuarios' => false]);

        $this->assertTrue($ok);
        $user = $this->findUser($facade->users(), $id);
        $this->assertTrue($user['permisos']['estadisticas']);
        $this->assertFalse($user['permisos']['usuarios']);

        $novaId = DB::table('usuarios_nova')->where('redmine_id', $id)->value('id');
        $perfilId = DB::table('redmine_tic_perfiles_usuario')->where('usuario_id', $novaId)->value('id');
        $storedValue = DB::table('redmine_tic_permisos_usuario')
            ->where('perfil_id', $perfilId)->where('clave', 'estadisticas')->value('valor');
        $this->assertSame('si', $storedValue);
    }

    public function test_deleting_a_user_revokes_project_access(): void
    {
        $id = $this->newRedmineId();
        $facade = $this->facade();
        $facade->saveUser(['_creating' => true, 'id' => $id, 'rut_sin_dv' => 'b3del' . $id, 'nombre' => 'Borrar', 'apellido' => 'B3']);
        $novaId = DB::table('usuarios_nova')->where('redmine_id', $id)->value('id');
        $this->assertTrue(DB::table('permisos_usuario_modulo')->where('usuario_id', $novaId)->exists());

        $deleted = $facade->deleteUser($id);

        $this->assertSame(1, $deleted);
        $this->assertFalse(DB::table('permisos_usuario_modulo')->where('usuario_id', $novaId)->exists());
        $this->assertNull($this->findUser($facade->users(), $id));

        // The usuarios_nova row itself is not physically removed — only project access is revoked.
        $this->assertNotNull(DB::table('usuarios_nova')->where('id', $novaId)->value('id'));
    }

    public function test_timestamps_are_set_on_create(): void
    {
        $id = $this->newRedmineId();
        $facade = $this->facade();
        $facade->saveUser(['_creating' => true, 'id' => $id, 'rut_sin_dv' => 'b3ts' . $id, 'nombre' => 'Fechas', 'apellido' => 'B3']);

        $novaId = DB::table('usuarios_nova')->where('redmine_id', $id)->value('id');
        $this->assertNotNull($novaId);
        $profile = DB::table('redmine_tic_perfiles_usuario')->where('usuario_id', $novaId)->first();

        $this->assertNotNull($profile);
        $this->assertNotNull($profile->actualizado_at);
        $this->assertNotNull(DB::table('usuarios_nova')->where('id', $novaId)->value('creado_at'));
    }

    public function test_import_reconciles_a_changed_redmine_id_without_creating_a_second_identity(): void
    {
        $oldId = $this->newRedmineId();
        $newId = $this->newRedmineId();
        $username = 'b3reconcile' . $oldId;
        $facade = $this->facade();
        $facade->saveUser([
            '_creating' => true,
            'id' => $oldId,
            'rut_sin_dv' => $username,
            'nombre' => 'Cambio',
            'apellido' => 'Identidad',
        ]);

        $nova = DB::table('usuarios_nova')->where('redmine_id', $oldId)->first();
        $this->assertNotNull($nova);
        DB::table('integraciones_usuario')->updateOrInsert(
            ['usuario_id' => $nova->id, 'tipo' => 'redmine_mantencion'],
            ['usuario_externo' => $oldId, 'actualizado_at' => now()]
        );

        $this->userRepo()->persistUsers([[
            'id' => $newId,
            'redmine_id' => $newId,
            '_nova_user_id' => $nova->uuid,
            'rut_sin_dv' => $username,
            'nombre' => 'Cambio',
            'apellido' => 'Identidad',
            'rol' => 'usuario',
        ]], true, 'baneado');

        $this->assertSame(1, DB::table('usuarios_nova')->where('usuario', $username)->count());
        $this->assertFalse(DB::table('usuarios_nova')->where('redmine_id', $oldId)->exists());
        $this->assertSame($newId, (string) DB::table('usuarios_nova')->where('id', $nova->id)->value('redmine_id'));
        $this->assertSame(
            [$newId, $newId],
            DB::table('integraciones_usuario')
                ->where('usuario_id', $nova->id)
                ->whereIn('tipo', ['redmine_tic', 'redmine_mantencion'])
                ->orderBy('tipo')
                ->pluck('usuario_externo')
                ->map(static fn ($value): string => (string) $value)
                ->all()
        );
    }

    public function test_active_users_with_phone_matches_repository(): void
    {
        $viaFacade = $this->facade()->activeUsersWithPhone();
        $viaRepo = $this->userRepo()->activeUsersWithPhone();

        $this->assertSame($viaRepo, $viaFacade);
    }

    public function test_project_user_provider_matches_facade_for_redmine_tic(): void
    {
        $provider = new RedmineProjectUserProvider(new RedmineDataRepository());
        $this->assertInstanceOf(ProjectUserProviderInterface::class, $provider);

        $viaProvider = $provider->projectUsers('redmine_tic');
        $viaFacade = $this->facade()->users();

        $this->assertSame($viaFacade, $viaProvider);
    }

    public function test_project_user_provider_returns_empty_for_unsupported_project(): void
    {
        $provider = new RedmineProjectUserProvider(new RedmineDataRepository());

        $this->assertSame([], $provider->projectUsers('some_other_module'));
    }
}
