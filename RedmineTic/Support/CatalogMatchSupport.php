<?php

namespace RedmineTic\Support;

/**
 * ETAPA B / Lote B6.3 — pure fuzzy catalog-matching utilities extracted
 * verbatim from RedmineDataRepository's private helper cluster (used to
 * infer a category/unit from free-form Telegram report text). No DB, cache,
 * session or network access.
 */
final class CatalogMatchSupport
{
    /**
     * @param string[] $items
     */
    public static function inferCatalogMatch(string $text, array $items): string
    {
        $target = TextSupport::normalizeTelegramReportText($text);
        if ($target === '') {
            return '';
        }

        $targetTokens = self::catalogMatchTokens($target);
        $hints = self::catalogMatchHints($target);
        $bestItem = '';
        $bestScore = 0;

        foreach ($items as $item) {
            $item = trim($item);
            $normalized = TextSupport::normalizeTelegramReportText($item);
            if ($normalized === '') {
                continue;
            }
            if ($normalized === $target || preg_match('/\b' . preg_quote($normalized, '/') . '\b/u', $target)) {
                return $item;
            }
            if (strlen($normalized) >= 4 && (str_contains($target, $normalized) || str_contains($normalized, $target))) {
                return $item;
            }

            $score = self::catalogMatchScore($targetTokens, $hints, self::catalogMatchTokens($normalized), $normalized);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestItem = $item;
            }
        }

        return $bestScore >= 22 ? $bestItem : '';
    }

    /**
     * @return array<int,string>
     */
    public static function catalogMatchTokens(string $text): array
    {
        $stopWords = array_fill_keys(['de', 'del', 'la', 'las', 'los', 'el', 'en', 'para', 'por', 'con', 'sin', 'un', 'una', 'y', 'o', 'no', 'se', 'a'], true);
        $tokens = [];
        foreach (explode(' ', TextSupport::normalizeTelegramReportText($text)) as $token) {
            if (strlen($token) < 3 || isset($stopWords[$token])) {
                continue;
            }
            $tokens[] = self::catalogTokenStem($token);
        }

        return array_values(array_unique($tokens));
    }

    public static function catalogTokenStem(string $token): string
    {
        foreach (['ciones', 'cion', 'oras', 'ores', 'icos', 'icas', 'ados', 'adas', 'es', 's'] as $suffix) {
            if (strlen($token) > strlen($suffix) + 3 && str_ends_with($token, $suffix)) {
                return substr($token, 0, -strlen($suffix));
            }
        }

        return $token;
    }

    /**
     * @return array<int,string>
     */
    public static function catalogMatchHints(string $target): array
    {
        $rules = [
            'impresora' => ['impresora', 'impresion', 'printer', 'toner'],
            'problem' => ['problema', 'problemas', 'no funciona', 'no imprime'],
            'falla' => ['falla', 'fallando', 'lento', 'lenta', 'malo', 'mala'],
            'anexo' => ['anexo', 'telefono', 'telefonico'],
            'telefono' => ['telefono', 'telefonico', 'celular', 'fono'],
            'correo' => ['correo', 'email', 'mail', 'outlook'],
            'cuenta' => ['cuenta', 'usuario', 'clave', 'password', 'contrasena'],
            'pc' => ['pc', 'equipo', 'computador', 'notebook'],
            'red' => ['red', 'internet', 'wifi', 'conexion', 'vlan', 'vpn'],
            'camara' => ['camara', 'webcam'],
            'recuper' => ['recuperacion', 'recuperar', 'recupero'],
        ];

        $hints = [];
        foreach ($rules as $hint => $needles) {
            foreach ($needles as $needle) {
                if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $target)) {
                    $hints[] = $hint;
                    break;
                }
            }
        }

        return array_values(array_unique($hints));
    }

    /**
     * @param array<int,string> $targetTokens
     * @param array<int,string> $hints
     * @param array<int,string> $itemTokens
     */
    public static function catalogMatchScore(array $targetTokens, array $hints, array $itemTokens, string $normalizedItem): int
    {
        if ($targetTokens === [] || $itemTokens === []) {
            return 0;
        }

        $score = 0;
        foreach ($targetTokens as $targetToken) {
            foreach ($itemTokens as $itemToken) {
                if ($targetToken === $itemToken) {
                    $score += 18;
                    continue;
                }
                if (strlen($targetToken) >= 4 && strlen($itemToken) >= 4 && (str_starts_with($targetToken, $itemToken) || str_starts_with($itemToken, $targetToken))) {
                    $score += 12;
                }
            }
        }

        foreach ($hints as $hint) {
            if (str_contains($normalizedItem, $hint)) {
                $score += 22;
            }
        }

        if (count($itemTokens) > 4) {
            $score -= min(12, count($itemTokens) - 4);
        }

        return $score;
    }
}
