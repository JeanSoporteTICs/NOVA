<?php

namespace RedmineTic\Support;

/** The historical screen's normalization, including uppercase accented letters. */
final class HistoryFilter
{
    public static function date(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    public static function text(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return is_string($converted) ? $converted : $value;
    }
}
