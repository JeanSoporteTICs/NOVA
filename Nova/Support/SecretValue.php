<?php

namespace App\Modulos\Nova\Support;

use Illuminate\Support\Facades\Log;

/**
 * Central helper for reading/writing encrypted secrets stored across NOVA
 * (integraciones_usuario.valor_secreto and equivalents).
 *
 * Wraps Laravel's encrypt()/decrypt() and recognizes historical plaintext and
 * `enc:v1:` AES+HMAC values so migrations never need to load procedural code.
 */
final class SecretValue
{
    /**
     * Encrypts a secret using Laravel's encrypter. An empty string is
     * returned unchanged (matches every existing call site's convention of
     * never encrypting an absent secret).
     */
    public static function encryptSecret(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return encrypt($value);
    }

    /**
     * Decrypts a secret regardless of which format it was stored in.
     * Returns null when there is nothing to decrypt or the value cannot be
     * recovered (corrupted/tampered ciphertext, or a recognized-but-currently
     * undecodable legacy format) — never throws, never leaks the input.
     */
    public static function decryptSecret(?string $value): ?string
    {
        return self::analyze($value)['plaintext'];
    }

    /**
     * Returns metadata about a stored value without ever exposing it.
     *
     * @return array{status:string,decryptable:bool,needs_rewrite:bool}
     */
    public static function inspect(?string $value): array
    {
        $result = self::analyze($value);
        $status = $result['status'];

        return [
            'status' => $status,
            'decryptable' => $result['plaintext'] !== null,
            'needs_rewrite' => in_array($status, ['legacy_enc_v1', 'plaintext_legacy'], true),
        ];
    }

    /**
     * @return array{status:string,plaintext:?string}
     */
    private static function analyze(?string $value): array
    {
        if ($value === null || $value === '') {
            return ['status' => 'empty', 'plaintext' => null];
        }

        if (str_starts_with($value, 'enc:v1:')) {
            return self::analyzeLegacyEncV1($value);
        }

        if (self::looksLikeLaravelEncryptedShape($value)) {
            try {
                return ['status' => 'laravel_encrypted', 'plaintext' => (string) decrypt($value)];
            } catch (\Throwable) {
                Log::warning('SecretValue: no se pudo descifrar un valor con formato Laravel encrypt() reconocido (posible corrupcion o cambio de APP_KEY).');

                return ['status' => 'invalid', 'plaintext' => null];
            }
        }

        return ['status' => 'plaintext_legacy', 'plaintext' => $value];
    }

    /**
     * @return array{status:string,plaintext:?string}
     */
    private static function analyzeLegacyEncV1(string $value): array
    {
        if (! function_exists('openssl_decrypt')) {
            return ['status' => 'invalid', 'plaintext' => null];
        }
        $parts = explode(':', trim($value), 5);
        if (count($parts) !== 5) {
            return ['status' => 'invalid', 'plaintext' => null];
        }
        $iv = base64_decode($parts[2], true);
        $cipher = base64_decode($parts[3], true);
        $mac = base64_decode($parts[4], true);
        $keySource = trim((string) (getenv('CORE_CREDENTIAL_KEY') ?: getenv('APP_KEY') ?: config('app.key', '')));
        if ($iv === false || $cipher === false || $mac === false || $keySource === '') {
            return ['status' => 'invalid', 'plaintext' => null];
        }
        $key = hash('sha256', $keySource, true);
        $expected = hash_hmac('sha256', $iv.$cipher, $key, true);
        if (! hash_equals($expected, $mac)) {
            Log::warning('SecretValue: no se pudo descifrar un valor legacy enc:v1 (posible corrupcion o clave distinta).');

            return ['status' => 'invalid', 'plaintext' => null];
        }
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false || trim($plain) === '') {
            return ['status' => 'invalid', 'plaintext' => null];
        }

        return ['status' => 'legacy_enc_v1', 'plaintext' => trim($plain)];
    }

    private static function looksLikeLaravelEncryptedShape(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && array_key_exists('iv', $payload)
            && array_key_exists('value', $payload)
            && array_key_exists('mac', $payload);
    }
}
