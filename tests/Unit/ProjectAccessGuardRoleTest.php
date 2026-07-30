<?php

namespace Tests\Unit;

use App\Modulos\Nova\Services\ProjectAccessGuard;
use App\Modulos\Nova\Services\NovaUserService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class ProjectAccessGuardRoleTest extends TestCase
{
    public function test_nova_keeps_root_as_a_distinct_global_role(): void
    {
        $service = (new ReflectionClass(NovaUserService::class))->newInstanceWithoutConstructor();

        $this->assertSame('root', $service->normalizeNovaRole('root'));
        $this->assertSame('admin', $service->normalizeNovaRole('admin'));
        $this->assertSame('usuario', $service->normalizeNovaRole('gestor'));
    }

    public function test_admin_uses_module_role_without_global_all_permission(): void
    {
        $projectUser = $this->sessionProjectUser('redmine_tic', [
            'id' => 'nova-admin',
            'redmine_id' => '42',
            'role' => 'admin',
        ]);

        $this->assertSame('administrador', $projectUser['rol']);
        $this->assertArrayNotHasKey('all', $projectUser['permisos']);
    }

    public function test_root_is_the_only_global_module_superuser(): void
    {
        $projectUser = $this->sessionProjectUser('redmine-mantencion', [
            'id' => 'nova-root',
            'redmine_id' => '99',
            'role' => 'root',
        ]);

        $this->assertSame('root', $projectUser['rol']);
        $this->assertTrue($projectUser['permisos']['all']);
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function sessionProjectUser(string $projectKey, array $user): array
    {
        $guard = (new ReflectionClass(ProjectAccessGuard::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ProjectAccessGuard::class, 'sessionProjectUser');

        return $method->invoke($guard, $projectKey, $user);
    }
}
