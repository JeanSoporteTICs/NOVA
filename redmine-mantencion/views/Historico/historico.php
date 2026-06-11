<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/dashboard.php';
require_once __DIR__ . '/../../controllers/storage.php';
require_once __DIR__ . '/../../controllers/maintenance.php';
if (!auth_can('historico')) {
  header('Location: ' . legacy_app_url());
  exit;
}

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$maintenanceMode = maintenance_mode_enabled();

function historico_read_json_file(string $file): array {
  $data = storage_read_json($file, []);
  return is_array($data) ? $data : [];
}

function historico_prefix_from_base(string $base, string $fallback): string {
  $rel = storage_relative_data_path(rtrim($base, '/\\') . '/placeholder.json');
  $prefix = is_string($rel) ? preg_replace('#/placeholder\.json$#', '', $rel) : '';
  return trim((string)($prefix ?: $fallback), '/');
}

// --- Helpers para eliminar registros ---
function delete_reporte(string $base, string $id): bool {
  $changed = false;
  foreach (storage_json_by_prefix(historico_prefix_from_base($base, 'reportes')) as $rel => $data) {
    if (!$data) continue;
    $new = array_values(array_filter($data, fn($r) => !is_array($r) || ($r['id'] ?? '') !== $id));
    if (count($new) !== count($data)) {
      storage_write_json(storage_data_path($rel), $new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      $changed = true;
    }
  }
  return $changed;
}

function delete_horas_extra(string $base, string $id): bool {
  $changed = false;
  foreach (storage_json_by_prefix(historico_prefix_from_base($base, 'horasExtras')) as $rel => $groups) {
    if (!$groups) continue;
    $newGroups = [];
    foreach ($groups as $g) {
      if (!isset($g['reports']) || !is_array($g['reports'])) continue;
      $reports = array_values(array_filter($g['reports'], fn($r) => !is_array($r) || ($r['id'] ?? '') !== $id));
      if (empty($reports)) continue;
      $g['reports'] = $reports;
      $newGroups[] = $g;
    }
    if ($newGroups !== $groups) {
      storage_write_json(storage_data_path($rel), $newGroups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      $changed = true;
    }
  }
  return $changed;
}

// --- Eliminar si se solicito ---
$alert = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'], $_POST['id'], $_POST['fuente']) && $_POST['action'] === 'delete') {
  if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
  $id = trim($_POST['id']);
  $src = $_POST['fuente'];
  $ok = false;
  $deleteRoles = auth_load_roles();
  $deleteRoleName = auth_get_user_role();
  $deleteRoleCfg = $deleteRoles[$deleteRoleName] ?? [];
  $deleteCanAct = !$maintenanceMode && auth_can('historico_acciones');
  if (!$maintenanceMode && !$deleteCanAct && $deleteRoleName === 'gestor' && !array_key_exists('historico_acciones', $deleteRoleCfg)) {
    $deleteCanAct = true;
  }
  $deleteScope = auth_user_has_all_permissions() ? 'todos' : (auth_get_permission_value('historico_scope') ?? ($deleteRoleCfg['historico_scope'] ?? 'asignados'));
  $deleteUserId = (string)auth_get_user_id();
  $deleteUserNames = array_values(array_filter([
    trim((string)($_SESSION['user']['nombre'] ?? '')),
    trim((string)((auth_find_user_by_id($deleteUserId)['nombre'] ?? ''))),
  ], fn($value) => $value !== ''));
  $sourceRows = $src === 'reportes'
    ? load_reportes(__DIR__ . '/../../data/reportes')
    : ($src === 'horas_extra' ? load_horas_extras(__DIR__ . '/../../data/horasExtras') : []);
  $target = null;
  foreach ($sourceRows as $row) {
    if (is_array($row) && (string)($row['id'] ?? '') === $id) {
      $target = $row;
      break;
    }
  }
  $canDeleteTarget = $deleteCanAct
    && is_array($target)
    && ($deleteScope === 'todos' || historico_record_matches_current_user($target, $deleteUserId, $deleteUserNames));

  if ($canDeleteTarget) {
    if ($src === 'reportes') {
      $ok = delete_reporte(__DIR__ . '/../../data/reportes', $id);
    } elseif ($src === 'horas_extra') {
      $ok = delete_horas_extra(__DIR__ . '/../../data/horasExtras', $id);
    }
  }
  $alert = $ok ? 'Reporte eliminado.' : 'No se pudo eliminar el registro.';
}

function norm_date(string $str): string {
  $str = trim($str);
  if ($str === '') return '';
  if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $str, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str)) return $str;
  return '';
}

function historico_format_date(string $str): string {
  $date = norm_date($str);
  if ($date === '') return $str;
  $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
  return $dt ? $dt->format('d-m-Y') : $str;
}

function historico_redmine_issue_url(string $platformUrl, string $redmineId): string {
  $redmineId = trim($redmineId);
  if ($redmineId === '' || !preg_match('/^\d+$/', $redmineId)) {
    return '';
  }
  $platformUrl = trim($platformUrl);
  if ($platformUrl === '') {
    return '';
  }
  $base = preg_replace('#/projects/[^/]+/issues(?:\.json)?(?:\?.*)?$#i', '', $platformUrl);
  if ($base === $platformUrl) {
    $base = preg_replace('#/issues(?:\.json)?(?:\?.*)?$#i', '', $platformUrl);
  }
  $base = rtrim((string)$base, '/');
  return $base !== '' ? $base . '/issues/' . rawurlencode($redmineId) : '';
}

