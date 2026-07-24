<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RedmineTic\Repositories\RedmineDataRepository;
use Tests\TestCase;

/**
 * ETAPA B / Lote B5.3 — covers the rewrite of deleteReports()/archiveReports()/
 * resetErrors() from "read all active reports, filter in PHP, rewrite the
 * whole collection via saveActiveReports()" to a targeted WHERE id IN (...)
 * per selection, via RedmineReportRepository::findActiveByIds()/
 * deleteActiveByIds(). Confirms: only selected ids are touched, ids that
 * don't match anything are harmless, state/module isolation holds, horas
 * extra pivots stay correct, and — critically — query count no longer scales
 * with the total number of active reports (the B5.0 finding this sublote
 * fixes).
 */
class RedmineReportMassOperationsTest extends TestCase
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
            'asunto' => 'Reporte B5.3',
            'descripcion' => 'Descripcion',
            'fecha_inicio' => '2026-06-01',
            'redmine_id' => null,
            'hora_extra' => 0,
            'origen' => 'manual',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ], $overrides));
    }

    private function makeOtherModuleReport(): array
    {
        $otherModuleId = (int) DB::table('modulos_nova')->insertGetId([
            'clave_modulo' => 'b53_other_' . Str::random(6),
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

        $reportId = (int) DB::table('redmine_tic_reportes')->insertGetId([
            'modulo_id' => $otherModuleId,
            'estado' => 'pendiente',
            'tipo' => 'Soporte',
            'prioridad' => 'NORMAL',
            'asunto' => 'Reporte de otro modulo',
            'descripcion' => 'd',
            'fecha_inicio' => '2026-06-01',
            'redmine_id' => null,
            'hora_extra' => 0,
            'origen' => 'manual',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        return ['module_id' => $otherModuleId, 'report_id' => $reportId];
    }

    // ---- deleteReports() ----

    public function test_delete_reports_with_empty_selection_does_nothing(): void
    {
        $this->assertSame(0, $this->facade()->deleteReports([]));
    }

    public function test_delete_reports_ignores_invalid_ids_but_deletes_valid_ones(): void
    {
        $keepId = $this->makeReport();
        $deleteId = $this->makeReport();

        $deleted = $this->facade()->deleteReports([(string) $deleteId, 'not-a-number', '999999999']);

        $this->assertSame(1, $deleted);
        $this->assertFalse(DB::table('redmine_tic_reportes')->where('id', $deleteId)->exists());
        $this->assertTrue(DB::table('redmine_tic_reportes')->where('id', $keepId)->exists());
    }

    public function test_delete_reports_does_not_affect_rows_from_another_module(): void
    {
        $other = $this->makeOtherModuleReport();

        // Ask the facade (scoped to 'redmine_tic') to delete the other module's report id.
        $deleted = $this->facade()->deleteReports([(string) $other['report_id']]);

        $this->assertSame(0, $deleted);
        $this->assertTrue(DB::table('redmine_tic_reportes')->where('id', $other['report_id'])->exists());
    }

    public function test_delete_reports_do_not_publish_hours_extra_before_archive(): void
    {
        $keepId = $this->makeReport(['estado' => 'procesado']);
        $deleteId = $this->makeReport(['estado' => 'procesado']);
        $facade = $this->facade();
        $facade->toggleHoursExtra((string) $keepId, true);
        $facade->toggleHoursExtra((string) $deleteId, true);

        $this->assertFalse(DB::table('horas_extra_grupo_reportes')->where('origen', 'tic')->where('reporte_id', $deleteId)->exists());
        $this->assertFalse(DB::table('horas_extra_grupo_reportes')->where('origen', 'tic')->where('reporte_id', $keepId)->exists());

        $facade->deleteReports([(string) $deleteId]);
        $facade->archiveReports([(string) $keepId]);

        $this->assertFalse(DB::table('horas_extra_grupo_reportes')->where('origen', 'tic')->where('reporte_id', $deleteId)->exists());
        $this->assertTrue(DB::table('horas_extra_grupo_reportes')->where('origen', 'tic')->where('reporte_id', $keepId)->exists());
    }

    // ---- archiveReports() ----

    public function test_archive_reports_with_empty_selection_does_nothing(): void
    {
        $this->assertSame(0, $this->facade()->archiveReports([]));
    }

    public function test_archive_reports_only_archives_selected_ids(): void
    {
        $keepId = $this->makeReport(['estado' => 'pendiente']);
        $archiveId = $this->makeReport(['estado' => 'pendiente']);

        $archived = $this->facade()->archiveReports([(string) $archiveId, '999999999']);

        $this->assertSame(1, $archived);
        $this->assertSame('archivado', DB::table('redmine_tic_reportes')->where('id', $archiveId)->value('estado'));
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $keepId)->value('estado'));
    }

    public function test_archive_reports_does_not_affect_rows_from_another_module(): void
    {
        $other = $this->makeOtherModuleReport();

        $archived = $this->facade()->archiveReports([(string) $other['report_id']]);

        $this->assertSame(0, $archived);
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $other['report_id'])->value('estado'));
    }

    public function test_archive_reports_reflects_immediately_in_active_and_archived_reads(): void
    {
        $archiveId = $this->makeReport(['estado' => 'pendiente']);
        $facade = $this->facade();

        $facade->archiveReports([(string) $archiveId]);

        $this->assertFalse(collect($facade->activeReports())->pluck('id')->contains((string) $archiveId));
        $this->assertTrue(collect($facade->archivedReports())->pluck('id')->contains((string) $archiveId));
    }

    // ---- resetErrors() ----

    public function test_reset_errors_with_empty_selection_does_nothing(): void
    {
        $this->assertSame(0, $this->facade()->resetErrors([]));
    }

    public function test_reset_errors_only_touches_selected_ids_in_error_state(): void
    {
        $errorId = $this->makeReport(['estado' => 'error']);
        $pendingId = $this->makeReport(['estado' => 'pendiente']);
        $otherErrorNotSelected = $this->makeReport(['estado' => 'error']);

        $updated = $this->facade()->resetErrors([(string) $errorId, (string) $pendingId, 'garbage-id']);

        $this->assertSame(1, $updated);
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $errorId)->value('estado'));
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $pendingId)->value('estado'));
        // Not selected — must stay in error even though it matches the state filter.
        $this->assertSame('error', DB::table('redmine_tic_reportes')->where('id', $otherErrorNotSelected)->value('estado'));
    }

    public function test_reset_errors_clears_redmine_id_and_procesado_ts(): void
    {
        $id = $this->makeReport(['estado' => 'error', 'redmine_id' => 555, 'procesado_at' => now()]);

        $this->facade()->resetErrors([(string) $id]);

        $row = DB::table('redmine_tic_reportes')->where('id', $id)->first();
        $this->assertNull($row->redmine_id);
        $this->assertNull($row->procesado_at);
    }

    public function test_reset_errors_does_not_affect_rows_from_another_module(): void
    {
        $other = $this->makeOtherModuleReport();
        DB::table('redmine_tic_reportes')->where('id', $other['report_id'])->update(['estado' => 'error']);

        $updated = $this->facade()->resetErrors([(string) $other['report_id']]);

        $this->assertSame(0, $updated);
        $this->assertSame('error', DB::table('redmine_tic_reportes')->where('id', $other['report_id'])->value('estado'));
    }

    // ---- O(1) query-count proofs (comparison-based, same method as B5.2) ----

    private function countQueriesFor(callable $action): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function makeManyOtherActiveReports(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeReport();
        }
    }

    public function test_delete_reports_query_count_does_not_scale_with_total_active_reports(): void
    {
        $smallId = $this->makeReport();
        $this->makeManyOtherActiveReports(5);
        $smallCount = $this->countQueriesFor(fn () => $this->facade()->deleteReports([(string) $smallId]));

        $largeId = $this->makeReport();
        $this->makeManyOtherActiveReports(60);
        $largeCount = $this->countQueriesFor(fn () => $this->facade()->deleteReports([(string) $largeId]));

        $this->assertLessThanOrEqual(3, abs($largeCount - $smallCount), "deleteReports() ejecutó {$smallCount} queries con 6 reportes activos y {$largeCount} con 61 — no debería escalar (O(1)).");
    }

    public function test_archive_reports_query_count_does_not_scale_with_total_active_reports(): void
    {
        $smallId = $this->makeReport();
        $this->makeManyOtherActiveReports(5);
        $smallCount = $this->countQueriesFor(fn () => $this->facade()->archiveReports([(string) $smallId]));

        $largeId = $this->makeReport();
        $this->makeManyOtherActiveReports(60);
        $largeCount = $this->countQueriesFor(fn () => $this->facade()->archiveReports([(string) $largeId]));

        $this->assertLessThanOrEqual(3, abs($largeCount - $smallCount), "archiveReports() ejecutó {$smallCount} queries con 6 reportes activos y {$largeCount} con 61 — no debería escalar (O(1)).");
    }

    public function test_reset_errors_query_count_does_not_scale_with_total_active_reports(): void
    {
        $smallId = $this->makeReport(['estado' => 'error']);
        $this->makeManyOtherActiveReports(5);
        $smallCount = $this->countQueriesFor(fn () => $this->facade()->resetErrors([(string) $smallId]));

        $largeId = $this->makeReport(['estado' => 'error']);
        $this->makeManyOtherActiveReports(60);
        $largeCount = $this->countQueriesFor(fn () => $this->facade()->resetErrors([(string) $largeId]));

        $this->assertLessThanOrEqual(3, abs($largeCount - $smallCount), "resetErrors() ejecutó {$smallCount} queries con 6 reportes activos y {$largeCount} con 61 — no debería escalar (O(1)).");
    }
}
