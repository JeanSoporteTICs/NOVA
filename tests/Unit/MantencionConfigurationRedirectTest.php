<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MantencionConfigurationRedirectTest extends TestCase
{
    public function test_permission_redirects_use_the_laravel_route_generator(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Controllers/ConfiguracionController.php'
        );

        self::assertIsString($controller);
        self::assertStringNotContainsString("\$_SERVER['SCRIPT_NAME']", $controller);
        self::assertGreaterThanOrEqual(
            4,
            substr_count($controller, "route('redmine.mantencion.section'")
        );
        self::assertStringContainsString("'panel' => 'usuarios'", $controller);
        self::assertStringContainsString("'user_id' => \$selectedUser", $controller);
    }
}
