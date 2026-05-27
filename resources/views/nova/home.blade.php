<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>NOVA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #eef3fb;
        }

        .nova-home {
            width: 100%;
            margin: 0 auto;
            padding: 0 24px 44px;
        }

        .nova-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            min-height: 68px;
            margin: 0 -24px 24px;
            padding: 12px 24px;
            background: linear-gradient(115deg, #1f2f56 0%, #314ed8 62%, #4966ff 100%);
            box-shadow: 0 16px 36px rgba(31, 47, 86, .22);
        }

        .nova-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .nova-brand-mark {
            display: grid;
            width: 48px;
            height: 48px;
            place-items: center;
            flex: 0 0 auto;
            border-radius: 12px;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.24);
            color: #fff;
        }

        .nova-brand-title {
            display: grid;
            gap: 2px;
            min-width: 0;
        }

        .nova-brand-title strong {
            color: #fff;
            font-size: 1.55rem;
            line-height: 1;
            font-weight: 800;
        }

        .nova-brand-title span {
            color: rgba(255,255,255,.62);
            font-size: 0.86rem;
        }

        .nova-session {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .nova-user {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 38px;
            max-width: 260px;
            padding: 0 12px;
            border: 1px solid var(--nova-line);
            border-radius: var(--nova-radius);
            background: rgba(255,255,255,.12);
            color: #fff;
            font-size: 0.9rem;
            font-weight: 700;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .nova-topbar .nova-badge,
        .nova-topbar .btn-outline-secondary {
            border-color: rgba(255,255,255,.35);
            background: rgba(255,255,255,.12);
            color: #fff;
        }

        .nova-topbar .btn-outline-secondary:hover {
            border-color: rgba(255,255,255,.62);
            background: rgba(255,255,255,.2);
            color: #fff;
        }

        .nova-icon-btn {
            width: 42px;
            min-width: 42px;
            padding-inline: 0;
        }

        .nova-summary {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 18px;
            margin-bottom: 20px;
            padding: 18px 20px;
            border: 0;
            border-radius: 12px;
            color: #fff;
            background: linear-gradient(130deg, #4f86f7 0%, #2f9ed9 48%, #31c5ae 100%);
            box-shadow: 0 18px 34px rgba(49, 91, 170, .14);
        }

        .nova-summary h1 {
            margin: 0;
            color: #fff;
            font-size: clamp(1.35rem, 3vw, 1.85rem);
            line-height: 1.08;
            font-weight: 800;
        }

        .nova-metrics {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .nova-metric {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-width: 94px;
            padding: 10px 12px;
            border: 1px solid var(--nova-line);
            border-radius: var(--nova-radius-lg);
            background: rgba(255,255,255,.14);
            color: #fff;
        }

        button.nova-metric {
            cursor: pointer;
            text-align: left;
            transition: transform .16s ease, border-color .16s ease, box-shadow .16s ease;
        }

        button.nova-metric:hover {
            transform: translateY(-1px);
            border-color: rgba(245, 158, 11, .38);
            box-shadow: 0 12px 26px rgba(15, 23, 42, .08);
        }

        .nova-metric i {
            color: #fff;
            font-size: 1.05rem;
        }

        .nova-metric strong {
            display: inline;
            color: #fff;
            font-size: 1.25rem;
            line-height: 1;
        }

        .nova-metric span {
            display: block;
            margin-top: 1px;
            color: rgba(255,255,255,.76);
            font-size: 0.72rem;
            font-weight: 700;
        }

        .nova-metric.is-warning strong {
            color: var(--nova-warning);
        }

        .nova-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin: 0 0 12px;
        }

        .nova-section-header h2 {
            margin: 0;
            color: #0f172a;
            font-size: 1rem;
            font-weight: 800;
        }

        .nova-section-header span {
            color: var(--nova-muted);
            font-size: 0.88rem;
        }

        .nova-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .nova-module {
            position: relative;
            display: grid;
            align-content: center;
            gap: 14px;
            min-height: 210px;
            padding: 24px 22px;
            overflow: hidden;
            border: 1px solid rgba(216, 227, 244, .95);
            background: #fff;
            transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
        }

        .nova-module:hover {
            transform: translateY(-2px);
            border-color: rgba(37, 99, 235, .32);
            box-shadow: 0 20px 42px rgba(15, 23, 42, .1);
        }

        .nova-module-link {
            position: absolute;
            inset: 0;
            z-index: 4;
            border-radius: inherit;
        }

        .nova-module-link:focus-visible {
            outline: 3px solid rgba(37, 99, 235, .28);
            outline-offset: -4px;
        }

        .nova-module::before {
            content: '';
            position: absolute;
            inset: 0 0 auto;
            height: 4px;
            background: linear-gradient(90deg, var(--nova-primary), #14b8a6);
        }

        .nova-module-head {
            display: grid;
            justify-items: center;
            gap: 12px;
        }

        .nova-module-title {
            display: grid;
            justify-items: center;
            gap: 8px;
            min-width: 0;
            text-align: center;
        }

        .nova-module-title-row {
            display: grid;
            justify-items: center;
            gap: 12px;
            min-width: 0;
            text-align: center;
        }

        .nova-module-title h3 {
            margin: 0;
            color: #0f172a;
            font-size: clamp(1.25rem, 2.1vw, 1.58rem);
            font-weight: 900;
            overflow-wrap: anywhere;
        }

        .nova-module-title p {
            display: none;
        }

        .nova-module-icon {
            display: grid;
            width: 98px;
            height: 98px;
            place-items: center;
            border-radius: 26px;
            background: linear-gradient(135deg, #2563eb, #0ea5e9);
            color: #fff;
            font-size: 2.9rem;
            box-shadow: 0 20px 38px rgba(37, 99, 235, .22);
        }

        .nova-module-icon.is-maintenance {
            background: linear-gradient(135deg, #f97316, #f59e0b);
            box-shadow: 0 16px 30px rgba(249, 115, 22, .2);
        }

        .nova-module-icon.is-legacy {
            background: linear-gradient(135deg, #475569, #64748b);
            box-shadow: 0 16px 30px rgba(71, 85, 105, .18);
        }

        .nova-module-type {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            justify-self: center;
            min-height: 28px;
            padding: 5px 9px;
            border: 1px solid #d8e3f4;
            border-radius: 999px;
            background: #fff;
            color: #334155;
            font-size: .76rem;
            font-weight: 900;
        }

        .nova-module-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .nova-module-meta .nova-badge:first-child {
            display: none;
        }

        .nova-badge.is-maintenance {
            border: 1px solid rgba(217, 119, 6, 0.3);
            background: #fff7ed;
            color: #9a3412;
        }

        .nova-module-actions {
            display: none;
        }

        .nova-module-actions .btn {
            min-width: 132px;
        }

        .nova-users-tools input {
            width: min(420px, 100%);
        }

        .nova-users-table-wrap {
            overflow: auto;
            background: #fff;
        }

        .nova-users-table {
            margin: 0;
            min-width: 860px;
            border-collapse: collapse;
        }

        .nova-users-table td,
        .nova-users-table th {
            vertical-align: top;
        }

        .nova-project-role {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0 6px 6px 0;
            padding: 6px 8px;
            border: 1px solid var(--nova-line);
            border-radius: 999px;
            background: #f8fafc;
            color: #334155;
            font-size: 0.78rem;
            font-weight: 800;
        }

        .nova-project-role.is-active {
            border-color: rgba(22, 163, 74, 0.28);
            background: #f0fdf4;
            color: #166534;
        }

        .nova-project-role.is-banned {
            border-color: rgba(220, 38, 38, 0.28);
            background: #fef2f2;
            color: #991b1b;
        }

        .nova-maintenance-list {
            display: grid;
            gap: 10px;
        }

        .nova-maintenance-item {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            padding: 14px;
            border: 1px solid var(--nova-line);
            border-radius: var(--nova-radius-lg);
            background: #fff;
        }

        .nova-maintenance-item i {
            display: grid;
            width: 42px;
            height: 42px;
            place-items: center;
            border-radius: 12px;
            background: #fff7ed;
            color: #c2410c;
            font-size: 1.15rem;
        }

        .nova-maintenance-item strong,
        .nova-maintenance-item span {
            display: block;
        }

        .nova-maintenance-item span {
            color: var(--nova-muted);
            font-size: .86rem;
            margin-top: 2px;
        }

        @media (max-width: 860px) {
            .nova-topbar,
            .nova-summary {
                align-items: flex-start;
                grid-template-columns: 1fr;
                flex-direction: column;
            }

            .nova-session,
            .nova-metrics {
                justify-content: flex-start;
            }

            .nova-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 560px) {
            .nova-home {
                padding-inline: 10px;
            }

            .nova-summary,
            .nova-module {
                padding: 16px;
            }

            .nova-module-head {
                flex-direction: column;
            }

            .nova-users-tools { padding: 14px; }
        }
    </style>
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
                <a class="btn btn-outline-secondary nova-icon-btn" href="{{ route('logout') }}" title="Salir" aria-label="Salir">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
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

        <div class="nova-section-header">
            <div>
                <h2>Disponibles</h2>
            </div>
        </div>

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
                        'administracion' => 'bi-person-gear',
                    ];
                    $projectType = $project['type'] ?? 'legacy';
                    $projectIcon = $project['icon'] ?? ($moduleIcons[$key] ?? ($projectType === 'native' ? 'bi-window-stack' : 'bi-window-sidebar'));
                    $isMaintenance = (bool) data_get($project, 'maintenance.enabled');
                    $projectUrl = match ($key) {
                        'redmine_tic' => route('redmine.native.dashboard'),
                        'redmine-mantencion' => route('redmine.mantencion.dashboard'),
                        'administracion' => route('administracion.index'),
                        default => url($key),
                    };
                @endphp
                <article class="nova-module nova-card">
                    <a class="nova-module-link" href="{{ $projectUrl }}" aria-label="Abrir {{ $project['name'] }}"></a>
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
                            <p>{{ $project['description'] }}</p>
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
