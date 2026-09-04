(() => {
  const boot = () => {
    const root = document.querySelector('[data-tic-quick-report]');
    if (!root) return;

  const notes = root.querySelector('[data-quick-notes]');
  const notesStatus = root.querySelector('[data-quick-notes-status]');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  let notesTimer = null;
  let notesRequest = null;
  let notesResizeTimer = null;
  let savedNotes = notes?.value || '';

  const fitNotesToViewport = () => {
    if (!notes) return;
    if (window.matchMedia('(max-width: 1099.98px)').matches) {
      notes.style.removeProperty('--tic-quick-notes-height');
      return;
    }

    const top = notes.getBoundingClientRect().top;
    if (top < 0) return;
    const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
    notes.style.setProperty(
      '--tic-quick-notes-height',
      `${Math.max(420, Math.floor(viewportHeight - top - 18))}px`
    );
  };

  const setNotesState = (state, message) => {
    if (!notesStatus) return;
    notesStatus.classList.toggle('is-saving', state === 'saving');
    notesStatus.classList.toggle('is-error', state === 'error');
    const icon = notesStatus.querySelector('i');
    const text = notesStatus.querySelector('span');
    if (icon) {
      icon.className = state === 'saving'
        ? 'bi bi-arrow-repeat'
        : state === 'error' ? 'bi bi-cloud-slash' : 'bi bi-cloud-check';
    }
    if (text) text.textContent = message;
  };

  const saveNotes = async () => {
    if (!notes || !notes.dataset.notesSaveUrl || notes.value === savedNotes) return;
    const value = notes.value;
    notesRequest?.abort();
    notesRequest = new AbortController();
    setNotesState('saving', 'Guardando…');

    try {
      const payload = new URLSearchParams({ notes: value });
      const response = await fetch(notes.dataset.notesSaveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: payload,
        signal: notesRequest.signal,
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const result = await response.json();
      savedNotes = value;
      if (notes.value === value) {
        setNotesState('saved', `Guardado en esta sesión · ${result.saved_at || 'ahora'}`);
      } else {
        window.clearTimeout(notesTimer);
        notesTimer = window.setTimeout(saveNotes, 450);
      }
    } catch (error) {
      if (error?.name === 'AbortError') return;
      setNotesState('error', 'No se pudo guardar');
    }
  };

  if (notes) {
    fitNotesToViewport();
    window.addEventListener('resize', () => {
      window.clearTimeout(notesResizeTimer);
      notesResizeTimer = window.setTimeout(fitNotesToViewport, 120);
    });
    notes.addEventListener('input', () => {
      window.clearTimeout(notesTimer);
      setNotesState('saving', 'Cambios pendientes…');
      notesTimer = window.setTimeout(saveNotes, 650);
    });
    notes.addEventListener('blur', () => {
      window.clearTimeout(notesTimer);
      saveNotes();
    });
    window.addEventListener('pagehide', () => {
      window.clearTimeout(notesTimer);
      if (notes.value === savedNotes || !notes.dataset.notesSaveUrl) return;
      const payload = new FormData();
      payload.append('_token', csrfToken);
      payload.append('notes', notes.value);
      navigator.sendBeacon?.(notes.dataset.notesSaveUrl, payload);
    });
  }

  const initSelect2 = () => {
    if (!window.jQuery?.fn?.select2) return;
    window.jQuery(root).find('[data-tic-webhook-select2]').each(function () {
      const select = window.jQuery(this);
      if (select.hasClass('select2-hidden-accessible')) return;
      select.select2({
        width: '100%',
        allowClear: false,
        dropdownCssClass: 'tic-select2-dropdown',
        placeholder: this.dataset.placeholder || 'Seleccionar',
        language: {
          noResults: () => 'No se encontraron resultados',
          searching: () => 'Buscando…'
        }
      });
    });
  };

  const editor = root.querySelector('[data-tic-quick-form]');
  const hoursExtra = root.querySelector('#quick-hora-extra');
  const estimatedTime = root.querySelector('#quick-tiempo');
  const syncEstimatedTime = () => {
    if (!hoursExtra || !estimatedTime) return;
    estimatedTime.value = hoursExtra.value === 'SI' ? '1' : '';
  };
  hoursExtra?.addEventListener('change', syncEstimatedTime);
  syncEstimatedTime();
  const previewDrawer = root.querySelector('[data-quick-preview-drawer]');
  const minimizeDrawerButton = previewDrawer?.querySelector('[data-quick-drawer-minimize]');
  const maximizeDrawerButton = previewDrawer?.querySelector('[data-quick-drawer-maximize]');
  const output = (name) => root.querySelector(`[data-quick-output="${name}"]`);
  const textValue = (control, fallback = 'Sin indicar') => {
    if (!control) return fallback;
    if (control instanceof HTMLSelectElement) {
      return control.selectedOptions[0]?.dataset.assigneeName
        || control.selectedOptions[0]?.textContent?.replace(' · sin Telegram', '').trim()
        || fallback;
    }
    return control.value.trim() || fallback;
  };

  const syncPreview = () => {
    if (!editor) return;
    editor.querySelectorAll('[data-quick-source]').forEach((control) => {
      const name = control.dataset.quickSource;
      const target = output(name);
      if (!target) return;
      const fallback = name === 'descripcion' ? 'Sin descripción adicional' : 'Sin indicar';
      target.textContent = textValue(control, fallback);
    });

    const assignee = editor.querySelector('#quick-asignado');
    const state = editor.querySelector('[data-telegram-state]');
    if (!assignee || !state) return;
    const option = assignee.selectedOptions[0];
    const name = option?.dataset.assigneeName || 'El responsable';
    const hasChat = Boolean(option?.dataset.chatId);
    state.classList.toggle('is-ready', hasChat);
    state.classList.toggle('is-warning', !hasChat);
    state.querySelector('span').textContent = hasChat
      ? `${name} recibirá el ticket por Telegram.`
      : `${name} no tiene Chat ID; el ticket se creará sin notificación.`;
  };

  const syncDrawerControls = () => {
    if (!previewDrawer) return;
    const minimized = previewDrawer.classList.contains('is-minimized');
    const maximized = previewDrawer.classList.contains('is-maximized');
    const minimizeIcon = minimizeDrawerButton?.querySelector('i');
    const maximizeIcon = maximizeDrawerButton?.querySelector('i');

    if (minimizeDrawerButton) {
      minimizeDrawerButton.title = minimized ? 'Restaurar' : 'Minimizar';
      minimizeDrawerButton.setAttribute('aria-label', minimized ? 'Restaurar revisión' : 'Minimizar revisión');
    }
    if (minimizeIcon) minimizeIcon.className = minimized ? 'bi bi-window' : 'bi bi-dash-lg';
    if (maximizeDrawerButton) {
      maximizeDrawerButton.title = maximized ? 'Restaurar tamaño' : 'Maximizar';
      maximizeDrawerButton.setAttribute('aria-label', maximized ? 'Restaurar tamaño de la revisión' : 'Maximizar revisión');
    }
    if (maximizeIcon) maximizeIcon.className = maximized ? 'bi bi-fullscreen-exit' : 'bi bi-arrows-fullscreen';
    if (minimized) {
      previewDrawer.removeAttribute('aria-modal');
    } else if (previewDrawer.classList.contains('show')) {
      previewDrawer.setAttribute('aria-modal', 'true');
    }
  };

  minimizeDrawerButton?.addEventListener('click', () => {
    const restore = previewDrawer.classList.contains('is-minimized');
    previewDrawer.classList.toggle('is-minimized', !restore);
    previewDrawer.classList.remove('is-maximized');
    document.body.classList.toggle('modal-open', restore);
    syncDrawerControls();
  });

  maximizeDrawerButton?.addEventListener('click', () => {
    const restore = previewDrawer.classList.contains('is-maximized');
    previewDrawer.classList.toggle('is-maximized', !restore);
    previewDrawer.classList.remove('is-minimized');
    document.body.classList.add('modal-open');
    syncDrawerControls();
  });

  previewDrawer?.addEventListener('nova-drawer:close', () => {
    previewDrawer.classList.remove('is-minimized', 'is-maximized');
    syncDrawerControls();
  });

  root.querySelectorAll('[data-nova-drawer-open="tic-quick-preview-drawer"]').forEach((button) => {
    button.addEventListener('click', () => {
      previewDrawer?.classList.remove('is-minimized', 'is-maximized');
      syncDrawerControls();
    });
  });

  initSelect2();
  window.requestAnimationFrame(fitNotesToViewport);
  window.addEventListener('load', fitNotesToViewport, { once: true });
  syncPreview();
  syncDrawerControls();
  if (previewDrawer && root.dataset.quickPreviewReady === '1') {
    window.requestAnimationFrame(() => window.NovaDrawer?.open(previewDrawer));
  }
  root.addEventListener('input', syncPreview);
  root.addEventListener('change', syncPreview);
  if (window.jQuery) {
    window.jQuery(root).on('select2:select select2:clear', syncPreview);
  }

    window.addEventListener('pageshow', () => {
      root.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = false;
        button.classList.remove('is-loading');
      });
      window.appUi?.setIntegrationLoading?.(false);
      fitNotesToViewport();
      syncPreview();
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
