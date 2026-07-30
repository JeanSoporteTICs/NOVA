<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
if (!auth_can('horas_extra')) {
    http_response_code(403);
    exit('No tienes permiso para ver Horas extra.');
}
require_once __DIR__ . '/../../controllers/dashboard.php';
require_once __DIR__ . '/../../controllers/maintenance.php';
$maintenanceMode = maintenance_mode_enabled();
$canEditHours = auth_can('horas_extra_editar');

function load_hours_extra_all(): array {
    $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;
    return $repo !== null ? $repo->groups() : [];
}

$activeNav = 'horas';
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = legacy_csrf_token();
setlocale(LC_TIME, 'es_CL.UTF-8', 'es_ES.UTF-8', 'es_ES', 'Spanish');

$today = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
$mesActual = $today->format('n');
$selMes = array_key_exists('mes', $_GET) ? trim($_GET['mes']) : $mesActual;
$anioActual = $today->format('Y');
$selAnio = array_key_exists('anio', $_GET) ? trim($_GET['anio']) : $anioActual;
$flash = null;
$meses = [
    1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
    7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'
];
$dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];

function normalize_date_key($fecha) {
    $fecha = trim((string)$fecha);
    if ($fecha === '') return '';
    $fmts = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'];
    foreach ($fmts as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $fecha);
        if ($dt instanceof DateTime) return $dt->format('Y-m-d');
    }
}

function deduplicate_groups_by_shared_date(array $groups): array {
    $out = [];
    foreach ($groups as $group) {
        if (!is_array($group) || empty($group['reports']) || !is_array($group['reports'])) {
            continue;
        }
        $groupFecha = normalize_date_key($group['fecha'] ?? '');
        if ($groupFecha === '') {
            continue;
        }
        foreach ($group['reports'] as $report) {
            if (!is_array($report)) {
                continue;
            }
            $startDate = $groupFecha;
            if ($startDate === '') {
                continue;
            }
            if (!isset($out[$startDate])) {
                $out[$startDate] = [
                    'fecha' => $startDate,
                    'hora_inicio' => $group['hora_inicio'] ?? '',
                    'hora_fin' => $group['hora_fin'] ?? '',
                    'reports' => [],
                    '__order' => [],
                ];
            }
            if (!empty($group['hora_inicio'])) {
                $out[$startDate]['hora_inicio'] = $group['hora_inicio'];
            }
            if (!empty($group['hora_fin'])) {
                $out[$startDate]['hora_fin'] = $group['hora_fin'];
            }
            $reportKey = $report['id'] ?? null;
            if ($reportKey === null) {
                $reportKey = ($report['numero'] ?? '') . '|' . ($report['hora'] ?? '') . '|' . ($report['asunto'] ?? '');
            }
            if ($reportKey === '') {
                continue;
            }
            if (!isset($out[$startDate]['reports'][$reportKey])) {
                $out[$startDate]['reports'][$reportKey] = $report;
                $out[$startDate]['__order'][] = $reportKey;
                continue;
            }
            foreach ($report as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $out[$startDate]['reports'][$reportKey][$key] = $value;
            }
        }
    }
    foreach ($out as &$entry) {
        $reports = [];
        foreach ($entry['__order'] as $key) {
            if (isset($entry['reports'][$key])) {
                $reports[] = $entry['reports'][$key];
            }
        }
        $entry['reports'] = $reports;
        unset($entry['__order']);
    }
    unset($entry);
    return array_values($out);
}

function filter_hours_groups_for_user(array $groups, string $userId): array {
    if ($userId === '') {
        return [];
    }

    return array_values(array_filter(array_map(static function ($group) use ($userId) {
        if (!is_array($group)) return null;
        $group['reports'] = array_values(array_filter(
            (array)($group['reports'] ?? []),
            static fn($report) => is_array($report) && (string)($report['asignado_a'] ?? '') === $userId
        ));

        return $group['reports'] !== [] ? $group : null;
    }, $groups), static fn($group) => $group !== null));
}

function sanitize_time_value($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
        $hh = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $mm = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        if (!isset($m[3]) || $m[3] === '') {
            return "$hh:$mm";
        }
        $ss = str_pad($m[3], 2, '0', STR_PAD_LEFT);
        return "$hh:$mm:$ss";
    }
    return $value;
}

