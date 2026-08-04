<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\RedmineMantencion\Repositories\MantencionCatalogRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;

final class MantencionRedmineIssueService
{
    public function __construct(
        private readonly MantencionConfigRepository $config,
        private readonly MantencionCatalogRepository $catalogs,
    ) {}

    /** @param array<string,mixed> $message @return array{ok:bool,ticket_id:string,error:string,http_code:int,payload:array<string,mixed>} */
    public function send(array $message, string $personalToken): array
    {
        if (trim($personalToken) === '') {
            return $this->failure('Configura tu API Key personal de Redmine en Cuentas conectadas.', 0);
        }
        if ($this->isCoreInReview($message)) {
            return $this->failure('La solicitud permanece En Revisión en CORE.', 0);
        }

        $config = $this->config->loadAll() ?? [];
        $url = $this->issuesUrl((string) ($config['platform_url'] ?? $config['redmine_url'] ?? ''));
        if ($url === '') {
            return $this->failure('La URL de Redmine no está configurada.', 0);
        }
        if (! function_exists('curl_init')) {
            return $this->failure('La extensión cURL no está disponible.', 0);
        }

        $issue = $this->payload($message, $config);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Redmine-API-Key: '.trim($personalToken),
            ],
            CURLOPT_POSTFIELDS => json_encode(['issue' => $issue], JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = trim((string) curl_error($ch));
        curl_close($ch);
        $decoded = json_decode((string) $body, true);
        $ticketId = trim((string) ($decoded['issue']['id'] ?? ''));

        if ($httpCode === 201 && ctype_digit($ticketId)) {
            return ['ok' => true, 'ticket_id' => $ticketId, 'error' => '', 'http_code' => $httpCode, 'payload' => $issue];
        }

        $remoteErrors = array_filter(array_map('strval', (array) ($decoded['errors'] ?? [])));
        $error = $curlError !== '' ? $curlError : ($remoteErrors !== [] ? implode(' ', $remoteErrors) : 'Redmine respondió HTTP '.$httpCode.'.');

        return ['ok' => false, 'ticket_id' => '', 'error' => $error, 'http_code' => $httpCode, 'payload' => $issue];
    }

    /** @param array<string,mixed> $message */
    public function isCoreInReview(array $message): bool
    {
        $isCore = strtolower(trim((string) ($message['fuente'] ?? ''))) === 'core'
            || trim((string) ($message['id_core'] ?? '')) !== '';

        return $isCore && $this->normalize((string) ($message['core_estado'] ?? $message['estado_redmine'] ?? '')) === 'en revision';
    }

    /** @param array<string,mixed> $message @param array<string,mixed> $config @return array<string,mixed> */
    public function payload(array $message, array $config): array
    {
        $isManual = strtolower(trim((string) ($message['fuente'] ?? ''))) === 'manual';
        $subject = trim((string) ($message['asunto'] ?? $message['mensaje'] ?? ''));
        if (! $isManual) {
            $parts = array_filter([
                trim((string) ($message['tipo'] ?? $message['core_tipo_solicitud'] ?? '')),
                trim((string) ($message['unidad_solicitante'] ?? '')),
                trim((string) ($message['unidad'] ?? '')),
            ], static fn (string $value): bool => $value !== '' && strtoupper($value) !== 'N/A');
            $subject = implode(' / ', array_unique($parts)) ?: $subject;
        }

        $issue = [
            'project_id' => (int) ($message['project_id'] ?? $config['project_id'] ?? 48),
            'subject' => $subject,
            'description' => trim((string) ($message['descripcion'] ?? '')),
            'tracker_id' => (int) ($message['tipo_id'] ?? $message['tracker_id'] ?? $config['tracker_id'] ?? ($isManual ? 3 : 1)),
            'priority_id' => (int) ($message['priority_id'] ?? $config['priority_id'] ?? 2),
            'status_id' => (int) ($message['status_id'] ?? $config['status_id'] ?? 1),
        ];
        foreach (['fecha_inicio' => 'start_date', 'fecha_fin' => 'due_date'] as $source => $target) {
            $date = $this->date((string) ($message[$source] ?? $message['fecha'] ?? ''));
            if ($date !== '') {
                $issue[$target] = $date;
            }
        }
        if (is_numeric($message['tiempo_estimado'] ?? null)) {
            $issue['estimated_hours'] = (float) $message['tiempo_estimado'];
        }
        if (trim((string) ($message['asignado_a'] ?? '')) !== '') {
            $issue['assigned_to_id'] = (int) $message['asignado_a'];
        }

        $categoryId = $this->catalogs->categoriaIdPorNombre((string) ($message['categoria'] ?? ''));
        if ($categoryId !== null) {
            $issue['category_id'] = $categoryId;
        }

        $customFields = [];
        $customValues = [
            'cf_solicitante' => trim((string) ($message['solicitante'] ?? '')),
            'cf_unidad' => trim((string) ($message['unidad'] ?? '')),
            'cf_unidad_solicitante' => trim((string) ($message['unidad_solicitante'] ?? $message['unidad'] ?? '')),
            'cf_hora_extra' => $this->truthy($message['hora_extra'] ?? '') ? '1' : '0',
        ];
        $defaults = ['cf_solicitante' => 3, 'cf_unidad' => 5, 'cf_hora_extra' => 12];
        foreach ($customValues as $key => $value) {
            $id = (int) ($config[$key] ?? $defaults[$key] ?? 0);
            if ($id > 0 && $value !== '') {
                $customFields[] = ['id' => $id, 'value' => $value];
            }
        }
        if (trim((string) ($message['anexo'] ?? '')) !== '') {
            $customFields[] = ['id' => 4, 'value' => trim((string) $message['anexo'])];
        }
        if (filter_var(trim((string) ($message['correo'] ?? $message['core_email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
            $customFields[] = ['id' => 8, 'value' => trim((string) ($message['correo'] ?? $message['core_email']))];
        }
        if ($customFields !== []) {
            $issue['custom_fields'] = $customFields;
        }

        return array_filter($issue, static fn (mixed $value): bool => ! in_array($value, ['', null, 0], true));
    }

    private function issuesUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#/issues(?:\.json)?(?:\?.*)?$#i', $url)) {
            return preg_replace('#/issues(?:\.json)?(?:\?.*)?$#i', '/issues.json', $url) ?? '';
        }

        return rtrim($url, '/').'/issues.json';
    }

    private function date(string $value): string
    {
        try {
            return trim($value) !== '' ? (new \DateTimeImmutable($value))->format('Y-m-d') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function truthy(mixed $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'si', 'sí', 's', 'true', 'yes'], true);
    }

    private function normalize(string $value): string
    {
        $lower = strtr(mb_strtolower(trim($value), 'UTF-8'), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        ]);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', is_string($ascii) ? $ascii : $lower) ?? '');
    }

    /** @return array{ok:false,ticket_id:string,error:string,http_code:int,payload:array<string,mixed>} */
    private function failure(string $error, int $httpCode): array
    {
        return ['ok' => false, 'ticket_id' => '', 'error' => $error, 'http_code' => $httpCode, 'payload' => []];
    }
}
