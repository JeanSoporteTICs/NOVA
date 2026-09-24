<?php

namespace Tests\Integration;

use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;
use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use App\Modulos\RedmineMantencion\Services\MantencionDashboardService;
use App\Modulos\RedmineMantencion\Services\MantencionRedmineSyncService;
use App\Modulos\RedmineMantencion\Services\MantencionRetentionService;
use App\Services\Database\SchemaBaseline;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RedmineTic\Repositories\RedmineDataRepository;
use RedmineTic\Repositories\RedmineReportRepository;
use RedmineTic\Support\DateSupport;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DashboardReadQueryTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $schema = new SchemaBaseline;
        $schema->bootstrap(DB::connection(), $schema->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
        config(['cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array']]);
        app()->register(CacheServiceProvider::class);
        Cache::put('nova.redmine.archive_check.redmine_tic', 1, 300);
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
        for ($id = 1; $id <= 60; $id++) {
            DB::table('redmine_tic_reportes')->insert([
                'id' => $id, 'modulo_id' => $id % 13 ? 1 : 2,
                'estado' => ['pendiente', 'procesado', 'procesada', 'error', 'fallido', 'fallida', 'enviando', '', ' ERROR ', 'archivado'][$id % 10],
                'asignado_a' => ['101', '0101', '202', null, null, '101 '][$id % 6],
                'asunto' => 'Asunto '.$id, 'descripcion' => 'Descripción '.$id, 'mensaje' => 'Mensaje '.$id,
                'creado_at' => gmdate('Y-m-d H:i:s', strtotime('2026-09-01 UTC') + $id),
                'actualizado_at' => '2020-01-01 00:00:00',
            ]);
            DB::table('redmine_mantencion_reportes')->insert([
                'id' => $id, 'modulo_id' => $id % 13 ? 2 : 1,
                'fuente' => $id % 2 ? 'core' : 'manual', 'fuente_id' => $id % 5 ? 'message-'.$id : 'repeated',
                'estado' => ['pendiente', 'procesado', 'error', 'archivado', 'PROCESADO', 'procesado '][$id % 6],
                'id_redmine_asignado' => ['101', '0101', '202', null, '', ' 101 '][$id % 6],
                'asignado_nombre' => ['Ana María Pérez', 'Otra Persona', 'ana.core', '12.345.678-5', '101', ''][$id % 6],
                'asunto' => 'Asunto '.$id, 'descripcion' => 'Descripción '.$id,
                'fecha_reporte' => '2026-09-01', 'hora_reporte' => '18:30:00',
                'actualizado_at' => '2020-01-01 00:00:00',
            ]);
        }
        DB::table('tic_log')->insert(['modulo_id' => 1, 'evento' => 'envio_redmine_error',
            'linea' => json_encode(['event' => 'envio_redmine_error', 'context' => ['message_id' => '3', 'error' => 'Error sintético']]),
            'creado_at' => '2026-09-01 00:00:00']);

        // Real scope, normalization, timestamp and repository helpers, without
        // bootstrapping legacy sessions, production configuration or services.
        $source = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/controllers/dashboard.php');
        foreach (['load_messages', 'dashboard_filter_messages_by_scope', 'dashboard_user_matches_assigned',
            'dashboard_user_match_priority', 'dashboard_name_tokens_match', 'dashboard_normalize_text',
            'parse_message_timestamp', 'dashboard_manual_detail_row', 'dashboard_filter_detail_rows',
            'dashboard_detail_preview_rows', 'dashboard_core_detail_table_schema'] as $name) {
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
        eval('function auth_get_user_id() { return $GLOBALS["dashboard_user"]["id"] ?? ""; }
            function dashboard_current_user() { return $GLOBALS["dashboard_user"] ?? []; }
            function mantencion_current_user() { return $GLOBALS["dashboard_session"] ?? []; }');
        $GLOBALS['dashboard_user'] = ['id' => '101', 'nombre' => 'Ana', 'apellido' => 'Pérez'];
    }

    /** Original full-reader dashboard composition; filters themselves are unchanged. */
    private function ticReference(RedmineDataRepository $repo, string $filter, array $user): array
    {
        $call = fn ($method, ...$args) => (new \ReflectionMethod($repo, $method))->invoke($repo, ...$args);
        $filter = $call('normalizeDashboardFilter', $filter);
        $all = $repo->activeReports();
        $scoped = $call('filterReportsByUserScope', $all, $user, 'mensajes');
        $visible = $call('filterReportsByDashboardStatus', $scoped, $filter);
        $summary = $call('dashboardSummaryForReports', $scoped);

        return ['summary' => array_merge($summary, [
            'filter' => $filter, 'visible_total' => count($visible), 'scope_total' => count($scoped),
            'hidden_by_scope' => count($all) - count($scoped), 'total_pending' => $summary['pending'],
            'total_processed' => $summary['processed'], 'total_errors' => $summary['errors'],
        ]), 'reports' => $visible, 'dashboardFilter' => $filter, 'errorLogsByReport' => $repo->errorLogsByReport()];
    }

    private function mantencion(): MantencionDashboardService
    {
        $core = new MantencionCoreImportService;

        return new MantencionDashboardService($core, new MantencionRedmineSyncService($core), new MantencionRetentionService);
    }

    public function test_tic_scope_states_details_counts_and_error_logs_match_the_full_reader(): void
    {
        $users = [[], ['id' => '101', 'legacy' => ['permisos' => ['all' => true]]],
            ['id' => '101', 'name' => 'Ana', 'apellido' => 'Pérez', 'legacy' => ['permisos' => ['mensajes' => 'asignados']]],
            ['redmine_id' => '0101', 'legacy' => ['permisos' => ['mensajes' => 'asignados']]],
            ['id' => '', 'name' => 'Ana Pérez', 'legacy' => ['permisos' => ['mensajes' => 'asignados']]],
            ['id' => 'nobody', 'legacy' => ['permisos' => ['mensajes' => 'asignados']]]];
        foreach ($users as $user) {
            foreach (['todos', 'pendientes', 'procesados', 'errores', 'invalid'] as $filter) {
                self::assertSame($this->ticReference(new RedmineDataRepository, $filter, $user),
                    (new RedmineDataRepository)->dashboardData($filter, $user));
            }
        }
        self::assertNotEmpty((new RedmineDataRepository)->errorLogsByReport());
    }

    public function test_tic_cards_recount_pending_processed_and_errors_after_deletion(): void
    {
        $repo = new RedmineDataRepository;
        $user = ['id' => '101', 'legacy' => ['permisos' => ['all' => true]]];
        $before = $repo->dashboardData('todos', $user)['summary'];

        self::assertSame(3, DB::table('redmine_tic_reportes')
            ->where('modulo_id', 1)->whereIn('id', [10, 1, 3])->delete());

        // El POST de TIC redirige: el GET siguiente usa una nueva instancia.
        $after = (new RedmineDataRepository)->dashboardData('todos', $user)['summary'];
        self::assertSame($before['pending'] - 1, $after['pending']);
        self::assertSame($before['processed'] - 1, $after['processed']);
        self::assertSame($before['errors'] - 1, $after['errors']);
    }

    public function test_mantencion_dashboard_reads_only_visible_error_logs_with_identical_text(): void
    {
        foreach ([
            ['message-2', ['error' => 'first']],
            ['hidden', ['error' => str_repeat('Hidden error ', 10000)]],
            ['message-2', ['error' => 'last']],
            ['repeated', ['error' => 'shared ID']],
            ['MESSAGE-2', ['error' => 'case variant']],
        ] as [$id, $context]) {
            DB::table('mantencion_log')->insert([
                'canal' => 'redmine', 'mensaje_id' => $id,
                'contexto' => json_encode($context, JSON_THROW_ON_ERROR),
            ]);
        }
        for ($i = 0; $i < 200; $i++) {
            DB::table('mantencion_log')->insert([
                'canal' => 'redmine', 'mensaje_id' => 'hidden-'.$i,
                'contexto' => json_encode(['error' => str_repeat('Hidden detail ', 600)], JSON_THROW_ON_ERROR),
            ]);
        }
        $reader = new MantencionRedmineSyncService(new MantencionCoreImportService);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $beforeMemory = memory_get_usage();
        $all = $reader->load_redmine_logs_by_message();
        $beforePeak = memory_get_peak_usage() - $beforeMemory;
        $wanted = ['message-2' => true, 'repeated' => true];

        DB::flushQueryLog();
        DB::enableQueryLog();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $afterMemory = memory_get_usage();
        $actual = $reader->load_redmine_logs_by_message(array_keys($wanted));
        $afterPeak = memory_get_peak_usage() - $afterMemory;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertSame(array_intersect_key($all, $wanted), $actual);
        self::assertLessThan($beforePeak / 5, $afterPeak);
        file_put_contents(sys_get_temp_dir().'/nova-p06-dashboard-log-metrics.json', json_encode([
            'hidden_logs' => 201, 'before_peak_bytes' => $beforePeak,
            'after_peak_bytes' => $afterPeak,
        ]), LOCK_EX);
        $logQueries = array_values(array_filter($queries, fn (array $query): bool => str_contains($query['query'], '`mantencion_log`')));
        self::assertCount(1, $logQueries);
        self::assertStringContainsString('`mensaje_id` in', strtolower($logQueries[0]['query']));

        $dispatcher = new Dispatcher(app());
        DB::connection()->setEventDispatcher($dispatcher);
        $failed = false;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$failed): void {
            if (! $failed && str_contains($event->sql, '`mantencion_log`') && str_contains($event->sql, '`mensaje_id` in')) {
                $failed = true;
                throw new \RuntimeException('Synthetic filtered log read failure');
            }
        });
        self::assertSame($actual, $reader->load_redmine_logs_by_message(array_keys($wanted)));
        self::assertTrue($failed);
        DB::connection()->unsetEventDispatcher();

        DB::flushQueryLog();
        DB::enableQueryLog();
        self::assertSame([], $reader->load_redmine_logs_by_message([]));
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_tic_only_visible_details_are_read_and_empty_scope_does_not_read_details(): void
    {
        DB::enableQueryLog();
        $repo = new RedmineDataRepository;
        $result = $repo->dashboardData('pendientes', ['id' => '101', 'legacy' => ['permisos' => ['all' => true]]]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $full = array_values(array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select * from `redmine_tic_reportes`')));
        self::assertCount(1, $full);
        self::assertStringContainsString('id IN (', $full[0]['query']);
        self::assertNotEmpty($result['reports']);
        foreach ($result['reports'] as $row) {
            self::assertSame('pendiente', $row['estado']);
            self::assertNotEmpty($row['descripcion']);
        }
        self::assertGreaterThan(count($result['reports']), count($repo->activeReports()), 'A partial read must not populate the complete-report cache.');
        DB::flushQueryLog();
        DB::enableQueryLog();
        self::assertSame([], (new RedmineDataRepository)->dashboardData('todos', [])['reports']);
        self::assertCount(0, array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'select * from `redmine_tic_reportes`')));
        DB::disableQueryLog();
    }

    public function test_tic_dashboard_falls_back_when_detail_read_fails_after_selecting_visible_rows(): void
    {
        $user = ['id' => '101', 'legacy' => ['permisos' => ['all' => true]]];
        $expected = $this->ticReference(new RedmineDataRepository, 'pendientes', $user);
        self::assertNotEmpty($expected['reports']);

        $dispatcher = new Dispatcher(app());
        DB::connection()->setEventDispatcher($dispatcher);
        $failed = false;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$failed): void {
            if (! $failed && str_contains($event->sql, '`redmine_tic_reportes`')
                && str_contains($event->sql, 'id IN (')) {
                $failed = true;
                throw new \RuntimeException('Synthetic dashboard detail read failure');
            }
        });
        try {
            self::assertSame($expected, (new RedmineDataRepository)->dashboardData('pendientes', $user));
            self::assertTrue($failed);
        } finally {
            DB::connection()->unsetEventDispatcher();
        }
    }

    public function test_tic_keeps_tied_order_and_existing_cache_and_unsigned_bigint_ids(): void
    {
        $user = ['id' => '101', 'legacy' => ['permisos' => ['all' => true]]];
        $seed = (array) DB::table('redmine_tic_reportes')->where('id', 10)->first();
        DB::table('redmine_tic_reportes')->insert(array_replace($seed,
            ['id' => '9223372036854775808', 'creado_at' => '2026-09-02 00:00:00']));
        $expected = $this->ticReference(new RedmineDataRepository, 'todos', $user);
        $repo = new RedmineDataRepository;
        self::assertSame($expected, $repo->dashboardData('todos', $user));
        self::assertContains('9223372036854775808', array_column($expected['reports'], 'id'));
        $repo->activeReports();
        DB::table('redmine_tic_reportes')->where('id', 10)->update(['estado' => 'error']);
        self::assertSame($expected, $repo->dashboardData('todos', $user));

        DB::table('redmine_tic_reportes')->whereIn('id', [1, 2])->update(['creado_at' => '2026-09-03 00:00:00']);
        $expected = $this->ticReference(new RedmineDataRepository, 'todos', $user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        self::assertSame($expected, (new RedmineDataRepository)->dashboardData('todos', $user));
        $full = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'select * from `redmine_tic_reportes`')));
        DB::disableQueryLog();
        self::assertCount(1, $full);
        self::assertStringNotContainsString('id IN (', $full[0]['query']);
    }

    public function test_mantencion_scope_retention_and_post_keep_original_order_and_full_details(): void
    {
        $service = $this->mantencion();
        foreach ([[], ['id' => '101', 'nombre' => 'Ana', 'apellido' => 'Pérez'],
            ['id' => '0101'], ['id' => 'nobody', 'core_user' => 'ana.core'],
            ['id' => 'nobody', 'rut' => '12.345.678-5'], ['id' => 'nobody', 'rut_sin_dv' => '12345678']] as $user) {
            $GLOBALS['dashboard_user'] = $user;
            $all = load_messages();
            $scoped = dashboard_filter_messages_by_scope($all);
            $expected = array_values(array_filter($all, fn ($row) => strtolower($row['estado']) === 'procesado' || in_array($row, $scoped, true)));
            $actual = $service->messagesForRequest('GET');
            self::assertSame($expected, $actual);
            self::assertSame($scoped, dashboard_filter_messages_by_scope($actual));
            self::assertSame($actual, $service->messagesForRequest('HEAD'));
            self::assertSame($all, $service->messagesForRequest('POST'));
            // Exercise the unchanged retention algorithm, including failures,
            // and compare every attempted archive (including hidden users).
            $retention = new class extends MantencionRetentionService
            {
                public array $attempted = [];

                public function get_retencion_horas(int $default = 24): int
                {
                    return 24;
                }

                public function archive_message_record(array $message, string $archivedBy = 'retencion'): bool
                {
                    $this->attempted[] = $message;

                    return $message['id'] !== 'repeated';
                }
            };
            $retention->apply_retention_archive($all);
            $attempted = $retention->attempted;
            $retention->attempted = [];
            $retention->apply_retention_archive($actual);
            self::assertNotEmpty($attempted);
            self::assertSame($attempted, $retention->attempted);
            self::assertSame(dashboard_filter_messages_by_scope($all), dashboard_filter_messages_by_scope($actual));
        }
    }

    public function test_mantencion_duplicate_public_ids_and_bigint_do_not_pull_hidden_rows(): void
    {
        $seed = (array) DB::table('redmine_mantencion_reportes')->where('id', 6)->first();
        foreach (['9223372036854775807' => '101', '9223372036854775808' => '202'] as $id => $assignee) {
            DB::table('redmine_mantencion_reportes')->insert(array_replace($seed, ['id' => (string) $id,
                'fuente_id' => 'same-public-id', 'id_redmine_asignado' => $assignee,
                'asignado_nombre' => '', 'descripcion' => 'Detail '.$assignee]));
        }
        $all = load_messages();
        $actual = $this->mantencion()->messagesForRequest('GET');
        self::assertSame(dashboard_filter_messages_by_scope($all), dashboard_filter_messages_by_scope($actual));
        $same = array_values(array_filter($actual, fn ($row) => $row['id'] === 'same-public-id'));
        self::assertCount(1, $same);
        self::assertSame('Detail 101', $same[0]['descripcion']);
        $GLOBALS['dashboard_user'] = ['id' => '202'];
        $same = array_values(array_filter($this->mantencion()->messagesForRequest('GET'), fn ($row) => $row['id'] === 'same-public-id'));
        self::assertCount(1, $same);
        self::assertSame('Detail 202', $same[0]['descripcion']);
    }

    public function test_mantencion_detail_uses_real_id_when_public_ids_repeat(): void
    {
        $seed = (array) DB::table('redmine_mantencion_reportes')->where('id', 6)->first();
        foreach (['9223372036854775807' => '101', '9223372036854775808' => '202'] as $id => $assignee) {
            DB::table('redmine_mantencion_reportes')->insert(array_replace($seed, [
                'id' => $id, 'fuente' => 'manual', 'fuente_id' => 'repeated-detail',
                'id_redmine_asignado' => $assignee, 'asignado_nombre' => '',
                'descripcion' => 'Detalle '.$assignee,
            ]));
        }
        $service = $this->mantencion();
        $plain = $service->messagesForRequest('GET');
        $identified = $service->messagesForRequest('GET', null, null, true);
        self::assertSame($plain, array_map(static function (array $message): array {
            unset($message['_dashboard_id']);

            return $message;
        }, $identified));
        $visible = dashboard_filter_messages_by_scope($identified);
        $matches = array_values(array_filter($visible, static fn ($message): bool => $message['id'] === 'repeated-detail'));
        self::assertCount(1, $matches);
        self::assertSame('9223372036854775807', $matches[0]['_dashboard_id']);
        $detail = $service->reportDetailForDatabaseId('9223372036854775807');
        self::assertSame('Detalle 101', $detail['descripcion']);
        self::assertSame('Detalle 101', $detail['preview_rows'][0]['detalle_descripcion']);
        self::assertNull($service->reportDetailForDatabaseId('9223372036854775808'));
        $GLOBALS['dashboard_user'] = ['id' => '202'];
        self::assertSame('Detalle 202', $service->reportDetailForDatabaseId('9223372036854775808')['descripcion']);
        self::assertNull($service->reportDetailForDatabaseId('9223372036854775807'));
    }

    public function test_mantencion_keeps_empty_response_when_repository_is_unavailable(): void
    {
        app()->bind(MantencionReportRepository::class,
            fn () => throw new \RuntimeException('Synthetic unavailable repository'));
        self::assertSame([], load_messages());
        self::assertSame([], $this->mantencion()->messagesForRequest('GET'));
        self::assertSame([], $this->mantencion()->messagesForRequest('POST'));
    }

    public function test_both_dashboard_reads_use_one_snapshot_for_metadata_and_details(): void
    {
        $user = ['id' => '101', 'legacy' => ['permisos' => ['all' => true]]];
        $expectedTic = $this->ticReference(new RedmineDataRepository, 'pendientes', $user);
        $expectedMantencion = dashboard_filter_messages_by_scope(load_messages());
        $dispatcher = new Dispatcher(app());
        DB::connection()->setEventDispatcher($dispatcher);
        $changed = [];
        $dispatcher->listen(QueryExecuted::class, function ($event) use (&$changed): void {
            foreach (['redmine_tic_reportes' => 'asignado_a', 'redmine_mantencion_reportes' => 'id_redmine_asignado'] as $table => $column) {
                if (! isset($changed[$table]) && str_contains($event->sql, 'from `'.$table.'`')
                    && str_contains($event->sql, '`'.$column.'`')) {
                    $changed[$table] = true;
                    DB::connection('concurrent')->table($table)->where('id', $table === 'redmine_tic_reportes' ? 10 : 6)
                        ->update(['descripcion' => 'Concurrent update', $column => '202', 'estado' => 'error']);
                }
            }
        });
        self::assertSame($expectedTic, (new RedmineDataRepository)->dashboardData('pendientes', $user));
        self::assertSame($expectedMantencion, dashboard_filter_messages_by_scope($this->mantencion()->messagesForRequest('GET')));
        self::assertCount(2, $changed);
    }

    public function test_hidden_report_text_no_longer_dominates_dashboard_memory(): void
    {
        $tic = (array) DB::table('redmine_tic_reportes')->where('id', 10)->first();
        $mantencion = (array) DB::table('redmine_mantencion_reportes')->where('id', 6)->first();
        for ($id = 1000; $id < 1400; $id++) {
            $text = str_repeat('Hidden report detail. ', 2000);
            DB::table('redmine_tic_reportes')->insert(array_replace($tic, ['id' => $id, 'asignado_a' => '202', 'descripcion' => $text,
                'creado_at' => gmdate('Y-m-d H:i:s', strtotime('2026-09-01 UTC') + $id)]));
            DB::table('redmine_mantencion_reportes')->insert(array_replace($mantencion, ['id' => $id, 'id_redmine_asignado' => '202',
                'asignado_nombre' => 'Otra Persona', 'descripcion' => $text, 'fuente_id' => 'hidden-'.$id]));
        }
        unset($text, $tic, $mantencion);
        $measure = static function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            $value = $read();

            return [$value, ['elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3), 'peak_bytes' => memory_get_peak_usage() - $memory]];
        };
        $user = ['id' => '101', 'legacy' => ['permisos' => ['mensajes' => 'asignados']]];
        [$expected, $ticBefore] = $measure(fn () => $this->ticReference(new RedmineDataRepository, 'todos', $user));
        [$actual, $ticAfter] = $measure(fn () => (new RedmineDataRepository)->dashboardData('todos', $user));
        self::assertSame($expected, $actual);
        self::assertLessThan($ticBefore['peak_bytes'] / 3, $ticAfter['peak_bytes']);
        [$expected, $mantencionBefore] = $measure(fn () => dashboard_filter_messages_by_scope(load_messages()));
        [$actual, $mantencionAfter] = $measure(fn () => dashboard_filter_messages_by_scope($this->mantencion()->messagesForRequest('GET')));
        self::assertSame($expected, $actual);
        self::assertLessThan($mantencionBefore['peak_bytes'] / 3, $mantencionAfter['peak_bytes']);
        file_put_contents(sys_get_temp_dir().'/nova-p06-dashboard-metrics.json',
            json_encode(compact('ticBefore', 'ticAfter', 'mantencionBefore', 'mantencionAfter')), LOCK_EX);
    }

    public function test_tic_native_dashboard_omits_long_text_without_changing_visible_rows_or_counts(): void
    {
        $seed = (array) DB::table('redmine_tic_reportes')->where('id', 10)->first();
        for ($id = 1000; $id < 1200; $id++) {
            DB::table('redmine_tic_reportes')->insert(array_replace($seed, [
                'id' => $id, 'estado' => 'pendiente', 'asunto' => 'Visible '.$id,
                'mensaje' => str_repeat('Mensaje extenso ', 2500),
                'descripcion' => str_repeat('Descripción extensa ', 2500),
                'creado_at' => gmdate('Y-m-d H:i:s', strtotime('2026-09-01 UTC') + $id),
            ]));
        }
        $admin = ['id' => '101', 'legacy' => ['permisos' => ['all' => true]]];
        $measure = static function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $start = memory_get_usage();
            $result = $read();

            return [$result, memory_get_peak_usage() - $start];
        };
        [$full, $fullPeak] = $measure(fn () => (new RedmineDataRepository)->dashboardData('todos', $admin));
        DB::connection()->enableQueryLog();
        [$light, $lightPeak] = $measure(fn () => (new RedmineDataRepository)->dashboardData('todos', $admin, false));
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        DB::connection()->flushQueryLog();
        self::assertSame($full['summary'], $light['summary']);
        self::assertSame($full['dashboardFilter'], $light['dashboardFilter']);
        self::assertSame($full['errorLogsByReport'], $light['errorLogsByReport']);
        $expected = array_map(static fn (array $row): array => array_replace($row, ['mensaje' => '', 'descripcion' => '']), $full['reports']);
        self::assertSame($expected, $light['reports']);
        self::assertSame($light['reports'], (new RedmineDataRepository)->nativeSectionData('dashboard', 'todos', [], $admin)['reports']);
        self::assertLessThan($fullPeak / 3, $lightPeak);
        $detailQueries = array_filter($queries, static fn ($entry): bool =>
            str_contains($entry['query'], 'from `redmine_tic_reportes`')
            && str_contains($entry['query'], 'and id IN ('));
        self::assertNotEmpty($detailQueries);
        foreach ($detailQueries as $entry) {
            self::assertStringNotContainsString('`mensaje`', $entry['query']);
            self::assertStringNotContainsString('`descripcion`', $entry['query']);
        }
        file_put_contents(sys_get_temp_dir().'/nova-p06-tic-dashboard-detail-metrics.json',
            json_encode(compact('fullPeak', 'lightPeak')), LOCK_EX);
    }

    public function test_tic_dashboard_detail_is_exactly_scoped_to_one_accessible_active_report(): void
    {
        $repo = new RedmineDataRepository;
        $admin = ['id' => '101', 'legacy' => ['permisos' => ['all' => true]]];
        $report = $repo->activeReports();
        $selected = array_values(array_filter($report, static fn ($row): bool => ($row['id'] ?? '') === '10'))[0];
        self::assertSame([
            'mensaje' => $selected['mensaje'],
            'descripcion' => $selected['descripcion'],
        ], $repo->dashboardReportText('10', $admin));
        self::assertNull($repo->dashboardReportText('010', $admin));
        self::assertNull($repo->dashboardReportText('13', $admin), 'Other modules are not accessible.');
        self::assertNull($repo->dashboardReportText('9', $admin), 'Archived reports are not editable.');
        self::assertNull($repo->dashboardReportText('10', []));
    }

    public function test_tic_retention_hydrates_only_expired_rows_with_identical_dates_details_and_order(): void
    {
        DB::statement("SET SESSION sql_mode = ''");
        $seed = (array) DB::table('redmine_tic_reportes')->where('id', 1)->first();
        foreach ([
            ['procesado_at' => '2026-09-07 12:00:00'],
            ['procesado_at' => '2026-09-07 12:00:01'],
            ['procesado_at' => null, 'actualizado_at' => '2026-09-07 12:00:00'],
            ['procesado_at' => null, 'actualizado_at' => null],
            ['procesado_at' => '0000-00-00 00:00:00'],
            ['estado' => 'PROCESADA', 'procesado_at' => '2026-09-06 12:00:00'],
            ['estado' => 'procesado ', 'procesado_at' => '2026-09-06 12:00:00'],
            ['modulo_id' => 2, 'procesado_at' => '2026-09-06 12:00:00'],
        ] as $index => $changes) {
            DB::table('redmine_tic_reportes')->insert(array_replace($seed, ['id' => 100 + $index], $changes));
        }
        $timezone = date_default_timezone_get();
        DB::connection()->enableQueryLog();
        try {
            foreach (['UTC', 'America/Santiago', 'Pacific/Kiritimati', 'Etc/GMT+12'] as $zone) {
                date_default_timezone_set($zone);
                $limit = (new \DateTimeImmutable('2026-09-07 12:00:00'))->getTimestamp();
                $repo = new RedmineReportRepository('redmine_tic', 'TIC');
                $all = $repo->findActiveByStates(1, ['procesado', 'procesada'], fn ($id) => 'User '.$id);
                $expected = array_values(array_filter($all, static function ($report) use ($limit): bool {
                    $ts = DateSupport::timestampFromValue($report['procesado_ts'] ?? null);

                    return $ts !== null && $ts <= $limit;
                }));
                $hydrated = 0;
                $actual = $repo->findActiveByStates(1, ['procesado', 'procesada'], function ($id) use (&$hydrated) {
                    $hydrated++;

                    return 'User '.$id;
                }, $limit);
                self::assertSame($expected, $actual);
                self::assertCount($hydrated, $actual);
                self::assertLessThan(count($all), $hydrated);
                self::assertContains('100', array_column($actual, 'id'));
                self::assertNotContains('101', array_column($actual, 'id'));
                self::assertNotContains('107', array_column($actual, 'id'));
            }
            $retentionQueries = array_filter(DB::connection()->getQueryLog(), static fn ($entry): bool =>
                str_contains($entry['query'], 'from `redmine_tic_reportes`')
                && str_contains($entry['query'], '`procesado_at` <= ?'));
            self::assertCount(4, $retentionQueries, 'The retention reader must filter clearly recent rows in SQL.');
            foreach ($retentionQueries as $entry) {
                self::assertStringContainsString('`actualizado_at` <= ?', $entry['query']);
            }
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
            date_default_timezone_set($timezone);
        }
    }

    public function test_mantencion_cutoff_preserves_archive_attempts_failures_and_visible_recent_reports(): void
    {
        // Exercise the legacy nullable-date fallback as well as the baseline's
        // ordinary timestamps. No operational schema is used by this test.
        DB::statement('ALTER TABLE redmine_mantencion_reportes MODIFY actualizado_at TIMESTAMP NULL DEFAULT NULL');
        $threshold = new \DateTimeImmutable('2026-09-07 12:00:00');
        $seed = (array) DB::table('redmine_mantencion_reportes')->where('id', 1)->first();
        foreach ([
            ['actualizado_at' => '2026-09-07 12:00:00'],
            ['actualizado_at' => '2026-09-07 12:00:01'],
            ['actualizado_at' => null, 'fecha_reporte' => '2026-09-06', 'hora_reporte' => '12:00:00'],
            ['actualizado_at' => null, 'fecha_reporte' => null, 'fecha_inicio' => null],
            ['actualizado_at' => null, 'fecha_reporte' => null, 'fecha_inicio' => '2026-09-06', 'hora_reporte' => '12:00:00'],
            ['actualizado_at' => '2026-09-07 12:00:01', 'id_redmine_asignado' => '101'],
        ] as $index => $changes) {
            DB::table('redmine_mantencion_reportes')->insert(array_replace($seed,
                ['id' => 100 + $index, 'fuente_id' => 'cutoff-'.$index, 'id_redmine_asignado' => '202', 'asignado_nombre' => 'Otra Persona'], $changes));
        }
        $all = load_messages();
        $actual = $this->mantencion()->messagesForRequest('GET', $threshold);
        self::assertNotContains('cutoff-1', array_column($actual, 'id'));
        self::assertNotContains('cutoff-3', array_column($actual, 'id'));
        self::assertContains('cutoff-5', array_column($actual, 'id'), 'Visible recent reports must keep their full details.');
        self::assertSame($actual, $this->mantencion()->messagesForRequest('HEAD', $threshold));
        self::assertSame($all, $this->mantencion()->messagesForRequest('POST', $threshold));
        $retention = new class extends MantencionRetentionService
        {
            public array $attempts = [];

            public function get_retencion_horas(int $default = 24): int
            {
                throw new \LogicException('The cutoff must be reused, not recomputed.');
            }

            public function archive_message_record(array $message, string $archivedBy = 'retencion'): bool
            {
                $this->attempts[] = $message;

                return ! in_array($message['id'], ['repeated', 'cutoff-2'], true);
            }
        };
        self::assertTrue($retention->apply_retention_archive($all, $threshold));
        $expected = $retention->attempts;
        $retention->attempts = [];
        self::assertTrue($retention->apply_retention_archive($actual, $threshold));
        self::assertSame($expected, $retention->attempts);
        foreach (['cutoff-0', 'cutoff-2', 'cutoff-4'] as $id) {
            self::assertContains($id, array_column($expected, 'id'));
        }
        self::assertSame(dashboard_filter_messages_by_scope($all), dashboard_filter_messages_by_scope($actual));
    }

    public function test_tic_error_log_cap_keeps_the_original_200_entry_window_and_eight_errors_per_report(): void
    {
        $lines = ['false', '{invalid', json_encode(['event' => 'other', 'context' => ['message_id' => '3']])];
        for ($i = 0; $i < 210; $i++) {
            $lines[] = json_encode(['ts' => 'synthetic-'.$i, 'event' => $i % 3 ? 'envio_redmine_error' : 'envio_redmine_http',
                'context' => ['message_id' => $i % 2 ? '3' : '4', 'http_code' => $i % 5 ? 500 : 200,
                    'error' => $i % 5 ? 'Failure '.$i : '', 'body' => 'Body '.$i, 'payload' => ['ordinal' => $i]]]);
        }
        foreach ($lines as $i => $line) {
            DB::table('tic_log')->insert(['modulo_id' => 1, 'evento' => 'deliberately_different', 'linea' => $line,
                'creado_at' => gmdate('Y-m-d H:i:s', strtotime('2026-09-23 UTC') - $i)]);
        }
        $repo = new RedmineDataRepository;
        $format = new \ReflectionMethod($repo, 'formatErrorLogEntry');
        $expected = [];
        // Original algorithm: format everything within activity(), then slice.
        foreach ($repo->activity() as $line) {
            $entry = json_decode((string) $line, true);
            if (! is_array($entry)) {
                continue;
            }
            $event = trim((string) ($entry['event'] ?? ''));
            if (! in_array($event, ['envio_redmine_error', 'envio_redmine_http'], true)) {
                continue;
            }
            $context = $entry['context'] ?? [];
            if (! is_array($context)) {
                continue;
            }
            $id = trim((string) ($context['message_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $code = (int) ($context['http_code'] ?? 0);
            if ($event === 'envio_redmine_http' && $code >= 200 && $code < 400 && trim((string) ($context['error'] ?? '')) === '') {
                continue;
            }
            $expected[$id][] = $format->invoke($repo, $entry, $context);
        }
        $expected = array_map(fn ($entries) => implode(PHP_EOL.PHP_EOL.'---'.PHP_EOL.PHP_EOL, array_slice($entries, 0, 8)), $expected);
        self::assertSame($expected, $repo->errorLogsByReport());
        self::assertCount(200, $repo->activity());
        self::assertCount(2, $expected);
    }

    public function test_mantencion_get_reuses_cutoff_and_maintenance_still_suppresses_archival(): void
    {
        eval('function load_user_api_token($id) { return "synthetic"; }
            function maintenance_mode_enabled() { return $GLOBALS["retention_maintenance"]; }
            function security_load_events() { return [["tag" => "LOG", "details" => "Synthetic"]]; }');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $retention = new class extends MantencionRetentionService
        {
            public int $cutoffs = 0;

            public array $attempts = [];

            public function retention_threshold(): \DateTimeImmutable
            {
                $this->cutoffs++;

                return new \DateTimeImmutable('2026-09-22 00:00:00');
            }

            public function archive_message_record(array $message, string $archivedBy = 'retencion'): bool
            {
                $this->attempts[] = $message;

                return true;
            }
        };
        $core = new MantencionCoreImportService;
        $service = new class($core, new MantencionRedmineSyncService($core), $retention) extends MantencionDashboardService
        {
            public function dashboard_consume_flash(): ?string
            {
                return 'Synthetic flash';
            }
        };
        foreach ([true, false] as $maintenance) {
            $GLOBALS['retention_maintenance'] = $maintenance;
            $expected = load_messages();
            $threshold = $retention->retention_threshold();
            if (! $maintenance) {
                $retention->apply_retention_archive($expected, $threshold);
            }
            $attempts = $retention->attempts;
            $retention->attempts = [];
            $retention->cutoffs = 0;
            [$actual, $flash, $logs] = $service->handle_request();
            self::assertSame(dashboard_filter_messages_by_scope($expected), $actual);
            self::assertSame($attempts, $retention->attempts);
            self::assertSame(1, $retention->cutoffs);
            self::assertSame('Synthetic flash', $flash);
            self::assertSame([['tag' => 'LOG', 'details' => 'Synthetic']], $logs);
            $beforeViewAttempts = count($retention->attempts);
            [$identified] = $service->handle_request(true);
            $viewAttempts = array_slice($retention->attempts, $beforeViewAttempts);
            self::assertSame($attempts, array_map(static function (array $message): array {
                unset($message['_dashboard_id']);

                return $message;
            }, $viewAttempts));
            self::assertSame(array_map(static function (array $message): array {
                $message['descripcion'] = '';

                return $message;
            }, $actual), array_map(static function (array $message): array {
                unset($message['_dashboard_id']);

                return $message;
            }, $identified));
            foreach ($identified as $message) {
                self::assertNotSame('', $message['_dashboard_id'] ?? '');
            }
        }
    }

    public function test_mantencion_visible_descriptions_stay_out_of_the_dashboard_sql_read(): void
    {
        eval('function load_user_api_token($id) { return "synthetic"; }
            function maintenance_mode_enabled() { return true; }
            function security_load_events() { return []; }');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $seed = (array) DB::table('redmine_mantencion_reportes')->where('id', 6)->first();
        for ($id = 1000; $id < 1300; $id++) {
            DB::table('redmine_mantencion_reportes')->insert(array_replace($seed, [
                'id' => $id, 'fuente_id' => 'visible-'.$id, 'estado' => 'pendiente',
                'id_redmine_asignado' => '101', 'asignado_nombre' => 'Ana Pérez',
                'descripcion' => str_repeat('Descripción visible extensa. ', 1500),
                'fecha_reporte' => '2026-09-01',
            ]));
        }
        foreach (['9223372036854775807' => '101', '9223372036854775808' => '202'] as $id => $assignee) {
            DB::table('redmine_mantencion_reportes')->insert(array_replace($seed, [
                'id' => $id, 'fuente_id' => 'repeated-visible', 'estado' => 'pendiente',
                'id_redmine_asignado' => $assignee, 'asignado_nombre' => '',
                'descripcion' => 'BIGINT '.$assignee,
                'fecha_reporte' => '2026-09-01',
            ]));
        }
        $retention = new class extends MantencionRetentionService
        {
            public function retention_threshold(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-22 00:00:00');
            }
        };
        $core = new MantencionCoreImportService;
        $service = new class($core, new MantencionRedmineSyncService($core), $retention) extends MantencionDashboardService
        {
            public function dashboard_consume_flash(): ?string
            {
                return null;
            }
        };
        $measure = static function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $startMemory = memory_get_usage();
            $startTime = hrtime(true);
            $result = $read();

            return [$result, memory_get_peak_usage() - $startMemory,
                round((hrtime(true) - $startTime) / 1e6, 3)];
        };
        [[$full], $fullPeak, $fullMs] = $measure(fn () => $service->handle_request());
        DB::connection()->enableQueryLog();
        [[$view], $viewPeak, $viewMs] = $measure(fn () => $service->handle_request(true));
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        DB::connection()->flushQueryLog();
        $expected = array_map(static function (array $message): array {
            $message['descripcion'] = '';

            return $message;
        }, $full);
        $actual = array_map(static function (array $message): array {
            unset($message['_dashboard_id']);

            return $message;
        }, $view);
        self::assertSame($expected, $actual);
        $repeated = array_values(array_filter($view, static fn (array $row): bool => $row['id'] === 'repeated-visible'));
        self::assertCount(1, $repeated);
        self::assertSame('9223372036854775807', $repeated[0]['_dashboard_id']);
        self::assertLessThan($fullPeak / 3, $viewPeak);
        $lightQueries = array_filter($queries, static fn ($entry): bool =>
            str_contains($entry['query'], 'from `redmine_mantencion_reportes` as `r`')
            && str_contains($entry['query'], 'r.id IN (')
            && ! str_contains($entry['query'], '`r`.*'));
        self::assertNotEmpty($lightQueries);
        foreach ($lightQueries as $entry) {
            self::assertStringNotContainsString('`r`.`descripcion`', $entry['query']);
        }
        file_put_contents(sys_get_temp_dir().'/nova-p06-mantencion-dashboard-detail-metrics.json',
            json_encode(compact('fullPeak', 'viewPeak', 'fullMs', 'viewMs')), LOCK_EX);
    }

    public function test_recent_processed_reports_use_less_retention_memory(): void
    {
        $tic = (array) DB::table('redmine_tic_reportes')->where('id', 1)->first();
        $mantencion = (array) DB::table('redmine_mantencion_reportes')->where('id', 1)->first();
        for ($id = 1000; $id < 1400; $id++) {
            $text = str_repeat('Recent processed report. ', 2000);
            DB::table('redmine_tic_reportes')->insert(array_replace($tic, ['id' => $id, 'descripcion' => $text,
                'procesado_at' => '2026-09-23 00:00:00']));
            DB::table('redmine_mantencion_reportes')->insert(array_replace($mantencion, ['id' => $id,
                'descripcion' => $text, 'fuente_id' => 'recent-'.$id, 'id_redmine_asignado' => '202',
                'asignado_nombre' => 'Otra Persona', 'actualizado_at' => '2026-09-23 00:00:00']));
        }
        unset($text, $tic, $mantencion);
        $measure = static function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            $value = $read();

            return [$value, ['elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3), 'peak_bytes' => memory_get_peak_usage() - $memory]];
        };
        $threshold = new \DateTimeImmutable('2026-09-21 00:00:00');
        $repo = new RedmineReportRepository('redmine_tic', 'TIC');
        [$expected, $ticBefore] = $measure(fn () => array_values(array_filter(
            $repo->findActiveByStates(1, ['procesado', 'procesada'], fn ($id) => $id),
            fn ($report) => ($ts = DateSupport::timestampFromValue($report['procesado_ts'])) !== null && $ts <= $threshold->getTimestamp())));
        [$actual, $ticAfter] = $measure(fn () => $repo->findActiveByStates(1, ['procesado', 'procesada'], fn ($id) => $id, $threshold->getTimestamp()));
        self::assertSame($expected, $actual);
        self::assertLessThan($ticBefore['peak_bytes'] * 0.8, $ticAfter['peak_bytes']);
        [$expected, $mantencionBefore] = $measure(fn () => dashboard_filter_messages_by_scope($this->mantencion()->messagesForRequest('GET')));
        [$actual, $mantencionAfter] = $measure(fn () => dashboard_filter_messages_by_scope($this->mantencion()->messagesForRequest('GET', $threshold)));
        self::assertSame($expected, $actual);
        self::assertLessThan($mantencionBefore['peak_bytes'] / 3, $mantencionAfter['peak_bytes']);
        file_put_contents(sys_get_temp_dir().'/nova-p06-retention-metrics.json',
            json_encode(compact('ticBefore', 'ticAfter', 'mantencionBefore', 'mantencionAfter')), LOCK_EX);
    }
}
