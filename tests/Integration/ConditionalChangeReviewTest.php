<?php

namespace Tests\Integration;

use App\Console\Commands\ReviewConditionalDatabaseChanges;
use App\Repositories\Database\ConditionalChangeReview;
use App\Services\Database\SchemaBaseline;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Tester\CommandTester;

final class ConditionalChangeReviewTest extends IsolatedMariaDbTestCase
{
    private function baseline(): void
    {
        $version = explode('-', DB::selectOne('SELECT VERSION() AS version')->version)[0];
        if (version_compare($version, '12.3.2', '<')) {
            self::markTestSkipped('Requires the MariaDB 12.3.2 baseline.');
        }
        $baseline = new SchemaBaseline;
        $baseline->bootstrap(DB::connection(), $baseline->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
    }

    public function test_observations_preserve_multiple_journeys_null_owners_and_midnight_cases(): void
    {
        $this->baseline();
        DB::table('modulos_nova')->insert([['id' => 1, 'clave_modulo' => 'redmine_tic', 'nombre' => 'TIC'], ['id' => 2, 'clave_modulo' => 'redmine-mantencion', 'nombre' => 'M']]);
        DB::table('catalogos_modulo')->insert(['id' => 1, 'modulo_id' => 2, 'tipo' => 'unidad', 'nombre' => 'PRIVATE-CATALOG']);
        DB::table('redmine_tic_reportes')->insert(['id' => 1, 'modulo_id' => 1, 'fecha_inicio' => '2026-09-14', 'categoria_catalogo_id' => 1, 'hora_extra' => 2, 'tiempo_estimado' => -1, 'descripcion' => 'PRIVATE-REPORT']);
        DB::table('horas_extra_grupos')->insert([
            ['id' => 1, 'fecha' => '2026-09-14', 'hora_inicio' => '22:00:00', 'hora_fin' => '02:00:00'],
            ['id' => 2, 'fecha' => '2026-09-15', 'hora_inicio' => null, 'hora_fin' => null],
            ['id' => 3, 'fecha' => '2026-09-15', 'hora_inicio' => null, 'hora_fin' => null],
        ]);
        DB::table('horas_extra_grupo_reportes')->insert([
            ['grupo_id' => 1, 'origen' => 'tic', 'reporte_id' => 1],
            ['grupo_id' => 2, 'origen' => 'tic', 'reporte_id' => 1],
            ['grupo_id' => 2, 'origen' => 'mantencion', 'reporte_id' => 999],
        ]);
        DB::table('redmine_mantencion_reportes')->insert([
            ['modulo_id' => 2, 'fuente' => 'manual', 'fuente_id' => 'PRIVATE-ID', 'id_core' => 'CORE-1'],
            ['modulo_id' => 2, 'fuente' => 'manual', 'fuente_id' => 'PRIVATE-ID', 'id_core' => 'CORE-1'],
            ['modulo_id' => 2, 'fuente' => 'manual', 'fuente_id' => null, 'id_core' => null],
        ]);
        DB::table('categorias')->insert([
            ['modulo_id' => 2, 'clave_externa' => 'A', 'nombre' => 'PRIVATE-A'],
            ['modulo_id' => 2, 'clave_externa' => 'A', 'nombre' => 'PRIVATE-B'],
            ['modulo_id' => 1, 'clave_externa' => 'A', 'nombre' => 'same key other module'],
        ]);
        $schema = new SchemaBaseline;
        $beforeSchema = $schema->capture(DB::connection());
        $beforeRows = $this->allRows($beforeSchema);
        $previousTimeout = DB::selectOne('SELECT @@SESSION.max_statement_time AS timeout')->timeout;
        DB::connection()->enableQueryLog();
        $result = (new ConditionalChangeReview)->review(DB::connection(), 2);
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        foreach (['hours_multiple_groups' => 1, 'hours_empty_groups' => 1, 'hours_null_owner' => 3,
            'hours_null_owner_same_date' => 1, 'hours_overnight' => 1, 'hours_outside_clock' => 0,
            'tic_hours_date_mismatch' => 1, 'mantencion_orphan_report_links' => 1,
            'mantencion_source_duplicates' => 1, 'mantencion_incomplete_source' => 1,
            'mantencion_core_duplicates' => 1, 'categorias_external_duplicates' => 1,
            'tic_categoria_catalogo_id_mismatch' => 1, 'tic_unidad_catalogo_id_mismatch' => 0,
            'tic_invalid_boolean' => 1, 'tic_negative_estimate' => 1] as $key => $count) {
            self::assertSame($count, $result['checks'][$key]['count'], $key);
        }
        self::assertNotContains('not_evaluated', array_column($result['checks'], 'status'));
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        self::assertSame($beforeRows, $this->allRows($beforeSchema));
        self::assertSame([], $schema->differences($beforeSchema, $schema->capture(DB::connection())));
        self::assertSame(0, DB::connection()->transactionLevel());
        self::assertSame($previousTimeout, DB::selectOne('SELECT @@SESSION.max_statement_time AS timeout')->timeout);
        foreach ($queries as $query) {
            self::assertMatchesRegularExpression('/^(select|set)\b/i', $query['query']);
        }
    }

    public function test_missing_schema_is_explicit_and_never_reported_as_zero(): void
    {
        $result = (new ConditionalChangeReview)->review(DB::connection());
        foreach ($result['checks'] as $check) {
            self::assertNull($check['count']);
            self::assertSame('not_evaluated', $check['status']);
            self::assertNotEmpty($check['missing']);
        }
    }

    public function test_existing_transaction_is_rejected_and_left_owned_by_the_caller(): void
    {
        Schema::create('sentinel', fn (Blueprint $t) => $t->id());
        DB::beginTransaction();
        DB::table('sentinel')->insert(['id' => 12]);
        try {
            (new ConditionalChangeReview)->review(DB::connection());
            self::fail('Must not join or roll back a caller transaction.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('sin transacción', $exception->getMessage());
        }
        self::assertSame(1, DB::connection()->transactionLevel());
        self::assertSame(12, DB::table('sentinel')->value('id'));
        DB::rollBack();
    }

    public function test_database_enforces_read_only_and_restores_fractional_timeout_after_failure(): void
    {
        Schema::create('horas_extra_grupo_reportes', function (Blueprint $t): void {
            $t->id();
            $t->integer('grupo_id');
            $t->string('origen');
            $t->integer('reporte_id');
        });
        Schema::create('sentinel', fn (Blueprint $t) => $t->id());
        DB::statement('SET SESSION max_statement_time=0.25');
        $attempted = false;
        DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use (&$attempted): void {
            if (! $attempted && str_starts_with($sql, 'SELECT COUNT(*)')) {
                $attempted = true;
                $connection->statement('INSERT INTO sentinel (id) VALUES (9)');
            }
        });
        try {
            (new ConditionalChangeReview)->review(DB::connection(), 1);
            self::fail('MariaDB must reject writes in the review transaction.');
        } catch (QueryException $exception) {
            self::assertSame(1792, (int) $exception->errorInfo[1]);
        }
        self::assertSame(0, DB::connection()->transactionLevel());
        self::assertSame(0, DB::table('sentinel')->count());
        self::assertSame(0.25, (float) DB::selectOne('SELECT @@SESSION.max_statement_time AS timeout')->timeout);
        DB::table('sentinel')->insert(['id' => 10]);
        self::assertSame(10, DB::table('sentinel')->value('id'));
    }

    public function test_slow_statement_is_interrupted_and_session_is_restored(): void
    {
        Schema::create('horas_extra_grupo_reportes', function (Blueprint $t): void {
            $t->id();
            $t->integer('grupo_id');
            $t->string('origen');
            $t->integer('reporte_id');
        });
        $previous = DB::selectOne('SELECT @@SESSION.max_statement_time AS timeout')->timeout;
        $injected = false;
        DB::connection()->beforeExecuting(function ($sql, $bindings, $connection) use (&$injected): void {
            if (! $injected && str_starts_with($sql, 'SELECT COUNT(*)')) {
                $injected = true;
                $connection->select('SELECT SLEEP(3)');
            }
        });
        try {
            (new ConditionalChangeReview)->review(DB::connection(), 1);
            self::fail('The server must interrupt the slow statement.');
        } catch (QueryException $exception) {
            self::assertSame(1969, (int) $exception->errorInfo[1]);
        }
        self::assertSame(0, DB::connection()->transactionLevel());
        self::assertSame($previous, DB::selectOne('SELECT @@SESSION.max_statement_time AS timeout')->timeout);
    }

    public function test_command_reports_partial_schema_and_rejects_invalid_timeout(): void
    {
        $command = new ReviewConditionalDatabaseChanges;
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute(['--database' => 'mysql', '--json' => true]));
        $result = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($result['automatic_changes']);
        self::assertSame('not_evaluated', $result['checks']['hours_multiple_groups']['status']);
        self::assertSame(1, $tester->execute(['--timeout' => '1.5']));
        self::assertStringContainsString('Timeout debe ser un entero', $tester->getDisplay());
    }

