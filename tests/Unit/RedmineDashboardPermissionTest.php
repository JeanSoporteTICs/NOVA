<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use RedmineTic\Controllers\RedmineDashboardController;
use RedmineTic\Repositories\RedminePermissionRepository;
use ReflectionClass;

class RedmineDashboardPermissionTest extends TestCase
{
    public function test_global_permission_overrides_stale_explicit_denial(): void
    {
        $this->assertTrue($this->can([
            'all' => true,
            'historico' => false,
            'historico_acciones' => false,
        ], 'historico_acciones'));
    }

    public function test_explicit_action_denial_still_applies_without_global_permission(): void
    {
        $this->assertFalse($this->can([
            'historico' => true,
            'historico_acciones' => false,
        ], 'historico_acciones'));
    }

    public function test_user_can_have_statistics_without_report_access(): void
    {
        $permissions = [
            'mensajes_acceso' => false,
            'estadisticas' => true,
        ];

        $this->assertFalse($this->can($permissions, 'mensajes_acceso'));
        $this->assertTrue($this->can($permissions, 'estadisticas'));
    }

    public function test_only_nova_root_can_change_permission_scopes(): void
    {
        $requested = [
            'mensajes' => 'todos',
            'horas_extra' => 'todos',
            'historico_scope' => 'todos',
        ];
        $current = [
            'mensajes' => 'asignados',
            'horas_extra' => 'asignados',
            'historico_scope' => 'asignados',
        ];

        $this->assertSame($requested, $this->restrictedScopes($requested, $current, 'root'));
        $this->assertSame($current, $this->restrictedScopes($requested, $current, 'admin'));
    }

    public function test_non_root_can_disable_hours_extra_without_changing_its_scope(): void
    {
        $result = $this->restrictedScopes([
            'mensajes' => 'todos',
            'horas_extra' => '',
            'historico_scope' => 'todos',
        ], [
            'mensajes' => 'asignados',
            'horas_extra' => 'todos',
            'historico_scope' => 'asignados',
        ], 'usuario');

        $this->assertSame('', $result['horas_extra']);
        $this->assertSame('asignados', $result['mensajes']);
        $this->assertSame('asignados', $result['historico_scope']);
    }

    public function test_hours_extra_uses_only_the_edit_action_permission(): void
    {
        $payload = $this->permissionPayload(Request::create('/', 'POST', [
            'perm_horas_extra' => '1',
            'perm_horas_extra_editar' => '1',
            'perm_horas_extra_eliminar' => '1',
        ]));

        $this->assertTrue($payload['horas_extra_editar']);
        $this->assertArrayNotHasKey('horas_extra_eliminar', $payload);

        $roles = (new RedminePermissionRepository('redmine_tic', 'Backlog Soporte TI'))->defaultRoles();
        foreach ($roles as $permissions) {
            $this->assertArrayNotHasKey('horas_extra_eliminar', $permissions);
        }
    }

    /**
     * @param array<string,mixed> $permissions
     */
    private function can(array $permissions, string $permission): bool
    {
        $controller = (new ReflectionClass(RedmineDashboardController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RedmineDashboardController::class, 'can');

        return $method->invoke($controller, $permissions, $permission);
    }

    /**
     * @param array<string,mixed> $permissions
     * @param array<string,mixed> $currentPermissions
     * @return array<string,mixed>
     */
    private function restrictedScopes(array $permissions, array $currentPermissions, string $novaRole): array
    {
        $controller = (new ReflectionClass(RedmineDashboardController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RedmineDashboardController::class, 'preserveRestrictedScopes');

        return $method->invoke($controller, $permissions, $currentPermissions, $novaRole);
    }

    /**
     * @return array<string,mixed>
     */
    private function permissionPayload(Request $request): array
    {
        $controller = (new ReflectionClass(RedmineDashboardController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RedmineDashboardController::class, 'permissionPayload');

        return $method->invoke($controller, $request);
    }
}
