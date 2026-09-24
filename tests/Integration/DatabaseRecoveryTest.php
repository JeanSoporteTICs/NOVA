<?php

namespace Tests\Integration;

use App\Services\Database\SchemaBaseline;
use App\Services\Database\UpgradeSafety;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

final class DatabaseRecoveryTest extends IsolatedMariaDbTestCase
{
    public function test_reviewed_baseline_recreates_schema_and_ledger_without_application_rows(): void
    {
        $this->requireBaselineServer();
        $baseline = new SchemaBaseline;
        $reference = $baseline->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15');
        $baseline->bootstrap(DB::connection(), $reference);
        $actual = $baseline->capture(DB::connection());
        self::assertSame([], $baseline->differences($reference, $actual));
        self::assertSame($reference['migrations'], $actual['migrations']);
        self::assertSame(0, DB::table('usuarios_nova')->count());
        self::assertSame(0, DB::table('redmine_tic_reportes')->count());
        self::assertSame(0, DB::table('redmine_mantencion_reportes')->count());
        self::assertCount(7, $actual['schema']['triggers']);
        self::assertSame(0, DB::table('migrations')->where('migration', '2026_09_14_000000_create_redmine_send_attempts')->count());
        self::assertStringNotContainsString('DEFINER', file_get_contents(dirname(__DIR__, 2).'/database/baselines/2026-09-15/schema.sql'));
        (new UpgradeSafety)->assertMayUpgrade(DB::connection());
    }

    private function requireBaselineServer(): void
    {
        $version = explode('-', DB::selectOne('SELECT VERSION() AS version')->version)[0];
        if (version_compare($version, '12.3.2', '<')) {
            self::markTestSkipped('The reviewed baseline requires MariaDB 12.3.2 (including trigger collations).');
        }
    }

    public function test_unsupported_trigger_collation_fails_before_creating_tables_and_restores_session(): void
    {
        $baseline = new SchemaBaseline;
        $reference = $baseline->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15');
        $reference['schema']['triggers'][0]['COLLATION_CONNECTION'] = 'nova_nonexistent_collation';
        $before = (array) DB::selectOne('SELECT @@sql_mode AS mode, @@foreign_key_checks AS fk, @@collation_connection AS collation');
        try {
            $baseline->bootstrap(DB::connection(), $reference);
            self::fail('An unsupported collation must be rejected.');
        } catch (QueryException $exception) {
            self::assertSame(1273, (int) $exception->errorInfo[1]);
        }
        self::assertSame([], Schema::getTables(DB::connection()->getDatabaseName()));
        self::assertSame($before, (array) DB::selectOne('SELECT @@sql_mode AS mode, @@foreign_key_checks AS fk, @@collation_connection AS collation'));
    }

