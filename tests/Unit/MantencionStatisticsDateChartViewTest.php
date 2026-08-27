<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MantencionStatisticsDateChartViewTest extends TestCase
{
    public function test_date_chart_uses_the_shared_daily_histogram_and_full_screen_modal(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/redmine-mantencion/estadisticas.blade.php');
        $styles = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/assets/css/estadisticas.css');

        self::assertIsString($view);
        self::assertIsString($styles);
        self::assertStringContainsString('class="rm-date-histogram"', $view);
        self::assertStringContainsString('class="row g-3 mb-4 mantencion-stats-chart-grid"', $view);
        self::assertStringContainsString('class="col-12 col-xl-8"', $view);
        self::assertStringContainsString('class="col-12 col-xl-4"', $view);
        self::assertStringContainsString('preserveAspectRatio="none"', $view);
        self::assertStringContainsString('class="modal fade rm-stats-chart-modal mantencion-date-chart-modal" id="modalChartFechas"', $view);
        self::assertStringContainsString('rm-stats-chart-dialog', $view);
        self::assertStringContainsString('class="rm-date-chart-scroll"', $view);
        self::assertStringContainsString('class="rm-date-bar-chart"', $view);
        self::assertStringContainsString('$anchoLienzoFecha = max(1200, ($cantidadFechas * 34) + 128);', $view);
        self::assertStringContainsString('data-rm-chart-point', $view);
        self::assertStringContainsString("document.addEventListener('pointerover'", $view);
        self::assertStringNotContainsString('id="chart-fechas"', $view);
        self::assertStringNotContainsString('id="chart-fechas-modal"', $view);
        self::assertStringNotContainsString('function renderFechasModal', $view);
        self::assertStringNotContainsString('const buildSeries', $view);
        self::assertStringNotContainsString('$years = []', $view);
        self::assertStringContainsString('.mantencion-date-chart-card', $styles);
        self::assertStringContainsString('.mantencion-date-chart-modal .rm-date-bar-chart', $styles);
        self::assertStringContainsString('width: max(100%, var(--date-chart-width, 1200px));', $styles);
        self::assertStringContainsString('.mantencion-date-chart-modal .modal-body', $styles);
        self::assertStringContainsString('flex: 1 1 auto;', $styles);
        self::assertStringContainsString('height: 100%;', $styles);
        self::assertStringContainsString('min-height: 360px;', $styles);
        self::assertStringContainsString('@media (max-width: 575.98px)', $styles);
    }

    public function test_user_charts_use_a_high_contrast_palette(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/redmine-mantencion/estadisticas.blade.php');

        self::assertIsString($view);
        self::assertStringContainsString('const mantencionUserChartColors = Object.freeze([', $view);
        self::assertStringContainsString("'#2563eb'", $view);
        self::assertStringContainsString("'#e11d48'", $view);
        self::assertStringContainsString("'#059669'", $view);
        self::assertStringContainsString("'#d97706'", $view);
        self::assertStringContainsString("'#7c3aed'", $view);
        self::assertStringContainsString("borderColor: '#ffffff'", $view);
        self::assertStringContainsString("pointStyle: 'circle'", $view);
        self::assertStringNotContainsString("['#4e73df','#5a8dee','#6fa3ff'", $view);
    }
}
