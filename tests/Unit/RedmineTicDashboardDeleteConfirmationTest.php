<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RedmineTicDashboardDeleteConfirmationTest extends TestCase
{
    public function test_dashboard_deletions_use_the_shared_confirmation_modal(): void
    {
        $root = dirname(__DIR__, 2);
        $dashboard = file_get_contents($root.'/RedmineTic/views/native-sections/dashboard.blade.php');
        $layout = file_get_contents($root.'/RedmineTic/views/native.blade.php');

        self::assertIsString($dashboard);
        self::assertIsString($layout);
        self::assertStringContainsString('data-dashboard-delete-selected', $dashboard);
        self::assertStringContainsString('data-app-confirm-title="Eliminar solicitudes"', $dashboard);
        self::assertStringContainsString('data-app-confirm-title="Eliminar solicitud"', $dashboard);
        self::assertStringContainsString('dashboardDeleteSelected.disabled = selectedIds.length === 0 || locked;', $dashboard);
        self::assertStringContainsString('`¿Eliminar las ${selectedIds.length} solicitudes seleccionadas?', $dashboard);
        self::assertStringContainsString("submitter?.getAttribute('data-app-confirm')", $layout);
        self::assertStringContainsString('submitter?.dataset.appConfirmTitle', $layout);
        self::assertStringContainsString('pendingConfirmSubmitter = submitter;', $layout);
    }

    public function test_dashboard_table_displays_category_instead_of_request_type(): void
    {
        $dashboard = file_get_contents(
            dirname(__DIR__, 2).'/RedmineTic/views/native-sections/dashboard.blade.php'
        );

        self::assertIsString($dashboard);
        self::assertStringContainsString('<col class="rm-dashboard-col-category">', $dashboard);
        self::assertStringContainsString('<th>Categorías</th>', $dashboard);
        self::assertStringContainsString("<td>{{ \$report['categoria'] ?? '-' }}</td>", $dashboard);
        self::assertStringNotContainsString('<th>Tipo solicitud</th>', $dashboard);
    }

    public function test_dashboard_editor_uses_select2_and_defaults_to_the_active_session_user(): void
    {
        $root = dirname(__DIR__, 2);
        $dashboard = file_get_contents($root.'/RedmineTic/views/native-sections/dashboard.blade.php');
        $layout = file_get_contents($root.'/RedmineTic/views/native.blade.php');
        $styles = file_get_contents($root.'/public/assets/nova-ui.css');

        self::assertIsString($dashboard);
        self::assertIsString($layout);
        self::assertIsString($styles);
        self::assertSame(3, substr_count($dashboard, '<select class="form-select tic-dashboard-select2"'));
        self::assertStringContainsString("estado_usuario'] ?? \$user['estado'] ?? 'activo'", $dashboard);
        self::assertStringContainsString('const currentDashboardAssigneeId = @json($currentAssigneeId);', $dashboard);
        self::assertStringContainsString('const assigneeId = reportAssigneeId || currentDashboardAssigneeId;', $dashboard);
        self::assertStringContainsString('dropdownParent: modal', $dashboard);
        self::assertStringContainsString('select2@4.1.0-rc.0/dist/css/select2.min.css', $layout);
        self::assertStringContainsString('select2@4.1.0-rc.0/dist/js/select2.min.js', $layout);
        self::assertStringContainsString('#editar-solicitud .tic-dashboard-select2 + .select2-container', $styles);
        self::assertStringContainsString('font-family: var(--nova-font);', $styles);
        self::assertMatchesRegularExpression('/tic-dashboard-select2.*?select2-selection--single\s*\{[^}]*min-height:\s*44px;/s', $styles);
        self::assertMatchesRegularExpression('/tic-dashboard-select2.*?select2-selection__rendered\s*\{[^}]*font-weight:\s*700;/s', $styles);
        self::assertMatchesRegularExpression('/\.tic-select2-dropdown \.select2-results__option\s*\{[^}]*font-weight:\s*700;/s', $styles);
    }

    public function test_dashboard_editor_starts_with_subject_then_type_status_and_requesting_unit(): void
    {
        $dashboard = file_get_contents(
            dirname(__DIR__, 2).'/RedmineTic/views/native-sections/dashboard.blade.php'
        );

        self::assertIsString($dashboard);
        self::assertMatchesRegularExpression(
            '/col-12"><label[^>]*>Asunto<\/label>.*?name="asunto".*?col-12 col-md-3"><label[^>]*>Tipo<\/label>.*?name="tipo".*?col-12 col-md-3">\s*<label[^>]*>Estado<\/label>.*?name="estado".*?col-12 col-md-6">\s*<label[^>]*>Unidad Solicitante<\/label>.*?name="unidad_solicitante"/s',
            $dashboard
        );
        self::assertSame(1, substr_count($dashboard, 'name="unidad_solicitante"'));
    }

    public function test_dashboard_editor_finishes_with_the_requested_four_column_grid(): void
    {
        $dashboard = file_get_contents(
            dirname(__DIR__, 2).'/RedmineTic/views/native-sections/dashboard.blade.php'
        );

        self::assertIsString($dashboard);
        self::assertMatchesRegularExpression(
            '/col-12 col-md-6"><label[^>]*>Unidad<\/label>.*?name="unidad".*?col-12 col-md-3"><label[^>]*>Fecha Inicio<\/label>.*?name="fecha_inicio".*?col-12 col-md-3"><label[^>]*>Fecha Fin<\/label>.*?name="fecha_fin".*?col-12 col-md-3">\s*<label[^>]*>Hora extra<\/label>.*?name="hora_extra".*?col-12 col-md-3"><label[^>]*>Tiempo Estimado<\/label>.*?name="tiempo_estimado".*?col-12 col-md-3"><label[^>]*>Fecha<\/label>.*?name="fecha".*?col-12 col-md-3"><label[^>]*>Hora<\/label>.*?name="hora"/s',
            $dashboard
        );
    }
}
