(function () {
  const modalEl = document.getElementById('appFeedbackModal');
  if (!modalEl || !window.bootstrap) return;

  const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
  const header = modalEl.querySelector('.modal-header');
  const kicker = document.getElementById('appFeedbackModalKicker');
  const title = document.getElementById('appFeedbackModalTitle');
  const message = document.getElementById('appFeedbackModalMessage');
  const cancelBtn = modalEl.querySelector('.app-modal-cancel');
  const confirmBtn = modalEl.querySelector('.app-modal-confirm');
  let pendingConfirm = null;
  const queue = [];
  let activeItem = null;

  const labels = {
    info: 'Aviso',
    success: 'Listo',
    warning: 'Atencion',
    danger: 'Confirmacion',
  };

  function ensureToastStyles() {
    if (document.getElementById('app-toast-styles')) return;
    const style = document.createElement('style');
    style.id = 'app-toast-styles';
    style.textContent = `
      .app-toast-stack { position: fixed; right: 22px; bottom: 22px; z-index: 3000; display: grid; gap: 10px; width: min(420px, calc(100vw - 32px)); pointer-events: none; }
      .app-toast { display: flex; align-items: flex-start; gap: 10px; padding: 14px 16px; border: 1px solid #bfdbfe; border-radius: 14px; background: #eff6ff; color: #0f172a; box-shadow: 0 22px 48px rgba(15, 23, 42, .18); font-weight: 800; pointer-events: auto; transition: opacity .18s ease, transform .18s ease; }
      .app-toast i { flex: 0 0 auto; margin-top: 1px; font-size: 1.1rem; color: #2563eb; }
      .app-toast.is-success { border-color: #86efac; background: #ecfdf5; color: #14532d; }
      .app-toast.is-success i { color: #16a34a; }
      .app-toast.is-warning { border-color: #fde68a; background: #fffbeb; color: #713f12; }
      .app-toast.is-warning i { color: #d97706; }
      .app-toast.is-danger { border-color: #fecaca; background: #fef2f2; color: #7f1d1d; }
      .app-toast.is-danger i { color: #dc2626; }
      .app-toast.is-hiding { opacity: 0; transform: translateY(8px); }
    `;
    document.head.appendChild(style);
  }

  function toastIcon(tone) {
    if (tone === 'success') return 'bi-check-circle-fill';
    if (tone === 'danger') return 'bi-exclamation-triangle-fill';
    if (tone === 'warning') return 'bi-exclamation-circle-fill';
    return 'bi-info-circle-fill';
  }

  function showToast(options) {
    ensureToastStyles();
    let stack = document.querySelector('.app-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'app-toast-stack';
      stack.setAttribute('aria-live', 'polite');
      stack.setAttribute('aria-atomic', 'true');
      document.body.appendChild(stack);
    }
    const tone = options.tone || 'info';
    const toast = document.createElement('div');
    toast.className = `app-toast is-${tone}`;
    toast.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
    const icon = document.createElement('i');
    icon.className = `bi ${toastIcon(tone)}`;
    const text = document.createElement('span');
    text.textContent = options.message || options.title || '';
    toast.append(icon, text);
    stack.appendChild(toast);
    window.setTimeout(() => {
      toast.classList.add('is-hiding');
      window.setTimeout(() => toast.remove(), 220);
    }, options.duration || 4500);
    return Promise.resolve(true);
  }

  function configure(options) {
    const tone = options.tone || 'info';
    header?.setAttribute('data-app-modal-tone', tone);
    if (kicker) kicker.textContent = options.kicker || labels[tone] || labels.info;
    if (title) title.textContent = options.title || (tone === 'danger' ? 'Confirmar accion' : 'Mensaje');
    if (message) message.textContent = options.message || '';
    if (cancelBtn) {
      cancelBtn.classList.toggle('d-none', !options.confirm);
      cancelBtn.textContent = options.cancelText || 'Cancelar';
    }
    if (confirmBtn) {
      confirmBtn.textContent = options.confirmText || (options.confirm ? 'Confirmar' : 'Aceptar');
      confirmBtn.className = `btn app-modal-confirm ${tone === 'danger' ? 'btn-danger' : 'btn-primary'}`;
      confirmBtn.setAttribute('data-bs-dismiss', 'modal');
    }
  }

  function runNext() {
    if (activeItem || queue.length === 0) return;
    activeItem = queue.shift();
    pendingConfirm = activeItem.confirm ? activeItem.resolve : null;
    configure(activeItem);
    modal.show();
  }

  function enqueue(options) {
    return new Promise((resolve) => {
      queue.push({ ...options, resolve });
      runNext();
    });
  }

  window.appModal = {
    show(options) {
      return showToast({ ...options, confirm: false });
    },
    confirm(options) {
      return enqueue({ ...options, confirm: true, tone: options.tone || 'danger' });
    },
  };

  confirmBtn?.addEventListener('click', () => {
    if (pendingConfirm) {
      const resolve = pendingConfirm;
      pendingConfirm = null;
      resolve(true);
    }
    if (activeItem && !activeItem.confirm) {
      activeItem.resolve(true);
    }
  });

  modalEl.addEventListener('hidden.bs.modal', () => {
    if (pendingConfirm) {
      const resolve = pendingConfirm;
      pendingConfirm = null;
      resolve(false);
    }
    if (activeItem && !activeItem.confirm) {
      activeItem.resolve(true);
    }
    activeItem = null;
    runNext();
  });

  window.alert = (text) => {
    window.appModal.show({
      title: 'Mensaje',
      message: String(text || ''),
      tone: 'info',
      confirmText: 'Aceptar',
    });
  };

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-app-confirm]');
    if (!form || form.dataset.appConfirmAccepted === '1') return;
    event.preventDefault();
    window.appModal.confirm({
      title: form.dataset.appConfirmTitle || 'Confirmar accion',
      message: form.dataset.appConfirm || 'Confirma esta accion.',
      tone: form.dataset.appConfirmTone || 'danger',
      confirmText: form.dataset.appConfirmText || 'Eliminar',
      cancelText: form.dataset.appCancelText || 'Cancelar',
    }).then((accepted) => {
      if (!accepted) return;
      form.dataset.appConfirmAccepted = '1';
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    });
  }, true);

  document.addEventListener('DOMContentLoaded', () => {
    const toastSources = [
      '.alert[data-app-toast]:not(.d-none)',
      '#flash-msg:not(.d-none)',
      '#flash-roles:not(.d-none)',
      '#flash-maintenance:not(.d-none)',
      '#flash-nextcloud:not(.d-none)',
      '#cat-sync-msg:not(.d-none)',
    ].join(',');

    document.querySelectorAll(toastSources).forEach((alertEl) => {
      if (alertEl.closest('.rm-config-view-panel:not(.is-active)')) return;
      const text = alertEl.textContent.replace(/\s+/g, ' ').trim();
      if (!text) return;
      const tone = alertEl.dataset.appToastTone
        || (alertEl.classList.contains('alert-danger') ? 'danger'
          : alertEl.classList.contains('alert-warning') ? 'warning'
            : alertEl.classList.contains('alert-success') ? 'success'
              : 'info');
      alertEl.classList.add('d-none');
      window.appModal.show({
        title: tone === 'success' ? 'Mensaje' : 'Aviso',
        message: text,
        tone,
      });
    });
  });
})();


