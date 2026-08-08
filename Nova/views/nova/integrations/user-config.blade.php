<!doctype html>
<html lang="es">
<head>
    @if ($moduleKey === 'emach')
        @php
            $pageTitle = 'EMACH | Configuracion';
            $includeTheme = true;
        @endphp
        <?php include base_path('Emach/views/partials/bootstrap-head.php'); ?>
    @else
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $moduleConfig['title'] }} | Mis integraciones</title>
        @include('nova.partials.favicon')
        @php $novaSidebarPreloadVersion = @filemtime(public_path('assets/nova-sidebar-preload.js')) ?: '1'; @endphp
        <script src="{{ asset('assets/nova-sidebar-preload.js') }}?v={{ $novaSidebarPreloadVersion }}" data-nova-sidebar-key="{{ $moduleKey }}"></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
        <link href="{{ asset('assets/nova-ui.css') }}?v={{ @filemtime(public_path('assets/nova-ui.css')) ?: '1' }}" rel="stylesheet">
    @endif
</head>
<body class="{{ $moduleKey === 'emach' ? 'emach-page' : 'nova-page' }} integration-page integration-page-{{ $moduleConfig['theme'] ?? $moduleKey }}">
@php
    $activeNav = 'configuracion';
    $configuredCount = collect($integrations)->filter(fn ($item) => !empty($item['stored']))->count();
    $statusFor = function (string $type, array $definition, array $state): array {
        $stored = (bool) ($state['stored'] ?? false);
        $hasSecret = (bool) ($state['has_secret'] ?? false);
        $hasExternal = (bool) ($state['has_external_user'] ?? false);
        $needsExternal = !empty($definition['external_required']);

        if (!$stored) {
            return ['class' => 'is-empty', 'label' => 'Sin configurar', 'icon' => 'bi-circle'];
        }
        if (!$hasSecret || ($needsExternal && !$hasExternal)) {
            return ['class' => 'is-warning', 'label' => 'Requiere actualizacion', 'icon' => 'bi-circle-fill'];
        }

        return ['class' => 'is-ready', 'label' => 'Configurada', 'icon' => 'bi-circle-fill'];
    };
    $formatDate = function ($value): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '-';
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->timezone('America/Santiago')->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    };
    $ticSections = [
        'dashboard' => 'Reportes',
        'webhook' => 'Reporte manual',
        'horas-extra' => 'Horas extra',
        'historico' => 'Historico',
        'usuarios' => 'Usuarios',
        'configuracion' => 'Configuracion',
        'estadisticas' => 'Estadisticas',
        'actividad' => 'Actividad',
    ];
    $ticSectionIcons = [
        'dashboard' => config('navigation-icons.reportes'),
        'webhook' => config('navigation-icons.reporte_manual'),
        'horas-extra' => config('navigation-icons.horas_extra'),
        'historico' => config('navigation-icons.historico'),
        'usuarios' => config('navigation-icons.usuarios'),
        'configuracion' => config('navigation-icons.configuracion'),
        'estadisticas' => config('navigation-icons.estadisticas'),
        'actividad' => config('navigation-icons.actividad'),
    ];
    $ticSectionPermissions = [
        'dashboard' => 'mensajes_acceso', 'webhook' => 'simulador', 'horas-extra' => 'horas_extra',
        'historico' => 'historico', 'usuarios' => 'usuarios', 'configuracion' => 'configuracion',
        'estadisticas' => 'estadisticas', 'actividad' => 'actividad',
    ];
    $hasIntegrationPermission = fn (string $permission): bool => !empty($integrationPermissions['all']) || !empty($integrationPermissions[$permission]);
    $ticSections = array_filter($ticSections, fn ($label, $key) => $hasIntegrationPermission($ticSectionPermissions[$key] ?? ''), ARRAY_FILTER_USE_BOTH);
@endphp
@if ($moduleKey === 'emach')
    <?php include base_path('Emach/views/partials/navbar.php'); ?>
