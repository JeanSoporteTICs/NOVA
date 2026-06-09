<?php

$h = $h ?? static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$activeNav = $activeNav ?? '';
$sessionTimeout = function_exists('app')
  ? app(\App\Support\Nova\NovaSettingsRepository::class)->sessionTimeout()
  : 300;
$lastActivity = function_exists('session')
  ? (int) session('nova_last_activity', time())
  : (int) ($_SESSION['last_activity'] ?? time());
$remaining = max(0, $sessionTimeout - (time() - $lastActivity));
$emachBaseUrl = function_exists('url') ? rtrim(url('/emach'), '/') : '/emach';
$homeUrl = function_exists('url') ? url('/') : '/NOVA/public';
$logoutUrl = function_exists('route') ? route('logout') : '/NOVA/public/logout';
$sessionExtendUrl = function_exists('route') ? route('session.extend') : '/NOVA/public/session/extend';
$csrfToken = function_exists('csrf_token') ? csrf_token() : '';
$currentUser = $_SESSION['user'] ?? [];
$navItems = [
  ['key' => 'inicio', 'label' => 'Consulta', 'href' => $emachBaseUrl, 'icon' => 'bi-table'],
  ['key' => 'mantenedor', 'label' => 'Mantenedor', 'href' => $emachBaseUrl . '/views/Mantenedor/mantenedor.php', 'icon' => 'bi-sliders'],
];

?>
<nav class="navbar navbar-expand-lg navbar-dark emach-navbar">
  <div class="container-fluid px-4">
    <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="<?= $h($emachBaseUrl) ?>">
      <span class="emach-brand-mark"><i class="bi bi-heart-pulse"></i></span>
      <span>EMACH</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#emachNavMenu" aria-controls="emachNavMenu" aria-expanded="false" aria-label="Abrir navegacion">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="d-flex align-items-center gap-2 ms-auto emach-nav-actions">
      <span class="emach-session-badge badge bg-light text-dark d-inline-flex align-items-center gap-1" id="session-timer" data-remaining="<?= $h($remaining) ?>" data-timeout="<?= $h($sessionTimeout) ?>">
        <i class="bi bi-clock"></i><span id="session-timer-text">--:--</span>
      </span>
      <span class="text-white-50 fw-bold d-none d-md-inline"><i class="bi bi-person-circle"></i> <?= $h($currentUser['nombre'] ?? $currentUser['name'] ?? 'Usuario') ?></span>
      <a class="btn btn-outline-light" href="<?= $h($homeUrl) ?>"><i class="bi bi-house-door"></i>NOVA</a>
      <a class="btn btn-outline-light" href="<?= $h($logoutUrl) ?>"><i class="bi bi-box-arrow-right"></i>Salir</a>
    </div>
  </div>
</nav>
<div class="emach-menu-wrap">
  <div class="collapse navbar-collapse show" id="emachNavMenu">
    <ul class="navbar-nav emach-nav-list me-auto mb-0">
      <?php foreach ($navItems as $item): ?>
        <?php $isActive = $activeNav === $item['key']; ?>
        <li class="nav-item">
          <a class="nav-link emach-nav-link <?= $isActive ? 'active' : '' ?>" href="<?= $h($item['href']) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= $h($item['icon']) ?>"></i>
            <span><?= $h($item['label']) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<script>