function update_hours_by_date($fecha, $horaIni, $horaFin) {
    if ($fecha === '') return false;
    $fechaKey = normalize_date_key($fecha);
    $horaIni = sanitize_time_value($horaIni);
    $horaFin = sanitize_time_value($horaFin);
    $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;
    return $repo !== null && $repo->updateGroupHours($fechaKey, $horaIni, $horaFin);
}

$grupos = load_hours_extra_all();
$grupos = deduplicate_groups_by_shared_date($grupos);
$uid = auth_get_user_id();
$grupos = filter_hours_groups_for_user($grupos, (string)$uid);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_extra') {
    if (!$canEditHours) {
        http_response_code(403);
        exit('No tienes permiso para editar Horas extra.');
    }
    if (function_exists('csrf_validate')) csrf_validate();
    if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
    $fecha = trim($_POST['fecha'] ?? '');
    $horaIni = trim($_POST['hora_ini'] ?? '');
    $horaFin = trim($_POST['hora_fin'] ?? '');
    if (update_hours_by_date($fecha, $horaIni, $horaFin)) {
        $flash = 'Horas actualizadas';
    } else {
        $flash = 'No se encontraron registros para esa fecha';
    }
    $grupos = filter_hours_groups_for_user(deduplicate_groups_by_shared_date(load_hours_extra_all()), (string)$uid);
    if ($fecha !== '' && $selMes === '' && $selAnio === '') {
        $dtTmp = DateTime::createFromFormat('Y-m-d', $fecha) ?: DateTime::createFromFormat('d-m-Y', $fecha);
        if ($dtTmp instanceof DateTime) {
            $selMes = $dtTmp->format('n');
            $selAnio = $dtTmp->format('Y');
        }
    }
}

$aniosDisponibles = [];
foreach ($grupos as $g) {
    $fechaBase = $g['fecha'] ?? '';
    if ($fechaBase) {
        $dt = DateTime::createFromFormat('Y-m-d', $fechaBase) ?: DateTime::createFromFormat('d-m-Y', $fechaBase);
        if ($dt instanceof DateTime) {
            $aniosDisponibles[$dt->format('Y')] = true;
        }
    }
}
$aniosDisponibles = array_keys($aniosDisponibles);
$aniosDisponibles[] = $anioActual;
if ($selAnio !== '') {
    $aniosDisponibles[] = $selAnio;
}
$aniosDisponibles = array_values(array_unique(array_map('strval', $aniosDisponibles)));
$aniosDisponibles ? sort($aniosDisponibles, SORT_NUMERIC) : [];

$grupos = array_values(array_filter($grupos, function ($g) use ($selMes, $selAnio) {
    $fechaBase = $g['fecha'] ?? '';
    if ($fechaBase) {
        $dt = DateTime::createFromFormat('Y-m-d', $fechaBase) ?: DateTime::createFromFormat('d-m-Y', $fechaBase);
        if ($dt instanceof DateTime) {
            $mesNum = (int)$dt->format('n');
            $anioNum = $dt->format('Y');
            if ($selMes !== '' && (int)$selMes !== $mesNum) return false;
            if ($selAnio !== '' && $selAnio !== $anioNum) return false;
        }
    }
    return true;
}));

usort($grupos, function ($a, $b) {
    $fa = normalize_date_key($a['fecha'] ?? '');
    $fb = normalize_date_key($b['fecha'] ?? '');
    if ($fa === $fb) {
        return 0;
    }
    if ($fa === '') {
        return 1;
    }
    if ($fb === '') {
        return -1;
    }
    return $fa <=> $fb; // mostrar primero las fechas más antiguas
});

function fmt_fecha($fecha) {
    $dt = DateTime::createFromFormat('Y-m-d', $fecha) ?: DateTime::createFromFormat('d-m-Y', $fecha);
    return $dt ? $dt->format('d-m-Y') : $fecha;
}
function minutos_diff($ini, $fin) {
    if (!$ini || !$fin) return null;
    $d1 = DateTime::createFromFormat('H:i', $ini) ?: DateTime::createFromFormat('H:i:s', $ini);
    $d2 = DateTime::createFromFormat('H:i', $fin) ?: DateTime::createFromFormat('H:i:s', $fin);
    if (!$d1 || !$d2 || $d2 <= $d1) return null;
    return (int)round(($d2->getTimestamp() - $d1->getTimestamp()) / 60);
}
function hhmm($mins) {
    if ($mins === null) return '';
    $hh = str_pad((string)floor($mins / 60), 2, '0', STR_PAD_LEFT);
    $mm = str_pad((string)($mins % 60), 2, '0', STR_PAD_LEFT);
    return "$hh:$mm";
}

