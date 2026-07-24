<?php

namespace RedmineTic\Support;

use Illuminate\Support\Str;

/**
 * ETAPA B / Lote B6.3 — pure string/name utilities extracted verbatim from
 * RedmineDataRepository's private helper cluster. No DB, cache, session or
 * network access.
 */
final class TextSupport
{
    public static function normalizeTelegramReportText(string $text): string
    {
        $text = Str::ascii(Str::lower($text));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    public static function joinPersonName(string $first, string $last): string
    {
        $first = trim($first);
        $last = trim($last);
        if ($first === '') {
            return $last;
        }
        if ($last === '') {
            return $first;
        }

        $firstNormalized = self::normalizeTelegramReportText($first);
        $lastNormalized = self::normalizeTelegramReportText($last);
        if ($lastNormalized !== '' && str_contains($firstNormalized, $lastNormalized)) {
            return $first;
        }

        return trim($first . ' ' . $last);
    }

    /**
     * @param array<string,mixed> $telegramUser
     */
    public static function telegramUserDisplayName(array $telegramUser): string
    {
        $first = trim((string) ($telegramUser['name'] ?? ''));
        $last = trim((string) ($telegramUser['apellido'] ?? ''));
        $name = self::joinPersonName($first, $last);
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($telegramUser['username'] ?? ''));
    }

    public static function truncateLogValue(string $value, int $limit = 900): string
    {
        $value = trim($value);
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit) . '...';
    }

    public static function isClosedIssueStatus(string $statusName): bool
    {
        $statusKey = strtolower(trim($statusName));
        foreach (['cerrad', 'closed', 'resuelt', 'resolved', 'finaliz', 'complet', 'terminad'] as $needle) {
            if (str_contains($statusKey, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    public static function nameTokens(string $value): array
    {
        $normalized = strtolower(Str::ascii($value));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';
        $tokens = array_filter(explode(' ', $normalized), static fn (string $token): bool => strlen($token) >= 3);

        return array_values(array_unique($tokens));
    }

    public static function nameTokensMatch(string $left, string $right): bool
    {
        $leftTokens = self::nameTokens($left);
        $rightTokens = self::nameTokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $matches = 0;
        foreach ($leftTokens as $token) {
            if (in_array($token, $rightTokens, true)) {
                $matches++;
            }
        }

        return $matches >= min(2, count($leftTokens), count($rightTokens));
    }
}
