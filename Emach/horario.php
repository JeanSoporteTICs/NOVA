<?php

$pageTitle = 'EMACH | Horario';
$activeNav = 'horario';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$days = [
  1 => 'Lunes',
  2 => 'Martes',
  3 => 'Miercoles',
  4 => 'Jueves',
  5 => 'Viernes',
  6 => 'Sabado',
  7 => 'Domingo',
];
$statusMessage = '';
$errorMessage = '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

if ($isPost) {
  $expectedCsrfToken = function_exists('csrf_token') ? (string) csrf_token() : '';
  $submittedCsrfToken = trim((string) ($_POST['_token'] ?? ''));
  if ($expectedCsrfToken === '' || $submittedCsrfToken === '' || !hash_equals($expectedCsrfToken, $submittedCsrfToken)) {
    if (function_exists('abort')) {
      abort(419, 'Token CSRF invalido.');
    }
    http_response_code(419);
    exit('Token CSRF invalido.');
  }
}

function emach_schedule_session_user(): array {
  if (function_exists('request')) {
    $novaUser = request()->session()->get('nova_user');
    if (is_array($novaUser)) {
      return $novaUser;
    }
  }
  return is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
}

function emach_schedule_user_id(array $sessionUser): ?int {
  if (!function_exists('app')) {
    return null;
  }

  try {
    if (!\Illuminate\Support\Facades\Schema::hasTable('usuarios_nova')) {
      return null;
    }

    $candidates = [
      'uuid' => [$sessionUser['id'] ?? '', $sessionUser['_nova_user_id'] ?? ''],
      'usuario' => [$sessionUser['username'] ?? '', $sessionUser['usuario'] ?? '', $sessionUser['rut_sin_dv'] ?? ''],
      'rut' => [$sessionUser['rut'] ?? ''],
      'redmine_id' => [$sessionUser['redmine_id'] ?? '', $sessionUser['legacy']['id'] ?? ''],
      'usuario_core' => [$sessionUser['core_user'] ?? '', $sessionUser['usuario_core'] ?? ''],
    ];

    foreach ($candidates as $column => $values) {
      foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '') {
          continue;
        }
        $id = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where($column, $value)->value('id');
        if ($id !== null) {
          return (int) $id;
        }
      }
    }
  } catch (Throwable) {
  }

  return null;
}

function emach_schedule_table_ready(): bool {
  try {
    return function_exists('app') && \Illuminate\Support\Facades\Schema::hasTable('emach_horarios_usuario');
  } catch (Throwable) {
    return false;
  }
}

function emach_schedule_time_or_null(mixed $value): ?string {
  $value = trim((string) $value);
  if ($value === '') {
    return null;
  }
  return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
}

function emach_schedule_rows(int $userId, array $days): array {
  $defaults = [];
  foreach ($days as $day => $label) {
    $defaults[$day] = [
      'dia_semana' => $day,
      'activo' => $day <= 5,
      'hora_entrada' => '08:00',
      'hora_salida' => '17:00',
    ];
  }

  if ($userId <= 0 || !emach_schedule_table_ready()) {
    return $defaults;
  }

  try {
    $rows = \Illuminate\Support\Facades\DB::table('emach_horarios_usuario')
      ->where('usuario_id', $userId)
      ->get()
      ->keyBy('dia_semana');

    foreach ($rows as $day => $row) {
      $day = (int) $day;
      if (!isset($defaults[$day])) {
        continue;
      }
      $defaults[$day] = [
        'dia_semana' => $day,
        'activo' => (bool) ($row->activo ?? false),
        'hora_entrada' => substr((string) ($row->hora_entrada ?? ''), 0, 5),
        'hora_salida' => substr((string) ($row->hora_salida ?? ''), 0, 5),
      ];
    }
  } catch (Throwable) {
  }

  return $defaults;
}

$sessionUser = emach_schedule_session_user();
$userId = emach_schedule_user_id($sessionUser);
$tableReady = emach_schedule_table_ready();

if ($isPost) {
  if (!$tableReady || !$userId) {
    $errorMessage = 'No se pudo guardar el horario. Verifica que la tabla exista y que tu usuario NOVA este identificado.';
  } else {
    $incoming = is_array($_POST['days'] ?? null) ? $_POST['days'] : [];
    $normalized = [];

    foreach ($days as $day => $label) {
      $dayData = is_array($incoming[$day] ?? null) ? $incoming[$day] : [];
      $active = (string) ($dayData['activo'] ?? '') === '1';
      $entry = emach_schedule_time_or_null($dayData['entrada'] ?? '');
      $exit = emach_schedule_time_or_null($dayData['salida'] ?? '');

      if ($active && ($entry === null || $exit === null)) {
        $errorMessage = 'Completa entrada y salida para ' . $label . '.';
        break;
      }
      if ($active && $entry >= $exit) {
        $errorMessage = 'La salida debe ser posterior a la entrada en ' . $label . '.';
        break;
      }

      $normalized[$day] = [
        'activo' => $active,
        'hora_entrada' => $active ? $entry : null,
        'hora_salida' => $active ? $exit : null,
      ];
    }

    if ($errorMessage === '') {
      try {
        foreach ($normalized as $day => $row) {
          \Illuminate\Support\Facades\DB::table('emach_horarios_usuario')->updateOrInsert(
            ['usuario_id' => $userId, 'dia_semana' => $day],
            [
              'activo' => $row['activo'],
              'hora_entrada' => $row['hora_entrada'],
              'hora_salida' => $row['hora_salida'],
              'actualizado_at' => now(),
            ]
          );
        }
        $statusMessage = 'Horario EMACH guardado correctamente.';
      } catch (Throwable) {
        $errorMessage = 'No se pudo guardar el horario EMACH.';
      }
    }
  }
}