function mantencion_emach_minutes_from_time($value) {
    $value = trim((string)$value);
    if (!preg_match('/^(\d{1,2}):([0-5]\d)(?::[0-5]\d)?$/', $value, $matches)) {
        return null;
    }
    $hour = (int)$matches[1];
    if ($hour < 0 || $hour > 23) {
        return null;
    }
    return ($hour * 60) + (int)$matches[2];
}

function mantencion_emach_clock_from_minutes($minutes) {
    $minutes = max(0, min(1439, (int)$minutes));
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function mantencion_emach_central_user_id(array $sessionUser): ?int {
    if (!class_exists(\Illuminate\Support\Facades\DB::class) || !class_exists(\Illuminate\Support\Facades\Schema::class)) {
        return null;
    }

    try {
        if (!\Illuminate\Support\Facades\Schema::hasTable('usuarios_nova')) {
            return null;
        }

        $candidates = [
            'uuid' => [$sessionUser['_nova_user_id'] ?? '', $sessionUser['uuid'] ?? ''],
            'usuario' => [$sessionUser['username'] ?? '', $sessionUser['usuario'] ?? '', $sessionUser['rut_sin_dv'] ?? '', $sessionUser['id'] ?? ''],
            'rut' => [$sessionUser['rut'] ?? ''],
            'redmine_id' => [$sessionUser['redmine_id'] ?? '', $sessionUser['id'] ?? ''],
            'usuario_core' => [$sessionUser['core_user'] ?? '', $sessionUser['usuario_core'] ?? ''],
        ];

        foreach ($candidates as $column => $values) {
            foreach ($values as $value) {
                $value = trim((string)$value);
                if ($value === '') {
                    continue;
                }
                $id = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where($column, $value)->value('id');
                if ($id !== null) {
                    return (int)$id;
                }
            }
        }
    } catch (Throwable) {
    }

    return null;
}

function mantencion_emach_schedule_for_user(?int $userId): array {
    if (!$userId || !class_exists(\Illuminate\Support\Facades\DB::class) || !class_exists(\Illuminate\Support\Facades\Schema::class)) {
        return [];
    }

    try {
        if (!\Illuminate\Support\Facades\Schema::hasTable('emach_horarios_usuario')) {
            return [];
        }

        $schedule = [];
        $rows = \Illuminate\Support\Facades\DB::table('emach_horarios_usuario')
            ->where('usuario_id', $userId)
            ->get();
        foreach ($rows as $row) {
            $day = (int)($row->dia_semana ?? 0);
            if ($day < 1 || $day > 7) {
                continue;
            }
            $schedule[$day] = [
                'activo' => (bool)($row->activo ?? false),
                'salida' => substr((string)($row->hora_salida ?? ''), 0, 5),
            ];
        }
        return $schedule;
    } catch (Throwable) {
        return [];
    }
}

function mantencion_emach_exit_marks_from_session(): array {
    if (!function_exists('request') || !request()->hasSession()) {
        return [];
    }

    $payload = request()->session()->get('emach.last_query', []);
    $rows = is_array($payload) ? (array)data_get($payload, 'planilla.rows', []) : [];
    $marks = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = strtoupper(trim((string)($row[5] ?? data_get($row, 'tipo', ''))));
        if ($type !== 'SALIDA') {
            continue;
        }
        $dateKey = normalize_date_key((string)($row[3] ?? data_get($row, 'fecha', '')));
        $minutes = mantencion_emach_minutes_from_time((string)($row[4] ?? data_get($row, 'marcas', data_get($row, 'marca', ''))));
        if ($dateKey === '' || $minutes === null) {
            continue;
        }
        $marks[$dateKey]['exit'] = max((int)($marks[$dateKey]['exit'] ?? -1), $minutes);
    }
    return $marks;
}

