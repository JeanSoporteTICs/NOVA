/* Shared bounded read-only history synchronization for both Redmine modules. */
(() => {
const novaHistorySyncSessions = new WeakMap();
window.NovaRedmineHistory = {
  start({ endpoint, badges, panel, count, bar, render, onComplete }) {
    const previous = panel && novaHistorySyncSessions.get(panel);
    if (previous) {
      previous.cancelled = true;
      previous.controller?.abort();
    }

    const ids = [...new Set(badges.map(b => b.dataset.redmineId).filter(Boolean))];
    const details = panel?.querySelector('[data-redmine-sync-details]') || document.createElement('div');
    details.className = 'historico-redmine-sync__details';
    details.dataset.redmineSyncDetails = '';
    details.replaceChildren();
    if (panel && !details.isConnected) panel.append(details);
    if (!ids.length) {
      panel?.classList.add('d-none');
      panel?.removeAttribute('data-sync-state');
      return;
    }

    const session = { cancelled: false, controller: null };
    if (panel) novaHistorySyncSessions.set(panel, session);
    let pending = ids.slice();
    let running = false;
    const changedIds = new Set();
    const title = panel?.querySelector('[data-redmine-sync-title]');
    const icon = panel?.querySelector('.historico-redmine-sync__title i');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-sm btn-outline-primary d-none';
    button.textContent = 'Continuar sincronización';
    button.hidden = true;
    const message = document.createElement('span');
    message.className = 'historico-redmine-sync__message';
    details.append(message, button);

    const setState = (state, label, iconClass) => {
      if (panel) panel.dataset.syncState = state;
      if (title) title.textContent = label;
      if (icon) icon.className = `bi ${iconClass}`;
    };
    const setProgress = () => {
      const done = ids.length - pending.length;
      if (count) {
        count.textContent = `${done}/${ids.length}`;
        count.setAttribute('aria-label', `${done} de ${ids.length} tickets revisados`);
      }
      if (bar) bar.style.width = `${100 * done / ids.length}%`;
    };
    setProgress();

    async function run() {
      if (session.cancelled || running || !pending.length) return;
      running = true;
      button.disabled = true;
      button.classList.add('d-none');
      button.hidden = true;
      panel?.classList.remove('d-none');
      setState('running', 'Consultando estados en Redmine', 'bi-arrow-repeat');
      bar?.classList.add('progress-bar-striped', 'progress-bar-animated');
      message.textContent = 'Comprobando conexión con Redmine…';
      let completedResult = null;
      let failed = false;
      const controller = new AbortController();
      session.controller = controller;
      const timer = setTimeout(() => controller.abort(), 30000);
      try {
        const url = new URL(endpoint, window.location.href);
        url.searchParams.set('ids', pending.join(','));
        const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: controller.signal });
        if (!response.ok) throw new Error(`No se pudo consultar Redmine (HTTP ${response.status}).`);
        const result = await response.json();
        if (session.cancelled) return;
        if (!Array.isArray(result.pending_ids) || !result.statuses) throw new Error('Respuesta de sincronización no válida.');
        for (const [id, status] of Object.entries(result.statuses)) {
          badges.filter(b => b.dataset.redmineId === id).forEach(b => render(b, status));
        }
        pending = [...new Set(result.pending_ids.map(String).filter(id => ids.includes(id)))];
        (result.changed_ids || []).forEach(id => changedIds.add(String(id)));
        if (pending.length) {
          const reason = (result.error || '').trim();
          message.textContent = reason && reason !== 'Consulta finalizada.'
            ? reason : `Quedan ${pending.length} tickets por revisar. Puedes continuar.`;
        } else {
          message.textContent = result.error || 'Se revisaron los tickets de esta página.';
          if (result.complete) completedResult = { ...result, changed_ids: [...changedIds] };
        }
      } catch (error) {
        if (session.cancelled) return;
        failed = true;
        message.textContent = error.name === 'AbortError'
          ? 'Se alcanzó el límite de 30 segundos. Puedes continuar; se conservan los estados guardados.'
          : error.message;
      } finally {
        clearTimeout(timer);
        if (session.cancelled) return;
        session.controller = null;
        running = false;
        button.disabled = false;
        button.classList.toggle('d-none', pending.length === 0);
        button.hidden = pending.length === 0;
        button.textContent = failed && pending.length === ids.length ? 'Reintentar consulta' : 'Continuar sincronización';
        badges.filter(b => pending.includes(b.dataset.redmineId)).forEach(b => render(b, { available: false, message: message.textContent }));
        setProgress();
        bar?.classList.remove('progress-bar-striped', 'progress-bar-animated');
        if (pending.length) {
          setState('paused', failed ? 'No se pudo completar la consulta' : 'Quedan estados por revisar', 'bi-exclamation-circle');
        } else {
          setState('complete', 'Consulta de Redmine finalizada', 'bi-check-circle-fill');
        }
      }
      if (completedResult && onComplete) {
        Promise.resolve(onComplete(completedResult)).catch(() => window.NovaToast?.warning?.('Estados guardados. Recarga para actualizar el filtro del histórico.'));
      }
    }
    button.addEventListener('click', run);
    run();
  }
};
})();