function historico_redmine_issue_api_url(string $platformUrl, string $redmineId): string {
  $issueUrl = historico_redmine_issue_url($platformUrl, $redmineId);
  return $issueUrl !== '' ? $issueUrl . '.json' : '';
}

function historico_redmine_is_closed_status(string $statusName): bool {
  $statusKey = dashboard_normalize_text($statusName);
  if ($statusKey === '') {
    return false;
  }

  foreach (['cerrad', 'closed', 'resuelt', 'resolved', 'finaliz', 'complet', 'terminad'] as $closedNeedle) {
    if (str_contains($statusKey, $closedNeedle)) {
      return true;
    }
  }

  return false;
}

function historico_fetch_redmine_status(string $platformUrl, string $redmineId, string $token): array {
  static $cache = [];

  $redmineId = trim($redmineId);
  $cacheKey = $platformUrl . '|' . $redmineId . '|' . ($token !== '' ? 'token' : 'public');
  if (isset($cache[$cacheKey])) {
    return $cache[$cacheKey];
  }

  $empty = [
    'name' => '',
    'closed' => false,
    'available' => false,
    'message' => 'Sin Redmine ID',
  ];
  if ($redmineId === '') {
    return $cache[$cacheKey] = $empty;
  }

  $url = historico_redmine_issue_api_url($platformUrl, $redmineId);
  if ($url === '') {
    return $cache[$cacheKey] = [
      'name' => '',
      'closed' => false,
      'available' => false,
      'message' => 'URL Redmine no configurada',
    ];
  }
  if (!function_exists('curl_init')) {
    return $cache[$cacheKey] = [
      'name' => '',
      'closed' => false,
      'available' => false,
      'message' => 'cURL no disponible',
    ];
  }

  $headers = ['Accept: application/json'];
  if ($token !== '') {
    $headers[] = 'X-Redmine-API-Key: ' . $token;
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 4,
  ]);
  $body = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  if ($body === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 300) {
    return $cache[$cacheKey] = [
      'name' => '',
      'closed' => false,
      'available' => false,
      'message' => $curlError !== '' ? $curlError : 'HTTP ' . $httpCode,
    ];
  }

  $payload = json_decode((string)$body, true);
  $statusName = trim((string)($payload['issue']['status']['name'] ?? ''));
  if ($statusName === '') {
    return $cache[$cacheKey] = [
      'name' => '',
      'closed' => false,
      'available' => false,
      'message' => 'Estado no informado por Redmine',
    ];
  }

  return $cache[$cacheKey] = [
    'name' => $statusName,
    'closed' => historico_redmine_is_closed_status($statusName),
    'available' => true,
    'message' => '',
  ];
}

function historico_matches_search(array $row, string $needle): bool {
  $needle = dashboard_normalize_text($needle);
  if ($needle === '') {
    return true;
  }

  $haystacks = [
    trim((string)($row['solicitante'] ?? '')),
    trim((string)($row['core_detalle_nombre'] ?? '')),
    trim((string)($row['core_detalle_run'] ?? '')),
  ];

  foreach ((array)($row['core_detalle_items'] ?? []) as $item) {
    if (!is_array($item)) {
      continue;
    }
    $haystacks[] = trim((string)($item['detalle_nombre'] ?? ''));
    $haystacks[] = trim((string)($item['detalle_run'] ?? ''));
  }

  foreach ($haystacks as $candidate) {
    $normalized = dashboard_normalize_text($candidate);
    if ($normalized !== '' && str_contains($normalized, $needle)) {
      return true;
    }
  }

  return false;
}

function load_reportes(string $base): array {
  $out = [];
  foreach (storage_json_by_prefix(historico_prefix_from_base($base, 'reportes')) as $data) {
    if (!$data) continue;
    foreach ($data as $row) {
      if (!is_array($row)) continue;
      $row = dashboard_expand_message($row);
      $row['_fuente'] = 'reportes';
      $out[] = $row;
    }
  }
  return $out;
}

function load_horas_extras(string $base): array {
  $out = [];
  foreach (storage_json_by_prefix(historico_prefix_from_base($base, 'horasExtras')) as $groups) {
    if (!$groups) continue;
    foreach ($groups as $g) {
      if (!isset($g['reports']) || !is_array($g['reports'])) continue;
      $fechaGrupo = $g['fecha'] ?? '';
      foreach ($g['reports'] as $rep) {
        if (!is_array($rep)) continue;
        $rep['fecha'] = $rep['fecha'] ?? $fechaGrupo;
        $rep['_fuente'] = 'horas_extra';
        $rep['hora_extra'] = 'SI';
        $out[] = $rep;
      }
    }
  }
  return $out;
}

function historico_record_matches_current_user(array $row, string $userId, array $userNames): bool {
  $assignedId = trim((string)($row['asignado_a'] ?? ''));
  if ($assignedId !== '' && $assignedId === $userId) {
    return true;
  }
  $candidates = [
    trim((string)($row['core_usuario_asignado'] ?? '')),
    trim((string)($row['asignado_nombre'] ?? '')),
  ];
  foreach ($userNames as $expected) {
    if ($expected === '') continue;
    foreach ($candidates as $candidate) {
      if ($candidate !== '' && dashboard_name_tokens_match($expected, $candidate)) {
        return true;
      }
    }
  }
  return false;
}