function mantencion_emach_credentials_configured(array $sessionUser): bool {
    if (!function_exists('app')) {
        return false;
    }

    try {
        $credentials = app(\App\Modulos\Nova\Repositories\UserIntegrationRepository::class)->emachForSession($sessionUser);
        return !empty($credentials['stored']);
    } catch (Throwable) {
        return false;
    }
}

function mantencion_emach_overtime_suggestions(array $groups): array {
    $suggestions = [];
    foreach ($groups as $group) {
        $dateKey = normalize_date_key($group['fecha'] ?? '');
        if ($dateKey !== '') {
            $suggestions[$dateKey] = [
                'ok' => false,
                'hora_inicio' => '',
                'hora_fin' => '',
                'total' => '',
                'status' => 'Sin datos EMACH para calcular esta fecha.',
            ];
        }
    }
    if (!$suggestions) {
        return [];
    }

    auth_start_session();
    $sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
    if (function_exists('request')) {
        $novaUser = request()->session()->get('nova_user', []);
        if (is_array($novaUser)) {
            $sessionUser = array_merge($novaUser, $sessionUser);
        }
    }

    if (!mantencion_emach_credentials_configured($sessionUser)) {
        return array_map(function ($suggestion) {
            $suggestion['status'] = 'Configura tus credenciales EMACH antes de calcular.';
            return $suggestion;
        }, $suggestions);
    }

    $userId = mantencion_emach_central_user_id($sessionUser);
    if ($userId === null) {
        return array_map(function ($suggestion) {
            $suggestion['status'] = 'No pude asociar tu usuario NOVA con EMACH.';
            return $suggestion;
        }, $suggestions);
    }

    $schedule = mantencion_emach_schedule_for_user($userId);
    if (!$schedule) {
        return array_map(function ($suggestion) {
            $suggestion['status'] = 'Define tu horario semanal en EMACH > Horario.';
            return $suggestion;
        }, $suggestions);
    }

    $marks = mantencion_emach_exit_marks_from_session();
    if (!$marks) {
        return array_map(function ($suggestion) {
            $suggestion['status'] = 'Consulta tus marcaciones en EMACH antes de calcular.';
            return $suggestion;
        }, $suggestions);
    }

    foreach (array_keys($suggestions) as $dateKey) {
        $date = DateTime::createFromFormat('Y-m-d', $dateKey);
        if (!$date) {
            continue;
        }
        $weekday = (int)$date->format('N');
        $configured = $schedule[$weekday] ?? null;
        if (!$configured || empty($configured['activo'])) {
            $suggestions[$dateKey]['status'] = 'Ese dia no tiene jornada activa en tu horario EMACH.';
            continue;
        }
        $scheduledExit = mantencion_emach_minutes_from_time($configured['salida'] ?? '');
        if ($scheduledExit === null) {
            $suggestions[$dateKey]['status'] = 'Tu horario EMACH no tiene hora de salida para ese dia.';
            continue;
        }
        $actualExit = $marks[$dateKey]['exit'] ?? null;
        if ($actualExit === null) {
            $suggestions[$dateKey]['status'] = 'No encontre una marcacion de salida EMACH para esa fecha.';
            continue;
        }
        $extraMinutes = $actualExit - $scheduledExit;
        if ($extraMinutes <= 0) {
            $suggestions[$dateKey]['status'] = 'La salida EMACH no supera tu horario de salida.';
            continue;
        }
        $suggestions[$dateKey] = [
            'ok' => true,
            'hora_inicio' => mantencion_emach_clock_from_minutes($scheduledExit),
            'hora_fin' => mantencion_emach_clock_from_minutes($actualExit),
            'total' => hhmm($extraMinutes),
            'status' => 'Calculado con horario EMACH y marcacion de salida.',
        ];
    }

    return $suggestions;
}

