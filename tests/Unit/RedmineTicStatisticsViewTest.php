<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RedmineTicStatisticsViewTest extends TestCase
{
    public function test_history_does_not_render_the_scope_badge(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/RedmineTic/views/native-sections/history.blade.php');

        self::assertIsString($view);
        self::assertStringNotContainsString('Alcance: {{ $historyScope }}', $view);
        self::assertStringNotContainsString('$historyScope =', $view);
    }

    public function test_statistics_remove_assignee_rank_and_use_stable_chart_dialogs(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/RedmineTic/views/native-sections/stats.blade.php');
        $styles = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.css');
        $nativeStyles = file_get_contents(dirname(__DIR__, 2).'/public/assets/redmine-tic-native.css');

        self::assertIsString($view);
        self::assertIsString($styles);
        self::assertIsString($nativeStyles);
        self::assertStringNotContainsString("'by_assignee' => ['label' => 'Asignados'", $view);
        self::assertStringContainsString('Categorias y unidades del rango.', $view);
        self::assertStringNotContainsString('Top 10', $view);
        self::assertStringNotContainsString('$topCategories', $view);
        self::assertStringNotContainsString('modal-dialog modal-fullscreen', $view);
        self::assertStringContainsString('rm-stats-chart-dialog', $view);
        self::assertStringContainsString('preserveAspectRatio="xMidYMid meet"', $view);
        self::assertStringContainsString('(count($rows) * 84) + 128', $view);
        self::assertStringContainsString('--chart-canvas-width: {{ $modalCanvasWidth }}px;', $view);
        self::assertStringContainsString('viewBox="0 0 {{ $modalCanvasWidth }} {{ $modalCanvasHeight }}"', $view);
        self::assertStringContainsString('$modalLongestLabel', $view);
        self::assertStringContainsString('$modalCanvasHeight = max(680, 392 + $modalLabelDepth);', $view);
        self::assertStringContainsString('transform="rotate(-42', $view);
        self::assertStringContainsString('<title>{{ $name }}</title>{{ $name }}', $view);
        self::assertStringNotContainsString('Str::limit($name, 22)', $view);
        self::assertStringContainsString('body > .modal[id^="stats-"]', $view);
        self::assertStringContainsString('data-rm-chart-point', $view);
        self::assertStringContainsString('data-chart-label="{{ $name }}"', $view);
        self::assertStringContainsString('rm-chart-point-tooltip', $view);
        self::assertStringContainsString("document.addEventListener('pointerover'", $view);
        self::assertStringNotContainsString('$linePoints', $view);
        self::assertStringContainsString('class="rm-date-histogram"', $view);
        self::assertStringContainsString('class="rm-date-bar-chart"', $view);
        self::assertStringContainsString('($dateRowsCount * 34) + 128', $view);
        self::assertStringContainsString('class="rm-date-chart-scroll"', $view);
        self::assertStringNotContainsString('rm-date-detail-disclosure', $view);
        self::assertStringNotContainsString('Ver detalle de las', $view);
        self::assertStringNotContainsString('rm-date-detail-row', $view);
        self::assertStringNotContainsString('$smoothChartPaths', $view);
        self::assertStringNotContainsString('class="rm-category-area"', $view);
        self::assertStringNotContainsString('class="rm-category-line"', $view);
        self::assertStringContainsString('$chartBars = static function', $view);
        self::assertStringContainsString('class="rm-rank-bar"', $view);
        self::assertStringContainsString('class="rm-rank-chart-scroll"', $view);
        self::assertStringContainsString('.rm-stats-chart-modal .rm-stats-chart-dialog', $styles);
        self::assertStringContainsString('width: 100vw;', $styles);
        self::assertStringContainsString('height: 100dvh;', $styles);
        self::assertStringContainsString('max-width: none !important;', $styles);
        self::assertStringContainsString('margin: 0 !important;', $styles);
        self::assertStringContainsString('border-radius: 0 !important;', $styles);
        self::assertStringContainsString('.rm-stats-chart-modal .rm-modal-chart-panel', $styles);
        self::assertStringContainsString('width: max(100%, var(--chart-canvas-width, 1200px));', $styles);
        self::assertStringContainsString('overscroll-behavior-inline: contain;', $styles);
        self::assertStringContainsString('.rm-chart-point-tooltip', $styles);
        self::assertStringContainsString('.rm-date-chart-scroll', $styles);
        self::assertStringContainsString('.rm-date-bar-chart', $styles);
        self::assertStringNotContainsString('.rm-date-detail-disclosure', $styles);
        self::assertStringNotContainsString('.rm-date-detail-row', $nativeStyles);
        self::assertStringContainsString('.rm-rank-bar', $styles);
        self::assertStringContainsString('.rm-rank-chart-scroll', $styles);
        self::assertStringContainsString('height: var(--chart-canvas-height, 680px);', $styles);
        self::assertStringNotContainsString('.rm-rank-axis-labels', $styles);
        self::assertStringContainsString('.rm-category-point { cursor: crosshair; }', $nativeStyles);
        self::assertStringNotContainsString('.rm-category-point { cursor: help; }', $nativeStyles);
    }
}
