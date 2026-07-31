(() => {
    const script = document.currentScript;
    const statusUrl = script?.dataset.monitorStatusUrl || '';

    function initServerForm() {
        const form = document.querySelector('[data-monitor-server-form]');
        if (!form) return;

        const type = form.querySelector('[data-monitor-type]');
        const port = form.querySelector('[data-monitor-port]');
        const portField = form.querySelector('[data-monitor-port-field]');
        const endpointRow = form.querySelector('[data-monitor-endpoint-row]');
        const destination = form.querySelector('[data-monitor-destination]');
        const destinationLabel = form.querySelector('[data-monitor-destination-label]');
        const destinationHelp = form.querySelector('[data-monitor-destination-help]');
        const methodHelp = form.querySelector('[data-monitor-method-help]');
        const sslField = form.querySelector('[data-monitor-ssl-field]');
        let previousType = type?.value || 'tcp';

        const update = () => {
            const selected = type?.value || 'tcp';
            const isTcp = selected === 'tcp';
            const isHostOnly = selected === 'tcp' || selected === 'icmp';
            portField?.classList.toggle('is-hidden', !isTcp);
            endpointRow?.classList.toggle('is-single', !isTcp);
            sslField?.classList.toggle('is-hidden', selected !== 'https');
            if (port && selected !== previousType) {
                port.value = isTcp ? '' : (selected === 'https' ? '443' : (selected === 'http' ? '80' : ''));
            }
            if (port) port.required = isTcp;

            const content = {
                icmp: {
                    label: 'Host o IP',
                    placeholder: '10.63.123.249',
                    help: 'Ingresa la IP o nombre del equipo, sin protocolo ni puerto.',
                    method: 'Ping / ICMP comprueba si el equipo responde a solicitudes de eco.',
                },
                tcp: {
                    label: 'Host o IP',
                    placeholder: '10.63.123.249',
                    help: 'Ingresa la IP o nombre del servidor; el puerto se configura arriba.',
                    method: 'TCP comprueba si el puerto acepta conexiones.',
                },
                http: {
                    label: 'URL o destino HTTP',
                    placeholder: 'http://servidor/health',
                    help: 'Puedes incluir http:// y la ruta completa. Si lo omites, NOVA lo añade automáticamente.',
                    method: 'HTTP considera disponible cualquier respuesta menor a 500.',
                },
                https: {
                    label: 'URL o destino HTTPS',
                    placeholder: 'https://www.hbvaldivia.cl/',
                    help: 'Puedes incluir https:// y la ruta completa. Si lo omites, NOVA lo añade automáticamente.',
                    method: 'HTTPS considera disponible cualquier respuesta menor a 500 y permite validar el certificado.',
                },
            }[selected];

            if (destinationLabel) destinationLabel.textContent = content.label;
            if (destination) {
                destination.placeholder = content.placeholder;
                destination.inputMode = isHostOnly ? 'text' : 'url';
                if (selected !== previousType && /^https?:\/\//i.test(destination.value)) {
                    if (isHostOnly) {
                        try {
                            destination.value = new URL(destination.value).hostname.replace(/^\[|\]$/g, '');
                        } catch (_) {
                            // La validación del servidor mostrará el formato correcto si la URL es inválida.
                        }
                    } else {
                        destination.value = destination.value.replace(/^https?:\/\//i, `${selected}://`);
                    }
                }
            }
            if (destinationHelp) destinationHelp.textContent = content.help;
            if (methodHelp) methodHelp.textContent = content.method;
            previousType = selected;
        };

        type?.addEventListener('change', update);
        update();
    }

    function initDeleteModal() {
        const modalElement = document.getElementById('monitor-delete-modal');
        if (!modalElement || typeof bootstrap === 'undefined') return;

        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        const form = modalElement.querySelector('[data-monitor-delete-form]');
        const label = modalElement.querySelector('[data-monitor-delete-label]');

        document.querySelectorAll('[data-monitor-delete]').forEach((button) => {
            button.addEventListener('click', () => {
                if (form) form.action = button.dataset.monitorDeleteUrl || '';
                if (label) label.textContent = button.dataset.monitorDeleteName || 'este servidor';
                modal.show();
            });
        });
    }

    function initServerDrawer() {
        const drawerElement = document.getElementById('monitor-server-drawer');
        if (!drawerElement || typeof bootstrap === 'undefined') return;

        if (drawerElement.hasAttribute('data-monitor-drawer-auto-open')) {
            bootstrap.Offcanvas.getOrCreateInstance(drawerElement).show();
        }
    }

    function bindFilter(inputSelector, itemSelector, dataKey) {
        const input = document.querySelector(inputSelector);
        const items = Array.from(document.querySelectorAll(itemSelector));
        if (!input || items.length === 0) return;

        input.addEventListener('input', () => {
            const query = input.value.trim().toLocaleLowerCase('es');
            items.forEach((item) => {
                const haystack = (item.dataset[dataKey] || '').toLocaleLowerCase('es');
                item.hidden = query !== '' && !haystack.includes(query);
            });
        });
    }

    function initServerInventory() {
        const list = document.querySelector('[data-monitor-server-list]');
        const search = document.querySelector('[data-monitor-search]');
        const pageSizeSelect = document.querySelector('[data-monitor-page-size]');
        const summary = document.querySelector('[data-monitor-pagination-summary]');
        const pages = document.querySelector('[data-monitor-pagination-pages]');
        const empty = document.querySelector('[data-monitor-filter-empty]');
        const items = list ? Array.from(list.querySelectorAll('[data-monitor-filter]')) : [];
        if (!list || !search || !pageSizeSelect || !summary || !pages || items.length === 0) return;

        let currentPage = 1;

        const pageButton = (label, targetPage, options = {}) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `monitor-page-button${options.active ? ' is-active' : ''}`;
            button.disabled = Boolean(options.disabled);
            button.setAttribute('aria-label', options.ariaLabel || `Página ${label}`);
            if (options.active) button.setAttribute('aria-current', 'page');

            if (options.icon) {
                const icon = document.createElement('i');
                icon.className = `bi ${options.icon}`;
                icon.setAttribute('aria-hidden', 'true');
                button.append(icon);
            } else {
                button.textContent = String(label);
            }

            button.addEventListener('click', () => {
                currentPage = targetPage;
                render();
            });

            return button;
        };

        const render = () => {
            const query = search.value.trim().toLocaleLowerCase('es');
            const requestedPageSize = Number(pageSizeSelect.value);
            const pageSize = [10, 25, 50].includes(requestedPageSize) ? requestedPageSize : 10;
            const filtered = items.filter((item) => {
                const haystack = (item.dataset.monitorFilter || '').toLocaleLowerCase('es');
                return query === '' || haystack.includes(query);
            });
            const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
            currentPage = Math.min(Math.max(1, currentPage), totalPages);
            const start = (currentPage - 1) * pageSize;
            const end = Math.min(start + pageSize, filtered.length);
            const visible = new Set(filtered.slice(start, end));

            items.forEach((item) => {
                item.hidden = !visible.has(item);
            });
            if (empty) empty.hidden = filtered.length !== 0;

            summary.textContent = filtered.length === 0
                ? '0 servidores encontrados'
                : `Mostrando ${start + 1}-${end} de ${filtered.length} servidor(es)`;

            pages.replaceChildren();
            pages.append(pageButton('Anterior', currentPage - 1, {
                disabled: currentPage === 1,
                ariaLabel: 'Página anterior',
                icon: 'bi-chevron-left',
            }));

            const visiblePages = Array.from(new Set([
                1,
                currentPage - 2,
                currentPage - 1,
                currentPage,
                currentPage + 1,
                currentPage + 2,
                totalPages,
            ]))
                .filter((page) => page >= 1 && page <= totalPages)
                .sort((a, b) => a - b);

            visiblePages.forEach((page, index) => {
                if (index > 0 && page - visiblePages[index - 1] > 1) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'monitor-page-ellipsis';
                    ellipsis.textContent = '…';
                    ellipsis.setAttribute('aria-hidden', 'true');
                    pages.append(ellipsis);
                }
                pages.append(pageButton(page, page, { active: page === currentPage }));
            });

            pages.append(pageButton('Siguiente', currentPage + 1, {
                disabled: currentPage === totalPages,
                ariaLabel: 'Página siguiente',
                icon: 'bi-chevron-right',
            }));
        };

        search.addEventListener('input', () => {
            currentPage = 1;
            render();
        });
        pageSizeSelect.addEventListener('change', () => {
            currentPage = 1;
            render();
        });
        render();
    }

    function initRecipientCount() {
        const count = document.querySelector('[data-monitor-recipient-count]');
        const toggles = Array.from(document.querySelectorAll('[data-monitor-recipient-toggle]'));
        if (!count || toggles.length === 0) return;

        const update = () => {
            count.textContent = String(toggles.filter((toggle) => toggle.checked && !toggle.disabled).length);
        };
        toggles.forEach((toggle) => toggle.addEventListener('change', update));
        update();
    }

    function initCheckAll() {
        const form = document.querySelector('[data-monitor-check-all]');
        const button = form?.querySelector('button[type="submit"]');
        if (!form || !button) return;

        form.addEventListener('submit', () => {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.innerHTML = '<span class="nova-spinner" aria-hidden="true"></span><span>Comprobando…</span>';
        });
    }

    function stateLabel(state) {
        return {
            arriba: 'Disponible',
            abajo: 'Caído',
            degradado: 'Inestable',
            pendiente: 'Pendiente',
            inactivo: 'Pausado',
        }[state] || state;
    }

    async function refreshDashboard() {
        if (!statusUrl || !document.querySelector('[data-monitor-server]')) return;

        try {
            const response = await fetch(statusUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;
            const payload = await response.json();

            Object.entries(payload.stats || {}).forEach(([key, value]) => {
                const targetKey = key === 'degraded' ? null : key;
                if (targetKey) {
                    const element = document.querySelector(`[data-monitor-stat="${targetKey}"]`);
                    if (element) {
                        const total = key === 'pending'
                            ? Number(value || 0) + Number(payload.stats?.degraded || 0)
                            : Number(value || 0);
                        element.textContent = String(total);
                    }
                }
            });

            (payload.servers || []).forEach((server) => {
                const row = document.querySelector(`[data-monitor-server="${server.id}"]`);
                if (!row) return;
                const state = row.querySelector('[data-monitor-state]');
                if (state) {
                    state.className = `monitor-server-overview-icon is-${server.state}`;
                    state.setAttribute('aria-label', `Estado: ${stateLabel(server.state)}`);
                    state.setAttribute('title', stateLabel(server.state));
                }
                const latency = row.querySelector('[data-monitor-latency]');
                if (latency) latency.textContent = server.latency_ms === null ? '—' : `${server.latency_ms} ms`;
                const lastCheck = row.querySelector('[data-monitor-last-check]');
                if (lastCheck) lastCheck.textContent = server.last_check_text || 'Sin comprobar';
            });

            const worker = document.querySelector('[data-monitor-worker]');
            if (worker) {
                const healthy = Boolean(payload.worker?.healthy);
                worker.classList.toggle('is-online', healthy);
                worker.classList.toggle('is-offline', !healthy);
                const label = worker.querySelector('[data-monitor-worker-label]');
                const detail = worker.querySelector('[data-monitor-worker-detail]');
                if (label) label.textContent = healthy ? 'Monitoreando' : 'Sin actividad';
                if (detail) {
                    detail.textContent = payload.worker?.last_cycle
                        ? `Último ciclo: ${new Date(payload.worker.last_cycle).toLocaleString('es-CL')}`
                        : 'Aún no registra heartbeat';
                }
            }
        } catch (_) {
            // La próxima actualización vuelve a intentar sin interrumpir la vista.
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initServerForm();
        initServerDrawer();
        initDeleteModal();
        initRecipientCount();
        initCheckAll();
        initServerInventory();
        bindFilter('[data-monitor-recipient-search]', '[data-monitor-recipient-filter]', 'monitorRecipientFilter');
        refreshDashboard();
        if (statusUrl && document.querySelector('[data-monitor-server]')) {
            window.setInterval(refreshDashboard, 15000);
        }
    });
})();