$emachSuggestions = mantencion_emach_overtime_suggestions($grupos);
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Horas extra'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
  <?php $horasExtraCssVersion = @filemtime(__DIR__ . '/../../assets/css/horas-extra.css') ?: time(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/horas-extra.css?v=<?= (int)$horasExtraCssVersion ?>">
</head>
<body class="bg-light">
<?php $activeNav = 'horas'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-alarm';
    $heroTitle = 'Horas extra';
    $heroSubtitle = 'Reportes con hora extra agrupados por fecha';
    $heroExtras = '';
    if ($selMes || $selAnio) {
      $heroExtras = '<span class="badge bg-white bg-opacity-25 text-white border border-white">Filtrado: ' . ($selMes ? ucfirst($meses[(int)$selMes] ?? $selMes) : 'Todos los meses') . ' ' . ($selAnio ?: '') . '</span>';
    }
    include __DIR__ . '/../partials/hero.php';
  ?>

  <form class="card shadow-sm mb-3" method="get">
    <div class="card-body row g-3 align-items-end">
      <div class="col-md-4 col-lg-3">
        <label class="form-label">Mes</label>
        <select name="mes" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($meses as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($selMes !== '' && (int)$selMes === $k) ? 'selected' : '' ?>><?= ucwords($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 col-lg-3">
        <label class="form-label">A&ntilde;o</label>
        <select name="anio" class="form-select">
          <option value="" <?= $selAnio === '' ? 'selected' : '' ?>>Todos</option>
          <?php foreach ($aniosDisponibles as $an): ?>
            <option value="<?= $h($an) ?>" <?= ($selAnio !== '' && (string)$selAnio === (string)$an) ? 'selected' : '' ?>><?= $h($an) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 col-lg-3 d-flex gap-2">
        <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
        <a class="btn-nova btn-nova-secondary" href="?"><i class="bi bi-x-circle"></i> Limpiar</a>
      </div>
    </div>
  </form>

  <?php if ($flash): ?><div data-nova-flash="<?= $flash === 'No se encontraron registros para esa fecha' ? 'info' : 'success' ?>" data-nova-flash-message="<?= $h($flash) ?>" hidden></div><?php endif; ?>

  <?php
    $totalHorasTablaMins = 0;
    $totalHorasTabla = '';
  ?>
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-table text-primary"></i>
          <span class="fw-semibold">Listado</span>
        </div>
        <button type="button" class="btn-nova btn-nova-secondary" id="copy-table-btn" aria-label="Copiar tabla">
          <i class="bi bi-clipboard"></i> Copiar tabla
        </button>
      </div>

      <?php if (empty($grupos)): ?>
        <div class="nova-alert-card is-info"><i class="bi bi-calendar-x"></i> <span>No se encontraron registros para esa fecha</span></div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="extras-table" data-total-hours="">
            <thead class="table-light">
              <tr>
                <th>Fecha</th>
                <th>Detalle</th>
                <th>N&deg; Ticket</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grupos as $g):
                $fechaKey = $g['fecha'] ?? '';
                $dt = DateTime::createFromFormat('Y-m-d', $fechaKey) ?: DateTime::createFromFormat('d-m-Y', $fechaKey);
                $mesNum = $dt ? (int)$dt->format('n') : null;
                $anioNum = $dt ? $dt->format('Y') : '';
                $diaNum = $dt ? (int)$dt->format('d') : '';
                $mesTxt = $mesNum ? ucfirst($meses[$mesNum] ?? '') : '';
                $diaNombre = $dt ? ucfirst($dias[(int)$dt->format('w')] ?? '') : '';
                $horaIni = $g['hora_inicio'] ?? '';
                $horaFin = $g['hora_fin'] ?? '';
                $minsGrupo = minutos_diff($horaIni, $horaFin);
                if ($minsGrupo !== null) $totalHorasTablaMins += $minsGrupo;
                $totalGrupo = hhmm($minsGrupo);
                $emachSuggestion = $emachSuggestions[$fechaKey] ?? [];
              ?>
                <tr class="group-row" data-fecha="<?= $h(fmt_fecha($fechaKey)) ?>" data-horaini="<?= $h($horaIni) ?>" data-horafin="<?= $h($horaFin) ?>" data-total="<?= $h($totalGrupo) ?>">
                  <td colspan="3">
                    <div class="d-flex justify-content-between align-items-center w-100">
                      <span><strong><?= $h(fmt_fecha($fechaKey)) ?></strong> &middot; Hora inicio: <?= $h($horaIni) ?> | Hora término: <?= $h($horaFin) ?><?= $totalGrupo ? ' | Total de horas: ' . $h($totalGrupo) : '' ?></span>
                      <?php if ($canEditHours): ?>
                        <button type="button" class="btn-action btn-action-edit" data-bs-toggle="modal" data-bs-target="#editModal" data-fecha="<?= $h(fmt_fecha($fechaKey)) ?>" data-horaini="<?= $h($horaIni) ?>" data-horafin="<?= $h($horaFin) ?>" data-emach-ok="<?= !empty($emachSuggestion['ok']) ? '1' : '0' ?>" data-emach-hora-inicio="<?= $h($emachSuggestion['hora_inicio'] ?? '') ?>" data-emach-hora-fin="<?= $h($emachSuggestion['hora_fin'] ?? '') ?>" data-emach-total="<?= $h($emachSuggestion['total'] ?? '') ?>" data-emach-status="<?= $h($emachSuggestion['status'] ?? 'Sin datos EMACH para calcular esta fecha.') ?>" title="Editar horas" aria-label="Editar horas"><i class="bi bi-pencil-square"></i></button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php if (isset($g['reports']) && is_array($g['reports'])):
                  foreach ($g['reports'] as $r):
                    $detalleFecha = $r['fecha_inicio'] ?? $r['fecha'] ?? $fechaKey;
                  ?>
                  <tr class="detail-row" data-detalle="<?= $h($r['asunto'] ?? '') ?>" data-ticket="<?= $h($r['redmine_id'] ?? '') ?>">
                    <td><?= $h(fmt_fecha($detalleFecha)) ?></td>
                    <td style="white-space:pre-line;"><?= $h($r['asunto'] ?? '') ?></td>
                    <td class="text-center"><?= $h($r['redmine_id'] ?? '') ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              <?php endforeach; ?>
            </tbody>
            <?php $totalHorasTabla = hhmm($totalHorasTablaMins); ?>
            <tfoot>
              <tr>
                <th colspan="3">Total de horas: <?= $h($totalHorasTabla ?: '00:00') ?></th>
              </tr>
            </tfoot>
          </table>
        </div>
        <script>document.getElementById('extras-table').dataset.totalHours = '<?= $h($totalHorasTabla ?: '') ?>';</script>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal editar horas -->
<?php if ($canEditHours): ?>
<div class="modal fade detail-drawer-modal" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog detail-drawer-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar horas por fecha</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="update_extra">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Fecha</label>
            <input type="text" class="form-control" name="fecha" id="md-fecha" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Hora de inicio</label>
            <input type="time" class="form-control" name="hora_ini" id="md-hora-ini" step="1">
          </div>
          <div class="mb-3">
            <label class="form-label">Hora de t&eacute;rmino</label>
            <input type="time" class="form-control" name="hora_fin" id="md-hora-fin" step="1">
          </div>
          <div class="mb-3">
            <button type="button" class="btn btn-outline-primary" id="md-calcular-emach">
              <i class="bi bi-calculator"></i> Calcular desde EMACH
            </button>
            <div class="form-text fw-semibold" id="md-emach-status"></div>
          </div>
          <div class="mb-2 text-muted small" id="md-total-horas"></div>
          <p class="text-muted small mb-0">Las horas se aplican a todos los reportes de esa fecha.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn-nova btn-nova-primary" <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>>Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
<script>
const editModal = document.getElementById('editModal');
const totalHorasEl = document.getElementById('md-total-horas');
const horaIniInput = document.getElementById('md-hora-ini');
const horaFinInput = document.getElementById('md-hora-fin');
const calcularEmachBtn = document.getElementById('md-calcular-emach');
const emachStatusEl = document.getElementById('md-emach-status');
const emachSuggestionEndpoint = '<?= $h(function_exists('url') ? url('/emach/horas-extra-sugerencia') : '/emach/horas-extra-sugerencia') ?>';
const laravelCsrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

function parseTimeInput(value) {
  if (!value) return null;
  const parts = value.split(':');
  if (parts.length === 3) {
    const date = new Date(`1970-01-01T${value}`);
    return isNaN(date) ? null : date;
  }
  if (parts.length === 2) {
    const date = new Date(`1970-01-01T${value}:00`);
    return isNaN(date) ? null : date;
  }
  return null;
}

function updateTotalHorasPreview() {
  if (!totalHorasEl || !horaIniInput || !horaFinInput) return;
  const d1 = parseTimeInput(horaIniInput.value);
  const d2 = parseTimeInput(horaFinInput.value);
  if (d1 && d2 && d2 > d1) {
    const diffMs = d2 - d1;
    const mins = Math.floor(diffMs / 60000);
    const hh = String(Math.floor(mins / 60)).padStart(2,'0');
    const mm = String(mins % 60).padStart(2,'0');
    totalHorasEl.textContent = `Total de horas: ${hh}:${mm}`;
    return;
  }
  totalHorasEl.textContent = '';
}

if (editModal) {
  editModal.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget;
    if (!btn) return;
    const setVal = (id, attr) => {
      const el = document.getElementById(id);
      if (el) el.value = btn.getAttribute(attr) || '';
    };
    setVal('md-fecha', 'data-fecha');
    setVal('md-hora-ini', 'data-horaini');
    setVal('md-hora-fin', 'data-horafin');
    editModal.dataset.emachOk = btn.getAttribute('data-emach-ok') || '0';
    editModal.dataset.emachHoraInicio = btn.getAttribute('data-emach-hora-inicio') || '';
    editModal.dataset.emachHoraFin = btn.getAttribute('data-emach-hora-fin') || '';
    editModal.dataset.emachTotal = btn.getAttribute('data-emach-total') || '';
    editModal.dataset.emachStatus = btn.getAttribute('data-emach-status') || '';
    if (emachStatusEl) {
      emachStatusEl.classList.remove('text-success', 'text-danger');
      emachStatusEl.textContent = '';
    }
    updateTotalHorasPreview();
  });
}
if (horaIniInput) horaIniInput.addEventListener('input', updateTotalHorasPreview);
if (horaFinInput) horaFinInput.addEventListener('input', updateTotalHorasPreview);
if (calcularEmachBtn && editModal && emachStatusEl) {
  const applyEmachSuggestion = (suggestion, message) => {
    if (horaIniInput) horaIniInput.value = suggestion.hora_inicio || '';
    if (horaFinInput) horaFinInput.value = suggestion.hora_fin || '';
    emachStatusEl.classList.remove('text-danger');
    emachStatusEl.classList.add('text-success');
    emachStatusEl.textContent = message || `Inicio desde tu salida programada y termino desde EMACH. Total: ${suggestion.total || '00:00'}.`;
    updateTotalHorasPreview();
  };

  calcularEmachBtn.addEventListener('click', async () => {
    emachStatusEl.classList.remove('text-success', 'text-danger');
    if (editModal.dataset.emachOk === '1') {
      applyEmachSuggestion({
        hora_inicio: editModal.dataset.emachHoraInicio || '',
        hora_fin: editModal.dataset.emachHoraFin || '',
        total: editModal.dataset.emachTotal || '00:00'
      });
      return;
    }

    calcularEmachBtn.disabled = true;
    emachStatusEl.textContent = 'Consultando EMACH...';
    try {
      const fecha = document.getElementById('md-fecha')?.value || '';
      const response = await fetch(emachSuggestionEndpoint, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': laravelCsrfToken
        },
        body: JSON.stringify({ fecha })
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || editModal.dataset.emachStatus || 'Sin datos EMACH para calcular esta fecha.');
      }

      editModal.dataset.emachOk = '1';
      editModal.dataset.emachHoraInicio = payload.hora_inicio || '';
      editModal.dataset.emachHoraFin = payload.hora_fin || '';
      editModal.dataset.emachTotal = payload.total || '00:00';
      editModal.dataset.emachStatus = payload.message || '';
      applyEmachSuggestion(payload, `${payload.message || 'Calculado desde EMACH.'} Total: ${payload.total || '00:00'}.`);
    } catch (error) {
      emachStatusEl.classList.add('text-danger');
      emachStatusEl.textContent = error?.message || 'No se pudo consultar EMACH.';
    } finally {
      calcularEmachBtn.disabled = false;
    }
  });
}

