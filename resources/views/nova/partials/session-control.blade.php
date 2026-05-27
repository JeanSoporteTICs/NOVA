@once
    <style>
        .nova-session-badge { display: inline-flex; align-items: center; gap: 6px; min-height: 31px; padding: 6px 10px; border-radius: 999px; font-weight: 900; }
        .nova-session-badge.is-ok { background: #f8fafc; color: #0f172a; }
        .nova-session-badge.is-warning { background: #fef3c7; color: #78350f; }
        .nova-session-badge.is-danger { background: #fee2e2; color: #991b1b; }
        .nova-session-modal { border: 0; border-radius: 18px; box-shadow: 0 28px 70px rgba(15, 23, 42, .28); overflow: hidden; }
        .nova-session-modal .modal-header { background: linear-gradient(180deg, #f8fbff 0%, #eef6ff 100%); border-bottom: 1px solid #d8e3f4; }
        .nova-session-modal .modal-title { color: #0f172a; font-weight: 900; }
        .nova-session-modal .modal-body { padding: 22px; }
        .nova-session-modal .modal-body p { margin: 0 0 14px; color: #334155; font-weight: 700; }
        .nova-session-modal .form-label { color: #334155; font-weight: 900; }
        .nova-session-modal .form-control { min-height: 42px; border-color: #d8e3f4; border-radius: 12px; font-weight: 700; }
        .nova-session-modal .modal-footer { background: #f8fbff; border-top: 1px solid #d8e3f4; }
    </style>
@endonce

@php
    $novaSessionTimeout = app(\App\Support\Nova\NovaSettingsRepository::class)->sessionTimeout();
    $novaSessionLastActivity = (int) session('nova_last_activity', time());
    $novaSessionRemaining = max(0, $novaSessionTimeout - max(0, time() - $novaSessionLastActivity));
@endphp

<span class="nova-session-badge is-ok" data-nova-session-timer data-timeout="{{ $novaSessionTimeout }}" data-remaining="{{ $novaSessionRemaining }}">
    <i class="bi bi-clock"></i><span data-nova-session-timer-text>--:--</span>
</span>

@once
    <div class="modal fade" id="nova-session-modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false" data-nova-session-modal>
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content nova-session-modal">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" data-nova-session-modal-title>Sesion por expirar</h2>
                </div>
                <div class="modal-body">
                    <p data-nova-session-modal-copy>Tu sesion expira pronto. Ingresa tu contrasena para continuar.</p>
                    <label class="form-label" for="nova-session-password">Contrasena</label>
                    <input class="form-control" id="nova-session-password" type="password" autocomplete="current-password" data-nova-session-password>
                    <div class="form-text text-danger fw-semibold" data-nova-session-message></div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline-secondary" type="button" data-nova-session-logout>Cerrar sesion</button>
                    <button class="btn btn-primary" type="button" data-nova-session-extend>Continuar sesion</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const sessionTimer = document.querySelector('[data-nova-session-timer]');
            const sessionTimerText = document.querySelector('[data-nova-session-timer-text]');
            const sessionModalElement = document.querySelector('[data-nova-session-modal]');
            if (!sessionTimer || !sessionTimerText || !sessionModalElement) return;

            const baseTimeout = Math.max(60, parseInt(sessionTimer.dataset.timeout || '3600', 10) || 3600);
            const warningThreshold = Math.min(60, Math.max(10, baseTimeout - 1));
            let expiresAt = Date.now() + ((parseInt(sessionTimer.dataset.remaining || String(baseTimeout), 10) || baseTimeout) * 1000);
            let timerHandle = null;
            let modalShown = false;
            let sessionExpired = false;

            const sessionModal = window.bootstrap?.Modal
                ? window.bootstrap.Modal.getOrCreateInstance(sessionModalElement, {backdrop: 'static', keyboard: false})
                : null;
            const modalTitle = sessionModalElement.querySelector('[data-nova-session-modal-title]');
            const modalCopy = sessionModalElement.querySelector('[data-nova-session-modal-copy]');
            const passwordInput = sessionModalElement.querySelector('[data-nova-session-password]');
            const messageBox = sessionModalElement.querySelector('[data-nova-session-message]');
            const extendButton = sessionModalElement.querySelector('[data-nova-session-extend]');
            const logoutButton = sessionModalElement.querySelector('[data-nova-session-logout]');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            const fallbackCloseModal = () => {
                sessionModalElement.classList.remove('show');
                sessionModalElement.setAttribute('aria-hidden', 'true');
                sessionModalElement.removeAttribute('aria-modal');
                sessionModalElement.style.display = 'none';
                document.body.classList.remove('modal-open');
            };

            const showSessionModal = (expired) => {
                sessionExpired = expired;
                modalShown = true;
                if (modalTitle) modalTitle.textContent = expired ? 'Sesion expirada' : 'Sesion por expirar';
                if (modalCopy) modalCopy.textContent = expired
                    ? 'Tu sesion ya expiro. Ingresa tu contrasena para continuar o cierra la sesion.'
                    : 'Tu sesion expira pronto. Ingresa tu contrasena para continuar o cierra la sesion.';
                if (extendButton) {
                    extendButton.disabled = false;
                    extendButton.innerHTML = 'Continuar sesion';
                }
                if (messageBox) messageBox.textContent = '';
                if (sessionModal) {
                    sessionModal.show();
                } else {
                    sessionModalElement.classList.add('show');
                    sessionModalElement.removeAttribute('aria-hidden');
                    sessionModalElement.setAttribute('aria-modal', 'true');
                    sessionModalElement.style.display = 'block';
                    document.body.classList.add('modal-open');
                }
                window.setTimeout(() => passwordInput?.focus(), 120);
            };

            const hideSessionModal = () => {
                modalShown = false;
                if (sessionModal) {
                    sessionModal.hide();
                } else {
                    fallbackCloseModal();
                }
            };

            const renderSessionTimer = () => {
                const remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
                const minutes = Math.floor(remaining / 60).toString().padStart(2, '0');
                const seconds = (remaining % 60).toString().padStart(2, '0');
                sessionTimerText.textContent = `${minutes}:${seconds}`;
                sessionTimer.classList.toggle('is-ok', remaining > warningThreshold);
                sessionTimer.classList.toggle('is-warning', remaining > 0 && remaining <= warningThreshold);
                sessionTimer.classList.toggle('is-danger', remaining <= 0);

                if (remaining <= 0) {
                    if (!modalShown || !sessionExpired) showSessionModal(true);
                    return;
                }

                if (remaining <= warningThreshold && !modalShown) {
                    showSessionModal(false);
                }

                timerHandle = window.setTimeout(renderSessionTimer, 1000);
            };

            const restartSessionTimer = () => {
                if (timerHandle) window.clearTimeout(timerHandle);
                renderSessionTimer();
            };

            logoutButton?.addEventListener('click', () => {
                window.location.href = @json(route('logout'));
            });

            extendButton?.addEventListener('click', async () => {
                const password = (passwordInput?.value || '').trim();
                if (!password) {
                    if (messageBox) messageBox.textContent = 'Ingresa tu contrasena.';
                    return;
                }
                if (messageBox) messageBox.textContent = '';
                extendButton.disabled = true;
                extendButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Validando';

                try {
                    const response = await fetch(@json(route('session.extend')), {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({password}),
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok) {
                        throw new Error(data.msg || 'No se pudo extender la sesion.');
                    }
                    expiresAt = Date.now() + ((parseInt(data.remaining || data.timeout || String(baseTimeout), 10) || baseTimeout) * 1000);
                    if (passwordInput) passwordInput.value = '';
                    hideSessionModal();
                    restartSessionTimer();
                } catch (error) {
                    if (messageBox) messageBox.textContent = error.message || 'No se pudo extender la sesion.';
                    extendButton.disabled = false;
                    extendButton.innerHTML = 'Continuar sesion';
                }
            });

            document.addEventListener('visibilitychange', restartSessionTimer);
            window.addEventListener('focus', restartSessionTimer);
            restartSessionTimer();
        })();
    </script>
@endonce
