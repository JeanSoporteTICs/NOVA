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

window.NovaToast = NovaToast;

/**
 * Shared NOVA UI bridge.
 *
 * Keep this assignment non-destructive while legacy modules still add their
 * own helpers to the same object during the staged migration.
 */
window.appUi = window.appUi || {};

const NovaAppUi = (() => {
    function setLoading(active) {
        document.querySelectorAll('.app-page-loader').forEach((loader) => {
            loader.classList.toggle('is-visible', !!active);
        });
        document.querySelectorAll('.nova-page-loader').forEach((loader) => {
            loader.classList.toggle('is-active', !!active);
        });
    }

    function toast(message, tone = 'info') {
        if (window.NovaToast?.show) {
            return window.NovaToast.show({ type: tone, message });
        }
        return undefined;
    }

    function resolveModal(target) {
        const query = (selector) => {
            try {
                return document.querySelector(selector);
            } catch (error) {
                return null;
            }
        };

        if (target instanceof Element) {
            const declaredTarget = target.getAttribute('data-nova-modal')
                || target.getAttribute('data-nova-modal-open')
                || target.getAttribute('data-bs-target');

            if (declaredTarget) {
                const selector = declaredTarget.startsWith('#') ? declaredTarget : `#${declaredTarget}`;
                return query(selector) || target;
            }

            return target;
        }

        if (typeof target !== 'string' || !target.trim()) return null;
        const value = target.trim();
        const selector = value.startsWith('#') ? value : `#${value}`;
        return query(selector) || query(value);
    }

    function cleanupModalState() {
        document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
        if (!document.querySelector('.modal.show, [data-nova-modal].show, [data-nova-modal].is-open')) {
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    }

    function openModal(target) {
        const modal = resolveModal(target);
        if (!modal) return;

        if (modal.parentElement !== document.body) document.body.appendChild(modal);

        if (modal.classList.contains('modal') && window.bootstrap?.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
            return;
        }

        modal.hidden = false;
        modal.classList.add('show');
        if (modal.hasAttribute('data-nova-modal')) modal.classList.add('is-open');
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');
        modal.style.display = 'block';
        document.body.classList.add('modal-open');
    }

    function closeModal(target) {
        const modal = resolveModal(target);
        if (!modal) return;

        if (modal.classList.contains('modal') && window.bootstrap?.Modal) {
            modal.addEventListener('hidden.bs.modal', cleanupModalState, { once: true });
            window.bootstrap.Modal.getOrCreateInstance(modal).hide();
            window.setTimeout(() => {
                if (!modal.classList.contains('show')) cleanupModalState();
            }, 260);
            return;
        }

        modal.classList.remove('show', 'is-open');
        modal.setAttribute('aria-hidden', 'true');
        modal.removeAttribute('aria-modal');
        modal.style.display = 'none';
        if (modal.hasAttribute('data-nova-modal')) modal.hidden = true;
        cleanupModalState();
    }

    function loadingDisabled(element) {
        return element instanceof Element
            && element.closest('[data-app-no-loading], [data-no-page-loader]') !== null;
    }

    function registerLoadingListeners() {
        if (window.appUi.__novaGlobalLoadingListeners) return;
        window.appUi.__novaGlobalLoadingListeners = true;

        document.addEventListener('click', (event) => {
            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!link || event.defaultPrevented || link.target === '_blank' || loadingDisabled(link)) return;

            try {
                const destination = new URL(link.href, window.location.href);
                if (destination.origin !== window.location.origin) return;
            } catch (error) {
                return;
            }

            window.appUi.setLoading(true);
        });

        document.addEventListener('submit', (event) => {
            if (event.defaultPrevented || loadingDisabled(event.target)) return;
            window.appUi.setLoading(true);
        });

        window.addEventListener('pageshow', () => window.appUi.setLoading(false));
        window.addEventListener('load', () => window.appUi.setLoading(false));
    }

    return { setLoading, toast, openModal, closeModal, registerLoadingListeners };
})();

