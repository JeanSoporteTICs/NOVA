<?php

namespace App\Modulos\Nova\Support;

use Illuminate\Support\Facades\Log;

/**
 * Central helper for reading/writing encrypted secrets stored across NOVA
 * (integraciones_usuario.valor_secreto and equivalents).
 *
 * Wraps Laravel's encrypt()/decrypt() and recognizes the legacy formats already
 * present in the codebase (plaintext, and the custom `enc:v1:` AES+HMAC codec
 * from RedmineMantencion/controllers/core_credentials.php) without duplicating
 * their logic. Not wired to any caller yet — see docs/ETAPA_A for the rollout.
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
     * enc:v1 is decoded exclusively through the existing legacy codec
     * (RedmineMantencion/controllers/core_credentials.php::core_credentials_decrypt())
     * when it happens to be loaded in the current request — this class never
     * reimplements that AES/HMAC logic.
     *
     * @return array{status:string,plaintext:?string}
     */
    private static function analyzeLegacyEncV1(string $value): array
    {
        if (!function_exists('core_credentials_decrypt')) {
            return ['status' => 'legacy_enc_v1', 'plaintext' => null];
        }

        $plain = core_credentials_decrypt($value);
        if ($plain === '') {
            Log::warning('SecretValue: no se pudo descifrar un valor legacy enc:v1 (posible corrupcion o clave distinta).');

            return ['status' => 'invalid', 'plaintext' => null];
        }

        return ['status' => 'legacy_enc_v1', 'plaintext' => $plain];
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
