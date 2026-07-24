<?php

namespace RedmineTic\Services;

/**
 * ETAPA B / Lote B6.2 — HTTP transport for Redmine project membership
 * synchronization, extracted verbatim from
 * RedmineDataRepository::fetchRedmineMemberships()'s pagination loop and
 * ::redmineUserName()'s JSON/HTML fallback name resolution.
 *
 * Owns transport only: fetching membership pages and resolving a remote
 * user's display name (including the HTML-scrape fallback Redmine's API
 * sometimes requires). It does NOT own:
 *  - config/token/base-URL resolution (stays in the facade — depends on
 *    configuration()/userApiToken()/redmineBaseUrl(), shared with other
 *    Redmine sync flows);
 *  - the JSON GET itself (getRedmineJson() stays in the facade — shared
 *    with syncCategoriesFromRedmine()/syncUnitsFromRedmine()/
 *    fetchRedmineIssues()/issueStatuses() — passed in here as a callback,
 *    same pattern as RedmineIssueSenderService's category resolver);
 *  - classification/merge logic (previewUsersFromRedmine()/
 *    syncUsersFromRedmine() keep comparing remote memberships against local
 *    users — that is the "sincronización" layer, distinct from transport);
 *  - persistence (RedmineUserRepository, already separate since B3).
 *
 * No generic "RedmineClient" was introduced: grepping the module found no
 * existing reusable Redmine HTTP client to build on, and this service's
 * scope stays narrow (membership sync only), matching the same shape as
 * RedmineIssueSenderService from B5.4 rather than a general-purpose client.
 */
final class RedmineMembershipSyncService
{
    /**
     * @param callable $httpGetJson fn(string $url, string $token): array{http_code:int,body:string,error:string}
     * @return array{ok:bool,memberships:array<int,mixed>,error:string}
     */
    public function fetchMemberships(string $baseUrl, string $token, string $projectId, callable $httpGetJson): array
    {
        $memberships = [];
        $offset = 0;
        $limit = 100;
        $total = 0;
        do {
            $url = $baseUrl . '/projects/' . rawurlencode($projectId) . '/memberships.json?limit=' . $limit . '&offset=' . $offset;
            $response = $httpGetJson($url, $token);
            if ($response['error'] !== '') {
                return ['ok' => false, 'memberships' => [], 'error' => $response['error']];
            }
            if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
                return ['ok' => false, 'memberships' => [], 'error' => 'HTTP ' . $response['http_code'] . ' - ' . $response['body']];
            }

            $data = json_decode($response['body'], true);
            $page = is_array($data['memberships'] ?? null) ? $data['memberships'] : [];
            $memberships = array_merge($memberships, $page);
            $total = (int) ($data['total_count'] ?? count($memberships));
            $offset += $limit;
        } while ($offset < $total);

        return ['ok' => true, 'memberships' => $memberships, 'error' => ''];
    }

    /**
     * @param array<string,mixed> $redmineUser
     * @param callable $httpGetJson fn(string $url, string $token): array{http_code:int,body:string,error:string}
     * @return array{0:string,1:string}
     */
    public function resolveUserName(array $redmineUser, string $baseUrl, string $token, callable $httpGetJson): array
    {
        $firstName = trim((string) ($redmineUser['firstname'] ?? $redmineUser['first_name'] ?? ''));
        $lastName = trim((string) ($redmineUser['lastname'] ?? $redmineUser['last_name'] ?? ''));

        if ($firstName !== '' && $lastName !== '') {
            return [$firstName, $lastName];
        }

        $id = trim((string) ($redmineUser['id'] ?? ''));
        if ($id !== '' && $baseUrl !== '' && $token !== '') {
            $response = $httpGetJson($baseUrl . '/users/' . rawurlencode($id) . '.json', $token);
            if ($response['error'] === '' && $response['http_code'] >= 200 && $response['http_code'] < 300) {
                $data = json_decode($response['body'], true);
                $detail = is_array($data['user'] ?? null) ? $data['user'] : [];
                $detailFirstName = trim((string) ($detail['firstname'] ?? $detail['first_name'] ?? ''));
                $detailLastName = trim((string) ($detail['lastname'] ?? $detail['last_name'] ?? ''));

                if ($detailFirstName !== '') {
                    $firstName = $detailFirstName;
                }
                if ($detailLastName !== '') {
                    $lastName = $detailLastName;
                }
            }

            if ($firstName === '' || $lastName === '') {
                $response = $this->getRedmineHtml($baseUrl . '/users/' . rawurlencode($id) . '/edit', $token);
                if ($response['error'] === '' && $response['http_code'] >= 200 && $response['http_code'] < 300) {
                    $htmlFirstName = $this->htmlInputValue($response['body'], 'user[firstname]');
                    $htmlLastName = $this->htmlInputValue($response['body'], 'user[lastname]');

                    if ($htmlFirstName !== '') {
                        $firstName = $htmlFirstName;
                    }
                    if ($htmlLastName !== '') {
                        $lastName = $htmlLastName;
                    }
                }
            }
        }

        if ($firstName !== '' && $lastName !== '') {
            return [$firstName, $lastName];
        }

        [$splitFirstName, $splitLastName] = $this->splitRedmineName((string) ($redmineUser['name'] ?? ''));

        return [
            $firstName !== '' ? $firstName : $splitFirstName,
            $lastName !== '' ? $lastName : $splitLastName,
        ];
    }

    /**
     * @return array{http_code:int,body:string,error:string}
     */
    private function getRedmineHtml(string $url, string $token): array
    {
        if (!function_exists('curl_init')) {
            return ['http_code' => 0, 'body' => '', 'error' => 'Extension cURL no disponible'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'X-Redmine-API-Key: ' . $token],
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        return ['http_code' => $httpCode, 'body' => (string) $body, 'error' => $error];
    }

    private function htmlInputValue(string $html, string $name): string
    {
        if ($html === '' || $name === '') {
            return '';
        }

        if (!preg_match_all('/<input\b[^>]*>/i', $html, $matches)) {
            return '';
        }

        foreach ($matches[0] as $tag) {
            if ($this->htmlAttrValue($tag, 'name') !== $name) {
                continue;
            }

            return html_entity_decode($this->htmlAttrValue($tag, 'value'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    private function htmlAttrValue(string $tag, string $attribute): string
    {
        $quoted = '/\b' . preg_quote($attribute, '/') . '\s*=\s*([\'"])(.*?)\1/i';
        if (preg_match($quoted, $tag, $match)) {
            return (string) $match[2];
        }

        $plain = '/\b' . preg_quote($attribute, '/') . '\s*=\s*([^\s>]+)/i';
        if (preg_match($plain, $tag, $match)) {
            return trim((string) $match[1], '"\'');
        }

        return '';
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitRedmineName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return ['', ''];
        }
        $parts = explode(' ', $name, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
