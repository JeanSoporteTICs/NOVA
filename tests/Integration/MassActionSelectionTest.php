<?php

namespace Tests\Integration;

use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use App\Modulos\RedmineMantencion\Services\MantencionDashboardService;
use App\Modulos\RedmineMantencion\Services\MantencionRedmineSyncService;
use App\Modulos\RedmineMantencion\Services\MantencionRetentionService;
use App\Services\Database\SchemaBaseline;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RedmineTic\Repositories\RedmineDataRepository;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MassActionSelectionTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $schema = new SchemaBaseline;
        $schema->bootstrap(DB::connection(), $schema->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
        DB::table('modulos_nova')->insert([
            ['id' => 1, 'clave_modulo' => 'redmine_tic', 'nombre' => 'TIC'],
            ['id' => 2, 'clave_modulo' => 'redmine-mantencion', 'nombre' => 'Mantención'],
        ]);
        foreach (['101' => ['Ana', 'Pérez'], '202' => ['Otra', 'Persona'], '0101' => ['Ana', 'Perez']] as $id => $name) {
            $userId = DB::table('usuarios_nova')->insertGetId(['uuid' => 'user-'.$id, 'usuario' => 'user'.$id,
                'redmine_id' => (string) $id, 'nombre' => $name[0], 'apellido' => $name[1],
                'password' => 'synthetic', 'rol' => 'usuario', 'estado' => 'activo']);
            DB::table('permisos_usuario_modulo')->insert(['usuario_id' => $userId, 'modulo_id' => 1, 'permitido' => 1]);
        }
        for ($id = 1; $id <= 30; $id++) {
            DB::table('redmine_tic_reportes')->insert(['id' => $id, 'modulo_id' => $id % 13 ? 1 : 2,
                'estado' => ['procesado', 'error', 'pendiente', 'archivado', ''][$id % 5],
                'asignado_a' => ['101', '202', '0101', null, '101 '][$id % 5],
                'asunto' => 'Report '.$id, 'descripcion' => 'Detail '.$id, 'mensaje' => 'Message '.$id,
                'creado_at' => '2026-09-01 12:00:00', 'actualizado_at' => '2026-09-01 12:00:00']);
        }
    }

    private function user(): array
    {
        return ['id' => '101', 'name' => 'Ana', 'apellido' => 'Pérez', 'legacy' => ['permisos' => ['mensajes' => 'asignados']]];
    }

    public function test_tic_authorization_matches_full_reader_for_scopes_order_duplicates_and_id_spellings(): void
    {
        $seed = (array) DB::table('redmine_tic_reportes')->where('id', 5)->first();
        DB::table('redmine_tic_reportes')->insert(array_replace($seed, ['id' => '9223372036854775808']));
        $ids = array_merge(['5', '005', '5 ', '+5', '5e0', "5' OR 1=1 --", '5', '9223372036854775808'], array_map('strval', range(30, 1)));
        foreach ([[], $this->user(), ['id' => '101', 'legacy' => ['permisos' => ['all' => true]]],
            ['id' => '0101', 'legacy' => ['permisos' => ['mensajes' => 'asignados']]],
            ['id' => 'none', 'legacy' => ['permisos' => ['mensajes' => 'asignados']]],
            ['id' => '', 'legacy' => ['permisos' => ['mensajes' => 'asignados']]]] as $user) {
            self::assertSame((new RedmineDataRepository)->filterAccessibleActiveReportIds($ids, $user),
                (new RedmineDataRepository)->filterAccessibleActiveReportIds($ids, $user, true));
        }
        $actual = (new RedmineDataRepository)->filterAccessibleActiveReportIds($ids, $this->user(), true);
        self::assertContains('9223372036854775808', $actual);
        self::assertNotContains('005', $actual);
        self::assertNotContains('5e0', $actual);
        self::assertSame(['5', '5'], array_slice($actual, 0, 2));
        foreach (['5', '005', '2', '13', '9223372036854775808', 'missing'] as $id) {
            $expected = (new RedmineDataRepository)->filterAccessibleActiveReportIds([trim($id)], $this->user()) === [trim($id)];
            self::assertSame($expected, (new RedmineDataRepository)->canAccessActiveReport($id, $this->user()));
        }
    }

    public function test_tic_projection_does_not_load_details_or_poison_cache_and_preserves_existing_cache(): void
    {
        $repo = new RedmineDataRepository;
        DB::enableQueryLog();
        self::assertSame(['5', '5'], $repo->filterAccessibleActiveReportIds(['5', '1', '5'], $this->user(), true));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $reportQueries = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'from `redmine_tic_reportes`')));
        self::assertCount(1, $reportQueries);
        self::assertStringContainsString('select `id`, `asignado_a`', $reportQueries[0]['query']);
        self::assertStringContainsString('id IN (', $reportQueries[0]['query']);
        self::assertGreaterThan(1, count($repo->activeReports()));
        DB::table('redmine_tic_reportes')->where('id', 5)->update(['asignado_a' => '202']);
        self::assertSame(['5'], $repo->filterAccessibleActiveReportIds(['5'], $this->user(), true), 'Keep an already loaded snapshot.');
        self::assertSame([], (new RedmineDataRepository)->filterAccessibleActiveReportIds(['5'], $this->user(), true));
        DB::flushQueryLog();
        DB::enableQueryLog();
        self::assertSame([], (new RedmineDataRepository)->filterAccessibleActiveReportIds([], $this->user(), true));
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_tic_failed_projection_falls_back_and_concurrent_reads_keep_one_snapshot(): void
    {
        $expected = (new RedmineDataRepository)->filterAccessibleActiveReportIds(['5', '1'], $this->user());
        $dispatcher = new Dispatcher(app());
        DB::connection()->setEventDispatcher($dispatcher);
        $failed = false;
        $dispatcher->listen(QueryExecuted::class, function ($event) use (&$failed): void {
            if (! $failed && str_starts_with($event->sql, 'select `id`, `asignado_a`')) {
                $failed = true;
                throw new \RuntimeException('Synthetic projection failure');
            }
        });
        self::assertSame($expected, (new RedmineDataRepository)->filterAccessibleActiveReportIds(['5', '1'], $this->user(), true));
        self::assertTrue($failed);
        $dispatcher = new Dispatcher(app());
        DB::connection()->setEventDispatcher($dispatcher);
        $changed = false;
        $dispatcher->listen(QueryExecuted::class, function ($event) use (&$changed): void {
            if (! $changed && str_starts_with($event->sql, 'select `id`, `asignado_a`')) {
                $changed = true;
                DB::connection('concurrent')->table('redmine_tic_reportes')->where('id', 5)->update(['asignado_a' => '202']);
            }
        });
        self::assertSame($expected, (new RedmineDataRepository)->filterAccessibleActiveReportIds(['5', '1'], $this->user(), true));
        self::assertTrue($changed);
        self::assertSame([], (new RedmineDataRepository)->filterAccessibleActiveReportIds(['5', '1'], $this->user(), true));
    }

    public function test_tic_archive_delete_and_reset_keep_the_same_database_effects_after_authorization(): void
    {
        DB::table('redmine_tic_reportes')->insert(['id' => 31, 'modulo_id' => 1, 'estado' => 'error', 'asignado_a' => '101']);
        Carbon::setTestNow('2026-09-23 12:00:00');
        try {
            foreach (['archiveReports', 'deleteReports', 'resetErrors'] as $action) {
                $snapshots = [];
                foreach ([false, true] as $projected) {
                    DB::beginTransaction();
                    $repo = new RedmineDataRepository;
                    $ids = $repo->filterAccessibleActiveReportIds(['5', '1', '2', '3', '5', '13', '31'], $this->user(), $projected);
                    $count = $repo->$action($ids);
                    $snapshots[] = [$ids, $count, DB::table('redmine_tic_reportes')->orderBy('id')->get()->all(),
                        DB::table('horas_extra_grupos')->get()->all(), DB::table('horas_extra_grupo_reportes')->get()->all()];
                    DB::rollBack();
                }
                self::assertEquals($snapshots[0], $snapshots[1], $action);
                self::assertNotEmpty($snapshots[1][0]);
                self::assertGreaterThan(0, $snapshots[1][1]);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Original archive loop used as an oracle for strict selection behavior. */
    private function originalArchive(array &$messages, array $ids, MantencionRetentionService $service): int
    {
        $ids = array_filter(array_map('trim', $ids));
        if (empty($ids)) {
            return 0;
        }
        $archived = 0;
        foreach ($messages as $key => $message) {
            if (! in_array(($message['id'] ?? ''), $ids, true)) {
                continue;
            }
            if (strtolower(trim((string) ($message['estado'] ?? ''))) !== 'procesado') {
                continue;
            }
            if (! $service->archive_message_record($message, 'manual')) {
                continue;
            }
            unset($messages[$key]);
            $archived++;
        }
        if ($archived > 0) {
            $messages = array_values($messages);
        }

        return $archived;
    }

    private function recordingRetention(): MantencionRetentionService
    {
        return new class extends MantencionRetentionService
        {
            public array $attempts = [];

            public function archive_message_record(array $message, string $archivedBy = 'retencion'): bool
            {
                $this->attempts[] = [$message, $archivedBy];

                return ! ($message['fail'] ?? false);
            }
        };
    }

    public function test_mantencion_archive_keeps_strict_ids_duplicate_reports_order_and_partial_failures(): void
    {
        $messages = [];
        foreach (['1', 1, '01', '0', 0, '', null, 'same', 'same', ' 1 ', 'failed', 'pending', 'upper', 'spaced'] as $index => $id) {
            $messages['key-'.$index] = ['id' => $id, 'estado' => match ($id) {
                'pending' => 'pendiente', 'upper' => 'PROCESADO', 'spaced' => ' procesado ', default => 'procesado',
            }, 'fail' => $id === 'failed', 'ordinal' => $index];
        }
        foreach ([[], ['missing'], ['failed'], ['1', '01', '0', 'same', 'same', 'failed', 'pending', 'upper', 'spaced']] as $ids) {
            $old = $new = $messages;
            $oldService = $this->recordingRetention();
            $newService = $this->recordingRetention();
            self::assertSame($this->originalArchive($old, $ids, $oldService), $newService->archive_selected_messages($new, $ids));
            self::assertSame($old, $new);
            self::assertSame($oldService->attempts, $newService->attempts);
        }
    }

    public function test_mass_selection_avoids_unrelated_report_text_and_repeated_id_scans(): void
    {
        $seed = (array) DB::table('redmine_tic_reportes')->where('id', 5)->first();
        for ($id = 1000; $id < 1400; $id++) {
            DB::table('redmine_tic_reportes')->insert(array_replace($seed, ['id' => $id,
                'descripcion' => str_repeat('Unrelated report. ', 3000)]));
        }
        $measure = static function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            $value = $read();

            return [$value, ['elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3), 'peak_bytes' => memory_get_peak_usage() - $memory]];
        };
        [$expected, $ticBefore] = $measure(fn () => (new RedmineDataRepository)->filterAccessibleActiveReportIds(['5', '1'], $this->user()));
        [$actual, $ticAfter] = $measure(fn () => (new RedmineDataRepository)->filterAccessibleActiveReportIds(['5', '1'], $this->user(), true));
        self::assertSame($expected, $actual);
        self::assertLessThan($ticBefore['peak_bytes'] / 5, $ticAfter['peak_bytes']);
        $messages = [];
        for ($id = 1; $id <= 12000; $id++) {
            $messages[] = ['id' => (string) $id, 'estado' => 'procesado'];
        }
        $ids = array_map('strval', range(6001, 12000));
        $old = $new = $messages;
        $oldService = $this->recordingRetention();
        $newService = $this->recordingRetention();
        [$expected, $mantencionBefore] = $measure(fn () => $this->originalArchive($old, $ids, $oldService));
        [$actual, $mantencionAfter] = $measure(fn () => $newService->archive_selected_messages($new, $ids));
        self::assertSame($expected, $actual);
        self::assertSame($oldService->attempts, $newService->attempts);
        file_put_contents(sys_get_temp_dir().'/nova-p06-mass-selection-metrics.json',
            json_encode(compact('ticBefore', 'ticAfter', 'mantencionBefore', 'mantencionAfter')), LOCK_EX);
    }

    public function test_mantencion_bulk_delete_and_reset_preserve_scope_csrf_responses_and_unselected_rows(): void
    {
        // Load only the helpers needed by the real service; no legacy bootstrap.
        $source = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/controllers/dashboard.php');
        foreach (['load_messages', 'save_messages', 'dashboard_filter_messages_by_scope', 'dashboard_accessible_message_ids',
            'dashboard_user_matches_assigned', 'dashboard_user_match_priority', 'dashboard_name_tokens_match', 'dashboard_normalize_text'] as $name) {
            $start = strpos($source, 'function '.$name.'(');
            self::assertNotFalse($start);
            $end = strpos($source, "\n}", $start) + 2;
            eval(substr($source, $start, $end - $start));
        }
        $source = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/controllers/storage.php');
        foreach (['mantencion_report_repository', 'mantencion_catalog_repository'] as $name) {
            $start = strpos($source, 'function '.$name.'(');
            $end = strpos($source, "\n    }", $start) + 6;
            eval(substr($source, $start, $end - $start));
        }
        eval('function auth_get_user_id() { return "101"; }
            function dashboard_current_user() { return ["id" => "101", "nombre" => "Ana", "apellido" => "Pérez"]; }
            function mantencion_current_user() { return []; }
            function load_user_api_token($id) { return "synthetic"; }
            function load_platform_config() { return []; }
            function maintenance_mode_enabled() { return true; }
            function csrf_validate() { $GLOBALS["mass_csrf"]++; }
            function auth_can($permission) { return $GLOBALS["mass_permission"]; }
            function dashboard_log_action($event, $detail) { $GLOBALS["mass_logs"][] = [$event, $detail]; }
            function dashboard_json_response($payload, $status) { throw new \\Tests\\Integration\\MassActionResponse($payload, $status); }');
        foreach ([['1', '101', 'error'], ['01', '101', 'procesado'], ['2', '202', 'error'], ['3', '101', 'error']] as $i => $row) {
            DB::table('redmine_mantencion_reportes')->insert(['id' => $i + 1, 'modulo_id' => 2, 'fuente' => 'manual',
                'fuente_id' => $row[0], 'id_redmine_asignado' => $row[1], 'estado' => $row[2],
                'asunto' => 'Report '.$i, 'descripcion' => 'Unchanged '.$i, 'actualizado_at' => '2026-09-01 12:00:00']);
        }
        $core = new MantencionCoreImportService;
        $service = new class($core, new MantencionRedmineSyncService($core), new MantencionRetentionService) extends MantencionDashboardService
        {
            public function dashboard_consume_flash(): ?string
            {
                return null;
            }

            public function dashboard_is_ajax_request(): bool
            {
                return true;
            }
        };
        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach (['delete_selected', 'reset_errors'] as $action) {
            foreach ([false, true] as $permitted) {
                DB::beginTransaction();
                $before = DB::table('redmine_mantencion_reportes')->orderBy('id')->get()->keyBy('id')->all();
                $GLOBALS['mass_permission'] = $permitted;
                $GLOBALS['mass_csrf'] = 0;
                $GLOBALS['mass_logs'] = [];
                $_POST = ['action' => $action, 'ids' => '1, 01,1,2,missing'];
                try {
                    $service->handle_request();
                    self::fail('Expected the JSON response.');
                } catch (MassActionResponse $response) {
                    self::assertSame(1, $GLOBALS['mass_csrf']);
                    self::assertSame($permitted ? 200 : 403, $response->status);
                    self::assertSame($permitted, $response->payload['ok']);
                    if ($permitted) {
                        self::assertSame(['1', '01', '1'], $response->payload['ids']);
                        self::assertCount(1, $GLOBALS['mass_logs']);
                        if ($action === 'delete_selected') {
                            self::assertSame(['pendiente' => 0, 'procesado' => 0, 'error' => 1],
                                $response->payload['counts']);
                        }
                    }
                }
                $after = DB::table('redmine_mantencion_reportes')->orderBy('id')->get()->keyBy('id')->all();
                if (! $permitted) {
                    self::assertEquals($before, $after);
                } else {
                    self::assertEquals($before[3], $after[3], 'Another user must remain unchanged.');
                    self::assertEquals($before[4], $after[4], 'An unselected report must remain unchanged.');
                    if ($action === 'delete_selected') {
                        self::assertSame([3, 4], array_keys($after));
                    } else {
                        self::assertSame('pendiente', $after[1]->estado);
                        self::assertSame($before[1]->descripcion, $after[1]->descripcion);
                        self::assertEquals($before[2], $after[2], 'A processed report must not reset.');
                    }
                }
                DB::rollBack();
            }
        }

        eval('function dashboard_can_access_message($messages, $id) {
            foreach ($messages as $message) {
                if (($message["id"] ?? "") === $id) return true;
            }
            return false;
        }');
        DB::beginTransaction();
        try {
            $GLOBALS['mass_permission'] = true;
            $GLOBALS['mass_csrf'] = 0;
            $GLOBALS['mass_logs'] = [];
            $_POST = ['action' => 'delete', 'id' => '1'];
            try {
                $service->handle_request();
                self::fail('Expected the JSON response.');
            } catch (MassActionResponse $response) {
                self::assertSame(200, $response->status);
                self::assertSame(['1'], $response->payload['ids']);
                self::assertSame(['pendiente' => 0, 'procesado' => 1, 'error' => 1],
                    $response->payload['counts']);
            }
        } finally {
            DB::rollBack();
        }
    }
}

final class MassActionResponse extends \RuntimeException
{
    public function __construct(public array $payload, public int $status)
    {
        parent::__construct('Synthetic JSON response');
    }
}
