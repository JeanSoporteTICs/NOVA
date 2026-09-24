<?php

namespace Tests\Integration;

use App\Services\Database\SchemaBaseline;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RedmineTic\Repositories\RedmineHoursExtraRepository;
use RedmineTic\Services\HoursDateReconciler;
use RuntimeException;

final class HoursDateReconciliationTest extends IsolatedMariaDbTestCase
{
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (version_compare(explode('-', DB::selectOne('SELECT VERSION() AS version')->version)[0], '12.3.2', '<')) {
            self::markTestSkipped('Requires MariaDB 12.3.2 baseline.');
        }
        $schema = new SchemaBaseline;
        $schema->bootstrap(DB::connection(), $schema->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
        DB::table('modulos_nova')->insert(['id' => 1, 'clave_modulo' => 'redmine_tic', 'nombre' => 'TIC']);
        DB::table('redmine_tic_reportes')->insert(['id' => 1, 'modulo_id' => 1, 'fecha' => '2026-01-24', 'fecha_inicio' => '2026-01-24', 'hora_extra' => 1, 'descripcion' => 'PRIVATE']);
        DB::table('horas_extra_grupos')->insert([
            ['id' => 1, 'fecha' => '2026-01-24', 'hora_inicio' => '18:00', 'hora_fin' => '20:00'],
            ['id' => 2, 'fecha' => '2026-01-26', 'hora_inicio' => '19:00', 'hora_fin' => '21:00'],
        ]);
        DB::table('horas_extra_grupo_reportes')->insert([
            ['id' => 1, 'grupo_id' => 1, 'origen' => 'tic', 'reporte_id' => 1],
            ['id' => 2, 'grupo_id' => 2, 'origen' => 'tic', 'reporte_id' => 1],
            ['id' => 3, 'grupo_id' => 2, 'origen' => 'mantencion', 'reporte_id' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function backup(): string
    {
        return $this->files[] = sys_get_temp_dir().'/nova-hours-repair-'.bin2hex(random_bytes(12)).'.json';
    }

    public function test_only_wrong_date_link_is_removed_and_backup_restores_exact_original_rows(): void
    {
        $service = new HoursDateReconciler(DB::connection());
        $reports = DB::table('redmine_tic_reportes')->get()->toJson();
        $groups = DB::table('horas_extra_grupos')->orderBy('id')->get()->toJson();
        $links = DB::table('horas_extra_grupo_reportes')->orderBy('id')->get()->toJson();
        $plan = $service->plan([1]);
        self::assertSame([1], $plan['items'][0]['keep']);
        self::assertSame([2], $plan['items'][0]['remove']);
        $file = $this->backup();
        self::assertSame(1, $service->apply($plan, $file));
        self::assertSame(0600, fileperms($file) & 0777);
        self::assertStringNotContainsString('PRIVATE', file_get_contents($file));
        self::assertSame([1, 3], DB::table('horas_extra_grupo_reportes')->orderBy('id')->pluck('id')->all());
        self::assertSame($reports, DB::table('redmine_tic_reportes')->get()->toJson());
        self::assertSame($groups, DB::table('horas_extra_grupos')->orderBy('id')->get()->toJson());
        self::assertSame(0, $service->apply($service->plan([1]), $this->backup()));
        self::assertSame(1, $service->restore(json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)));
        self::assertSame($links, DB::table('horas_extra_grupo_reportes')->orderBy('id')->get()->toJson());
    }

    public function test_missing_correct_link_blocks_instead_of_inventing_a_date_or_deleting_the_last_link(): void
    {
        DB::table('horas_extra_grupo_reportes')->where('id', 1)->delete();
        $service = new HoursDateReconciler(DB::connection());
        $plan = $service->plan([1]);
        self::assertNotNull($plan['items'][0]['blocked']);
        try {
            $service->apply($plan, $this->backup());
            self::fail('Missing keeper must block repair.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('exactamente un vínculo', $e->getMessage());
        }
        self::assertTrue(DB::table('horas_extra_grupo_reportes')->where('id', 2)->exists());
    }

    public function test_changed_report_invalidates_review_without_removing_links(): void
    {
        $service = new HoursDateReconciler(DB::connection());
        $plan = $service->plan([1]);
        DB::table('redmine_tic_reportes')->where('id', 1)->update(['asunto' => 'Changed']);
        try {
            $service->apply($plan, $this->backup());
            self::fail('Stale review must fail.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('cambiaron desde la revisión', $e->getMessage());
        }
        self::assertSame(3, DB::table('horas_extra_grupo_reportes')->count());
    }

    public function test_failure_rolls_back_all_deletions_and_retains_backup(): void
    {
        DB::table('redmine_tic_reportes')->insert(['id' => 2, 'modulo_id' => 1, 'fecha' => '2026-01-24', 'fecha_inicio' => '2026-01-24']);
        DB::table('horas_extra_grupo_reportes')->insert([
            ['id' => 4, 'grupo_id' => 1, 'origen' => 'tic', 'reporte_id' => 2],
            ['id' => 5, 'grupo_id' => 2, 'origen' => 'tic', 'reporte_id' => 2],
        ]);
        DB::statement("CREATE TRIGGER fail_repair BEFORE DELETE ON horas_extra_grupo_reportes FOR EACH ROW BEGIN IF OLD.id=5 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'; END IF; END");
        $service = new HoursDateReconciler(DB::connection());
        $file = $this->backup();
        try {
            $service->apply($service->plan([1, 2]), $file);
            self::fail('Injected failure must abort.');
        } catch (QueryException $e) {
            self::assertSame('45000', $e->errorInfo[0]);
        }
        self::assertSame(5, DB::table('horas_extra_grupo_reportes')->count());
        self::assertFileExists($file);
    }

    public function test_backup_failure_preserves_links_and_corrupted_restore_is_rejected(): void
    {
        $service = new HoursDateReconciler(DB::connection());
        $plan = $service->plan([1]);
        try {
            $service->apply($plan, dirname(__DIR__, 2).'/must-not-create.json');
            self::fail('The public project must not receive a backup.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('fuera del proyecto', $e->getMessage());
        }
        self::assertSame(3, DB::table('horas_extra_grupo_reportes')->count());
        $service->apply($plan, $this->backup());
        $plan['links'][1]['reporte_id'] = 999;
        try {
            $service->restore($plan);
            self::fail('Corrupted backup must fail.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('integridad', $e->getMessage());
        }
        self::assertSame([1, 3], DB::table('horas_extra_grupo_reportes')->orderBy('id')->pluck('id')->all());
    }

    public function test_direct_tic_attachment_rejects_a_different_date(): void
    {
        DB::table('horas_extra_grupo_reportes')->where('id', 2)->delete();
        $repository = new RedmineHoursExtraRepository;
        try {
            $repository->attachReporte(2, 1);
            self::fail('Wrong date must fail.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('fecha de inicio', $e->getMessage());
        }
        self::assertSame([1], DB::table('horas_extra_grupo_reportes')->where('origen', 'tic')->pluck('grupo_id')->all());
        $repository->attachReporte(1, 1);
        self::assertSame([1], DB::table('horas_extra_grupo_reportes')->where('origen', 'tic')->pluck('grupo_id')->all());
    }
}
