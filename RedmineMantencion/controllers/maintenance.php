<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';

function maintenance_sections(): array {
    return [
        'procedimientos' => [
            'label' => 'Archivos de procedimientos',
            'paths' => ['procedimientos/documentos', 'procedimientos/imagenes'],
        ],
        'logs' => [
            'label' => 'Logs locales',
            'paths' => ['logs'],
        ],
    ];
}

function maintenance_load_config(): array {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    if ($repo !== null) {
        $cfg = $repo->loadAll();
        return is_array($cfg) ? $cfg : [];
    }

    return [];
}

function maintenance_save_config(array $cfg): bool {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    if ($repo !== null) {
        $repo->saveAll($cfg);
        return true;
    }

    return false;
}

function maintenance_mode_settings(): array {
    $cfg = maintenance_load_config();
    return [
        'enabled' => !empty($cfg['maintenance_mode']),
        'until' => trim((string)($cfg['maintenance_until'] ?? '')),
        'started_at' => trim((string)($cfg['maintenance_started_at'] ?? '')),
    ];
}

function maintenance_mode_enabled(): bool {
    return !empty(maintenance_mode_settings()['enabled']);
}

function maintenance_mode_until_text(): string {
    $until = maintenance_mode_settings()['until'];
    if ($until === '') {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $until, new DateTimeZone('America/Santiago'));
    return $dt ? $dt->format('d-m-Y H:i') : $until;
}

function maintenance_mode_block_message(): string {
    $until = maintenance_mode_until_text();
    return 'La plataforma esta en mantencion. Solo se permite ver datos actuales; no se pueden realizar cambios' . ($until !== '' ? ' hasta ' . $until : '') . '.';
}

function maintenance_mode_block_if_enabled(): void {
    if (!maintenance_mode_enabled()) {
        return;
    }
    $message = maintenance_mode_block_message();
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || (string)($_POST['ajax'] ?? '') === '1') {
        if (function_exists('dashboard_json_response')) {
            dashboard_json_response(['ok' => false, 'message' => $message, 'reason' => 'maintenance_mode'], 503);
        }
    }
    $notified = false;
    if (function_exists('dashboard_set_flash')) {
        dashboard_set_flash($message);
        $notified = true;
    }
    if (function_exists('usuarios_set_flash')) {
        usuarios_set_flash($message);
        $notified = true;
    }
    if (function_exists('manual_pending_flash_set')) {
        manual_pending_flash_set($message);
        $notified = true;
    }
    if (function_exists('procedures_set_flash')) {
        procedures_set_flash($message);
        $notified = true;
    }
    if (!$notified) {
        maintenance_set_flash($message);
    }
    $target = function_exists('url')
        ? url('/redmine-mantencion/app')
        : legacy_app_url();
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '') {
        $refererPath = parse_url($referer, PHP_URL_PATH);
        $refererHost = strtolower((string)(parse_url($referer, PHP_URL_HOST) ?? ''));
        $requestHost = function_exists('request') ? strtolower((string)request()->getHost()) : '';
        $basePath = function_exists('request') ? rtrim(request()->getBaseUrl(), '/') : '';
        $allowedPrefix = $basePath . '/redmine-mantencion/';
        if ($refererHost !== '' && $refererHost === $requestHost
            && is_string($refererPath) && str_starts_with($refererPath, $allowedPrefix)) {
            $target = $referer;
        }
    }
    header('Location: ' . $target);
    exit;
}

function maintenance_data_file(string $relative): string {
    return __DIR__ . '/../data/' . ltrim(str_replace('\\', '/', $relative), '/');
}

function maintenance_read_path(string $relative): array {
    return [];
}

function maintenance_read_binary_path(string $relative): array {
    $absolute = maintenance_data_file($relative);
    $files = [];
    if (is_file($absolute)) {
        $files[$relative] = base64_encode((string)file_get_contents($absolute));
        return $files;
    }
    if (!is_dir($absolute)) {
        return [];
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $full = str_replace('\\', '/', $item->getPathname());
        $root = str_replace('\\', '/', __DIR__ . '/../data/');
        $rel = substr($full, strlen($root));
        $files[$rel] = base64_encode((string)file_get_contents($item->getPathname()));
    }
    ksort($files);
    return $files;
}

