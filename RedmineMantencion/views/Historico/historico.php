<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/dashboard.php';
$historicoActionUrl = function_exists('url') ? url('/redmine-mantencion/app/historico') : legacy_app_url('app/historico');
require_once __DIR__ . '/../../controllers/storage.php';
require_once __DIR__ . '/../../controllers/maintenance.php';
if (!auth_can('historico')) {
  header('Location: ' . legacy_app_url());
  exit;
}

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$maintenanceMode = maintenance_mode_enabled();
$csrf = legacy_csrf_token();

// --- Helpers para eliminar registros ---
function delete_reporte(string $base, string $id): bool {
  $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
  if ($repo !== null && $repo->tableReady()) {
    $repo->deleteByFuenteIds([$id]);
    return true;
  }
  return false;
}

function delete_horas_extra(string $base, string $id): bool {
  $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;
  return $repo !== null && $repo->detachMessageId($id);
}

// --- Eliminar si se solicito ---
$alert = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'], $_POST['id'], $_POST['fuente']) && $_POST['action'] === 'delete') {
  if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
  if (function_exists('csrf_validate')) csrf_validate();
  if ($maintenanceMode || !auth_can('historico_eliminar')) {
    http_response_code(403);
    exit('No tienes permiso para eliminar registros del histórico.');
  }
  $id = trim($_POST['id']);
  $src = $_POST['fuente'];
  $ok = false;
  $deleteCanAct = true;
  $deleteScope = 'asignados';
  $deleteUserId = (string)auth_get_user_id();
  $deleteUserNames = array_values(array_filter([
    trim((string)($_SESSION['user']['nombre'] ?? '')),
    trim((string)((auth_find_user_by_id($deleteUserId)['nombre'] ?? ''))),
  ], fn($value) => $value !== ''));
  $sourceRows = $src === 'reportes'
    ? load_reportes('')
    : ($src === 'horas_extra' ? load_horas_extras('') : []);
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
      $ok = delete_reporte('', $id);
    } elseif ($src === 'horas_extra') {
      $ok = delete_horas_extra('', $id);
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
  return historico_redmine_status_service()->issueUrl($platformUrl, $redmineId);
}

function historico_redmine_issue_api_url(string $platformUrl, string $redmineId): string {
  return historico_redmine_status_service()->issueApiUrl($platformUrl, $redmineId);
}

function historico_redmine_is_closed_status(string $statusName): bool {
  return historico_redmine_status_service()->isClosedStatus($statusName);
}

function historico_redmine_status_service(): \App\Modulos\RedmineMantencion\Services\RedmineIssueStatusService {
  static $service = null;
  if (!$service instanceof \App\Modulos\RedmineMantencion\Services\RedmineIssueStatusService) {
    $service = app(\App\Modulos\RedmineMantencion\Services\RedmineIssueStatusService::class);
  }
  return $service;
}

function historico_fetch_redmine_status(string $platformUrl, string $redmineId, string $token): array {
  static $cache = [];

  $redmineId = trim($redmineId);
  $cacheKey = $platformUrl . '|' . $redmineId . '|' . ($token !== '' ? 'token' : 'public');
  if (isset($cache[$cacheKey])) {
    return $cache[$cacheKey];
  }

  return $cache[$cacheKey] = historico_redmine_status_service()->fetchStatus($platformUrl, $redmineId, $token);
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
  $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
  if ($repo !== null && $repo->tableReady()) {
    return array_map(static function (array $row): array {
      $row['_fuente'] = 'reportes';
      return $row;
    }, $repo->archivedMessages());
  }
  return [];
}

