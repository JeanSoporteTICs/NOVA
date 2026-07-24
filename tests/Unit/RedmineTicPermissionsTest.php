<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RedmineTic\Repositories\RedmineDataRepository;
use RedmineTic\Repositories\RedminePermissionRepository;
use Tests\TestCase;

/**
 * Covers the ETAPA B / Lote B2 delegation of RedmineDataRepository::roles()
 * onto the newly-added RedminePermissionRepository::roles(), plus the
 * already-delegated saveRolePermissions()/deleteRole() dual-write (relational
 * + JSON legacy) contract. Runs against the real
 * redmine_tic_permisos_rol/configuraciones_modulo tables for the
 * 'redmine_tic' module inside a rolled-back transaction.
 */
class RedmineTicPermissionsTest extends TestCase
{
    use DatabaseTransactions;

    private function facade(): RedmineDataRepository
    {
        return (new RedmineDataRepository())->forProject('redmine_tic');
    }

    private function permissionRepo(): RedminePermissionRepository
    {
        return new RedminePermissionRepository('redmine_tic', 'Backlog Soporte TI');
    }

    public function test_facade_roles_matches_permission_repository_directly(): void
    {
        $viaFacade = $this->facade()->roles();
        $viaRepo = $this->permissionRepo()->roles();

        $this->assertSame($viaRepo, $viaFacade);
    }

    public function test_roles_falls_back_to_defaults_when_relational_and_json_are_empty(): void
    {
        $repo = new RedminePermissionRepository('redmine_tic_b2_empty_test', 'B2 Empty Test');

        $roles = $repo->roles();

        $this->assertSame($repo->defaultRoles(), $roles);
        $this->assertArrayHasKey('root', $roles);
        $this->assertArrayHasKey('usuario', $roles);
    }

    public function test_creating_a_new_role_via_save_role_permissions(): void
    {
        $facade = $this->facade();

        $ok = $facade->saveRolePermissions('rol_prueba_b2', ['usuarios' => true, 'estadisticas' => false]);

        $this->assertTrue($ok);
        $roles = $facade->roles();
        $this->assertArrayHasKey('rol_prueba_b2', $roles);
        $this->assertTrue($roles['rol_prueba_b2']['usuarios']);
        $this->assertFalse($roles['rol_prueba_b2']['estadisticas']);
    }

    public function test_editing_an_existing_role(): void
    {
        $facade = $this->facade();
        $facade->saveRolePermissions('rol_prueba_b2_edit', ['usuarios' => true, 'estadisticas' => false]);

        $facade->saveRolePermissions('rol_prueba_b2_edit', ['usuarios' => false, 'estadisticas' => true]);

        $role = $facade->roles()['rol_prueba_b2_edit'];
        $this->assertFalse($role['usuarios']);
        $this->assertTrue($role['estadisticas']);
    }

    public function test_deleting_a_role_removes_it_from_relational_and_json(): void
    {
        $facade = $this->facade();
        $facade->saveRolePermissions('rol_prueba_b2_delete', ['usuarios' => true]);
        $this->assertArrayHasKey('rol_prueba_b2_delete', $facade->roles());

        $result = $facade->deleteRole('rol_prueba_b2_delete');
        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('rol_prueba_b2_delete', $facade->roles());

        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');

        $relationalCount = DB::table('redmine_tic_permisos_rol')
            ->where('modulo_id', $moduleId)
            ->where('rol', 'rol_prueba_b2_delete')
            ->count();
        $this->assertSame(0, $relationalCount);

        $jsonRaw = DB::table('configuraciones_modulo')
            ->where('modulo_id', $moduleId)
            ->where('clave', 'roles')
            ->value('valor');
        $json = json_decode((string) $jsonRaw, true);
        $this->assertArrayNotHasKey('rol_prueba_b2_delete', (array) $json);
    }

    public function test_deleting_a_reserved_role_is_rejected(): void
    {
        $result = $this->facade()->deleteRole('root');
        $this->assertFalse($result['ok']);
    }

    public function test_cannot_delete_role_assigned_to_a_user(): void
    {
        $users = $this->facade()->users();
        if ($users === []) {
            $this->markTestSkipped('No hay usuarios TIC para probar esta regla.');
        }
        $assignedRole = (string) ($users[0]['rol'] ?? '');
        if ($assignedRole === '' || in_array($assignedRole, ['root', 'administrador', 'gestor', 'usuario'], true)) {
            $this->markTestSkipped('El primer usuario no tiene un rol custom no reservado para probar.');
        }

        $result = $this->facade()->deleteRole($assignedRole);
        $this->assertFalse($result['ok']);
    }

    public function test_save_role_permissions_writes_relational_only_dual_write_needs_delete_role(): void
    {
        // saveRolePermissions() only writes the relational table by design —
        // the JSON blob is only refreshed via saveRolesToDatabase(), called
        // by deleteRole(). B2 must not change this split.
        $facade = $this->facade();
        $facade->saveRolePermissions('rol_prueba_b2_json', ['usuarios' => true]);

        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        $this->assertTrue(
            DB::table('redmine_tic_permisos_rol')->where('modulo_id', $moduleId)->where('rol', 'rol_prueba_b2_json')->exists()
        );

        $facade->deleteRole('rol_prueba_b2_json');

        $jsonRaw = DB::table('configuraciones_modulo')->where('modulo_id', $moduleId)->where('clave', 'roles')->value('valor');
        $json = json_decode((string) $jsonRaw, true);
        $this->assertArrayNotHasKey('rol_prueba_b2_json', (array) $json);
    }

    public function test_reflection_based_permission_bridges_still_exist(): void
    {
        // Phase3aPermissionsTest reaches these two private facade methods via
        // reflection — B2 deliberately keeps them (see RedminePermissionRepository
        // class docblock) instead of removing them like the other dead bridges.
        $ref = new \ReflectionClass(RedmineDataRepository::class);
        $this->assertTrue($ref->hasMethod('allPermissionsFromRelational'));
        $this->assertTrue($ref->hasMethod('savePermissionsToRelational'));
    }
}