@else
<div class="rm-shell">
    <nav class="navbar navbar-expand-lg navbar-dark rm-navbar integration-navbar">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="{{ $homeUrl }}">
                <span class="rm-brand-mark"><i class="bi {{ $moduleConfig['icon'] }}"></i></span>
                <span>{{ $moduleConfig['title'] }}</span>
            </a>
            @if ($moduleKey === 'redmine_tic')
                <button class="nova-sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#novaSidebar" aria-controls="novaSidebar" aria-label="Abrir menu lateral">
                    <i class="bi bi-list"></i>
                </button>
            @endif
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#integrationTopbar" aria-controls="integrationTopbar" aria-expanded="false" aria-label="Alternar navegacion">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="integrationTopbar">
                <div class="rm-top-actions integration-top-actions mt-3 mt-lg-0">
                    @include('nova.partials.session-control')
                    <span class="nova-navbar-user"><i class="bi bi-person-circle"></i> @include('nova.partials.current-user-name')</span>
                    <a class="btn btn-outline-light" href="{{ $homeUrl }}"><i class="bi bi-arrow-left"></i>Modulo</a>
                    <a class="btn btn-outline-light" href="{{ route('home') }}"><i class="bi bi-house-door"></i>NOVA</a>
                    <form method="POST" action="{{ route('logout') }}" style="display:inline">
                        @csrf
                        <button class="btn btn-outline-light" type="submit"><i class="bi bi-box-arrow-right"></i>Salir</button>
                    </form>
                </div>
            </div>
        </div>
    </nav>
    @if ($moduleKey === 'redmine_tic')
        <div class="nova-layout">
            <aside class="nova-sidebar offcanvas-lg offcanvas-start" id="novaSidebar" tabindex="-1" aria-labelledby="novaSidebarLabel">
                <div class="offcanvas-header d-lg-none border-bottom py-3">
                    <strong class="offcanvas-title fw-bold" id="novaSidebarLabel">{{ $moduleConfig['title'] }}</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                </div>
                <nav class="nova-sidebar-body" aria-label="Secciones Redmine TIC">
                    @foreach ($ticSections as $key => $label)
                        <a class="nova-sidebar-link" href="{{ route('redmine.native.section', $key) }}">
                            <i class="bi {{ $ticSectionIcons[$key] ?? 'bi-window' }} nova-sidebar-icon"></i>
                            <span>{{ $label }}</span>
                        </a>
                    @endforeach
                    <a class="nova-sidebar-link active" href="{{ route('integrations.redmine_tic') }}" aria-current="page">
                        <i class="bi {{ config('navigation-icons.cuentas_conectadas') }} nova-sidebar-icon"></i>
                        <span>Mis integraciones</span>
                    </a>
                </nav>
                @include('nova.partials.sidebar-compact-control', ['sidebarId' => 'novaSidebar'])
            </aside>
    @endif