    public function test_prefix_candidates_exclude_unique_keys_different_lengths_and_sort_directions(): void
    {
        Schema::create('index_probe', function (Blueprint $t): void {
            $t->id();
            $t->string('a');
            $t->string('b');
            $t->unique('a', 'unique_a');
            $t->index('a', 'short_a');
            $t->index(['a', 'b'], 'long_ab');
        });
        DB::statement('CREATE INDEX prefix_a ON index_probe (a(10))');
        $result = (new ConditionalChangeReview)->review(DB::connection());
        self::assertSame([['table' => 'index_probe', 'index' => 'short_a', 'covered_by' => 'long_ab',
            'requires' => 'Planes y uso de todos los consumidores, dependencias FK y ensayo de recreación; no retirar automáticamente.']], $result['index_prefix_candidates']);
        $rows = [
            ['TABLE_NAME' => 't', 'INDEX_NAME' => 'a', 'NON_UNIQUE' => 1, 'COLUMN_NAME' => 'x', 'SUB_PART' => null, 'COLLATION' => 'A', 'INDEX_TYPE' => 'BTREE'],
            ['TABLE_NAME' => 't', 'INDEX_NAME' => 'b', 'NON_UNIQUE' => 1, 'COLUMN_NAME' => 'x', 'SUB_PART' => null, 'COLLATION' => 'D', 'INDEX_TYPE' => 'BTREE'],
            ['TABLE_NAME' => 't', 'INDEX_NAME' => 'b', 'NON_UNIQUE' => 1, 'COLUMN_NAME' => 'y', 'SUB_PART' => null, 'COLLATION' => 'A', 'INDEX_TYPE' => 'BTREE'],
        ];
        self::assertSame([], (new ConditionalChangeReview)->prefixCandidates($rows));
    }

    private function allRows(array $snapshot): array
    {
        $rows = [];
        foreach ($snapshot['schema']['tables'] as $table) {
            $rows[$table['TABLE_NAME']] = DB::table($table['TABLE_NAME'])->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
