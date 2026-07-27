<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;
use App\Modulos\RedmineMantencion\Services\CorePendingReportSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorePendingReportSyncServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_changed_core_status_updates_the_same_pending_report(): void
    {
        $service = app(CorePendingReportSyncService::class);
        $current = [
            'id' => 'old-source',
            'fuente' => 'core',
            'fuente_id' => 'old-source',
            'id_core' => '7788',
            'estado' => 'pendiente',
            'core_estado' => 'En Revisión',
            'descripcion' => 'Estado CORE: En Revisión',
            'redmine_id' => '',
            'procesado_ts' => '',
        ];
        $incoming = [
            'id' => 'core-core-id:7788',
            'fuente' => 'core',
            'fuente_id' => 'core-id:7788',
            'id_core' => '7788',
            'estado' => 'pendiente',
            'core_estado' => 'Gestionada',
            'descripcion' => 'Estado CORE: Gestionada',
        ];

        $indexes = $service->indexes([$current]);
        $index = $service->matchIndex($indexes, $incoming);
        $merge = $service->mergePending($current, $incoming);

        $this->assertSame(0, $index);
        $this->assertTrue($merge['eligible']);
        $this->assertTrue($merge['changed']);
        $this->assertSame('Gestionada', $merge['message']['core_estado']);
        $this->assertSame('old-source', $merge['message']['fuente_id']);
        $this->assertSame('old-source', $merge['message']['id']);
    }

    public function test_unchanged_pending_report_is_not_marked_as_updated(): void
    {
        $service = app(CorePendingReportSyncService::class);
        $current = [
            'fuente' => 'core',
            'fuente_id' => 'core-id:99',
            'id_core' => '99',
            'estado' => 'pendiente',
            'core_estado' => 'Gestionada',
            'descripcion' => 'Sin cambios',
        ];

        $merge = $service->mergePending($current, $current);

        $this->assertTrue($merge['eligible']);
        $this->assertFalse($merge['changed']);
    }

    public function test_processed_or_error_reports_are_never_refreshed_from_core(): void
    {
        $service = app(CorePendingReportSyncService::class);
        foreach (['procesado', 'error', 'archivado'] as $state) {
            $current = [
                'fuente_id' => 'core-id-1',
                'id_core' => '1',
                'estado' => $state,
                'core_estado' => 'En Revisión',
            ];
            $incoming = array_merge($current, ['core_estado' => 'Gestionada']);

            $merge = $service->mergePending($current, $incoming);

            $this->assertFalse($merge['eligible']);
            $this->assertFalse($merge['changed']);
            $this->assertSame('En Revisión', $merge['message']['core_estado']);
        }
    }

    public function test_repository_reconciles_changed_fingerprint_by_core_id_without_duplicate(): void
    {
        $repo = app(MantencionReportRepository::class);
        $coreId = (string) random_int(90000000, 99999999);
        $oldSource = 'legacy-' . bin2hex(random_bytes(8));
        $newSource = 'core-id:' . $coreId;

        $repo->syncMessages([[
            'fuente' => 'core',
            'fuente_id' => $oldSource,
            'id_core' => $coreId,
            'estado' => 'pendiente',
            'core_estado' => 'En Revisión',
            'asunto' => 'Solicitud CORE',
            'descripcion' => 'Estado CORE: En Revisión',
            'fecha' => '25-07-2026',
        ]]);

        $current = collect($repo->activeMessages())
            ->first(fn (array $message): bool => ($message['id_core'] ?? '') === $coreId);
        $this->assertIsArray($current);

        $incoming = [
            'fuente' => 'core',
            'fuente_id' => $newSource,
            'id_core' => $coreId,
            'estado' => 'pendiente',
            'core_estado' => 'Gestionada',
            'asunto' => 'Solicitud CORE',
            'descripcion' => 'Estado CORE: Gestionada',
            'fecha' => '25-07-2026',
        ];
        $merge = app(CorePendingReportSyncService::class)->mergePending($current, $incoming);
        $this->assertTrue($merge['changed']);
        $repo->syncMessages([$merge['message']]);

        $rows = DB::table('redmine_mantencion_reportes')
            ->where('fuente', 'core')
            ->where('id_core', $coreId)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($oldSource, (string) $rows->first()->fuente_id);
        $this->assertSame('Gestionada', (string) $rows->first()->estado_redmine);
    }
}
