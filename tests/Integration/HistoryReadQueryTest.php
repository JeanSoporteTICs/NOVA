<?php

namespace Tests\Integration;

use App\Modulos\RedmineMantencion\Repositories\MantencionHistoryRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionHoursExtraRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;
use App\Modulos\RedmineMantencion\Services\MantencionHistoricoService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HistoryReadQueryTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Load the two real pure helpers without starting the legacy runtime.
        $source = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/controllers/dashboard.php');
        foreach (['dashboard_normalize_text', 'dashboard_name_tokens_match'] as $name) {
            $start = strpos($source, 'function '.$name.'(');
            $end = strpos($source, "\nfunction ", $start + 1);
            $definition = substr($source, $start, $end - $start);
            eval($definition);
        }
        Schema::create('modulos_nova', function (Blueprint $t): void {
            $t->id();
            $t->string('clave_modulo');
        });
        DB::table('modulos_nova')->insert([['id' => 1, 'clave_modulo' => 'redmine_tic'], ['id' => 2, 'clave_modulo' => 'redmine-mantencion']]);
        Schema::create('configuraciones_modulo', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('modulo_id');
            $t->string('clave');
            $t->text('valor')->nullable();
            $t->string('tipo');
            $t->dateTime('actualizado_at')->nullable();
            $t->unique(['modulo_id', 'clave']);
        });
        Schema::create('modulo_opciones', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('modulo_id');
            $t->string('tipo');
            $t->string('id_externo')->nullable();
            $t->string('nombre');
            $t->boolean('predeterminado')->default(0);
            $t->boolean('activo')->default(1);
            $t->integer('orden')->default(1);
            $t->dateTime('actualizado_at')->nullable();
            $t->unique(['modulo_id', 'tipo', 'id_externo']);
        });
        Schema::create('categorias', function (Blueprint $t): void {
            $t->id();
            $t->string('nombre');
        });
        Schema::create('redmine_mantencion_reportes', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('modulo_id');
            $t->unsignedBigInteger('categoria_id')->nullable();
            foreach (['fuente', 'fuente_id', 'id_core', 'proyecto', 'project_id', 'tipo', 'tipo_id',
                'asunto', 'descripcion', 'estado', 'estado_redmine', 'estado_id', 'prioridad', 'priority_id',
                'id_redmine_asignado', 'asignado_nombre', 'solicitante', 'anexo', 'unidad_texto', 'correo'] as $column) {
                $t->{$column === 'descripcion' ? 'text' : 'string'}($column)->nullable();
            }
            foreach (['fecha_inicio', 'fecha_fin', 'fecha_reporte'] as $column) {
                $t->date($column)->nullable();
            }
            $t->time('hora_reporte')->nullable();
            $t->decimal('tiempo_estimado', 10, 2)->nullable();
            $t->boolean('hora_extra')->default(0);
            $t->unsignedBigInteger('numero_ticket_redmine')->nullable();
            $t->dateTime('creado_at')->nullable();
            $t->dateTime('actualizado_at')->nullable();
        });

        Schema::create('usuarios_nova', function (Blueprint $t): void {
            $t->id();
        });
        (require dirname(__DIR__, 2).'/database/migrations/2026_07_05_000000_create_horas_extra_grupos_shared_tables.php')->up();
        DB::table('usuarios_nova')->insert([['id' => 1], ['id' => 2]]);
        DB::table('categorias')->insert([['id' => 1, 'nombre' => 'Eléctrica'], ['id' => 2, 'nombre' => 'Agua']]);
        DB::table('horas_extra_grupos')->insert([
            ['id' => 1, 'usuario_id' => 1, 'fecha' => '2026-09-12'],
            ['id' => 2, 'usuario_id' => 2, 'fecha' => '2026-09-12'],
        ]);
        for ($id = 1; $id <= 85; $id++) {
            DB::table('redmine_mantencion_reportes')->insert([
                'id' => $id, 'modulo_id' => $id % 11 === 0 ? 1 : 2, 'fuente_id' => $id % 7 === 0 ? null : 'm-'.$id,
                'fuente' => 'manual', 'estado' => $id % 13 === 0 ? 'pendiente' : 'archivado',
                'categoria_id' => $id % 3 === 0 ? null : ($id % 2 + 1), 'asunto' => 'Reporte '.$id,
                'descripcion' => $id % 2 === 0 ? 'Revisión de ÁREA %_ con tabulación' : 'Detalle normal',
                'id_redmine_asignado' => $id % 3 === 0 ? 'otro' : '101', 'asignado_nombre' => $id % 4 === 0 ? 'Pérez Ana' : 'Ajeno',
                'solicitante' => $id % 2 === 0 ? 'José Muñoz' : 'María Díaz',
                'fecha_reporte' => $id % 5 === 0 ? null : '2026-09-12', 'fecha_inicio' => $id % 6 === 0 ? null : '2026-09-10',
                'estado_redmine' => $id % 2 === 0 ? 'En progreso' : null, 'estado_id' => '1',
                'hora_extra' => 0, 'numero_ticket_redmine' => $id % 4 === 0 ? 9000 : $id + 9000,
            ]);
            if ($id % 3 === 0) {
                foreach ([1, 2] as $group) {
                    DB::table('horas_extra_grupo_reportes')->insert(['grupo_id' => $group, 'origen' => 'mantencion', 'reporte_id' => $id]);
                }
            }
        }
    }

    private function originalItems(): array
    {
        $reports = array_map(static function (array $r): array {
            $r['_fuente'] = 'reportes';

            return $r;
        }, app(MantencionReportRepository::class)->archivedMessages());

        return array_merge($reports, app(MantencionHoursExtraRepository::class)->messages());
    }

    public function test_history_preserves_filtered_order_totals_details_and_choices(): void
    {
        $history = app(MantencionHistoricoService::class);
        $items = $this->originalItems();
        $cases = [[], ['fuente' => 'reportes'], ['fuente' => 'horas_extra'], ['fuente' => 'inexistente'],
            ['desde' => '2026-09-11'], ['hasta' => '2026-09-10'], ['categoria' => 'eléctrica'], ['categoria' => 'agua'],
            ['estado_redmine' => 'Nueva'], ['estado_redmine' => 'en PROGRESO'], ['buscar' => 'Jose Munoz'],
            ['buscar' => 'Muñoz'], ['descripcion' => 'AREA %_'], ['descripcion' => 'normal'], ['usuario' => 'otro'],
            ['usuario' => '101'], ['scope' => 'todos'], ['desde' => '2027-01-01'], ['buscar' => 'no existe']];
        foreach ($cases as $filter) {
            foreach ([1, 2, 999] as $page) {
                $filter += ['scope' => 'asignados'];
                $expected = $history->filterRows($items, $filter, '101', ['Ana Pérez'], [1 => 'Nueva']);
                $result = app(MantencionHistoryRepository::class)->page($history, $filter, '101', ['Ana Pérez'], [1 => 'Nueva'], $page, 25);
                self::assertSame(count($expected), $result['total'], json_encode($filter));
                $actualPage = min($page, max(1, (int) ceil(count($expected) / 25)));
                self::assertSame($actualPage, $result['page']);
                self::assertSame(array_slice($expected, ($actualPage - 1) * 25, 25), $result['rows'], json_encode($filter));
                $users = $categories = [];
                foreach ($items as $row) {
                    $users[$row['asignado_a']] = $row['asignado_nombre'];
                    $categories[strtolower($row['categoria'])] = $row['categoria'];
                }
                ksort($users);
                ksort($categories);
                self::assertSame($users, $result['users']);
                self::assertSame($categories, $result['categories']);
            }
        }
    }

    public function test_history_uses_compatible_page_when_sql_projection_fails(): void
    {
        $history = app(MantencionHistoricoService::class);
        $args = [$history, ['scope' => 'asignados'], '101', ['Ana Pérez'], [1 => 'Nueva'], 1, 25];
        $expected = app(MantencionHistoryRepository::class)->page(...$args);
        self::assertGreaterThan(0, $expected['total']);

        $dispatcher = new Dispatcher(app());
        DB::connection()->setEventDispatcher($dispatcher);
        $failed = false;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$failed): void {
            if (! $failed && str_contains($event->sql, 'COUNT(*) OVER () AS _total')) {
                $failed = true;
                throw new \RuntimeException('Synthetic SQL history failure');
            }
        });
        try {
            self::assertSame($expected, app(MantencionHistoryRepository::class)->page(...$args));
            self::assertTrue($failed);
        } finally {
            DB::connection()->unsetEventDispatcher();
        }
    }

    public function test_only_visible_reports_load_full_descriptions_and_other_users_do_not_enter_page(): void
    {
        DB::enableQueryLog();
        $result = app(MantencionHistoryRepository::class)->page(app(MantencionHistoricoService::class), [], '101', [], [1 => 'Nueva'], 1, 25);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $full = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'select `r`.*')));
        self::assertCount(1, $full);
        self::assertLessThanOrEqual(25, count($full[0]['bindings']));
        foreach ($result['rows'] as $row) {
            self::assertSame('101', $row['asignado_a']);
            self::assertNotSame('', $row['descripcion']);
        }
        foreach ($queries as $q) {
            if (str_contains($q['query'], 'history_candidates')) {
                self::assertStringNotContainsString('`r`.`descripcion`', $q['query']);
            }
        }
    }

    public function test_sql_matches_previous_projection_for_unicode_zero_dates_dedupe_and_selector_collisions(): void
    {
        $texts = ['ÁREA %_', 'Straße Øresund', 'JosÃ© Muñoz', 'Kelvin İ', 'Ana 😊 Pérez', "\tAna\0 Pérez\n", '０１２', '0', ''];
        foreach (['ÉLÉCTRICA', 'élÉctrica', "\tAgua\n", 'AGUA'] as $i => $category) {
            DB::table('categorias')->insert(['id' => 10 + $i, 'nombre' => $category]);
        }
        foreach ($texts as $i => $text) {
            $id = 2000 + $i;
            DB::table('redmine_mantencion_reportes')->insert([
                'id' => $id, 'modulo_id' => 2, 'estado' => $i === 0 ? 'árchivado' : 'ARCHIVADO ',
                'fuente_id' => $i % 2 === 0 ? null : ' repeat ', 'numero_ticket_redmine' => $i % 3 === 0 ? 0 : null,
                'fecha_reporte' => '2026-09-12', 'solicitante' => $text, 'descripcion' => $text,
                'asignado_nombre' => $text, 'id_redmine_asignado' => $i % 2 === 0 ? "\t101\n" : '0101',
                'categoria_id' => 10 + $i % 4, 'estado_redmine' => $text, 'estado_id' => '1',
            ]);
            DB::table('horas_extra_grupo_reportes')->insert(['grupo_id' => 1, 'origen' => 'mantencion', 'reporte_id' => $id]);
        }
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        try {
            DB::statement("SET SESSION sql_mode = ''");
            foreach (['0000-00-00', '2026-00-00', '2026-01-00'] as $i => $date) {
                DB::table('redmine_mantencion_reportes')->insert(['id' => 2100 + $i, 'modulo_id' => 2, 'estado' => 'archivado',
                    'fuente_id' => 'zero-'.$i, 'fecha_reporte' => $date, 'id_redmine_asignado' => '101']);
            }
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
        }
        $repo = app(MantencionHistoryRepository::class);
        $previous = new \ReflectionMethod($repo, 'readPage');
        $history = app(MantencionHistoricoService::class);
        $cases = [[], ['scope' => 'todos'], ['fuente' => 'horas_extra'], ['fuente' => 'reportes'], ['fuente' => '0'],
            ['categoria' => 'ÉlÉctrica'], ['categoria' => 'agua'], ['desde' => '2025-12-01', 'hasta' => '2026-09-12'], ['usuario' => '0101']];
        foreach ($texts as $text) {
            $cases[] = ['buscar' => $text];
            $cases[] = ['descripcion' => $text];
            $cases[] = ['estado_redmine' => $text];
        }
        foreach ($cases as $filters) {
            foreach ([['Ana Pérez'], ['Straße Øresund', '0']] as $names) {
                $args = [$history, $filters, '101', $names, [1 => 'Nueva'], 1, 25];
                $allArgs = [$history, $filters, '101', $names, [1 => 'Nueva'], 1, 100];
                $summary = fn ($result) => array_map(fn ($row) => [$row['id'], $row['_fecha_norm']], $result['rows']);
                self::assertSame($summary($previous->invoke($repo, ...$allArgs)), $summary($repo->page(...$allArgs)), json_encode([$filters, $names]));
                self::assertSame($previous->invoke($repo, ...$args), $repo->page(...$args), json_encode([$filters, $names]));
            }
        }
    }

    public function test_sql_page_does_not_transfer_whole_history_metadata_to_php(): void
    {
        $repo = app(MantencionHistoryRepository::class);
        DB::enableQueryLog();
        $repo->page(app(MantencionHistoricoService::class), [], '101', [], [1 => 'Nueva'], 1, 25);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $references = array_values(array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select `id`, `_selection_key`')));
        self::assertCount(1, $references);
        self::assertStringContainsString('limit 25', $references[0]['query']);
        self::assertNotEmpty(array_filter($queries, fn ($q) => str_contains($q['query'], 'COUNT(*) OVER () AS _total')));
        self::assertEmpty(array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select * from (') && str_contains($q['query'], 'history_candidates')));
    }

    public function test_large_history_measures_bounded_detail_loading_against_legacy(): void
    {
        $description = str_repeat('Contenido sintético de descripción. ', 250);
        for ($batch = 0; $batch < 10; $batch++) {
            $rows = [];
            for ($i = 1; $i <= 150; $i++) {
                $rows[] = ['id' => 1000 + $batch * 150 + $i, 'modulo_id' => 2, 'estado' => 'archivado',
                    'fuente_id' => 'large-'.$batch.'-'.$i, 'fecha_reporte' => '2026-09-12', 'id_redmine_asignado' => '101',
                    'descripcion' => $description, 'numero_ticket_redmine' => 100000 + $batch * 150 + $i];
            }
            DB::table('redmine_mantencion_reportes')->insert($rows);
        }
        $history = app(MantencionHistoricoService::class);
        $measure = function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $read();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $peak = memory_get_peak_usage() - $memory;
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            DB::flushQueryLog();

            $plans = [];
            $fullRows = 0;
            foreach ($queries as $query) {
                if (! str_contains($query['query'], 'select `r`.*')) {
                    continue;
                }
                $plans[] = DB::select('EXPLAIN '.$query['query'], $query['bindings']);
                $fullRows += count(DB::select($query['query'], $query['bindings']));
            }

            return [$result, ['queries' => count($queries), 'full_rows' => $fullRows, 'peak_bytes' => $peak, 'elapsed_ms' => round($elapsed, 2), 'detail_plans' => $plans,
                'query_times' => array_map(fn ($q) => ['sql' => substr($q['query'], 0, 100), 'ms' => $q['time']], $queries)]];
        };
        [$old,$before] = $measure(function () use ($history): array {
            $filtered = $history->filterRows($this->originalItems(), [], '101', [], [1 => 'Nueva']);

            return ['total' => count($filtered), 'rows' => array_slice($filtered, 0, 25)];
        });
        [$new,$after] = $measure(fn () => app(MantencionHistoryRepository::class)->page($history, [], '101', [], [1 => 'Nueva'], 1, 25));
        $repository = app(MantencionHistoryRepository::class);
        $previousPage = new \ReflectionMethod($repository, 'readPage');
        [$projected, $projection] = $measure(fn () => $previousPage->invoke($repository, $history, [], '101', [], [1 => 'Nueva'], 1, 25));
        self::assertSame($old['total'], $new['total']);
        self::assertSame($old['rows'], $new['rows']);
        self::assertSame($projected, $new);
        self::assertLessThan($before['peak_bytes'], $after['peak_bytes']);
        file_put_contents(sys_get_temp_dir().'/nova-p06-history-metrics.json', json_encode(['history_reports' => 1585, 'visible' => 25, 'before' => $before, 'previous_projection' => $projection, 'after' => $after]), LOCK_EX);
    }
}
