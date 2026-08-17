<?php

namespace Tests\Unit;

use RedmineTic\Services\RedmineIssueSenderService;
use Tests\TestCase;

/**
 * ETAPA B / Lote B5.4 — direct unit coverage of the Redmine issue payload
 * construction now living in RedmineIssueSenderService::buildIssuePayload(),
 * extracted verbatim from RedmineDataRepository::buildIssuePayload(). Only
 * exercises payload construction (no network access) — send()'s HTTP leg
 * (postRedmineIssue()) always talks to a real cURL socket and is
 * deliberately not unit tested here; the existing
 * test_send_reports_to_redmine_without_token_short_circuits_before_http in
 * RedmineTicReportsBaselineTest.php already proves the facade never reaches
 * it without a resolved API token.
 */
class RedmineIssueSenderServiceTest extends TestCase
{
    private function service(): RedmineIssueSenderService
    {
        return new RedmineIssueSenderService;
    }

    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'platform_url' => 'https://redmine.example.test/issues.json',
            'project_id' => 7,
            'tracker_id' => 3,
            'priority_id' => 2,
            'status_id' => 1,
        ], $overrides);
    }

    private function baseReport(array $overrides = []): array
    {
        return array_merge([
            'asunto' => 'Impresora no imprime',
            'descripcion' => 'Detalle del problema',
            'categoria' => 'Equipos',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-02',
            'tiempo_estimado' => '1.5',
            'asignado_a' => '100',
            'solicitante' => 'Juan Perez',
            'unidad' => 'HBV',
            'unidad_solicitante' => 'HBV',
            'hora_extra' => 'NO',
        ], $overrides);
    }

    private function noopCategoryResolver(): callable
    {
        return fn (string $category): int => 0;
    }

    public function test_build_issue_payload_maps_basic_fields(): void
    {
        $payload = $this->service()->buildIssuePayload($this->baseReport(), $this->baseConfig(), $this->noopCategoryResolver());

        $this->assertSame(7, $payload['project_id']);
        $this->assertSame('Impresora no imprime', $payload['subject']);
        $this->assertSame('Detalle del problema', $payload['description']);
        $this->assertSame(3, $payload['tracker_id']);
        $this->assertSame(2, $payload['priority_id']);
        $this->assertSame(1, $payload['status_id']);
    }

    public function test_build_issue_payload_uses_category_resolver_and_includes_id_only_when_positive(): void
    {
        $receivedCategory = null;
        $resolver = function (string $category) use (&$receivedCategory): int {
            $receivedCategory = $category;

            return 42;
        };

        $payload = $this->service()->buildIssuePayload($this->baseReport(['categoria' => 'Equipos']), $this->baseConfig(), $resolver);

        $this->assertSame('Equipos', $receivedCategory);
        $this->assertSame(42, $payload['category_id']);

        $payloadWithoutMatch = $this->service()->buildIssuePayload($this->baseReport(), $this->baseConfig(), $this->noopCategoryResolver());
        $this->assertArrayNotHasKey('category_id', $payloadWithoutMatch);
    }

    public function test_build_issue_payload_includes_valid_dates_and_omits_invalid_ones(): void
    {
        $payload = $this->service()->buildIssuePayload($this->baseReport([
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-02',
        ]), $this->baseConfig(), $this->noopCategoryResolver());
        $this->assertSame('2026-06-01', $payload['start_date']);
        $this->assertSame('2026-06-02', $payload['due_date']);

        $payloadInvalid = $this->service()->buildIssuePayload($this->baseReport([
            'fecha_inicio' => 'not-a-date',
            'fecha_fin' => '',
        ]), $this->baseConfig(), $this->noopCategoryResolver());
        $this->assertArrayNotHasKey('start_date', $payloadInvalid);
        $this->assertArrayNotHasKey('due_date', $payloadInvalid);
    }

    public function test_build_issue_payload_includes_estimated_hours_only_when_numeric(): void
    {
        $payload = $this->service()->buildIssuePayload($this->baseReport(['tiempo_estimado' => '1.5']), $this->baseConfig(), $this->noopCategoryResolver());
        $this->assertSame(1.5, $payload['estimated_hours']);

        $payloadInvalid = $this->service()->buildIssuePayload($this->baseReport(['tiempo_estimado' => '']), $this->baseConfig(), $this->noopCategoryResolver());
        $this->assertArrayNotHasKey('estimated_hours', $payloadInvalid);
    }

    public function test_build_issue_payload_includes_assigned_to_id_only_when_present(): void
    {
        $payload = $this->service()->buildIssuePayload($this->baseReport(['asignado_a' => '100']), $this->baseConfig(), $this->noopCategoryResolver());
        $this->assertSame('100', $payload['assigned_to_id']);

        $payloadEmpty = $this->service()->buildIssuePayload($this->baseReport(['asignado_a' => '']), $this->baseConfig(), $this->noopCategoryResolver());
        $this->assertArrayNotHasKey('assigned_to_id', $payloadEmpty);
    }

    public function test_build_issue_payload_includes_custom_fields_only_when_config_key_and_value_present(): void
    {
        $config = $this->baseConfig(['cf_solicitante' => 55, 'cf_unidad' => 56]);
        $payload = $this->service()->buildIssuePayload($this->baseReport(['solicitante' => 'Juan Perez', 'unidad' => 'HBV', 'unidad_solicitante' => '']), $config, $this->noopCategoryResolver());

        $this->assertArrayHasKey('custom_fields', $payload);
        $ids = array_column($payload['custom_fields'], 'id');
        $this->assertContains(55, $ids);
        $this->assertContains(56, $ids);

        $payloadNoConfig = $this->service()->buildIssuePayload($this->baseReport(), $this->baseConfig(), $this->noopCategoryResolver());
        $this->assertArrayNotHasKey('custom_fields', $payloadNoConfig);
    }

    public function test_build_issue_payload_marks_hora_extra_custom_field_as_1_or_0(): void
    {
        $config = $this->baseConfig(['cf_hora_extra' => 60]);

        $payloadSi = $this->service()->buildIssuePayload($this->baseReport(['hora_extra' => 'SI']), $config, $this->noopCategoryResolver());
        $field = collect($payloadSi['custom_fields'])->firstWhere('id', 60);
        $this->assertSame('1', $field['value']);

        $payloadNo = $this->service()->buildIssuePayload($this->baseReport(['hora_extra' => 'NO']), $config, $this->noopCategoryResolver());
        $field = collect($payloadNo['custom_fields'])->firstWhere('id', 60);
        $this->assertSame('0', $field['value']);
    }

    public function test_build_issue_payload_preserves_exact_redmine_unit_value(): void
    {
        $config = $this->baseConfig(['cf_unidad_solicitante' => 11]);
        $payload = $this->service()->buildIssuePayload(
            $this->baseReport(['unidad_solicitante' => 'UNI_CORE_Exacta']),
            $config,
            $this->noopCategoryResolver()
        );

        $field = collect($payload['custom_fields'])->firstWhere('id', 11);
        $this->assertSame('UNI_CORE_Exacta', $field['value']);
    }

    public function test_send_short_circuits_with_error_when_platform_url_is_not_configured(): void
    {
        $result = $this->service()->send($this->baseReport(), $this->baseConfig(['platform_url' => '']), 'fake-token', $this->noopCategoryResolver());

        $this->assertSame(0, $result['http_code']);
        $this->assertSame('', $result['body']);
        $this->assertSame('URL no configurada', $result['error']);
        $this->assertArrayHasKey('payload', $result);
        $this->assertArrayHasKey('issue', $result['payload']);
    }

    public function test_failure_message_exposes_redmine_validation_errors(): void
    {
        $message = $this->service()->failureMessage([
            'http_code' => 422,
            'body' => json_encode(['errors' => ['Unidad solicitante no está incluida en la lista']]),
            'error' => '',
        ]);

        $this->assertSame('Unidad solicitante no está incluida en la lista', $message);
    }

    public function test_failure_message_prioritizes_transport_errors_and_has_http_fallback(): void
    {
        $this->assertSame('Tiempo de espera agotado', $this->service()->failureMessage([
            'http_code' => 0,
            'body' => '',
            'error' => 'Tiempo de espera agotado',
        ]));
        $this->assertSame('Redmine rechazó la creación del reporte (HTTP 403).', $this->service()->failureMessage([
            'http_code' => 403,
            'body' => '',
            'error' => '',
        ]));
    }
}
