<?php

namespace Tests\Integration;

use App\Modulos\RedmineMantencion\Exceptions\ConfigurationWriteException;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;
use App\Modulos\RedmineMantencion\Services\MantencionConfiguracionService;
use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use App\Modulos\RedmineMantencion\Services\MantencionRedmineSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\NullLogger;

class MantencionPersistenceTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::swap(new NullLogger);
        (require dirname(__DIR__, 2).'/database/migrations/2026_09_14_000000_create_redmine_send_attempts.php')->up();
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
                $t->string($column)->nullable();
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
        (require dirname(__DIR__, 2).'/database/migrations/2026_09_24_000000_add_core_detail_to_mantencion_reports.php')->up();
        foreach ([1, 2] as $module) {
            foreach (['tracker_id' => '1', 'session_timeout' => '300'] as $key => $value) {
                DB::table('configuraciones_modulo')->insert(['modulo_id' => $module, 'clave' => $key, 'valor' => $value, 'tipo' => 'int']);
            }
            foreach (['1', '2'] as $id) {
                DB::table('modulo_opciones')->insert(['modulo_id' => $module, 'tipo' => 'tracker', 'id_externo' => $id, 'nombre' => 'Tipo '.$id, 'predeterminado' => $id === '1' ? 1 : 0]);
            }
        }
        foreach (['a', 'b'] as $id) {
            self::assertTrue($this->reports()->upsertMessage(['id' => $id, 'fuente_id' => $id, 'fuente' => 'manual',
                'asunto' => 'Original '.$id, 'estado' => 'pendiente', 'fecha' => '2026-09-10', 'hora_extra' => '1', 'tiempo_estimado' => '2.5']));
        }
    }

    public function test_legacy_core_detail_can_be_saved_without_false_conflict(): void
    {
        $description = "Tipo de solicitud: Modificar Usuario\nDetalle tipo solicitud: Modificar Perfil\n"
            ."RUN: 12345678-9\nNombre: Persona de Prueba\nMotivo: Turno\nOtros permisos: RCH";
        self::assertTrue($this->reports()->upsertMessage([
            'id' => 'core-old', 'fuente_id' => 'core-id:4433', 'fuente' => 'core',
            'id_core' => '4433', 'asunto' => 'Modificar Usuario', 'descripcion' => $description,
            'estado' => 'pendiente', 'fecha' => '2026-09-24',
        ]));
        $original = $this->reports()->activeMessages([
            (string) DB::table('redmine_mantencion_reportes')->where('fuente_id', 'core-id:4433')->value('id'),
        ])[0];
        self::assertSame('Persona de Prueba', $original['core_detalle_nombre']);
        self::assertNull($original['_core_detalle_stored']);

        self::assertTrue($this->reports()->updateMessage($original, $original));
        self::assertNotNull(DB::table('redmine_mantencion_reportes')
            ->where('fuente_id', 'core-id:4433')->value('core_detalle'));
    }

    public function test_report_batch_rolls_back_earlier_rows_when_later_save_fails(): void
    {
        $saved = $this->reports()->syncMessages([
            ['id' => 'batch-first', 'fuente_id' => 'batch-first', 'fuente' => 'manual',
                'asunto' => 'Primero', 'estado' => 'pendiente', 'fecha' => '2026-09-24'],
            ['id' => '', 'fuente_id' => '', 'fuente' => 'manual', 'asunto' => 'Inválido'],
        ]);

        self::assertFalse($saved);
        self::assertFalse(DB::table('redmine_mantencion_reportes')->where('fuente_id', 'batch-first')->exists());
    }

    public function test_config_failure_rolls_back_options_and_all_scalar_keys(): void
    {
        $before = $this->configRows();
        DB::unprepared("CREATE TRIGGER reject_config BEFORE UPDATE ON configuraciones_modulo FOR EACH ROW BEGIN IF NEW.clave = 'session_timeout' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic config failure'; END IF; END");
        try {
            $this->configRepo()->saveAll(['trackers' => [['id' => '3', 'nombre' => 'Nuevo']], 'tracker_id' => '3', 'session_timeout' => 600]);
            self::fail('The caller must receive an error.');
        } catch (ConfigurationWriteException $e) {
            self::assertStringNotContainsString('Synthetic', $e->getMessage());
        }
        self::assertSame($before, $this->configRows());
    }

    public function test_option_failure_does_not_prune_failed_keys_or_save_other_config(): void
    {
        $before = $this->configRows();
        DB::unprepared("CREATE TRIGGER reject_option BEFORE UPDATE ON modulo_opciones FOR EACH ROW BEGIN IF NEW.id_externo = '2' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic option failure'; END IF; END");
        try {
            $this->configRepo()->saveAll(['trackers' => [['id' => '1', 'nombre' => 'Editado'], ['id' => '2', 'nombre' => 'Fallido']], 'tracker_id' => '2']);
            self::fail('Expected a rolled-back option replacement.');
        } catch (ConfigurationWriteException) {
        }
        self::assertSame($before, $this->configRows());
    }

    public function test_default_failure_rolls_back_create_update_delete_and_set_default(): void
    {
        DB::unprepared("CREATE TRIGGER reject_default BEFORE UPDATE ON configuraciones_modulo FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic default failure'");
        $before = $this->configRows();
        $repo = $this->configRepo();
        self::assertFalse($repo->createOption('tracker', '3', 'Nuevo', true));
        self::assertSame($before, $this->configRows());
        self::assertFalse($repo->updateOption('tracker', '2', '3', 'Renombrado', true));
        self::assertSame($before, $this->configRows());
        self::assertFalse($repo->deleteOption('tracker', '1'));
        self::assertSame($before, $this->configRows());
        self::assertFalse($repo->setDefaultOption('tracker', '2'));
        self::assertSame($before, $this->configRows());
    }

    public function test_successful_config_keeps_types_defaults_and_other_module(): void
    {
        $other = DB::table('modulo_opciones')->where('modulo_id', 1)->get()->toJson();
        $repo = $this->configRepo();
        $repo->saveAll(['session_timeout' => 600, 'maintenance_mode' => true, 'custom' => ['a' => 1], 'nullable' => null]);
        self::assertTrue($repo->setDefaultOption('tracker', '2'));
        $config = $repo->loadAll();
        self::assertSame(600, $config['session_timeout']);
        self::assertTrue($config['maintenance_mode']);
        self::assertSame(['a' => 1], $config['custom']);
        self::assertNull($config['nullable']);
        self::assertSame(2, $config['tracker_id']);
        self::assertSame('2', $repo->defaultOptionId('tracker'));
        self::assertSame($other, DB::table('modulo_opciones')->where('modulo_id', 1)->get()->toJson());
    }

    public function test_edit_preserves_ticket_state_and_hours_updated_by_another_connection(): void
    {
        $repo = $this->reports();
        $original = $this->message('a');
        // A ticket refresh must not be lost while editing an already processed report.
        DB::table('redmine_mantencion_reportes')->where('fuente_id', 'a')->update(['estado' => 'procesado']);
        $original = $this->message('a');
        DB::connection('concurrent')->table('redmine_mantencion_reportes')->where('fuente_id', 'a')->update([
            'numero_ticket_redmine' => 987, 'estado_redmine' => 'Cerrada', 'estado_id' => '5', 'tiempo_estimado' => 3.5,
        ]);
        $other = $this->row('b');
        self::assertTrue($repo->updateMessage(array_replace($original, ['asunto' => 'Editado']), $original));
        $row = $this->row('a');
        self::assertSame('Editado', $row['asunto']);
        self::assertSame(987, (int) $row['numero_ticket_redmine']);
        self::assertSame('Cerrada', $row['estado_redmine']);
        self::assertSame('3.50', $row['tiempo_estimado']);
        self::assertSame($other, $this->row('b'));
    }

    public function test_send_result_changes_only_workflow_fields_and_preserves_parallel_edit(): void
    {
        $original = $this->message('a');
        DB::connection('concurrent')->table('redmine_mantencion_reportes')->where('fuente_id', 'a')->update(['asunto' => 'Edición paralela']);
        self::assertTrue($this->reports()->updateMessage(array_replace($original, ['estado' => 'procesado', 'redmine_id' => '987', 'procesado_ts' => '2026-09-11T12:00:00+00:00']), $original));
        self::assertSame('Edición paralela', $this->row('a')['asunto']);
        self::assertSame('procesado', $this->row('a')['estado']);
        self::assertSame(987, (int) $this->row('a')['numero_ticket_redmine']);
    }

    public function test_conflict_or_deletion_does_not_overwrite_or_recreate_the_report(): void
    {
        $original = $this->message('a');
        DB::connection('concurrent')->table('redmine_mantencion_reportes')->where('fuente_id', 'a')->update(['asunto' => 'Ganador']);
        self::assertFalse($this->reports()->updateMessage(array_replace($original, ['asunto' => 'Obsoleto']), $original));
        self::assertSame('Ganador', $this->row('a')['asunto']);
        DB::table('redmine_mantencion_reportes')->where('fuente_id', 'a')->delete();
        self::assertFalse($this->reports()->updateMessage(array_replace($original, ['asunto' => 'Obsoleto']), $original));
        self::assertSame(1, DB::table('redmine_mantencion_reportes')->count());
    }

    public function test_quantity_normalization_no_op_and_archive_preserve_existing_behavior(): void
    {
        $original = $this->message('a');
        $before = $this->row('a');
        self::assertTrue($this->reports()->updateMessage($original, $original));
        self::assertSame($before, $this->row('a'));
        self::assertTrue($this->reports()->updateMessage(array_replace($original, ['tiempo_estimado' => '3.5']), $original));
        self::assertSame('3.50', $this->row('a')['tiempo_estimado']);
        DB::table('redmine_mantencion_reportes')->where('fuente_id', 'a')->update(['estado' => 'procesado']);
        $other = $this->row('b');
        self::assertTrue($this->reports()->markArchived($this->message('a')));
        self::assertSame('archivado', $this->row('a')['estado']);
        self::assertSame($other, $this->row('b'));
    }

    public function test_archive_matches_generic_update_and_reduces_queries(): void
    {
        Carbon::setTestNow('2026-09-23 10:00:00');
        try {
            $metrics = [];
            foreach (['pendiente', 'procesado', 'error', 'archivado'] as $status) {
                DB::table('redmine_mantencion_reportes')->where('fuente_id', 'a')->update(['estado' => $status]);
                $message = $this->message('a');
                DB::beginTransaction();
                try {
                    DB::flushQueryLog();
                    DB::enableQueryLog();
                    $expected = $this->reports()->updateMessage(
                        array_replace($message, ['estado' => 'archivado', 'procesado_ts' => '']), $message
                    );
                    $oldQueries = count(DB::getQueryLog());
                    DB::disableQueryLog();
                    $expectedRow = $this->row('a');
                } finally {
                    DB::rollBack();
                }

                DB::flushQueryLog();
                DB::enableQueryLog();
                $actual = $this->reports()->markArchived($message);
                $newQueries = count(DB::getQueryLog());
                DB::disableQueryLog();
                self::assertSame($expected, $actual, $status);
                self::assertEquals($expectedRow, $this->row('a'), $status);
                self::assertLessThan($oldQueries, $newQueries, $status);
                $metrics[$status] = ['before_queries' => $oldQueries, 'after_queries' => $newQueries];
            }

            DB::table('redmine_mantencion_reportes')->where('fuente_id', 'a')->update(['estado' => 'procesado']);
            $stale = $this->message('a');
            DB::table('redmine_mantencion_reportes')->where('fuente_id', 'a')->update(['estado' => 'error']);
            self::assertFalse($this->reports()->markArchived($stale));
            self::assertSame('error', $this->row('a')['estado']);

            $alreadyArchived = array_replace($stale, ['estado' => 'archivado']);
            $generic = $this->reports()->updateMessage(
                array_replace($alreadyArchived, ['estado' => 'archivado', 'procesado_ts' => '']), $alreadyArchived
            );
            self::assertSame($generic, $this->reports()->markArchived($alreadyArchived));
            self::assertSame('error', $this->row('a')['estado']);
            file_put_contents(sys_get_temp_dir().'/nova-p06-mantencion-archive-metrics.json', json_encode($metrics), LOCK_EX);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_reset_batch_matches_individual_updates_with_fewer_queries(): void
    {
        Carbon::setTestNow('2026-09-23 10:00:00');
        try {
            $messages = [];
            $originals = [];
            for ($i = 0; $i < 30; $i++) {
                $id = 'reset-'.$i;
                self::assertTrue($this->reports()->upsertMessage(['id' => $id, 'fuente_id' => $id,
                    'fuente' => 'manual', 'asunto' => 'Error '.$i, 'estado' => 'error',
                    'fecha' => '2026-09-10', 'redmine_id' => '1000']));
                $original = $this->message($id);
                $originals[] = $original;
                $messages[] = array_replace($original, ['estado' => 'pendiente',
                    'redmine_id' => '', 'numero_ticket_redmine' => '', 'procesado_ts' => '']);
            }

            DB::beginTransaction();
            try {
                DB::flushQueryLog();
                DB::enableQueryLog();
                foreach ($messages as $index => $message) {
                    self::assertTrue($this->reports()->updateMessage($message, $originals[$index]));
                }
                $beforeQueries = count(DB::getQueryLog());
                DB::disableQueryLog();
                $expected = DB::table('redmine_mantencion_reportes')->orderBy('id')->get()->toArray();
            } finally {
                DB::rollBack();
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            self::assertTrue($this->reports()->syncMessages($messages, [], $originals));
            $afterQueries = count(DB::getQueryLog());
            DB::disableQueryLog();
            self::assertEquals($expected, DB::table('redmine_mantencion_reportes')->orderBy('id')->get()->toArray());
            self::assertLessThan($beforeQueries / 3, $afterQueries);
            file_put_contents(sys_get_temp_dir().'/nova-p06-mantencion-reset-metrics.json', json_encode([
                'reports' => count($messages), 'before_queries' => $beforeQueries,
                'after_queries' => $afterQueries,
            ]), LOCK_EX);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_core_pending_update_cannot_restore_a_report_processed_during_import(): void
    {
        $original = $this->message('a');
        DB::connection('concurrent')->table('redmine_mantencion_reportes')->where('fuente_id', 'a')->update(['estado' => 'procesado', 'numero_ticket_redmine' => 988]);
        self::assertFalse($this->reports()->updateMessage(array_replace($original, ['descripcion' => 'CORE']), $original));
        self::assertSame('procesado', $this->row('a')['estado']);
        self::assertSame(988, (int) $this->row('a')['numero_ticket_redmine']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_selected_send_persists_receipt_without_overwriting_other_reports(): void
    {
        $messages = [$this->message('a'), $this->message('b')];
        $service = $this->sender(function (): array {
            DB::connection('concurrent')->table('redmine_mantencion_reportes')->where('fuente_id', 'b')->update(['asunto' => 'No sobrescribir']);
            DB::connection('concurrent')->table('redmine_mantencion_reportes')->where('fuente_id', 'a')->update(['descripcion' => 'Edición durante envío']);

            return ['http_code' => 201, 'body' => '{"issue":{"id":1234}}', 'error' => ''];
        });
        $result = $service->send_selected_messages($messages, ['a'], [], 'synthetic-token');
        self::assertSame(1, $service->requests);
        self::assertSame(1, $result['success']);
        self::assertSame([], $result['errors']);
        self::assertSame(1234, (int) $this->row('a')['numero_ticket_redmine']);
        self::assertSame('Edición durante envío', $this->row('a')['descripcion']);
        self::assertSame('No sobrescribir', $this->row('b')['asunto']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_remote_success_with_failed_local_write_stops_batch_and_keeps_ticket_reference(): void
    {
        DB::unprepared("CREATE TRIGGER reject_receipt BEFORE UPDATE ON redmine_mantencion_reportes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic receipt failure'");
        $messages = [$this->message('a'), $this->message('b')];
        $service = $this->sender(fn () => ['http_code' => 201, 'body' => '{"issue":{"id":1234}}', 'error' => '']);
        $result = $service->send_selected_messages($messages, ['a', 'b'], [], 'synthetic-token');
        self::assertSame(1, $service->requests);
        self::assertSame(['1234'], $result['redmine_ids']);
        self::assertStringContainsString('ticket 1234', implode(' ', $result['errors']));
        self::assertSame('pendiente', $this->row('a')['estado']);
        self::assertSame('pendiente', $this->row('b')['estado']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_overlapping_mantencion_sends_make_only_one_post(): void
    {
        $nested = null;
        $service = null;
        $service = $this->sender(function () use (&$service, &$nested): array {
            DB::setDefaultConnection('concurrent');
            try {
                $otherMessages = [$this->message('a')];
                $nested = $service->send_selected_messages($otherMessages, ['a'], [], 'synthetic-token');
            } finally {
                DB::setDefaultConnection('mysql');
            }

            return ['http_code' => 201, 'body' => '{"issue":{"id":444}}', 'error' => ''];
        });
        $messages = [$this->message('a')];
        $result = $service->send_selected_messages($messages, ['a'], [], 'synthetic-token');
        self::assertSame(1, $service->requests);
        self::assertSame(0, $nested['attempts']);
        self::assertSame(1, $result['success']);
        self::assertStringContainsString('conciliación', implode(' ', $nested['errors']));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_mantencion_uncertain_result_cannot_be_sent_again(): void
    {
        $service = $this->sender(fn () => ['http_code' => 0, 'body' => '', 'error' => 'Synthetic timeout']);
        $messages = [$this->message('a')];
        $service->send_selected_messages($messages, ['a'], [], 'synthetic-token');
        $messages = [$this->message('a')];
        $retry = $service->send_selected_messages($messages, ['a'], [], 'synthetic-token');
        self::assertSame(1, $service->requests);
        self::assertSame(0, $retry['attempts']);
        self::assertSame('uncertain', DB::table('redmine_send_attempts')->value('status'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_option_failure_uses_error_flash_instead_of_success(): void
    {
        require_once __DIR__.'/fixtures/mantencion_functions.php';
        $request = Request::create('/redmine-mantencion/app/configuracion?panel=trackers', 'POST');
        app()->instance('request', $request);
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        app()->instance('session', $session);
        $savedPost = $_POST;
        $savedMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['opt_type' => 'trackers', 'opt_action' => 'set_default', 'opt_id' => '2'];
            DB::unprepared("CREATE TRIGGER reject_config_ui BEFORE UPDATE ON configuraciones_modulo FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic UI failure'");
            $result = app(MantencionConfiguracionService::class)->handle();
            self::assertSame(303, $result->getStatusCode());
            self::assertStringContainsString('No fue posible', $session->get('mantencion_config_flash'));
            self::assertSame('1', $this->configRepo()->defaultOptionId('tracker'));
        } finally {
            $_POST = $savedPost;
            if ($savedMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $savedMethod;
            }
        }
    }

    private function sender(callable $response): MantencionRedmineSyncService
    {
        require_once __DIR__.'/fixtures/mantencion_functions.php';

        return new class($response, $this->reports()) extends MantencionRedmineSyncService
        {
            public int $requests = 0;

            public function __construct(private $response, private MantencionReportRepository $reports)
            {
                parent::__construct(new MantencionCoreImportService);
            }

            public function check_redmine_availability(array $cfg, string $userToken): array
            {
                return ['ok' => true, 'error' => ''];
            }

            public function dashboard_redmine_send_block_reason(array $message): ?string
            {
                return null;
            }

            public function build_redmine_issue_payload(array $message, array $cfg, array $catMap, array $unitMap): array
            {
                return ['subject' => $message['asunto']];
            }

            public function send_redmine_issue(array $issue, array $cfg, string $userToken = ''): array
            {
                $this->requests++;

                return ($this->response)();
            }

            public function append_redmine_log(array $entry): void {}

            protected function persist_message_changes(array $message, array $original): bool
            {
                return $this->reports->updateMessage($message, $original);
            }
        };
    }

    private function row(string $id): array
    {
        return (array) DB::table('redmine_mantencion_reportes')->where('fuente_id', $id)->first();
    }

    private function message(string $id): array
    {
        return $this->reports()->rowToMessage((object) $this->row($id));
    }

    private function configRows(): array
    {
        return [DB::table('configuraciones_modulo')->orderBy('id')->get()->toJson(), DB::table('modulo_opciones')->orderBy('id')->get()->toJson()];
    }

    private function reports(): MantencionReportRepository
    {
        return app(MantencionReportRepository::class);
    }

    private function configRepo(): MantencionConfigRepository
    {
        return new MantencionConfigRepository;
    }
}