window.addEventListener('load', () => {
  const extendBtn = document.getElementById('btn-extend-session');
  const extendPwd = document.getElementById('session-password');
  const extendMsg = document.getElementById('session-msg');
  const closeBtn = document.getElementById('btn-logout-session');
  const timerEl = document.getElementById('session-timer');
  const textEl = document.getElementById('session-timer-text') || timerEl;
  const baseTimeout = timerEl ? (parseInt(timerEl.getAttribute('data-timeout'), 10) || 300) : 300;
  let remaining = timerEl ? (parseInt(timerEl.getAttribute('data-remaining'), 10) || baseTimeout) : baseTimeout;
  let expiresAt = Date.now() + (remaining * 1000);
  const logoutUrl = '<?= $h($logoutUrl) ?>';
  const sessionExtendUrl = '<?= $h($sessionExtendUrl) ?>';
  const csrfToken = '<?= $h($csrfToken) ?>' || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const modalEl = document.getElementById('sessionModal');
  const modal = (window.bootstrap && modalEl) ? new bootstrap.Modal(modalEl) : null;
  const modalTitleEl = modalEl ? modalEl.querySelector('.modal-title') : null;
  const modalBodyTextEl = modalEl ? modalEl.querySelector('.modal-body > p') : null;
  let modalShown = false;
  let sessionExpired = false;
  let tickHandle = null;

  function setTimerAppearance(secondsLeft) {
    if (!timerEl) return;
    if (secondsLeft <= 20) {
      timerEl.className = 'emach-session-badge badge bg-danger text-light d-inline-flex align-items-center gap-1';
    } else if (secondsLeft <= 60) {
      timerEl.className = 'emach-session-badge badge bg-warning text-dark d-inline-flex align-items-center gap-1';
    } else {
      timerEl.className = 'emach-session-badge badge bg-light text-dark d-inline-flex align-items-center gap-1';
    }
  }

  function setModalState(expired) {
    sessionExpired = expired;
    if (modalTitleEl) modalTitleEl.textContent = expired ? 'Sesion expirada' : 'Sesion por expirar';
    if (modalBodyTextEl) {
      modalBodyTextEl.textContent = expired
        ? 'Tu sesion ya expiro. Debes iniciar sesion nuevamente.'
        : 'Tu sesion expira pronto. Ingresa tu contrasena para continuar.';
    }
    if (extendPwd) extendPwd.disabled = false;
    if (extendBtn) {
      extendBtn.disabled = false;
      extendBtn.textContent = 'Continuar sesion';
    }
    if (closeBtn) closeBtn.textContent = expired ? 'Cancelar' : 'Cerrar sesion';
  }

  function getRemainingSeconds() {
    return Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
  }

  function tick() {
    if (!timerEl) return;
    remaining = getRemainingSeconds();
    if (remaining <= 0) {
      textEl.textContent = '00:00';
      setTimerAppearance(0);
      setModalState(true);
      if (modal && !modalShown) {
        modal.show();
        modalShown = true;
        if (extendPwd) setTimeout(() => extendPwd.focus(), 120);
      }
      return;
    }
    if (modal && !modalShown && remaining <= 60) {
      setModalState(false);
      modal.show();
      modalShown = true;
      if (extendPwd) setTimeout(() => extendPwd.focus(), 120);
    }
    const minutes = Math.floor(remaining / 60).toString().padStart(2, '0');
    const seconds = (remaining % 60).toString().padStart(2, '0');
    textEl.textContent = `${minutes}:${seconds}`;
    setTimerAppearance(remaining);
    tickHandle = setTimeout(tick, 1000);
  }

  function restartTick() {
    if (tickHandle) clearTimeout(tickHandle);
    tick();
  }

  function syncTimerState() {
    remaining = getRemainingSeconds();
    if (remaining <= 0) {
      restartTick();
      return;
    }
    if (modalShown && remaining > 60 && modal) {
      modal.hide();
      modalShown = false;
      setModalState(false);
      if (extendMsg) extendMsg.textContent = '';
    }
    restartTick();
  }

  restartTick();
  document.addEventListener('visibilitychange', syncTimerState);
  window.addEventListener('focus', syncTimerState);

  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      window.location.href = logoutUrl;
    });
  }
  if (extendBtn && extendPwd) {
    extendBtn.addEventListener('click', async () => {
      if (extendMsg) extendMsg.textContent = '';
      const pwd = extendPwd.value.trim();
      if (!pwd) {
        if (extendMsg) extendMsg.textContent = 'Ingresa tu contrasena.';
        return;
      }
      try {
        const resp = await fetch(sessionExtendUrl, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
          },
          credentials: 'same-origin',
          body: JSON.stringify({password: pwd})
        });
        const data = await resp.json();
        if (data.ok) {
          remaining = parseInt(data.remaining ?? data.timeout ?? baseTimeout, 10) || baseTimeout;
          expiresAt = Date.now() + (remaining * 1000);
          modalShown = false;
          setModalState(false);
          extendPwd.value = '';
          if (extendMsg) extendMsg.textContent = 'Sesion extendida.';
          restartTick();
          if (modal) setTimeout(() => modal.hide(), 400);
        } else if (extendMsg) {
          extendMsg.textContent = data.msg || 'Contrasena incorrecta.';
        }
      } catch (e) {
        if (extendMsg) extendMsg.textContent = 'No se pudo extender la sesion.';
      }
    });
  }
  if (modalEl) {
    modalEl.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) return;
      if (!modalEl.classList.contains('show')) return;
      event.preventDefault();
      if (extendBtn && !extendBtn.disabled) extendBtn.click();
    });
  }
  setModalState(false);
});
</script>

<div class="modal fade" id="sessionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Sesion por expirar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p>Tu sesion expira pronto. Ingresa tu contrasena para continuar.</p>
        <div class="mb-3">
          <label class="form-label" for="session-password">Contrasena</label>
          <input type="password" id="session-password" class="form-control" autocomplete="current-password">
          <div class="form-text text-danger" id="session-msg"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="btn-logout-session">Cerrar sesion</button>
        <button type="button" class="btn btn-primary" id="btn-extend-session">Continuar sesion</button>
      </div>
    </div>
  </div>
</div>
