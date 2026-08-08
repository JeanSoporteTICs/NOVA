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

    public function test_history_actions_and_filters_preserve_navigation_state(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Controllers/HistoricoController.php'
        );
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/historico.blade.php'
        );

        self::assertIsString($controller);
        self::assertIsString($view);
        self::assertStringContainsString("'per_page' => \$perPage", $controller);
        self::assertStringContainsString("'page' => \$currentPage", $controller);
        self::assertStringContainsString('$historicoActionUrl = $historicoBaseUrl', $controller);
        self::assertStringContainsString('name="per_page" value="<?= $h($perPage) ?>"', $view);
        self::assertGreaterThanOrEqual(3, substr_count($view, 'action="<?= $h($historicoActionUrl) ?>"'));
    }

    public function test_mantencion_head_uses_the_current_nova_favicon(): void
    {
        $head = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/views/partials/bootstrap-head.php'
        );

        self::assertIsString($head);
        self::assertStringContainsString("asset('assets/logos/favicon-nova.svg')", $head);
        self::assertStringContainsString("base_path('public/assets/logos/favicon-nova.svg')", $head);
        self::assertStringNotContainsString("RedmineMantencion/assets/favicon.svg", $head);
    }

    public function test_history_date_keeps_the_full_value_on_one_line(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 2).'/public/assets/nova-ui.css'
        );

        self::assertIsString($css);
        self::assertStringContainsString('.historico-col-date { width: 8.5%; }', $css);
        self::assertMatchesRegularExpression(
            '/\.historico-date\s*\{[^}]*white-space:\s*nowrap;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.historico-date\s*>\s*i\s*\{[^}]*font-size:\s*\.82rem;/s',
            $css
        );
    }
}
