<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MantencionHistoricoRouteTest extends TestCase
{
    public function test_redmine_status_ajax_uses_the_laravel_history_route(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/historico.blade.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString('new URL(redmineStatusEndpoint, window.location.href)', $view);
        self::assertStringContainsString("searchParams.set('ajax', 'redmine_statuses')", $view);
        self::assertStringNotContainsString('fetch(`historico.php?ajax=redmine_statuses', $view);
    }

    public function test_bulk_status_change_uses_the_application_modal(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/historico.blade.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString('data-app-confirm-title="Cambiar estado en Redmine"', $view);
        self::assertStringContainsString('bulkStatusForm.dataset.appConfirm =', $view);
        self::assertStringContainsString('bulkStatusForm.requestSubmit();', $view);
        self::assertStringNotContainsString('window.confirm(`¿Cambiar ${ids.length}', $view);
    }
}
