<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
</head>
<body class="nova-page">
    <main class="nova-home nova-shell">
        <header class="nova-topbar">
            <div class="nova-brand">
                <div class="nova-brand-mark" title="NOVA" aria-label="NOVA">
                    <i class="bi bi-grid-1x2-fill"></i>
                </div>
                <div class="nova-brand-title">
                    <strong>NOVA</strong>
                </div>
            </div>

            <nav class="nova-session" aria-label="Sesión de {{ session('nova_user.name') }}">
                @include('nova.partials.session-control')
                <span class="nova-navbar-user"><i class="bi bi-person-circle"></i> {{ session('nova_user.name') }}</span>
                @if (isset($projects['integraciones']))
                    <a class="btn btn-outline-light" href="{{ route('integrations.nova') }}" title="Mis integraciones">
                        <i class="bi bi-person-lock"></i><span class="nova-navbar-label">Integraciones</span>
                    </a>
                @endif
                <a class="btn btn-outline-light" href="{{ route('modules.index') }}" title="Módulos">
                    <i class="bi bi-sliders"></i><span class="nova-navbar-label">Módulos</span>
                </a>
                @if (in_array((string) session('nova_user.role', 'usuario'), config('nova.module_admin_roles', []), true))
                    <a class="btn btn-outline-light" href="{{ route('administracion.index') }}" title="Administración">
                        <i class="bi bi-person-gear"></i><span class="nova-navbar-label">Administración</span>
                    </a>
                @endif
                <form method="POST" action="{{ route('logout') }}" class="nova-inline-form">
                    @csrf
                    <button class="btn btn-outline-light" type="submit" title="Salir">
                        <i class="bi bi-box-arrow-right"></i><span class="nova-navbar-label">Salir</span>
                    </button>
                </form>
            </nav>
        </header>

        @php
            $homeProjects = collect($projects)->filter(static fn ($project) => ($project['show_on_home'] ?? true) !== false);
            $maintenanceProjects = $homeProjects->filter(static fn ($project) => data_get($project, 'maintenance.enabled'));
            $maintenanceCount = $maintenanceProjects->count();
            $availableModules = $homeProjects->count();
        @endphp

        <section class="nova-home-section-head" aria-label="Módulos disponibles">
            <div>
                <span class="nova-home-eyebrow">Tus herramientas</span>
                <h2>Módulos disponibles</h2>
                <p>Accede directamente a cada área de trabajo.</p>
            </div>
            <div class="nova-home-section-actions" aria-label="Resumen de módulos">
                <span class="nova-home-status-pill"><i class="bi bi-grid"></i><strong>{{ $availableModules }}</strong> accesos</span>
                <button class="nova-home-status-pill is-warning" type="button" data-nova-modal-open="mantencion-detalle" title="Ver proyectos en mantención">
                    <i class="bi bi-tools"></i><strong>{{ $maintenanceCount }}</strong> en mantención
                </button>
            </div>
        </section>

        <section class="nova-grid" aria-label="Modulos disponibles">
            @foreach ($homeProjects as $key => $project)
                @php
                    $moduleIcons = [
                        'redmine_tic' => 'bi-kanban',
                        'redmine-mantencion' => 'bi-tools',
                        'core' => 'bi-diagram-3',
                        'archivo' => 'bi-archive',
                        'servicios' => 'bi-hdd-network',
                        'reportes' => 'bi-clipboard-data',
                        'usuarios' => 'bi-people',
                        'telegram' => 'bi-telegram',
                        'administracion' => 'bi-person-gear',
                        'procedimientos' => 'bi-journal-richtext',
                        'monitoreo-servidores' => 'bi-hdd-network',
                    ];
                    $projectType = $project['type'] ?? 'legacy';
                    $projectIcon = $project['icon'] ?? ($moduleIcons[$key] ?? ($projectType === 'native' ? 'bi-window-stack' : 'bi-window-sidebar'));
                    $isMaintenance = (bool) data_get($project, 'maintenance.enabled');
                    $hasEmachCredentials = $key === 'emach' && (bool) session('nova_user.has_emach_credentials', false);
                    $hasTelegramSettings = $key === 'telegram' && (bool) session('nova_user.has_telegram_settings', false);
                    $projectUrl = match ($key) {
                        'redmine_tic' => route('redmine.native.dashboard'),
                        'redmine-mantencion' => route('redmine.mantencion.dashboard'),
                        'telegram' => route('telegram.index'),
                        'administracion' => route('administracion.index'),
                        'procedimientos' => route('procedimientos.index'),
                        'horas-extra' => route('horas-extra.index'),
                        'monitoreo-servidores' => route('monitor.dashboard'),
                        default => url($key),
                    };
                @endphp
                <article class="nova-module nova-card {{ $isMaintenance ? 'is-maintenance' : '' }}">
                    <a class="nova-module-link" href="{{ $projectUrl }}" aria-label="Abrir {{ $project['name'] }}"></a>
                    @if ($isMaintenance)
                        <span class="nova-module-maintenance" title="Módulo en mantención" aria-label="Módulo en mantención"><i class="bi bi-tools"></i></span>
                    @endif
                    @if ($hasEmachCredentials || $hasTelegramSettings)
                        <span class="nova-module-status-icon" title="{{ $hasEmachCredentials ? 'Credenciales EMACH guardadas' : 'Telegram personal configurado' }}" aria-label="{{ $hasEmachCredentials ? 'Credenciales EMACH guardadas' : 'Telegram personal configurado' }}"><i class="bi {{ $hasEmachCredentials ? 'bi-key-fill' : 'bi-check-circle-fill' }}"></i></span>
                    @endif
                    <div class="nova-module-head">
                        <div class="nova-module-title">
                            <div class="nova-module-title-row">
                                <div class="nova-module-icon {{ $isMaintenance ? 'is-maintenance' : ($projectType === 'legacy' ? 'is-legacy' : '') }}" aria-hidden="true">
                                    <i class="bi {{ $projectIcon }}"></i>
                                </div>
                                <div>
                                    <h3>{{ $project['name'] }}</h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            @endforeach

        </section>

        <div class="modal fade" id="usuarios-roles" tabindex="-1" aria-labelledby="usuarios-roles-title" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-5" id="usuarios-roles-title">Usuarios, roles y proyectos</h2>
                            <p class="mb-0 nova-muted">{{ count($users ?? []) }} usuarios encontrados en los modulos registrados.</p>
                        </div>
                        <button type="button" class="btn-close" data-nova-modal-close aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div class="p-3 border-bottom bg-light">
                            <input class="form-control" type="search" id="nova-user-search" placeholder="Buscar por nombre, RUT, rol o proyecto">
                        </div>
                        <div class="table-responsive">
                            <table class="table nova-users-table" id="nova-users-table">
                                <thead>
                                    <tr>
                                        <th>Usuario</th>
                                        <th>RUT / ID</th>
                                        <th>Proyectos y roles</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @forelse (($users ?? []) as $user)
                                <tr>
                                    <td>
                                        <strong>{{ $user['name'] }}</strong>
                                    </td>
                                    <td>
                                        {{ $user['rut'] !== '' ? $user['rut'] : $user['identity'] }}
                                    </td>
                                    <td>
                                        @foreach ($user['projects'] as $projectKey => $project)
                                            @php
                                                $projectStatus = strtolower((string) ($project['status'] ?? ''));
                                            @endphp
                                            <span class="nova-project-role {{ in_array($projectStatus, ['activo', 'active'], true) ? 'is-active' : (in_array($projectStatus, ['baneado', 'banneado', 'banned', 'inactivo'], true) ? 'is-banned' : '') }}">
                                                <i class="bi bi-folder2-open"></i>
                                                {{ $project['name'] }}:
                                                {{ $project['role'] }}
                                                <i class="bi {{ in_array($projectStatus, ['activo', 'active'], true) ? 'bi-check-circle' : (in_array($projectStatus, ['baneado', 'banneado', 'banned', 'inactivo'], true) ? 'bi-x-circle' : 'bi-dash-circle') }}"></i>
                                                {{ $project['status'] !== '' ? $project['status'] : 'sin estado' }}
                                            </span>
                                        @endforeach
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="4">No se encontraron usuarios en los modulos registrados.</td>
                                </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="mantencion-detalle" tabindex="-1" aria-labelledby="mantencion-detalle-title" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-5" id="mantencion-detalle-title">Proyectos en mantencion</h2>
                            <p class="mb-0 nova-muted">{{ $maintenanceCount }} proyecto(s)</p>
                        </div>
                        <button type="button" class="btn-close" data-nova-modal-close aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="nova-maintenance-list">
                            @forelse ($maintenanceProjects as $key => $project)
                                <div class="nova-maintenance-item">
                                    <i class="bi bi-tools" aria-hidden="true"></i>
                                    <div>
                                        <strong>{{ $project['name'] }}</strong>
                                        <span>{{ data_get($project, 'maintenance.until_text') ? 'Hasta ' . data_get($project, 'maintenance.until_text') : 'Mantencion activa' }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="nova-empty">No hay proyectos en mantencion.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if (session('access_error'))
            <div class="modal fade" id="access-denied-modal" tabindex="-1" aria-labelledby="access-denied-title" aria-hidden="true" data-auto-open-modal>
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h2 class="modal-title fs-5" id="access-denied-title">Acceso no autorizado</h2>
                                <p class="mb-0 nova-muted">Permisos del proyecto</p>
                            </div>
                            <button type="button" class="btn-close" data-nova-modal-close aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="nova-card nova-card-pad nova-alert-danger mb-0">
                                {{ session('access_error') }}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" type="button" data-nova-modal-close>Cerrar</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('assets/nova-ui.js') }}"></script>
    <script>
        // Delegated open/close wiring for this page's 3 modals, mirroring the
        // Keep the session dialog behavior aligned with the shared NOVA UI.
        // window.appUi.openModal/closeModal (nova-ui.js) delegate to the real
        // bootstrap.Modal component for elements with class="modal" (all 3
        // here), so this page now gets a backdrop/focus-trap/native Escape
        // handling instead of the previous hand-rolled toggle.
        document.addEventListener('click', (event) => {
            const closeTrigger = event.target.closest('[data-nova-modal-close], [data-bs-dismiss="modal"]');
            if (closeTrigger) {
                event.preventDefault();
                window.appUi.closeModal(closeTrigger.closest('.modal'));
                return;
            }
            const openTrigger = event.target.closest('[data-nova-modal-open]');
            if (openTrigger && !openTrigger.matches('[data-bs-toggle]')) {
                const target = document.getElementById(openTrigger.getAttribute('data-nova-modal-open'));
                if (target) {
                    event.preventDefault();
                    window.appUi.openModal(target);
                }
            }
        });

        document.querySelectorAll('[data-auto-open-modal]').forEach((modal) => {
            window.appUi.openModal(modal);
        });

        const novaUserSearch = document.getElementById('nova-user-search');
        const novaUsersTable = document.getElementById('nova-users-table');
        if (novaUserSearch && novaUsersTable) {
            novaUserSearch.addEventListener('input', () => {
                const term = novaUserSearch.value.trim().toLowerCase();
                novaUsersTable.querySelectorAll('tbody tr').forEach((row) => {
                    row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
                });
            });
        }
    </script>
</body>
</html>
