<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MantencionCompleteNativeMigrationTest extends TestCase
{
    public function test_all_operational_mantencion_routes_are_native(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/web.php');
        $start = strpos($routes, "Route::get('/redmine-mantencion/health.php'");
        $end = strpos($routes, "Route::get('/redmine'", $start + 1);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $block = substr($routes, $start, $end - $start);

        $this->assertStringNotContainsString('LegacyProjectController', $block);
        $this->assertStringContainsString('MantencionDashboardController', $block);
        $this->assertStringContainsString('MantencionSectionController', $block);
        $this->assertStringContainsString('MantencionAdministrationController', $block);
        $this->assertStringContainsString('MantencionActivityController', $block);
        $this->assertStringContainsString('MantencionManualController', $block);
    }

    public function test_native_runtime_does_not_include_procedural_files_or_novalegacy(): void
    {
        $root = dirname(__DIR__, 2).'/RedmineMantencion';
        $files = array_merge(
            glob($root.'/Controllers/*.php') ?: [],
            glob($root.'/Services/*.php') ?: [],
            glob($root.'/views/native/*.blade.php') ?: [],
        );
        $source = implode("\n", array_map(static fn (string $file): string => (string) file_get_contents($file), $files));

        $this->assertStringNotContainsString('NOVALEGACY', $source);
        $this->assertDoesNotMatchRegularExpression('/require(?:_once)?\s*\(?[^;]*(?:controllers|views)\//i', $source);
        $this->assertStringNotContainsString('LegacyProjectController', $source);
        $this->assertDirectoryDoesNotExist($root.'/controllers');
        $this->assertDirectoryDoesNotExist($root.'/app');
        $this->assertFileDoesNotExist($root.'/index.php');
    }

    public function test_procedimientos_browser_uses_native_services(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2).'/Procedimientos/Controllers/ProcedimientosController.php');
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/procedimientos/index.blade.php');

        $this->assertStringContainsString('NextcloudBrowserService', $controller);
        $this->assertStringNotContainsString('require_once', $controller);
        $this->assertStringContainsString("@include('procedimientos.browser'", $view);
        $this->assertStringNotContainsString('nc_browser.php', $view);
    }

    public function test_native_administration_keeps_catalog_crud_and_encrypts_nextcloud_admin_secret(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/Controllers/MantencionAdministrationController.php');
        $provisioning = (string) file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/Services/MantencionNextcloudProvisioningService.php');
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/views/native/config.blade.php');

        $this->assertStringContainsString("['catalog_save', 'catalog_delete']", $controller);
        $this->assertStringContainsString('SecretValue::encryptSecret($secret)', $controller);
        $this->assertStringContainsString("['nextcloud_admin_pass_enc']", $controller);
        $this->assertStringContainsString('SecretValue::decryptSecret', $provisioning);
        $this->assertStringContainsString('name="action" value="catalog_save"', $view);
        $this->assertStringContainsString('name="action" value="option_update"', $view);
    }

    public function test_mixed_send_warning_is_emitted_after_progress_completion(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/views/native/dashboard.blade.php');
        $progress = strpos($view, 'await finishProgress(Boolean(data.ok))');
        $warning = strpos($view, 'Mixed-send CORE warnings intentionally appear only here');
        $toast = strpos($view, 'toast(data.message', $warning);

        $this->assertNotFalse($progress);
        $this->assertNotFalse($warning);
        $this->assertNotFalse($toast);
        $this->assertGreaterThan($progress, $warning);
        $this->assertGreaterThan($warning, $toast);
    }

    public function test_dashboard_restores_animated_media_and_continuous_progress_for_core_and_redmine(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/views/native/dashboard.blade.php');

        $this->assertStringContainsString('assets/img/animacion-carga.gif', $view);
        $this->assertStringContainsString('assets/img/redmine.gif', $view);
        $this->assertStringContainsString('id="dashboard-progress-gif"', $view);
        $this->assertStringContainsString('showProgress(\'core\')', $view);
        $this->assertStringContainsString('showProgress(\'redmine\')', $view);
        $this->assertStringContainsString('progressTimer = window.setInterval', $view);
        $this->assertStringContainsString('integrationProgress.style.width = \'100%\'', $view);
        $this->assertStringNotContainsString('requestAnimationFrame(() => progress.style.width = \'82%\')', $view);
    }

    public function test_dashboard_restores_source_indicators_blank_redmine_ids_and_local_status_icons(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/views/native/dashboard.blade.php');

        $this->assertStringContainsString('dashboard-select-control', $view);
        $this->assertStringContainsString("'gestionada' => ['label' => 'Gestionada', 'icon' => 'bi-check-circle-fill', 'badge' => 'success']", $view);
        $this->assertStringContainsString("'en revision' => ['label' => 'En Revisión', 'icon' => 'bi-hourglass-split', 'badge' => 'warning']", $view);
        $this->assertStringContainsString('dashboard-source-indicator is-manual', $view);
        $this->assertStringContainsString('bi bi-pencil-fill', $view);
        $this->assertStringContainsString("<td>{{ \$message['redmine_id'] ?? '' }}</td>", $view);
        $this->assertStringContainsString("'pendiente' => ['pending', 'bi-hourglass-split']", $view);
        $this->assertStringContainsString("'procesado' => ['processed', 'bi-check2']", $view);
        $this->assertStringNotContainsString('dashboard-status-icon--{{ $status }}', $view);
    }

    public function test_dashboard_actions_use_a_compact_aligned_visual_rail(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root.'/RedmineMantencion/views/native/dashboard.blade.php');
        $styles = (string) file_get_contents($root.'/public/assets/nova-ui.css');

        $this->assertStringContainsString('dashboard-action dashboard-action--edit', $view);
        $this->assertStringContainsString('dashboard-action dashboard-action--hours', $view);
        $this->assertStringContainsString('dashboard-action dashboard-action--delete', $view);
        $this->assertStringContainsString('.nova-mantencion-page .dashboard-table .dashboard-row-actions', $styles);
        $this->assertStringContainsString('width: 34px !important;', $styles);
        $this->assertStringContainsString('height: 34px !important;', $styles);
        $this->assertStringContainsString('justify-content: center !important;', $styles);
        $this->assertStringContainsString('width: 136px !important;', $styles);
        $this->assertStringContainsString('background: linear-gradient(135deg, #0891b2, #0e7490) !important;', $styles);
        $this->assertStringContainsString('background: linear-gradient(135deg, #ef4444, #dc2626) !important;', $styles);
    }

    public function test_dashboard_bulk_commands_and_edit_drawer_share_the_restored_button_style(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root.'/RedmineMantencion/views/native/dashboard.blade.php');
        $styles = (string) file_get_contents($root.'/public/assets/nova-ui.css');

        $this->assertStringContainsString('btn-nova btn-nova-success btn-icon dashboard-command', $view);
        $this->assertStringContainsString('btn-nova btn-nova-danger btn-icon dashboard-command', $view);
        $this->assertStringNotContainsString('Solicitudes activas', $view);
        $this->assertStringNotContainsString('dashboard-active-chips', $view);
        $this->assertStringNotContainsString('dashboard-compact-toggle', $view);
        $this->assertStringContainsString('data-bulk-action="delete_selected" data-status-action="pendiente procesado"', $view);
        $this->assertStringContainsString('visibleStatuses.includes(statusFilter)', $view);
        $this->assertStringContainsString('modal fade detail-drawer-modal dashboard-edit-drawer', $view);
        $this->assertStringContainsString('modal-dialog detail-drawer-dialog modal-dialog-scrollable', $view);
        $this->assertStringContainsString('btn-nova btn-nova-secondary btn-icon dashboard-command', $view);
        $this->assertStringContainsString('btn-nova btn-nova-primary btn-icon dashboard-command', $view);
        $this->assertStringContainsString('.dashboard-toolbar__button-group .dashboard-command', $styles);
        $this->assertStringContainsString('.dashboard-toolbar__button-group .dashboard-command.d-none', $styles);
        $this->assertStringContainsString('display: none !important;', $styles);
        $this->assertStringContainsString('.dashboard-edit-drawer .dashboard-command', $styles);
        $this->assertStringContainsString('min-height: 42px !important;', $styles);
        $this->assertStringContainsString('border-radius: 10px !important;', $styles);
    }

    public function test_every_native_section_has_a_blade_view(): void
    {
        $views = ['layout', 'dashboard', 'manual', 'history', 'hours', 'stats', 'users', 'config', 'activity', 'nextcloud-history', 'integrations'];
        foreach ($views as $view) {
            $this->assertFileExists(dirname(__DIR__, 2).'/RedmineMantencion/views/native/'.$view.'.blade.php');
        }
    }

    public function test_native_asset_path_pattern_accepts_nested_stylesheets(): void
    {
        $pattern = '~^[A-Za-z0-9_./-]+$~';

        $this->assertSame(1, preg_match($pattern, 'theme.css'));
        $this->assertSame(1, preg_match($pattern, 'css/pendiente-manual.css'));
        $this->assertSame(0, preg_match($pattern, 'css/theme.css?invalid'));

        $controller = (string) file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Controllers/MantencionAssetController.php'
        );
        $this->assertStringContainsString("preg_match('~^[A-Za-z0-9_./-]+$~', \$path)", $controller);
        $this->assertStringContainsString("'css' => 'text/css; charset=UTF-8'", $controller);
        $this->assertStringContainsString("'js' => 'application/javascript; charset=UTF-8'", $controller);
        $this->assertStringContainsString("'Content-Type' => \$contentTypes[\$extension]", $controller);
    }

    public function test_all_native_views_restore_the_original_mantencion_visual_components(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root.'/RedmineMantencion/views/native/layout.blade.php');
        $dashboard = (string) file_get_contents($root.'/RedmineMantencion/views/native/dashboard.blade.php');
        $views = [];
        foreach (['manual', 'hours', 'history', 'users', 'config', 'stats', 'nextcloud-history', 'activity', 'integrations'] as $name) {
            $views[$name] = (string) file_get_contents($root.'/RedmineMantencion/views/native/'.$name.'.blade.php');
        }
        $integrations = (string) file_get_contents($root.'/RedmineMantencion/views/native/integrations.blade.php');
        $styles = (string) file_get_contents($root.'/public/assets/nova-ui.css');

        $this->assertStringContainsString('nova-mantencion-page', $layout);
        $this->assertStringContainsString('nova-sidebar-group', $layout);
        $this->assertStringContainsString("'label' => 'Integraciones'", $layout);
        $this->assertStringContainsString('dashboard-shell', $dashboard);
        $this->assertStringContainsString('dashboard-panel', $dashboard);
        $this->assertStringContainsString('dashboard-stats', $dashboard);
        $this->assertStringContainsString('dashboard-table-card', $dashboard);
        $this->assertStringContainsString('assets/css/dashboard.css', $dashboard);
        $this->assertStringContainsString('assets/css/pendiente-manual.css', $views['manual']);
        $this->assertStringContainsString('he-workspace', $views['hours']);
        $this->assertStringContainsString('he-action-copy', $views['hours']);
        $this->assertStringContainsString('he-action-edit', $views['hours']);
        $this->assertStringContainsString('he-action-remove', $views['hours']);
        $this->assertStringContainsString('window.ClipboardItem', $views['hours']);
        $this->assertStringContainsString("document.execCommand('copy')", $views['hours']);
        $this->assertStringContainsString('assets/css/historico.css', $views['history']);
        $this->assertStringContainsString('assets/css/usuarios.css', $views['users']);
        $this->assertStringContainsString('assets/css/configuracion.css', $views['config']);
        $this->assertStringContainsString('assets/css/estadisticas.css', $views['stats']);
        $this->assertStringContainsString('assets/css/nextcloud-usuarios.css', $views['nextcloud-history']);
        $this->assertStringContainsString('assets/css/nextcloud-historial.css', $views['nextcloud-history']);
        foreach ($views as $view) {
            $this->assertStringContainsString('redmine_mantencion::native.partials.hero', $view);
        }
        $this->assertStringContainsString('rm-config-shell', $views['config']);
        $this->assertStringContainsString('nova-user-summary-grid', $views['users']);
        $this->assertStringContainsString('historico-table-card', $views['history']);
        $this->assertStringContainsString('chart-card', $views['stats']);
        $this->assertStringContainsString('nextcloud-panel', $views['nextcloud-history']);
        $this->assertStringContainsString('integration-grid', $integrations);
        $this->assertStringNotContainsString('nova-summary', $dashboard);
        $this->assertStringContainsString('container-fluid py-4', $integrations);
        $this->assertStringNotContainsString('body.nova-mantencion-page .sb-native-navbar', $styles);
        $this->assertStringContainsString('.nova-system-card:not(.p-0)', $styles);
    }
}
