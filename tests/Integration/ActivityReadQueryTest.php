<?php

namespace Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RedmineTic\Repositories\RedmineActivityRepository;

final class ActivityReadQueryTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('modulos_nova', function (Blueprint $table): void {
            $table->id();
            $table->string('clave_modulo');
        });
        Schema::create('tic_log', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('modulo_id');
            $table->string('evento')->nullable();
            $table->longText('contexto')->nullable();
            $table->longText('linea')->nullable();
            $table->dateTime('creado_at')->nullable();
        });
        DB::table('modulos_nova')->insert([['id' => 1, 'clave_modulo' => 'redmine_tic'], ['id' => 2, 'clave_modulo' => 'other']]);
        $owners = ['user-1', 'USER-1', 'user-10', "\tuser-1\n", ' user-1 ', '', null, 1, 1.0, true, false, '01', '1', '\\user-1', 'usér-1'];
        $events = ['reporte_update', 'envio_redmine_error', 'consulta_datos', 'envio_redmine_http', 'Evento', 'evento', '', '0', '01', '1'];
        for ($id = 1; $id <= 360; $id++) {
            DB::table('tic_log')->insert(['id' => $id, 'modulo_id' => $id % 9 === 0 ? 2 : 1,
                'evento' => $events[$id % count($events)],
                'contexto' => $id % 29 === 0 ? 'invalid JSON' : json_encode(['user_id' => $owners[$id % count($owners)], 'asunto' => 'Caso %_ '.str_repeat('x', 4000), 'http_code' => $id % 3 === 0 ? 500 : 201], JSON_PRESERVE_ZERO_FRACTION),
                'creado_at' => $id % 7 === 0 ? null : ($id % 2 === 0 ? '2026-09-16 10:00:00' : '2026-09-15 23:59:59')]);
        }
        foreach ([
            '{"user_id":"user-1","user_id":"USER-1"}',
            '{"user_id":"USER-1","user_id":"user-1"}',
            '{"user_id":"USER-1","\\u0075ser_id":"user-1"}',
            '{"\\u0075ser_id":"user-1"}',
            '{"user_id":"user-1","deep":'.str_repeat('[', 512).'0'.str_repeat(']', 512).'}',
        ] as $context) {
            DB::table('tic_log')->insert(['modulo_id' => 1, 'evento' => 'reporte_update', 'contexto' => $context,
                'creado_at' => '2026-09-16 10:00:00']);
        }
        DB::table('tic_log')->insert(['id' => '9223372036854775808', 'modulo_id' => 1,
            'evento' => 'reporte_update', 'contexto' => '{"user_id":1,"asunto":"Unsigned BIGINT"}',
            'creado_at' => '2026-09-16 10:00:00']);
    }

    private function original(array $filters, string $viewer, bool $all): array
    {
        $repo = new RedmineActivityRepository('redmine_tic', 'TIC');
        $map = new \ReflectionMethod($repo, 'operationalEntry');
        $query = DB::table('tic_log')->where('modulo_id', 1)->whereNotIn('evento', ['consulta_datos', 'envio_redmine_http']);
        if (trim($filters['evento'] ?? '') !== '') {
            $query->where('evento', trim($filters['evento']));
        }
        if (trim($filters['buscar'] ?? '') !== '') {
            $search = '%'.trim($filters['buscar']).'%';
            $query->where(fn ($q) => $q->where('evento', 'like', $search)->orWhere('contexto', 'like', $search));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['desde'] ?? '')) {
            $query->where('creado_at', '>=', $filters['desde'].' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['hasta'] ?? '')) {
            $query->where('creado_at', '<=', $filters['hasta'].' 23:59:59');
        }
        $entries = $query->orderByDesc('creado_at')->orderByDesc('id')->get()
            ->map(fn ($row) => $map->invoke($repo, $row))
            ->filter(fn ($entry) => $all || ($viewer !== '' && $entry['user_id'] === $viewer))->values();
        $perPage = in_array((int) ($filters['per_page'] ?? 50), [25, 50, 100], true) ? (int) ($filters['per_page'] ?? 50) : 50;
        $total = $entries->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($filters['page'] ?? 1)), $pages);

        return ['entries' => $entries->slice(($page - 1) * $perPage, $perPage)->values()->all(), 'total' => $total,
            'page' => $page, 'per_page' => $perPage, 'pages' => $pages,
            'events' => $entries->pluck('event')->filter()->unique()->sort()->values()->all()];
    }

    public function test_sql_scope_pagination_and_event_choices_match_the_previous_reader(): void
    {
        $repo = new RedmineActivityRepository('redmine_tic', 'TIC');
        foreach (['user-1', 'USER-1', 'usér-1', '1', '01', '0', ''] as $viewer) {
            foreach ([true, false] as $all) {
                foreach ([[], ['page' => 2, 'per_page' => 25], ['page' => 999, 'per_page' => 17], ['evento' => 'EVENTO'], ['buscar' => '%_'], ['desde' => '2026-09-16'], ['hasta' => '2026-09-15']] as $filters) {
                    self::assertSame($this->original($filters, $viewer, $all), $repo->search($filters, $viewer, $all), json_encode([$viewer, $all, $filters]));
                }
            }
        }
    }

    public function test_only_the_visible_page_loads_context_payloads_and_no_linea_is_loaded(): void
    {
        DB::enableQueryLog();
        $result = (new RedmineActivityRepository('redmine_tic', 'TIC'))->search(['per_page' => 25], '', true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertCount(25, $result['entries']);
        $payloads = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], '`contexto`')));
        self::assertCount(1, $payloads);
        self::assertStringContainsString('limit 25', $payloads[0]['query']);
        foreach ($queries as $query) {
            self::assertStringNotContainsString('`linea`', $query['query']);
        }
    }

    public function test_activity_memory_and_result_against_the_previous_reader(): void
    {
        $repo = new RedmineActivityRepository('redmine_tic', 'TIC');
        $measure = function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $read();
            $metrics = ['peak_bytes' => memory_get_peak_usage() - $memory,
                'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 2), 'queries' => count(DB::getQueryLog())];
            DB::disableQueryLog();
            DB::flushQueryLog();

            return [$result, $metrics];
        };
        [$previous, $before] = $measure(fn () => $this->original(['per_page' => 25], '', true));
        [$current, $after] = $measure(fn () => $repo->search(['per_page' => 25], '', true));
        self::assertSame($previous, $current);
        self::assertLessThan($before['peak_bytes'], $after['peak_bytes']);
        file_put_contents(sys_get_temp_dir().'/nova-p06-activity-metrics.json', json_encode([
            'logs' => DB::table('tic_log')->count(), 'visible' => 25, 'before' => $before, 'after' => $after,
        ]), LOCK_EX);
    }
}
