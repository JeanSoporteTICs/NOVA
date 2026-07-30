<?php

namespace RedmineTic\Services;

use RedmineTic\Support\TextSupport;

final class RedmineIssueStatusService
{
    /**
     * @param  array<int,mixed>  $items
     * @return array<int,array{id:int,name:string}>
     */
    public function options(array $items): array
    {
        $options = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string) ($item['nombre'] ?? $item['name'] ?? ''));
            if ($id === false || $id <= 0 || $name === '') {
                continue;
            }

            $options[$id] = ['id' => $id, 'name' => $name];
        }

        return array_values($options);
    }

    /**
     * @param  array<int,array{id:int,name:string}>  $options
     */
    public function statusName(array $options, int $statusId): ?string
    {
        foreach ($options as $option) {
            if ($option['id'] === $statusId) {
                return $option['name'];
            }
        }

        return null;
    }

    /**
     * @param  array{id?:int,name?:string}  $current
     */
    public function isCurrentStatus(array $current, int $statusId, string $statusName): bool
    {
        if ((int) ($current['id'] ?? 0) === $statusId) {
            return true;
        }

        return $this->normalize((string) ($current['name'] ?? '')) === $this->normalize($statusName);
    }

    /**
     * @return array{id:int,name:string,closed:bool,available:bool,message:string}
     */
    public function fetch(string $issueUrl, string $token): array
    {
        $empty = [
            'id' => 0,
            'name' => '',
            'closed' => false,
            'available' => false,
            'message' => '',
        ];

        if (trim($issueUrl) === '') {
            $empty['message'] = 'URL Redmine no configurada';

            return $empty;
        }
        if (trim($token) === '') {
            $empty['message'] = 'Token API no configurado';

            return $empty;
        }

        $response = $this->request(rtrim($issueUrl, '/').'.json', 'GET', $token, null, 5);
        if (! $response['ok']) {
            $empty['message'] = $response['error'];

            return $empty;
        }

        $status = data_get($response['payload'], 'issue.status', []);
        $status = is_array($status) ? $status : [];
        $statusName = trim((string) ($status['name'] ?? ''));
        if ($statusName === '') {
            $empty['message'] = 'Estado no informado por Redmine';

            return $empty;
        }
        $remoteClosed = array_key_exists('is_closed', $status)
            ? filter_var($status['is_closed'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;

        return [
            'id' => (int) ($status['id'] ?? 0),
            'name' => $statusName,
            'closed' => $remoteClosed ?? TextSupport::isClosedIssueStatus($statusName),
            'available' => true,
            'message' => '',
        ];
    }

    /**
     * @return array{ok:bool,error:string,http_code:int}
     */
    public function update(string $issueUrl, int $statusId, string $token): array
    {
        if (trim($issueUrl) === '' || $statusId <= 0) {
            return ['ok' => false, 'error' => 'Ticket o estado Redmine no válido.', 'http_code' => 0];
        }
        if (trim($token) === '') {
            return ['ok' => false, 'error' => 'Configura tu API Key personal de Redmine.', 'http_code' => 0];
        }

        $response = $this->request(
            rtrim($issueUrl, '/').'.json',
            'PUT',
            $token,
            ['issue' => ['status_id' => $statusId]],
            15
        );

        return [
            'ok' => $response['ok'],
            'error' => $response['error'],
            'http_code' => $response['http_code'],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array{ok:bool,payload:array<string,mixed>,error:string,http_code:int}
     */
    private function request(
        string $url,
        string $method,
        string $token,
        ?array $payload,
        int $timeout
    ): array {
        if (! function_exists('curl_init')) {
            return ['ok' => false, 'payload' => [], 'error' => 'Extensión cURL no disponible.', 'http_code' => 0];
        }

        $headers = [
            'Accept: application/json',
            'X-Redmine-API-Key: '.trim($token),
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(4, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ];
        if ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = trim((string) curl_error($ch));
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $ok = $body !== false && $curlError === '' && $httpCode >= 200 && $httpCode < 300;
        if ($ok) {
            return ['ok' => true, 'payload' => $decoded, 'error' => '', 'http_code' => $httpCode];
        }

        $errors = array_values(array_filter(array_map(
            static fn ($error): string => trim((string) $error),
            (array) ($decoded['errors'] ?? [])
        )));
        $error = $curlError !== ''
            ? $curlError
            : ($errors !== [] ? implode(' ', $errors) : 'Redmine respondió HTTP '.$httpCode.'.');

        return ['ok' => false, 'payload' => $decoded, 'error' => $error, 'http_code' => $httpCode];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return preg_replace('/[^a-z0-9]+/', ' ', $ascii !== false ? $ascii : $value) ?? '';
    }
}
