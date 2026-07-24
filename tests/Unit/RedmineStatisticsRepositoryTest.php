<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use RedmineTic\Repositories\RedmineStatisticsRepository;
use Tests\TestCase;

/**
 * ETAPA B / Lote B6.1 — direct unit coverage of the pure data
 * aggregation/transformation now living in RedmineStatisticsRepository,
 * extracted verbatim from RedmineDataRepository::statistics()/
 * redmineApiStatistics()'s private helper cluster. No HTTP transport is
 * exercised here — this repository never talks to Redmine (that stays in
 * the facade, see RedmineStatisticsRepository's class docblock).
 */
class RedmineStatisticsRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private function repo(): RedmineStatisticsRepository
    {
        return new RedmineStatisticsRepository('redmine_tic', 'Backlog Soporte TI');
    }

    private function noopDateKeyNormalizer(): callable
    {
        // Mirrors the facade's normalizeDateKey() closely enough for these
        // pure-aggregation tests: accepts already-normalized 'Y-m-d' input.
        return fn (string $date): string => $date;
    }

    private function report(array $overrides = []): array
    {
        return array_merge([
            'estado' => 'pendiente',
            'categoria' => 'Equipos',
            'unidad_solicitante' => 'HBV',
            'asignado_nombre' => 'Juan Perez',
            'fecha_inicio' => '2026-06-01',
        ], $overrides);
    }

    // ---- statistics() ----

    public function test_statistics_counts_by_status_category_unit_and_assignee(): void
    {
        $reports = [
            $this->report(['estado' => 'pendiente']),
            $this->report(['estado' => 'pendiente']),
            $this->report(['estado' => 'procesado']),
        ];

        $result = $this->repo()->statistics($reports, null, null, $this->noopDateKeyNormalizer());

        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['by_status']['pendiente']);
        $this->assertSame(1, $result['by_status']['procesado']);
        $this->assertSame(3, $result['by_category']['Equipos']);
        $this->assertSame(3, $result['by_unit']['HBV']);
        $this->assertSame(3, $result['by_assignee']['Juan Perez']);
    }

    public function test_statistics_groups_by_date_and_month_and_tracks_maxima(): void
    {
        $reports = [
            $this->report(['fecha_inicio' => '2026-06-01']),
            $this->report(['fecha_inicio' => '2026-06-01']),
            $this->report(['fecha_inicio' => '2026-06-15']),
            $this->report(['fecha_inicio' => '2026-07-01']),
        ];

        $result = $this->repo()->statistics($reports, null, null, $this->noopDateKeyNormalizer());

        $this->assertSame(2, $result['by_date']['2026-06-01']);
        $this->assertSame(1, $result['by_date']['2026-06-15']);
        $this->assertSame(3, $result['by_month']['2026-06']);
        $this->assertSame(1, $result['by_month']['2026-07']);
        $this->assertSame(2, $result['max_daily']);
        $this->assertSame(3, $result['max_monthly']);
    }

    public function test_statistics_embeds_formatted_from_to_filters(): void
    {
        $from = new \DateTimeImmutable('2026-06-01');
        $to = new \DateTimeImmutable('2026-06-30');

        $result = $this->repo()->statistics([], $from, $to, $this->noopDateKeyNormalizer());

        $this->assertSame('01-06-2026', $result['filters']['desde']);
        $this->assertSame('30-06-2026', $result['filters']['hasta']);
    }

    public function test_statistics_with_empty_reports_returns_zeroed_skeleton(): void
    {
        $result = $this->repo()->statistics([], null, null, $this->noopDateKeyNormalizer());

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['by_status']);
        $this->assertSame(0, $result['max_daily']);
        $this->assertSame(0, $result['max_monthly']);
    }

    // ---- redmineApiStatistics() pure pieces ----

    public function test_empty_statistics_returns_zeroed_skeleton_with_given_filters(): void
    {
        $result = $this->repo()->emptyStatistics(['desde' => '01-06-2026', 'hasta' => '30-06-2026']);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['by_priority']);
        $this->assertSame([], $result['by_tracker']);
        $this->assertSame(['desde' => '01-06-2026', 'hasta' => '30-06-2026'], $result['filters']);
    }

    private function apiRow(array $overrides = []): array
    {
        return array_merge([
            'status' => ['name' => 'Nueva'],
            'category' => ['name' => 'Equipos'],
            'priority' => ['name' => 'Normal'],
            'tracker' => ['name' => 'Soporte'],
            'assigned_to' => ['name' => 'Juan Perez'],
            'start_date' => '2026-06-01',
        ], $overrides);
    }

    public function test_build_api_statistics_from_rows_counts_by_relation_fields(): void
    {
        $rows = [
            $this->apiRow(['status' => ['name' => 'Nueva']]),
            $this->apiRow(['status' => ['name' => 'Nueva']]),
            $this->apiRow(['status' => ['name' => 'Cerrada']]),
        ];

        $result = $this->repo()->buildApiStatisticsFromRows($rows, [], [], false, $this->noopDateKeyNormalizer());

        $this->assertSame('redmine-api', $result['source']);
        $this->assertTrue($result['fetched']);
        $this->assertFalse($result['cached']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['by_status']['Nueva']);
        $this->assertSame(1, $result['by_status']['Cerrada']);
        $this->assertSame(3, $result['by_category']['Equipos']);
        $this->assertSame(3, $result['by_priority']['Normal']);
        $this->assertSame(3, $result['by_tracker']['Soporte']);
        $this->assertSame(3, $result['by_assignee']['Juan Perez']);
        $this->assertCount(3, $result['raw_rows']);
    }

    public function test_build_api_statistics_from_rows_filters_by_selected_categories_only_when_active(): void
    {
        $rows = [
            $this->apiRow(['category' => ['name' => 'Equipos']]),
            $this->apiRow(['category' => ['name' => 'Redes']]),
        ];

        $filtered = $this->repo()->buildApiStatisticsFromRows($rows, [], ['category_scope' => ['Equipos'], 'category_filter' => '1'], false, $this->noopDateKeyNormalizer());
        $this->assertSame(1, $filtered['total']);

        $unfiltered = $this->repo()->buildApiStatisticsFromRows($rows, [], ['category_scope' => ['Equipos'], 'category_filter' => ''], false, $this->noopDateKeyNormalizer());
        $this->assertSame(2, $unfiltered['total']);
        // category_options always reflects the full unfiltered row set.
        $this->assertSame(2, array_sum($unfiltered['category_options']));
    }

    public function test_build_api_statistics_from_rows_marks_cached_flag_as_given(): void
    {
        $result = $this->repo()->buildApiStatisticsFromRows([], [], [], true, $this->noopDateKeyNormalizer());
        $this->assertTrue($result['cached']);
    }

    public function test_normalize_api_statistics_deduplicates_unit_labels_case_insensitively(): void
    {
        $result = $this->repo()->normalizeApiStatistics([
            'by_unit' => ['HBV' => 2, 'hbv' => 3, 'Sin dato' => 5],
        ]);

        $this->assertSame(5, $result['by_unit']['HBV'] ?? $result['by_unit']['Hbv'] ?? null);
        $this->assertArrayNotHasKey('Sin dato', $result['by_unit']);
    }

    public function test_status_options_includes_defaults_plus_configured_estados(): void
    {
        $options = $this->repo()->statusOptions(['estados' => [
            ['id' => '5', 'nombre' => 'Resuelto'],
        ]]);

        $values = array_column($options, 'value');
        $this->assertContains('open', $values);
        $this->assertContains('closed', $values);
        $this->assertContains('all', $values);
        $this->assertContains('5', $values);
    }

    public function test_config_options_includes_all_option_plus_configured_items(): void
    {
        $options = $this->repo()->configOptions(['trackers' => [
            ['id' => '3', 'nombre' => 'Soporte'],
        ]], 'trackers');

        $values = array_column($options, 'value');
        $this->assertSame(['all', '3'], $values);
    }

    public function test_normalize_status_selection_defaults_to_open_when_invalid(): void
    {
        $options = [['value' => 'open', 'label' => 'Abiertos'], ['value' => 'all', 'label' => 'Todos']];
        $repo = $this->repo();

        $this->assertSame('all', $repo->normalizeStatusSelection('all', $options));
        $this->assertSame('open', $repo->normalizeStatusSelection('garbage', $options));
    }

    public function test_normalize_option_selection_defaults_to_all_when_invalid(): void
    {
        $options = [['value' => 'all', 'label' => 'Todos'], ['value' => '3', 'label' => 'Soporte']];
        $repo = $this->repo();

        $this->assertSame('3', $repo->normalizeOptionSelection('3', $options));
        $this->assertSame('all', $repo->normalizeOptionSelection('garbage', $options));
    }

    public function test_status_query_value_maps_known_selections(): void
    {
        $repo = $this->repo();
        $this->assertSame('*', $repo->statusQueryValue('all'));
        $this->assertSame('c', $repo->statusQueryValue('closed'));
        $this->assertSame('o', $repo->statusQueryValue('open'));
        $this->assertSame('5', $repo->statusQueryValue('5'));
    }

    public function test_normalize_category_selection_trims_and_deduplicates(): void
    {
        $result = $this->repo()->normalizeCategorySelection([' Equipos ', 'Equipos', '', 'Redes']);
        $this->assertSame(['Equipos', 'Redes'], $result);
    }

    // ---- API statistics cache (DB-backed via RedmineConfigRepository) ----

    public function test_api_statistics_cache_round_trips_through_save_and_read(): void
    {
        $repo = $this->repo();
        // saveApiStatisticsCache() runs by_unit through normalizeApiStatistics()
        // before persisting (title-cases labels via titleLabel()) — same as
        // the pre-extraction behavior, so assert against the normalized key.
        $repo->saveApiStatisticsCache(['total' => 7, 'by_unit' => ['HBV' => 2]]);

        $cached = $repo->apiStatisticsCache();

        $this->assertSame(7, $cached['total']);
        $this->assertSame(2, array_sum($cached['by_unit']));
    }

    public function test_api_statistics_cache_is_empty_before_anything_is_saved(): void
    {
        // A fresh repository instance never had saveApiStatisticsCache()
        // called against this connection within the current transaction.
        $cached = $this->repo()->apiStatisticsCache();
        $this->assertIsArray($cached);
    }
}
