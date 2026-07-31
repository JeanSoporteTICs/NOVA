<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Monitor de Servidores | NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
</head>
<body class="nova-page monitor-page">
    <main class="nova-shell nova-shell-fluid">
        <header class="nova-topbar">
            <div class="nova-brand">
                <button class="nova-sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#monitorSidebar"
                        aria-controls="monitorSidebar" aria-label="Abrir menú lateral">
                    <i class="bi bi-list"></i>
                </button>
                <div class="nova-brand-mark" aria-hidden="true"><i class="bi bi-hdd-network"></i></div>
                <div class="nova-brand-title">
                    <strong>Monitor de Servidores</strong>
                    <span>Disponibilidad y alertas operacionales</span>
                </div>
            </div>
            <nav class="nova-session" aria-label="Sesión">
                @include('nova.partials.session-control')
                <span class="nova-navbar-user"><i class="bi bi-person-circle"></i> {{ session('nova_user.name') }}</span>
                <a class="btn btn-outline-light" href="{{ route('home') }}" title="NOVA">
                    <i class="bi bi-house-door"></i><span class="nova-navbar-label">NOVA</span>
                </a>
                <form method="POST" action="{{ route('logout') }}" class="nova-inline-form">
                    @csrf
                    <button class="btn btn-outline-light" type="submit" title="Salir">
                        <i class="bi bi-box-arrow-right"></i><span class="nova-navbar-label">Salir</span>
                    </button>
                </form>
            </nav>
        </header>

        @foreach (['monitor_status' => 'success', 'monitor_warning' => 'warning', 'monitor_error' => 'error'] as $flashKey => $flashType)
            @if (session($flashKey))
                <div data-nova-flash="{{ $flashType }}" data-nova-flash-message="{{ session($flashKey) }}" hidden></div>
            @endif
        @endforeach
        @if ($errors->any())
            <div data-nova-flash="error" data-nova-flash-message="{{ $errors->first() }}" hidden></div>
        @endif

        <div class="nova-layout monitor-layout">
            <aside class="nova-sidebar offcanvas-lg offcanvas-start" id="monitorSidebar" tabindex="-1" aria-labelledby="monitorSidebarLabel">
                <div class="offcanvas-header d-lg-none border-bottom py-3">
                    <strong class="offcanvas-title fw-bold" id="monitorSidebarLabel">Monitor de Servidores</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                </div>
                <nav class="nova-sidebar-body" aria-label="Secciones del monitor">
                    <a class="nova-sidebar-link {{ $section === 'dashboard' ? 'active' : '' }}" href="{{ route('monitor.dashboard') }}"
                       @if ($section === 'dashboard') aria-current="page" @endif>
                        <i class="bi bi-speedometer2 nova-sidebar-icon"></i><span>Resumen</span>
                    </a>
                    @if ($canManage)
                        <a class="nova-sidebar-link {{ $section === 'servers' ? 'active' : '' }}" href="{{ route('monitor.servers') }}"
                           @if ($section === 'servers') aria-current="page" @endif>
                            <i class="bi bi-server nova-sidebar-icon"></i><span>Servidores</span>
                        </a>
                        <a class="nova-sidebar-link {{ $section === 'recipients' ? 'active' : '' }}" href="{{ route('monitor.recipients') }}"
                           @if ($section === 'recipients') aria-current="page" @endif>
                            <i class="bi bi-send-check nova-sidebar-icon"></i><span>Destinatarios</span>
                        </a>
                    @endif
                </nav>
            </aside>

            <div class="nova-content monitor-content">
                <section class="monitor-hero">
            <div class="monitor-hero-copy">
                <span class="monitor-eyebrow"><i class="bi bi-broadcast-pin"></i> Centro de disponibilidad</span>
                <h1>Estado de la infraestructura</h1>
                <p>El contenedor comprueba cada destino y Telegram avisa únicamente cuando se confirma una caída o una recuperación.</p>
            </div>
            <div class="monitor-worker {{ $workerHealthy ? 'is-online' : 'is-offline' }}" data-monitor-worker>
                <span class="monitor-worker-pulse" aria-hidden="true"></span>
                <div>
                    <small>Servicio Docker</small>
                    <strong data-monitor-worker-label>{{ $workerHealthy ? 'Monitoreando' : 'Sin actividad' }}</strong>
                    <span data-monitor-worker-detail>
                        @if ($worker?->ultimo_ciclo_at)
                            Último ciclo {{ \Illuminate\Support\Carbon::parse($worker->ultimo_ciclo_at)->diffForHumans() }}
                        @else
                            Aún no registra heartbeat
                        @endif
                    </span>
                </div>
            </div>
                </section>

                @if ($section === 'dashboard')
            <section class="monitor-stats" aria-label="Resumen de disponibilidad">
                <article class="monitor-stat is-total">
                    <span><i class="bi bi-stack"></i></span>
                    <div><small>Monitoreados</small><strong data-monitor-stat="total">{{ $stats['total'] }}</strong></div>
                </article>
                <article class="monitor-stat is-up">
                    <span><i class="bi bi-check2-circle"></i></span>
                    <div><small>Disponibles</small><strong data-monitor-stat="up">{{ $stats['up'] }}</strong></div>
                </article>
                <article class="monitor-stat is-down">
                    <span><i class="bi bi-exclamation-octagon"></i></span>
                    <div><small>Caídos</small><strong data-monitor-stat="down">{{ $stats['down'] }}</strong></div>
                </article>
                <article class="monitor-stat is-pending">
                    <span><i class="bi bi-hourglass-split"></i></span>
                    <div><small>Pendientes / inestables</small><strong data-monitor-stat="pending">{{ $stats['pending'] + $stats['degraded'] }}</strong></div>
                </article>
            </section>

            <div class="monitor-dashboard-grid">
                <section class="nova-card monitor-fleet">
                    <header class="monitor-panel-head">
                        <div>
                            <span>Vista en vivo</span>
                            <h2>Servidores</h2>
                        </div>
                    </header>
                    <div class="monitor-server-overview-grid">
                        @forelse ($servers as $server)
                            @php
                                $state = $server->activo ? (string) $server->estado : 'inactivo';
                                $stateLabels = ['arriba' => 'Disponible', 'abajo' => 'Caído', 'degradado' => 'Inestable', 'pendiente' => 'Pendiente', 'inactivo' => 'Pausado'];
                                $stateLabel = $stateLabels[$state] ?? ucfirst($state);
                            @endphp
                            <article class="monitor-server-overview" data-monitor-server="{{ $server->id }}">
                                <span class="monitor-server-overview-icon is-{{ $state }}"
                                      data-monitor-state
                                      role="img"
                                      aria-label="Estado: {{ $stateLabel }}"
                                      title="{{ $stateLabel }}">
                                    <i class="bi bi-server" aria-hidden="true"></i>
                                    <span class="monitor-server-status-dot" aria-hidden="true"></span>
                                </span>
                                <strong>{{ $server->nombre }}</strong>
                            </article>
                        @empty
                            <div class="nova-empty-state monitor-empty">
                                <div class="nova-empty-state-icon"><i class="bi bi-server"></i></div>
                                <h3>Sin servidores</h3>
                                <p>Aún no hay servidores configurados.</p>
                            </div>
                        @endforelse
                    </div>
                </section>

                <aside class="nova-card monitor-events">
                    <header class="monitor-panel-head">
                        <div>
                            <span>Últimas transiciones</span>
                            <h2>Actividad</h2>
                        </div>
                        <i class="bi bi-activity monitor-panel-icon"></i>
                    </header>
                    <div class="monitor-timeline">
                        @forelse ($events as $event)
                            <article class="monitor-event is-{{ $event->tipo }}">
                                <span class="monitor-event-dot"><i class="bi {{ $event->tipo === 'recuperacion' ? 'bi-check-lg' : 'bi-exclamation-lg' }}"></i></span>
                                <div>
                                    <strong>{{ $event->servidor_nombre }}</strong>
                                    <span>{{ $event->tipo === 'recuperacion' ? 'Servidor recuperado' : 'Caída confirmada' }}</span>
                                    <small>{{ \Illuminate\Support\Carbon::parse($event->ocurrido_at)->format('d-m-Y H:i:s') }} · {{ $event->destinatarios_notificados }} aviso(s)</small>
                                </div>
                            </article>
                        @empty
                            <div class="nova-empty-state monitor-empty">
                                <div class="nova-empty-state-icon"><i class="bi bi-heart-pulse"></i></div>
                                <h3>Sin eventos</h3>
                                <p>Las caídas y recuperaciones aparecerán aquí.</p>
                            </div>
                        @endforelse
                    </div>
                </aside>
            </div>
        @elseif ($section === 'servers')
            @php
                $formServer = $editing;
                $formType = old('tipo', $formServer->tipo ?? 'tcp');
                $formDestination = old('host', $formServer
                    ? (in_array($formServer->tipo, ['icmp', 'tcp'], true) ? $formServer->host : ($targetLabels[$formServer->id] ?? $formServer->host))
                    : '');
                $openServerDrawer = $formServer || $errors->any() || request()->boolean('nuevo');
            @endphp
            <div class="monitor-inventory-shell">
                <section class="nova-card monitor-inventory">
                    <header class="monitor-panel-head">
                        <div>
                            <span>Servidores configurados</span>
                            <h2>{{ count($servers) }} destino(s) en el inventario</h2>
                        </div>
                        <div class="monitor-inventory-actions">
                            <div class="nova-table-search monitor-search">
                                <i class="bi bi-search"></i>
                                <input type="search" placeholder="Buscar servidor" data-monitor-search>
                            </div>
                            <form method="POST" action="{{ route('monitor.servers.check-all') }}" data-monitor-check-all>
                                @csrf
                                <button class="btn-nova btn-nova-secondary" type="submit" @disabled(collect($servers)->where('activo', 1)->isEmpty())>
                                    <i class="bi bi-arrow-repeat"></i><span>Comprobar todos</span>
                                </button>
                            </form>
                            @if ($formServer)
                                <a class="btn-nova btn-nova-primary" href="{{ route('monitor.servers', ['nuevo' => 1]) }}">
                                    <i class="bi bi-plus-lg"></i>Añadir servidor
                                </a>
                            @else
                                <button class="btn-nova btn-nova-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#monitor-server-drawer" aria-controls="monitor-server-drawer">
                                    <i class="bi bi-plus-lg"></i>Añadir servidor
                                </button>
                            @endif
                        </div>
                    </header>
                    <div class="monitor-server-list" data-monitor-server-list>
                        @forelse ($servers as $server)
                            @php
                                $state = $server->activo ? (string) $server->estado : 'inactivo';
                                $stateLabels = ['arriba' => 'Disponible', 'abajo' => 'Caído', 'degradado' => 'Inestable', 'pendiente' => 'Pendiente', 'inactivo' => 'Pausado'];
                            @endphp
                            <article class="monitor-server-card" data-monitor-filter="{{ strtolower($server->nombre . ' ' . $server->host . ' ' . ($targetLabels[$server->id] ?? '')) }}">
                                <div class="monitor-server-card-main">
                                    <span class="monitor-server-icon is-{{ $state }}"><i class="bi bi-server"></i></span>
                                    <div>
                                        <strong>{{ $server->nombre }}</strong>
                                        <code>{{ $targetLabels[$server->id] ?? $server->host }}</code>
                                        <span class="monitor-state is-{{ $state }}"><i class="bi bi-circle-fill"></i>{{ $stateLabels[$state] ?? ucfirst($state) }}</span>
                                    </div>
                                </div>
                                <div class="monitor-card-metrics">
                                    <span><small>Intervalo</small><strong>{{ $server->intervalo_segundos }}s</strong></span>
                                    <span><small>Latencia</small><strong>{{ $server->latencia_ms !== null ? $server->latencia_ms . ' ms' : '—' }}</strong></span>
                                    <span><small>Fallos</small><strong>{{ $server->fallos_consecutivos }}/{{ $server->fallos_para_alertar }}</strong></span>
                                </div>
                                <div class="monitor-card-actions">
                                    <form method="POST" action="{{ route('monitor.servers.check', $server->id) }}">
                                        @csrf
                                        <button class="btn-nova btn-nova-secondary btn-nova-icon-only" type="submit" title="Comprobar ahora"><i class="bi bi-arrow-repeat"></i></button>
                                    </form>
                                    <a class="btn-nova btn-nova-secondary btn-nova-icon-only" href="{{ route('monitor.servers', ['editar' => $server->id]) }}" title="Editar"><i class="bi bi-pencil"></i></a>
                                    <button class="btn-nova btn-nova-danger btn-nova-icon-only" type="button"
                                            data-monitor-delete
                                            data-monitor-delete-name="{{ $server->nombre }}"
                                            data-monitor-delete-url="{{ route('monitor.servers.destroy', $server->id) }}"
                                            title="Eliminar"><i class="bi bi-trash"></i></button>
                                </div>
                            </article>
                        @empty
                            <div class="nova-empty-state monitor-empty">
                                <div class="nova-empty-state-icon"><i class="bi bi-server"></i></div>
                                <h3>Inventario vacío</h3>
                                <p>Usa “Añadir servidor” para crear el primer destino.</p>
                            </div>
                        @endforelse
                        <div class="nova-empty-state monitor-empty monitor-filter-empty" data-monitor-filter-empty hidden>
                            <div class="nova-empty-state-icon"><i class="bi bi-search"></i></div>
                            <h3>Sin coincidencias</h3>
                            <p>No hay servidores que coincidan con la búsqueda.</p>
                        </div>
                    </div>
                    @if (count($servers) > 0)
                        <footer class="monitor-pagination-footer" data-monitor-pagination>
                            <span class="monitor-pagination-summary" data-monitor-pagination-summary aria-live="polite"></span>
                            <nav class="monitor-pagination-pages" data-monitor-pagination-pages aria-label="Páginas de servidores"></nav>
                            <label class="monitor-page-size">
                                <span>Mostrar</span>
                                <select class="form-select" data-monitor-page-size aria-label="Servidores por página">
                                    <option value="10" selected>10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                </select>
                                <span>por página</span>
                            </label>
                        </footer>
                    @endif
                </section>
            </div>

            <div class="offcanvas offcanvas-end monitor-server-drawer" tabindex="-1" id="monitor-server-drawer"
                 aria-labelledby="monitor-server-drawer-title" @if ($openServerDrawer) data-monitor-drawer-auto-open @endif>
                <div class="offcanvas-header monitor-drawer-head">
                    <div class="monitor-drawer-heading">
                        <span class="monitor-drawer-icon"><i class="bi {{ $formServer ? 'bi-pencil-square' : 'bi-plus-lg' }}"></i></span>
                        <div>
                            <small>{{ $formServer ? 'Editar destino' : 'Nuevo destino' }}</small>
                            <h2 id="monitor-server-drawer-title">{{ $formServer ? $formServer->nombre : 'Añadir servidor' }}</h2>
                            <p>{{ $formServer ? 'Actualiza la comprobación y sus tiempos.' : 'Configura dónde y con qué frecuencia comprobar.' }}</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                </div>
                <form method="POST" action="{{ $formServer ? route('monitor.servers.update', $formServer->id) : route('monitor.servers.store') }}" class="monitor-server-form monitor-drawer-form" data-monitor-server-form>
                    @csrf
                    @if ($formServer) @method('PUT') @endif
                    <div class="monitor-drawer-body">
                        <div>
                            <label class="form-label" for="monitor-name">Nombre visible</label>
                            <input class="form-control" id="monitor-name" name="nombre" value="{{ old('nombre', $formServer->nombre ?? '') }}" maxlength="160" required placeholder="Servidor de aplicaciones">
                        </div>
                        <div class="monitor-form-row" data-monitor-endpoint-row>
                            <div>
                                <label class="form-label" for="monitor-type">Comprobación</label>
                                <select class="form-select" id="monitor-type" name="tipo" data-monitor-type>
                                    <option value="icmp" @selected($formType === 'icmp')>Ping / ICMP</option>
                                    <option value="tcp" @selected($formType === 'tcp')>Puerto TCP</option>
                                    <option value="http" @selected($formType === 'http')>HTTP</option>
                                    <option value="https" @selected($formType === 'https')>HTTPS</option>
                                </select>
                            </div>
                            <div data-monitor-port-field>
                                <label class="form-label" for="monitor-port">Puerto</label>
                                <input class="form-control" id="monitor-port" name="puerto" type="number" min="1" max="65535" value="{{ old('puerto', $formServer->puerto ?? 80) }}" data-monitor-port>
                            </div>
                        </div>
                        <div class="monitor-destination-field">
                            <label class="form-label" for="monitor-host" data-monitor-destination-label>Host o IP</label>
                            <input class="form-control" id="monitor-host" name="host" value="{{ $formDestination }}" maxlength="760" required placeholder="10.63.123.249" data-monitor-destination>
                            <small class="monitor-destination-help" data-monitor-destination-help>Ingresa la IP o nombre del servidor; el puerto se configura arriba.</small>
                        </div>
                        <div class="monitor-form-row is-three">
                            <div>
                                <label class="form-label" for="monitor-interval">Intervalo</label>
                                <select class="form-select" id="monitor-interval" name="intervalo_segundos">
                                    @foreach ([30 => '30 segundos', 60 => '1 minuto', 120 => '2 minutos', 300 => '5 minutos', 600 => '10 minutos'] as $seconds => $label)
                                        <option value="{{ $seconds }}" @selected((int) old('intervalo_segundos', $formServer->intervalo_segundos ?? 60) === $seconds)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="monitor-timeout">Espera máxima</label>
                                <select class="form-select" id="monitor-timeout" name="timeout_segundos">
                                    @foreach ([2, 3, 5, 10, 15, 30] as $seconds)
                                        <option value="{{ $seconds }}" @selected((int) old('timeout_segundos', $formServer->timeout_segundos ?? 5) === $seconds)>{{ $seconds }} segundos</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="monitor-failures">Confirmar caída</label>
                                <select class="form-select" id="monitor-failures" name="fallos_para_alertar">
                                    @foreach ([1, 2, 3, 4, 5] as $attempts)
                                        <option value="{{ $attempts }}" @selected((int) old('fallos_para_alertar', $formServer->fallos_para_alertar ?? 3) === $attempts)>{{ $attempts }} intento(s)</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="monitor-switches">
                            <label class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="activo" value="1" @checked(old('activo', $formServer ? (bool) $formServer->activo : true))>
                                <span>Monitoreo activo</span>
                            </label>
                            <label class="form-check form-switch" data-monitor-ssl-field>
                                <input class="form-check-input" type="checkbox" name="verificar_ssl" value="1" @checked(old('verificar_ssl', (bool) ($formServer->verificar_ssl ?? false)))>
                                <span>Validar certificado SSL</span>
                            </label>
                        </div>
                        <p class="monitor-form-help"><i class="bi bi-info-circle"></i><span data-monitor-method-help>TCP comprueba si el puerto acepta conexiones.</span></p>
                    </div>
                    <div class="monitor-drawer-actions">
                        <button class="btn-nova btn-nova-secondary" type="button" data-bs-dismiss="offcanvas"><i class="bi bi-x-lg"></i>Cancelar</button>
                        <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i>{{ $formServer ? 'Guardar cambios' : 'Añadir servidor' }}</button>
                    </div>
                </form>
            </div>

            <div class="modal fade" id="monitor-delete-modal" tabindex="-1" aria-labelledby="monitor-delete-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content" method="POST" action="" data-monitor-delete-form>
                        @csrf
                        @method('DELETE')
                        <div class="modal-header">
                            <div>
                                <p class="detail-drawer-kicker">Eliminar servidor</p>
                                <h2 class="modal-title fs-5" id="monitor-delete-title">Confirmar eliminación</h2>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-0">Se eliminará <strong data-monitor-delete-label></strong> y su historial de eventos. Esta acción no afecta el servidor real.</p>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i>Cancelar</button>
                            <button class="btn btn-danger" type="submit"><i class="bi bi-trash"></i>Eliminar del monitor</button>
                        </div>
                    </form>
                </div>
            </div>
        @elseif ($section === 'recipients')
            @php
                $selectedCount = collect($recipientUsers)->where('alerta_activa', 1)->count();
                $adminConfigured = collect($automaticAdmins)->filter(fn ($user) => trim((string) $user->telegram_id_chat) !== '')->count();
            @endphp
            <div class="monitor-recipient-grid">
                <section class="nova-card monitor-automatic-recipients">
                    <header class="monitor-panel-head">
                        <div>
                            <span>Siempre incluidos</span>
                            <h2>Administradores</h2>
                        </div>
                        <span class="monitor-count-badge">{{ $adminConfigured }}/{{ count($automaticAdmins) }} con Telegram</span>
                    </header>
                    <p class="monitor-section-copy">Los usuarios NOVA con rol administrador o root reciben las alertas automáticamente mientras estén activos y tengan Chat ID.</p>
                    <div class="monitor-recipient-list">
                        @forelse ($automaticAdmins as $admin)
                            @php $configured = trim((string) $admin->telegram_id_chat) !== ''; @endphp
                            <article class="monitor-recipient">
                                <span class="monitor-recipient-avatar">{{ mb_strtoupper(mb_substr($admin->nombre, 0, 1) . mb_substr($admin->apellido, 0, 1)) }}</span>
                                <div>
                                    <strong>{{ trim($admin->nombre . ' ' . $admin->apellido) }}</strong>
                                    <span>{{ $admin->rol }}</span>
                                </div>
                                <span class="monitor-telegram-status {{ $configured ? 'is-ready' : 'is-pending' }}">
                                    <i class="bi {{ $configured ? 'bi-telegram' : 'bi-exclamation-circle' }}"></i>{{ $configured ? 'Configurado' : 'Sin Chat ID' }}
                                </span>
                            </article>
                        @empty
                            <div class="nova-empty">No hay administradores activos.</div>
                        @endforelse
                    </div>
                </section>

                <section class="nova-card monitor-extra-recipients">
                    <header class="monitor-panel-head">
                        <div>
                            <span>Suscripciones</span>
                            <h2>Usuarios adicionales</h2>
                        </div>
                        <span class="monitor-count-badge"><strong data-monitor-recipient-count>{{ $selectedCount }}</strong> seleccionado(s)</span>
                    </header>
                    <p class="monitor-section-copy">Selecciona usuarios activos que también recibirán tanto las caídas como las recuperaciones. Cada persona debe configurar su propio Chat ID.</p>
                    <form method="POST" action="{{ route('monitor.recipients.update') }}">
                        @csrf
                        <div class="nova-table-search monitor-recipient-search">
                            <i class="bi bi-search"></i>
                            <input type="search" placeholder="Buscar usuario" data-monitor-recipient-search>
                        </div>
                        <div class="monitor-recipient-options">
                            @forelse ($recipientUsers as $user)
                                @php
                                    $configured = trim((string) $user->telegram_id_chat) !== '';
                                    $selected = (bool) $user->alerta_activa && $configured;
                                @endphp
                                <label class="monitor-recipient-option {{ !$configured ? 'is-disabled' : '' }}"
                                       data-monitor-recipient-filter="{{ strtolower(trim($user->nombre . ' ' . $user->apellido . ' ' . $user->usuario)) }}">
                                    <span class="monitor-recipient-avatar">{{ mb_strtoupper(mb_substr($user->nombre, 0, 1) . mb_substr($user->apellido, 0, 1)) }}</span>
                                    <span class="monitor-recipient-option-copy">
                                        <strong>{{ trim($user->nombre . ' ' . $user->apellido) }}</strong>
                                        <small>{{ $configured ? '@' . $user->usuario . ' · Telegram configurado' : '@' . $user->usuario . ' · Sin Chat ID' }}</small>
                                    </span>
                                    <span class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="usuarios[]" value="{{ $user->id }}"
                                               @checked($selected) @disabled(!$configured) data-monitor-recipient-toggle>
                                    </span>
                                </label>
                            @empty
                                <div class="nova-empty-state monitor-empty">
                                    <div class="nova-empty-state-icon"><i class="bi bi-people"></i></div>
                                    <h3>Sin usuarios disponibles</h3>
                                    <p>No hay usuarios activos fuera del grupo administrador.</p>
                                </div>
                            @endforelse
                        </div>
                        <div class="monitor-recipient-actions">
                            <span><i class="bi bi-shield-check"></i>Los administradores automáticos no se modifican aquí.</span>
                            <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i>Guardar destinatarios</button>
                        </div>
                    </form>
                </section>
            </div>
                @endif
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('assets/nova-ui.js') }}"></script>
    <script src="{{ asset('assets/server-monitor.js') }}"
            data-monitor-status-url="{{ route('monitor.status') }}"
            defer></script>
</body>
</html>
