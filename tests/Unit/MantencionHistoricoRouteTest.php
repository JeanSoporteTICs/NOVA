<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MantencionHistoricoRouteTest extends TestCase
{
    public function test_redmine_status_ajax_uses_the_laravel_history_route(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/views/Historico/historico.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString('new URL(redmineStatusEndpoint, window.location.href)', $view);
        self::assertStringContainsString("searchParams.set('ajax', 'redmine_statuses')", $view);
        self::assertStringNotContainsString('fetch(`historico.php?ajax=redmine_statuses', $view);
    }
}
