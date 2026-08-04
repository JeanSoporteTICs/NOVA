<?php

namespace Tests\Unit;

use App\Modulos\Nova\Support\SecretValue;
use Tests\TestCase;

class SecretValueTest extends TestCase
{
    public function test_encrypts_and_decrypts_a_normal_value(): void
    {
        $encrypted = SecretValue::encryptSecret('hunter2');

        $this->assertNotSame('hunter2', $encrypted);
        $this->assertSame('hunter2', SecretValue::decryptSecret($encrypted));

        $inspection = SecretValue::inspect($encrypted);
        $this->assertSame('laravel_encrypted', $inspection['status']);
        $this->assertTrue($inspection['decryptable']);
        $this->assertFalse($inspection['needs_rewrite']);
    }

    public function test_decrypt_null_returns_null(): void
    {
        $this->assertNull(SecretValue::decryptSecret(null));

        $inspection = SecretValue::inspect(null);
        $this->assertSame('empty', $inspection['status']);
        $this->assertFalse($inspection['decryptable']);
        $this->assertFalse($inspection['needs_rewrite']);
    }

    public function test_encrypt_and_decrypt_empty_string(): void
    {
        $this->assertSame('', SecretValue::encryptSecret(''));
        $this->assertNull(SecretValue::decryptSecret(''));

        $inspection = SecretValue::inspect('');
        $this->assertSame('empty', $inspection['status']);
    }

    public function test_plaintext_legacy_value_is_returned_as_is(): void
    {
        $this->assertSame('old-plain-password', SecretValue::decryptSecret('old-plain-password'));

        $inspection = SecretValue::inspect('old-plain-password');
        $this->assertSame('plaintext_legacy', $inspection['status']);
        $this->assertTrue($inspection['decryptable']);
        $this->assertTrue($inspection['needs_rewrite']);
    }

    public function test_corrupted_laravel_shaped_payload_is_invalid(): void
    {
        $fakePayload = base64_encode(json_encode([
            'iv' => base64_encode(str_repeat('a', 16)),
            'value' => base64_encode('not-a-real-ciphertext'),
            'mac' => hash('sha256', 'wrong-mac'),
        ]));

        $this->assertNull(SecretValue::decryptSecret($fakePayload));

        $inspection = SecretValue::inspect($fakePayload);
        $this->assertSame('invalid', $inspection['status']);
        $this->assertFalse($inspection['decryptable']);
        $this->assertFalse($inspection['needs_rewrite']);
    }

    public function test_legacy_enc_v1_value_is_decoded_via_existing_helper(): void
    {
        putenv('CORE_CREDENTIAL_KEY=test-secret-value-key');

        $legacyEncrypted = $this->legacyEncrypt('legacy-core-password', 'test-secret-value-key');
        $this->assertStringStartsWith('enc:v1:', $legacyEncrypted);

        $this->assertSame('legacy-core-password', SecretValue::decryptSecret($legacyEncrypted));

        $inspection = SecretValue::inspect($legacyEncrypted);
        $this->assertSame('legacy_enc_v1', $inspection['status']);
        $this->assertTrue($inspection['decryptable']);
        $this->assertTrue($inspection['needs_rewrite']);

        putenv('CORE_CREDENTIAL_KEY');
    }

    private function legacyEncrypt(string $plain, string $keySource): string
    {
        $iv = random_bytes(16);
        $key = hash('sha256', $keySource, true);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        self::assertIsString($cipher);
        $mac = hash_hmac('sha256', $iv.$cipher, $key, true);

        return 'enc:v1:'.base64_encode($iv).':'.base64_encode($cipher).':'.base64_encode($mac);
    }

    public function test_inspect_never_exposes_the_secret_value(): void
    {
        $secret = 'super-sensitive-value-should-never-leak';
        $encrypted = SecretValue::encryptSecret($secret);

        foreach ([$secret, $encrypted, 'old-plain-password', null, ''] as $value) {
            $inspection = SecretValue::inspect($value);
            $this->assertSame(['status', 'decryptable', 'needs_rewrite'], array_keys($inspection));
            foreach ($inspection as $field) {
                if (is_string($field)) {
                    $this->assertStringNotContainsString($secret, $field);
                }
            }
        }
    }
}
