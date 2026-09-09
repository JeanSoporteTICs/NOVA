<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RedmineTic\Repositories\RedmineDataRepository;
use Tests\TestCase;

/**
 * ETAPA B / Lote B5.6 Parte 2 — covers the rewrite of sendReportsToRedmine()
 * from "load every active report, mutate the matching ones in memory, then
 * rewrite the WHOLE active set via saveActiveReports()" to a targeted fetch
 * (RedmineReportRepository::findActiveByIds()) plus a punctual per-report
 * persist (updateActiveFields() via the new persistSentReport() helper).
 *
 * HTTP responses are simulated with Http::fake and stray requests are
 * blocked. Database fixtures exercise targeted persistence, availability
 * failures before sending, and interruption after a failed POST.
 */
class RedmineSendReportsTargetedPersistenceTest extends TestCase
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

    /**
     * Creates a real usuarios_nova + integraciones_usuario(redmine) row
     * so userApiToken() resolves to a non-empty value, letting the send flow
     * proceed past the "no token" short-circuit.
     */
    private function makeUserWithToken(string $token): string
    {
        $id = (string) random_int(500000, 599999);
        $this->facade()->saveUser([
            '_creating' => true,
            'id' => $id,
            'rut_sin_dv' => 'b56' . $id,
            'nombre' => 'Envio',
            'apellido' => 'B56',
            'api' => $token,
        ]);

        return $id;
    }

    private function unreachablePlatformConfig(): void
    {
        $this->facade()->saveConfiguration(['platform_url' => 'http://invalid.invalid/issues.json']);
    }

    public function test_send_reports_with_empty_selection_attempts_nothing(): void
    {
        $result = $this->facade()->sendReportsToRedmine([], 'no-such-user-b56');

        $this->assertSame(0, $result['attempts']);
        $this->assertSame(0, $result['success']);
    }

    public function test_send_reports_only_counts_selected_ids_that_are_actually_active(): void
    {
        $targetId = $this->makeReport();
        $this->makeReport(); // another active report, not selected

        $result = $this->facade()->sendReportsToRedmine([(string) $targetId, 'not-numeric', '999999999'], 'no-such-user-b56');

        $this->assertSame(1, $result['attempts']);
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $targetId)->value('estado'));
    }

    public function test_send_reports_does_not_count_a_report_from_another_module(): void
    {
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

        $result = $this->facade()->sendReportsToRedmine([(string) $otherReportId], 'no-such-user-b56');

        $this->assertSame(0, $result['attempts']);
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $otherReportId)->value('estado'));
    }

    /**
     * Single, consolidated test covering every assertion that requires
     * actually reaching the per-report HTTP-attempt loop (unreachable from
     * the "no token" short-circuit): targeted persistence on failure, other
     * active reports left untouched, no delete-by-difference, and full
     * activity-log preservation (including envio_redmine_resumen, which is
     * only appended after the loop completes).
     */
    public function test_send_reports_attempt_loop_persists_only_the_targeted_report_and_preserves_logs(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['issues' => []], 200)->push([], 503);
        $userId = $this->makeUserWithToken('token-b56');
        $this->unreachablePlatformConfig();
        $targetId = $this->makeReport(['estado' => 'pendiente']);
        $otherId = $this->makeReport(['estado' => 'pendiente']);
        $totalBefore = DB::table('redmine_tic_reportes')->where('modulo_id', $this->moduleId())->count();

        $result = $this->facade()->sendReportsToRedmine([(string) $targetId], $userId);

        $this->assertSame(1, $result['attempts']);
        $this->assertSame(0, $result['success']);
        $this->assertNotEmpty($result['errors']);

        // Targeted persistence: only the attempted report changed state.
        $this->assertSame('error', DB::table('redmine_tic_reportes')->where('id', $targetId)->value('estado'));
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $otherId)->value('estado'));

        // No delete-by-difference: row count in the module is unchanged.
        $totalAfter = DB::table('redmine_tic_reportes')->where('modulo_id', $this->moduleId())->count();
        $this->assertSame($totalBefore, $totalAfter);

        // Activity log fully preserved, including the post-loop summary
        // entry that only fires once the whole batch has been attempted.
        $moduleId = $this->moduleId();
        $this->assertTrue(DB::table('tic_log')->where('modulo_id', $moduleId)->where('evento', 'envio_redmine_error')->exists());
        $this->assertTrue(DB::table('tic_log')->where('modulo_id', $moduleId)->where('evento', 'envio_redmine_resumen')->exists());
    }
    public function test_unavailable_redmine_leaves_selected_reports_unchanged(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 503)]);
        $userId = $this->makeUserWithToken('token-unavailable');
        $this->unreachablePlatformConfig();
        $first = $this->makeReport();
        $second = $this->makeReport();
        $result = $this->facade()->sendReportsToRedmine([(string) $first, (string) $second], $userId);
        $this->assertSame(0, $result['attempts']);
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $first)->value('estado'));
        $this->assertSame('pendiente', DB::table('redmine_tic_reportes')->where('id', $second)->value('estado'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_batch_stops_after_redmine_fails_during_first_post(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['issues' => []], 200)->push([], 503);
        $userId = $this->makeUserWithToken('token-stop');
        $this->unreachablePlatformConfig();
        $first = $this->makeReport();
        $second = $this->makeReport();
        $result = $this->facade()->sendReportsToRedmine([(string) $first, (string) $second], $userId);
        $this->assertSame(1, $result['attempts']);
        $states = DB::table('redmine_tic_reportes')->whereIn('id', [$first, $second])->pluck('estado')->all();
        $this->assertEqualsCanonicalizing(['error', 'pendiente'], $states);
        $this->assertStringContainsString('evitar duplicados', $result['errors'][0]);
        Http::assertSentCount(2);
    }
}
