@php
    $redmineRoute = static function (string $name, array|string $parameters = []): string {
        if (is_string($parameters)) {
            $parameters = ['section' => $parameters];
        }

        return route($name, $parameters);
    };
    $sectionIcons = [
        'dashboard' => config('navigation-icons.reportes'),
        'webhook' => config('navigation-icons.reporte_manual'),
        'horas-extra' => config('navigation-icons.horas_extra'),
        'historico' => config('navigation-icons.historico'),
        'usuarios' => config('navigation-icons.usuarios'),
        'configuracion' => config('navigation-icons.configuracion'),
        'estadisticas' => config('navigation-icons.estadisticas'),
        'actividad' => config('navigation-icons.actividad'),
    ];
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Redmine - {{ $sectionLabel }}</title>
    @include('nova.partials.favicon')
    @php $novaSidebarPreloadVersion = @filemtime(public_path('assets/nova-sidebar-preload.js')) ?: '1'; @endphp
    <script src="{{ asset('assets/nova-sidebar-preload.js') }}?v={{ $novaSidebarPreloadVersion }}" data-nova-sidebar-key="redmine_tic"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    @if (in_array($section, ['dashboard', 'webhook'], true))
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    @endif
@php $nativeCssVersion = @filemtime(public_path('assets/redmine-tic-native.css')) ?: '1'; @endphp
    <link href="{{ asset('assets/redmine-tic-native.css') }}?v={{ $nativeCssVersion }}" rel="stylesheet">
