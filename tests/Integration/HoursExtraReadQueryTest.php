<?php

namespace Tests\Integration;

use App\Modulos\RedmineMantencion\Repositories\MantencionHoursExtraRepository;
use App\Modulos\RedmineMantencion\Services\MantencionHorasExtraService;
use App\Services\Database\SchemaBaseline;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use RedmineTic\Repositories\RedmineDataRepository;

final class HoursExtraReadQueryTest extends IsolatedMariaDbTestCase
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
        foreach ([1 => '101', 2 => '202'] as $id => $redmineId) {
            DB::table('usuarios_nova')->insert(['id' => $id, 'uuid' => 'hours-'.$id, 'usuario' => 'hours-'.$id,
                'redmine_id' => $redmineId, 'nombre' => 'Persona '.$id, 'apellido' => 'Prueba',
                'password' => 'synthetic', 'rol' => 'usuario', 'estado' => 'activo']);
            DB::table('permisos_usuario_modulo')->insert(['usuario_id' => $id, 'modulo_id' => 1, 'permitido' => 1]);
        }
        for ($id = 1; $id <= 36; $id++) {
            $date = ['2026-09-01', '2025-03-12', null, '2026-09-02'][$id % 4];
            DB::table('redmine_tic_reportes')->insert([
                'id' => $id, 'modulo_id' => $id % 7 ? 1 : 2, 'estado' => $id % 5 ? 'archivado' : 'pendiente',
                'asignado_a' => $id % 3 ? 101 : 202, 'fecha' => '2024-01-01', 'fecha_inicio' => $date,
                'asunto' => 'TIC '.$id, 'descripcion' => 'Detalle TIC '.$id, 'hora_extra' => $id % 2,
                'actualizado_at' => '2026-09-01 12:00:00',
            ]);
            DB::table('redmine_mantencion_reportes')->insert([
                'id' => $id, 'modulo_id' => $id % 7 ? 2 : 1, 'estado' => $id % 5 ? 'archivado' : 'pendiente',
                'fuente' => $id % 2 ? 'manual' : 'core', 'fuente_id' => $id % 6 ? 'message-'.$id : 'repeated',
                'id_redmine_asignado' => ['101', '202', '0101', ' 101 '][$id % 4],
                'fecha_reporte' => $date, 'fecha_inicio' => '2024-01-01', 'hora_reporte' => '18:30:00',
                'asunto' => 'Mantención '.$id, 'descripcion' => 'Detalle Mantención '.$id, 'hora_extra' => $id % 2,
            ]);
        }
        for ($id = 1; $id <= 14; $id++) {
            DB::table('horas_extra_grupos')->insert([
                'id' => $id, 'usuario_id' => $id <= 2 ? $id : null,
                'fecha' => ['2026-09-01', '2026-09-02', '2025-03-12'][$id % 3],
                'hora_inicio' => $id % 3 ? '18:00:00' : null,
                'hora_fin' => $id % 2 ? '21:30:00' : '02:00:00',
            ]);
            foreach (['tic', 'mantencion'] as $origin) {
                foreach ($id <= 12 ? [1, 3, 5, 7, 12, 20 + $id] : [999] as $reportId) {
                    DB::table('horas_extra_grupo_reportes')->insert([
                        'grupo_id' => $id, 'origen' => $origin, 'reporte_id' => $reportId,
                        'actualizado_at' => '2026-09-01 12:00:00',
                    ]);
                }
            }
        }
    }

    private function ticReference(string $method, array $arguments = []): array
    {
        $readers = require __DIR__.'/fixtures/tic_hours_reference.php';

        return $readers[$method]->call(new RedmineDataRepository, ...$arguments);
    }

    private function mantencionReference(): array
    {
        $reader = require __DIR__.'/fixtures/mantencion_hours_reference.php';

        return $reader->call(app(MantencionHoursExtraRepository::class));
    }

    public function test_tic_groups_and_screen_keep_dates_order_scope_filters_and_totals(): void
    {
        $expected = $this->ticReference('groups');
        DB::enableQueryLog();
        self::assertSame($expected, (new RedmineDataRepository)->hoursExtra());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $reads = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'from `redmine_tic_reportes`')));
        self::assertCount(1, $reads);
        self::assertStringContainsString('`id` in (', $reads[0]['query']);
        self::assertStringContainsString('`modulo_id` = ?', $reads[0]['query']);
        self::assertStringContainsString('`estado` = ?', $reads[0]['query']);
        self::assertCount(14, $expected, 'Empty report groups are retained by TIC.');
        foreach ([[], ['id' => '101'], ['id' => '202'], ['id' => '0101'], ['redmine_id' => '101'], ['id' => 'missing']] as $user) {
            foreach ([[], ['filters' => '1'], ['mes' => '', 'anio' => ''], ['mes' => '9', 'anio' => '2026'],
                ['mes' => '03', 'anio' => '2025'], ['mes' => 'invalid', 'anio' => 'invalid'], ['anio' => '2030']] as $filters) {
                self::assertSame($this->ticReference('screen', [$filters, $user]),
                    (new RedmineDataRepository)->hoursExtraData($filters, $user));
            }
        }
    }

    public function test_mantencion_preserves_sql_report_order_duplicates_and_shared_hour_precedence(): void
    {
        $expected = $this->mantencionReference();
        $actual = app(MantencionHoursExtraRepository::class)->groups();
        self::assertSame($expected, $actual);
        self::assertCount(12, $actual, 'Mantención omits groups with no matching archived reports.');
        $service = new MantencionHorasExtraService;
        foreach (['', '101', '0101', '202', 'missing'] as $user) {
            self::assertSame($service->filterGroupsForUser($service->deduplicateGroupsBySharedDate($expected), $user),
                $service->filterGroupsForUser($service->deduplicateGroupsBySharedDate($actual), $user));
        }
        // Arrays reused internally must still support independent downstream edits.
        $actual[0]['reports'][0]['descripcion'] = 'Changed copy';
        self::assertSame($expected[1], $actual[1]);
        self::assertSame($expected, app(MantencionHoursExtraRepository::class)->groups());
    }

    public function test_readers_preserve_bigint_pivot_conversion_and_zero_dates(): void
    {
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        try {
            DB::statement("SET SESSION sql_mode = ''");
            foreach (['redmine_tic_reportes', 'redmine_mantencion_reportes'] as $table) {
                $seed = (array) DB::table($table)->where('id', 1)->first();
                foreach (['9223372036854775807', '9223372036854775808'] as $id) {
                    DB::table($table)->insert(array_replace($seed, ['id' => $id, 'fecha_inicio' => '2026-00-00']));
                }
            }
            DB::table('horas_extra_grupos')->insert(['id' => 99, 'fecha' => '2026-00-00']);
            foreach (['tic', 'mantencion'] as $origin) {
                foreach (['9223372036854775807', '9223372036854775808'] as $id) {
                    DB::table('horas_extra_grupo_reportes')->insert(['grupo_id' => 99, 'origen' => $origin, 'reporte_id' => $id]);
                }
            }
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
        }
        self::assertSame($this->ticReference('groups'), (new RedmineDataRepository)->hoursExtra());
        self::assertSame($this->ticReference('screen', [['filters' => 1], ['id' => '101']]),
            (new RedmineDataRepository)->hoursExtraData(['filters' => 1], ['id' => '101']));
        self::assertSame($this->mantencionReference(), app(MantencionHoursExtraRepository::class)->groups());
        self::assertSame($this->mantencionScreenReference('101', '', ''),
            (new MantencionHorasExtraService)->screenData('101', '', '', '2026'));
    }

    private function mantencionScreenReference(string $user, string $month, string $year): array
    {
        $select = require __DIR__.'/fixtures/mantencion_hours_screen_reference.php';

        return $select($this->mantencionReference(), $user, $month, $year, '2026');
    }

    public function test_mantencion_screen_preserves_merged_details_public_ids_years_and_filters(): void
    {
        // Mixed source IDs, blank merge fields and independent owners exercise
        // the legacy public-ID merge rather than a simple SQL assignee filter.
        foreach ([1 => ['fuente_id' => '0'], 3 => ['fuente_id' => ' repeated ', 'descripcion' => ''],
            7 => ['fuente_id' => '', 'id_redmine_asignado' => '0101'],
            12 => ['fuente_id' => 'repeated', 'asunto' => '', 'id_redmine_asignado' => ''],
            21 => ['fuente_id' => 'repeated', 'descripcion' => 'detalle que debe combinarse']] as $id => $changes) {
            DB::table('redmine_mantencion_reportes')->where('id', $id)->update($changes);
        }
        $service = new MantencionHorasExtraService;
        foreach (['', '101', '0101', '202', 'missing'] as $user) {
            foreach ([['', ''], ['9', '2026'], ['03', '2025'], ['', '2030'], ['invalid', ''], ['9', '02026'], ['', 'invalid']] as [$month, $year]) {
                self::assertSame($this->mantencionScreenReference($user, $month, $year),
                    $service->screenData($user, $month, $year, '2026'));
            }
        }
        // Subsequent reads see edited hours; no metadata or details persist in a global cache.
        DB::table('horas_extra_grupos')->where('fecha', '2026-09-01')->update(['hora_inicio' => '20:00:00']);
        self::assertSame($this->mantencionScreenReference('101', '9', '2026'), $service->screenData('101', '9', '2026', '2026'));
    }

    public function test_screen_queries_do_not_fetch_details_outside_scope_and_period(): void
    {
        $ticFilters = ['mes' => '9', 'anio' => '2026'];
        $ticExpected = $this->ticReference('screen', [$ticFilters, ['id' => '101']]);
        $mantExpected = $this->mantencionScreenReference('101', '9', '2026');
        DB::flushQueryLog();
        DB::enableQueryLog();
        self::assertSame($ticExpected, (new RedmineDataRepository)->hoursExtraData($ticFilters, ['id' => '101']));
        self::assertSame($mantExpected, (new MantencionHorasExtraService)->screenData('101', '9', '2026', '2026'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $ticQueries = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'from `redmine_tic_reportes`')));
        $mantQueries = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'from `redmine_mantencion_reportes`')));
        self::assertCount(2, $ticQueries);
        self::assertCount(2, $mantQueries);
        self::assertStringStartsWith('select `id`, `asignado_a`, `fecha_inicio`', $ticQueries[0]['query']);
        self::assertStringStartsWith('select `r`.`id`, `r`.`fuente_id`, `r`.`id_redmine_asignado`', $mantQueries[0]['query']);
        foreach ([$ticQueries[1], $mantQueries[1]] as $query) {
            self::assertStringContainsString(' in (', $query['query']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        self::assertSame([], (new RedmineDataRepository)->hoursExtraData($ticFilters, ['id' => 'absent'])['rows']);
        self::assertSame([], (new MantencionHorasExtraService)->screenData('101', '9', '2099', '2026')['grupos']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            self::assertFalse(str_starts_with($query['query'], 'select * from `redmine_tic_reportes`'));
            self::assertFalse(str_starts_with($query['query'], 'select `r`.*'));
        }
    }

    public function test_screen_memory_is_reduced_for_large_linked_reports_outside_scope_or_period(): void
    {
        $ticSeed = (array) DB::table('redmine_tic_reportes')->where('id', 1)->first();
        $mantSeed = (array) DB::table('redmine_mantencion_reportes')->where('id', 1)->first();
        DB::table('horas_extra_grupos')->insert([
            ['id' => 100, 'fecha' => '2023-01-01'], ['id' => 101, 'fecha' => '2026-09-01'],
        ]);
        for ($batch = 0; $batch < 4; $batch++) {
            $tic = $mant = $links = [];
            for ($i = 1; $i <= 100; $i++) {
                $id = 1000 + $batch * 100 + $i;
                $owner = $i % 2 ? '101' : '202';
                $date = $i % 2 ? '2023-01-01' : '2026-09-01';
                $description = str_repeat('Detalle extenso de otra jornada. ', 1500);
                $tic[] = array_replace($ticSeed, ['id' => $id, 'asignado_a' => $owner, 'fecha_inicio' => $date, 'descripcion' => $description]);
                $mant[] = array_replace($mantSeed, ['id' => $id, 'fuente_id' => 'large-'.$id, 'id_redmine_asignado' => $owner, 'fecha_inicio' => $date, 'descripcion' => $description]);
                foreach (['tic', 'mantencion'] as $origin) {
                    $links[] = ['grupo_id' => $i % 2 ? 100 : 101, 'origen' => $origin, 'reporte_id' => $id];
                }
            }
            DB::table('redmine_tic_reportes')->insert($tic);
            DB::table('redmine_mantencion_reportes')->insert($mant);
            DB::table('horas_extra_grupo_reportes')->insert($links);
        }
        unset($tic, $mant, $links, $description);
        $filters = ['mes' => '9', 'anio' => '2026'];
        $cases = [
            'tic' => [fn () => $this->ticReference('screen', [$filters, ['id' => '101']]),
                fn () => (new RedmineDataRepository)->hoursExtraData($filters, ['id' => '101'])],
            'mantencion' => [fn () => $this->mantencionScreenReference('101', '9', '2026'),
                fn () => (new MantencionHorasExtraService)->screenData('101', '9', '2026', '2026')],
        ];
        $metrics = [];
        foreach ($cases as $module => [$reference, $optimized]) {
            $reference();
            $optimized();
            [$expected, $before] = $this->measure($reference);
            [$actual, $after] = $this->measure($optimized);
            self::assertSame($expected, $actual);
            self::assertLessThan($before['peak_bytes'] / 4, $after['peak_bytes']);
            $metrics[$module] = compact('before', 'after');
        }
        file_put_contents(sys_get_temp_dir().'/nova-p06-hours-screen-metrics.json', json_encode($metrics), LOCK_EX);
    }

    public function test_screen_reads_use_one_snapshot_when_reports_change_between_projection_and_details(): void
    {
        $filters = ['mes' => '', 'anio' => ''];
        $ticExpected = $this->ticReference('screen', [$filters, ['id' => '101']]);
        $mantExpected = $this->mantencionScreenReference('101', '', '');
        $dispatcher = new Dispatcher;
        DB::connection()->setEventDispatcher($dispatcher);
        $changed = [];
        $dispatcher->listen(QueryExecuted::class, function ($event) use (&$changed): void {
            foreach (['tic' => 'select `id`, `asignado_a`, `fecha_inicio`',
                'mantencion' => 'select `r`.`id`, `r`.`fuente_id`, `r`.`id_redmine_asignado`'] as $module => $prefix) {
                if (! isset($changed[$module]) && str_starts_with($event->sql, $prefix)) {
                    $changed[$module] = true;
                    DB::connection('concurrent')->table('redmine_'.$module.'_reportes')->where('id', $module === 'tic' ? 1 : 3)->update([
                        'descripcion' => 'Concurrent change', $module === 'tic' ? 'asignado_a' : 'id_redmine_asignado' => '202',
                    ]);
                }
            }
        });
        try {
            self::assertSame($ticExpected, (new RedmineDataRepository)->hoursExtraData($filters, ['id' => '101']));
            self::assertSame($mantExpected, (new MantencionHorasExtraService)->screenData('101', '', '', '2026'));
            self::assertSame(['tic' => true, 'mantencion' => true], $changed);
        } finally {
            DB::connection()->unsetEventDispatcher();
        }
        self::assertSame($this->ticReference('screen', [$filters, ['id' => '101']]),
            (new RedmineDataRepository)->hoursExtraData($filters, ['id' => '101']));
        self::assertSame($this->mantencionScreenReference('101', '', ''),
            (new MantencionHorasExtraService)->screenData('101', '', '', '2026'));
    }

    private function measure(callable $read): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memory = memory_get_usage();
        $start = hrtime(true);
        $value = $read();

        return [$value, ['elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3), 'peak_bytes' => memory_get_peak_usage() - $memory]];
    }

    public function test_tic_does_not_load_large_archives_unrelated_to_hours(): void
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
        $this->ticReference('groups');
        (new RedmineDataRepository)->hoursExtra();
        [$expected, $before] = $this->measure(fn () => $this->ticReference('groups'));
        [$actual, $after] = $this->measure(fn () => (new RedmineDataRepository)->hoursExtra());
        self::assertSame($expected, $actual);
        self::assertLessThan($before['peak_bytes'] / 4, $after['peak_bytes']);
        file_put_contents(sys_get_temp_dir().'/nova-p06-tic-hours-metrics.json', json_encode(compact('before', 'after')), LOCK_EX);
    }

    public function test_mantencion_reuses_hydration_for_reports_in_many_jornadas(): void
    {
        for ($group = 100; $group < 160; $group++) {
            DB::table('horas_extra_grupos')->insert(['id' => $group, 'fecha' => '2026-09-01']);
            $links = [];
            for ($id = 1; $id <= 36; $id++) {
                $links[] = ['grupo_id' => $group, 'origen' => 'mantencion', 'reporte_id' => $id];
            }
            DB::table('horas_extra_grupo_reportes')->insert($links);
        }
        $this->mantencionReference();
        app(MantencionHoursExtraRepository::class)->groups();
        [$expected, $before] = $this->measure(fn () => $this->mantencionReference());
        [$actual, $after] = $this->measure(fn () => app(MantencionHoursExtraRepository::class)->groups());
        self::assertSame($expected, $actual);
        self::assertLessThan($before['peak_bytes'] / 2, $after['peak_bytes']);
        file_put_contents(sys_get_temp_dir().'/nova-p06-mantencion-hours-metrics.json', json_encode(compact('before', 'after')), LOCK_EX);
    }

    public function test_empty_origins_return_no_groups_without_reading_reports(): void
    {
        DB::table('horas_extra_grupo_reportes')->delete();
        DB::enableQueryLog();
        self::assertSame([], (new RedmineDataRepository)->hoursExtra());
        self::assertSame([], app(MantencionHoursExtraRepository::class)->groups());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertSame([], array_values(array_filter($queries, fn ($q) => preg_match('/from `redmine_(tic|mantencion)_reportes`/', $q['query']))));
    }
}
