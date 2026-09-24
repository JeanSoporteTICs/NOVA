<?php

namespace Tests\Integration;

use App\Modulos\Nova\Repositories\HorasExtraRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionHoursExtraRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;
use App\Modulos\RedmineMantencion\Services\MantencionRetentionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RedmineTic\Repositories\RedmineHoursExtraRepository;
use RedmineTic\Repositories\RedmineReportRepository;

class HoursExtraPersistenceTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('usuarios_nova', function (Blueprint $t): void {
            $t->id();
            $t->string('redmine_id')->nullable();
        });
        DB::table('usuarios_nova')->insert([['id' => 1, 'redmine_id' => '101'], ['id' => 2, 'redmine_id' => '102']]);
        Schema::create('modulos_nova', function (Blueprint $t): void {
            $t->id();
            $t->string('clave_modulo');
        });
        DB::table('modulos_nova')->insert([['id' => 1, 'clave_modulo' => 'redmine_tic'], ['id' => 2, 'clave_modulo' => 'redmine-mantencion']]);
        Schema::create('categorias', function (Blueprint $t): void {
            $t->id();
            $t->string('nombre');
        });
        foreach (['redmine_tic_reportes', 'redmine_mantencion_reportes'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('modulo_id');
                $t->string('estado')->default('archivado');
                $t->string('fuente')->nullable();
                $t->string('fuente_id')->nullable();
                $t->boolean('hora_extra')->default(1);
                $t->timestamp('actualizado_at')->nullable();
            });
        }
        DB::table('redmine_tic_reportes')->insert([['id' => 1, 'modulo_id' => 1], ['id' => 2, 'modulo_id' => 1], ['id' => 3, 'modulo_id' => 2]]);
        DB::table('redmine_mantencion_reportes')->insert([['id' => 1, 'modulo_id' => 2, 'fuente' => 'manual', 'fuente_id' => 'm1'], ['id' => 2, 'modulo_id' => 2, 'fuente' => 'manual', 'fuente_id' => 'm2'], ['id' => 3, 'modulo_id' => 1, 'fuente' => 'manual', 'fuente_id' => 'm1']]);
        (require dirname(__DIR__, 2).'/database/migrations/2026_07_05_000000_create_horas_extra_grupos_shared_tables.php')->up();
    }

    private function shared(): HorasExtraRepository
    {
        return new HorasExtraRepository;
    }

    private function tic(): RedmineHoursExtraRepository
    {
        return new RedmineHoursExtraRepository;
    }

    private function mantencion(): MantencionHoursExtraRepository
    {
        return app(MantencionHoursExtraRepository::class);
    }

    private function report(array $extra = []): array
    {
        return array_replace(['id' => '1', 'hora_extra' => '1', 'asignado_a' => '101', 'fecha_inicio' => '2026-09-12', 'hora' => '18:30'], $extra);
    }

    private function message(array $extra = []): array
    {
        return array_replace($this->report(), ['id' => 'm1', 'fuente_id' => 'm1', 'fuente' => 'manual'], $extra);
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['horas_extra_grupos', 'horas_extra_grupo_reportes', 'redmine_tic_reportes', 'redmine_mantencion_reportes'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $result;
    }

    #[Group('hours_compatibility')]
    public function test_tic_detach_and_recreate_keeps_existing_date_and_hour_precedence(): void
    {
        $this->tic()->syncForReport($this->report(['fecha' => '2026-09-13', 'hora_inicio' => '22:00', 'hora_fin' => '02:00']));
        $group = DB::table('horas_extra_grupos')->first();
        self::assertSame('2026-09-12', $group->fecha);
        self::assertSame(240, (int) $group->total_minutos);
        $this->shared()->updateGroupTime($group->id, '17:00', '23:00');
        $this->tic()->syncForReport($this->report());
        $next = DB::table('horas_extra_grupos')->first();
        self::assertNotEquals($group->id, $next->id); // Existing singleton recreation, not a new rule.
        self::assertSame('18:30:00', $next->hora_inicio);
        self::assertSame('18:30:00', $next->hora_fin);
        self::assertSame(0, (int) $next->total_minutos);
        $this->tic()->syncForReport($this->report(['hora_extra' => 'NO']));
        self::assertSame(0, DB::table('horas_extra_grupo_reportes')->count());
        self::assertSame(0, DB::table('horas_extra_grupos')->count());
    }

    #[Group('hours_compatibility')]
    public function test_mantencion_retains_multiple_links_and_incoming_hours_replace_defined_values(): void
    {
        $this->mantencion()->syncMessage($this->message(['hora_extra' => 'yes']));
        $first = DB::table('horas_extra_grupos')->first();
        $this->shared()->updateGroupTime($first->id, '17:00', '23:00');
        $this->mantencion()->syncMessage($this->message(['hora_inicio' => '19:00', 'hora_fin' => '21:00']));
        self::assertSame('19:00:00', DB::table('horas_extra_grupos')->where('id', $first->id)->value('hora_inicio'));
        $this->mantencion()->syncMessage($this->message(['fecha_inicio' => '2026-09-13']));
        self::assertSame(2, DB::table('horas_extra_grupo_reportes')->where('reporte_id', 1)->count());
        $this->mantencion()->syncMessage($this->message(['hora_extra' => '0']));
        self::assertSame(2, DB::table('horas_extra_grupo_reportes')->count());
    }

    #[Group('hours_compatibility')]
    public function test_date_edit_updates_all_users_for_origin_preserves_blank_endpoint_and_other_empty_groups(): void
    {
        $this->tic()->syncForReport($this->report());
        $this->tic()->syncForReport($this->report(['id' => '2', 'asignado_a' => '102']));
        $first = DB::table('horas_extra_grupos')->where('usuario_id', 1)->value('id');
        $this->shared()->attachReporte($first, 'mantencion', 1);
        $empty = $this->shared()->findOrCreateGroup(null, '2026-09-10', null, null);
        self::assertTrue($this->tic()->saveGroup('', ['fecha' => '2026-09-12', 'hora_inicio' => '20:00', 'hora_fin' => '']));
        foreach (DB::table('horas_extra_grupos')->where('fecha', '2026-09-12')->get() as $group) {
            self::assertSame('20:00:00', $group->hora_inicio);
            self::assertSame('18:30:00', $group->hora_fin);
            self::assertSame(1350, (int) $group->total_minutos);
        }
        self::assertSame(1, DB::table('horas_extra_grupo_reportes')->where('origen', 'mantencion')->count());
        self::assertTrue(DB::table('horas_extra_grupos')->where('id', $empty)->exists());
    }

    #[Group('hours_compatibility')]
    public function test_missing_user_keeps_nullable_group_and_does_not_clean_legacy_duplicates(): void
    {
        $one = $this->shared()->findOrCreateGroup(null, '2026-09-12');
        $two = DB::table('horas_extra_grupos')->insertGetId(['usuario_id' => null, 'fecha' => '2026-09-12']);
        $this->mantencion()->syncMessage($this->message(['asignado_a' => '']));
        self::assertSame(2, DB::table('horas_extra_grupos')->count());
        self::assertContains((int) DB::table('horas_extra_grupo_reportes')->value('grupo_id'), [$one, $two]);
    }

    public function test_failed_tic_move_restores_prior_groups_times_and_links(): void
    {
        $this->tic()->syncForReport($this->report());
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER reject_hours_link BEFORE INSERT ON horas_extra_grupo_reportes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic hours failure'");
        $this->tic()->syncForReport($this->report(['fecha_inicio' => '2026-09-13']));
        self::assertSame($before, $this->snapshot());
    }

    public function test_failed_mantencion_attach_restores_existing_schedule_and_keeps_other_origin(): void
    {
        $this->tic()->syncForReport($this->report());
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER reject_hours_link BEFORE INSERT ON horas_extra_grupo_reportes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic hours failure'");
        $this->mantencion()->syncMessage($this->message(['hora_inicio' => '22:00', 'hora_fin' => '23:00']));
        self::assertSame($before, $this->snapshot());
    }

    public function test_failed_second_group_update_rolls_back_entire_date_edit(): void
    {
        $this->tic()->syncForReport($this->report());
        $this->tic()->syncForReport($this->report(['id' => '2', 'asignado_a' => '102']));
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER reject_second_group BEFORE UPDATE ON horas_extra_grupos FOR EACH ROW BEGIN IF OLD.usuario_id=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic group failure'; END IF; END");
        self::assertFalse($this->tic()->saveGroup('', ['fecha' => '2026-09-12', 'hora_inicio' => '20:00', 'hora_fin' => '21:00']));
        self::assertSame($before, $this->snapshot());
    }

    public function test_failed_detach_keeps_report_flag_and_pivot(): void
    {
        $this->mantencion()->syncMessage($this->message());
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER reject_group_delete BEFORE DELETE ON horas_extra_grupos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic cleanup failure'");
        self::assertFalse($this->mantencion()->detachMessageId('m1'));
        self::assertSame($before, $this->snapshot());
    }

    public function test_report_delete_rolls_back_if_hours_cleanup_fails(): void
    {
        $this->tic()->syncForReport($this->report());
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER reject_group_delete BEFORE DELETE ON horas_extra_grupos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic cleanup failure'");
        self::assertSame(0, (new RedmineReportRepository('redmine_tic', 'TIC'))->deleteArchived('1'));
        self::assertSame($before, $this->snapshot());
    }

    public function test_deletes_use_actual_report_ids_and_keep_other_origin_module_and_empty_history(): void
    {
        $this->tic()->syncForReport($this->report());
        $this->mantencion()->syncMessage($this->message());
        $empty = $this->shared()->findOrCreateGroup(null, '2026-09-10');
        self::assertSame(1, app(MantencionReportRepository::class)->deleteByFuenteIds(['m1']));
        self::assertTrue(DB::table('redmine_mantencion_reportes')->where('id', 3)->exists());
        self::assertTrue(DB::table('horas_extra_grupo_reportes')->where('origen', 'tic')->where('reporte_id', 1)->exists());
        self::assertFalse(DB::table('horas_extra_grupo_reportes')->where('origen', 'mantencion')->where('reporte_id', 1)->exists());
        self::assertTrue(DB::table('horas_extra_grupos')->where('id', $empty)->exists());
        self::assertSame(1, (new RedmineReportRepository('redmine_tic', 'TIC'))->deleteArchived('1'));
        self::assertSame(0, DB::table('horas_extra_grupo_reportes')->count());
        self::assertTrue(DB::table('horas_extra_grupos')->where('id', $empty)->exists());
    }

    public function test_bulk_delete_does_not_detach_unselected_archived_or_other_module_reports(): void
    {
        $this->tic()->syncForReport($this->report());
        $this->tic()->syncForReport($this->report(['id' => '2']));
        $this->tic()->syncForReport($this->report(['id' => '3']));
        DB::table('redmine_tic_reportes')->where('id', 1)->update(['estado' => 'pendiente']);
        self::assertSame(1, (new RedmineReportRepository('redmine_tic', 'TIC'))->deleteActiveByIds(1, ['1', '2', '3']));
        self::assertEqualsCanonicalizing([2, 3], DB::table('horas_extra_grupo_reportes')->pluck('reporte_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_bulk_delete_detaches_many_links_with_one_bounded_query_sequence(): void
    {
        $groupId = $this->shared()->findOrCreateGroup(1, '2026-09-12');
        self::assertNotNull($groupId);
        $this->shared()->attachReporte($groupId, 'mantencion', 1);
        $this->shared()->attachReporte($groupId, 'tic', 2);
        $selected = [];
        for ($id = 10; $id < 50; $id++) {
            DB::table('redmine_tic_reportes')->insert(['id' => $id, 'modulo_id' => 1, 'estado' => 'pendiente']);
            $this->shared()->attachReporte($groupId, 'tic', $id);
            $selected[] = (string) $id;
        }

        $repository = new RedmineReportRepository('redmine_tic', 'TIC');
        $repository->deleteActiveByIds(1, []); // Warm the schema capability cache.
        DB::beginTransaction();
        try {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $query = DB::table('redmine_tic_reportes')->where('modulo_id', 1)->whereIn('id', $selected)
                ->where(fn ($builder) => $builder->whereNull('estado')->orWhere('estado', '<>', 'archivado'));
            $oldIds = (clone $query)->orderBy('id')->lockForUpdate()->pluck('id');
            foreach ($oldIds as $id) {
                $this->shared()->detachReporte('tic', (int) $id);
            }
            $oldDeleted = (clone $query)->whereIn('id', $oldIds)->delete();
            $beforeQueries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $oldSnapshot = $this->snapshot();
        } finally {
            DB::rollBack();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $deleted = $repository->deleteActiveByIds(1, $selected);
        $afterQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertSame($oldDeleted, $deleted);
        self::assertSame($oldSnapshot, $this->snapshot());
        self::assertSame(40, $deleted);
        self::assertLessThanOrEqual(12, $afterQueries);
        self::assertLessThan($beforeQueries / 5, $afterQueries);
        self::assertSame(0, DB::table('redmine_tic_reportes')->whereIn('id', $selected)->count());
        self::assertTrue(DB::table('redmine_tic_reportes')->where('id', 2)->exists());
        self::assertTrue(DB::table('horas_extra_grupo_reportes')->where('origen', 'tic')->where('reporte_id', 2)->exists());
        self::assertTrue(DB::table('horas_extra_grupo_reportes')->where('origen', 'mantencion')->where('reporte_id', 1)->exists());
        self::assertSame(2, DB::table('horas_extra_grupo_reportes')->where('grupo_id', $groupId)->count());
        file_put_contents(sys_get_temp_dir().'/nova-p06-bulk-delete-metrics.json', json_encode([
            'reports' => count($selected), 'before_queries' => $beforeQueries, 'after_queries' => $afterQueries,
        ]), LOCK_EX);
    }

    public function test_stale_sync_cannot_attach_after_report_was_deleted(): void
    {
        DB::table('redmine_tic_reportes')->where('id', 1)->delete();
        DB::table('redmine_mantencion_reportes')->where('id', 1)->delete();
        self::assertFalse($this->tic()->syncForReport($this->report()));
        self::assertFalse($this->mantencion()->syncMessage($this->message()));
        self::assertSame(0, DB::table('horas_extra_grupo_reportes')->count());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_archive_mantencion_rolls_back_report_when_hours_attach_fails(): void
    {
        require __DIR__.'/fixtures/hours_functions.php';
        DB::table('redmine_mantencion_reportes')->where('id', 1)->update(['estado' => 'procesado']);
        $before = $this->snapshot();
        DB::unprepared("CREATE TRIGGER reject_archive_hours BEFORE INSERT ON horas_extra_grupo_reportes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic archive failure'");
        $service = new MantencionRetentionService;
        self::assertFalse($service->archive_message_record($this->message(['estado' => 'procesado'])));
        self::assertSame($before, $this->snapshot());
    }

    public function test_failed_report_delete_restores_links_already_detached(): void
    {
        $this->tic()->syncForReport($this->report());
        $this->mantencion()->syncMessage($this->message());
        $before = $this->snapshot();
        foreach (['redmine_tic_reportes', 'redmine_mantencion_reportes'] as $table) {
            DB::unprepared("CREATE TRIGGER reject_{$table} BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic report delete failure'");
        }
        self::assertSame(0, (new RedmineReportRepository('redmine_tic', 'TIC'))->deleteArchived('1'));
        self::assertSame(0, app(MantencionReportRepository::class)->deleteByFuenteIds(['m1']));
        self::assertSame($before, $this->snapshot());
    }

    private function startWorker(string $action, int $groupId = 0): array
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/fixtures/hours_worker.php',
            (string) getenv('NOVA_PERSISTENCE_TEST_SOCKET'), config('database.connections.mysql.database'),
            $action, (string) $groupId], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $thread = (int) trim(fgets($pipes[1]));
        self::assertGreaterThan(0, $thread);

        return [$process, $pipes, $thread];
    }

    private function assertWorkerBlocked(int $thread): void
    {
        $deadline = microtime(true) + 3;
        do {
            $process = DB::connection('concurrent')->selectOne('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [$thread]);
            if (str_contains(strtolower((string) ($process->INFO ?? '')), 'for update')) {
                // MariaDB can expose the prepared statement as "Statistics" while waiting;
                // verify it remains in flight with the parent row still locked.
                usleep(50000);
                $waiting = DB::connection('concurrent')->selectOne('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [$thread]);
                if (($waiting->INFO ?? null) === $process->INFO) {
                    self::assertTrue(true);

                    return;
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('The independent locking query did not remain blocked.');
    }

    private function finishWorker($process, array $pipes): string
    {
        $result = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);

        return trim($result);
    }

    public function test_concurrent_creator_uses_the_committed_winner_and_attaches_its_report(): void
    {
        DB::beginTransaction();
        DB::table('usuarios_nova')->where('id', 1)->lockForUpdate()->first();
        [$worker,$pipes,$thread] = $this->startWorker('create');
        try {
            $this->assertWorkerBlocked($thread);
            $winner = $this->shared()->findOrCreateGroup(1, '2026-09-12', '18:00:00', '21:00:00');
            DB::commit();
            self::assertSame('CREATED:'.$winner, $this->finishWorker($worker, $pipes));
            self::assertSame(1, DB::table('horas_extra_grupos')->count());
            self::assertSame($winner, (int) DB::table('horas_extra_grupo_reportes')->where('reporte_id', 2)->value('grupo_id'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (is_resource($worker)) {
                proc_terminate($worker);

            }
        }
    }

    public function test_cleanup_cannot_silently_cascade_a_concurrent_attach(): void
    {
        $this->tic()->syncForReport($this->report());
        $group = (int) DB::table('horas_extra_grupos')->value('id');
        DB::beginTransaction();
        DB::table('horas_extra_grupos')->where('id', $group)->lockForUpdate()->first();
        [$worker,$pipes,$thread] = $this->startWorker('attach', $group);
        try {
            $this->assertWorkerBlocked($thread);
            $this->shared()->detachReporte('tic', 1);
            DB::commit();
            // The late attach fails explicitly, rather than reporting success then being cascaded away.
            self::assertSame('FAILED', $this->finishWorker($worker, $pipes));
            self::assertSame(0, DB::table('horas_extra_grupo_reportes')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (is_resource($worker)) {
                proc_terminate($worker);

            }
        }
    }
}
