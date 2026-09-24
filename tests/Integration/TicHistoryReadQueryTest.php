<?php

namespace Tests\Integration;

use App\Services\Database\SchemaBaseline;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\ViewServiceProvider;
use RedmineTic\Repositories\RedmineDataRepository;
use RedmineTic\Repositories\RedmineReportRepository;
use RedmineTic\Repositories\RedmineStatisticsRepository;
use RedmineTic\Support\DateSupport;

final class TicHistoryReadQueryTest extends IsolatedMariaDbTestCase
{
    private function referenceStatistics(RedmineDataRepository $repository, array $filters = []): array
    {
        [$from, $to] = DateSupport::statisticsDateRange($filters);
        $rows = DateSupport::filterReportsByDateRange(array_merge($repository->activeReports(), $repository->archivedReports()), $from, $to);

        return (new RedmineStatisticsRepository('redmine_tic', 'Redmine TIC'))
            ->statistics($rows, $from, $to, fn (string $date): string => DateSupport::normalizeDateKey($date));
    }

    private function uniqueStatisticsOrder(): void
    {
        // The real baseline has a BEFORE UPDATE timestamp trigger. Reinsert
        // this synthetic fixture so distinct historical timestamps are retained.
        $rows = DB::table('redmine_tic_reportes')->get()->map(static fn ($row): array => array_replace((array) $row, [
            'creado_at' => gmdate('Y-m-d H:i:s', strtotime('2026-09-01 00:00:00 UTC') + $row->id),
            'actualizado_at' => gmdate('Y-m-d H:i:s', strtotime('2026-09-02 00:00:00 UTC') + $row->id),
        ]))->all();
        DB::table('redmine_tic_reportes')->delete();
        foreach (array_chunk($rows, 100) as $batch) {
            DB::table('redmine_tic_reportes')->insert($batch);
        }
    }

    public function test_statistics_projection_preserves_filters_names_and_chart_order(): void
    {
        $this->uniqueStatisticsOrder();
        self::assertSame(120, DB::table('redmine_tic_reportes')->distinct()->count('creado_at'));
        DB::table('redmine_tic_reportes')->where('id', 7)->update(['estado' => '']);
        DB::table('redmine_tic_reportes')->where('id', 14)->update(['estado' => ' ERROR ']);
        foreach ([[], ['desde' => '02-09-2026'], ['hasta' => '03/09/2026'],
            ['desde' => '2026-09-04', 'hasta' => '2026-09-02'], ['desde' => 'invalid'], ['desde' => '2027-01-01']] as $filters) {
            $expected = $this->referenceStatistics(new RedmineDataRepository, $filters);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $actual = (new RedmineDataRepository)->nativeSectionData('estadisticas', 'todos', $filters)['stats'];
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            self::assertSame($expected, $actual);
            $reads = array_filter($queries, fn ($q) => str_contains($q['query'], 'from `redmine_tic_reportes`'));
            self::assertNotEmpty($reads);
            foreach ($reads as $query) {
                self::assertStringNotContainsString('select *', $query['query']);
                self::assertStringNotContainsString('descripcion', $query['query']);
                self::assertStringNotContainsString('mensaje', $query['query']);
            }
        }
        DB::table('usuarios_nova')->where('redmine_id', '101')->update(['nombre' => 'Renombrada']);
        self::assertSame($this->referenceStatistics(new RedmineDataRepository), (new RedmineDataRepository)->statistics());
    }

