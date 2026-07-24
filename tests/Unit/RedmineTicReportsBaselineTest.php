<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RedmineTic\Repositories\RedmineDataRepository;
use Tests\TestCase;

/**
 * ETAPA B / Lote B5.0 — baseline coverage for the Reportes family in
 * RedmineDataRepository, taken BEFORE any extraction. No production code is
 * touched in this lote; these tests exist to catch any behavior drift once
 * B5.1-B5.5 start moving code. Runs against the real redmine_tic_reportes
 * table inside a rolled-back transaction.
 *
 * Key finding this baseline locks in: deleteReports()/archiveReports()/
 * resetErrors() are "mass" by contract but implemented today via
 * activeReports() + saveActiveReports() (rewrite every active row + delete
 * by difference) rather than a targeted WHERE id IN (...). The tests below
 * assert the *outcome* (only selected ids change), not the query count —
 * B5.3 is expected to change the implementation, not this behavior.
 */
class RedmineTicReportsBaselineTest extends TestCase
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
            'asunto' => 'Reporte base B5.0',
            'descripcion' => 'Descripcion base',
            'fecha_inicio' => '2026-06-01',
            'redmine_id' => null,
            'hora_extra' => 0,
            'origen' => 'manual',
            'creado_at' => now(),
            'actualizado_at' => now(),
        ], $overrides));
    }

    public function test_active_reports_returns_only_non_archived_reports(): void
    {
        $activeId = $this->makeReport(['estado' => 'pendiente']);
        $archivedId = $this->makeReport(['estado' => 'archivado']);

        $ids = collect($this->facade()->activeReports())->pluck('id');

        $this->assertTrue($ids->contains((string) $activeId));
        $this->assertFalse($ids->contains((string) $archivedId));
    }

    public function test_archived_reports_returns_only_archived_reports(): void
    {
        $archivedId = $this->makeReport(['estado' => 'archivado']);

        $ids = collect($this->facade()->archivedReports())->pluck('id');

        $this->assertTrue($ids->contains((string) $archivedId));
    }

    public function test_active_reports_cache_is_invalidated_after_update_report(): void
    {
        $id = $this->makeReport(['asunto' => 'Original']);
        $facade = $this->facade();

        $before = collect($facade->activeReports())->firstWhere('id', (string) $id);
        $this->assertSame('Original', $before['asunto']);

        $facade->updateReport(['id' => (string) $id, 'asunto' => 'Editado']);

        $after = collect($facade->activeReports())->firstWhere('id', (string) $id);
        $this->assertSame('Editado', $after['asunto']);
    }

    public function test_database_report_mapping_handles_null_and_typed_fields(): void
    {
        $id = $this->makeReport([
            'tiempo_estimado' => null,
            'redmine_id' => null,
            'estado_redmine' => null,
            'hora_extra' => 0,
        ]);

        $report = collect($this->facade()->activeReports())->firstWhere('id', (string) $id);

        $this->assertNotNull($report);
        $this->assertSame('', $report['tiempo_estimado']);
        $this->assertSame('', $report['redmine_id']);
        $this->assertSame('NO', $report['hora_extra']);
        $this->assertSame('', $report['estado_redmine']);
    }

    public function test_update_report_contract_is_bool_and_rejects_missing_id(): void
    {
        $id = $this->makeReport();
        $facade = $this->facade();

        $this->assertTrue($facade->updateReport(['id' => (string) $id, 'asunto' => 'Cambiado']));
        $this->assertFalse($facade->updateReport(['id' => '']));
        $this->assertFalse($facade->updateReport(['id' => '99999999']));
    }

    public function test_delete_report_is_punctual_and_returns_int(): void
    {
        $keepId = $this->makeReport(['asunto' => 'Se mantiene']);
        $deleteId = $this->makeReport(['asunto' => 'Se borra']);
        $facade = $this->facade();

        $deleted = $facade->deleteReport((string) $deleteId);

        $this->assertSame(1, $deleted);
        $this->assertFalse(DB::table('redmine_tic_reportes')->where('id', $deleteId)->exists());
        $this->assertTrue(DB::table('redmine_tic_reportes')->where('id', $keepId)->where('asunto', 'Se mantiene')->exists());
    }

    public function test_delete_reports_mass_action_removes_only_selected_ids(): void
    {
        $keepId = $this->makeReport();
        $deleteId1 = $this->makeReport();
        $deleteId2 = $this->makeReport();
        $facade = $this->facade();

        $deleted = $facade->deleteReports([(string) $deleteId1, (string) $deleteId2]);

        $this->assertSame(2, $deleted);
        $this->assertTrue(DB::table('redmine_tic_reportes')->where('id', $keepId)->exists());
        $this->assertFalse(DB::table('redmine_tic_reportes')->where('id', $deleteId1)->exists());
        $this->assertFalse(DB::table('redmine_tic_reportes')->where('id', $deleteId2)->exists());
    }

    public function test_archive_reports_mass_action_moves_only_selected_to_archived(): void
    {
        $keepId = $this->makeReport(['estado' => 'pendiente']);
        $archiveId = $this->makeReport(['estado' => 'pendiente']);
        $facade = $this->facade();

        $archived = $facade->archiveReports([(string) $archiveId]);

        $this->assertSame(1, $archived);
        $this->assertSame('archivado', DB::table('redmine_tic_reportes')->where('id', $archiveId)->value('estado'));
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $keepId)->value('estado'));
    }

    public function test_reset_errors_only_affects_reports_actually_in_error_state(): void
    {
        $errorId = $this->makeReport(['estado' => 'error']);
        $pendingId = $this->makeReport(['estado' => 'pendiente']);
        $facade = $this->facade();

        $updated = $facade->resetErrors([(string) $errorId, (string) $pendingId]);

        $this->assertSame(1, $updated);
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $errorId)->value('estado'));
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $pendingId)->value('estado'));
    }

    public function test_send_reports_to_redmine_without_token_short_circuits_before_http(): void
    {
        $id = $this->makeReport();
        $facade = $this->facade();

        // No integraciones_usuario row of type redmine_tic exists for this
        // fake user id, so userApiToken() resolves to '' — the contract
        // requires this to short-circuit before any outbound HTTP call.
        $result = $facade->sendReportsToRedmine([(string) $id], 'no-such-user-b5');

        $this->assertSame(['attempts', 'success', 'errors', 'redmine_ids'], array_keys($result));
        $this->assertSame(0, $result['success']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $id)->value('estado'));
    }

    public function test_toggle_hours_extra_is_punctual_and_returns_bool(): void
    {
        $keepId = $this->makeReport(['estado' => 'procesado']);
        $toggleId = $this->makeReport(['estado' => 'procesado']);
        $facade = $this->facade();

        $ok = $facade->toggleHoursExtra((string) $toggleId, true);

        $this->assertTrue($ok);
        $this->assertSame(1, (int) DB::table('redmine_tic_reportes')->where('id', $toggleId)->value('hora_extra'));
        $this->assertSame(0, (int) DB::table('redmine_tic_reportes')->where('id', $keepId)->value('hora_extra'));
    }

    public function test_delete_archived_report_only_deletes_archived_state(): void
    {
        $archivedId = $this->makeReport(['estado' => 'archivado']);
        $pendingId = $this->makeReport(['estado' => 'pendiente']);
        $facade = $this->facade();

        // Contract: deleteArchivedReport() must not delete a non-archived row.
        $this->assertSame(0, $facade->deleteArchivedReport((string) $pendingId));
        $this->assertSame(1, $facade->deleteArchivedReport((string) $archivedId));
        $this->assertTrue(DB::table('redmine_tic_reportes')->where('id', $pendingId)->exists());
        $this->assertFalse(DB::table('redmine_tic_reportes')->where('id', $archivedId)->exists());
    }
}
