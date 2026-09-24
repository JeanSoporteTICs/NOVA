<?php

namespace Tests\Integration;

use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;
use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use App\Modulos\RedmineMantencion\Services\MantencionDashboardService;
use App\Modulos\RedmineMantencion\Services\MantencionRedmineSyncService;
use App\Modulos\RedmineMantencion\Services\MantencionRetentionService;
use App\Services\Database\SchemaBaseline;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MantencionBulkReadQueryTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $schema = new SchemaBaseline;
        $schema->bootstrap(DB::connection(), $schema->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
        DB::statement('SET timestamp = 1790190000');
        DB::table('modulos_nova')->insert(['id' => 2, 'clave_modulo' => 'redmine-mantencion', 'nombre' => 'Mantención']);
        foreach (['101', '202'] as $id) {
            DB::table('usuarios_nova')->insert(['uuid' => 'u-'.$id, 'usuario' => 'u'.$id,
                'redmine_id' => $id, 'nombre' => 'User '.$id, 'apellido' => 'Test', 'password' => 'synthetic']);
        }
        foreach ([
            ['one', 'error', '101'], ['01', 'pendiente', '101'], ['recent', 'procesado', '101'],
            ['expired', 'procesado', '202'], ['unselected', 'error', '101'], ['hidden', 'error', '202'],
            ['repeated', 'error', '101'], ['repeated', 'error', '101'], ['0', 'procesado', '101'],
            ['', 'pendiente', '101'], ['archived', 'archivado', '101'],
        ] as $i => $values) {
            DB::table('redmine_mantencion_reportes')->insert(['id' => $i + 1, 'modulo_id' => 2,
                'fuente' => $i % 2 ? 'core' : 'manual', 'fuente_id' => $values[0], 'estado' => $values[1],
                'id_redmine_asignado' => $values[2], 'asignado_nombre' => 'User '.$values[2].' Test',
                'asunto' => 'Subject '.$i, 'descripcion' => 'Description '.$i, 'correo' => 'synthetic'.$i.'@example.test',
                'fecha_reporte' => '2026-09-01', 'fecha_inicio' => '2026-09-01', 'hora_reporte' => '18:30:00',
                'hora_extra' => 1, 'tiempo_estimado' => 1, 'numero_ticket_redmine' => 1000 + $i,
                'actualizado_at' => $i === 3 ? '2020-01-01 00:00:00' : '2030-01-01 00:00:00']);
        }
        $source = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/controllers/dashboard.php');
        foreach (['load_messages', 'save_messages', 'dashboard_filter_messages_by_scope', 'dashboard_accessible_message_ids', 'dashboard_can_access_message',
            'dashboard_user_matches_assigned', 'dashboard_user_match_priority', 'dashboard_name_tokens_match', 'dashboard_normalize_text',
            'parse_message_timestamp', 'normalize_hour_extra_value', 'message_has_hora_extra', 'append_hours_extra_record',
            'dashboard_expand_manual_message', 'parse_issue_date'] as $name) {
            $start = strpos($source, 'function '.$name.'(');
            self::assertNotFalse($start);
            $end = strpos($source, "\n}", $start) + 2;
            eval(substr($source, $start, $end - $start));
        }
        foreach (['dashboard_update_message_hora_extra', 'remove_hours_extra_record_by_id'] as $name) {
            $start = strpos($source, 'function '.$name.'(');
            self::assertNotFalse($start);
            $end = strpos($source, "\n}", $start) + 2;
            eval(substr($source, $start, $end - $start));
        }
        $source = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/controllers/storage.php');
        foreach (['mantencion_report_repository', 'mantencion_catalog_repository', 'mantencion_hours_extra_repository'] as $name) {
            $start = strpos($source, 'function '.$name.'(');
            $end = strpos($source, "\n    }", $start) + 6;
            eval(substr($source, $start, $end - $start));
        }
        eval('function auth_get_user_id() { return "101"; }
            function dashboard_current_user() { return ["id" => "101", "nombre" => "User 101", "apellido" => "Test"]; }
            function mantencion_current_user() { return []; }
            function load_user_api_token($id) { return "synthetic"; }
            function load_platform_config() { return ["retencion_horas" => 24]; }
            function maintenance_mode_enabled() { return $GLOBALS["bulk_maintenance"]; }
            function csrf_validate() { $GLOBALS["bulk_csrf"]++; }
            function auth_can($permission) { return $GLOBALS["bulk_permission"]; }
            function dashboard_can_assign_other_users() { return false; }
            function dashboard_current_user_full_name() { return "User 101 Test"; }
            function dashboard_find_user_name($id) { return $id === "101" ? "User 101 Test" : ""; }
            function dashboard_log_action($event, $detail) { $GLOBALS["bulk_logs"][] = [$event, $detail]; }
            function dashboard_set_flash($message) { $GLOBALS["bulk_flash"] = $message; }
            function dashboard_json_response($payload, $status) { throw new \\Tests\\Integration\\BulkReadResponse($payload, $status); }');
        $GLOBALS['bulk_permission'] = true;
        $GLOBALS['bulk_maintenance'] = false;
        $GLOBALS['bulk_csrf'] = 0;
        $GLOBALS['bulk_logs'] = [];
        $GLOBALS['bulk_flash'] = null;
    }

    private function service(bool $projected = true): MantencionDashboardService
    {
        $core = new MantencionCoreImportService;
        $service = new class($core, new MantencionRedmineSyncService($core), new MantencionRetentionService) extends MantencionDashboardService
        {
            public bool $projected = true;

            public function messagesForRequest(string $method, ?\DateTimeImmutable $threshold = null, ?array $bulkIds = null, bool $withDatabaseId = false): array
            {
                return parent::messagesForRequest($method, $threshold, $this->projected ? $bulkIds : null, $withDatabaseId);
            }

            public function dashboard_consume_flash(): ?string
            {
                return null;
            }

            public function dashboard_is_ajax_request(): bool
            {
                return true;
            }

            public function dashboard_redirect_back(): RedirectResponse
            {
                return new RedirectResponse('/synthetic');
            }
        };
        $service->projected = $projected;

        return $service;
    }

    public function test_projection_preserves_scope_counts_order_duplicate_ids_and_every_write_target(): void
    {
        $repo = app(MantencionReportRepository::class);
        $all = $repo->activeMessages();
        $service = $this->service();
        foreach ([[], [' one ', 'repeated', '0'], array_column($all, 'id')] as $ids) {
            $actual = $service->messagesForRequest('POST', null, $ids);
            self::assertSame(array_column($all, 'id'), array_column($actual, 'id'));
            self::assertSame($service->dashboard_status_counts(dashboard_filter_messages_by_scope($all)),
                $service->dashboard_status_counts(dashboard_filter_messages_by_scope($actual)));
            self::assertSame(dashboard_accessible_message_ids($all), dashboard_accessible_message_ids($actual));
            foreach ($all as $i => $row) {
                if (strtolower($row['estado']) === 'procesado' || in_array($row['id'], array_map('trim', $ids), true)) {
                    self::assertSame($row, $actual[$i]);
                } else {
                    self::assertSame(array_intersect_key($row, $actual[$i]), $actual[$i]);
                    self::assertArrayNotHasKey('descripcion', $actual[$i]);
                    self::assertArrayNotHasKey('_dashboard_id', $actual[$i]);
                }
            }
        }
        self::assertSame($all, $service->messagesForRequest('POST'));
        self::assertSame($all, $service->messagesForRequest('PUT', null, ['one']));
        $individual = $repo->bulkActionMessages(['one'], false);
        self::assertSame(array_column($all, 'id'), array_column($individual, 'id'));
        self::assertSame('Description 0', array_column($individual, null, 'id')['one']['descripcion']);
        self::assertArrayNotHasKey('descripcion', array_column($individual, null, 'id')['expired']);
    }

    public function test_projection_keeps_unsigned_ids_and_empty_source_ids_separate(): void
    {
        $seed = (array) DB::table('redmine_mantencion_reportes')->where('id', 1)->first();
        foreach (['9223372036854775807', '9223372036854775808'] as $id) {
            DB::table('redmine_mantencion_reportes')->insert(array_replace($seed,
                ['id' => $id, 'fuente_id' => '', 'descripcion' => 'Description '.$id]));
        }
        $repo = app(MantencionReportRepository::class);
        $actual = $repo->bulkActionMessages(['9223372036854775808']);
        self::assertSame(array_column($repo->activeMessages(), 'id'), array_column($actual, 'id'));
        $rows = array_column($actual, null, 'id');
        self::assertSame('Description 9223372036854775808', $rows['9223372036854775808']['descripcion']);
        self::assertArrayNotHasKey('descripcion', $rows['9223372036854775807']);
    }

    public function test_projection_fallback_and_snapshot_do_not_leave_partial_write_targets(): void
    {
        $repo = app(MantencionReportRepository::class);
        $all = $repo->activeMessages();
        $dispatcher = new Dispatcher(app());
        DB::connection()->setEventDispatcher($dispatcher);
        $failed = false;
        $dispatcher->listen(QueryExecuted::class, function ($event) use (&$failed): void {
            if (! $failed && str_contains($event->sql, 'r.id IN (')) {
                $failed = true;
                throw new \RuntimeException('Synthetic detail read failure');
            }
        });
        self::assertSame($all, $repo->bulkActionMessages(['one']));
        self::assertTrue($failed);
        DB::connection()->unsetEventDispatcher();
        $expected = $repo->bulkActionMessages(['one']);
        $dispatcher = new Dispatcher(app());
        DB::connection()->setEventDispatcher($dispatcher);
        $changed = false;
        $dispatcher->listen(QueryExecuted::class, function ($event) use (&$changed): void {
            if (! $changed && str_starts_with($event->sql, 'select `r`.`id`, `r`.`fuente`')) {
                $changed = true;
                DB::connection('concurrent')->table('redmine_mantencion_reportes')->where('id', 1)
                    ->update(['estado' => 'procesado', 'descripcion' => 'Concurrent change']);
            }
        });
        self::assertSame($expected, $repo->bulkActionMessages(['one']));
        self::assertTrue($changed);
        $next = array_column($repo->bulkActionMessages(['one']), null, 'id');
        self::assertSame('Concurrent change', $next['one']['descripcion']);
    }

    private function snapshot(): array
    {
        // Auto-increment IDs advance across rolled-back comparison runs. Compare
        // groups by owner/date and links by that identity instead of sequence IDs.
        $groups = [];
        $groupKeys = [];
        foreach (DB::table('horas_extra_grupos')->orderBy('usuario_id')->orderBy('fecha')->get() as $row) {
            $data = (array) $row;
            $key = $row->usuario_id.'|'.$row->fecha;
            $groupKeys[$row->id] = $key;
            unset($data['id']);
            $groups[$key] = $data;
        }
        $links = [];
        foreach (DB::table('horas_extra_grupo_reportes')->orderBy('origen')->orderBy('reporte_id')->get() as $row) {
            $data = (array) $row;
            unset($data['id']);
            $data['grupo_id'] = $groupKeys[$row->grupo_id];
            $links[] = $data;
        }

        return [DB::table('redmine_mantencion_reportes')->orderBy('id')->get()->toArray(), $groups, $links,
            DB::table('mantencion_log')->orderBy('id')->get()->toArray()];
    }

    private function runPost(bool $projected, string $action, string $ids): array
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = in_array($action, ['update', 'toggle_hora_extra', 'delete'], true)
            ? ['action' => $action, 'id' => trim(explode(',', $ids)[0] ?? '')]
            : ['action' => $action, 'ids' => $ids];
        if ($action === 'update') {
            $_POST += ['asunto' => 'Updated subject', 'descripcion' => 'Updated description', 'fecha' => '23-09-2026'];
        }
        $GLOBALS['bulk_csrf'] = 0;
        $GLOBALS['bulk_logs'] = [];
        $GLOBALS['bulk_flash'] = null;
        DB::beginTransaction();
        try {
            try {
                $response = $this->service($projected)->handle_request();
                if ($action === 'process_selected') {
                    return [$response->getStatusCode(), [
                        'location' => $response->headers->get('Location'),
                        'flash' => $GLOBALS['bulk_flash'],
                    ], $GLOBALS['bulk_csrf'], $GLOBALS['bulk_logs'], $this->snapshot()];
                }
                self::fail('Expected the JSON response.');
            } catch (BulkReadResponse $response) {
                return [$response->status, $response->payload, $GLOBALS['bulk_csrf'], $GLOBALS['bulk_logs'], $this->snapshot()];
            }
        } finally {
            DB::rollBack();
        }
    }

    public function test_three_real_posts_match_full_reader_including_retention_hours_permissions_and_counts(): void
    {
        Carbon::setTestNow('2026-09-23 12:00:00');
        try {
            foreach (['process_selected', 'archive_selected', 'delete_selected', 'reset_errors', 'update', 'delete', 'toggle_hora_extra'] as $action) {
                foreach ([false, true] as $maintenance) {
                    $GLOBALS['bulk_maintenance'] = $maintenance;
                    foreach ([false, true] as $permission) {
                        $GLOBALS['bulk_permission'] = $permission;
                        $ids = 'one, 01,recent,repeated,hidden,0,10,missing,one';
                        $expected = $this->runPost(false, $action, $ids);
                        $actual = $this->runPost(true, $action, $ids);
                        self::assertEquals($expected, $actual, $action);
                        self::assertSame(1, $actual[2]);
                        self::assertSame($permission && $action === 'process_selected' ? 302 : ($permission ? 200 : 403), $actual[0]);
                        if (! $maintenance) {
                            self::assertSame('archivado', array_column($actual[4][0], null, 'id')[4]->estado, 'Retention still archives another user before the action.');
                            self::assertNotEmpty($actual[4][2], 'Retention must keep the hours links.');
                        }
                    }
                }
                $GLOBALS['bulk_permission'] = true;
                $GLOBALS['bulk_maintenance'] = true;
                self::assertEquals($this->runPost(false, $action, ''), $this->runPost(true, $action, ''));
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failed_archives_and_resets_keep_identical_partial_success_and_rollback(): void
    {
        Carbon::setTestNow('2026-09-23 12:00:00');
        DB::unprepared("CREATE TRIGGER reject_bulk_report BEFORE UPDATE ON redmine_mantencion_reportes FOR EACH ROW BEGIN IF OLD.fuente_id IN ('one','recent') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic write failure'; END IF; END");
        try {
            foreach (['archive_selected', 'reset_errors'] as $action) {
                self::assertEquals($this->runPost(false, $action, 'one,recent,repeated'), $this->runPost(true, $action, 'one,recent,repeated'));
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unselected_pending_details_are_not_loaded_for_bulk_posts(): void
    {
        $seed = (array) DB::table('redmine_mantencion_reportes')->where('id', 2)->first();
        for ($id = 1000; $id < 1400; $id++) {
            DB::table('redmine_mantencion_reportes')->insert(array_replace($seed, ['id' => $id,
                'fuente_id' => 'unselected-'.$id, 'descripcion' => str_repeat('Unselected report detail. ', 2000)]));
        }
        $measure = static function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            $value = $read();

            return [$value, ['elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3), 'peak_bytes' => memory_get_peak_usage() - $memory]];
        };
        [$all, $before] = $measure(fn () => $this->service()->messagesForRequest('POST'));
        [$actual, $after] = $measure(fn () => $this->service()->messagesForRequest('POST', null, ['one']));
        self::assertSame(array_column($all, 'id'), array_column($actual, 'id'));
        self::assertSame($this->service()->dashboard_status_counts($all), $this->service()->dashboard_status_counts($actual));
        self::assertLessThan($before['peak_bytes'] / 5, $after['peak_bytes']);
        DB::enableQueryLog();
        $this->service()->messagesForRequest('POST', null, ['one']);
        $details = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'select `r`.*')));
        DB::disableQueryLog();
        self::assertCount(1, $details);
        self::assertStringContainsString('r.id IN (', $details[0]['query']);
        file_put_contents(sys_get_temp_dir().'/nova-p06-bulk-post-metrics.json', json_encode(compact('before', 'after')), LOCK_EX);
    }
}

final class BulkReadResponse extends \RuntimeException
{
    public function __construct(public array $payload, public int $status)
    {
        parent::__construct('Synthetic JSON response');
    }
}
