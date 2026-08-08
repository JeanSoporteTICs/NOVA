<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RedmineScrollTopVisibilityTest extends TestCase
{
    public function test_mantencion_shows_back_to_top_only_in_dashboard_history_and_extra_hours(): void
    {
        $root = dirname(__DIR__, 2);
        $dashboard = file_get_contents($root.'/resources/views/redmine-mantencion/dashboard.blade.php');
        $history = file_get_contents($root.'/resources/views/redmine-mantencion/historico.blade.php');
        $extraHours = file_get_contents($root.'/resources/views/redmine-mantencion/horas-extra.blade.php');
        $manual = file_get_contents($root.'/resources/views/redmine-mantencion/pendientes-manual.blade.php');
        $users = file_get_contents($root.'/resources/views/redmine-mantencion/usuarios.blade.php');

        self::assertIsString($dashboard);
        self::assertIsString($history);
        self::assertIsString($extraHours);
        self::assertIsString($manual);
        self::assertIsString($users);
        self::assertStringContainsString('id="dashboard-scroll-top"', $dashboard);
        self::assertStringContainsString('id="historico-scroll-top"', $history);
        self::assertStringContainsString('id="horas-extra-scroll-top"', $extraHours);
        self::assertStringNotContainsString('nova-scroll-top', $manual);
        self::assertStringNotContainsString('dashboard-scroll-top', $manual);
        self::assertStringNotContainsString('users-scroll-top', $users);
    }

    public function test_tic_limits_back_to_top_to_the_same_three_sections(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/RedmineTic/views/native.blade.php');

        self::assertIsString($view);
        self::assertStringContainsString("in_array(\$section, ['dashboard', 'historico', 'horas-extra'], true)", $view);
        self::assertStringNotContainsString("['dashboard', 'historico', 'usuarios']", $view);
        self::assertStringContainsString('id="redmine-tic-scroll-top"', $view);
    }

    public function test_partial_navigation_removes_stale_floating_buttons(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.js');

        self::assertIsString($script);
        self::assertStringContainsString('function refreshAfterPartialNavigation()', $script);
        self::assertStringContainsString("document.querySelectorAll('body > .nova-scroll-top').forEach(btn => btn.remove())", $script);
        self::assertStringContainsString("document.querySelectorAll('body > .dashboard-scroll-top').forEach(btn => btn.remove())", $script);
        self::assertStringContainsString("document.addEventListener('partial:loaded', refreshAfterPartialNavigation)", $script);
    }
}
