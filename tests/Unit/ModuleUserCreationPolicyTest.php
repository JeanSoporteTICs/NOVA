<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ModuleUserCreationPolicyTest extends TestCase
{
    public function test_mantencion_only_exposes_project_access_management(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/redmine-mantencion/usuarios.blade.php');
        $service = file_get_contents($root.'/RedmineMantencion/Services/MantencionUsuariosService.php');

        self::assertIsString($view);
        self::assertIsString($service);
        self::assertStringNotContainsString('id="createUserModal"', $view);
        self::assertStringNotContainsString('name="action" value="create"', $view);
        self::assertStringContainsString("if (\$action === 'create')", $service);
        self::assertStringContainsString('La creacion de usuarios se realiza unicamente desde NOVA.', $service);
    }

    public function test_tic_only_exposes_role_editing_for_existing_users(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/RedmineTic/views/native-sections/users.blade.php');
        $controller = file_get_contents($root.'/RedmineTic/Controllers/RedmineDashboardController.php');

        self::assertIsString($view);
        self::assertIsString($controller);
        self::assertStringNotContainsString('id="new-user-button"', $view);
        self::assertStringNotContainsString('name="_creating" value="1"', $view);
        self::assertStringContainsString('name="_creating" value="0"', $view);
        self::assertStringContainsString('Editar rol de proyecto', $view);
        self::assertStringContainsString("\$action === 'save' && \$request->boolean('_creating')", $controller);
        self::assertStringContainsString('La creacion de usuarios se realiza unicamente desde NOVA.', $controller);
    }

    public function test_nova_remains_the_central_user_creation_screen(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/Nova/views/nova/admin/index.blade.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString('<h2 data-user-form-title>Crear usuario</h2>', $view);
    }
}