window.appUi.setLoading = NovaAppUi.setLoading;
window.appUi.toast = NovaAppUi.toast;
window.appUi.openModal = NovaAppUi.openModal;
window.appUi.closeModal = NovaAppUi.closeModal;
NovaAppUi.registerLoadingListeners();

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
 * NovaSearchSelect — searchable dropdown for datalist-like fields.
 *
 * Markup:
 *   <div class="nova-search-select" data-search-select data-options='["A","B"]'>
 *     <input data-search-select-input>
 *     <div data-search-select-menu hidden></div>
 *   </div>
 *
 * Optional object options: [{ "label": "Jean", "value": "12" }]
 * Optional hidden value input: data-value-input="#field-id"
 */
const NovaSearchSelect = (() => {
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char]));

    const normalize = value => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();

    function parseOptions(rawOptions) {
        let options = [];
        try {
            options = Array.isArray(rawOptions) ? rawOptions : JSON.parse(rawOptions || '[]');
        } catch (error) {
            options = [];
        }

        options = options.map(option => {
            if (option && typeof option === 'object') {
                const label = String(option.label || option.nombre || option.name || option.value || '').trim();
                const value = String(option.value || option.id || label).trim();
                const search = String(option.search || option.searchText || '').trim();
                return label && value ? { label, value, search } : null;
            }

            const label = String(option || '').trim();
            return label ? { label, value: label, search: '' } : null;
        }).filter(Boolean);

        return Array.from(new Map(options.map(option => [option.value, option])).values());
    }

    function init(root = document) {
        root.querySelectorAll('[data-search-select]:not([data-search-select-ready])').forEach(wrapper => {
            const input = wrapper.querySelector('[data-search-select-input]');
            const menu = wrapper.querySelector('[data-search-select-menu]');
            const clearButton = wrapper.querySelector('[data-search-select-clear]');
            if (!input || !menu) return;

            wrapper.setAttribute('data-search-select-ready', 'true');

            const valueInput = wrapper.dataset.valueInput
                ? document.querySelector(wrapper.dataset.valueInput)
                : null;
            const options = parseOptions(wrapper.dataset.options || '[]');
            const maxVisible = Number(wrapper.dataset.maxVisible || 60);
            const preserveValueOnClear = wrapper.hasAttribute('data-preserve-value-on-clear');
            let activeIndex = -1;
            let selectedValue = String(valueInput?.value || '');

            const hide = () => {
                menu.hidden = true;
                activeIndex = -1;
            };

            const clear = () => {
                input.value = '';
                if (valueInput) {
                    valueInput.value = preserveValueOnClear ? selectedValue : '';
                }
                input.dispatchEvent(new CustomEvent('nova:search-select-clear', { bubbles: true }));
                input.focus();
                hide();
            };

            const setActive = index => {
                const items = Array.from(menu.querySelectorAll('[data-search-option]'));
                if (!items.length) {
                    activeIndex = -1;
                    return;
                }
                activeIndex = Math.max(0, Math.min(index, items.length - 1));
                items.forEach((item, itemIndex) => item.classList.toggle('is-active', itemIndex === activeIndex));
                items[activeIndex]?.scrollIntoView({ block: 'nearest' });
            };

            const syncExactValue = () => {
                if (!valueInput) return;
                const selected = options.find(option => normalize(option.label) === normalize(input.value));
                valueInput.value = selected ? selected.value : '';
                if (selected) selectedValue = selected.value;
            };

            const choose = option => {
                input.value = option.label;
                if (valueInput) {
                    valueInput.value = option.value;
                    selectedValue = option.value;
                }
                input.dispatchEvent(new Event('change', { bubbles: true }));
                hide();
            };

            const render = () => {
                const term = normalize(input.value);
                const filtered = options
                    .filter(option => normalize(`${option.label} ${option.search}`).includes(term))
                    .slice(0, maxVisible);

                if (!filtered.length) {
                    menu.innerHTML = '<div class="nova-search-select__empty">Sin resultados</div>';
                    menu.hidden = false;
                    activeIndex = -1;
                    return;
                }

                menu.innerHTML = filtered.map(option => (
                    `<button type="button" class="nova-search-select__option" role="option" data-search-option data-search-value="${escapeHtml(option.value)}">${escapeHtml(option.label)}</button>`
                )).join('');
                menu.hidden = false;
                activeIndex = -1;
            };

            input.addEventListener('focus', render);
            input.addEventListener('input', () => {
                syncExactValue();
                render();
            });
            input.addEventListener('search', () => {
                if (input.value === '') clear();
            });
            input.addEventListener('keydown', event => {
                const items = Array.from(menu.querySelectorAll('[data-search-option]'));
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    if (menu.hidden) render();
                    setActive(activeIndex + 1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    setActive(activeIndex <= 0 ? items.length - 1 : activeIndex - 1);
                } else if (event.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
                    event.preventDefault();
                    choose({
                        label: items[activeIndex].textContent || '',
                        value: items[activeIndex].dataset.searchValue || items[activeIndex].textContent || ''
                    });
                } else if (event.key === 'Escape') {
                    hide();
                }
            });

            menu.addEventListener('mousedown', event => {
                const option = event.target.closest('[data-search-option]');
                if (!option) return;
                event.preventDefault();
                choose({
                    label: option.textContent || '',
                    value: option.dataset.searchValue || option.textContent || ''
                });
            });

            clearButton?.addEventListener('click', clear);

            document.addEventListener('mousedown', event => {
                if (!wrapper.contains(event.target)) hide();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => init());
    } else {
        init();
    }

    return { init };
})();

window.NovaSearchSelect = NovaSearchSelect;

/** Excel/TSV paste conversion and safe preview for description textareas. */
const NovaDescriptionTables = (() => {
    const cleanCell = value => String(value || '')
        .replace(/\r?\n/g, '<br>')
        .replace(/\|/g, '\\|')
        .trim();

    const rowsToMarkdown = rows => {
        const normalized = rows
            .map(row => row.map(cleanCell))
            .filter(row => row.some(cell => cell !== ''));
        const columnCount = normalized.reduce((max, row) => Math.max(max, row.length), 0);
        if (!normalized.length || columnCount < 2) return '';
        normalized.forEach(row => {
            while (row.length < columnCount) row.push('');
        });
        const line = row => `| ${row.join(' | ')} |`;
        return [
            line(normalized[0]),
            line(Array(columnCount).fill('---')),
            ...normalized.slice(1).map(line),
        ].join('\n');
    };

    const clipboardRows = clipboardData => {
        const html = clipboardData?.getData('text/html') || '';
        if (html) {
            const table = new DOMParser().parseFromString(html, 'text/html').querySelector('table');
            if (table) {
                return Array.from(table.rows).map(row =>
                    Array.from(row.cells).map(cell => cell.innerText || cell.textContent || '')
                );
            }
        }
        const text = clipboardData?.getData('text/plain') || '';
        return text.includes('\t')
            ? text.replace(/\r\n?/g, '\n').split('\n').map(row => row.split('\t'))
            : [];
    };

    const installPaste = input => {
        if (!input || input.dataset.descriptionTablePasteReady === 'true') return;
        input.dataset.descriptionTablePasteReady = 'true';
        input.addEventListener('paste', event => {
            const markdown = rowsToMarkdown(clipboardRows(event.clipboardData));
            if (!markdown) return;
            event.preventDefault();
            const start = input.selectionStart ?? input.value.length;
            const end = input.selectionEnd ?? start;
            const before = input.value.slice(0, start);
            const after = input.value.slice(end);
            const prefix = before && !before.endsWith('\n') ? '\n' : '';
            const suffix = after && !after.startsWith('\n') ? '\n' : '';
            input.value = `${before}${prefix}${markdown}${suffix}${after}`;
            const cursor = before.length + prefix.length + markdown.length;
            input.setSelectionRange(cursor, cursor);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    };

    const cells = line => line.trim().replace(/^\||\|$/g, '').split('|')
        .map(cell => cell.trim().replace(/<br\s*\/?>/gi, '\n').replace(/\\\|/g, '|'));

    const render = (input, preview) => {
        if (!input || !preview) return;
        preview.replaceChildren();
        const value = input.value.trim();
        if (!value) {
            const empty = document.createElement('div');
            empty.className = 'nova-empty-state';
            empty.innerHTML = '<i class="bi bi-text-paragraph"></i><strong>Sin descripción</strong><p>No hay contenido para previsualizar.</p>';
            preview.appendChild(empty);
            return;
        }
        const lines = value.split(/\r?\n/).filter(line => line.trim());
        if (!lines.length || lines.some(line => !line.includes('|'))) {
            const text = document.createElement('div');
            text.className = 'nova-description-preview__text';
            text.textContent = value;
            preview.appendChild(text);
            return;
        }
        const separator = /^\s*\|?(?:\s*:?-{3,}:?\s*\|)+\s*$/;
        const hasHeader = lines.length > 1 && separator.test(lines[1]);
        const wrapper = document.createElement('div');
        wrapper.className = 'table-responsive';
        const table = document.createElement('table');
        table.className = 'table table-sm table-bordered align-middle mb-0 nova-description-table';
        const tbody = document.createElement('tbody');
        const appendRow = (target, line, tag) => {
            const row = document.createElement('tr');
            cells(line).forEach(value => {
                const cell = document.createElement(tag);
                cell.textContent = value;
                row.appendChild(cell);
            });
            target.appendChild(row);
        };
        if (hasHeader) {
            const thead = document.createElement('thead');
            appendRow(thead, lines[0], 'th');
            table.appendChild(thead);
            lines.slice(2).forEach(line => appendRow(tbody, line, 'td'));
        } else {
            lines.forEach(line => appendRow(tbody, line, 'td'));
        }
        table.appendChild(tbody);
        wrapper.appendChild(table);
        preview.appendChild(wrapper);
    };

    const bind = ({ input, editTab, previewTab, editPanel, previewPanel }) => {
        if (!input || !editTab || !previewTab || !editPanel || !previewPanel) return;
        installPaste(input);
        if (editTab.dataset.descriptionTabsReady === 'true') return;
        editTab.dataset.descriptionTabsReady = 'true';
        const show = previewMode => {
            if (previewMode) render(input, previewPanel);
            editPanel.hidden = previewMode;
            previewPanel.hidden = !previewMode;
            editTab.classList.toggle('is-active', !previewMode);
            previewTab.classList.toggle('is-active', previewMode);
            editTab.setAttribute('aria-selected', previewMode ? 'false' : 'true');
            previewTab.setAttribute('aria-selected', previewMode ? 'true' : 'false');
        };
        editTab.addEventListener('click', () => show(false));
        previewTab.addEventListener('click', () => show(true));
        show(false);
        return { show, render: () => render(input, previewPanel) };
    };

    return { bind, installPaste, render, rowsToMarkdown };
})();

window.NovaDescriptionTables = NovaDescriptionTables;

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

window.NovaDrawer = NovaDrawer;

/**
 * NovaOptimisticToggle — optimistic UI for icon-toggle buttons submitted as a form
 * (e.g. the "Hora Extra" clock icon on Redmine TIC / Redmine Mantencion dashboards).
 *
 * On click: flips the icon/classes immediately, disables the button, marks it
 * aria-busy and spinning (reusing .is-submitting), then sends the form via fetch.
 * On success: keeps the optimistic state. On failure: reverts it and shows a
 * NovaToast error. Never touches the rest of the page.
 *
 * Markup contract:
 *   <form method="post" action="..." data-optimistic-toggle
 *         data-toggle-active-icon="bi-clock-fill" data-toggle-inactive-icon="bi-clock"
 *         data-toggle-active-class="btn-warning" data-toggle-inactive-class="btn-outline-secondary"
 *         data-toggle-active-title="Quitar hora extra" data-toggle-inactive-title="Marcar hora extra">
 *     @csrf (or the module's own hidden CSRF field)
 *     <input type="hidden" name="hora_extra" value="1">   <!-- optional: flipped after success -->
 *     <button type="submit"><i class="bi bi-clock"></i></button>
 *   </form>
 *
 * data-toggle-active-class / -inactive-class and -active-title / -inactive-title are optional.
 *
 * Server contract (only when the request is AJAX): JSON { ok: boolean, message?: string }.
 * The button's next visual state is decided entirely on the client before the request is
 * sent — the server response is only used to confirm or roll it back.
 */
const NovaOptimisticToggle = (() => {
    function classList(value) {
        return String(value || '').split(/\s+/).filter(Boolean);
    }

    function applyState(form, button, icon, active) {
        const activeIcon = form.dataset.toggleActiveIcon;
        const inactiveIcon = form.dataset.toggleInactiveIcon;
        if (activeIcon) icon.classList.toggle(activeIcon, active);
        if (inactiveIcon) icon.classList.toggle(inactiveIcon, !active);

        classList(form.dataset.toggleActiveClass).forEach((cls) => button.classList.toggle(cls, active));
        classList(form.dataset.toggleInactiveClass).forEach((cls) => button.classList.toggle(cls, !active));

        const title = active ? form.dataset.toggleActiveTitle : form.dataset.toggleInactiveTitle;
        if (title) {
            button.setAttribute('title', title);
            button.setAttribute('aria-label', title);
            if (window.bootstrap?.Tooltip && button.classList.contains('action-tooltip')) {
                window.bootstrap.Tooltip.getInstance(button)?.dispose();
                new window.bootstrap.Tooltip(button);
            }
        }

        // Domain-specific but harmless elsewhere: some forms carry the next target
        // value to send in a hidden "hora_extra" field; keep it in sync so a second
        // click (without a full page reload) still submits the correct value.
        const hiddenValue = form.querySelector('input[name="hora_extra"]');
        if (hiddenValue) hiddenValue.value = active ? '0' : '1';

        // Let the page react to the new state (e.g. keep a sibling "edit" button's
        // own data attributes in sync) without this shared function knowing about
        // any page-specific markup.
        form.dispatchEvent(new CustomEvent('nova-optimistic-toggle:change', { bubbles: true, detail: { active } }));
    }

    async function handle(form) {
        const button = form.querySelector('button[type="submit"]');
        const icon = button?.querySelector('i.bi');
        const activeIcon = form.dataset.toggleActiveIcon;
        if (!button || !icon || !activeIcon || button.disabled) return;

        const wasActive = icon.classList.contains(activeIcon);
        const previousTitle = button.getAttribute('title') || '';
        const previousAriaLabel = button.getAttribute('aria-label') || '';

        applyState(form, button, icon, !wasActive);
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.classList.add('is-submitting');

        try {
            const response = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: new FormData(form),
            });
            const raw = await response.text();
            let payload = {};
            try {
                payload = raw ? JSON.parse(raw) : {};
            } catch (parseError) {
                throw new Error('El servidor respondio en un formato inesperado.');
            }
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.message || 'No se pudo actualizar el estado.');
            }
        } catch (error) {
            applyState(form, button, icon, wasActive);
            if (previousTitle) {
                button.setAttribute('title', previousTitle);
                button.setAttribute('aria-label', previousAriaLabel || previousTitle);
            }
            window.NovaToast?.error(error.message || 'No se pudo actualizar el estado.');
        } finally {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.classList.remove('is-submitting');
        }
    }

    // Registered on the CAPTURE phase and stops propagation so this always wins
    // over any other document-level submit listener (page loaders, integration
    // spinners, etc.) regardless of script load order — this form must never
    // trigger a page-wide loading indicator, only its own button.
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-optimistic-toggle]')) return;
        event.preventDefault();
        event.stopPropagation();
        handle(form);
    }, true);

    return { handle };
})();

