<?php

namespace RedmineTic\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RedmineTic\Support\RedmineUrlSupport;

/**
 * ETAPA B / Lote B5.4 — transporte HTTP puro para creación de issues en
 * Redmine (construcción del payload + POST HTTP), extraído de
 * RedmineDataRepository::buildIssuePayload()/postRedmineIssue(). No conoce
 * persistencia, activity log ni el dominio de Reportes/Horas Extra — eso
 * permanece en RedmineDataRepository::sendReportsToRedmine(), que sigue
 * siendo responsable de iterar la selección, guardar el resultado y
 * sincronizar horas extra. La resolución de category_id sigue siendo
 * responsabilidad del catálogo (dominio ya delegado en B1); se recibe aquí
 * como callback para no duplicar el matching difuso ni acoplar este cliente
 * al catálogo.
 */
final class RedmineIssueSenderService
{
    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $config
     * @return array{http_code:int,body:string,error:string,payload:array<string,mixed>}
     */
    public function send(array $report, array $config, string $token, callable $categoryIdResolver): array
    {
        $payload = ['issue' => $this->buildIssuePayload($report, $config, $categoryIdResolver)];
        $result = $this->postRedmineIssue($config, $payload, $token);

        return $result + ['payload' => $payload];
    }

    /**
     * Converts a rejected Redmine response into a message suitable for the UI.
     * Redmine performs the definitive validation of list custom-field values
     * when the issue is created.
     *
     * @param  array{http_code?:int,body?:string,error?:string}  $result
     */
    public function failureMessage(array $result): string
    {
        $transportError = trim((string) ($result['error'] ?? ''));
        if ($transportError !== '') {
            return $transportError;
        }

        $body = trim((string) ($result['body'] ?? ''));
        $decoded = json_decode($body, true);
        $errors = is_array($decoded) ? ($decoded['errors'] ?? []) : [];
        if (is_array($errors)) {
            $errors = array_values(array_filter(array_map(
                static fn (mixed $error): string => trim((string) $error),
                $errors
            )));
            if ($errors !== []) {
                return implode(' ', $errors);
            }
        }

        if ($body !== '') {
            return $body;
        }

        $httpCode = (int) ($result['http_code'] ?? 0);

        return $httpCode > 0
            ? 'Redmine rechazó la creación del reporte (HTTP '.$httpCode.').'
            : 'No fue posible conectar con Redmine.';
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public function buildIssuePayload(array $report, array $config, callable $categoryIdResolver): array
    {
        $issue = [
            'project_id' => (int) ($config['project_id'] ?? 0),
            'subject' => trim((string) ($report['asunto'] ?? $report['descripcion'] ?? $report['mensaje'] ?? '')),
            'description' => trim((string) ($report['descripcion'] ?? '')),
            'tracker_id' => (int) ($config['tracker_id'] ?? 0),
            'priority_id' => (int) ($config['priority_id'] ?? 0),
            'status_id' => (int) ($config['status_id'] ?? 0),
        ];

        $categoryId = (int) $categoryIdResolver((string) ($report['categoria'] ?? ''));
        if ($categoryId > 0) {
            $issue['category_id'] = $categoryId;
        }

        $start = $this->parseDate($report['fecha_inicio'] ?? $report['fecha'] ?? '');
        $due = $this->parseDate($report['fecha_fin'] ?? $report['fecha'] ?? $report['fecha_inicio'] ?? '');
        if ($start !== '') {
            $issue['start_date'] = $start;
        }
        if ($due !== '') {
            $issue['due_date'] = $due;
        }
        if (is_numeric($report['tiempo_estimado'] ?? null)) {
            $issue['estimated_hours'] = (float) $report['tiempo_estimado'];
        }
        if (! empty($report['asignado_a'])) {
            $issue['assigned_to_id'] = $report['asignado_a'];
        }

        $customFields = [];
        foreach ([
            'cf_solicitante' => $report['solicitante'] ?? '',
            'cf_unidad' => $report['unidad'] ?? '',
            'cf_unidad_solicitante' => $report['unidad_solicitante'] ?? $report['unidad'] ?? '',
            'cf_hora_extra' => in_array(strtolower((string) ($report['hora_extra'] ?? '')), ['si', 'sí', '1', 'true'], true) ? '1' : '0',
        ] as $configKey => $value) {
            if (empty($config[$configKey]) || trim((string) $value) === '') {
                continue;
            }
            $customFields[] = ['id' => $config[$configKey], 'value' => $value];
        }
        if ($customFields) {
            $issue['custom_fields'] = $customFields;
        }

        return array_filter($issue, static fn ($value): bool => $value !== '' && $value !== 0 && $value !== null);
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $payload
     * @return array{http_code:int,body:string,error:string}
     */
    private function postRedmineIssue(array $config, array $payload, string $token): array
    {
        $url = trim((string) ($config['platform_url'] ?? ''));
        if ($url === '') {
            return ['http_code' => 0, 'body' => '', 'error' => 'URL no configurada'];
        }
        return $this->requestRedmine($url, $token, $payload, 20);
    }

    public function checkAvailability(array $config, string $token): array
    {
        $url = RedmineUrlSupport::redmineIssuesUrl((string) ($config['platform_url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'error' => 'La URL de Redmine no está configurada o no es válida.'];
        }
        $result = $this->requestRedmine($url.'?limit=1', $token, null, 5);
        $data = json_decode($result['body'], true);
        if ($result['error'] === '' && $result['http_code'] === 200 && is_array($data) && isset($data['issues']) && is_array($data['issues'])) {
            return ['ok' => true, 'error' => ''];
        }
        return ['ok' => false, 'error' => match ($result['http_code']) {
            401, 403 => 'Redmine rechazó el acceso. Revisa tu API Key personal y tus permisos.',
            429 => 'Redmine está limitando las solicitudes. Intenta más tarde.',
            default => 'Redmine no está disponible o no respondió en 5 segundos. No se enviaron reportes; intenta más tarde.',
        }];
    }

    public function shouldStopBatch(array $result): bool
    {
        $code = (int) ($result['http_code'] ?? 0);
        return !empty($result['error']) || $code === 0 || $code >= 500 || in_array($code, [401, 403, 429], true);
    }

    private function requestRedmine(string $url, string $token, ?array $payload, int $timeout): array
    {
        try {
            $request = Http::acceptJson()->asJson()->connectTimeout(3)->timeout($timeout)->withoutRedirecting();
            if ($token !== '') {
                $request = $request->withHeaders(['X-Redmine-API-Key' => $token]);
            }
            // Do not retry ticket creation: an unanswered POST may have created the issue.
            $response = $payload === null ? $request->get($url) : $request->post($url, $payload);
            return ['http_code' => $response->status(), 'body' => $response->body(), 'error' => ''];
        } catch (ConnectionException $exception) {
            return ['http_code' => 0, 'body' => '', 'error' => 'No se pudo conectar con Redmine o se agotó el tiempo de espera ('.$timeout.' segundos).'];
        }
    }

    private function parseDate(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception) {
            return '';
        }
    }
}
