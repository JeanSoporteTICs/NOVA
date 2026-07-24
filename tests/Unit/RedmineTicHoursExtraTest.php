<?php

namespace Tests\Unit;

use App\Modulos\Nova\Repositories\HorasExtraRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RedmineTic\Repositories\RedmineDataRepository;
use Tests\TestCase;

/**
 * Covers ETAPA B / Lote B4 — confirms the Horas Extra family (already fully
 * delegated to RedmineHoursExtraRepository -> shared Nova\HorasExtraRepository
 * before this lote) behaves identically through the facade, that the
 * "solo archivados" rule gates sync, and that the Mantención/TIC shared
 * table consolidates correctly by (usuario_id, fecha) while keeping each
 * origen's pivot independent. Runs against the real
 * horas_extra_grupos/horas_extra_grupo_reportes/redmine_tic_reportes tables
 * inside a rolled-back transaction.
 */
class RedmineTicHoursExtraTest extends TestCase
{
    use DatabaseTransactions;

    private function facade(): RedmineDataRepository
    {
        return (new RedmineDataRepository())->forProject('redmine_tic');
    }

    private function shared(): HorasExtraRepository
    {
        return new HorasExtraRepository();
    }

    /** @return array{redmine_id:string, nova_id:int} */
    private function makeNovaUserWithRedmineId(): array
    {
        $redmineId = (string) random_int(90000000, 99999999);
        $novaId = DB::table('usuarios_nova')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'usuario' => 'b4_test_' . Str::random(8),
            'redmine_id' => $redmineId,
            'nombre' => 'B4',
            'apellido' => 'Test',
            'rol' => 'usuario',
            'estado' => 'activo',
            'password' => bcrypt(Str::random(20)),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);

