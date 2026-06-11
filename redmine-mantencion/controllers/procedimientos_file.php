<?php

require_once __DIR__ . '/procedimientos.php';

$id = trim((string)($_GET['id'] ?? ''));
$token = trim((string)($_GET['token'] ?? ''));
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
    http_response_code(404);
    exit('Archivo no disponible.');
}

$fileName = (string)($record['file_original_name'] ?? $record['file_name'] ?? 'documento');
$mime = trim((string)($record['file_mime'] ?? '')) ?: 'application/octet-stream';
$content = '';

if (($record['storage_driver'] ?? '') === 'nextcloud') {
    $download = procedures_nextcloud_download($record);
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

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($content));
header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($fileName)) . '"');
header('X-Content-Type-Options: nosniff');
echo $content;