// Copiar tabla con formato similar al ejemplo (compatible con Excel)
const copyBtn = document.getElementById('copy-table-btn');
if (copyBtn) {
  copyBtn.addEventListener('click', () => {
    const table = document.getElementById('extras-table');
    if (!table) return;
    const groupRows = Array.from(table.querySelectorAll('tbody tr.group-row'));
    if (!groupRows.length) {
      window.appModal?.show({
        title: 'Sin datos',
        message: 'No hay datos para copiar.',
        tone: 'warning'
      });
      return;
    }

    const totalHorasTabla = table.dataset.totalHours || '';
    const grupos = [];
    groupRows.forEach(grp => {
      const fecha = grp.dataset.fecha || '';
      const ini = grp.dataset.horaini || '';
      const fin = grp.dataset.horafin || '';
      const total = grp.dataset.total || '';
      const detalles = [];
      let node = grp.nextElementSibling;
      while (node && !node.classList.contains('group-row')) {
        if (node.dataset) detalles.push({ detalle: node.dataset.detalle || '', ticket: node.dataset.ticket || '' });
        node = node.nextElementSibling;
      }
      grupos.push({ fecha, ini, fin, total, detalles });
    });

    const html = [];
    html.push('<table border="1" style="border-collapse:collapse;border:1px solid #000;font-family:Arial,sans-serif;font-size:12px;">');
    html.push('<tr style="background:#d9d9d9;font-weight:bold;"><th style="border:1px solid #000;padding:6px;">Fecha</th><th style="border:1px solid #000;padding:6px;">Detalle</th><th style="border:1px solid #000;padding:6px;width:120px;">N° Ticket</th></tr>');
    grupos.forEach(g => {
      const header = `${g.fecha} · Hora inicio: ${g.ini} | Hora término: ${g.fin}${g.total ? ' | Total de horas: ' + g.total : ''}`;
      html.push(`<tr style=\"background:#cfe2ff;\"><td colspan=\"3\" style=\"border:1px solid #000;padding:6px;\">${header}</td></tr>`);
      g.detalles.forEach(d => {
        html.push(`<tr><td style=\"border:1px solid #000;padding:6px;\"></td><td style=\"border:1px solid #000;padding:6px;white-space:pre-line;\">${d.detalle}</td><td style=\"border:1px solid #000;padding:6px;text-align:center;\">${d.ticket}</td></tr>`);
      });
    });
    html.push(`<tr><td colspan=\"3\" style=\"border:1px solid #000;padding:6px;font-weight:bold;\">Total de horas extra realizadas${totalHorasTabla ? ': ' + totalHorasTabla : ''}</td></tr>`);
    html.push('</table>');
    const textPlain = grupos.map(g => {
      const header = `${g.fecha} · Hora inicio: ${g.ini} | Hora término: ${g.fin}${g.total ? ' | Total de horas: ' + g.total : ''}`;
      const dets = g.detalles.map(d => `\t${d.detalle}\t${d.ticket}`).join('\n');
      return header + '\n' + dets;
    }).join('\n');
    const finalPlain = textPlain + `\nTotal de horas extra realizadas${totalHorasTabla ? ': ' + totalHorasTabla : ''}`;
    const fallbackCopyPlain = (txt) => {
      const ta = document.createElement('textarea');
      ta.value = txt;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    };

    const fallbackCopyHtml = (htmlStr, plainStr) => {
      const container = document.createElement('div');
      container.innerHTML = htmlStr;
      container.style.position = 'fixed';
      container.style.pointerEvents = 'none';
      container.style.opacity = '0';
      document.body.appendChild(container);
      const range = document.createRange();
      range.selectNodeContents(container);
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      const ok = document.execCommand('copy');
      sel.removeAllRanges();
      document.body.removeChild(container);
      if (!ok) fallbackCopyPlain(plainStr);
    };

    const htmlString = html.join('');

    if (navigator.clipboard && navigator.clipboard.write) {
      const item = new ClipboardItem({
        'text/html': new Blob([htmlString], { type: 'text/html' }),
        'text/plain': new Blob([finalPlain], { type: 'text/plain' })
      });
      navigator.clipboard.write([item]).then(
        () => window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }),
        () => { fallbackCopyHtml(htmlString, finalPlain); window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }); }
      );
    } else if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(finalPlain).then(
        () => window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }),
        () => { fallbackCopyHtml(htmlString, finalPlain); window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }); }
      );
    } else {
      fallbackCopyHtml(htmlString, finalPlain);
      window.appModal?.show({
        title: 'Tabla copiada',
        message: 'Tabla copiada al portapapeles.',
        tone: 'success'
      });
    }
  });
}
</script>
</div> <!-- #page-content -->
</body>
</html>
