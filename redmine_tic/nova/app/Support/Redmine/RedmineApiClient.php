<?php

namespace RedmineTic\Support\Redmine;

/**
 * Thin HTTP client for all cURL calls to the Redmine REST API.
 * No DB queries, no state, no side effects.
 */
class RedmineApiClient
{
    /** @return array{http_code:int,body:string,error:string} */
    public function getJson(string $url, string $token): array
    {
        if (!function_exists('curl_init')) {
            return ['http_code' => 0, 'body' => '', 'error' => 'Extension cURL no disponible'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'X-Redmine-API-Key: ' . $token],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = (string) curl_error($ch);
        curl_close($ch);

        return ['http_code' => $httpCode, 'body' => (string) $body, 'error' => $error];
    }

    /** @return array{http_code:int,body:string,error:string} */
    public function getHtml(string $url, string $token): array
    {
        if (!function_exists('curl_init')) {
            return ['http_code' => 0, 'body' => '', 'error' => 'Extension cURL no disponible'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: text/html', 'X-Redmine-API-Key: ' . $token],
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = (string) curl_error($ch);
        curl_close($ch);

        return ['http_code' => $httpCode, 'body' => (string) $body, 'error' => $error];
    }

    /** @return array{http_code:int,body:string,error:string} */
    public function postIssue(array $config, array $payload, string $token): array
    {
        $url = trim((string) ($config['platform_url'] ?? ''));
        if ($url === '') {
            return ['http_code' => 0, 'body' => '', 'error' => 'URL no configurada'];
        }
        if (!function_exists('curl_init')) {
            return ['http_code' => 0, 'body' => '', 'error' => 'Extension cURL no disponible'];
        }

        $ch      = curl_init($url);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'X-Redmine-API-Key: ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = (string) curl_error($ch);
        curl_close($ch);

        return ['http_code' => $httpCode, 'body' => (string) $body, 'error' => $error];
    }

    public function baseUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $path        = (string) ($parts['path'] ?? '');
        $prefix      = '';
        $projectsPos = strpos($path, '/projects/');
        if ($projectsPos !== false) {
            $prefix = substr($path, 0, $projectsPos);
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return rtrim($parts['scheme'] . '://' . $parts['host'] . $port . $prefix, '/');
    }

    public function categoriesUrl(string $url): string
    {
        $base = $this->baseUrl($url);

        return $base === '' ? '' : $base . '/issue_categories.json';
    }

    public function customFieldUrl(string $url, string $fieldId): string
    {
        $base = $this->baseUrl($url);

        return $base === '' ? '' : $base . '/custom_fields/' . rawurlencode($fieldId) . '.json';
    }

    public function issuesUrl(string $projectUrl): string
    {
        $base = $this->baseUrl($projectUrl);

        return $base === '' ? '' : $base . '/issues.json';
    }
}