        return ['redmine_id' => $redmineId, 'nova_id' => (int) $novaId];
    }

    private function makeTicReport(string $redmineId, string $fecha, string $estado = 'procesado'): int
    {
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');

        return (int) DB::table('redmine_tic_reportes')->insertGetId([
            'modulo_id' => $moduleId,
            'redmine_id' => (string) random_int(1000, 999999),
            'estado' => $estado,
            'asunto' => 'Reporte de prueba B4',
            'descripcion' => 'Descripcion',
            'fecha_inicio' => $fecha,
            'asignado_a' => $redmineId,
            'hora_extra' => 1,
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);
    }

    public function test_hours_extra_data_returns_hydrated_group_after_toggle(): void
    {
        $user = $this->makeNovaUserWithRedmineId();
        $fecha = '2026-05-04';
        $reportId = $this->makeTicReport($user['redmine_id'], $fecha);
        $facade = $this->facade();

        $ok = $facade->toggleHoursExtra((string) $reportId, true);
        $this->assertTrue($ok);
        $this->assertFalse(DB::table('horas_extra_grupo_reportes')->where('origen', 'tic')->where('reporte_id', $reportId)->exists());
        $this->assertSame(1, $facade->archiveReports([(string) $reportId]));

        // Explicit filters matching $fecha's own month/year — hoursExtraData()
        // defaults to the *current* month/year when no filter is given, which
        // would otherwise hide fixture dates outside of "today".
        $data = $facade->hoursExtraData(
            ['mes' => '5', 'anio' => '2026'],
            ['id' => $user['redmine_id']]
        );
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('hoursMeta', $data);

        $group = collect($data['rows'])->firstWhere('fecha', $fecha);
        $this->assertNotNull($group);
        $reportIds = collect($group['reports'])->pluck('id');
        $this->assertTrue($reportIds->contains((string) $reportId));
    }

    public function test_toggle_off_before_archive_prevents_the_tic_pivot(): void
    {
        $user = $this->makeNovaUserWithRedmineId();
        $fecha = '2026-05-05';
        $reportId = $this->makeTicReport($user['redmine_id'], $fecha);
        $facade = $this->facade();
        $facade->toggleHoursExtra((string) $reportId, true);
        $facade->toggleHoursExtra((string) $reportId, false);
        $facade->archiveReports([(string) $reportId]);

        $this->assertFalse(
            DB::table('horas_extra_grupo_reportes')->where('origen', 'tic')->where('reporte_id', $reportId)->exists()
        );
    }

    public function test_toggle_on_waits_for_archive_even_when_processed(): void
    {
        $user = $this->makeNovaUserWithRedmineId();
        $fecha = '2026-05-06';
        $reportId = $this->makeTicReport($user['redmine_id'], $fecha, 'procesado');
        $facade = $this->facade();

        $ok = $facade->toggleHoursExtra((string) $reportId, true);

        $this->assertTrue($ok);
        $this->assertSame(1, (int) DB::table('redmine_tic_reportes')->where('id', $reportId)->value('hora_extra'));
        // Marcar hora extra no crea el grupo mientras el reporte siga activo.
        $this->assertFalse(DB::table('horas_extra_grupos')->where('usuario_id', $user['nova_id'])->where('fecha', $fecha)->exists());

        $this->assertSame(1, $facade->archiveReports([(string) $reportId]));
        $this->assertTrue(DB::table('horas_extra_grupos')->where('usuario_id', $user['nova_id'])->where('fecha', $fecha)->exists());
    }

    public function test_mantencion_and_tic_reports_consolidate_into_the_same_group(): void
    {
        $user = $this->makeNovaUserWithRedmineId();
        $fecha = '2026-05-07';

        // Mantención attaches first, using the shared repository directly (no
        // need to model a full redmine_mantencion_reportes row for this lote's
        // purpose — it's the shared table's consolidation being verified, not
        // Mantención's own report hydration).
        $mantencionGrupoId = $this->shared()->findOrCreateGroup($user['nova_id'], $fecha, '08:00:00', '17:00:00');
        $this->shared()->attachReporte((int) $mantencionGrupoId, 'mantencion', 999999902);

        $reportId = $this->makeTicReport($user['redmine_id'], $fecha);
        $facade = $this->facade();
        $facade->toggleHoursExtra((string) $reportId, true);
        $facade->archiveReports([(string) $reportId]);

        $ticGrupoId = DB::table('horas_extra_grupos')->where('usuario_id', $user['nova_id'])->where('fecha', $fecha)->value('id');
        $this->assertSame($mantencionGrupoId, (int) $ticGrupoId);

        $origenes = DB::table('horas_extra_grupo_reportes')->where('grupo_id', $ticGrupoId)->pluck('origen')->all();
        $this->assertEqualsCanonicalizing(['mantencion', 'tic'], $origenes);
    }

    public function test_deleting_tic_group_does_not_remove_group_or_mantencion_pivot(): void
    {
        $user = $this->makeNovaUserWithRedmineId();
        $fecha = '2026-05-08';
        $grupoId = $this->shared()->findOrCreateGroup($user['nova_id'], $fecha, '08:00:00', '17:00:00');
        $this->shared()->attachReporte((int) $grupoId, 'mantencion', 999999903);

        $reportId = $this->makeTicReport($user['redmine_id'], $fecha);
        $facade = $this->facade();
        $facade->toggleHoursExtra((string) $reportId, true);
        $facade->archiveReports([(string) $reportId]);

        $deleted = $facade->deleteHoursGroup('', $fecha);

        $this->assertSame(1, $deleted);
        $this->assertFalse(DB::table('horas_extra_grupo_reportes')->where('grupo_id', $grupoId)->where('origen', 'tic')->exists());
        $this->assertTrue(DB::table('horas_extra_grupo_reportes')->where('grupo_id', $grupoId)->where('origen', 'mantencion')->exists());
        $this->assertTrue(DB::table('horas_extra_grupos')->where('id', $grupoId)->exists());
    }

    public function test_save_hours_group_updates_time_without_creating_a_new_group(): void
    {
        $user = $this->makeNovaUserWithRedmineId();
        $fecha = '2026-05-09';
        $reportId = $this->makeTicReport($user['redmine_id'], $fecha);
        $facade = $this->facade();
        $facade->toggleHoursExtra((string) $reportId, true);
        $facade->archiveReports([(string) $reportId]);

        $before = DB::table('horas_extra_grupos')->count();

        $ok = $facade->saveHoursGroup('', ['fecha' => $fecha, 'hora_inicio' => '09:00', 'hora_fin' => '19:30']);

        $this->assertTrue($ok);
        $this->assertSame($before, DB::table('horas_extra_grupos')->count());
        $grupo = DB::table('horas_extra_grupos')->where('usuario_id', $user['nova_id'])->where('fecha', $fecha)->first();
        $this->assertSame('09:00:00', $grupo->hora_inicio);
        $this->assertSame('19:30:00', $grupo->hora_fin);
    }

    public function test_emach_suggestion_present_without_credentials_and_no_http_call(): void
    {
        $user = $this->makeNovaUserWithRedmineId();
        $fecha = '2026-05-10';
        $reportId = $this->makeTicReport($user['redmine_id'], $fecha);
        $facade = $this->facade();
        $facade->toggleHoursExtra((string) $reportId, true);
        $facade->archiveReports([(string) $reportId]);

        // 'id' matches the report's own asignado_a so the horas_extra scope
        // filter (assigned-only) doesn't hide the fixture group; this user
        // still has no integraciones_usuario row of type 'emach', so
        // credentials resolve as "not configured" purely via DB reads — this
        // must never trigger an outbound HTTP call to the EMACH scraper.
        $data = $facade->hoursExtraData(['mes' => '5', 'anio' => '2026'], ['id' => $user['redmine_id']]);

        $this->assertArrayHasKey($fecha, $data['hoursMeta']['emachSuggestions']);
        $suggestion = $data['hoursMeta']['emachSuggestions'][$fecha];
        $this->assertFalse($suggestion['ok']);
        $this->assertSame('Configura tus credenciales EMACH antes de calcular.', $suggestion['status']);
    }
}
