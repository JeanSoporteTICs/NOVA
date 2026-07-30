<?php

namespace App\Modulos\RedmineMantencion\Services;

final class RedmineIssueStatusService
{
    /** @var array<int,string> */
    private const STATUS_OPTIONS = [
        1 => 'Nueva',
        2 => 'En curso',
        5 => 'Cerrada',
        6 => 'Rechazada',
    ];

    /** @return array<int,string> */
    public function statusOptions(): array
    {
        return self::STATUS_OPTIONS;
    }

    public function statusName(int $statusId): ?string
    {
        return self::STATUS_OPTIONS[$statusId] ?? null;
    }

    public function issueUrl(string $platformUrl, string $issueId): string
    {
        $issueId = trim($issueId);
        $platformUrl = trim($platformUrl);
        if ($issueId === '' || ! preg_match('/^\d+$/', $issueId) || $platformUrl === '') {
            return '';
        }

        $base = preg_replace('#/projects/[^/]+/issues(?:\.json)?(?:\?.*)?$#i', '', $platformUrl);
        if ($base === $platformUrl) {
            $base = preg_replace('#/issues(?:\.json)?(?:\?.*)?$#i', '', $platformUrl);
        }
        $base = rtrim((string) $base, '/');

        return $base !== '' ? $base.'/issues/'.rawurlencode($issueId) : '';
    }

    public function issueApiUrl(string $platformUrl, string $issueId): string
    {
        $url = $this->issueUrl($platformUrl, $issueId);

        return $url !== '' ? $url.'.json' : '';
    }

    public function isClosedStatus(string $statusName, ?bool $remoteClosed = null): bool
    {
        if ($remoteClosed !== null) {
            return $remoteClosed;
        }

        $normalized = $this->normalize($statusName);
        foreach (['cerrad', 'closed', 'resuelt', 'resolved', 'finaliz', 'complet', 'terminad', 'rechaz', 'reject'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id:int,name:string,closed:bool,available:bool,message:string}
     */
    public function fetchStatus(string $platformUrl, string $issueId, string $token): array
    {
        $empty = [
            'id' => 0,
            'name' => '',
            'closed' => false,
            'available' => false,
            'message' => 'Sin Redmine ID',
        ];
        $url = $this->issueApiUrl($platformUrl, $issueId);
        if ($url === '') {
            $empty['message'] = 'URL Redmine no configurada';

            return $empty;
        }
        if (! function_exists('curl_init')) {
            $empty['message'] = 'cURL no disponible';

            return $empty;
        }

        $response = $this->request($url, 'GET', $token, null, 5);
        if (! $response['ok']) {
            $empty['message'] = $response['error'];

            return $empty;
        }

        $status = is_array($response['payload']['issue']['status'] ?? null)
            ? $response['payload']['issue']['status']
            : [];
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
            'closed' => $this->isClosedStatus($statusName, $remoteClosed),
            'available' => true,
            'message' => '',
        ];
    }

    /**
     * @return array{ok:bool,error:string,http_code:int}
     */
    public function updateStatus(string $platformUrl, string $issueId, int $statusId, string $token): array
    {
        if ($this->statusName($statusId) === null) {
            return ['ok' => false, 'error' => 'Estado Redmine no permitido.', 'http_code' => 0];
        }
        if (trim($token) === '') {
            return ['ok' => false, 'error' => 'Configura tu API Key personal de Redmine.', 'http_code' => 0];
        }

        $url = $this->issueApiUrl($platformUrl, $issueId);
        if ($url === '') {
            return ['ok' => false, 'error' => 'URL Redmine no configurada.', 'http_code' => 0];
        }

        $response = $this->request($url, 'PUT', $token, ['issue' => ['status_id' => $statusId]], 15);

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
        ?array $payload = null,
        int $timeout = 15
    ): array {
        if (! function_exists('curl_init')) {
            return ['ok' => false, 'payload' => [], 'error' => 'cURL no disponible.', 'http_code' => 0];
        }

        $headers = ['Accept: application/json'];
        if (trim($token) !== '') {
            $headers[] = 'X-Redmine-API-Key: '.trim($token);
        }
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