@php $novaUiCssVersion = @filemtime(public_path('assets/nova-ui.css')) ?: '1'; @endphp
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ $novaUiCssVersion }}" rel="stylesheet">
</head>
<body class="nova-page {{ !empty($redmineMaintenance['enabled']) ? 'rm-maintenance-active' : '' }}">
    <div class="rm-shell">
        <nav class="navbar navbar-expand-lg navbar-dark rm-navbar">
            <div class="container-fluid px-4">
                <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="{{ $redmineRoute('redmine.dashboard') }}">
                    <span class="rm-brand-mark"><i class="bi bi-layout-sidebar-inset"></i></span>
                    <span>{{ $redmineProjectName ?? 'Backlog Soporte TI' }}</span>
                </a>
                <button class="nova-sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#novaSidebar" aria-controls="novaSidebar" aria-label="Abrir menú lateral">
                    <i class="bi bi-list"></i>
                </button>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#rmTopbar" aria-controls="rmTopbar" aria-expanded="false" aria-label="Alternar navegacion">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="rmTopbar">
                    <div class="rm-top-actions mt-3 mt-lg-0">
                        @include('nova.partials.session-control')
                        <span class="text-white-50 fw-bold"><i class="bi bi-person-circle"></i> @include('nova.partials.current-user-name')</span>
                        <a class="btn btn-outline-light" href="{{ route('home') }}"><i class="bi bi-house-door"></i>NOVA</a>
                        <form method="POST" action="{{ route('logout') }}" style="display:inline" data-maintenance-allowed="1">@csrf<button class="btn btn-outline-light" type="submit"><i class="bi bi-box-arrow-right"></i>Salir</button></form>
                    </div>
                </div>
            </div>
        </nav>

        <div class="nova-layout">
            <aside class="nova-sidebar offcanvas-lg offcanvas-start" id="novaSidebar" tabindex="-1" aria-labelledby="novaSidebarLabel">
                <div class="offcanvas-header d-lg-none border-bottom py-3">
                    <strong class="offcanvas-title fw-bold" id="novaSidebarLabel">{{ $redmineProjectName ?? 'Backlog Soporte TI' }}</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                </div>
                <nav class="nova-sidebar-body" aria-label="Secciones Redmine">
                    @foreach ($sections as $key => $label)
                        <a class="nova-sidebar-link {{ $section === $key ? 'active' : '' }}"
                           href="{{ $redmineRoute('redmine.native.section', $key) }}"
                           @if ($section === $key) aria-current="page" @endif>
                            <i class="bi {{ $sectionIcons[$key] ?? 'bi-window' }} nova-sidebar-icon"></i>
                            <span>{{ $label }}</span>
                        </a>
                    @endforeach
                    @if (!empty(data_get(session('redmine_project_user'), 'legacy.permisos.all')) || !empty(data_get(session('redmine_project_user'), 'legacy.permisos.mis_integraciones')))
                        <a class="nova-sidebar-link" href="{{ route('integrations.redmine_tic') }}">
                            <i class="bi {{ config('navigation-icons.cuentas_conectadas') }} nova-sidebar-icon"></i>
                            <span>Mis integraciones</span>
                        </a>
                    @endif
                </nav>
                @include('nova.partials.sidebar-compact-control', ['sidebarId' => 'novaSidebar'])
            </aside>

            <main class="nova-content rm-main">
                <section class="card card-hero sb-page-hero rm-hero mb-3">
                    <div class="card-body p-3 p-lg-4 d-flex align-items-center gap-3 flex-wrap">
                        <div class="d-flex align-items-center gap-3">
                            <span class="rm-hero-icon"><i class="bi bi-speedometer2"></i></span>
                            <div>
                                <h1 class="rm-page-title text-white">{{ $sectionLabel }}</h1>
                            </div>
                        </div>
                        <span class="rm-hero-retention"><i class="bi bi-archive"></i>Retencion procesados: {{ $redmineRetentionHours ?? 24 }} hora(s)</span>
                    </div>
                </section>

                @if (!empty($redmineMaintenance['enabled']))
                    <div class="nova-alert-card is-warning mb-3" role="status">
                        <i class="bi bi-tools"></i>
                        <span>Modulo en mantencion{{ !empty($redmineMaintenance['until_text']) ? ' hasta ' . $redmineMaintenance['until_text'] : '' }}. La edicion de datos esta desactivada.</span>
                    </div>
                @endif

                @if ($section === 'dashboard')
                    @include('redmine_tic::native-sections.dashboard')
                @elseif ($section === 'usuarios')
                    @include('redmine_tic::native-sections.users')
                @elseif ($section === 'configuracion')
                    @include('redmine_tic::native-sections.config')
                @elseif ($section === 'horas-extra')
                    @include('redmine_tic::native-sections.hours')
                @elseif ($section === 'historico')
                    @include('redmine_tic::native-sections.history')
                @elseif ($section === 'estadisticas')
                    @include('redmine_tic::native-sections.stats')
                @elseif ($section === 'actividad')
                    @include('redmine_tic::native-sections.activity')
                @else
                    @include('redmine_tic::native-sections.webhook')
                @endif
            </main><!-- /.nova-content -->
        </div><!-- /.nova-layout -->
    </div><!-- /.rm-shell -->
    @if (session('redmine_status'))
        @php
            $redmineStatusText = (string) session('redmine_status');
            $redmineStatusType = (string) session('redmine_status_type', '');
            if (!in_array($redmineStatusType, ['success', 'info', 'danger'], true)) {
                $lowerStatus = Str::lower($redmineStatusText);
                $redmineStatusType = Str::contains($lowerStatus, ['error', 'no se', 'no pudo', 'falta', 'http ', 'bloque', 'desactivada'])
                    ? 'error'
                    : (Str::contains($lowerStatus, ['sin cambios', 'solo se sincronizan', 'selecciona']) ? 'info' : 'success');
            }
        @endphp
        <div data-nova-flash="{{ $redmineStatusType }}" data-nova-flash-title="Redmine TIC" data-nova-flash-message="{{ $redmineStatusText }}" hidden></div>
    @endif

    <div class="nova-integration-overlay" id="nova-integration-overlay" role="status" aria-live="polite" aria-hidden="true">
        <div class="nova-integration-card">
            <span class="nova-integration-icon"><i class="bi bi-cloud-arrow-down"></i></span>
            <strong id="nova-integration-title">Consultando integración</strong>
            <span id="nova-integration-detail">La operación puede tardar unos segundos.</span>
            <div class="nova-integration-bar" aria-hidden="true"><i></i></div>
        </div>
    </div>

    <div class="modal fade rm-confirm-modal" id="rmConfirmModal" tabindex="-1" aria-labelledby="rmConfirmModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-3">
                        <span class="rm-confirm-icon"><i class="bi bi-exclamation-triangle"></i></span>
                        <div>
                            <p class="rm-confirm-kicker">Confirmacion requerida</p>
                            <h2 class="modal-title fs-5" id="rmConfirmModalTitle">Confirmar accion</h2>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="rm-confirm-message" data-confirm-message>Confirma esta accion.</p>
                </div>
                <div class="modal-footer rm-confirm-actions">
                    <button class="btn-nova btn-nova-secondary" type="button" data-bs-dismiss="modal" data-confirm-cancel>
                        <i class="bi bi-x-lg"></i>
                        <span data-confirm-cancel-label>Cancelar</span>
                    </button>
                    <button class="btn-nova btn-nova-danger" type="button" data-confirm-accept>
                        <i class="bi bi-check2"></i>
                        <span data-confirm-accept-label>Aceptar</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    @if (in_array($section, ['dashboard', 'historico', 'horas-extra'], true))
        <button id="redmine-tic-scroll-top" type="button" title="Volver arriba" aria-label="Volver arriba" class="btn btn-primary nova-scroll-top">
            <i class="bi bi-arrow-up"></i>
        </button>
    @endif

    @if (in_array($section, ['dashboard', 'webhook'], true))
        <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    @endif
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('assets/nova-ui.js') }}?v={{ @filemtime(public_path('assets/nova-ui.js')) ?: '1' }}"></script>
    <script>
        window.appUi = window.appUi || {};

        const promoteModalToBody = (modal) => {
            if (!modal || modal.parentElement === document.body) return;
            document.body.appendChild(modal);
        };

        document.addEventListener('click', (event) => {
            const modalTrigger = event.target.closest('[data-bs-toggle="modal"][data-bs-target]');
            if (!modalTrigger) return;
            const selector = modalTrigger.getAttribute('data-bs-target');
            if (!selector || !selector.startsWith('#')) return;
            promoteModalToBody(document.querySelector(selector));
        }, true);

        const integrationOverlay = document.getElementById('nova-integration-overlay');
        window.appUi.setIntegrationLoading = function (state, options = {}) {
            if (!integrationOverlay) return;
            const title = document.getElementById('nova-integration-title');
            const detail = document.getElementById('nova-integration-detail');
            const icon = integrationOverlay.querySelector('.nova-integration-icon i');
            if (state) {
                if (title) title.textContent = options.title || 'Consultando integración';
                if (detail) detail.textContent = options.detail || 'La operación puede tardar unos segundos.';
                if (icon) icon.className = 'bi ' + (options.icon || 'bi-cloud-arrow-down');
                integrationOverlay.classList.add('is-active');
                integrationOverlay.setAttribute('aria-hidden', 'false');
                document.body.classList.add('nova-integration-loading');
            } else {
                integrationOverlay.classList.remove('is-active');
                integrationOverlay.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('nova-integration-loading');
            }
        };

        const integrationCopyForForm = (form, submitter) => {
            const actionInput = form.querySelector('input[name="action"]');
            const action = `${actionInput?.value || ''} ${submitter?.value || ''} ${submitter?.textContent || ''}`.toLowerCase();
            if (!/(sync|sincron|import|fetch|consult|confirm|core|api)/i.test(action)) return null;
            if (/nextcloud/.test(action)) return { title: 'Procesando Nextcloud', detail: 'Conectando con Nextcloud y preparando la respuesta.', icon: 'bi-cloud-arrow-up' };
            if (/core/.test(action)) return { title: 'Consultando CORE', detail: 'Buscando y normalizando datos recibidos desde CORE.', icon: 'bi-database-down' };
            if (/redmine|sync|sincron/.test(action)) return { title: 'Sincronizando Redmine', detail: 'Actualizando catálogos y datos desde Redmine.', icon: 'bi-arrow-repeat' };
            if (/import/.test(action)) return { title: 'Importando datos', detail: 'Procesando archivo o datos externos.', icon: 'bi-file-earmark-arrow-up' };
            return { title: 'Consultando integración', detail: 'La operación puede tardar unos segundos.', icon: 'bi-cloud-arrow-down' };
        };

        const confirmModal = document.getElementById('rmConfirmModal');
        const confirmMessage = confirmModal?.querySelector('[data-confirm-message]');
        const confirmAccept = confirmModal?.querySelector('[data-confirm-accept]');
        const confirmAcceptLabel = confirmModal?.querySelector('[data-confirm-accept-label]');
        const confirmTitle = confirmModal?.querySelector('#rmConfirmModalTitle');
        const confirmCancelLabel = confirmModal?.querySelector('[data-confirm-cancel-label]');
        let pendingConfirmForm = null;
        let pendingConfirmSubmitter = null;
        let pendingConfirmCallback = null;

        const showConfirmModal = (message, options = {}) => {
            if (confirmMessage) confirmMessage.textContent = message;
            if (confirmTitle) confirmTitle.textContent = options.title || 'Confirmar accion';
            if (confirmAccept) {
                confirmAccept.hidden = options.accept === false;
                confirmAccept.disabled = options.accept === false;
                confirmAccept.classList.toggle('btn-nova-danger', (options.tone || 'danger') === 'danger');
                confirmAccept.classList.toggle('btn-nova-primary', (options.tone || 'danger') !== 'danger');
            }
            if (confirmAcceptLabel) confirmAcceptLabel.textContent = options.acceptText || 'Aceptar';
            if (confirmCancelLabel) {
                confirmCancelLabel.textContent = options.accept === false ? 'Entendido' : 'Cancelar';
            }
            if (confirmModal && window.bootstrap?.Modal) {
                if (confirmModal.parentElement !== document.body) {
                    document.body.appendChild(confirmModal);
                }
                confirmModal.style.zIndex = '5000';
                document.querySelectorAll('.offcanvas.show').forEach((drawer) => {
                    drawer.dataset.previousZIndex = drawer.style.zIndex || '';
                    drawer.style.zIndex = '3000';
                });
                window.bootstrap.Modal.getOrCreateInstance(confirmModal).show();
                window.setTimeout(() => {
                    confirmModal.style.zIndex = '5000';
                    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
                        backdrop.style.zIndex = backdrop.classList.contains('show') ? '4990' : '4980';
                    });
                }, 0);
            }
        };

        window.appUi.confirmAction = (message, onAccept, options = {}) => {
            pendingConfirmForm = null;
            pendingConfirmSubmitter = null;
            pendingConfirmCallback = typeof onAccept === 'function' ? onAccept : null;
            showConfirmModal(message, {
                title: options.title || 'Confirmar accion',
                accept: true,
                acceptText: options.acceptText || 'Aceptar',
                tone: options.tone || 'danger',
            });
        };

        document.addEventListener('submit', (event) => {
            const form = event.target;
            const submitter = event.submitter || document.activeElement?.closest?.('button, input[type="submit"]') || null;
            const blockMessage = submitter?.getAttribute('data-app-block-message')
                || form?.getAttribute('data-app-block-message')
                || '';
            if (blockMessage) {
                event.preventDefault();
                event.stopImmediatePropagation();
                pendingConfirmForm = null;
                showConfirmModal(blockMessage, { title: 'No se puede eliminar', accept: false });
                return;
            }

            const message = submitter?.getAttribute('data-app-confirm')
                || form?.getAttribute('data-app-confirm')
                || '';
            if (!message || form?.dataset.confirmAccepted === '1') return;

            event.preventDefault();
            event.stopImmediatePropagation();

            pendingConfirmForm = form;
            pendingConfirmSubmitter = submitter;
            showConfirmModal(message, {
                title: submitter?.dataset.appConfirmTitle || form.dataset.appConfirmTitle || 'Confirmar accion',
                accept: true,
                acceptText: submitter?.dataset.appConfirmText || form.dataset.appConfirmText || 'Aceptar',
                tone: submitter?.dataset.appConfirmTone || form.dataset.appConfirmTone || 'danger',
            });
            if (confirmModal && window.bootstrap?.Modal) return;

            pendingConfirmForm.dataset.confirmAccepted = '1';
            if (pendingConfirmSubmitter && pendingConfirmSubmitter.form === pendingConfirmForm) {
                pendingConfirmForm.requestSubmit(pendingConfirmSubmitter);
            } else {
                pendingConfirmForm.requestSubmit();
            }
        }, true);

        confirmAccept?.addEventListener('click', () => {
            if (pendingConfirmCallback) {
                const callback = pendingConfirmCallback;
                pendingConfirmCallback = null;
                if (confirmModal && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(confirmModal).hide();
                }
                callback();
                return;
            }

            if (!pendingConfirmForm) return;
            const form = pendingConfirmForm;
            const submitter = pendingConfirmSubmitter;
            pendingConfirmForm = null;
            pendingConfirmSubmitter = null;
            form.dataset.confirmAccepted = '1';
            if (confirmModal && window.bootstrap?.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(confirmModal).hide();
            }
            if (submitter && submitter.form === form) {
                form.requestSubmit(submitter);
            } else {
                form.requestSubmit();
            }
        });

        confirmModal?.addEventListener('hidden.bs.modal', () => {
            pendingConfirmForm = null;
            pendingConfirmSubmitter = null;
            pendingConfirmCallback = null;
            document.body.classList.remove('rm-confirm-open');
            document.querySelectorAll('.offcanvas.show').forEach((drawer) => {
                drawer.style.zIndex = drawer.dataset.previousZIndex || '';
                delete drawer.dataset.previousZIndex;
            });
            if (!document.querySelector('.modal.show')) {
                document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
            }
            if (confirmAccept) {
                confirmAccept.hidden = false;
                confirmAccept.disabled = false;
            }
            if (confirmCancelLabel) confirmCancelLabel.textContent = 'Cancelar';
        });

        confirmModal?.addEventListener('show.bs.modal', () => {
            document.body.classList.add('rm-confirm-open');
            confirmModal.style.zIndex = '5000';
        });

        confirmModal?.addEventListener('shown.bs.modal', () => {
            confirmModal.style.zIndex = '5000';
            document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
                backdrop.style.zIndex = '4990';
            });
        });

        document.addEventListener('click', (event) => {
            const closeTrigger = event.target.closest('[data-nova-modal-close], [data-bs-dismiss="modal"]');
            if (closeTrigger) {
                event.preventDefault();
                event.stopPropagation();
                window.appUi.closeModal(closeTrigger.closest('.modal'));
                return;
            }

            const openTrigger = event.target.closest('[data-nova-modal-open]');
            if (openTrigger && !openTrigger.matches('[data-bs-toggle]')) {
                const target = document.getElementById(openTrigger.getAttribute('data-nova-modal-open'));
                if (target) {
                    event.preventDefault();
                    promoteModalToBody(target);
                    window.appUi.openModal(target);
                }
            }

            const modal = event.target.classList?.contains('modal') ? event.target : null;
            if (modal && modal.dataset.novaSessionModal !== '') window.appUi.closeModal(modal);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('.modal.show:not([data-nova-session-modal])').forEach(window.appUi.closeModal);
        });

        if (document.body.classList.contains('rm-maintenance-active')) {
            document.querySelectorAll('form').forEach((form) => {
                if (form.dataset.maintenanceAllowed === '1') return;
                if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
                form.querySelectorAll('input, select, textarea, button').forEach((control) => {
                    if (control.matches('[data-nova-modal-close], [data-bs-dismiss="modal"]')) return;
                    control.disabled = true;
                    control.title = 'Modulo en mantencion';
                });
            });
            document.querySelectorAll('[data-nova-modal-open]').forEach((button) => {
                button.disabled = true;
                button.title = 'Modulo en mantencion';
            });
        }

        // TIC keeps its existing loader element; behavior is provided by nova-ui.js.
        (function () {
            const loader = document.createElement('div');
            loader.className = 'nova-page-loader';
            loader.id = 'nova-page-loader';
            loader.setAttribute('aria-hidden', 'true');
            document.body.prepend(loader);
            document.addEventListener('submit', function (e) {
                if (e.defaultPrevented) return;
                if (e.target?.matches?.('[data-app-no-loading], [data-no-page-loader]')) return;
                const copy = integrationCopyForForm(e.target, e.submitter || document.activeElement);
                if (copy) {
                    window.appUi.setIntegrationLoading(true, copy);
                    (e.submitter || e.target.querySelector('button[type="submit"], button:not([type])'))?.classList?.add('is-submitting');
                }
            });
            window.addEventListener('pageshow', () => window.appUi.setIntegrationLoading(false));
            window.addEventListener('load', () => window.appUi.setIntegrationLoading(false));
        }());

    </script>
</body>
</html>
