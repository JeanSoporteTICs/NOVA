<?php

namespace App\Support\Nova;

final class NovaValidation
{
    public static function normalizeRutUser(string $rut): string
    {
        $rut = strtoupper(trim($rut));
        $rut = str_replace(['.', ' '], '', $rut);
        if (str_contains($rut, '-')) {
            [$body] = array_pad(explode('-', $rut, 2), 2, '');
            return preg_replace('/\D+/', '', $body) ?: '';
        }
        if (preg_match('/^\d{7,8}[0-9K]$/', $rut) && strlen($rut) >= 9) {
            return substr($rut, 0, -1);
        }

        return preg_replace('/\D+/', '', $rut) ?: '';
    }

    public static function validEmail(string $email): bool
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }
}