    public function test_statistics_keeps_tie_fallback_and_previously_loaded_reports(): void
    {
        $expected = $this->referenceStatistics(new RedmineDataRepository);
        DB::enableQueryLog();
        self::assertSame($expected, (new RedmineDataRepository)->statistics());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertNotEmpty(array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select * from `redmine_tic_reportes`')));

        $this->uniqueStatisticsOrder();
        $repo = new RedmineDataRepository;
        $repo->activeReports();
        DB::table('redmine_tic_reportes')->where('id', 7)->update(['estado' => 'error']);
        self::assertSame($this->referenceStatistics($repo), $repo->statistics());
    }

    public function test_statistics_preserves_legacy_catalogue_text_and_zero_dates(): void
    {
        $this->uniqueStatisticsOrder();
        Schema::table('redmine_tic_reportes', function ($table): void {
            $table->string('categoria')->nullable();
            $table->string('unidad_solicitante')->nullable();
        });
        $row = (array) DB::table('redmine_tic_reportes')->where('id', 1)->first();
        $row = array_replace($row, ['categoria_catalogo_id' => null, 'unidad_solicitante_catalogo_id' => null,
            'categoria' => 'Categoría antigua', 'unidad_solicitante' => 'Unidad antigua', 'fecha_inicio' => '2026-00-00']);
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        try {
            DB::statement("SET SESSION sql_mode = ''");
            DB::table('redmine_tic_reportes')->where('id', 1)->delete();
            DB::table('redmine_tic_reportes')->insert($row);
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
        }
        $filters = ['desde' => '2020-01-01', 'hasta' => '2030-12-31'];
        $expected = $this->referenceStatistics(new RedmineDataRepository, $filters);
        $actual = (new RedmineDataRepository)->statistics($filters);
        self::assertSame($expected, $actual);
        self::assertSame(1, $actual['by_category']['Categoría antigua']);
        self::assertSame(1, $actual['by_unit']['Unidad antigua']);
        self::assertNotNull((new RedmineReportRepository('redmine_tic', 'Redmine TIC'))->statisticsRows(1, fn () => ''));
    }

    public function test_statistics_large_reports_do_not_transfer_unused_text(): void
    {
        $seed = (array) DB::table('redmine_tic_reportes')->where('id', 1)->first();
        for ($batch = 0; $batch < 10; $batch++) {
            $rows = [];
            for ($i = 1; $i <= 100; $i++) {
                $rows[] = array_replace($seed, ['id' => 1000 + $batch * 100 + $i,
                    'descripcion' => str_repeat('Detalle extenso. ', 2000), 'mensaje' => str_repeat('Mensaje. ', 1000)]);
            }
            DB::table('redmine_tic_reportes')->insert($rows);
        }
        unset($rows, $seed);
        $this->uniqueStatisticsOrder();
        self::assertNotNull((new RedmineReportRepository('redmine_tic', 'Redmine TIC'))->statisticsRows(1, fn () => ''));
        $measure = static function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            $value = $read();

            return [$value, ['elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3), 'peak_bytes' => memory_get_peak_usage() - $memory]];
        };
        [$expected, $before] = $measure(fn () => $this->referenceStatistics(new RedmineDataRepository));
        [$actual, $after] = $measure(fn () => (new RedmineDataRepository)->statistics());
        self::assertSame($expected, $actual);
        self::assertLessThan($before['peak_bytes'] / 4, $after['peak_bytes']);
        file_put_contents(sys_get_temp_dir().'/nova-p06-tic-statistics-metrics.json', json_encode(compact('before', 'after')), LOCK_EX);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (version_compare(explode('-', DB::selectOne('SELECT VERSION() AS version')->version)[0], '12.3.2', '<')) {
            self::markTestSkipped('Requires MariaDB 12.3.2 baseline.');
        }
        $schema = new SchemaBaseline;
        $schema->bootstrap(DB::connection(), $schema->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
        DB::table('modulos_nova')->insert([
            ['id' => 1, 'clave_modulo' => 'redmine_tic', 'nombre' => 'TIC'],
            ['id' => 2, 'clave_modulo' => 'other', 'nombre' => 'Otro'],
        ]);
        foreach (['101' => ['Ana', 'Pérez'], '202' => ['Otra', 'Persona'], '0101' => ['Ana', 'Perez']] as $id => $name) {
            $userId = DB::table('usuarios_nova')->insertGetId(['uuid' => 'user-'.$id, 'usuario' => 'user'.$id,
                'redmine_id' => (string) $id, 'nombre' => $name[0], 'apellido' => $name[1], 'password' => 'synthetic', 'rol' => 'usuario', 'estado' => 'activo']);
            DB::table('permisos_usuario_modulo')->insert(['usuario_id' => $userId, 'modulo_id' => 1, 'permitido' => 1]);
        }
        foreach (['Red', 'Réd', '0101', '101', '0', ' ÉLÉCTRICA '] as $i => $name) {
            DB::table('catalogos_modulo')->insert(['id' => $i + 1, 'modulo_id' => 1, 'tipo' => $i % 2 ? 'unidad' : 'categoria',
                'clave_externa' => 'cat-'.$i, 'nombre' => $name, 'activo' => $i % 2]);
        }
        DB::table('catalogos_modulo')->insert(['id' => 20, 'modulo_id' => 2, 'tipo' => 'categoria', 'clave_externa' => 'foreign', 'nombre' => 'Ajena']);
        DB::table('horas_extra_grupos')->insert([
            ['id' => 1, 'fecha' => '2026-09-01'], ['id' => 2, 'fecha' => '2026-09-02'],
        ]);
        for ($id = 1; $id <= 120; $id++) {
            DB::table('redmine_tic_reportes')->insert([
                'id' => $id, 'modulo_id' => $id % 11 === 0 ? 2 : 1, 'estado' => $id % 7 === 0 ? 'pendiente' : 'archivado',
                'redmine_id' => $id % 9 === 0 ? null : 1000 + $id % 13,
                'asunto' => ['Falla red', 'Revisión', 'ÁREA', '0'][$id % 4],
                'descripcion' => ['Texto de prueba', 'Pérez %_', 'Straße Øresund', "\tCONTROL\n", 'ÁRBOL'][$id % 5],
                'asignado_a' => ['101', '202', '0101', null][$id % 4],
                'solicitante' => $id % 3 ? 'Ángela' : 'Angel', 'mensaje' => 'Mensaje '.$id,
                'fecha' => '2026-09-01', 'fecha_inicio' => $id % 8 === 0 ? null : '2026-09-'.str_pad((string) (1 + $id % 5), 2, '0', STR_PAD_LEFT),
                'categoria_catalogo_id' => $id % 10 ? 1 + $id % 6 : 20,
                'unidad_catalogo_id' => 1 + $id % 6, 'unidad_solicitante_catalogo_id' => 1 + $id % 6,
                'unidad_texto' => $id % 4 ? '' : 'Manual',
                'origen' => $id % 4 ? 'manual' : "\tTeLeGrAm\n", 'chat_id_telegram' => $id % 6 ? null : '123',
                'estado_redmine' => ['Nueva', 'nueva', 'Revisión', 'REVÍSIÓN', '0', null][$id % 6],
                'creado_at' => '2026-09-01 12:00:00',
                'actualizado_at' => gmdate('Y-m-d H:i:s', strtotime('2026-09-02 12:00:00 UTC') + $id),
            ]);
            if ($id % 3 === 0) {
                foreach ([1, 2] as $group) {
                    DB::table('horas_extra_grupo_reportes')->insert(['grupo_id' => $group, 'origen' => 'tic', 'reporte_id' => $id]);
                }
            }
            if ($id % 4 === 0) {
                DB::table('horas_extra_grupo_reportes')->insert(['grupo_id' => 1, 'origen' => 'mantencion', 'reporte_id' => $id]);
            }
        }
    }

    private function users(): array
    {
        return [[], ['id' => 'nobody', 'legacy' => ['permisos' => ['historico_scope' => 'asignados']]],
            ['redmine_id' => '101', 'legacy' => ['permisos' => ['historico_scope' => 'todos']]],
            ['redmine_id' => '101', 'name' => 'Ana', 'apellido' => 'Pérez', 'legacy' => ['permisos' => ['historico_scope' => 'asignados']]],
            ['redmine_id' => '0101', 'legacy' => ['permisos' => ['historico' => 'asignados']]],
            ['redmine_id' => '', 'name' => 'Ana Pérez', 'legacy' => ['permisos' => ['historico_scope' => 'asignados']]],
        ];
    }

    private function viewResult(array $page, array $config): array
    {
        $statuses = [];
        foreach ($config['estados'] as $status) {
            $statuses[$status['nombre']] = $status['nombre'];
        }
        foreach ($page['statuses'] as $name) {
            $statuses[$name] = $name;
        }
        ksort($statuses, SORT_NATURAL | SORT_FLAG_CASE);

        return ['pagedRows' => $page['rows'], 'totalFiltered' => $page['total'], 'totalPages' => $page['pages'],
            'currentPage' => $page['page'], 'hoursRows' => $page['hours'], 'categories' => $page['categories'], 'redmineFilterStatuses' => $statuses];
    }

    public function test_sql_page_matches_original_history_and_screen_filters(): void
    {
        $reference = require __DIR__.'/fixtures/tic_history_view_reference.php';
        self::assertCount(3, (new RedmineDataRepository)->users(false), 'S31 does not contain usuarios_nova.email.');
        self::assertContains('Ana Pérez', array_column((new RedmineDataRepository)->history($this->users()[2]), 'asignado_nombre'));
        $config = ['estados' => [['id' => 1, 'nombre' => 'Nueva'], ['id' => 2, 'nombre' => 'Configurado']]];
        $filters = [[], ['page' => 2, 'per_page' => 25], ['page' => 999, 'per_page' => 17], ['per_page' => 100],
            ['desde' => '03-09-2026'], ['hasta' => '02/09/2026'], ['fuente' => 'telegram'], ['fuente' => 'manual'], ['fuente' => '0'],
            ['categoria' => 'Red'], ['categoria' => 'Réd'], ['categoria' => '101'], ['categoria' => '0101'],
            ['estado_redmine' => 'revision'], ['estado_redmine' => 'REVÍSIÓN'], ['buscar' => 'perez'], ['buscar' => 'ÁREA'],
            ['buscar' => '101'], ['descripcion' => '%_'], ['descripcion' => 'strasse'], ['descripcion' => 'ÁRBOL'],
            ['desde' => '2026-09-03', 'fuente' => 'telegram', 'buscar' => 'Mensaje']];
        foreach ($this->users() as $user) {
            $old = (new RedmineDataRepository)->history($user);
            foreach ($filters as $filter) {
                $page = (new RedmineDataRepository)->historyPage($filter, $user);
                self::assertNotNull($page);
                self::assertSame($reference($old, $filter, $config), $this->viewResult($page, $config), json_encode([$user, $filter]));
            }
        }
    }

    public function test_large_history_loads_only_page_details_and_preserves_result(): void
    {
        $seed = (array) DB::table('redmine_tic_reportes')->where('id', 1)->first();
        for ($batch = 0; $batch < 10; $batch++) {
            $rows = [];
            for ($i = 1; $i <= 100; $i++) {
                $rows[] = array_replace($seed, ['id' => 1000 + $batch * 100 + $i, 'descripcion' => str_repeat('Detalle grande. ', 2000),
                    'asignado_a' => '101', 'fecha_inicio' => '2026-09-15',
                    'actualizado_at' => gmdate('Y-m-d H:i:s', strtotime('2026-09-03 00:00:00 UTC') + $batch * 100 + $i)]);
            }
            DB::table('redmine_tic_reportes')->insert($rows);
        }
        unset($rows, $seed);
        $user = $this->users()[2];
        $config = ['estados' => []];
        $reference = require __DIR__.'/fixtures/tic_history_view_reference.php';
        $measure = function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $read();
            $stats = ['elapsed_ms' => round((hrtime(true) - $start) / 1e6, 2), 'peak_bytes' => memory_get_peak_usage() - $memory,
                'queries' => count(DB::getQueryLog())];
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            DB::flushQueryLog();

            return [$result, $stats, $queries];
        };
        [$old, $before] = $measure(fn () => $reference((new RedmineDataRepository)->history($user), [], $config));
        [$new, $after, $queries] = $measure(fn () => $this->viewResult((new RedmineDataRepository)->historyPage([], $user), $config));
        self::assertSame($old, $new);
        self::assertLessThan($before['peak_bytes'], $after['peak_bytes']);
        $details = array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select * from `redmine_tic_reportes`'));
        self::assertCount(1, $details);
        foreach ($details as $query) {
            self::assertCount(25, DB::select($query['query'], $query['bindings']));
        }
        self::assertEmpty(array_filter($queries, fn ($q) => str_contains($q['query'], 'descripcion')));
        file_put_contents(sys_get_temp_dir().'/nova-p06-tic-history-metrics.json', json_encode([
            'reports' => 1120, 'visible' => 25, 'before' => $before, 'after' => $after,
        ]), LOCK_EX);
    }

    public function test_zero_dates_text_boundaries_and_live_name_changes_keep_previous_semantics(): void
    {
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        try {
            DB::statement("SET SESSION sql_mode = ''");
            foreach (['0000-00-00', '2026-00-00', '2026-01-00'] as $i => $date) {
                DB::table('redmine_tic_reportes')->where('id', $i + 1)->update(['fecha_inicio' => $date]);
            }
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
        }
        DB::table('redmine_tic_reportes')->where('id', 5)->update([
            'solicitante' => "\0 %_ ", 'descripcion' => "\0Straße\r\nÁRBOL\0", 'unidad_texto' => '0',
        ]);
        DB::table('usuarios_nova')->where('redmine_id', '0101')->update(['nombre' => 'Persona', 'apellido' => 'Renombrada']);
        $reference = require __DIR__.'/fixtures/tic_history_view_reference.php';
        $config = ['estados' => []];
        foreach ([$this->users()[2], $this->users()[3]] as $user) {
            foreach ([[], ['desde' => '2026-01-01'], ['buscar' => 'renombrada'], ['buscar' => 'área'], ['buscar' => "\0 %_ "],
                ['descripcion' => "\0Straße\r\nÁRBOL\0"], ['estado_redmine' => '0'], ['hasta' => '2025-12-31', 'per_page' => 100]] as $filters) {
                $old = $reference((new RedmineDataRepository)->history($user), $filters, $config);
                $actual = $this->viewResult((new RedmineDataRepository)->historyPage($filters, $user), $config);
                self::assertSame($old, $actual, json_encode($filters));
            }
        }
    }

    public function test_history_page_returns_to_complete_reader_when_sql_projection_fails(): void
    {
        $user = $this->users()[2];
        self::assertNotNull((new RedmineDataRepository)->historyPage([], $user));

        $dispatcher = new Dispatcher(app());
        DB::connection()->setEventDispatcher($dispatcher);
        $failed = false;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$failed): void {
            if (! $failed && str_contains($event->sql, 'ROW_NUMBER() OVER')) {
                $failed = true;
                throw new \RuntimeException('Synthetic SQL history failure');
            }
        });
        try {
            $section = (new RedmineDataRepository)->nativeSectionData('historico', 'todos', [], $user);
            self::assertNull($section['historyPage']);
            self::assertSame((new RedmineDataRepository)->history($user), $section['rows']);
            self::assertTrue($failed);
        } finally {
            DB::connection()->unsetEventDispatcher();
        }
    }