function load_horas_extras(string $base): array {
  $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;
  return $repo !== null ? $repo->messages() : [];
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

function historico_dedupe_key(array $row): string {
  $redmineId = preg_replace('/\D+/', '', trim((string)($row['redmine_id'] ?? $row['numero_ticket_redmine'] ?? '')));
  if ($redmineId !== '') {
    return 'redmine:' . $redmineId;
  }

  $fuenteId = trim((string)($row['fuente_id'] ?? $row['id'] ?? ''));
  if ($fuenteId !== '') {
    return 'fuente:' . $fuenteId;
  }

  return 'row:' . md5(json_encode([
    $row['fecha'] ?? '',
    $row['solicitante'] ?? '',
    $row['asunto'] ?? $row['mensaje'] ?? '',
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function historico_dedupe_rows(array $rows): array {
  $deduped = [];
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    $key = historico_dedupe_key($row);
    if (!isset($deduped[$key])) {
      $deduped[$key] = $row;
      continue;
    }

    $currentSource = (string)($deduped[$key]['_fuente'] ?? '');
    $candidateSource = (string)($row['_fuente'] ?? '');
    if ($currentSource === 'horas_extra' && $candidateSource === 'reportes') {
      $deduped[$key] = $row;
    }
  }

  return array_values($deduped);
}

$f_desde     = norm_date($_GET['desde'] ?? '');
$f_hasta     = norm_date($_GET['hasta'] ?? '');
$f_usuario   = trim($_GET['usuario'] ?? '');
$f_categoria = strtolower(trim($_GET['categoria'] ?? ''));
$f_fuente    = $_GET['fuente'] ?? '';
$f_busqueda  = trim($_GET['buscar'] ?? '');
$f_descripcion = trim($_GET['descripcion'] ?? '');
$perPageOptions = [25, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, $perPageOptions, true)) {
  $perPage = 25;
}
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$scopePermitido = 'asignados';
$scopeBloqueado = ($scopePermitido === 'asignados');
$canChangeHistoryStatus = !$maintenanceMode && auth_can('historico_estado');
$canDeleteHistory = !$maintenanceMode && auth_can('historico_eliminar');
$showActions = $canChangeHistoryStatus || $canDeleteHistory;
$tableColspan = 9 + ($canChangeHistoryStatus ? 1 : 0) + ($showActions ? 1 : 0);
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
$redmineStatusService = historico_redmine_status_service();
$redmineStatusOptions = $redmineStatusService->statusOptions();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_redmine_status') {
  if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
  if (function_exists('csrf_validate')) csrf_validate();

  $ok = false;
  $statusId = (int)($_POST['status_id'] ?? 0);
  $statusName = $redmineStatusService->statusName($statusId);
  $requestedIds = is_array($_POST['redmine_ids'] ?? null)
    ? $_POST['redmine_ids']
    : explode(',', (string)($_POST['redmine_ids'] ?? ''));
  $requestedIds = array_slice(array_values(array_unique(array_filter(array_map(
    static function ($id): string {
      $id = trim((string)$id);
      return preg_match('/^\d+$/', $id) ? $id : '';
    },
    $requestedIds
  )))), 0, 100);

  if (!$canChangeHistoryStatus) {
    http_response_code(403);
    $alert = 'No tienes permiso para cambiar estados desde el histórico.';
  } elseif ($statusName === null) {
    $alert = 'Selecciona un estado Redmine válido.';
  } elseif ($requestedIds === []) {
    $alert = 'Selecciona al menos un reporte abierto.';
  } elseif (trim($redmineToken) === '') {
    $alert = 'Configura tu API Key personal de Redmine en Mis integraciones antes de cambiar estados.';
  } else {
    $reportRepo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
    $allowedTickets = [];
    foreach ($reportRepo?->archivedMessages() ?? [] as $archivedRow) {
      if (!is_array($archivedRow) || !historico_record_matches_current_user($archivedRow, $userId, $userNames)) {
        continue;
      }
      $ticketId = trim((string)($archivedRow['redmine_id'] ?? ''));
      if (preg_match('/^\d+$/', $ticketId)) {
        $allowedTickets[$ticketId] = true;
      }
    }

    $updated = 0;
    $errors = [];
    foreach ($requestedIds as $ticketId) {
      if (!isset($allowedTickets[$ticketId])) {
        $errors[] = '#' . $ticketId . ': no pertenece a tu histórico disponible.';
        continue;
      }

      $currentStatus = historico_fetch_redmine_status($redminePlatformUrl, $ticketId, $redmineToken);
      if (!($currentStatus['available'] ?? false)) {
        $errors[] = '#' . $ticketId . ': no se pudo confirmar el estado actual.';
        continue;
      }
      if ($currentStatus['closed'] ?? false) {
        $errors[] = '#' . $ticketId . ': ya está cerrado en Redmine.';
        continue;
      }

      $result = $redmineStatusService->updateStatus($redminePlatformUrl, $ticketId, $statusId, $redmineToken);
      if (!($result['ok'] ?? false)) {
        $errors[] = '#' . $ticketId . ': ' . trim((string)($result['error'] ?? 'no se pudo actualizar.'));
        continue;
      }

      $reportRepo?->updateRedmineStatus($ticketId, $statusId, $statusName);
      $updated++;
    }

    $ok = $updated > 0;
    $alert = $updated . ' reporte(s) actualizado(s) a “' . $statusName . '” en Redmine.';
    if ($errors !== []) {
      $alert .= ' No actualizados: ' . implode(' ', array_slice($errors, 0, 5));
      if (count($errors) > 5) {
        $alert .= ' y ' . (count($errors) - 5) . ' más.';
      }
    }

    dashboard_log_action(
      'REDMINE_STATUS_UPDATE',
      'Estado "' . $statusName . '" solicitado para ' . count($requestedIds)
        . ' ticket(s); actualizados=' . $updated . '; fallidos=' . count($errors)
    );
  }
}

if (($_GET['ajax'] ?? '') === 'redmine_statuses') {
  header('Content-Type: application/json; charset=utf-8');
  $ids = array_values(array_unique(array_filter(array_map(
    static function ($id): string {
      $id = trim((string)$id);
      return preg_match('/^\d+$/', $id) ? $id : '';
    },
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
$items  = array_merge($items, load_horas_extras(''));

$filtered = [];
foreach ($items as $row) {
  if (!is_array($row)) continue;
  if (!in_array(strtolower(trim((string)($row['estado'] ?? ''))), ['procesado', 'archivado'], true)) continue;
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
  if ($f_descripcion !== '') {
    $descriptionNeedle = dashboard_normalize_text($f_descripcion);
    $descriptionText = dashboard_normalize_text((string)($row['descripcion'] ?? ''));
    if ($descriptionNeedle !== '' && !str_contains($descriptionText, $descriptionNeedle)) continue;
  }
  $row['_fecha_norm'] = $fecha;
  $filtered[] = $row;
}

if ($f_fuente === '') {
  $filtered = historico_dedupe_rows($filtered);
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
$visibleRows = count($pagedRows);
$historicoFilterChips = [];
if ($f_desde !== '') $historicoFilterChips[] = ['icon' => 'bi-calendar-event', 'label' => 'Desde ' . historico_format_date($f_desde), 'remove' => 'desde'];
if ($f_hasta !== '') $historicoFilterChips[] = ['icon' => 'bi-calendar-check', 'label' => 'Hasta ' . historico_format_date($f_hasta), 'remove' => 'hasta'];
if ($f_fuente !== '') $historicoFilterChips[] = ['icon' => 'bi-inboxes', 'label' => 'Fuente ' . $f_fuente, 'remove' => 'fuente'];
if ($f_busqueda !== '') $historicoFilterChips[] = ['icon' => 'bi-search', 'label' => 'Busqueda ' . $f_busqueda, 'remove' => 'buscar'];
if ($f_descripcion !== '') $historicoFilterChips[] = ['icon' => 'bi-card-text', 'label' => 'Descripción ' . $f_descripcion, 'remove' => 'descripcion'];
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
  <?php $historicoCssVersion = @filemtime(__DIR__ . '/../../assets/css/historico.css') ?: time(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/historico.css?v=<?= (int)$historicoCssVersion ?>">
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
    <div data-nova-flash="<?= $ok ? 'success' : 'warning' ?>" data-nova-flash-message="<?= $h($alert) ?>" hidden></div>
  <?php endif; ?>

  <form id="filter-form" class="card card-body shadow-sm mb-3 historico-filter-card" method="get" aria-live="polite">
    <div class="row g-3 align-items-end">
      <?php
        $filterFields = [
          ['label' => 'Desde', 'name' => 'desde', 'type' => 'date', 'value' => $f_desde, 'col' => 2, 'aria_label' => 'Fecha desde'],
          ['label' => 'Hasta', 'name' => 'hasta', 'type' => 'date', 'value' => $f_hasta, 'col' => 2, 'aria_label' => 'Fecha hasta'],
          ['label' => 'Fuente', 'name' => 'fuente', 'type' => 'select', 'options' => ['' => 'Todas', 'reportes' => 'Reportes', 'horas_extra' => 'Horas extra'], 'value' => $f_fuente, 'col' => 2],
        ];
        if (!$scopeBloqueado) {
          $filterFields[] = [
            'label' => 'Asignado',
            'name' => 'usuario',
            'type' => 'select',
            'options' => ['' => 'Todos'] + $usuariosSel,
            'value' => $f_usuario,
            'col' => 3,
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
        $filterFields[] = [
          'label' => 'Buscar solicitante / nombre / rut',
          'name' => 'buscar',
          'type' => 'text',
          'value' => $f_busqueda,
          'col' => 4,
          'aria_label' => 'Buscar por solicitante, nombre o rut',
        ];
        $filterFields[] = [
          'label' => 'Buscar en descripción',
          'name' => 'descripcion',
          'type' => 'text',
          'value' => $f_descripcion,
          'col' => 4,
          'aria_label' => 'Buscar texto en la descripción de los reportes',
        ];
      ?>
      <?php foreach ($filterFields as $field): ?>
        <?php include __DIR__ . '/../partials/filter-field.php'; ?>
      <?php endforeach; ?>
      <div class="col-md-2">
        <button
          type="submit"
          id="btn-apply"
          class="btn-nova btn-nova-primary w-100"
          data-bs-spinner="true"
          aria-label="Aplicar filtros"
          aria-pressed="false">
          <i class="bi bi-funnel"></i> Filtrar
        </button>
      </div>
      <div class="col-md-2">
        <a
          class="btn-nova btn-nova-secondary w-100"
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
        <span class="text-muted ms-2">Mostrando <?= $h($visibleRows) ?> de <?= $h($totalFiltered) ?> registros</span>
      </div>
      <div class="historico-summary__tools">
        <?php if ($canChangeHistoryStatus): ?>
          <div class="dropdown historico-bulk-status">
            <button
              type="button"
              class="btn-nova btn-nova-primary historico-bulk-status__button dropdown-toggle"
              id="historico-bulk-status-button"
              data-bs-toggle="dropdown"
              aria-expanded="false"
              disabled>
              <i class="bi bi-kanban"></i>
              Cambiar estado
              <span class="historico-selection-count" id="historico-selection-count">0</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end historico-status-menu" aria-labelledby="historico-bulk-status-button">
              <li class="dropdown-header">Aplicar a seleccionados</li>
              <?php foreach ($redmineStatusOptions as $statusId => $statusLabel): ?>
                <li>
                  <button
                    type="button"
                    class="dropdown-item js-bulk-status-choice"
                    data-status-id="<?= $h($statusId) ?>"
                    data-status-label="<?= $h($statusLabel) ?>">
                    <span class="historico-status-dot is-status-<?= $h($statusId) ?>"></span>
                    <?= $h($statusLabel) ?>
                  </button>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <form method="post" action="<?= $h($historicoActionUrl) ?>" id="historico-bulk-status-form" class="d-none">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="update_redmine_status">
            <input type="hidden" name="redmine_ids" id="historico-bulk-redmine-ids" value="">
            <input type="hidden" name="status_id" id="historico-bulk-status-id" value="">
          </form>
        <?php endif; ?>
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
          <colgroup>
            <?php if ($canChangeHistoryStatus): ?><col class="historico-col-select"><?php endif; ?>
            <col class="historico-col-date">
            <col class="historico-col-id">
            <col class="historico-col-redmine-status">
            <col class="historico-col-requester">
            <col class="historico-col-category">
            <col class="historico-col-department">
            <col class="historico-col-subject">
            <col class="historico-col-source">
            <col class="historico-col-detail">
            <?php if ($showActions): ?><col class="historico-col-actions"><?php endif; ?>
          </colgroup>
          <thead class="table-light">
            <tr class="position-sticky top-0 bg-light">
              <?php if ($canChangeHistoryStatus): ?>
                <th scope="col" class="historico-select-cell">
                  <input
                    class="form-check-input js-history-select-all"
                    type="checkbox"
                    id="historico-select-all"
                    aria-label="Seleccionar todos los reportes abiertos"
                    disabled>
                </th>
              <?php endif; ?>
              <th scope="col">Fecha</th>
              <th scope="col">Redmine ID</th>
              <th scope="col">Estado Redmine</th>
              <th scope="col" class="text-truncate" style="max-width: 160px;">Solicitante</th>
              <th scope="col" class="text-truncate" style="max-width: 220px;">Categoría</th>
              <th scope="col" class="text-truncate" style="max-width: 140px;">Departamento</th>
              <th scope="col">Asunto</th>
              <th scope="col">Fuente</th>
              <th scope="col">Detalle</th>
              <?php if ($showActions): ?>
                <th scope="col" class="historico-actions-cell">Acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($pagedRows)): ?>
              <tr><td colspan="<?= $tableColspan ?>" class="nova-empty"><i class="bi bi-archive" style="font-size:1.5rem;display:block;margin-bottom:.4rem;opacity:.35"></i>Sin registros para el criterio seleccionado.</td></tr>
            <?php else: ?>
              <?php foreach ($pagedRows as $row): ?>
                <?php
                  $previewRows = dashboard_detail_preview_rows($row);
                  $previewRowsJson = $h((string)json_encode(array_values($previewRows), JSON_UNESCAPED_UNICODE));
                  $previewColumnsJson = $h((string)json_encode(dashboard_core_detail_table_schema($row), JSON_UNESCAPED_UNICODE));
                  // La descripción también contiene los datos completos usados al
                  // generar reportes CORE. No limitarla a registros manuales.
                  $detalleDescripcion = trim((string)($row['descripcion'] ?? ''));
                  $isManual = strtolower(trim((string)($row['fuente'] ?? ''))) === 'manual';
                  $sourceLabel = $isManual ? 'Manual' : 'CORE';
                  $sourceIcon = $isManual ? 'bi-pencil-square' : 'bi-cloud-arrow-down';
                  $isHoraExtra = normalize_hour_extra_value($row['hora_extra'] ?? '') === '1';
                  $redmineId = trim((string)($row['redmine_id'] ?? ''));
                  $redmineIssueUrl = historico_redmine_issue_url($redminePlatformUrl, $redmineId);
                ?>
                <tr data-redmine-row="<?= $h($redmineId) ?>">
                  <?php if ($canChangeHistoryStatus): ?>
                    <td class="historico-select-cell">
                      <?php if ($redmineId !== ''): ?>
                        <input
                          class="form-check-input js-history-select"
                          type="checkbox"
                          value="<?= $h($redmineId) ?>"
                          data-redmine-id="<?= $h($redmineId) ?>"
                          aria-label="Seleccionar ticket Redmine <?= $h($redmineId) ?>"
                          disabled>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
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
                  <td class="text-truncate" style="max-width: 140px;" title="<?= $h($row['core_departamento'] ?? ($row['unidad'] ?? '')) ?>"><?= $h($row['core_departamento'] ?? ($row['unidad'] ?? '')) ?></td>
                  <td class="text-truncate" title="<?= $h($row['asunto'] ?? '') ?>"><?= $h($row['asunto'] ?? '-') ?></td>
                  <td>
                    <span class="historico-source-badge <?= $isManual ? 'is-manual' : 'is-core' ?>" title="<?= $h($isManual ? 'Creado manualmente' : 'Importado desde CORE') ?>">
                      <i class="bi <?= $h($sourceIcon) ?>"></i>
                      <?= $h($sourceLabel) ?>
                      <?php if ($isHoraExtra): ?>
                        <span class="historico-overtime-icon" title="Hora extra" aria-label="Hora extra"><i class="bi bi-clock-fill"></i></span>
                      <?php endif; ?>
                    </span>
                  </td>
                  <td>
                    <button
                      type="button"
                      class="btn-action btn-action-view historico-detail-btn"
                      data-bs-toggle="modal"
                      data-bs-target="#historicoDetalleModal"
                      data-preview_rows="<?= $previewRowsJson ?>"
                      data-preview_columns="<?= $previewColumnsJson ?>"
                      data-fuente="<?= $h($row['fuente'] ?? '') ?>"
                      data-core_tipo_solicitud="<?= $h($row['core_tipo_solicitud'] ?? '') ?>"
                      data-asunto="<?= $h($row['asunto'] ?? '') ?>"
                      data-solicitante="<?= $h($row['solicitante'] ?? '') ?>"
                      data-descripcion="<?= $h($detalleDescripcion) ?>"
                      data-fecha="<?= $h(historico_format_date($row['_fecha_norm'] ?? '')) ?>"
                      data-redmine-id="<?= $h($redmineId) ?>"
                      data-estado-redmine="<?= $h($row['estado_redmine'] ?? $row['redmine_estado'] ?? $row['status_name'] ?? '') ?>"
                      data-categoria="<?= $h($row['categoria'] ?? '') ?>"
                      data-establecimiento="<?= $h($row['core_establecimiento'] ?? ($row['unidad_solicitante'] ?? '')) ?>"
                      data-departamento="<?= $h($row['core_departamento'] ?? ($row['unidad'] ?? '')) ?>"
                      data-asignado="<?= $h($row['core_usuario_asignado'] ?? ($row['asignado_nombre'] ?? ($row['asignado_a'] ?? ''))) ?>"
                      data-fuente-label="<?= $h($sourceLabel) ?>"
                      data-hora-extra="<?= $isHoraExtra ? 'Sí' : 'No' ?>"
                      data-prioridad="<?= $h($row['prioridad'] ?? '') ?>"
                      data-tipo="<?= $h($row['tipo'] ?? $row['core_tipo_solicitud'] ?? '') ?>"
                      data-correo="<?= $h($row['core_email'] ?? $row['correo'] ?? '') ?>"
                      data-anexo="<?= $h($row['anexo'] ?? $row['core_telefono'] ?? '') ?>"
                      data-tiempo-estimado="<?= $h($row['tiempo_estimado'] ?? '') ?>"
                      data-fecha-inicio="<?= $h(historico_format_date($row['fecha_inicio'] ?? '')) ?>"
                      data-fecha-fin="<?= $h(historico_format_date($row['fecha_fin'] ?? '')) ?>"
                      title="Ver detalle"
                      aria-label="Ver detalle"
                    >
                      <i class="bi bi-eye"></i>
                    </button>
                  </td>
                  <?php if ($showActions): ?>
                    <td class="historico-actions-cell">
                      <div class="historico-row-actions">
                        <?php if ($canChangeHistoryStatus && $redmineId !== ''): ?>
                          <div class="dropdown">
                            <button
                              type="button"
                              class="btn-action btn-action-sync dropdown-toggle no-caret js-redmine-status-menu d-none"
                              data-redmine-id="<?= $h($redmineId) ?>"
                              data-bs-toggle="dropdown"
                              data-bs-boundary="viewport"
                              aria-expanded="false"
                              title="Cambiar estado en Redmine"
                              aria-label="Cambiar estado del ticket <?= $h($redmineId) ?>">
                              <i class="bi bi-arrow-repeat"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end historico-status-menu">
                              <li class="dropdown-header">Cambiar estado #<?= $h($redmineId) ?></li>
                              <?php foreach ($redmineStatusOptions as $statusId => $statusLabel): ?>
                                <li>
                                  <form
                                    method="post"
                                    class="m-0"
                                    data-app-confirm="¿Cambiar el ticket #<?= $h($redmineId) ?> a <?= $h($statusLabel) ?>?"
                                    data-app-confirm-title="Cambiar estado en Redmine"
                                    data-app-confirm-tone="info"
                                    data-app-confirm-text="Cambiar estado">
                                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                                    <input type="hidden" name="action" value="update_redmine_status">
                                    <input type="hidden" name="redmine_ids" value="<?= $h($redmineId) ?>">
                                    <input type="hidden" name="status_id" value="<?= $h($statusId) ?>">
                                    <button type="submit" class="dropdown-item">
                                      <span class="historico-status-dot is-status-<?= $h($statusId) ?>"></span>
                                      <?= $h($statusLabel) ?>
                                    </button>
                                  </form>
                                </li>
                              <?php endforeach; ?>
                            </ul>
                          </div>
                        <?php endif; ?>
                        <?php if ($canDeleteHistory): ?>
                          <form method="post" action="<?= $h($historicoActionUrl) ?>" class="m-0" data-app-confirm="Eliminar este registro del histórico?">
                            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $h($row['id'] ?? '') ?>">
                            <input type="hidden" name="fuente" value="<?= $h($row['_fuente'] ?? '') ?>">
                            <button type="submit" class="btn-action btn-action-delete" title="Eliminar" aria-label="Eliminar">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
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
            <input type="hidden" name="descripcion" value="<?= $h($f_descripcion) ?>">
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
            Mostrando <?= $h($visibleRows) ?> de <?= $h($totalFiltered) ?> registros
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


  <div class="modal fade rm-side-drawer detail-drawer-modal" id="historicoDetalleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable rm-side-drawer-dialog detail-drawer-dialog">
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
          <dl class="historico-detail-facts" id="historico-detail-facts">
            <div><dt><i class="bi bi-calendar3"></i>Fecha</dt><dd data-history-fact="fecha">-</dd></div>
            <div><dt><i class="bi bi-box-arrow-up-right"></i>Redmine ID</dt><dd data-history-fact="redmineId">-</dd></div>
            <div><dt><i class="bi bi-folder2-open"></i>Estado Redmine</dt><dd data-history-fact="estadoRedmine">-</dd></div>
            <div><dt><i class="bi bi-cloud-arrow-down"></i>Fuente</dt><dd data-history-fact="fuenteLabel">-</dd></div>
            <div><dt><i class="bi bi-ticket-perforated"></i>Tipo</dt><dd data-history-fact="tipo">-</dd></div>
            <div><dt><i class="bi bi-flag"></i>Prioridad</dt><dd data-history-fact="prioridad">-</dd></div>
            <div><dt><i class="bi bi-tags"></i>Categoría</dt><dd data-history-fact="categoria">-</dd></div>
            <div><dt><i class="bi bi-person-check"></i>Asignado a</dt><dd data-history-fact="asignado">-</dd></div>
            <div><dt><i class="bi bi-building"></i>Establecimiento</dt><dd data-history-fact="establecimiento">-</dd></div>
            <div><dt><i class="bi bi-diagram-3"></i>Departamento</dt><dd data-history-fact="departamento">-</dd></div>
            <div><dt><i class="bi bi-calendar-event"></i>Fecha inicio</dt><dd data-history-fact="fechaInicio">-</dd></div>
            <div><dt><i class="bi bi-calendar-check"></i>Fecha fin</dt><dd data-history-fact="fechaFin">-</dd></div>
            <div><dt><i class="bi bi-clock-history"></i>Hora extra</dt><dd data-history-fact="horaExtra">-</dd></div>
            <div><dt><i class="bi bi-hourglass-split"></i>Tiempo estimado</dt><dd data-history-fact="tiempoEstimado">-</dd></div>
            <div><dt><i class="bi bi-envelope"></i>Correo</dt><dd data-history-fact="correo">-</dd></div>
            <div><dt><i class="bi bi-telephone"></i>Anexo</dt><dd data-history-fact="anexo">-</dd></div>
          </dl>
          <div id="historico-detalle-tabla-wrap" class="table-responsive border rounded">
            <table class="table table-sm mb-0 align-middle">
              <thead class="table-light" id="historico-detalle-head"></thead>
              <tbody id="historico-detalle-body"></tbody>
            </table>
          </div>
          <div id="historico-detalle-descripcion-wrap" class="d-none">
            <div class="form-label fw-semibold"><i class="bi bi-table me-2"></i>Descripción / datos del reporte</div>
            <div id="historico-detalle-descripcion" class="nova-description-preview historico-description-preview"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
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
      const selectAll = document.getElementById('historico-select-all');
      const rowCheckboxes = Array.from(document.querySelectorAll('.js-history-select[data-redmine-id]'));
      const bulkStatusButton = document.getElementById('historico-bulk-status-button');
      const selectionCount = document.getElementById('historico-selection-count');
      const bulkStatusForm = document.getElementById('historico-bulk-status-form');
      const bulkRedmineIds = document.getElementById('historico-bulk-redmine-ids');
      const bulkStatusId = document.getElementById('historico-bulk-status-id');

      if (window.bootstrap?.Dropdown) {
        document.querySelectorAll('.js-redmine-status-menu').forEach(trigger => {
          const dropdown = trigger.closest('.dropdown');
          const menu = dropdown?.querySelector('.historico-status-menu');
          if (!dropdown || !menu) return;

          trigger.addEventListener('show.bs.dropdown', () => {
            menu.classList.add('is-portal');
            document.body.appendChild(menu);
          });
          trigger.addEventListener('hidden.bs.dropdown', () => {
            menu.classList.remove('is-portal');
            dropdown.appendChild(menu);
          });

          window.bootstrap.Dropdown.getOrCreateInstance(trigger, {
            boundary: 'viewport',
            popperConfig(defaultConfig) {
              return { ...defaultConfig, strategy: 'fixed' };
            },
          });
        });
      }

      const selectedOpenIds = () => [...new Set(
        rowCheckboxes
          .filter(checkbox => !checkbox.disabled && checkbox.checked)
          .map(checkbox => checkbox.value)
          .filter(Boolean)
      )];
      const refreshSelectionState = () => {
        const enabled = rowCheckboxes.filter(checkbox => !checkbox.disabled);
        const selected = selectedOpenIds();
        if (selectionCount) selectionCount.textContent = String(selected.length);
        if (bulkStatusButton) bulkStatusButton.disabled = selected.length === 0;
        if (selectAll) {
          selectAll.disabled = enabled.length === 0;
          selectAll.checked = enabled.length > 0 && enabled.every(checkbox => checkbox.checked);
          selectAll.indeterminate = selected.length > 0 && selected.length < enabled.length;
        }
      };

      rowCheckboxes.forEach(checkbox => checkbox.addEventListener('change', refreshSelectionState));
      selectAll?.addEventListener('change', () => {
        rowCheckboxes.forEach(checkbox => {
          if (!checkbox.disabled) checkbox.checked = selectAll.checked;
        });
        refreshSelectionState();
      });
      document.querySelectorAll('.js-bulk-status-choice').forEach(choice => {
        choice.addEventListener('click', () => {
          const ids = selectedOpenIds();
          const statusId = choice.getAttribute('data-status-id') || '';
          const statusLabel = choice.getAttribute('data-status-label') || '';
          if (!ids.length || !statusId || !bulkStatusForm || !bulkRedmineIds || !bulkStatusId) return;
          if (!window.confirm(`¿Cambiar ${ids.length} ticket(s) seleccionado(s) a “${statusLabel}”?`)) return;
          bulkRedmineIds.value = ids.join(',');
          bulkStatusId.value = statusId;
          bulkStatusForm.submit();
        });
      });

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

        const redmineId = badge.getAttribute('data-redmine-id') || '';
        if (redmineId) {
          const open = available && !closed;
          document.querySelectorAll(`.js-history-select[data-redmine-id="${CSS.escape(redmineId)}"]`).forEach(checkbox => {
            checkbox.disabled = !open;
            if (!open) checkbox.checked = false;
          });
          document.querySelectorAll(`.js-redmine-status-menu[data-redmine-id="${CSS.escape(redmineId)}"]`).forEach(menu => {
            menu.classList.toggle('d-none', !open);
          });
          refreshSelectionState();
        }
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

          document.querySelectorAll('#historico-detail-facts [data-history-fact]').forEach(field => {
            const key = field.dataset.historyFact || '';
            const value = triggerBtn.dataset[key] || '';
            field.textContent = value.trim() || '-';
          });

          const renderDescription = () => {
            if (!descriptionField) return;
            if (window.NovaDescriptionTables?.render) {
              window.NovaDescriptionTables.render({ value: descripcion }, descriptionField);
              return;
            }
            descriptionField.textContent = descripcion || 'Sin descripción.';
          };

          if (fuente === 'manual') {
            if (tableWrap) tableWrap.classList.add('d-none');
            if (descriptionWrap) descriptionWrap.classList.remove('d-none');
            renderDescription();
            return;
          }

          // Los reportes CORE archivados conservan en `descripcion` campos que no
          // tienen columnas propias (motivo, permisos, teléfono, correo, etc.).
          // Mostrar ese bloque junto con la vista tabular evita perderlos.
          if (descriptionWrap) descriptionWrap.classList.toggle('d-none', descripcion.trim() === '');
          if (tableWrap) tableWrap.classList.remove('d-none');
          renderDescription();

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
<button id="historico-scroll-top" type="button" title="Volver arriba" aria-label="Volver arriba" class="btn btn-primary nova-scroll-top">
    <i class="bi bi-arrow-up"></i>
</button>
</div> <!-- #page-content -->
</body>
</html>
