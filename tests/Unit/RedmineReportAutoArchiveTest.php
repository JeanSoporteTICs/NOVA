<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RedmineTic\Repositories\RedmineDataRepository;
use Tests\TestCase;

/**
 * ETAPA B / Lote B5.6 Parte 1 — covers the rewrite of
 * archiveExpiredProcessedReports() from "load every active report, rebuild
 * the whole collection minus archived ones, rewrite it via
 * saveActiveReports()" to a targeted query scoped by modulo_id + estado
 * (RedmineReportRepository::findActiveByStates()), followed by the existing
 * per-row archiveReport(). Confirms: only expired processed reports are
 * archived, pending/error/recent-processed rows are untouched, module
 * isolation holds, the returned count matches, and the 5-minute debounce
 * still gates repeated calls.
 */
class RedmineReportAutoArchiveTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // The debounce cache uses the 'array' store (see phpunit.xml), which
        // is process-lifetime, not per-test — clear it so an earlier test's
        // call doesn't silently short-circuit this one.
        Cache::forget('nova.redmine.archive_check.redmine_tic');
    }

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
            'asunto' => 'Reporte B5.6',
            'descripcion' => 'Descripcion',
            'fecha_inicio' => '2026-06-01',
            'redmine_id' => null,
            'hora_extra' => 0,
            'origen' => 'manual',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ], $overrides));
    }

    private function expiredTimestamp(): \Illuminate\Support\Carbon
    {
        return now('America/Santiago')->subDays(30);
    }

    private function recentTimestamp(): \Illuminate\Support\Carbon
    {
        return now('America/Santiago');
    }

    public function test_archives_only_expired_processed_reports(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['retencion_horas' => 1]);

        $expiredId = $this->makeReport(['estado' => 'procesado', 'procesado_at' => $this->expiredTimestamp()]);

        $archived = $facade->archiveExpiredProcessedReports();

        $this->assertSame(1, $archived);
        $this->assertSame('archivado', DB::table('redmine_tic_reportes')->where('id', $expiredId)->value('estado'));
    }

    public function test_does_not_archive_pending_or_error_reports(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['retencion_horas' => 1]);

        $pendingId = $this->makeReport(['estado' => 'pendiente', 'actualizado_at' => $this->expiredTimestamp()]);
        $errorId = $this->makeReport(['estado' => 'error', 'actualizado_at' => $this->expiredTimestamp()]);

        $archived = $facade->archiveExpiredProcessedReports();

        $this->assertSame(0, $archived);
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $pendingId)->value('estado'));
        $this->assertSame('error', DB::table('redmine_tic_reportes')->where('id', $errorId)->value('estado'));
    }

    public function test_does_not_archive_recently_processed_reports(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['retencion_horas' => 1]);

        $recentId = $this->makeReport(['estado' => 'procesado', 'procesado_at' => $this->recentTimestamp()]);

        $archived = $facade->archiveExpiredProcessedReports();

        $this->assertSame(0, $archived);
        $this->assertSame('procesado', DB::table('redmine_tic_reportes')->where('id', $recentId)->value('estado'));
    }

    public function test_does_not_touch_another_module(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['retencion_horas' => 1]);

        $otherModuleId = (int) DB::table('modulos_nova')->insertGetId([
            'clave_modulo' => 'b56_other_' . Str::random(6),
            'nombre' => 'Otro modulo',
            'descripcion' => '',
            'icono' => '',
            'tipo' => 'native',
            'ruta' => 'otro',
            'entrada' => 'laravel:redmine.native.dashboard',
            'habilitado' => 1,
            'orden' => 999,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);
        $otherReportId = (int) DB::table('redmine_tic_reportes')->insertGetId([
            'modulo_id' => $otherModuleId,
            'estado' => 'procesado',
            'tipo' => 'Soporte',
            'prioridad' => 'NORMAL',
            'asunto' => 'Reporte de otro modulo',
            'descripcion' => 'd',
            'fecha_inicio' => '2026-06-01',
            'redmine_id' => null,
            'hora_extra' => 0,
            'origen' => 'manual',
            'procesado_at' => $this->expiredTimestamp(),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        $archived = $facade->archiveExpiredProcessedReports();

        $this->assertSame(0, $archived);
        $this->assertSame('procesado', DB::table('redmine_tic_reportes')->where('id', $otherReportId)->value('estado'));
    }

    public function test_return_value_matches_number_of_rows_actually_archived_in_a_mixed_set(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['retencion_horas' => 1]);

        $expiredOne = $this->makeReport(['estado' => 'procesado', 'procesado_at' => $this->expiredTimestamp()]);
        $expiredTwo = $this->makeReport(['estado' => 'procesada', 'procesado_at' => $this->expiredTimestamp()]);
        $recent = $this->makeReport(['estado' => 'procesado', 'procesado_at' => $this->recentTimestamp()]);
        $pending = $this->makeReport(['estado' => 'pendiente']);
        $error = $this->makeReport(['estado' => 'error']);

        $archived = $facade->archiveExpiredProcessedReports();

        $this->assertSame(2, $archived);
        $this->assertSame('archivado', DB::table('redmine_tic_reportes')->where('id', $expiredOne)->value('estado'));
        $this->assertSame('archivado', DB::table('redmine_tic_reportes')->where('id', $expiredTwo)->value('estado'));
        $this->assertSame('procesado', DB::table('redmine_tic_reportes')->where('id', $recent)->value('estado'));
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $pending)->value('estado'));
        $this->assertSame('error', DB::table('redmine_tic_reportes')->where('id', $error)->value('estado'));
    }

    public function test_debounce_prevents_a_second_call_within_the_ttl(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['retencion_horas' => 1]);
        $this->makeReport(['estado' => 'procesado', 'procesado_at' => $this->expiredTimestamp()]);

        $first = $facade->archiveExpiredProcessedReports();
        $this->assertSame(1, $first);

        // A second eligible report appears, but the debounce should still
        // block any real work within the 5-minute TTL.
        $this->makeReport(['estado' => 'procesado', 'procesado_at' => $this->expiredTimestamp()]);
        $second = $facade->archiveExpiredProcessedReports();
        $this->assertSame(0, $second);
    }

    public function test_dashboard_data_still_triggers_archival(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['retencion_horas' => 1]);
        $expiredId = $this->makeReport(['estado' => 'procesado', 'procesado_at' => $this->expiredTimestamp()]);

        $facade->dashboardData();

        $this->assertSame('archivado', DB::table('redmine_tic_reportes')->where('id', $expiredId)->value('estado'));
    }
}