    public function test_equal_sort_keys_retain_the_old_reader_and_pagination(): void
    {
        DB::table('redmine_tic_reportes')->update(['fecha_inicio' => '2026-09-01', 'actualizado_at' => '2026-09-02 12:00:00']);
        $user = $this->users()[2];
        $filters = ['page' => 2, 'per_page' => 25];
        $repo = new RedmineDataRepository;
        self::assertNull($repo->historyPage($filters, $user));
        $section = $repo->nativeSectionData('historico', 'todos', $filters, $user);
        self::assertNull($section['historyPage']);
        self::assertSame((new RedmineDataRepository)->history($user), $section['rows']);
        $reference = require __DIR__.'/fixtures/tic_history_view_reference.php';
        self::assertCount(25, $reference($section['rows'], $filters, $section['config'])['pagedRows']);
    }

    public function test_native_section_uses_the_sql_page_and_keeps_other_modules_out(): void
    {
        $user = $this->users()[2];
        $section = (new RedmineDataRepository)->nativeSectionData('historico', 'todos', ['page' => 2], $user);
        self::assertSame([], $section['rows']);
        self::assertNotNull($section['historyPage']);
        self::assertSame(2, $section['historyPage']['page']);
        foreach ($section['historyPage']['rows'] as $row) {
            self::assertNotSame(0, ((int) $row['id']) % 11);
        }
    }

