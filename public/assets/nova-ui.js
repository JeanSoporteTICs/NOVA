/**
 * NovaToast — Global floating message system for NOVA and submodules.
 *
 * API:
 *   NovaToast.show({ type, title, message, timeout })
 *   NovaToast.success(message, title?)
 *   NovaToast.error(message, title?)
 *   NovaToast.warning(message, title?)
 *   NovaToast.info(message, title?)
 *   NovaToast.loading(message, title?)
 *
 * Types: success, error, danger, warning, info, loading, neutral,
 *        nextcloud, onlyoffice, redmine, core, emach, telegram
 *
 * Server-side flash: <div data-nova-flash="success" data-nova-flash-message="Texto" hidden></div>
 */
const NovaToast = (() => {
    const TYPE_CONFIG = {
        success:    { icon: 'bi-check-circle-fill',       role: 'status', live: 'polite' },
        error:      { icon: 'bi-exclamation-triangle-fill', role: 'alert',  live: 'assertive' },
        danger:     { icon: 'bi-exclamation-triangle-fill', role: 'alert',  live: 'assertive' },
        warning:    { icon: 'bi-exclamation-circle-fill',  role: 'alert',  live: 'assertive' },
        info:       { icon: 'bi-info-circle-fill',         role: 'status', live: 'polite' },
        loading:    { icon: 'bi-hourglass-split',          role: 'status', live: 'polite' },
        neutral:    { icon: 'bi-circle-fill',              role: 'status', live: 'polite' },
        nextcloud:  { icon: 'bi-cloud-fill',               role: 'status', live: 'polite' },
        onlyoffice: { icon: 'bi-file-earmark-text-fill',   role: 'status', live: 'polite' },
        redmine:    { icon: 'bi-bug-fill',                 role: 'status', live: 'polite' },
        core:       { icon: 'bi-cpu-fill',                 role: 'status', live: 'polite' },
        emach:      { icon: 'bi-gear-fill',                role: 'status', live: 'polite' },
        telegram:   { icon: 'bi-telegram',                 role: 'status', live: 'polite' },
    };

    const DEFAULT_TIMEOUT = 4500;

    function getOrCreateContainer() {
        let container = document.getElementById('nova-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'nova-toast-container';
            container.className = 'nova-toast-container';
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'false');
            document.body.appendChild(container);
        }
        return container;
    }

    function show(options) {
        if (!options || (!options.message && !options.title)) return;

        const type = options.type || 'info';
        const cfg = TYPE_CONFIG[type] || TYPE_CONFIG.info;
        const timeout = options.timeout ?? DEFAULT_TIMEOUT;

        const container = getOrCreateContainer();

        const item = document.createElement('div');
        item.className = `nova-toast-item is-${type}`;
        item.setAttribute('role', cfg.role);
        item.setAttribute('aria-live', cfg.live);

        const icon = document.createElement('i');
        icon.className = `bi ${cfg.icon} nova-toast-icon`;

        const body = document.createElement('div');
        body.className = 'nova-toast-body';
        if (options.title) {
            const titleEl = document.createElement('strong');
            titleEl.className = 'nova-toast-title';
            titleEl.textContent = options.title;
            body.appendChild(titleEl);
        }
        if (options.message) {
            const msgEl = document.createElement('span');
            msgEl.className = 'nova-toast-message';
            msgEl.textContent = options.message;
            body.appendChild(msgEl);
        }

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'nova-toast-close';
        closeBtn.setAttribute('aria-label', 'Cerrar mensaje');
        closeBtn.innerHTML = '<i class="bi bi-x"></i>';

        const progress = document.createElement('div');
        progress.className = 'nova-toast-progress';
        progress.style.animationDuration = `${timeout}ms`;

        item.append(icon, body, closeBtn);
        item.appendChild(progress);
        container.appendChild(item);

        // Animate in (next frame so transition fires)
        requestAnimationFrame(() => item.classList.add('is-visible'));

        function dismiss() {
            item.classList.remove('is-visible');
            item.classList.add('is-hiding');
            setTimeout(() => item.remove(), 260);
        }

        closeBtn.addEventListener('click', dismiss);

        closeBtn.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dismiss(); }
        });

        // Escape key while toast is focused
        item.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') dismiss();
        });

        let timer = timeout > 0 ? setTimeout(dismiss, timeout) : null;

        // Pause on hover
        item.addEventListener('mouseenter', () => {
            if (timer) { clearTimeout(timer); timer = null; }
            progress.style.animationPlayState = 'paused';
        });
        item.addEventListener('mouseleave', () => {
            progress.style.animationPlayState = 'running';
            if (timeout > 0) timer = setTimeout(dismiss, 1200);
        });

        return { dismiss };
    }

    // Process [data-nova-flash] elements injected server-side
    function processFlashElements() {
        document.querySelectorAll('[data-nova-flash]').forEach((el) => {
            const type = el.dataset.novaFlash || 'info';
            const message = el.dataset.novaFlashMessage || el.textContent.trim() || '';
            const title = el.dataset.novaFlashTitle || undefined;
            const timeout = el.dataset.novaFlashTimeout ? Number(el.dataset.novaFlashTimeout) : undefined;
            if (message || title) show({ type, message, title, timeout });
            el.remove();
        });
    }

    // Convenience shorthands
    function success(message, title)  { return show({ type: 'success', message, title }); }
    function error(message, title)    { return show({ type: 'error',   message, title }); }
    function warning(message, title)  { return show({ type: 'warning', message, title }); }
    function info(message, title)     { return show({ type: 'info',    message, title }); }
    function loading(message, title)  { return show({ type: 'loading', message, title }); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', processFlashElements);
    } else {
        processFlashElements();
    }

    return { show, success, error, warning, info, loading };
})();