$schedule = emach_schedule_rows((int) ($userId ?? 0), $days);
$activeDays = count(array_filter($schedule, static fn(array $day): bool => (bool) ($day['activo'] ?? false)));
$weeklyHours = 0.0;
foreach ($schedule as $day) {
  if (empty($day['activo']) || empty($day['hora_entrada']) || empty($day['hora_salida'])) {
    continue;
  }
  $weeklyHours += (strtotime('2000-01-01 ' . $day['hora_salida']) - strtotime('2000-01-01 ' . $day['hora_entrada'])) / 3600;
}

?>
<!doctype html>
<html lang="es">
<head>
  <?php include __DIR__ . '/views/partials/bootstrap-head.php'; ?>
</head>
<body class="emach-page">
  <?php include __DIR__ . '/views/partials/navbar.php'; ?>

  <main class="nova-content"><div class="container-fluid py-4">
    <section class="card card-hero sb-page-hero emach-hero nova-system-hero mb-4">
      <div class="card-body p-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div class="d-flex align-items-center gap-3">
          <span class="emach-hero-icon"><i class="bi bi-calendar-week"></i></span>
          <div>
            <h1 class="h3 mb-1 text-white fw-black">Mi horario EMACH</h1>
            <p class="mb-0 text-white-50 fw-semibold">Define tu entrada y salida esperada para cada dia de la semana.</p>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="emach-status-pill"><i class="bi bi-calendar-check"></i><?= $h($activeDays) ?> dias activos</span>
          <span class="emach-status-pill"><i class="bi bi-clock-history"></i><?= $h(number_format($weeklyHours, 1, ',', '.')) ?> h/semana</span>
        </div>
      </div>
    </section>

    <?php if (!$tableReady): ?>
      <div class="nova-alert-card is-warning" role="alert">
        <i class="bi bi-database-exclamation"></i>
        <span>La tabla de horarios EMACH aun no existe. Ejecuta las migraciones para habilitar esta vista.</span>
      </div>
    <?php endif; ?>
    <?php if ($statusMessage !== ''): ?>
      <div class="nova-alert-card is-success" role="status">
        <i class="bi bi-check-circle-fill"></i><span><?= $h($statusMessage) ?></span>
      </div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
      <div class="nova-alert-card is-warning" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i><span><?= $h($errorMessage) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= $h(function_exists('url') ? url('/emach/horario.php') : '/emach/horario.php') ?>">
      <?php if (function_exists('csrf_token')): ?>
        <input type="hidden" name="_token" value="<?= $h(csrf_token()) ?>">
      <?php endif; ?>

      <section class="emach-schedule-list mb-4">
        <?php foreach ($days as $day => $label): ?>
          <?php $row = $schedule[$day]; ?>
          <article class="emach-schedule-row <?= !empty($row['activo']) ? 'is-active' : '' ?>">
            <label class="emach-schedule-toggle">
              <input class="form-check-input" type="checkbox" name="days[<?= $day ?>][activo]" value="1" <?= !empty($row['activo']) ? 'checked' : '' ?> data-emach-day-toggle>
              <span>
                <strong><?= $h($label) ?></strong>
                <small><?= !empty($row['activo']) ? 'Jornada activa' : 'Sin jornada' ?></small>
              </span>
            </label>
            <div class="emach-schedule-times">
              <div>
                <label class="form-label" for="entrada-<?= $day ?>">Entrada</label>
                <input class="form-control" id="entrada-<?= $day ?>" name="days[<?= $day ?>][entrada]" type="time" value="<?= $h($row['hora_entrada']) ?>" data-emach-day-time>
              </div>
              <div>
                <label class="form-label" for="salida-<?= $day ?>">Salida</label>
                <input class="form-control" id="salida-<?= $day ?>" name="days[<?= $day ?>][salida]" type="time" value="<?= $h($row['hora_salida']) ?>" data-emach-day-time>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <section class="card emach-card">
        <div class="card-body p-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
          <div>
            <h2 class="h5 fw-black mb-1">Guardar horario semanal</h2>
            <p class="text-muted fw-semibold mb-0">Este horario queda asociado solo a tu usuario NOVA.</p>
          </div>
          <button class="btn btn-primary emach-submit-button" type="submit" <?= (!$tableReady || !$userId) ? 'disabled' : '' ?>>
            <i class="bi bi-save"></i>Guardar horario
          </button>
        </div>
      </section>
    </form>
  </div></main>
</div>

<script>
window.addEventListener('load', () => {
  document.querySelectorAll('.emach-schedule-row').forEach((card) => {
    const toggle = card.querySelector('[data-emach-day-toggle]');
    const times = card.querySelectorAll('[data-emach-day-time]');
    const label = card.querySelector('.emach-schedule-toggle small');
    const sync = () => {
      const active = !!toggle?.checked;
      card.classList.toggle('is-active', active);
      times.forEach((input) => { input.disabled = !active; });
      if (label) label.textContent = active ? 'Jornada activa' : 'Sin jornada';
    };
    toggle?.addEventListener('change', sync);
    sync();
  });
});
</script>
</body>
</html>
