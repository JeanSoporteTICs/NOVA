<?php

namespace Tests\Integration;

use App\Modulos\RedmineMantencion\Repositories\MantencionHistoryRepository;
use App\Services\Database\SchemaBaseline;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Candidate I4 is created/dropped ONLY on the random isolated database. */
final class ConditionalIndexEvaluationTest extends IsolatedMariaDbTestCase
{
    public function test_history_index_candidate_preserves_actual_queries_and_records_tradeoffs(): void
    {
        $version = explode('-', DB::selectOne('SELECT VERSION() AS version')->version)[0];
        if (version_compare($version, '12.3.2', '<')) {
            self::markTestSkipped('Requires MariaDB 12.3.2 baseline for index evaluation.');
        }
        $schema = new SchemaBaseline;
        $reference = $schema->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15');
        $schema->bootstrap(DB::connection(), $reference);
        DB::table('modulos_nova')->insert([['id' => 1, 'clave_modulo' => 'other', 'nombre' => 'Other'], ['id' => 2, 'clave_modulo' => 'redmine-mantencion', 'nombre' => 'Mantención']]);
        for ($batch = 0; $batch < 20; $batch++) {
            $rows = [];
            for ($n = 1; $n <= 500; $n++) {
                $id = $batch * 500 + $n;
                $rows[] = ['id' => $id, 'modulo_id' => $id % 3 === 0 ? 1 : 2, 'fuente' => 'manual', 'fuente_id' => 'test-'.$id,
                    'estado' => $id % 10 === 0 ? 'archivado' : 'pendiente', 'fecha_reporte' => $id % 7 === 0 ? null : '2026-09-'.str_pad((string) ($id % 28 + 1), 2, '0', STR_PAD_LEFT),
                    'fecha_inicio' => '2026-08-01', 'descripcion' => str_repeat('Synthetic ', 100)];
            }
            DB::table('redmine_mantencion_reportes')->insert($rows);
        }
        DB::table('horas_extra_grupos')->insert(['id' => 1, 'fecha' => '2026-09-01']);
        DB::table('horas_extra_grupo_reportes')->insert(array_map(fn ($i) => ['grupo_id' => 1, 'origen' => 'mantencion', 'reporte_id' => $i * 10], range(1, 30)));
        $observations = [];
        foreach (['selective_10_percent', 'all_archived'] as $scenario) {
            if ($scenario === 'all_archived') {
                DB::table('redmine_mantencion_reportes')->update(['estado' => 'archivado']);
            }
            $before = $this->measure();
            Schema::table('redmine_mantencion_reportes', fn (Blueprint $t) => $t->index(['modulo_id', 'estado', 'fecha_reporte', 'id'], 'p08_history_candidate'));
            $after = $this->measure();
            foreach ($before['queries'] as $key => $measurement) {
                self::assertSame($measurement['result_hash'], $after['queries'][$key]['result_hash'], $scenario.' '.$key);
                self::assertSame($measurement['rows'], $after['queries'][$key]['rows']);
            }
            Schema::table('redmine_mantencion_reportes', fn (Blueprint $t) => $t->dropIndex('p08_history_candidate'));
            self::assertSame([], $schema->differences($reference, $schema->capture(DB::connection())));
            $observations[$scenario] = ['before' => $before, 'after' => $after];
        }
        file_put_contents(sys_get_temp_dir().'/nova-p08-index-evaluation.json', json_encode([
            'engine' => 'MariaDB '.$version, 'rows' => 10000, 'synthetic' => true,
            'candidate' => ['modulo_id', 'estado', 'fecha_reporte', 'id'], 'scenarios' => $observations,
            'limits' => 'Warm-cache local measurements, not production load or DDL-window approval. No latency thresholds.',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function measure(): array
    {
        DB::statement('ANALYZE TABLE redmine_mantencion_reportes'); // Disposable data only.
        $history = app(MantencionHistoryRepository::class);
        $method = new \ReflectionMethod($history, 'candidates');
        $candidates = $method->invoke($history, false);
        $metadata = (clone $candidates)->orderBy('_source_order')->orderBy('_group_order')->orderByDesc('fecha_reporte')->orderByDesc('id');
        $keys = (clone $candidates)->pluck('_selection_key')->all();
        $page = (clone $candidates)->whereIn('_selection_key', $keys)->orderByRaw('COALESCE(fecha_reporte, fecha_inicio) DESC')
            ->orderBy('_source_order')->orderBy('_group_order')->orderByDesc('fecha_reporte')->orderByDesc('id')->limit(25)->select(['id', '_selection_key', '_source']);
        $measurements = ['metadata' => $this->query($metadata), 'page' => $this->query($page)];
        // Contrast the audit's simple LIMIT proposal with the actual P06 union/order.
        $simple = DB::table('redmine_mantencion_reportes')->where('modulo_id', 2)->where('estado', 'archivado')->orderByDesc('fecha_reporte')->orderByDesc('id')->limit(25)->select(['id', 'fecha_reporte']);
        $measurements['simple_limit_reference_only'] = $this->query($simple);
        $writes = [];
        for ($i = 0; $i < 3; $i++) {
            DB::beginTransaction();
            $start = hrtime(true);
            DB::table('redmine_mantencion_reportes')->where('id', '<=', 1000)->update(['fecha_reporte' => '2025-01-01']);
            $writes[] = (hrtime(true) - $start) / 1e6;
            DB::rollBack();
        }
        sort($writes);
        $size = DB::table('information_schema.TABLES')->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())->where('TABLE_NAME', 'redmine_mantencion_reportes')->value('INDEX_LENGTH');

        return ['queries' => $measurements, 'update_1000_rows_median_ms' => $writes[1], 'index_bytes_estimate' => (int) $size];
    }

    private function query(Builder $query): array
    {
        $plan = array_map(fn ($r) => (array) $r, DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()));
        $durations = [];
        $read = fn () => collect(DB::select("SHOW SESSION STATUS WHERE Variable_name IN ('Handler_read_key','Handler_read_next','Handler_read_prev','Handler_read_rnd_next')"))->mapWithKeys(fn ($r) => [$r->Variable_name => (int) $r->Value])->all();
        $startCounters = $read();
        for ($i = 0; $i < 3; $i++) {
            $start = hrtime(true);
            $rows = $query->get();
            $durations[] = (hrtime(true) - $start) / 1e6;
        }
        $endCounters = $read();
        $reads = [];
        foreach ($startCounters as $key => $value) {
            $reads[$key] = $endCounters[$key] - $value;
        }
        sort($durations);

        return ['rows' => $rows->count(), 'result_hash' => hash('sha256', $rows->toJson()), 'median_ms' => $durations[1],
            'handler_reads_three_runs' => $reads, 'plan' => $plan];
    }
}
