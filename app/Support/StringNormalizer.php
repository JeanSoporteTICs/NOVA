<?php

namespace App\Support;

final class StringNormalizer
{
    public static function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9a-z]/i', '', $value));
    }
}
