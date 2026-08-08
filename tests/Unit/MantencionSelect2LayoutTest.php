<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MantencionSelect2LayoutTest extends TestCase
{
    public function test_mantencion_shared_layout_loads_select2_for_full_and_partial_navigation(): void
    {
        $root = dirname(__DIR__, 2);
        $head = file_get_contents($root.'/RedmineMantencion/views/partials/bootstrap-head.php');
        $scripts = file_get_contents($root.'/RedmineMantencion/views/partials/bootstrap-scripts.php');
        $dashboard = file_get_contents($root.'/resources/views/redmine-mantencion/dashboard.blade.php');
        $manual = file_get_contents($root.'/resources/views/redmine-mantencion/pendientes-manual.blade.php');

        self::assertIsString($head);
        self::assertIsString($scripts);
        self::assertStringContainsString('select2.min.css', $head);
        self::assertStringContainsString('jquery.min.js', $scripts);
        self::assertStringContainsString('select2.min.js', $scripts);

        self::assertIsString($dashboard);
        self::assertIsString($manual);
        self::assertStringContainsString('<script data-partial-nav-script>', $manual);
    }

    public function test_dashboard_category_and_assignee_use_the_tic_select2_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/redmine-mantencion/dashboard.blade.php');
        $css = file_get_contents($root.'/public/assets/nova-ui.css');

        self::assertIsString($view);
        self::assertMatchesRegularExpression('/<select name="categoria" id="md-categoria"[^>]*mantencion-select2[^>]*data-mantencion-select2/', $view);
        self::assertMatchesRegularExpression('/<select name="asignado_a" id="md-asignado"[^>]*mantencion-select2[^>]*data-mantencion-select2/', $view);
        self::assertStringContainsString("dropdownCssClass: 'tic-select2-dropdown'", $view);
        self::assertStringContainsString('dropdownParent: modal', $view);
        self::assertStringContainsString('setMantencionSelectValue(', $view);

        self::assertIsString($css);
        self::assertStringContainsString('.mantencion-select2 + .select2-container', $css);
        self::assertStringContainsString('.mantencion-select2 + .select2-container .select2-selection__rendered', $css);
    }

    public function test_dashboard_edit_fields_follow_the_requested_row_order(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/redmine-mantencion/dashboard.blade.php');
        self::assertIsString($view);

        $start = strpos($view, '<div class="detail-drawer-view is-active" id="drawer-detail-view">');
        $end = strpos($view, '<div class="col-12 d-none" id="md-descripcion-wrap">', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $form = substr($view, $start, $end - $start);

        $orderedIds = [
            'md-asunto',
            'md-tipo', 'md-estado', 'md-prioridad',
            'md-categoria', 'md-asignado',
            'md-solicitante', 'md-numero',
            'md-core_email', 'md-establecimiento',
            'md-departamento',
            'md-fecha_inicio', 'md-fecha_fin', 'md-fecha', 'md-hora',
            'md-hora_extra', 'md-tiempo_estimado',
        ];

        $previousPosition = -1;
        foreach ($orderedIds as $id) {
            $position = strpos($form, 'id="'.$id.'"');
            self::assertNotFalse($position, $id);
            self::assertGreaterThan($previousPosition, $position, $id.' is out of order.');
            $previousPosition = $position;
        }

        self::assertMatchesRegularExpression('/col-md-6[^>]*>\s*<label class="form-label">Estado Redmine/', $form);
    }

    public function test_manual_assignee_and_category_use_select2_without_the_old_search_widget(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/redmine-mantencion/pendientes-manual.blade.php');

        self::assertIsString($view);
        self::assertMatchesRegularExpression('/<select name="asignado_a" id="asignado_a"[^>]*mantencion-select2[^>]*data-mantencion-select2/', $view);
        self::assertMatchesRegularExpression('/<select name="categoria" id="manual-categoria"[^>]*mantencion-select2[^>]*data-mantencion-select2/', $view);
        self::assertStringContainsString('initMantencionManualSelect2', $view);
        self::assertStringContainsString("dropdownCssClass: 'tic-select2-dropdown'", $view);
        self::assertStringNotContainsString('data-search-select-input', $view);
        self::assertStringNotContainsString('window.NovaSearchSelect?.init(document)', $view);
    }
}
