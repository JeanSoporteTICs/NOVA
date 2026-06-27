<?php

require_once __DIR__ . '/procedimientos.php';

$id = trim((string)($_GET['id'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
procedures_onlyoffice_log('file.request', [
    'id' => $id,
    'token' => $token,
]);
$items = procedures_read_all();
$record = $id !== '' ? procedures_find_by_id($items, $id) : null;

$allowed = false;
if ($record) {
    $recordToken = trim((string)($record['share_token'] ?? ''));
    if ($token !== '' && $recordToken !== '' && hash_equals($recordToken, $token)) {
        $allowed = true;
    } else {
        auth_start_session();
        $allowed = !empty($_SESSION['user']) && auth_can('procedimientos');
    }
}

if (!$record || !$allowed) {
    procedures_onlyoffice_log('file.denied', [
        'id' => $id,
        'record_found' => $record ? 'yes' : 'no',
        'token_present' => $token !== '' ? 'yes' : 'no',
    ]);
    http_response_code(404);
    exit('Archivo no disponible.');
}

$fileName = (string)($record['file_original_name'] ?? $record['file_name'] ?? 'documento');
$mime = trim((string)($record['file_mime'] ?? '')) ?: 'application/octet-stream';
$content = '';

if (($record['storage_driver'] ?? '') === 'nextcloud') {
    $t0 = microtime(true);
    $download = procedures_nextcloud_download($record);
    procedures_onlyoffice_log('file.nextcloud_download', [
        'id' => $id,
        'ok' => empty($download['ok']) ? 'no' : 'yes',
        'ms' => (int)round((microtime(true) - $t0) * 1000),
        'path' => (string)($record['nextcloud_path'] ?? ''),
        'error' => (string)($download['error'] ?? ''),
    ]);
    if (empty($download['ok'])) {
        http_response_code(502);
        exit('No se pudo obtener el archivo desde Nextcloud.');
    }
    $content = (string)($download['body'] ?? '');
} else {
    $path = procedures_local_file_path($record);
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        exit('Archivo no encontrado.');
    }
    $content = (string)file_get_contents($path);
}

procedures_onlyoffice_log('file.serve', [
    'id' => $id,
    'storage_driver' => (string)($record['storage_driver'] ?? ''),
    'mime' => $mime,
    'bytes' => strlen($content),
    'file_name' => $fileName,
]);

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($content));
header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($fileName)) . '"');
header('X-Content-Type-Options: nosniff');
echo $content;
