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

  function ensurePageLoader() {
    let loader = document.querySelector('.app-page-loader');
    if (!loader) {
      loader = document.createElement('div');
      loader.className = 'app-page-loader';
      document.body.appendChild(loader);
    }
    return loader;
  }

  function setPageLoading(active) {
    const loader = ensurePageLoader();
    const page = document.getElementById('page-content');
    loader.classList.toggle('is-visible', active);
    page?.classList.toggle('is-loading', active);
  }

  window.appUi = {
    setLoading: setPageLoading,
    toast: (message, tone = 'info') => showToast({ message, tone }),
  };

  function ensureToastStyles() {
    if (document.getElementById('app-toast-styles')) return;
    const style = document.createElement('style');
    style.id = 'app-toast-styles';
    style.textContent = `
      .app-toast-stack { position: fixed; right: 18px; bottom: 18px; z-index: 3000; display: grid; gap: 10px; width: min(420px, calc(100vw - 28px)); pointer-events: none; }
      .app-toast { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: start; gap: 10px; padding: 13px 14px; border: 1px solid #bdd2ee; border-left: 4px solid #2563eb; border-radius: 8px; background: #fff; color: #0f172a; box-shadow: 0 18px 40px rgba(16, 24, 40, .18); font-weight: 750; pointer-events: auto; opacity: 0; transform: translate3d(0, 10px, 0); animation: appToastIn .2s cubic-bezier(.22,1,.36,1) forwards; }
      .app-toast i { flex: 0 0 auto; margin-top: 2px; font-size: 1rem; color: #2563eb; }
      .app-toast span { line-height: 1.42; overflow-wrap: anywhere; }
      .app-toast button { width: 24px; height: 24px; border: 0; border-radius: 6px; background: transparent; color: #64748b; line-height: 1; }
      .app-toast button:hover { background: #eef2f7; color: #111827; }
      .app-toast.is-success { border-left-color: #0f9f7a; }
      .app-toast.is-success i { color: #0f9f7a; }
      .app-toast.is-warning { border-left-color: #b7791f; }
      .app-toast.is-warning i { color: #b7791f; }
      .app-toast.is-danger { border-left-color: #dc2626; }
      .app-toast.is-danger i { color: #dc2626; }
      .app-toast.is-hiding { opacity: 0; transform: translateY(8px); transition: opacity .18s ease, transform .18s ease; }
      @keyframes appToastIn { to { opacity: 1; transform: translate3d(0,0,0); } }
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
    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Cerrar mensaje');
    close.textContent = '×';
    toast.append(icon, text, close);
    stack.appendChild(toast);
    const hide = () => {
      toast.classList.add('is-hiding');
      window.setTimeout(() => toast.remove(), 220);
    };
    close.addEventListener('click', hide);
    window.setTimeout(hide, options.duration || 4500);
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

  document.addEventListener('submit', (event) => {
    const form = event.target.closest('form');
    if (!form || form.dataset.appNoLoading === '1') return;
    const submitter = event.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
    if (submitter && !submitter.disabled) {
      submitter.classList.add('is-submitting');
    }
    setPageLoading(true);
    window.setTimeout(() => setPageLoading(false), 7000);
  });

  window.addEventListener('beforeunload', () => setPageLoading(true));
  window.addEventListener('pageshow', () => setPageLoading(false));

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