function maintenance_export_bundle(array $selected): array {
    $available = maintenance_sections();
    $bundle = [
        'type' => 'redmine-mantencion-maintenance',
        'version' => 1,
        'created_at' => (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('c'),
        'sections' => [],
    ];
    foreach ($selected as $sectionKey) {
        if (!isset($available[$sectionKey])) {
            continue;
        }
        $bundle['sections'][$sectionKey] = [];
        foreach ($available[$sectionKey]['paths'] as $path) {
            foreach (maintenance_read_binary_path($path) as $relative => $encoded) {
                $bundle['sections'][$sectionKey][$relative] = [
                    '_encoding' => 'base64',
                    'content' => $encoded,
                ];
            }
        }
    }
    return $bundle;
}

function maintenance_allowed_paths_for_sections(array $selected): array {
    $available = maintenance_sections();
    $allowed = [];
    foreach ($selected as $sectionKey) {
        if (!isset($available[$sectionKey])) {
            continue;
        }
        foreach ($available[$sectionKey]['paths'] as $path) {
            $allowed[] = trim($path, '/');
        }
    }
    return $allowed;
}

function maintenance_path_allowed(string $relative, array $allowed): bool {
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    if ($relative === '' || str_contains($relative, '..')) {
        return false;
    }
    foreach ($allowed as $base) {
        if ($relative === $base || str_starts_with($relative, rtrim($base, '/') . '/')) {
            return true;
        }
    }
    return false;
}

function maintenance_merge_list_by_id(array $existing, array $incoming): array {
    $merged = [];
    $positions = [];
    foreach ($existing as $item) {
        if (!is_array($item)) {
            $merged[] = $item;
            continue;
        }
        $key = trim((string)($item['id'] ?? ''));
        if ($key === '') {
            $key = 'hash:' . md5(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $positions[$key] = count($merged);
        $merged[] = $item;
    }
    foreach ($incoming as $item) {
        if (!is_array($item)) {
            $merged[] = $item;
            continue;
        }
        $key = trim((string)($item['id'] ?? ''));
        if ($key === '') {
            $key = 'hash:' . md5(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if (array_key_exists($key, $positions)) {
            $merged[$positions[$key]] = array_replace_recursive(is_array($merged[$positions[$key]]) ? $merged[$positions[$key]] : [], $item);
        } else {
            $positions[$key] = count($merged);
            $merged[] = $item;
        }
    }
    return array_values($merged);
}

function maintenance_group_key(array $group): string {
    foreach (['fecha', 'fecha_inicio', 'start_date', 'date', 'created_on'] as $key) {
        $value = trim((string)($group[$key] ?? ''));
        if ($value !== '') {
            return $key . ':' . $value;
        }
    }
    if (isset($group['reports']) && is_array($group['reports'])) {
        $first = $group['reports'][0] ?? [];
        if (is_array($first)) {
            foreach (['fecha', 'fecha_inicio', 'created_on'] as $key) {
                $value = trim((string)($first[$key] ?? ''));
                if ($value !== '') {
                    return 'reports.' . $key . ':' . $value;
                }
            }
        }
    }
    return 'hash:' . md5(json_encode($group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function maintenance_merge_hours_groups(array $existing, array $incoming): array {
    $merged = [];
    $positions = [];
    foreach ($existing as $group) {
        if (!is_array($group)) {
            $merged[] = $group;
            continue;
        }
        $key = maintenance_group_key($group);
        $positions[$key] = count($merged);
        $merged[] = $group;
    }
    foreach ($incoming as $group) {
        if (!is_array($group)) {
            $merged[] = $group;
            continue;
        }
        $key = maintenance_group_key($group);
        if (array_key_exists($key, $positions)) {
            $current = is_array($merged[$positions[$key]]) ? $merged[$positions[$key]] : [];
            $combined = array_replace_recursive($current, $group);
            if (isset($current['reports']) || isset($group['reports'])) {
                $combined['reports'] = maintenance_merge_list_by_id(
                    is_array($current['reports'] ?? null) ? $current['reports'] : [],
                    is_array($group['reports'] ?? null) ? $group['reports'] : []
                );
            }
            $merged[$positions[$key]] = $combined;
        } else {
            $positions[$key] = count($merged);
            $merged[] = $group;
        }
    }
    return array_values($merged);
}

function maintenance_should_merge_json(string $relative): bool {
    return false;
}

function maintenance_write_import_json(string $relative, array $incoming): bool {
    return false;
}

function maintenance_import_bundle(array $bundle, array $selected): int {
    if (($bundle['type'] ?? '') !== 'redmine-mantencion-maintenance' || !isset($bundle['sections']) || !is_array($bundle['sections'])) {
        throw new RuntimeException('El archivo no corresponde a un respaldo de mantencion valido.');
    }
    $allowed = maintenance_allowed_paths_for_sections($selected);
    $written = 0;
    foreach ($selected as $sectionKey) {
        $files = $bundle['sections'][$sectionKey] ?? [];
        if (!is_array($files)) {
            continue;
        }
        foreach ($files as $relative => $data) {
            $relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
            if (!maintenance_path_allowed($relative, $allowed)) {
                continue;
            }
            if (is_array($data) && ($data['_encoding'] ?? '') === 'base64') {
                $decodedContent = (string)base64_decode((string)($data['content'] ?? ''), true);
                if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'json') {
                    $json = json_decode($decodedContent, true);
                    maintenance_write_import_json($relative, is_array($json) ? $json : []);
                } else {
                    storage_write_file_locked(maintenance_data_file($relative), $decodedContent, 0, true);
                }
            } else {
                maintenance_write_import_json($relative, is_array($data) ? $data : []);
            }
            $written++;
        }
    }
    return $written;
}

function maintenance_temp_dir(): string {
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'redmine-maint-' . bin2hex(random_bytes(6));
    if (!mkdir($base, 0777, true) && !is_dir($base)) {
        throw new RuntimeException('No se pudo crear el directorio temporal de respaldo.');
    }
    return $base;
}

function maintenance_remove_dir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

function maintenance_export_package(array $selected): array {
    $available = maintenance_sections();
    $root = maintenance_temp_dir();
    $dataRoot = $root . DIRECTORY_SEPARATOR . 'data';
    mkdir($dataRoot, 0777, true);
    $createdAt = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('c');
    $manifest = [
        'type' => 'redmine-mantencion-maintenance-package',
        'version' => 2,
        'created_at' => $createdAt,
        'sections' => [],
    ];

    foreach ($selected as $sectionKey) {
        if (!isset($available[$sectionKey])) {
            continue;
        }
        $manifest['sections'][$sectionKey] = [
            'label' => $available[$sectionKey]['label'],
            'files' => [],
        ];
        foreach ($available[$sectionKey]['paths'] as $path) {
            foreach (maintenance_read_binary_path($path) as $relative => $encoded) {
                $relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
                if ($relative === '' || str_contains($relative, '..') || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'json') {
                    continue;
                }
                $target = $dataRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                storage_write_file_locked($target, (string)base64_decode($encoded, true), 0, true);
                $manifest['sections'][$sectionKey]['files'][] = $relative;
            }
        }
    }
    storage_write_file_locked(
        $root . DIRECTORY_SEPARATOR . 'manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        0,
        false
    );

    $stamp = date('Ymd-His');
    if (class_exists('ZipArchive')) {
        $archive = $root . DIRECTORY_SEPARATOR . 'mantencion-redmine-' . $stamp . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP.');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getPathname() === $archive) {
                continue;
            }
            $local = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $zip->addFile($file->getPathname(), $local);
        }
        $zip->close();
        return ['path' => $archive, 'filename' => basename($archive), 'content_type' => 'application/zip', 'temp_dir' => $root];
    }

    $archive = $root . DIRECTORY_SEPARATOR . 'mantencion-redmine-' . $stamp . '.tar';
    $phar = new PharData($archive);
    $phar->buildFromDirectory($root, '/^(?!.*mantencion-redmine-.*\.tar$).*/');
    return ['path' => $archive, 'filename' => basename($archive), 'content_type' => 'application/x-tar', 'temp_dir' => $root];
}

function maintenance_extract_zip(string $file, string $dest): bool {
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        return false;
    }
    $ok = $zip->extractTo($dest);
    $zip->close();
    return $ok;
}

function maintenance_extract_tar(string $file, string $dest): bool {
    try {
        $phar = new PharData($file);
        $phar->extractTo($dest, null, true);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function maintenance_import_package(string $uploadedFile, array $selected): int {
    $extractDir = maintenance_temp_dir();
    $extracted = maintenance_extract_zip($uploadedFile, $extractDir) || maintenance_extract_tar($uploadedFile, $extractDir);
    if (!$extracted) {
        maintenance_remove_dir($extractDir);
        throw new RuntimeException('No se pudo descomprimir el respaldo. Usa ZIP o TAR.');
    }

    $manifestPath = $extractDir . DIRECTORY_SEPARATOR . 'manifest.json';
    $manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : [];
    if (!is_array($manifest) || ($manifest['type'] ?? '') !== 'redmine-mantencion-maintenance-package') {
        maintenance_remove_dir($extractDir);
        throw new RuntimeException('El paquete no contiene manifest.json valido.');
    }
    $allowed = maintenance_allowed_paths_for_sections($selected);
    $written = 0;
    foreach ($selected as $sectionKey) {
        $files = $manifest['sections'][$sectionKey]['files'] ?? [];
        if (!is_array($files)) {
            continue;
        }
        foreach ($files as $relative) {
            $relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
            if (!maintenance_path_allowed($relative, $allowed)) {
                continue;
            }
            $source = $extractDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($source)) {
                continue;
            }
            if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'json') {
                $decoded = json_decode((string)file_get_contents($source), true);
                maintenance_write_import_json($relative, is_array($decoded) ? $decoded : []);
            } else {
                storage_write_file_locked(maintenance_data_file($relative), (string)file_get_contents($source), 0, true);
            }
            $written++;
        }
    }
    maintenance_remove_dir($extractDir);
    return $written;
}

function maintenance_clear_output_buffers(): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function maintenance_set_flash(string $message): void {
    session()->put('mantencion_maintenance_flash', $message);
}

function maintenance_consume_flash(): ?string {
    return session()->pull('mantencion_maintenance_flash');
}

function maintenance_redirect_back(): void {
    $target = function_exists('url')
        ? legacy_app_url('app/configuracion?panel=mantencion')
        : legacy_app_url('views/Configuracion/configuracion.php');
    header('Location: ' . $target);
    exit;
}

function handle_maintenance_request(): ?string {
    $flash = maintenance_consume_flash();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $flash;
    }
    $action = $_POST['action'] ?? '';
    if ($action !== 'maintenance_settings') {
        return $flash;
    }
    if (function_exists('csrf_validate')) {
        csrf_validate();
    }
    $cfg = maintenance_load_config();
    $wasEnabled = !empty($cfg['maintenance_mode']);
    $enabled = !empty($_POST['maintenance_mode']);
    $cfg['maintenance_mode'] = $enabled;
    $cfg['maintenance_until'] = $enabled ? trim((string)($_POST['maintenance_until'] ?? '')) : '';
    if ($enabled && (!$wasEnabled || trim((string)($cfg['maintenance_started_at'] ?? '')) === '')) {
        $cfg['maintenance_started_at'] = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('c');
    } elseif (!$enabled) {
        unset($cfg['maintenance_started_at']);
    }
    maintenance_save_config($cfg);
    maintenance_set_flash($cfg['maintenance_mode'] ? 'Modo mantencion activado.' : 'Modo mantencion desactivado.');
    maintenance_redirect_back();
}
