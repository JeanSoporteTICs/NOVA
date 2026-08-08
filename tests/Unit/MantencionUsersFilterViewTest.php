<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MantencionUsersFilterViewTest extends TestCase
{
    public function test_active_users_are_the_progressive_default_before_javascript_runs(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/usuarios.blade.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString("\$uEstado === 'baneado' ? 'hidden' : ''", $view);
        self::assertStringContainsString('id="user-filter-count">Mostrando:', $view);
        self::assertStringContainsString('id="user-filter-empty"', $view);
    }

    public function test_status_and_search_filters_run_after_partial_navigation(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/redmine-mantencion/usuarios.blade.php');
        $navbar = file_get_contents($root.'/RedmineMantencion/views/partials/navbar.php');

        self::assertIsString($view);
        self::assertIsString($navbar);
        self::assertStringContainsString('<script data-partial-nav-script>', $view);
        self::assertStringContainsString('(() => {', $view);
        self::assertStringContainsString('tr.hidden = !visible;', $view);
        self::assertStringContainsString('userFilterEmpty.hidden = visibleUsers !== 0', $view);
        self::assertStringContainsString("filter(script => !script.hasAttribute('data-partial-nav-script'))", $navbar);
    }
}