$f_desde     = norm_date($_GET['desde'] ?? '');
$f_hasta     = norm_date($_GET['hasta'] ?? '');
$f_usuario   = trim($_GET['usuario'] ?? '');
$f_categoria = strtolower(trim($_GET['categoria'] ?? ''));
$f_fuente    = $_GET['fuente'] ?? '';
$f_busqueda  = trim($_GET['buscar'] ?? '');
$perPageOptions = [25, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, $perPageOptions, true)) {
  $perPage = 25;
}
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$roles       = auth_load_roles();
$roleName    = auth_get_user_role();
$roleCfg     = $roles[$roleName] ?? [];
$scopePermitido = auth_user_has_all_permissions() ? 'todos' : (auth_get_permission_value('historico_scope') ?? ($roleCfg['historico_scope'] ?? 'asignados'));
$scopeBloqueado = ($scopePermitido === 'asignados');
$showActions = !$maintenanceMode && auth_can('historico_acciones');
if (!$maintenanceMode && !$showActions && $roleName === 'gestor' && !array_key_exists('historico_acciones', $roleCfg)) {
  // compatibilidad con roles antiguos sin la clave
  $showActions = true;
}
$tableColspan = $showActions ? 12 : 11;
$f_scope = $_GET['mensajes_scope'] ?? ($scopePermitido === 'todos' ? 'todos' : 'asignados');
if (!in_array($f_scope, ['todos','asignados'], true)) $f_scope = 'asignados';
if ($scopePermitido === 'asignados') {
  $f_scope = 'asignados';
}
$userId = (string)auth_get_user_id();
$userNames = array_values(array_filter([
  trim((string)($_SESSION['user']['nombre'] ?? '')),
  trim((string)((auth_find_user_by_id($userId)['nombre'] ?? ''))),
], fn($value) => $value !== ''));

$cfg = load_platform_config();
$redminePlatformUrl = (string)($cfg['platform_url'] ?? '');
$redmineToken = load_user_api_token($userId);
if ($redmineToken === '') {
  $redmineToken = trim((string)($cfg['platform_token'] ?? ''));
}

if (($_GET['ajax'] ?? '') === 'redmine_statuses') {
  header('Content-Type: application/json; charset=utf-8');
  $ids = array_values(array_unique(array_filter(array_map(
    static fn($id) => preg_replace('/\D+/', '', trim((string)$id)),
    explode(',', (string)($_GET['ids'] ?? ''))
  ))));
  $ids = array_slice($ids, 0, 100);
  $statuses = [];
  foreach ($ids as $id) {
    if ($id === '') {
      continue;
    }
    $statuses[$id] = historico_fetch_redmine_status($redminePlatformUrl, $id, $redmineToken);
  }
  echo json_encode(['ok' => true, 'statuses' => $statuses], JSON_UNESCAPED_UNICODE);
  exit;
}

$items  = [];
$items  = array_merge($items, load_reportes(__DIR__ . '/../../data/reportes'));
$items  = array_merge($items, load_horas_extras(__DIR__ . '/../../data/horasExtras'));

$filtered = [];
foreach ($items as $row) {
  if (!is_array($row)) continue;
  if (strtolower(trim((string)($row['estado'] ?? ''))) !== 'procesado') continue;
  $fecha = norm_date($row['fecha'] ?? ($row['fecha_inicio'] ?? ''));
  if ($fecha === '') continue;
  if ($f_desde && $fecha < $f_desde) continue;
  if ($f_hasta && $fecha > $f_hasta) continue;
  if ($f_fuente && ($row['_fuente'] ?? '') !== $f_fuente) {
    continue;
  }
  if ($f_usuario !== '' && (string)($row['asignado_a'] ?? '') !== (string)$f_usuario) continue;
  if ($f_scope === 'asignados' && !historico_record_matches_current_user($row, $userId, $userNames)) continue;
  $cat = strtolower($row['categoria'] ?? '');
  if ($f_categoria !== '' && $cat !== $f_categoria) continue;
  if (!historico_matches_search($row, $f_busqueda)) continue;
  $row['_fecha_norm'] = $fecha;
  $filtered[] = $row;
}

usort($filtered, function ($a, $b) {
  return strcmp($b['_fecha_norm'] ?? '', $a['_fecha_norm'] ?? '');
});

$totalFiltered = count($filtered);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($currentPage > $totalPages) {
  $currentPage = $totalPages;
}
$pageOffset = ($currentPage - 1) * $perPage;
$pagedRows = array_slice($filtered, $pageOffset, $perPage);
$rangeStart = $totalFiltered > 0 ? $pageOffset + 1 : 0;
$rangeEnd = min($totalFiltered, $pageOffset + count($pagedRows));
$historicoFilterChips = [];
if ($f_desde !== '') $historicoFilterChips[] = ['icon' => 'bi-calendar-event', 'label' => 'Desde ' . historico_format_date($f_desde), 'remove' => 'desde'];
if ($f_hasta !== '') $historicoFilterChips[] = ['icon' => 'bi-calendar-check', 'label' => 'Hasta ' . historico_format_date($f_hasta), 'remove' => 'hasta'];
if ($f_fuente !== '') $historicoFilterChips[] = ['icon' => 'bi-inboxes', 'label' => 'Fuente ' . $f_fuente, 'remove' => 'fuente'];
if ($f_busqueda !== '') $historicoFilterChips[] = ['icon' => 'bi-search', 'label' => 'Busqueda ' . $f_busqueda, 'remove' => 'buscar'];
if ($f_categoria !== '') $historicoFilterChips[] = ['icon' => 'bi-tags', 'label' => 'Categoria ' . $f_categoria, 'remove' => 'categoria'];
if (!$scopeBloqueado && $f_usuario !== '') $historicoFilterChips[] = ['icon' => 'bi-person', 'label' => 'Asignado ' . $f_usuario, 'remove' => 'usuario'];
$historicoChipUrl = static function (string $key) use ($perPage): string {
  $query = $_GET;
  unset($query[$key]);
  $query['page'] = 1;
  $query['per_page'] = $perPage;
  return 'historico.php' . ($query ? '?' . http_build_query($query) : '');
};
$historicoPageUrl = static function (int $page) use ($perPage): string {
  $query = $_GET;
  $query['page'] = max(1, $page);
  $query['per_page'] = $perPage;
  return 'historico.php?' . http_build_query($query);
};

