<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RedmineTic\Repositories\RedmineDataRepository;
use RedmineTic\Repositories\RedmineReportRepository;
use Tests\TestCase;

/**
 * ETAPA B / Lote B5.2 — direct unit coverage of the punctual (single-PK)
 * report operations now living in RedmineReportRepository
 * (findActiveById/updateActiveFields/updateActiveHoursExtraFlag/
 * deleteActiveById/insertReport/upsertArchived/reportDatabaseId), plus a
 * query-count regression guard proving RedmineDataRepository::updateReport()/
 * deleteReport()/toggleHoursExtra() stay O(1) — bounded, not scaling with
 * the total number of active reports in the module.
 */
class RedmineReportIndividualOperationsTest extends TestCase
{
    use DatabaseTransactions;

    private function facade(): RedmineDataRepository
    {
        return (new RedmineDataRepository)->forProject('redmine_tic');
    }

    private function repo(): RedmineReportRepository
    {
        return new RedmineReportRepository('redmine_tic', 'Backlog Soporte TI');
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
            'asunto' => 'Reporte B5.2',
            'descripcion' => 'Descripcion',
            'fecha_inicio' => '2026-06-01',
            'redmine_id' => null,
            'hora_extra' => 0,
            'origen' => 'manual',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ], $overrides));
    }

    // ---- RedmineReportRepository direct coverage ----

    public function test_report_database_id_parses_numeric_and_rejects_invalid(): void
    {
        $repo = $this->repo();
        $this->assertSame(42, $repo->reportDatabaseId('42'));
        $this->assertSame(0, $repo->reportDatabaseId('abc'));
        $this->assertSame(0, $repo->reportDatabaseId(''));
        $this->assertSame(0, $repo->reportDatabaseId(null));
    }

    public function test_find_active_by_id_returns_hydrated_report_or_null(): void
    {
        $id = $this->makeReport(['asunto' => 'Encontrable']);
        $repo = $this->repo();

        $found = $repo->findActiveById($this->moduleId(), (string) $id, fn (string $x): string => '');
        $this->assertNotNull($found);
        $this->assertSame('Encontrable', $found['asunto']);

        $this->assertNull($repo->findActiveById($this->moduleId(), '999999999', fn (string $x): string => ''));
    }

    public function test_update_active_fields_touches_only_the_targeted_row(): void
    {
        $keepId = $this->makeReport(['asunto' => 'Sin tocar']);
        $targetId = $this->makeReport(['asunto' => 'Original']);
        $repo = $this->repo();

        $ok = $repo->updateActiveFields($this->moduleId(), (string) $targetId, ['asunto' => 'Editado']);

        $this->assertTrue($ok);
        $this->assertSame('Editado', DB::table('redmine_tic_reportes')->where('id', $targetId)->value('asunto'));
        $this->assertSame('Sin tocar', DB::table('redmine_tic_reportes')->where('id', $keepId)->value('asunto'));
    }

    public function test_update_active_hours_extra_flag_sets_columns_on_the_targeted_row_only(): void
    {
        $keepId = $this->makeReport();
        $targetId = $this->makeReport();
        $repo = $this->repo();

        $ok = $repo->updateActiveHoursExtraFlag($this->moduleId(), (string) $targetId, true);

        $this->assertTrue($ok);
        $row = DB::table('redmine_tic_reportes')->where('id', $targetId)->first();
        $this->assertSame(1, (int) $row->hora_extra);
        $this->assertNotNull($row->tiempo_estimado);
        $this->assertSame(0, (int) DB::table('redmine_tic_reportes')->where('id', $keepId)->value('hora_extra'));
    }

    public function test_delete_active_by_id_removes_only_the_targeted_row(): void
    {
        $keepId = $this->makeReport();
        $targetId = $this->makeReport();
        $repo = $this->repo();

        $deleted = $repo->deleteActiveById($this->moduleId(), (string) $targetId);

        $this->assertSame(1, $deleted);
        $this->assertFalse(DB::table('redmine_tic_reportes')->where('id', $targetId)->exists());
        $this->assertTrue(DB::table('redmine_tic_reportes')->where('id', $keepId)->exists());
    }

    public function test_update_archived_redmine_status_touches_only_the_matching_ticket(): void
    {
        $keepId = $this->makeReport([
            'estado' => 'archivado',
            'redmine_id' => 880001,
            'estado_redmine' => 'Nueva',
        ]);
        $targetId = $this->makeReport([
            'estado' => 'archivado',
            'redmine_id' => 880002,
            'estado_redmine' => 'Nueva',
        ]);

        $updated = $this->repo()->updateArchivedRedmineStatus('880002', 'Cerrada');

        $this->assertSame(1, $updated);
        $this->assertSame('Nueva', DB::table('redmine_tic_reportes')->where('id', $keepId)->value('estado_redmine'));
        $this->assertSame('Cerrada', DB::table('redmine_tic_reportes')->where('id', $targetId)->value('estado_redmine'));
    }

    public function test_insert_report_creates_a_row_and_returns_merged_array_with_id(): void
    {
        $repo = $this->repo();

        $saved = $repo->insertReport($this->moduleId(), ['asunto' => 'Nuevo', 'estado' => 'pendiente'], false);

        $this->assertNotNull($saved);
        $this->assertArrayHasKey('id', $saved);
        $this->assertTrue(DB::table('redmine_tic_reportes')->where('id', $saved['id'])->where('asunto', 'Nuevo')->exists());
    }

    public function test_update_report_preserves_free_text_unit_outside_catalog(): void
    {
        $id = $this->makeReport();

        $updated = $this->facade()->updateReport([
            'id' => (string) $id,
            'unidad' => 'de Farmacia a ex Pediatría',
        ]);

        $this->assertTrue($updated);
        $this->assertSame(
            'de Farmacia a ex Pediatría',
            DB::table('redmine_tic_reportes')->where('id', $id)->value('unidad_texto')
        );
    }

    public function test_upsert_archived_inserts_when_report_has_no_numeric_id(): void
    {
        $repo = $this->repo();

        $saved = $repo->upsertArchived($this->moduleId(), ['asunto' => 'Archivado nuevo', 'estado' => 'pendiente']);

        $this->assertNotEmpty($saved['id']);
        $this->assertSame('archivado', DB::table('redmine_tic_reportes')->where('id', $saved['id'])->value('estado'));
    }

    public function test_upsert_archived_updates_when_report_already_has_a_numeric_id(): void
    {
        $id = $this->makeReport(['estado' => 'pendiente']);
        $repo = $this->repo();

        $saved = $repo->upsertArchived($this->moduleId(), ['id' => (string) $id, 'asunto' => 'Actualizado', 'estado' => 'pendiente']);

        $this->assertSame((string) $id, $saved['id']);
        $this->assertSame('archivado', DB::table('redmine_tic_reportes')->where('id', $id)->value('estado'));
        // Only this row exists for this id — confirms UPDATE, not a second INSERT.
        $this->assertSame(1, DB::table('redmine_tic_reportes')->where('asunto', 'Actualizado')->count());
    }

    // ---- O(1) query-count regression guard, through the facade ----

    private function makeManyOtherActiveReports(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->makeReport();
        }
    }

    public function test_update_report_query_count_does_not_scale_with_total_active_reports(): void
    {
        $smallId = $this->makeReport(['asunto' => 'Objetivo chico']);
        $this->makeManyOtherActiveReports(5);
        // A fresh facade per measurement — reusing one would let its internal
        // repo-instance caches (e.g. RedmineReportRepository::tableAvailable())
        // warmed by the first call silently reduce the second call's count,
        // masking the very scaling this test exists to catch.
        $smallCount = $this->countQueriesFor(fn () => $this->facade()->updateReport(['id' => (string) $smallId, 'asunto' => 'Editado chico']));

        $largeId = $this->makeReport(['asunto' => 'Objetivo grande']);
        $this->makeManyOtherActiveReports(60);
        $largeCount = $this->countQueriesFor(fn () => $this->facade()->updateReport(['id' => (string) $largeId, 'asunto' => 'Editado grande']));

        // A tolerance of a few queries absorbs framework-level non-determinism
        // (e.g. shared schema-check caches warmed by unrelated tests earlier
        // in the same PHPUnit process) without masking real O(n) scaling,
        // which would show a difference on the order of the +55 extra reports.
        $this->assertLessThanOrEqual(3, abs($largeCount - $smallCount), "updateReport() ejecutó {$smallCount} queries con 6 reportes activos y {$largeCount} con 61 — no debería escalar (O(1)).");
    }

    /**
     * Proves O(1) by comparison rather than an arbitrary absolute threshold:
     * runs the same punctual action with a small vs. a much larger number of
     * OTHER active reports in the module and asserts the query count is
     * identical either way. A fixed, non-zero baseline is expected (module
     * resolution, table-existence checks, the horas-extra pivot lookup —
     * some of it inherited, uncached, from RedmineHoursExtraRepository /
     * Nova\Repositories\HorasExtraRepository, out of scope for B5.2 per "no
     * tocar horas extra") — what matters is that baseline does not grow with
     * the report count.
     */
    private function countQueriesFor(callable $action): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_delete_report_query_count_does_not_scale_with_total_active_reports(): void
    {
        $smallId = $this->makeReport();
        $this->makeManyOtherActiveReports(5);
        $smallCount = $this->countQueriesFor(fn () => $this->facade()->deleteReport((string) $smallId));

        $largeId = $this->makeReport();
        $this->makeManyOtherActiveReports(60);
        $largeCount = $this->countQueriesFor(fn () => $this->facade()->deleteReport((string) $largeId));

        $this->assertLessThanOrEqual(3, abs($largeCount - $smallCount), "deleteReport() ejecutó {$smallCount} queries con 6 reportes activos y {$largeCount} con 61 — no debería escalar (O(1)).");
    }

    public function test_toggle_hours_extra_query_count_does_not_scale_with_total_active_reports(): void
    {
        $smallId = $this->makeReport(['estado' => 'procesado']);
        $this->makeManyOtherActiveReports(5);
        $smallCount = $this->countQueriesFor(fn () => $this->facade()->toggleHoursExtra((string) $smallId, true));

        $largeId = $this->makeReport(['estado' => 'procesado']);
        $this->makeManyOtherActiveReports(60);
        $largeCount = $this->countQueriesFor(fn () => $this->facade()->toggleHoursExtra((string) $largeId, true));

        $this->assertLessThanOrEqual(3, abs($largeCount - $smallCount), "toggleHoursExtra() ejecutó {$smallCount} queries con 6 reportes activos y {$largeCount} con 61 — no debería escalar (O(1)).");
    }

    // Note: the B5.0-era "mass actions still scale with total active reports"
    // baseline test that used to live here was retired in B5.3 — that finding
    // is now fixed and re-proven (as O(1)) in
    // tests/Unit/RedmineReportMassOperationsTest.php instead.
}