@endif

    <main class="{{ $moduleKey === 'emach' ? 'nova-content' : ($moduleKey === 'redmine_tic' ? 'nova-content rm-main' : 'rm-layout') }}">
        <div class="{{ $moduleKey === 'emach' ? 'container-fluid py-4' : '' }}">
        <section class="card card-hero sb-page-hero {{ $moduleKey === 'emach' ? 'emach-hero' : 'rm-hero' }} integration-hero nova-system-hero mb-3">
            <div class="card-body d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div class="d-flex align-items-center gap-3">
                    <span class="{{ $moduleKey === 'emach' ? 'emach-hero-icon' : 'rm-hero-icon' }}"><i class="bi bi-person-lock"></i></span>
                    <div>
                        <h1 class="rm-page-title text-white">{{ $moduleKey === 'emach' ? 'Configuracion EMACH' : 'Mis integraciones' }}</h1>
                        <p class="mb-0 text-white-50 fw-semibold">{{ $moduleConfig['subtitle'] }}</p>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="{{ $moduleKey === 'emach' ? 'emach-status-pill' : 'rm-hero-retention' }}"><i class="bi bi-shield-check"></i> Solo tu usuario</span>
                    <span class="{{ $moduleKey === 'emach' ? 'emach-status-pill' : 'rm-hero-retention' }}"><i class="bi bi-key"></i> {{ $configuredCount }} activa(s)</span>
                </div>
            </div>
        </section>

        @if (session('integration_status'))
            <div data-nova-flash="success" data-nova-flash-message="{{ session('integration_status') }}" hidden></div>
        @endif
        @if (session('integration_error'))
            <div data-nova-flash="warning" data-nova-flash-message="{{ session('integration_error') }}" hidden></div>
        @endif

        <section class="integration-grid integration-grid--{{ count($integrationDefinitions) }} mb-3">
            @foreach ($integrationDefinitions as $type => $definition)
                @php
                    $state = $integrations[$type] ?? ['stored' => false, 'has_secret' => false, 'has_external_user' => false, 'masked_external_user' => '', 'updated_at' => ''];
                    $stored = (bool) ($state['stored'] ?? false);
                    $maskedExternal = (string) ($state['masked_external_user'] ?? '');
                    $updatedAt = $formatDate($state['updated_at'] ?? '');
                    $status = $statusFor($type, $definition, $state);
                    $hasExternalField = $definition['external_label'] !== '';
                    $isPlainValue = !empty($definition['is_plain_value']);
                    $valueLabel = $isPlainValue ? 'Dato' : 'Secreto';
                    $drawerId = 'integration-drawer-' . $type;
                    $formId = 'integration-form-' . $type;
                    $secretInputId = 'secret-drawer-' . $type;
                @endphp
                <article class="integration-card nova-card integration-card-summary" id="integration-{{ $type }}" role="button" tabindex="0"
                         data-integration-card data-drawer-target="{{ $drawerId }}"
                         aria-controls="{{ $drawerId }}">
                    <div class="integration-card-head">
                        <div class="integration-title">
                            <span class="integration-icon"><i class="bi {{ $definition['icon'] }}"></i></span>
                            <h2>{{ $definition['label'] }}</h2>
                        </div>
                        <span class="integration-card-open" aria-hidden="true"><i class="bi bi-sliders"></i></span>
                    </div>

                    <p class="text-muted fw-semibold mb-3">{{ $definition['description'] }}</p>

                    <div class="integration-card-status">
                        <span class="integration-status {{ $status['class'] }}">
                            <i class="bi {{ $status['icon'] }}"></i>{{ $status['label'] }}
                        </span>
                        @if ($moduleKey === 'nova' && $type === 'nextcloud')
                            @php $officeOnline = ($onlyOfficeHealth['status'] ?? '') === 'online'; @endphp
                            <span class="integration-status {{ $officeOnline ? 'is-ready' : 'is-warning' }}" title="{{ $onlyOfficeHealth['detail'] ?? '' }}">
                                <i class="bi {{ $officeOnline ? 'bi-file-earmark-check' : 'bi-file-earmark-x' }}"></i>OnlyOffice: {{ $onlyOfficeHealth['label'] ?? 'Sin verificar' }}
                            </span>
                        @endif
                    </div>

                    <dl class="integration-meta">
                        <div>
                            <dt>Usuario</dt>
                            <dd>{{ $hasExternalField ? ($maskedExternal !== '' ? $maskedExternal : '********') : '********' }}</dd>
                        </div>
                        <div>
                            <dt>{{ $valueLabel }}</dt>
                            <dd>{{ !empty($state['has_secret']) ? 'Guardado' : 'Pendiente' }}</dd>
                        </div>
                        <div>
                            <dt>Actualizacion</dt>
                            <dd>{{ $updatedAt }}</dd>
                        </div>
                    </dl>

                    <div class="integration-card-actions">
                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#{{ $drawerId }}" aria-controls="{{ $drawerId }}">
                            <i class="bi bi-pencil-square"></i>
                            <span>Editar</span>
                        </button>
                    </div>
                </article>

                <div class="offcanvas offcanvas-end integration-drawer" tabindex="-1" id="{{ $drawerId }}" aria-labelledby="{{ $drawerId }}-title">
                    <div class="offcanvas-header">
                        <div class="integration-drawer-title">
                            <span class="integration-icon"><i class="bi {{ $definition['icon'] }}"></i></span>
                            <div>
                                <h2 class="offcanvas-title" id="{{ $drawerId }}-title">{{ $definition['label'] }}</h2>
                                <span class="integration-status {{ $status['class'] }}">
                                    <i class="bi {{ $status['icon'] }}"></i>{{ $status['label'] }}
                                </span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                    </div>
                    <div class="offcanvas-body">
                        <dl class="integration-meta integration-drawer-meta">
                            <div>
                                <dt>Usuario</dt>
                                <dd>{{ $hasExternalField ? ($maskedExternal !== '' ? $maskedExternal : '********') : '********' }}</dd>
                            </div>
                            <div>
                                <dt>Ultima actualizacion</dt>
                                <dd>{{ $updatedAt }}</dd>
                            </div>
                        </dl>

                        <form id="{{ $formId }}" method="post" action="{{ $postUrl }}" class="integration-form js-integration-form" data-integration-type="{{ $type }}">
                            @csrf
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="type" value="{{ $type }}">

                            @if ($hasExternalField)
                                <div>
                                    <label class="form-label" for="external-{{ $type }}">{{ $definition['external_label'] }}</label>
                                    <input id="external-{{ $type }}" class="form-control" name="external_user" autocomplete="username"
                                           value="{{ old('type') === $type ? old('external_user') : '' }}"
                                           placeholder="{{ $maskedExternal !== '' ? $maskedExternal : $definition['external_label'] }}">
                                    @if ($stored)
                                        <div class="form-text">Dejar vacio para conservar el usuario actual.</div>
                                    @endif
                                </div>
                            @else
                                <input type="hidden" name="external_user" value="">
                            @endif

                            <div>
                                <label class="form-label" for="{{ $secretInputId }}">{{ $definition['secret_label'] }}</label>
                                <div class="input-group integration-secret-group" data-secret-wrapper>
                                    <input id="{{ $secretInputId }}" class="form-control" name="secret" type="{{ $isPlainValue ? 'text' : 'password' }}"
                                           autocomplete="{{ $isPlainValue ? 'off' : 'new-password' }}" placeholder="{{ $stored ? '**************' : $definition['secret_label'] }}"
                                           data-secret-input data-plain-value="{{ $isPlainValue ? '1' : '0' }}">
                                    @unless ($isPlainValue)
                                        <button class="btn btn-outline-secondary" type="button" data-toggle-secret data-target="{{ $secretInputId }}" aria-controls="{{ $secretInputId }}">
                                            <i class="bi bi-eye"></i><span>Mostrar</span>
                                        </button>
                                    @endunless
                                </div>
                                <div class="form-text">{{ $stored ? 'Dejar vacio para conservar el valor actual.' : ($isPlainValue ? 'Ingresa el identificador asociado a tu cuenta.' : 'No se muestra ni se precarga ningun secreto guardado.') }}</div>
                            </div>
                        </form>
                    </div>
                    <div class="offcanvas-footer nova-drawer-actions">
                        <form method="post" action="{{ $postUrl }}" class="integration-delete-form js-integration-delete"
                              data-integration-label="{{ $definition['label'] }}">
                            @csrf
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="type" value="{{ $type }}">
                            <button class="btn-nova btn-nova-danger" type="submit" @disabled(!$stored)>
                                <span class="btn-nova-icon"><i class="bi bi-trash"></i></span>
                                <span>Eliminar</span>
                            </button>
                        </form>
                        <button class="btn-nova btn-nova-primary js-submit-button" type="submit" form="{{ $formId }}">
                            <span class="nova-spinner" aria-hidden="true"></span>
                            <span class="btn-nova-icon"><i class="bi bi-check2"></i></span>
                            <span>Guardar</span>
                        </button>
                    </div>
                </div>
            @endforeach
        </section>

        <section class="integration-summary nova-card">
            <div class="rm-section-head">
                <div>
                    <h2><i class="bi bi-list-check"></i> Resumen</h2>
                    <p>Estado visible sin exponer claves ni contrasenas.</p>
                </div>
                <span class="badge text-bg-primary rounded-pill">{{ $configuredCount }} activa(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 modules-table">
                    <thead>
                        <tr>
                            <th>Integracion</th>
                            <th>Usuario</th>
                            <th>Secreto</th>
                            <th>Estado</th>
                            <th>Ultima actualizacion</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($integrationDefinitions as $type => $definition)
                            @php
                                $state = $integrations[$type] ?? [];
                                $status = $statusFor($type, $definition, $state);
                            @endphp
                            <tr>
                                <td><strong>{{ $definition['label'] }}</strong></td>
                                <td>{{ $definition['external_label'] === '' ? '********' : (($state['masked_external_user'] ?? '') ?: '********') }}</td>
                                <td>{{ !empty($state['has_secret']) ? 'Guardado' : 'Pendiente' }}</td>
                                <td>
                                    <span class="integration-status {{ $status['class'] }}">
                                        <i class="bi {{ $status['icon'] }}"></i>{{ $status['label'] }}
                                    </span>
                                </td>
                                <td>{{ $formatDate($state['updated_at'] ?? '') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        </div>
    </main>
@if ($moduleKey === 'emach')
</div>
@else
@if ($moduleKey === 'redmine_tic')
</div>
@endif
</div>
@endif

<div class="nova-integration-overlay" id="nova-nextcloud-account-loading" role="status" aria-live="polite" aria-hidden="true">
    <div class="nova-integration-card">
        <div class="nova-integration-nextcloud">
            <?php include base_path('resources/views/partials/nextcloud-loader.php'); ?>
        </div>
        <strong>Guardando cuenta Nextcloud</strong>
        <span>Validando y sincronizando tus credenciales.</span>
        <div class="nova-integration-bar" aria-hidden="true"><i></i></div>
    </div>
</div>

<div class="modal fade integration-confirm-modal" id="integrationDeleteConfirm" tabindex="-1"
     aria-labelledby="integrationDeleteConfirmTitle" aria-describedby="integrationDeleteConfirmDescription" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="integration-confirm-title">
                    <span class="integration-confirm-icon"><i class="bi bi-trash3"></i></span>
                    <div>
                        <small>Confirmar eliminación</small>
                        <h2 class="modal-title" id="integrationDeleteConfirmTitle">¿Eliminar esta integración?</h2>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p id="integrationDeleteConfirmDescription">
                    Se eliminarán las credenciales guardadas de
                    <strong data-integration-confirm-name>esta integración</strong>.
                </p>
                <div class="integration-confirm-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span>Para volver a usarla tendrás que ingresar nuevamente tus datos de acceso.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn-nova btn-nova-danger" type="button" data-integration-confirm-delete>
                    <span class="btn-nova-icon"><i class="bi bi-trash3"></i></span>
                    <span>Eliminar integración</span>
                </button>
            </div>
        </div>
    </div>
</div>

@if ($moduleKey !== 'emach')
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('assets/nova-ui.js') }}?v={{ @filemtime(public_path('assets/nova-ui.js')) ?: '1' }}"></script>
@endif
<script>
(function () {
    document.addEventListener('click', function (event) {
        const card = event.target.closest('[data-integration-card]');
        if (card && !event.target.closest('button, a, input, select, textarea, label')) {
            const drawer = document.getElementById(card.getAttribute('data-drawer-target'));
            if (drawer && window.bootstrap && window.bootstrap.Offcanvas) {
                window.bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
            }
        }

        const toggleBtn = event.target.closest('[data-toggle-secret]');
        if (!toggleBtn) return;

        event.preventDefault();
        event.stopPropagation();

        const targetId = toggleBtn.getAttribute('data-target') || toggleBtn.getAttribute('aria-controls') || '';
        const wrapper = toggleBtn.closest('[data-secret-wrapper]');
        const input = targetId !== ''
            ? document.getElementById(targetId)
            : wrapper?.querySelector('[data-secret-input]');
        if (!input) return;

        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');

        const label = toggleBtn.querySelector('span');
        if (label) label.textContent = isHidden ? 'Ocultar' : 'Mostrar';

        const icon = toggleBtn.querySelector('i');
        if (icon) icon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
        input.focus();
    });

    document.addEventListener('keydown', function (event) {
        const card = event.target.closest('[data-integration-card]');
        if (!card || (event.key !== 'Enter' && event.key !== ' ')) return;

        event.preventDefault();
        const drawer = document.getElementById(card.getAttribute('data-drawer-target'));
        if (drawer && window.bootstrap && window.bootstrap.Offcanvas) {
            window.bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
        }
    });

    const deleteModalElement = document.getElementById('integrationDeleteConfirm');
    const nextcloudAccountLoading = document.getElementById('nova-nextcloud-account-loading');
    const deleteIntegrationName = deleteModalElement?.querySelector('[data-integration-confirm-name]');
    const deleteConfirmButton = deleteModalElement?.querySelector('[data-integration-confirm-delete]');
    let pendingDeleteForm = null;
    let pendingDeleteTrigger = null;
    let deleteConfirmed = false;
    const getDeleteModal = function () {
        if (!deleteModalElement || !window.bootstrap || !window.bootstrap.Modal) return null;
        return window.bootstrap.Modal.getOrCreateInstance(deleteModalElement);
    };

    document.addEventListener('submit', function (event) {
        const form = event.target;

        if (form.matches('.js-integration-delete') && !deleteConfirmed) {
            event.preventDefault();
            const deleteModal = getDeleteModal();
            if (!deleteModal) {
                window.NovaToast?.error('No se pudo abrir la confirmación de eliminación.');
                return;
            }

            pendingDeleteForm = form;
            pendingDeleteTrigger = event.submitter || form.querySelector('button[type="submit"]');
            if (deleteIntegrationName) {
                deleteIntegrationName.textContent = form.dataset.integrationLabel || 'esta integración';
            }
            deleteModal?.show();
            return;
        }

        deleteConfirmed = false;
        if (event.defaultPrevented) {
            return;
        }

        if (form.matches('.js-integration-form[data-integration-type="nextcloud"]') && nextcloudAccountLoading) {
            nextcloudAccountLoading.classList.add('is-active', 'is-nextcloud');
            nextcloudAccountLoading.setAttribute('aria-hidden', 'false');
            document.body.classList.add('nova-integration-loading');
        }

        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
            button.disabled = true;
            button.classList.add('is-submitting');
        });
        if (event.submitter) {
            event.submitter.disabled = true;
            event.submitter.classList.add('is-submitting');
        }
    });

    window.addEventListener('pageshow', function () {
        nextcloudAccountLoading?.classList.remove('is-active');
        nextcloudAccountLoading?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('nova-integration-loading');
    });

    deleteConfirmButton?.addEventListener('click', function () {
        const form = pendingDeleteForm;
        const deleteModal = getDeleteModal();
        if (!form || !deleteModalElement || !deleteModal) return;

        deleteConfirmButton.disabled = true;
        deleteConfirmButton.classList.add('is-submitting');
        deleteConfirmed = true;
        deleteModalElement.addEventListener('hidden.bs.modal', function () {
            form.requestSubmit();
        }, { once: true });
        deleteModal.hide();
    });

    deleteModalElement?.addEventListener('hidden.bs.modal', function () {
        deleteConfirmButton?.classList.remove('is-submitting');
        if (deleteConfirmButton) deleteConfirmButton.disabled = false;
        if (!deleteConfirmed) pendingDeleteTrigger?.focus();
        pendingDeleteForm = null;
        pendingDeleteTrigger = null;
    });

    document.querySelectorAll('.integration-drawer').forEach(function (drawer) {
        const saveForm = drawer.querySelector('.js-integration-form');
        if (!saveForm) return;

        drawer.addEventListener('show.bs.offcanvas', function () {
            saveForm.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach(function (field) {
                field.dataset.originalValue = field.value;
            });
        });

        drawer.addEventListener('hide.bs.offcanvas', function () {
            saveForm.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach(function (field) {
                field.value = Object.prototype.hasOwnProperty.call(field.dataset, 'originalValue')
                    ? field.dataset.originalValue
                    : '';
            });

            saveForm.querySelectorAll('[data-secret-input]').forEach(function (secretInput) {
                secretInput.type = secretInput.dataset.plainValue === '1' ? 'text' : 'password';
                const wrapper = secretInput.closest('[data-secret-wrapper]');
                if (!wrapper) return;
                const toggleBtn = wrapper.querySelector('[data-toggle-secret]');
                if (!toggleBtn) return;
                toggleBtn.setAttribute('aria-pressed', 'false');
                const label = toggleBtn.querySelector('span');
                if (label) label.textContent = 'Mostrar';
                const icon = toggleBtn.querySelector('i');
                if (icon) icon.className = 'bi bi-eye';
            });
        });
    });
}());
</script>
</body>
</html>
