<?php

namespace RedmineTic\Support;

/**
 * ETAPA B / Lote B6.3 — pure Redmine URL-building utilities extracted
 * verbatim from RedmineDataRepository's private helper cluster. Only string
 * parsing/concatenation — no HTTP call is made here (that stays with
 * getRedmineJson()/fetchRedmineIssues() in the facade and in
 * RedmineMembershipSyncService/RedmineIssueSenderService).
 */
final class RedmineUrlSupport
{
    public static function redmineBaseUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $prefix = $path;
        foreach (['/projects/', '/issues'] as $marker) {
            $markerPos = strpos($path, $marker);
            if ($markerPos !== false) {
                $prefix = substr($path, 0, $markerPos);
                break;
            }
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return rtrim($parts['scheme'] . '://' . $parts['host'] . $port . $prefix, '/');
    }

    public static function redmineCategoriesUrl(string $url): string
    {
        $baseUrl = self::redmineBaseUrl($url);

        return $baseUrl === '' ? '' : $baseUrl . '/issue_categories.json';
    }

    public static function redmineCustomFieldUrl(string $url, string $fieldId): string
    {
        $baseUrl = self::redmineBaseUrl($url);

        return $baseUrl === '' ? '' : $baseUrl . '/custom_fields/' . rawurlencode($fieldId) . '.json';
    }

    public static function redmineIssuesUrl(string $url): string
    {
        $baseUrl = self::redmineBaseUrl($url);

        return $baseUrl === '' ? '' : $baseUrl . '/issues.json';
    }

    public static function redmineIssueUrl(string $url, string $issueId): string
    {
        $issueId = preg_replace('/\D+/', '', trim($issueId)) ?? '';
        $baseUrl = self::redmineBaseUrl($url);

        return $baseUrl === '' || $issueId === ''
            ? ''
            : $baseUrl . '/issues/' . rawurlencode($issueId);
    }
}
