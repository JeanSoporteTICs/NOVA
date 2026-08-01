<?php

require_once __DIR__ . '/storage.php';

function core_credentials_key(): string {
    $envKey = trim((string)(getenv('CORE_CREDENTIAL_KEY') ?: getenv('APP_KEY') ?: ''));
    if ($envKey !== '') {
        return hash('sha256', $envKey, true);
    }
    $keyFile = __DIR__ . '/../data/app.key';
    if (is_file($keyFile)) {
        $stored = trim((string)file_get_contents($keyFile));
        if ($stored !== '') {
            return hash('sha256', $stored, true);
        }
    }
    $generated = bin2hex(random_bytes(32));
    storage_write_file_locked($keyFile, $generated, 0, false);
    return hash('sha256', $generated, true);
}

function core_credentials_encrypt(string $plain): string {
    $plain = trim($plain);
    if ($plain === '' || !function_exists('openssl_encrypt')) {
        return '';
    }
    $iv = random_bytes(16);
    $key = core_credentials_key();
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        return '';
    }
    $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
    return 'enc:v1:' . base64_encode($iv) . ':' . base64_encode($cipher) . ':' . base64_encode($mac);
}

function core_credentials_decrypt(string $payload): string {
    $payload = trim($payload);
    if ($payload === '' || !str_starts_with($payload, 'enc:v1:') || !function_exists('openssl_decrypt')) {
        return '';
    }
    $parts = explode(':', $payload, 5);
    if (count($parts) !== 5) {
        return '';
    }
    $iv = base64_decode($parts[2], true);
    $cipher = base64_decode($parts[3], true);
    $mac = base64_decode($parts[4], true);
    if ($iv === false || $cipher === false || $mac === false) {
        return '';
    }
    $key = core_credentials_key();
    $expected = hash_hmac('sha256', $iv . $cipher, $key, true);
    if (!hash_equals($expected, $mac)) {
        return '';
    }
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : trim($plain);
}

function core_credentials_central_user_id(string $userId): ?int {
    $userId = trim($userId);
    if ($userId === '' || !class_exists(\Illuminate\Support\Facades\DB::class) || !class_exists(\Illuminate\Support\Facades\Schema::class)) {
        return null;
    }
    try {
        if (!\Illuminate\Support\Facades\Schema::hasTable('usuarios_nova') || !\Illuminate\Support\Facades\Schema::hasTable('integraciones_usuario')) {
            return null;
        }

        // Los IDs numericos de NOVA y Redmine pueden coincidir entre usuarios
        // distintos. Los prefijos permiten que los flujos autenticados indiquen
        // de forma inequívoca qué identidad están entregando.
        foreach ([
            'nova:' => 'id',
            'uuid:' => 'uuid',
            'redmine:' => 'redmine_id',
            'usuario:' => 'usuario',
        ] as $prefix => $column) {
            if (!str_starts_with($userId, $prefix)) {
                continue;
            }
            $identity = trim(substr($userId, strlen($prefix)));
            if ($identity === '' || ($column === 'id' && !ctype_digit($identity))) {
                return null;
            }
            $rowId = \Illuminate\Support\Facades\DB::table('usuarios_nova')
                ->where($column, $column === 'id' ? (int)$identity : $identity)
                ->value('id');

            return $rowId === null ? null : (int)$rowId;
        }

        $rowId = \Illuminate\Support\Facades\DB::table('usuarios_nova')
            ->where('redmine_id', $userId)
            ->orWhere('uuid', $userId)
            ->orWhere('usuario', $userId)
            ->value('id');
        if ($rowId === null && ctype_digit($userId)) {
            $rowId = \Illuminate\Support\Facades\DB::table('usuarios_nova')
                ->where('id', (int)$userId)
                ->value('id');
        }

        return $rowId === null ? null : (int)$rowId;
    } catch (\Throwable) {
        return null;
    }
}

function core_credentials_central_for_user(string $userId, string $type): array {
    $novaUserId = core_credentials_central_user_id($userId);
    if ($novaUserId === null) {
        return ['user' => '', 'pass' => ''];
    }
    try {
        $row = \Illuminate\Support\Facades\DB::table('integraciones_usuario')
            ->where('usuario_id', $novaUserId)
            ->where('tipo', $type)
            ->first();
        $rawSecret = trim((string)($row->valor_secreto ?? ''));
        $secret = \App\Modulos\Nova\Support\SecretValue::decryptSecret($rawSecret) ?? '';

        // Auto-rewrite covers 'nextcloud' (Lote A4) and 'core' (Lote A5).
        if ($type === 'nextcloud' || $type === 'core') {
            core_credentials_maybe_rewrite_secret($novaUserId, $type, $rawSecret, $secret);
        }

        return [
            'user' => trim((string)($row->usuario_externo ?? '')),
            'pass' => $secret,
        ];
    } catch (\Throwable) {
        return ['user' => '', 'pass' => ''];
    }
}