$usuariosSel = [];
$catsSel     = [];
foreach ($items as $r) {
  if (!is_array($r)) continue;
  $usuariosSel[(string)($r['asignado_a'] ?? '')] = $r['asignado_nombre'] ?? ($r['asignado_a'] ?? '');
  $catsSel[strtolower($r['categoria'] ?? '')]    = $r['categoria'] ?? '';
}
ksort($usuariosSel);
ksort($catsSel);
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Historico'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
  <style>
    .historico-filter-card {
      border: 1px solid rgba(15, 23, 42, .08);
      background: linear-gradient(180deg, rgba(255,255,255,.94), rgba(248,250,255,.9));
    }
    .historico-filter-card .form-floating > label {
      max-width: calc(100% - 1.5rem);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .historico-filter-card .btn {
      min-height: 48px;
      white-space: nowrap;
    }
    .historico-summary {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
      align-items: center;
      justify-content: space-between;
      padding: 1rem 1.15rem;
      border-bottom: 1px solid rgba(15, 23, 42, .08);
      background: rgba(248, 250, 255, .86);
    }
    .historico-count {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      color: #0f172a;
      font-weight: 700;
    }
    .historico-summary__tools {
      display: flex;
      align-items: center;
      gap: .9rem;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .historico-filter-chips {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem;
      padding: .85rem 1.15rem;
      border-bottom: 1px solid rgba(15, 23, 42, .06);
      background: rgba(255, 255, 255, .72);
    }
    .historico-filter-chip {
      display: inline-flex;
      align-items: center;
      gap: .38rem;
      min-height: 32px;
      padding: .36rem .62rem;
      border-radius: 999px;
      border: 1px solid rgba(37, 99, 235, .18);
      background: rgba(37, 99, 235, .08);
      color: #1d4ed8;
      font-size: .82rem;
      font-weight: 800;
      text-decoration: none;
    }
    .historico-filter-chip:hover {
      background: #2563eb;
      color: #fff;
    }
    .historico-table-card.is-compact .historico-table {
      font-size: .84rem;
    }
    .historico-table-card.is-compact .historico-table th,
    .historico-table-card.is-compact .historico-table td {
      padding-top: .45rem;
      padding-bottom: .45rem;
    }
    .historico-table th {
      white-space: nowrap;
      vertical-align: middle;
    }
    .historico-table td {
      vertical-align: middle;
    }
    .historico-date {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      min-width: 108px;
      color: #1e3a8a;
      font-weight: 700;
    }
    .historico-source-badge {
      border-radius: 999px;
      padding: .4rem .7rem;
      background: rgba(56, 189, 248, .14);
      color: #075985;
      border: 1px solid rgba(56, 189, 248, .22);
      font-weight: 700;
    }
    .historico-status {
      display: inline-flex;
      max-width: 130px;
      border-radius: 999px;
      padding: .35rem .65rem;
      background: rgba(52, 211, 153, .15);
      color: #047857;
      font-size: .78rem;
      font-weight: 700;
    }
  </style>
</head>
<body class="bg-light">
<?php $activeNav = 'historico'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-archive';
    $heroTitle = 'Histórico';
    $heroSubtitle = 'Registros procesados archivados y horas extra.';
    include __DIR__ . '/../partials/hero.php';
  ?>

  <?php if ($alert): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
      <?= $h($alert) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <form id="filter-form" class="card card-body shadow-sm mb-3 historico-filter-card" method="get" aria-live="polite">
    <div class="row g-3 align-items-end">
      <?php
        $filterFields = [
          ['label' => 'Desde', 'name' => 'desde', 'type' => 'date', 'value' => $f_desde, 'col' => 2, 'aria_label' => 'Fecha desde'],
          ['label' => 'Hasta', 'name' => 'hasta', 'type' => 'date', 'value' => $f_hasta, 'col' => 2, 'aria_label' => 'Fecha hasta'],
          ['label' => 'Fuente', 'name' => 'fuente', 'type' => 'select', 'options' => ['' => 'Todas', 'reportes' => 'Reportes', 'horas_extra' => 'Horas extra'], 'value' => $f_fuente, 'col' => 2],
          ['label' => 'Buscar solicitante / nombre / rut', 'name' => 'buscar', 'type' => 'text', 'value' => $f_busqueda, 'col' => 4, 'aria_label' => 'Buscar por solicitante, nombre o rut'],
        ];
        if (!$scopeBloqueado) {
          $filterFields[] = [
            'label' => 'Asignado',
            'name' => 'usuario',
            'type' => 'select',
            'options' => ['' => 'Todos'] + $usuariosSel,
            'value' => $f_usuario,
            'col' => 2,
          ];
        }
        $filterFields[] = [
          'label' => 'Categoría',
          'name' => 'categoria',
          'type' => 'select',
          'options' => ['' => 'Todas'] + $catsSel,
          'value' => $f_categoria,
          'col' => 3,
        ];
      ?>
      <?php foreach ($filterFields as $field): ?>
        <?php include __DIR__ . '/../partials/filter-field.php'; ?>
      <?php endforeach; ?>
      <div class="col-md-2">
        <button
          type="submit"
          id="btn-apply"
          class="btn btn-primary w-100"
          data-bs-spinner="true"
          aria-label="Aplicar filtros"
          aria-pressed="false">
          <i class="bi bi-funnel"></i> Filtrar
        </button>
      </div>
      <div class="col-md-2">
        <a
          class="btn btn-outline-secondary w-100"
          id="btn-clear"
          href="historico.php"
          aria-label="Limpiar filtros"
          aria-pressed="false">
          <i class="bi bi-x-circle"></i> Limpiar
        </a>
      </div>
    </div>
    <div id="filter-feedback" class="d-none mt-3 alert alert-info d-flex align-items-center" role="status" aria-live="polite">
      <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
      Aplicando filtros...
    </div>
  </form>

  <div class="card shadow-sm historico-table-card" id="historico-table-card">
    <div class="historico-summary">
      <div>
        <span class="historico-count"><i class="bi bi-clock-history text-primary"></i> <?= count($filtered) ?> registros</span>
        <span class="text-muted ms-2">Mostrando <?= $h($rangeStart) ?>-<?= $h($rangeEnd) ?> de <?= $h($totalFiltered) ?></span>
      </div>
      <div class="historico-summary__tools">
        <label class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" role="switch" id="historico-compact-toggle">
          <span class="form-check-label fw-semibold">Modo compacto</span>
        </label>
        <div class="historico-page-size text-muted small">
          Página <?= $h($currentPage) ?> de <?= $h($totalPages) ?>
        </div>
      </div>
    </div>
    <?php if (!empty($historicoFilterChips)): ?>
      <div class="historico-filter-chips" aria-label="Filtros activos">
        <?php foreach ($historicoFilterChips as $chip): ?>
          <a class="historico-filter-chip" href="<?= $h($historicoChipUrl($chip['remove'])) ?>" title="Quitar filtro">
            <i class="bi <?= $h($chip['icon']) ?>"></i>
            <?= $h($chip['label']) ?>
            <i class="bi bi-x"></i>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div id="redmine-sync-panel" class="historico-redmine-sync d-none" role="status" aria-live="polite">
      <div class="historico-redmine-sync__header">
        <span><i class="bi bi-arrow-repeat"></i> Sincronizando estados con Redmine</span>
        <strong id="redmine-sync-count">0/0</strong>
      </div>
      <div class="progress" aria-hidden="true">
        <div id="redmine-sync-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
      </div>
    </div>
    <div class="card-body p-0 position-relative">
      <div class="table-responsive position-relative">
        <div id="table-loader" class="loader-overlay d-none" role="status" aria-live="polite">
          <div class="d-flex align-items-center gap-2">
            <span class="spinner-border spinner-border-lg text-primary" role="status" aria-hidden="true"></span>
            <strong>Cargando registros…</strong>
          </div>
        </div>
        <table class="table table-hover historico-table align-middle mb-0" role="grid" aria-label="Histórico de reportes" aria-busy="false">
          <thead class="table-light">
            <tr class="position-sticky top-0 bg-light">
              <th scope="col">Fecha</th>
              <th scope="col">Redmine ID</th>
              <th scope="col">Estado Redmine</th>
              <th scope="col" class="text-truncate" style="max-width: 160px;">Solicitante</th>
              <th scope="col" class="text-truncate" style="max-width: 220px;">Categoría</th>
              <th scope="col" class="text-truncate" style="max-width: 140px;">Establecimiento</th>
              <th scope="col" class="text-truncate" style="max-width: 140px;">Departamento</th>
              <th scope="col" class="text-truncate" style="max-width: 140px;">Asignado CORE</th>
              <th scope="col">Estado CORE</th>
              <th scope="col">Fuente</th>
              <th scope="col">Detalle</th>
              <?php if ($showActions): ?>
                <th scope="col">Acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($pagedRows)): ?>
              <tr><td colspan="<?= $tableColspan ?>" class="text-center text-muted py-4">Sin registros para el criterio seleccionado.</td></tr>
            <?php else: ?>
              <?php foreach ($pagedRows as $row): ?>
                <?php
                  $previewRows = dashboard_detail_preview_rows($row);
                  $previewRowsJson = $h((string)json_encode(array_values($previewRows), JSON_UNESCAPED_UNICODE));
                  $previewColumnsJson = $h((string)json_encode(dashboard_core_detail_table_schema($row), JSON_UNESCAPED_UNICODE));
                  $detalleDescripcion = ($row['fuente'] ?? '') === 'manual'
                    ? trim((string)($row['descripcion'] ?? ''))
                    : '';
                  $coreEstado = trim((string)($row['core_estado'] ?? ''));
                  $coreEstadoKey = dashboard_normalize_text($coreEstado);
                  $coreEstadoClass = str_contains($coreEstadoKey, 'manual')
                    ? 'historico-status--manual'
                    : (str_contains($coreEstadoKey, 'gestionada') || str_contains($coreEstadoKey, 'gestionado')
                      ? 'historico-status--managed'
                      : 'historico-status--neutral');
                  $coreEstadoIcon = $coreEstadoClass === 'historico-status--manual'
                    ? 'bi-pencil-square'
                    : ($coreEstadoClass === 'historico-status--managed' ? 'bi-check2-circle' : 'bi-info-circle');
                  $redmineId = trim((string)($row['redmine_id'] ?? ''));
                  $redmineIssueUrl = historico_redmine_issue_url($redminePlatformUrl, $redmineId);
                ?>
                <tr>
                  <td><span class="historico-date"><i class="bi bi-calendar3"></i><?= $h(historico_format_date($row['_fecha_norm'] ?? '')) ?></span></td>
                  <td>
                    <?php if ($redmineId !== '' && $redmineIssueUrl !== ''): ?>
                      <a class="historico-redmine-link" href="<?= $h($redmineIssueUrl) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-box-arrow-up-right"></i> <?= $h($redmineId) ?>
                      </a>
                    <?php else: ?>
                      <span class="text-muted"><?= $h($redmineId !== '' ? $redmineId : '-') ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($redmineId !== ''): ?>
                      <span
                        class="historico-redmine-status historico-redmine-status--syncing js-redmine-status"
                        data-redmine-id="<?= $h($redmineId) ?>"
                        title="Sincronizando con Redmine">
                        <i class="bi bi-arrow-repeat"></i>
                        <span>Sincronizando</span>
                      </span>
                    <?php else: ?>
                      <span class="text-muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-truncate" style="max-width: 160px;" title="<?= $h($row['solicitante'] ?? '') ?>"><?= $h($row['solicitante'] ?? '') ?></td>
                  <td class="text-truncate" style="max-width: 220px;" title="<?= $h($row['categoria'] ?? '') ?>"><?= $h($row['categoria'] ?? '') ?></td>
                  <td class="text-truncate" style="max-width: 140px;" title="<?= $h($row['core_establecimiento'] ?? ($row['unidad_solicitante'] ?? '')) ?>"><?= $h($row['core_establecimiento'] ?? ($row['unidad_solicitante'] ?? '')) ?></td>
                  <td class="text-truncate" style="max-width: 140px;" title="<?= $h($row['core_departamento'] ?? ($row['unidad'] ?? '')) ?>"><?= $h($row['core_departamento'] ?? ($row['unidad'] ?? '')) ?></td>
                  <td class="text-truncate" style="max-width: 140px;" title="<?= $h($row['core_usuario_asignado'] ?? ($row['asignado_nombre'] ?? ($row['asignado_a'] ?? ''))) ?>"><?= $h($row['core_usuario_asignado'] ?? ($row['asignado_nombre'] ?? ($row['asignado_a'] ?? ''))) ?></td>
                  <td>
                    <span class="historico-status <?= $h($coreEstadoClass) ?> text-truncate" title="<?= $h($coreEstado) ?>">
                      <i class="bi <?= $h($coreEstadoIcon) ?>"></i>
                      <?= $h($coreEstado !== '' ? $coreEstado : 'Sin estado') ?>
                    </span>
                  </td>
                  <?php $fuenteLabel = $row['_fuente'] ?? ''; ?>
                  <td><span class="historico-source-badge"><?= $h($fuenteLabel) ?></span></td>
                  <td>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-primary historico-detail-btn"
                      data-bs-toggle="modal"
                      data-bs-target="#historicoDetalleModal"
                      data-preview_rows="<?= $previewRowsJson ?>"
                      data-preview_columns="<?= $previewColumnsJson ?>"
                      data-fuente="<?= $h($row['fuente'] ?? '') ?>"
                      data-core_tipo_solicitud="<?= $h($row['core_tipo_solicitud'] ?? '') ?>"
                      data-asunto="<?= $h($row['asunto'] ?? '') ?>"
                      data-solicitante="<?= $h($row['solicitante'] ?? '') ?>"
                      data-descripcion="<?= $h($detalleDescripcion) ?>"
                    >
                      <i class="bi bi-eye"></i>
                    </button>
                  </td>
                  <?php if ($showActions): ?>
                    <td>
                      <form method="post" class="m-0" data-app-confirm="Eliminar este registro del histórico?">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $h($row['id'] ?? '') ?>">
                        <input type="hidden" name="fuente" value="<?= $h($row['_fuente'] ?? '') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php
        $windowStart = max(1, $currentPage - 2);
        $windowEnd = min($totalPages, $currentPage + 2);
      ?>
      <nav class="historico-pagination" aria-label="Paginación histórico">
        <div class="historico-pagination__left">
          <form method="get" class="historico-page-size-form">
            <input type="hidden" name="desde" value="<?= $h($f_desde) ?>">
            <input type="hidden" name="hasta" value="<?= $h($f_hasta) ?>">
            <input type="hidden" name="fuente" value="<?= $h($f_fuente) ?>">
            <input type="hidden" name="buscar" value="<?= $h($f_busqueda) ?>">
            <input type="hidden" name="categoria" value="<?= $h($f_categoria) ?>">
            <input type="hidden" name="usuario" value="<?= $h($f_usuario) ?>">
            <input type="hidden" name="mensajes_scope" value="<?= $h($f_scope) ?>">
            <input type="hidden" name="page" value="1">
            <label for="historico-per-page" class="form-label mb-0">Mostrar</label>
            <select id="historico-per-page" name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
              <?php foreach ($perPageOptions as $option): ?>
                <option value="<?= $h($option) ?>" <?= $option === $perPage ? 'selected' : '' ?>><?= $h($option) ?></option>
              <?php endforeach; ?>
            </select>
            <span>registros</span>
          </form>
          <div class="historico-pagination__meta">
            <?= $h($rangeStart) ?>-<?= $h($rangeEnd) ?> de <?= $h($totalFiltered) ?> registros
          </div>
        </div>
        <?php if ($totalPages > 1): ?>
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $h($historicoPageUrl(1)) ?>" aria-label="Primera">&laquo;</a>
            </li>
            <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $h($historicoPageUrl($currentPage - 1)) ?>" aria-label="Anterior">Anterior</a>
            </li>
            <?php for ($page = $windowStart; $page <= $windowEnd; $page++): ?>
              <li class="page-item <?= $page === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="<?= $h($historicoPageUrl($page)) ?>"><?= $h($page) ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $h($historicoPageUrl($currentPage + 1)) ?>" aria-label="Siguiente">Siguiente</a>
            </li>
            <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $h($historicoPageUrl($totalPages)) ?>" aria-label="Última">&raquo;</a>
            </li>
          </ul>
        <?php endif; ?>
      </nav>
    </div>
  </div>


  <div class="modal fade rm-side-drawer" id="historicoDetalleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable rm-side-drawer-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Detalle histórico</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <div class="fw-semibold" id="historico-detalle-titulo"></div>
            <div class="text-muted small" id="historico-detalle-solicitante"></div>
          </div>
          <div id="historico-detalle-tabla-wrap" class="table-responsive border rounded">
            <table class="table table-sm mb-0 align-middle">
              <thead class="table-light" id="historico-detalle-head"></thead>
              <tbody id="historico-detalle-body"></tbody>
            </table>
          </div>
          <div id="historico-detalle-descripcion-wrap" class="d-none">
            <label for="historico-detalle-descripcion" class="form-label fw-semibold">Descripción</label>
            <textarea id="historico-detalle-descripcion" class="form-control" rows="10" readonly></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const form = document.getElementById('filter-form');
      const feedback = document.getElementById('filter-feedback');
      const table = document.querySelector('table[role=\"grid\"]');
      const loader = document.getElementById('table-loader');
      const btnApply = document.getElementById('btn-apply');
      const btnClear = document.getElementById('btn-clear');

      const setLoading = (state) => {
        if (feedback) feedback.classList.toggle('d-none', !state);
        if (loader) loader.classList.toggle('d-none', !state);
        if (table) table.setAttribute('aria-busy', state ? 'true' : 'false');
        if (btnApply) {
          btnApply.disabled = state;
          btnApply.setAttribute('aria-pressed', state ? 'true' : 'false');
        }
      };

      if (form) {
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          setLoading(true);
          setTimeout(() => form.submit(), 60);
        });
      }

      if (btnClear) {
        btnClear.addEventListener('click', function () {
          btnClear.setAttribute('aria-pressed', 'true');
        });
      }

      setLoading(false);

      const statusBadges = Array.from(document.querySelectorAll('.js-redmine-status[data-redmine-id]'));
      const syncPanel = document.getElementById('redmine-sync-panel');
      const syncBar = document.getElementById('redmine-sync-bar');
      const syncCount = document.getElementById('redmine-sync-count');
      const historicoTableCard = document.getElementById('historico-table-card');
      const historicoCompactToggle = document.getElementById('historico-compact-toggle');
      const historicoCompactKey = 'redmine-mantencion-historico-compact';

      if (historicoTableCard && historicoCompactToggle) {
        const savedCompact = localStorage.getItem(historicoCompactKey) === '1';
        historicoTableCard.classList.toggle('is-compact', savedCompact);
        historicoCompactToggle.checked = savedCompact;
        historicoCompactToggle.addEventListener('change', () => {
          const enabled = historicoCompactToggle.checked;
          historicoTableCard.classList.toggle('is-compact', enabled);
          localStorage.setItem(historicoCompactKey, enabled ? '1' : '0');
        });
      }

      const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#039;');
      const normalizeStatus = value => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
      const redmineStatusTone = statusName => {
        const key = normalizeStatus(statusName);
        if (key.includes('nueva') || key.includes('new')) return 'historico-redmine-status--new';
        if (key.includes('curso') || key.includes('progress') || key.includes('proceso')) return 'historico-redmine-status--progress';
        if (key.includes('resuelt') || key.includes('resolved')) return 'historico-redmine-status--resolved';
        if (key.includes('rechaz') || key.includes('reject')) return 'historico-redmine-status--rejected';
        return 'historico-redmine-status--open';
      };
      const setBadgeStatus = (badge, status) => {
        const available = Boolean(status && status.available);
        const closed = Boolean(status && status.closed);
        const statusName = String((status && status.name) || '');
        const message = String((status && status.message) || '');
        const cssClass = !available
          ? 'historico-redmine-status--unknown'
          : (closed ? 'historico-redmine-status--closed' : redmineStatusTone(statusName));
        const iconClass = !available ? 'bi-question-circle' : (closed ? 'bi-lock-fill' : 'bi-folder2-open');
        const label = !available ? 'No disponible' : (closed ? 'Cerrado' : 'Abierto');
        const detail = available && !closed && statusName ? `<small>${escapeHtml(statusName)}</small>` : '';

        badge.className = `historico-redmine-status js-redmine-status ${cssClass}`;
        badge.title = available ? `Redmine: ${statusName}` : message;
        badge.innerHTML = `<i class="bi ${iconClass}"></i><span>${escapeHtml(label)}</span>${detail}`;
      };

      const syncRedmineStatuses = async () => {
        const ids = [...new Set(statusBadges.map(badge => badge.getAttribute('data-redmine-id')).filter(Boolean))];
        if (!ids.length) return;

        const chunkSize = 5;
        const chunks = [];
        for (let index = 0; index < ids.length; index += chunkSize) {
          chunks.push(ids.slice(index, index + chunkSize));
        }

        let done = 0;
        if (syncPanel) syncPanel.classList.remove('d-none');
        if (syncCount) syncCount.textContent = `0/${ids.length}`;
        if (syncBar) syncBar.style.width = '0%';

        for (const chunk of chunks) {
          try {
            const response = await fetch(`historico.php?ajax=redmine_statuses&ids=${encodeURIComponent(chunk.join(','))}`, {
              headers: { 'Accept': 'application/json' },
              cache: 'no-store',
            });
            const payload = await response.json();
            const statuses = payload && payload.statuses ? payload.statuses : {};
            chunk.forEach(id => {
              document.querySelectorAll(`.js-redmine-status[data-redmine-id="${CSS.escape(id)}"]`).forEach(badge => {
                setBadgeStatus(badge, statuses[id] || { available: false, message: 'Sin respuesta desde Redmine' });
              });
            });
          } catch (error) {
            chunk.forEach(id => {
              document.querySelectorAll(`.js-redmine-status[data-redmine-id="${CSS.escape(id)}"]`).forEach(badge => {
                setBadgeStatus(badge, { available: false, message: 'No se pudo sincronizar con Redmine' });
              });
            });
          }

          done += chunk.length;
          const percent = Math.min(100, Math.round((done / ids.length) * 100));
          if (syncCount) syncCount.textContent = `${Math.min(done, ids.length)}/${ids.length}`;
          if (syncBar) syncBar.style.width = `${percent}%`;
        }

        if (syncPanel) {
          syncPanel.classList.add('historico-redmine-sync--done');
          setTimeout(() => syncPanel.classList.add('d-none'), 1200);
        }
      };

      syncRedmineStatuses();

      const historicoDetalleModal = document.getElementById('historicoDetalleModal');
      if (historicoDetalleModal) {
        historicoDetalleModal.addEventListener('show.bs.modal', function (event) {
          const triggerBtn = event.relatedTarget;
          if (!triggerBtn) return;

          const titleEl = document.getElementById('historico-detalle-titulo');
          const subtitleEl = document.getElementById('historico-detalle-solicitante');
          const tableWrap = document.getElementById('historico-detalle-tabla-wrap');
          const tableHead = document.getElementById('historico-detalle-head');
          const tableBody = document.getElementById('historico-detalle-body');
          const descriptionWrap = document.getElementById('historico-detalle-descripcion-wrap');
          const descriptionField = document.getElementById('historico-detalle-descripcion');

          const escapeHtml = value => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');

          let rows = [];
          let columns = [];
          try {
            rows = JSON.parse(triggerBtn.getAttribute('data-preview_rows') || '[]');
          } catch (error) {
            rows = [];
          }
          try {
            columns = JSON.parse(triggerBtn.getAttribute('data-preview_columns') || '[]');
          } catch (error) {
            columns = [];
          }

          const fuente = (triggerBtn.getAttribute('data-fuente') || '').trim().toLowerCase();
          const asunto = triggerBtn.getAttribute('data-asunto') || triggerBtn.getAttribute('data-core_tipo_solicitud') || 'Detalle histórico';
          const solicitante = triggerBtn.getAttribute('data-solicitante') || '';
          const descripcion = triggerBtn.getAttribute('data-descripcion') || '';

          if (titleEl) titleEl.textContent = asunto;
          if (subtitleEl) subtitleEl.textContent = solicitante ? `Solicitante: ${solicitante}` : '';

          if (fuente === 'manual') {
          if (tableWrap) tableWrap.classList.add('d-none');
          if (descriptionWrap) descriptionWrap.classList.remove('d-none');
          if (descriptionField) descriptionField.value = descripcion;
          return;
        }

          if (descriptionWrap) descriptionWrap.classList.add('d-none');
          if (tableWrap) tableWrap.classList.remove('d-none');
          if (descriptionField) descriptionField.value = '';

          if (!Array.isArray(columns) || columns.length === 0) {
            columns = [{ label: 'Detalle', key: 'detalle_nombre' }];
          }
          if (tableHead) {
            tableHead.innerHTML = `<tr>${columns.map(col => `<th>${escapeHtml(col.label || '')}</th>`).join('')}</tr>`;
          }
          if (tableBody) {
            if (!Array.isArray(rows) || rows.length === 0) {
              tableBody.innerHTML = `<tr><td colspan="${columns.length}" class="text-center text-muted py-4">Sin detalle para mostrar.</td></tr>`;
            } else {
              tableBody.innerHTML = rows.map(row => `
                <tr>
                  ${columns.map(col => `<td>${escapeHtml(row[col.key] || '')}</td>`).join('')}
                </tr>
              `).join('');
            }
          }
        });
      }
    });
  </script>
<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
</div> <!-- #page-content -->
</body>
</html>
