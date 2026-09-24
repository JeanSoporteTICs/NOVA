<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use App\Modulos\RedmineMantencion\Services\CorePendingReportSyncService;
use App\Modulos\RedmineMantencion\Support\CoreReportDetail;
use PHPUnit\Framework\TestCase;

class MantencionCoreDetailImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/RedmineMantencion/controllers/dashboard.php';
    }

    public function test_detail_link_id_precedes_unrelated_data_id(): void
    {
        $service = new MantencionCoreImportService();
        $html = '<tr data-id="9911"><td>Solicitante</td>'
            . '<td><a href="/obtener_detalle_modificar_usuario/4433">Ver detalle</a></td></tr>';

        $this->assertSame(['4433'], $service->dashboard_core_extract_candidate_request_ids($html));
    }

    public function test_imports_all_fields_from_core_detail_table(): void
    {
        $html = <<<'HTML'
            <table>
              <thead><tr>
                <th>Tipo solicitud</th><th>RUN</th><th>Nombre</th>
                <th>Motivo</th><th>Establecimientos</th><th>Otros permisos</th>
              </tr></thead>
              <tbody><tr>
                <td>Modificar Perfil</td><td>12345678-9</td><td>Persona de Prueba</td>
                <td>Realiza turnos en UTI 2</td><td>-</td>
                <td>Favor activar módulo RCH<br></td>
              </tr></tbody>
            </table>
            HTML;

        $service = new MantencionCoreImportService();
        $detail = $service->dashboard_core_extract_detail_from_body($html);
        $this->assertSame('12345678-9', $detail['detalle_run']);
        $this->assertSame('Persona de Prueba', $detail['detalle_nombre']);
        $this->assertSame('Realiza turnos en UTI 2', $detail['detalle_motivo']);
        $this->assertSame('Favor activar módulo RCH', $detail['detalle_otros_permisos']);
        $this->assertSame('-', $detail['detalle_establecimientos']);
        $this->assertCount(1, $detail['detalle_items']);

        $urls = $service->dashboard_core_detail_url_candidates(
            'https://core.example.test', ['tipo de solicitud' => 'Modificar Usuario', 'id' => '4433']
        );
        $this->assertContains('https://core.example.test/obtener_detalle_modificar_perfil/4433', $urls);

        $columns = dashboard_core_detail_table_schema(['fuente' => 'core', 'core_tipo_solicitud' => 'Modificar Usuario']);
        $this->assertContains(['label' => 'Establecimientos', 'key' => 'detalle_establecimientos'], $columns);
    }

    public function test_html_detail_table_overrides_generic_json_name(): void
    {
        $body = json_encode([
            'nombre' => 'Nombre del solicitante',
            'html' => '<table><tr><th>Tipo solicitud</th><th>RUN</th><th>Nombre</th><th>Motivo</th><th>Otros permisos</th></tr>'
                . '<tr><td>Modificar Perfil</td><td>12345678-9</td><td>Persona del detalle</td><td>Turno</td><td>RCH</td></tr></table>',
        ], JSON_UNESCAPED_UNICODE);

        $detail = (new MantencionCoreImportService())->dashboard_core_extract_detail_from_body($body);

        $this->assertSame('Persona del detalle', $detail['detalle_nombre']);
        $this->assertSame('12345678-9', $detail['detalle_run']);
        $this->assertSame('Persona del detalle', $detail['detalle_items'][0]['detalle_nombre']);
    }

    public function test_detail_fetch_uses_request_id_before_other_candidates_and_ignores_name_only_response(): void
    {
        $service = new class extends MantencionCoreImportService {
            public array $urls = [];

            public function dashboard_core_curl(string $url, array $options = []): array
            {
                $this->urls[] = $url;
                if (str_contains($url, '/obtener_detalle_modificar_usuario/')) {
                    return ['error' => '', 'http_code' => 200, 'body' => '{"nombre":"Persona Equivocada"}'];
                }
                return ['error' => '', 'http_code' => 200, 'body' =>
                    '<table><tr><th>Tipo solicitud</th><th>RUN</th><th>Nombre</th><th>Motivo</th><th>Otros permisos</th></tr>'
                    . '<tr><td>Modificar Perfil</td><td>12345678-9</td><td>Persona Correcta</td><td>Turno</td><td>RCH</td></tr></table>'];
            }
        };

        $rows = $service->dashboard_core_enrich_rows_with_detail([[
            'id_solicitud_core' => '4433',
            'tipo de solicitud' => 'Modificar Usuario',
            '_candidate_request_ids' => ['9911', '4433'],
        ]], 'https://core.example.test', '', []);

        $this->assertStringEndsWith('/4433', $service->urls[0]);
        $this->assertSame('Persona Correcta', $rows[0]['detalle_nombre']);
        $this->assertSame('12345678-9', $rows[0]['detalle_run']);
        $this->assertCount(2, $service->urls);
        $this->assertStringContainsString('/obtener_detalle_modificar_perfil/4433', $service->urls[1]);
    }

    public function test_core_detail_survives_storage_and_refreshes_pending_report(): void
    {
        $base = [
            'fuente' => 'core',
            'fuente_id' => 'core-id:4433',
            'id_core' => '4433',
            'estado' => 'pendiente',
            'descripcion' => 'Reporte CORE',
            'core_detalle_nombre' => 'Persona Anterior',
            'core_detalle_items' => [['detalle_nombre' => 'Persona Anterior']],
        ];
        $incoming = $base;
        $incoming['core_detalle_nombre'] = 'Persona Correcta';
        $incoming['core_detalle_items'] = [[
            'detalle_tipo_solicitud' => 'Modificar Perfil',
            'detalle_run' => '12345678-9',
            'detalle_nombre' => 'Persona Correcta',
            'detalle_motivo' => 'Turno',
            'detalle_establecimientos' => '-',
            'detalle_otros_permisos' => 'RCH',
        ]];

        $stored = CoreReportDetail::encode($incoming);
        $this->assertSame($incoming['core_detalle_items'], CoreReportDetail::decode($stored)['core_detalle_items']);
        $sync = new CorePendingReportSyncService();
        $this->assertTrue($sync->mergePending($base, $incoming)['changed']);
        $partial = $base;
        unset($partial['core_detalle_nombre'], $partial['core_detalle_items']);
        $this->assertSame('Persona Anterior', $sync->mergePending($base, $partial)['message']['core_detalle_nombre']);
    }

    public function test_legacy_core_description_restores_detail_for_modal(): void
    {
        $description = "Tipo de solicitud: Modificar Usuario\n"
            . "Detalle tipo solicitud: Modificar Perfil\n"
            . "RUN: 12345678-9\n"
            . "Nombre: Persona de Prueba\n"
            . "Motivo: Realiza turnos en UTI 2\n"
            . "Establecimientos: -\n"
            . "Otros permisos: Favor activar módulo RCH";
        $detail = CoreReportDetail::fromDescription($description);

        $this->assertSame('Modificar Perfil', $detail['core_detalle_items'][0]['detalle_tipo_solicitud']);
        $this->assertSame('12345678-9', $detail['core_detalle_items'][0]['detalle_run']);
        $this->assertSame('Persona de Prueba', $detail['core_detalle_items'][0]['detalle_nombre']);
        $this->assertSame('Favor activar módulo RCH', $detail['core_detalle_items'][0]['detalle_otros_permisos']);
    }

    public function test_name_only_response_is_not_attached_as_another_persons_detail(): void
    {
        $service = new class extends MantencionCoreImportService {
            public function dashboard_core_curl(string $url, array $options = []): array
            {
                return ['error' => '', 'http_code' => 200, 'body' => '{"nombre":"Persona Equivocada"}'];
            }
        };

        $rows = $service->dashboard_core_enrich_rows_with_detail([[
            'id_solicitud_core' => '4433',
            'tipo de solicitud' => 'Modificar Usuario',
            '_candidate_request_ids' => ['9911'],
        ]], 'https://core.example.test', '', []);

        $this->assertArrayNotHasKey('detalle_nombre', $rows[0]);
    }
}
