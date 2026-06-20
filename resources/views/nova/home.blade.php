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
                <div class="nova-brand-mark" aria-hidden="true">
                    <i class="bi bi-grid-1x2-fill"></i>
                </div>
                <div class="nova-brand-title">
                    <strong>NOVA</strong>
                    <span>Panel operativo</span>
                </div>
            </div>

            <nav class="nova-session" aria-label="Sesion">
                @include('nova.partials.session-control')
                <span class="nova-user">
                    <i class="bi bi-person-circle"></i>
                    {{ session('nova_user.name') }}
                </span>
                <a class="btn btn-outline-secondary nova-icon-btn" href="{{ route('modules.index') }}" title="Modulos" aria-label="Modulos">
                    <i class="bi bi-sliders"></i>
                </a>
                @if (in_array((string) session('nova_user.role', 'usuario'), config('nova.module_admin_roles', []), true))
                    <a class="btn btn-outline-secondary nova-icon-btn" href="{{ route('administracion.index') }}" title="Administracion" aria-label="Administracion">
                        <i class="bi bi-person-gear"></i>
                    </a>
                @endif
                <form method="POST" action="{{ route('logout') }}" style="display:inline">
                    @csrf
                    <button class="btn btn-outline-secondary nova-icon-btn" type="submit" title="Salir" aria-label="Salir">
                        <i class="bi bi-box-arrow-right"></i>
                    </button>
                </form>
            </nav>
        </header>

        <section class="nova-summary" aria-label="Resumen">
            @php
                $maintenanceProjects = collect($projects)->filter(static fn ($project) => data_get($project, 'maintenance.enabled'));
                $maintenanceCount = $maintenanceProjects->count();
            @endphp
            <div>
                <h1>Modulos de trabajo</h1>
            </div>
            <div class="nova-metrics" aria-label="Indicadores">
                <button class="nova-metric is-warning" type="button" data-nova-modal-open="mantencion-detalle" title="Ver proyectos en mantencion">
                    <i class="bi bi-tools"></i>
                    <div>
                        <strong>{{ $maintenanceCount }}</strong>
                        <span>Mantencion</span>
                    </div>
                </button>
            </div>
        </section>

        <section class="nova-system-head" aria-label="Modulos disponibles">
            <span class="nova-system-icon" aria-hidden="true"><i class="bi bi-grid"></i></span>
            <div>
                <small>Accesos disponibles</small>
                <h2>Disponibles</h2>
                <p>Modulos activos para tu sesion actual.</p>
            </div>
            <span class="nova-system-meter"><strong>{{ count($projects) }}</strong><span>modulos</span></span>
        </section>

        <section class="nova-grid" aria-label="Modulos disponibles">
            @foreach ($projects as $key => $project)
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
                        default => url($key),
                    };
                @endphp
                <article class="nova-module nova-card">
                    <a class="nova-module-link" href="{{ $projectUrl }}" aria-label="Abrir {{ $project['name'] }}"></a>
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
                                            @php($projectStatus = strtolower((string) ($project['status'] ?? '')))
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
                            <button class="btn btn-primary" type="button" data-nova-modal-close>Cerrar</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const openNovaModal = (modal) => {
            if (!modal) return;
            modal.classList.add('show');
            modal.removeAttribute('aria-hidden');
            modal.setAttribute('aria-modal', 'true');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        };

        const closeNovaModal = (modal) => {
            if (!modal) return;
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            modal.removeAttribute('aria-modal');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
        };

        document.querySelectorAll('[data-nova-modal-open]').forEach((trigger) => {
            trigger.addEventListener('click', () => {
                openNovaModal(document.getElementById(trigger.dataset.novaModalOpen));
            });
        });

        document.querySelectorAll('[data-auto-open-modal]').forEach((modal) => {
            openNovaModal(modal);
        });

        document.querySelectorAll('[data-nova-modal-close]').forEach((trigger) => {
            trigger.addEventListener('click', () => {
                closeNovaModal(trigger.closest('.modal'));
            });
        });

        document.querySelectorAll('.modal').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (modal.dataset.novaSessionModal === '') return;
                if (event.target === modal) {
                    closeNovaModal(modal);
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('.modal.show:not([data-nova-session-modal])').forEach((modal) => {
                closeNovaModal(modal);
            });
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