window.NovaOptimisticToggle = NovaOptimisticToggle;

// Shared "back to top" button — wires up any .nova-scroll-top button on the
// page (moves it to <body>, toggles visibility past 220px of scroll, smooth-
// scrolls on click). Consolidates what used to be 3 near-identical copies in
// Historico/historico.php, Usuarios/usuarios.php and RedmineTic/native.blade.php.
// RM Dashboard's .dashboard-scroll-top is intentionally NOT covered here — it
// has its own IntersectionObserver-based visibility logic tied to a specific
// scroll target, not just a raw scroll-position threshold.
const NovaScrollTop = (() => {
    function init() {
        document.querySelectorAll('.nova-scroll-top').forEach((btn) => {
            if (btn.parentElement !== document.body) {
                document.body.appendChild(btn);
            }
            const update = () => {
                // setProperty(..., 'important') because .nova-scroll-top's own
                // display:none in nova-ui.css is !important (needed to beat the
                // global .btn{display:inline-flex!important} rule) — a plain
                // non-important inline assignment here would never win against it.
                btn.style.setProperty('display', (window.scrollY || document.documentElement.scrollTop || 0) > 220 ? 'flex' : 'none', 'important');
            };
            btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
            window.addEventListener('scroll', update, { passive: true });
            window.addEventListener('resize', update);
            update();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return { init };
})();

window.NovaScrollTop = NovaScrollTop;
