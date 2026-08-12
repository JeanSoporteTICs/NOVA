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

    public function test_live_redmine_status_is_persisted_and_reapplies_the_active_filter(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/RedmineMantencion/Controllers/HistoricoController.php');
        $repository = file_get_contents($root.'/RedmineMantencion/Repositories/MantencionReportRepository.php');
        $view = file_get_contents($root.'/resources/views/redmine-mantencion/historico.blade.php');

        self::assertIsString($controller);
        self::assertIsString($repository);
        self::assertIsString($view);
        self::assertStringContainsString("'changed_ids' => array_values(array_unique(\$changedIds))", $controller);
        self::assertStringContainsString('updateRedmineStatus($id, $remoteStatusId, $remoteStatusName)', $controller);
        self::assertStringContainsString('const activeRedmineStatusFilter =', $view);
        self::assertStringContainsString('if (activeRedmineStatusFilter && tableNeedsRefresh)', $view);
        self::assertStringContainsString('await refreshHistoricoTable(redmineStatusEndpoint);', $view);
        self::assertStringContainsString("statusName || (closed ? 'Cerrada' : 'Abierto')", $view);
        self::assertStringNotContainsString('const detail = available && !closed', $view);
        self::assertStringContainsString("->orWhere('estado_id', '!=', (string) \$statusId)", $repository);
        self::assertStringContainsString("->orWhere('estado_redmine', '!=', trim(\$statusName))", $repository);
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

    public function test_redmine_status_change_refreshes_only_the_history_table(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/RedmineMantencion/Controllers/HistoricoController.php');
        $navbar = file_get_contents($root.'/RedmineMantencion/views/partials/navbar.php');
        $css = file_get_contents($root.'/public/assets/nova-ui.css');
        $view = file_get_contents($root.'/resources/views/redmine-mantencion/historico.blade.php');

        self::assertIsString($controller);
        self::assertIsString($navbar);
        self::assertIsString($css);
        self::assertIsString($view);
        self::assertStringContainsString('if (request()->expectsJson())', $controller);
        self::assertStringContainsString("'updated_ids' => \$updatedIds", $controller);
        self::assertStringContainsString('request()->getRequestUri()', $controller);
        self::assertStringContainsString('? $currentRequestUri', $controller);
        self::assertStringContainsString('class="d-none js-redmine-status-form"', $view);
        self::assertStringContainsString('data-app-no-loading="1"', $view);
        self::assertStringContainsString("'Accept': 'application/json'", $view);
        self::assertStringContainsString("statusForm.getAttribute('action') || window.location.href", $view);
        self::assertStringContainsString('await refreshHistoricoTable(statusActionUrl);', $view);
        self::assertStringNotContainsString('fetch(statusForm.action', $view);
        self::assertStringContainsString('window.appUi?.setIntegrationLoading?.(true', $view);
        self::assertStringContainsString("title: selectedIds.length > 1 ? 'Actualizando estados en Redmine'", $view);
        self::assertStringContainsString("mediaSrc: <?= json_encode(\$mantencionBaseUrl.'/assets/img/redmine.gif'", $view);
        self::assertStringContainsString('2000 - (performance.now() - loadingStartedAt)', $view);
        self::assertStringNotContainsString('2 segundos', $view);
        self::assertStringContainsString('id="nova-integration-provider-media"', $navbar);
        self::assertStringContainsString('class="nova-integration-copy"', $navbar);
        self::assertStringContainsString("providerMedia.setAttribute('src', mediaSrc)", $navbar);
        self::assertStringContainsString("integrationCard?.classList.toggle('is-provider-layout'", $navbar);
        self::assertStringContainsString('.nova-integration-provider-media {', $css);
        self::assertStringContainsString('.nova-integration-overlay.has-provider-media .nova-integration-card,', $css);
        self::assertStringContainsString('.nova-integration-card.is-provider-layout {', $css);
        self::assertStringContainsString('grid-template-columns: 48px minmax(0, 1fr);', $css);
        self::assertStringContainsString('width: min(150px, 48vw);', $css);
        self::assertStringContainsString('window.appUi?.setIntegrationLoading?.(false);', $view);
        self::assertStringNotContainsString("currentLoader?.classList.remove('d-none')", $view);
        self::assertStringContainsString('currentCard.replaceWith(updatedCard);', $view);
        self::assertStringNotContainsString('window.location.reload()', $view);
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

    public function test_history_can_filter_by_redmine_status_and_preserve_it_in_navigation(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/RedmineMantencion/Controllers/HistoricoController.php');
        $view = file_get_contents($root.'/resources/views/redmine-mantencion/historico.blade.php');

        self::assertIsString($controller);
        self::assertIsString($view);
        self::assertStringContainsString("\$f_estado_redmine = trim((string) (\$_GET['estado_redmine'] ?? ''))", $controller);
        self::assertStringContainsString("'remove' => 'estado_redmine'", $controller);
        self::assertStringContainsString("'estado_redmine' => \$f_estado_redmine", $controller);
        self::assertStringContainsString('foreach ($redmineStatusOptions as $statusLabel)', $controller);
        self::assertStringNotContainsString('foreach ($items as $statusRow)', $controller);
        self::assertStringContainsString("'label' => 'Estado Redmine'", $view);
        self::assertStringContainsString('name="estado_redmine" value="<?= $h($f_estado_redmine) ?>"', $view);
    }

    public function test_mantencion_head_uses_the_current_nova_favicon(): void
    {
        $head = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/views/partials/bootstrap-head.php'
        );

        self::assertIsString($head);
        self::assertStringContainsString("asset('assets/logos/favicon-nova.svg')", $head);
        self::assertStringContainsString("base_path('public/assets/logos/favicon-nova.svg')", $head);
        self::assertStringContainsString('request()->getBasePath()', $head);
        self::assertStringContainsString("'/assets/logos/favicon-nova.svg'", $head);
        self::assertStringContainsString("'data:image/svg+xml;base64,'.base64_encode(\$novaFaviconSvg)", $head);
        self::assertStringContainsString('htmlspecialchars($novaFaviconDataUrl', $head);
        self::assertStringContainsString('sha1_file($novaUiPath)', $head);
        self::assertStringNotContainsString('RedmineMantencion/assets/favicon.svg', $head);
    }

    public function test_history_does_not_print_a_stray_php_closing_tag_before_html(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/historico.blade.php'
        );

        self::assertIsString($view);
        self::assertStringStartsWith('<!doctype html>', $view);
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