/**
 * Opportunistically upgrades a plaintext-legacy or legacy enc:v1 secret to
 * Laravel encrypt() the moment it's read for a resolved usuario_id/tipo row.
 * Never runs for already-encrypted, invalid, or empty values, and never
 * fails the read if the rewrite itself fails.
 */
function core_credentials_maybe_rewrite_secret(int $userId, string $type, string $rawSecret, string $decryptedSecret): void {
    if ($rawSecret === '' || $decryptedSecret === '') {
        return;
    }
    if (!\App\Modulos\Nova\Support\SecretValue::inspect($rawSecret)['needs_rewrite']) {
        return;
    }
    try {
        \Illuminate\Support\Facades\DB::table('integraciones_usuario')
            ->where('usuario_id', $userId)
            ->where('tipo', $type)
            ->update([
                'valor_secreto' => \App\Modulos\Nova\Support\SecretValue::encryptSecret($decryptedSecret),
                'actualizado_at' => now(),
            ]);
    } catch (\Throwable) {
    }
}

function core_credentials_central_save_for_user(string $userId, string $type, string $externalUser, string $secret): bool {
    $novaUserId = core_credentials_central_user_id($userId);
    $externalUser = trim($externalUser);
    $secret = trim($secret);
    if ($novaUserId === null || $type === '' || $externalUser === '' || $secret === '') {
        return false;
    }
    try {
        \Illuminate\Support\Facades\DB::table('integraciones_usuario')->updateOrInsert(
            ['usuario_id' => $novaUserId, 'tipo' => $type],
            [
                'usuario_externo' => $externalUser,
                'valor_secreto' => \App\Modulos\Nova\Support\SecretValue::encryptSecret($secret),
                'actualizado_at' => now(),
            ]
        );
        return true;
    } catch (\Throwable) {
        return false;
    }
}

function core_credentials_central_clear_for_user(string $userId, string $type): bool {
    $novaUserId = core_credentials_central_user_id($userId);
    if ($novaUserId === null || $type === '') {
        return false;
    }
    try {
        \Illuminate\Support\Facades\DB::table('integraciones_usuario')
            ->where('usuario_id', $novaUserId)
            ->where('tipo', $type)
            ->delete();
        return true;
    } catch (\Throwable) {
        return false;
    }
}

function core_credentials_for_user(string $userId): array {
    if ($userId === '') {
        return ['user' => '', 'pass' => ''];
    }
    $central = core_credentials_central_for_user($userId, 'core');
    if ($central['user'] !== '' || $central['pass'] !== '') {
        return $central;
    }
    return ['user' => '', 'pass' => ''];
}

function nextcloud_credentials_for_user(string $userId): array {
    if ($userId === '') {
        return ['user' => '', 'pass' => ''];
    }
    $central = core_credentials_central_for_user($userId, 'nextcloud');
    if ($central['user'] !== '' || $central['pass'] !== '') {
        return $central;
    }
    return ['user' => '', 'pass' => ''];
}

function core_credentials_has_saved(string $userId): bool {
    $credentials = core_credentials_for_user($userId);
    return trim((string)$credentials['user']) !== '' && trim((string)$credentials['pass']) !== '';
}

function nextcloud_credentials_has_saved(string $userId): bool {
    $credentials = nextcloud_credentials_for_user($userId);
    return trim((string)$credentials['user']) !== '' && trim((string)$credentials['pass']) !== '';
}

function core_credentials_save_for_user(string $userId, string $coreUser, string $corePass): bool {
    $userId = trim($userId);
    $coreUser = trim($coreUser);
    $corePass = trim($corePass);
    if ($userId === '' || $coreUser === '' || $corePass === '') {
        return false;
    }
    if (core_credentials_central_save_for_user($userId, 'core', $coreUser, $corePass)) {
        return true;
    }
    return false;
}

function nextcloud_credentials_save_for_user(string $userId, string $nextcloudUser, string $nextcloudPass): bool {
    $userId = trim($userId);
    $nextcloudUser = trim($nextcloudUser);
    $nextcloudPass = trim($nextcloudPass);
    if ($userId === '' || $nextcloudUser === '' || $nextcloudPass === '') {
        return false;
    }
    if (core_credentials_central_save_for_user($userId, 'nextcloud', $nextcloudUser, $nextcloudPass)) {
        return true;
    }
    return false;
}

function core_credentials_clear_for_user(string $userId): bool {
    return core_credentials_central_clear_for_user($userId, 'core');
}