    public function test_rendered_screen_is_identical_with_sql_page_or_previous_rows(): void
    {
        $temporary = sys_get_temp_dir().'/nova-tic-view-'.bin2hex(random_bytes(6));
        mkdir($temporary, 0700);
        $app = app();
        $app['config']->set('view', ['paths' => [dirname(__DIR__, 2).'/RedmineTic/views'], 'compiled' => $temporary]);
        $app->register(EventServiceProvider::class);
        $app->register(FilesystemServiceProvider::class);
        $app->register(ViewServiceProvider::class);
        $request = Request::create('http://nova.invalid/historico?page=2');
        $session = new Store('synthetic', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $app->instance('session', $session);
        $app->instance('request', $request);
        $app->instance('url', new UrlGenerator(new RouteCollection, $request));
        $data = ['config' => ['estados' => [], 'platform_url' => 'http://redmine.invalid'],
            'redmineRoute' => fn ($route, $parameters = []) => '/synthetic/'.str_replace('.', '/', $route),
            'redmineMaintenance' => ['enabled' => false], 'canHistoryActionsPermission' => true];
        $user = $this->users()[2];
        try {
            $before = $app['view']->make('native-sections.history', $data + ['rows' => (new RedmineDataRepository)->history($user)])->render();
            $after = $app['view']->make('native-sections.history', $data + ['rows' => [],
                'historyPage' => (new RedmineDataRepository)->historyPage(['page' => 2], $user)])->render();
            self::assertSame($before, $after);
            self::assertStringContainsString('history-estado-redmine', $after);
        } finally {
            foreach (glob($temporary.'/*') as $file) {
                unlink($file);
            }
            rmdir($temporary);
        }
    }

    public function test_maintenance_header_does_not_reload_the_whole_history(): void
    {
        $expected = (new RedmineDataRepository)->dashboardSummary()['maintenance'];
        DB::flushQueryLog();
        DB::enableQueryLog();
        $actual = (new RedmineDataRepository)->maintenanceStatus();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertSame($expected, $actual);
        self::assertEmpty(array_filter($queries, fn ($q) => str_contains($q['query'], 'from `redmine_tic_reportes`')));
    }

    public function test_large_unsigned_report_id_preserves_the_old_hours_projection(): void
    {
        $id = '9223372036854775808';
        DB::table('redmine_tic_reportes')->insert(['id' => $id, 'modulo_id' => 1, 'estado' => 'archivado',
            'fecha_inicio' => '2026-09-20', 'asignado_a' => '101', 'hora_extra' => 1]);
        DB::table('horas_extra_grupo_reportes')->insert(['grupo_id' => 1, 'origen' => 'tic', 'reporte_id' => $id]);
        $user = $this->users()[2];
        $reference = require __DIR__.'/fixtures/tic_history_view_reference.php';
        $expected = $reference((new RedmineDataRepository)->history($user), [], ['estados' => []]);
        $page = (new RedmineDataRepository)->historyPage([], $user);
        self::assertSame($id, $page['rows'][0]['id']);
        self::assertSame($expected, $this->viewResult($page, ['estados' => []]));
    }
}
