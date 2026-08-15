<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RedmineTicWebhookSelect2Test extends TestCase
{
    public function test_manual_report_uses_matching_select2_controls(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/RedmineTic/views/native-sections/webhook.blade.php');
        $layout = file_get_contents($root.'/RedmineTic/views/native.blade.php');
        $styles = file_get_contents($root.'/public/assets/nova-ui.css');

        self::assertIsString($view);
        self::assertIsString($layout);
        self::assertIsString($styles);
        self::assertSame(5, substr_count($view, '<select class="form-select tic-webhook-select2"'));
        self::assertStringContainsString('id="manual-prioridad" name="prioridad" data-tic-webhook-select2', $view);
        self::assertStringContainsString('id="manual-asignado" name="asignado_a"', $view);
        self::assertStringContainsString('id="manual-categoria" name="categoria"', $view);
        self::assertStringContainsString('id="manual-unidad-solicitante" name="unidad_solicitante"', $view);
        self::assertStringContainsString('id="manual-hora-extra" name="hora_extra" data-tic-webhook-select2', $view);
        self::assertStringContainsString("dropdownCssClass: 'tic-select2-dropdown'", $view);
        self::assertStringContainsString("window.jQuery(assigneeSelect).trigger('change')", $view);
        self::assertStringContainsString("in_array(\$section, ['dashboard', 'webhook'], true)", $layout);
        self::assertMatchesRegularExpression('/tic-webhook-select2.*?select2-selection__rendered\s*\{[^}]*font-weight:\s*700;/s', $styles);
    }

    public function test_manual_report_places_existing_labels_to_the_left_without_changing_fields(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/RedmineTic/views/native-sections/webhook.blade.php');
        $styles = file_get_contents($root.'/public/assets/redmine-tic-webhook.css');

        self::assertIsString($view);
        self::assertIsString($styles);
        self::assertSame(14, substr_count($view, 'rm-manual-horizontal-field'));
        self::assertStringContainsString('class="col-12 rm-manual-description-field"', $view);
        self::assertStringContainsString('class="rm-manual-description-control"', $view);
        foreach ([
            'tipo', 'prioridad', 'asunto', 'descripcion', 'asignado_a',
            'categoria', 'solicitante', 'unidad', 'unidad_solicitante',
            'fecha_inicio', 'fecha_fin', 'fecha', 'hora', 'hora_extra',
            'tiempo_estimado',
        ] as $fieldName) {
            self::assertStringContainsString('name="'.$fieldName.'"', $view, $fieldName);
        }
        self::assertMatchesRegularExpression(
            '/\.rm-manual-horizontal-field\s*\{[^}]*grid-template-columns:\s*minmax\(88px, var\(--rm-manual-label-width\)\) minmax\(0, 1fr\);/s',
            $styles
        );
        self::assertMatchesRegularExpression(
            '/\.rm-manual-horizontal-field > \.form-label\s*\{[^}]*text-align:\s*right;/s',
            $styles
        );
        self::assertMatchesRegularExpression(
            '/\.rm-manual-description-field\s*\{[^}]*grid-template-columns:\s*minmax\(88px, 112px\) minmax\(0, 1fr\);/s',
            $styles
        );
        self::assertMatchesRegularExpression(
            '/\.rm-manual-description-control.*?\.nova-description-tabs.*?width:\s*100%;/s',
            $styles
        );
        self::assertMatchesRegularExpression(
            '/class="manual-extra-row">.*?<select[^>]*id="manual-hora-extra"[^>]*name="hora_extra".*?<option value="NO">No<\/option>.*?<option value="SI">Sí<\/option>.*?<label[^>]*for="manual-tiempo-estimado">Tiempo estimado<\/label>.*?name="tiempo_estimado"/s',
            $view
        );
        self::assertStringNotContainsString('type="checkbox" id="manual-hora-extra"', $view);
        self::assertMatchesRegularExpression(
            '/class="rm-manual-field-stack".*?name="unidad_solicitante".*?class="manual-extra-row".*?name="hora_extra".*?name="tiempo_estimado".*?<\/div>\s*<\/div>\s*<div class="col-lg-5">/s',
            $view
        );
    }
}
