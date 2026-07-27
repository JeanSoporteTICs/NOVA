<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Modulos\Nova\Repositories\HorasExtraRepository;
use RedmineTic\Repositories\RedmineCatalogRepository;
use RedmineTic\Services\LegacyTicBackupImportService;
use Tests\TestCase;

class LegacyTicBackupImportServiceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int,string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_analyze_selects_only_requested_archived_users_and_excludes_pending(): void
    {
        [$first, $second] = $this->createUsers();
        $package = $this->createPackage(
            archived: [
                $this->report('legacy-a', 800001, $first, '01-07-2026'),
                $this->report('legacy-b', 800002, $second, '02-07-2026'),
                $this->report('legacy-c', 800003, '99999991', '03-07-2026'),
            ],
            pending: [
                $this->report('pending-a', 800004, $first, '04-07-2026'),
            ],
            hours: [],
        );

        $summary = app(LegacyTicBackupImportService::class)->analyze($package, [$first, $second]);

        $this->assertSame(1, $summary['pending_excluded']);
        $this->assertSame(2, $summary['selected_reports']);
        $this->assertSame(2, $summary['unique_redmine_tickets']);
        $this->assertSame(0, $summary['existing_ticket_matches']);
    }

    public function test_import_is_idempotent_and_preserves_one_report_in_multiple_hour_dates(): void
    {
        [$first, $second] = $this->createUsers();
        $ticketA = random_int(81000000, 81999999);
        $ticketB = random_int(82000000, 82999999);
        $category = 'Categoria legacy ' . Str::random(10);
        $unit = 'Unidad legacy ' . Str::random(10);
        $reportA = $this->report('legacy-a', $ticketA, $first, '01-07-2026', $category, $unit);
        $reportB = $this->report('legacy-b', $ticketB, $second, '02-07-2026', $category, $unit);
        $package = $this->createPackage(
            archived: [$reportA, $reportB],
            pending: [$this->report('pending-a', 83000001, $first, '03-07-2026')],
            hours: [
                ['fecha' => '2026-07-04', 'hora_inicio' => '18:00:00', 'hora_fin' => '20:00:00', 'reports' => [$reportA]],
                ['fecha' => '2026-07-05', 'hora_inicio' => '19:00:00', 'hora_fin' => '21:00:00', 'reports' => [$reportA]],
            ],
        );

        $service = app(LegacyTicBackupImportService::class);
        $result = $service->import($package, [$first, $second]);

        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        $rows = DB::table('redmine_tic_reportes')
            ->where('modulo_id', $moduleId)
            ->whereIn('redmine_id', [$ticketA, $ticketB])
            ->get();
        $reportDatabaseId = (int) $rows->firstWhere('redmine_id', $ticketA)->id;

        $this->assertSame(2, $result['inserted_reports']);
        $this->assertSame(2, $result['created_hour_groups']);
        $this->assertSame(2, $result['created_hour_links']);
        $this->assertCount(2, $rows);
        $this->assertSame(['archivado'], $rows->pluck('estado')->unique()->values()->all());
        $this->assertSame(2, DB::table('horas_extra_grupo_reportes')
            ->where('origen', 'tic')
            ->where('reporte_id', $reportDatabaseId)
            ->count());
        $hourGroups = collect(app(HorasExtraRepository::class)->groupsForOrigen('tic'))
            ->filter(static fn (array $group): bool => in_array($reportDatabaseId, $group['reporte_ids'], true));
        $this->assertCount(2, $hourGroups);

        $categoryRow = DB::table('catalogos_modulo')
            ->where('modulo_id', $moduleId)
            ->where('tipo', 'categoria')
            ->where('nombre', $category)
            ->first();
        $this->assertNotNull($categoryRow);
        $this->assertSame(0, (int) $categoryRow->activo);
        $catalogs = new RedmineCatalogRepository('redmine_tic', 'Backlog Soporte TI');
        $this->assertSame($category, $catalogs->nameById($categoryRow->id));
        $this->assertNotContains($category, array_column($catalogs->categories(), 'nombre'));

        $secondRun = $service->import($package, [$first, $second]);
        $this->assertSame(0, $secondRun['inserted_reports']);
        $this->assertSame(2, $secondRun['skipped_existing_reports']);
        $this->assertSame(0, $secondRun['created_hour_groups']);
        $this->assertSame(2, $secondRun['reused_hour_groups']);
        $this->assertSame(0, $secondRun['created_hour_links']);
        $this->assertSame(2, $secondRun['existing_hour_links']);
        $this->assertSame(2, DB::table('redmine_tic_reportes')
            ->where('modulo_id', $moduleId)
            ->whereIn('redmine_id', [$ticketA, $ticketB])
            ->count());
    }

    /** @return array{0:string,1:string} */
    private function createUsers(): array
    {
        $first = (string) random_int(91000000, 91999999);
        $second = (string) random_int(92000000, 92999999);
        foreach ([$first, $second] as $index => $redmineId) {
            DB::table('usuarios_nova')->insert([
                'uuid' => (string) Str::uuid(),
                'usuario' => 'legacy_import_test_' . Str::random(10),
                'redmine_id' => $redmineId,
                'nombre' => 'Legacy',
                'apellido' => 'Tester ' . $index,
                'rol' => 'usuario',
                'estado' => 'activo',
                'password' => bcrypt(Str::random(24)),
                'creado_at' => now(),
                'actualizado_at' => now(),
            ]);
        }

        return [$first, $second];
    }

    /** @return array<string,mixed> */
    private function report(
        string $legacyId,
        int $ticket,
        string $assignee,
        string $date,
        string $category = 'Equipos',
        string $unit = 'HBV',
    ): array {
        return [
            'id' => $legacyId,
            'redmine_id' => (string) $ticket,
            'estado' => 'procesado',
            'tipo' => 'Soporte',
            'prioridad' => 'NORMAL',
            'categoria' => $category,
            'unidad' => $unit,
            'unidad_solicitante' => $unit,
            'solicitante' => 'Solicitante prueba',
            'asunto' => 'Reporte legacy ' . $legacyId,
            'mensaje' => 'Mensaje legacy ' . $legacyId,
            'descripcion' => '',
            'fecha' => $date,
            'fecha_inicio' => $date,
            'fecha_fin' => $date,
            'hora' => '10:30',
            'numero' => '56900000000',
            'asignado_a' => $assignee,
            'asignado_nombre' => 'Legacy Tester',
            'hora_extra' => false,
            'tiempo_estimado' => '1',
            'procesado_ts' => '2026-07-06T12:00:00-04:00',
            '_archivado_en' => '2026-07-07T12:00:00-04:00',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $archived
     * @param array<int,array<string,mixed>> $pending
     * @param array<int,array<string,mixed>> $hours
     */
    private function createPackage(array $archived, array $pending, array $hours): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nova-legacy-tic-test-' . Str::random(12);
        $this->temporaryDirectories[] = $root;
        mkdir($root . '/data/reportes/2026', 0770, true);
        mkdir($root . '/data/horasExtras/2026', 0770, true);
        file_put_contents($root . '/data/reportes/2026/julio.json', json_encode($archived, JSON_PRETTY_PRINT));
        file_put_contents($root . '/data/horasExtras/2026/julio.json', json_encode($hours, JSON_PRETTY_PRINT));
        file_put_contents($root . '/data/mensaje.json', json_encode($pending, JSON_PRETTY_PRINT));
        file_put_contents($root . '/manifest.json', json_encode([
            'type' => 'redmine-maintenance-package',
            'version' => 2,
            'sections' => [
                'archivados' => ['files' => ['reportes/2026/julio.json']],
                'pendientes' => ['files' => ['mensaje.json']],
                'horas_extras' => ['files' => ['horasExtras/2026/julio.json']],
            ],
        ], JSON_PRETTY_PRINT));

        return $root;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
