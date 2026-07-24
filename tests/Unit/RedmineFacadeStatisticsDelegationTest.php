<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RedmineTic\Repositories\RedmineDataRepository;
use Tests\TestCase;

/**
 * ETAPA B / Lote B6.1 — confirms RedmineDataRepository::statistics()/
 * redmineApiStatistics() still behave identically through the facade after
 * delegating their internals to RedmineStatisticsRepository. Only exercises
 * paths that never make a real outbound HTTP call (redmineApiStatistics()'s
 * "no fetch requested" / empty-cache path) — the actual Redmine fetch stays
 * untested here, same as before this lote (see
 * RedmineTicReportsBaselineTest's equivalent discipline for sendReportsToRedmine()).
 */
class RedmineFacadeStatisticsDelegationTest extends TestCase
{
    use DatabaseTransactions;

    private function facade(): RedmineDataRepository
    {
        return (new RedmineDataRepository())->forProject('redmine_tic');
    }

    private function moduleId(): int
    {
        return (int) DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
    }

    private function makeReport(array $overrides = []): int
    {
        return (int) DB::table('redmine_tic_reportes')->insertGetId(array_merge([
            'modulo_id' => $this->moduleId(),
            'estado' => 'pendiente',
            'tipo' => 'Soporte',
            'prioridad' => 'NORMAL',
            'asunto' => 'Reporte B6.1',
            'descripcion' => 'Descripcion',
            'fecha_inicio' => '2026-06-10',
            'redmine_id' => null,
            'hora_extra' => 0,
            'origen' => 'manual',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ], $overrides));
    }

    public function test_statistics_counts_active_and_archived_reports_via_facade(): void
    {
        // A far-future date range avoids any collision with real pre-existing
        // rows in this shared dev database (confirmed some baseline rows fall
        // within 2026 date ranges).
        $this->makeReport(['estado' => 'pendiente', 'fecha_inicio' => '2031-06-10']);
        $this->makeReport(['estado' => 'procesado', 'fecha_inicio' => '2031-06-10']);
        $this->makeReport(['estado' => 'archivado', 'fecha_inicio' => '2031-06-10']);

        $result = $this->facade()->statistics(['desde' => '01-06-2031', 'hasta' => '30-06-2031']);

        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['by_status']['pendiente']);
        $this->assertSame(1, $result['by_status']['procesado']);
        $this->assertSame(1, $result['by_status']['archivado']);
    }

    public function test_statistics_excludes_reports_outside_the_date_range(): void
    {
        $this->makeReport(['fecha_inicio' => '2031-06-15']);
        $this->makeReport(['fecha_inicio' => '2031-08-01']);

        $result = $this->facade()->statistics(['desde' => '01-06-2031', 'hasta' => '30-06-2031']);

        $this->assertSame(1, $result['total']);
    }

    public function test_redmine_api_statistics_without_fetch_and_without_cache_returns_empty_skeleton(): void
    {
        $result = $this->facade()->redmineApiStatistics(['fetch' => false], []);

        $this->assertSame('redmine-api', $result['source']);
        $this->assertFalse($result['fetched']);
        $this->assertSame(0, $result['total']);
        $this->assertArrayHasKey('status_options', $result);
        $this->assertArrayHasKey('tracker_options', $result);
        $this->assertArrayHasKey('priority_options', $result);
    }

    public function test_redmine_api_statistics_returns_cached_result_when_available(): void
    {
        $facade = $this->facade();
        // Seed the DB-backed cache directly through the same repository the
        // facade delegates to, matching how a real fetch would have saved it.
        $repo = new \RedmineTic\Repositories\RedmineStatisticsRepository('redmine_tic', 'Backlog Soporte TI');
        $repo->saveApiStatisticsCache([
            'total' => 4,
            'by_status' => ['Nueva' => 4],
            'raw_rows' => [
                ['status' => ['name' => 'Nueva'], 'start_date' => '2026-06-01'],
                ['status' => ['name' => 'Nueva'], 'start_date' => '2026-06-02'],
                ['status' => ['name' => 'Nueva'], 'start_date' => '2026-06-03'],
                ['status' => ['name' => 'Nueva'], 'start_date' => '2026-06-04'],
            ],
            'filters' => ['desde' => '01-06-2026', 'hasta' => '30-06-2026'],
        ]);

        $result = $facade->redmineApiStatistics(['fetch' => false], []);

        $this->assertTrue($result['fetched']);
        $this->assertTrue($result['cached']);
        $this->assertSame(4, $result['total']);
        $this->assertSame(4, $result['by_status']['Nueva']);
    }
}