    public function test_full_sql_backup_restores_reports_keys_relations_triggers_and_ledger(): void
    {
        $this->requireBaselineServer();
        $dumpBinary = getenv('NOVA_TEST_DUMP_BINARY') ?: '/opt/lampp/bin/mysqldump';
        $clientBinary = getenv('NOVA_TEST_MYSQL_BINARY') ?: '/opt/lampp/bin/mysql';
        if (! is_executable($dumpBinary) || ! is_executable($clientBinary)) {
            self::markTestSkipped('Requires MariaDB dump/client binaries for the full SQL recovery rehearsal.');
        }
        $baseline = new SchemaBaseline;
        $baseline->bootstrap(DB::connection(), $baseline->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
        DB::table('usuarios_nova')->insert(['id' => 71, 'uuid' => '00000000-0000-0000-0000-000000000071', 'usuario' => 'prueba', 'nombre' => 'Usuario', 'apellido' => 'Sintético', 'password' => 'synthetic-only', 'redmine_id' => '0123']);
        DB::table('modulos_nova')->insert(['id' => 1, 'clave_modulo' => 'tic_test', 'nombre' => 'TIC prueba']);
        DB::table('redmine_tic_reportes')->insert(['id' => 91, 'modulo_id' => 1, 'asignado_a' => '0123', 'asunto' => 'TIC á <texto>', 'descripcion' => "línea 1\nlínea 2", 'redmine_id' => 1234]);
        DB::table('redmine_mantencion_reportes')->insert(['id' => 92, 'modulo_id' => 1, 'fuente' => 'manual', 'fuente_id' => 'manual_92', 'descripcion' => 'Mantención sintética', 'numero_ticket_redmine' => 1235]);
        DB::table('horas_extra_grupos')->insert(['id' => 81, 'usuario_id' => 71, 'fecha' => '2026-09-15']);
        DB::table('horas_extra_grupo_reportes')->insert([['grupo_id' => 81, 'origen' => 'tic', 'reporte_id' => 91], ['grupo_id' => 81, 'origen' => 'mantencion', 'reporte_id' => 92]]);
        $tables = ['usuarios_nova', 'modulos_nova', 'redmine_tic_reportes', 'redmine_mantencion_reportes', 'horas_extra_grupos', 'horas_extra_grupo_reportes', 'migrations'];
        $rows = fn () => array_map(fn ($t) => DB::table($t)->orderBy('id')->get()->toJson(), $tables);
        $expectedRows = $rows();
        $expectedSchema = $baseline->capture(DB::connection());
        $options = ['--no-defaults', '--socket='.getenv('NOVA_PERSISTENCE_TEST_SOCKET'), '--user=root', '--default-character-set=utf8mb4'];
        $dump = new Process(array_merge([$dumpBinary], $options, ['--single-transaction', '--triggers', '--skip-comments', DB::connection()->getDatabaseName()]));
        $dump->mustRun();
        // The base class has verified a random disposable database with networking disabled.
        Schema::disableForeignKeyConstraints();
        foreach ($expectedSchema['schema']['tables'] as $table) {
            Schema::drop($table['TABLE_NAME']);
        }
        Schema::enableForeignKeyConstraints();
        $restore = new Process(array_merge([$clientBinary], $options, [DB::connection()->getDatabaseName()]));
        $restore->setInput($dump->getOutput());
        $restore->mustRun();
        self::assertSame($expectedRows, $rows());
        $actual = $baseline->capture(DB::connection());
        self::assertSame([], $baseline->differences($expectedSchema, $actual));
        self::assertSame($expectedSchema['migrations'], $actual['migrations']);
        (new UpgradeSafety)->assertMayUpgrade(DB::connection());
        DB::table('usuarios_nova')->where('id', 71)->update(['actualizado_at' => '2000-01-01 00:00:00']);
        self::assertNotSame('2000-01-01 00:00:00', DB::table('usuarios_nova')->where('id', 71)->value('actualizado_at'));
        try {
            DB::table('redmine_tic_reportes')->insert(['modulo_id' => 1, 'asignado_a' => 'nonexistent']);
            self::fail('The restored foreign key must reject orphan reports.');
        } catch (QueryException $exception) {
            self::assertSame('23000', $exception->errorInfo[0]);
        }
    }

    public function test_bootstrap_refuses_any_existing_table_without_modifying_it(): void
    {
        Schema::create('sentinel', fn (Blueprint $t) => $t->integer('value'));
        DB::table('sentinel')->insert(['value' => 17]);
        try {
            $baseline = new SchemaBaseline;
            $baseline->bootstrap(DB::connection(), $baseline->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
            self::fail('A populated schema must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('completamente vacía', $exception->getMessage());
        }
        self::assertSame(17, DB::table('sentinel')->value('value'));
        self::assertFalse(Schema::hasTable('migrations'));
    }

    public function test_legacy_cleanup_is_blocked_before_data_or_foreign_key_checks_change(): void
    {
        Schema::create('redmine_tic_reportes', fn (Blueprint $t) => $t->id());
        DB::table('redmine_tic_reportes')->insert(['id' => 1]);
        $checks = DB::selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled;
        try {
            (new UpgradeSafety)->assertMayUpgrade(DB::connection());
            self::fail('The pending cleanup must be blocked.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('redmine_tic_reportes', $exception->getMessage());
        }
        self::assertSame(1, DB::table('redmine_tic_reportes')->count());
        self::assertSame($checks, DB::selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled);
    }

    public function test_cleanup_allows_empty_install_and_preserved_configuration_only(): void
    {
        Schema::create('redmine_mantencion_storage', function (Blueprint $t): void {
            $t->string('path');
        });
        DB::table('redmine_mantencion_storage')->insert([['path' => 'configuracion.json'], ['path' => 'roles.json']]);
        (new UpgradeSafety)->assertMayUpgrade(DB::connection());
        DB::table('redmine_mantencion_storage')->insert(['path' => 'security.log']);
        $this->expectException(\RuntimeException::class);
        (new UpgradeSafety)->assertMayUpgrade(DB::connection());
    }

    public function test_index_downgrade_retains_preexisting_and_new_indexes_without_unsupported_api(): void
    {
        Schema::create('usuarios_nova', function (Blueprint $t): void {
            $t->id();
            $t->string('estado');
            $t->string('rol');
            $t->index('estado', 'idx_usuarios_nova_estado');
        });
        DB::table('usuarios_nova')->insert(['id' => 1, 'estado' => 'activo', 'rol' => 'usuario']);
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_06_12_100001_add_composite_indexes_for_performance.php';
        $migration->up();
        self::assertTrue(Schema::hasIndex('usuarios_nova', 'idx_usuarios_nova_estado'));
        self::assertTrue(Schema::hasIndex('usuarios_nova', 'idx_usuarios_nova_rol_estado'));
        $before = Schema::getIndexes('usuarios_nova');
        $migration->down();
        self::assertSame($before, Schema::getIndexes('usuarios_nova'));
        $migration->up();
        self::assertSame($before, Schema::getIndexes('usuarios_nova'));
        self::assertSame(1, DB::table('usuarios_nova')->count());
    }
}
