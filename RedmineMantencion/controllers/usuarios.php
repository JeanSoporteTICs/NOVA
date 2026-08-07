<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/core_credentials.php';
require_once __DIR__ . '/maintenance.php';

function usuarios_set_flash(string $message): void {
    session()->put('mantencion_usuarios_flash', $message);
}

function usuarios_text_key(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = strtr($value, [
        'ÃƒÆ’Ã‚Â¡' => 'á', 'ÃƒÆ’Ã‚Â©' => 'é', 'ÃƒÆ’Ã‚Â­' => 'í', 'ÃƒÆ’Ã‚Â³' => 'ó', 'ÃƒÆ’Ã‚Âº' => 'ú',
        'ÃƒÆ’Ã‚Â±' => 'ñ', 'ÃƒÆ’Ã‚Â‘' => 'Ñ',
        'Ã¡' => 'á', 'Ã©' => 'é', 'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú', 'Ã±' => 'ñ',
    ]);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string)$value);
}

function usuarios_detect_repeated_suffix(string $fullName): array {
    $fullName = preg_replace('/\s+/', ' ', trim($fullName)) ?? '';
    $tokens = preg_split('/\s+/', $fullName) ?: [];
    $count = count($tokens);
    if ($count < 3) {
        return [$fullName, ''];
    }
    $maxLen = min(4, intdiv($count, 2));
    for ($len = $maxLen; $len >= 1; $len--) {
        $suffix = array_slice($tokens, -$len);
        $prev = array_slice($tokens, -($len * 2), $len);
        if (usuarios_text_key(implode(' ', $suffix)) !== usuarios_text_key(implode(' ', $prev))) {
            continue;
        }
        $nameTokens = $tokens;
        while (count($nameTokens) > $len) {
            $tail = array_slice($nameTokens, -$len);
            if (usuarios_text_key(implode(' ', $tail)) !== usuarios_text_key(implode(' ', $suffix))) {
                break;
            }
            $nameTokens = array_slice($nameTokens, 0, -$len);
        }
        if ($nameTokens !== []) {
            return [implode(' ', $nameTokens), implode(' ', $suffix)];
        }
    }

    return [$fullName, ''];
}

function usuarios_normalize_status(string $status): string {
    return in_array(strtolower(trim($status)), ['baneado', 'bloqueado', 'inactivo'], true) ? 'baneado' : 'activo';
}

function usuarios_normalize_module_role(string $role): string {
    $role = strtolower(trim($role));

    return in_array($role, ['root', 'administrador', 'gestor', 'usuario'], true) ? $role : 'usuario';
}

function usuarios_split_name(string $fullName): array {
    $fullName = trim($fullName);
    if ($fullName === '') {
        return ['', ''];
    }
    [$cleanName, $detectedLastName] = usuarios_detect_repeated_suffix($fullName);
    if ($detectedLastName !== '') {
        return [$cleanName, $detectedLastName];
    }
    $parts = preg_split('/\s+/', $fullName);
    if (!$parts || count($parts) === 1) {
        return [$fullName, ''];
    }
    $lastNameLength = count($parts) >= 3 ? 2 : 1;
    $lastName = implode(' ', array_slice($parts, -$lastNameLength));
    $firstName = implode(' ', array_slice($parts, 0, -$lastNameLength));
    return [trim($firstName), trim($lastName)];
}