// Bridge: keep window.appUi.toast pointing to NovaToast
window.appUi = window.appUi || {};
window.appUi.toast = (message, tone = 'info') => NovaToast.show({ type: tone, message });

// Compat: expose appModal.show as NovaToast wrapper if appModal not already defined
if (!window.appModal) {
    window.appModal = {
        show: (options) => NovaToast.show({
            type: options.tone || 'info',
            message: options.message || options.title || '',
            title: options.kicker || undefined,
        }),
    };
}

/**
 * NovaDrawer — lateral drawer system for NOVA UI
 * Handles .nova-drawer elements independently of Bootstrap Modal JS.
 * Use data-nova-drawer-open="id" and data-nova-drawer-close on triggers.
 * Bootstrap Modals with detail-drawer-modal class are managed by Bootstrap's own JS.
 */
const NovaDrawer = (() => {
    function open(id) {
        const el = typeof id === 'string' ? document.getElementById(id) : id;
        if (!el) return;
        el.classList.add('show');
        el.removeAttribute('aria-hidden');
        el.setAttribute('aria-modal', 'true');
        el.style.display = 'block';
        document.body.classList.add('modal-open');
        el.dispatchEvent(new CustomEvent('nova-drawer:open', { bubbles: true }));
    }

    function close(el) {
        if (typeof el === 'string') el = document.getElementById(el);
        if (!el) return;
        el.classList.remove('show');
        el.setAttribute('aria-hidden', 'true');
        el.removeAttribute('aria-modal');
        el.style.display = '';
        if (!document.querySelector('.nova-drawer.show')) {
            document.body.classList.remove('modal-open');
        }
        el.dispatchEvent(new CustomEvent('nova-drawer:close', { bubbles: true }));
    }

    function toggle(id) {
        const el = typeof id === 'string' ? document.getElementById(id) : id;
        if (!el) return;
        el.classList.contains('show') ? close(el) : open(id);
    }

    document.addEventListener('click', (e) => {
        const opener = e.target.closest('[data-nova-drawer-open]');
        if (opener) {
            e.preventDefault();
            open(opener.dataset.novaDrawerOpen);
            return;
        }
        const closer = e.target.closest('[data-nova-drawer-close]');
        if (closer) {
            e.preventDefault();
            close(closer.closest('.nova-drawer'));
            return;
        }
        if (e.target.classList.contains('nova-drawer') && !e.target.hasAttribute('data-nova-session-modal')) {
            close(e.target);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.nova-drawer.show').forEach((el) => {
            if (!el.hasAttribute('data-nova-session-modal')) close(el);
        });
    });

    return { open, close, toggle };
})();
