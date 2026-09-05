<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RedmineTicHistoryStatusFilterTest extends TestCase
{
    public function test_history_filters_by_redmine_status_and_preserves_navigation_state(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/RedmineTic/views/native-sections/history.blade.php');

        self::assertIsString($view);
        self::assertStringContainsString("\$fEstadoRedmine = trim((string) (\$query['estado_redmine'] ?? ''));", $view);
        self::assertStringContainsString('name="estado_redmine"', $view);
        self::assertStringContainsString('id="history-estado-redmine"', $view);
        self::assertStringContainsString('$normalizeText($redmineStatus) !== $normalizeText($fEstadoRedmine)', $view);
        self::assertStringContainsString("'remove' => 'estado_redmine'", $view);
        self::assertStringContainsString("'estado_redmine' => \$fEstadoRedmine", $view);
        self::assertStringContainsString('$redmineFilterStatuses[$statusName] = $statusName;', $view);
        self::assertStringContainsString('$redmineFilterStatuses[$redmineStatus] = $redmineStatus;', $view);
    }

    public function test_history_filter_panel_has_responsive_date_presets_and_accessible_motion(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/RedmineTic/views/native-sections/history.blade.php');
        $styles = file_get_contents($root.'/public/assets/redmine-tic-history.css');

        self::assertIsString($view);
        self::assertIsString($styles);
        self::assertStringContainsString('class="historico-filter-layout"', $view);
        self::assertStringContainsString('data-history-date-range', $view);
        self::assertSame(5, substr_count($view, 'data-history-range-preset='));
        self::assertStringContainsString('aria-label="Rangos de fecha rápidos"', $view);
        self::assertStringContainsString("new Intl.DateTimeFormat('es-CL'", $view);
        self::assertStringContainsString('if (dateFrom.value && dateTo.value && dateFrom.value > dateTo.value)', $view);
        self::assertStringContainsString('if (changed === dateTo) dateFrom.value = dateTo.value;', $view);
        self::assertStringContainsString("form?.classList.toggle('is-filtering', state);", $view);
        self::assertStringContainsString('@media (max-width: 575.98px)', $styles);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        self::assertStringContainsString('@keyframes historico-filter-enter', $styles);
        self::assertStringContainsString('.historico-date-connector', $styles);
        self::assertStringContainsString('data-history-filter-toggle', $view);
        self::assertStringContainsString('data-history-filter-body hidden', $view);
        self::assertStringContainsString('setFilterExpanded(false);', $view);
        self::assertStringContainsString("label.textContent = expanded ? 'Ocultar filtros' : 'Mostrar filtros';", $view);
        self::assertStringContainsString('@keyframes historico-filter-body-open', $styles);
        self::assertStringContainsString('class="historico-search-strip"', $view);
        self::assertStringContainsString('class="historico-search-actions"', $view);
        self::assertStringNotContainsString('Búsqueda rápida', $view);
        self::assertStringNotContainsString('Presiona Buscar para aplicar los criterios.', $view);
        self::assertStringContainsString('id="btn-apply"', $view);
        self::assertStringNotContainsString('scheduleAutoFilter', $view);
        self::assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr));', $styles);
    }
}
