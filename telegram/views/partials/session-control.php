<?php

$h = $h ?? static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$sessionTimeout = function_exists('app')
    ? app(\App\Support\Nova\NovaSettingsRepository::class)->sessionTimeout()
    : 300;
$lastActivity = function_exists('session')
    ? (int) session('nova_last_activity', time())
    : (int) ($_SESSION['last_activity'] ?? time());
$remaining = max(0, $sessionTimeout - (time() - $lastActivity));
$logoutUrl = function_exists('route') ? route('logout') : '/NOVA/public/logout';
$sessionExtendUrl = function_exists('route') ? route('session.extend') : '/NOVA/public/session/extend';
$csrfToken = function_exists('csrf_token') ? csrf_token() : '';

?>
<span class="telegram-session-badge badge bg-light text-dark d-inline-flex align-items-center gap-1" id="session-timer" data-remaining="<?= $h($remaining) ?>" data-timeout="<?= $h($sessionTimeout) ?>">
  <i class="bi bi-clock"></i><span id="session-timer-text">--:--</span>
</span>

<script>
window.addEventListener('load', () => {
  const timerEl = document.getElementById('session-timer');
  const textEl = document.getElementById('session-timer-text') || timerEl;
  const extendBtn = document.getElementById('btn-extend-session');
  const extendPwd = document.getElementById('session-password');
  const extendMsg = document.getElementById('session-msg');
  const closeBtn = document.getElementById('btn-logout-session');
  const modalEl = document.getElementById('sessionModal');
  if (!timerEl || !textEl || !modalEl) return;

  const baseTimeout = parseInt(timerEl.getAttribute('data-timeout'), 10) || 300;
  let remaining = parseInt(timerEl.getAttribute('data-remaining'), 10) || baseTimeout;
  let expiresAt = Date.now() + (remaining * 1000);
  let modalShown = false;
  let tickHandle = null;
  const modal = window.bootstrap ? new bootstrap.Modal(modalEl, {backdrop: 'static', keyboard: false}) : null;
  const logoutUrl = '<?= $h($logoutUrl) ?>';
  const sessionExtendUrl = '<?= $h($sessionExtendUrl) ?>';
  const csrfToken = '<?= $h($csrfToken) ?>' || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

  function paint(secondsLeft) {
    if (secondsLeft <= 20) {
      timerEl.className = 'telegram-session-badge badge bg-danger text-light d-inline-flex align-items-center gap-1';
    } else if (secondsLeft <= 60) {
      timerEl.className = 'telegram-session-badge badge bg-warning text-dark d-inline-flex align-items-center gap-1';
    } else {
      timerEl.className = 'telegram-session-badge badge bg-light text-dark d-inline-flex align-items-center gap-1';
    }
  }

  function setModalState(expired) {
    const title = modalEl.querySelector('.modal-title');
    const copy = modalEl.querySelector('.modal-body > p');
    if (title) title.textContent = expired ? 'Sesion expirada' : 'Sesion por expirar';
    if (copy) copy.textContent = expired
      ? 'Tu sesion ya expiro. Ingresa tu contrasena para continuar o cierra sesion.'
      : 'Tu sesion expira pronto. Ingresa tu contrasena para continuar.';
    if (extendBtn) {
      extendBtn.disabled = false;
      extendBtn.textContent = 'Continuar sesion';
    }
    if (closeBtn) closeBtn.textContent = 'Cerrar sesion';
  }

  function secondsLeft() {
    return Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
  }

  function tick() {
    remaining = secondsLeft();
    const minutes = Math.floor(remaining / 60).toString().padStart(2, '0');
    const seconds = (remaining % 60).toString().padStart(2, '0');
    textEl.textContent = `${minutes}:${seconds}`;
    paint(remaining);
    if (remaining <= 0) {
      setModalState(true);
      if (modal && !modalShown) {
        modal.show();
        modalShown = true;
        setTimeout(() => extendPwd?.focus(), 120);
      }
      return;
    }
    if (remaining <= 60 && modal && !modalShown) {
      setModalState(false);
      modal.show();
      modalShown = true;
      setTimeout(() => extendPwd?.focus(), 120);
    }
    tickHandle = setTimeout(tick, 1000);
  }

  function restart() {
    if (tickHandle) clearTimeout(tickHandle);
    tick();
  }

  closeBtn?.addEventListener('click', () => {
    window.location.href = logoutUrl;
  });

  extendBtn?.addEventListener('click', async () => {
    const password = (extendPwd?.value || '').trim();
    if (!password) {
      if (extendMsg) extendMsg.textContent = 'Ingresa tu contrasena.';
      return;
    }
    if (extendMsg) extendMsg.textContent = '';
    extendBtn.disabled = true;
    extendBtn.textContent = 'Validando...';
    try {
      const resp = await fetch(sessionExtendUrl, {
        method: 'POST',
        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken},
        credentials: 'same-origin',
        body: JSON.stringify({password})
      });
      const data = await resp.json();
      if (!resp.ok || !data.ok) throw new Error(data.msg || 'No se pudo extender la sesion.');
      remaining = parseInt(data.remaining ?? data.timeout ?? baseTimeout, 10) || baseTimeout;
      expiresAt = Date.now() + (remaining * 1000);
      modalShown = false;
      if (extendPwd) extendPwd.value = '';
      modal?.hide();
      restart();
    } catch (error) {
      if (extendMsg) extendMsg.textContent = error.message || 'No se pudo extender la sesion.';
      extendBtn.disabled = false;
      extendBtn.textContent = 'Continuar sesion';
    }
  });

  modalEl.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) return;
    if (!modalEl.classList.contains('show')) return;
    event.preventDefault();
    if (extendBtn && !extendBtn.disabled) extendBtn.click();
  });

  document.addEventListener('visibilitychange', restart);
  window.addEventListener('focus', restart);
  restart();
});
</script>

<div class="modal fade" id="sessionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Sesion por expirar</h5>
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
