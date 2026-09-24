<?php

namespace App\Repositories\Database;

/** SQL expressions for the existing PHP byte-oriented identity/trim rules. */
final class SqlText
{
    public static function identity(string $expression): string
    {
        return "COALESCE(LOWER(REGEXP_REPLACE($expression COLLATE utf8mb4_bin, '(?-i)[^0-9a-zA-Z]', '')), '') COLLATE utf8mb4_bin";
    }

    public static function trim(string $expression): string
    {
        // PHP trim(): space, TAB, LF, CR, NUL, VT. Not every Unicode space.
        return "REGEXP_REPLACE(COALESCE($expression, ''), '^[\\\\x00\\\\x09\\\\x0a\\\\x0b\\\\x0d ]+|[\\\\x00\\\\x09\\\\x0a\\\\x0b\\\\x0d ]+$', '')";
    }

    public static function asciiLower(string $expression): string
    {
        foreach (range('A', 'Z') as $letter) {
            $expression = "REPLACE($expression, '$letter', '".strtolower($letter)."')";
        }

        return $expression;
    }
}
