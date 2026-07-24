<?php

namespace App\Modulos\Procedimientos\Services;

final class OnlyOfficeJwt
{
    public function encode(array $payload, string $secret): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = $this->base64Url(hash_hmac('sha256', $header . '.' . $body, $secret, true));

        return $header . '.' . $body . '.' . $signature;
    }

    public function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3 || $secret === '') {
            return null;
        }
        $expected = $this->base64Url(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true));
        if (!hash_equals($expected, $parts[2])) {
            return null;
        }
        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);

        return is_array($payload) ? $payload : null;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
