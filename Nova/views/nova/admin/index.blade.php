<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Administracion - NOVA</title>
    @include('nova.partials.favicon')
    @php $novaSidebarPreloadVersion = @filemtime(public_path('assets/nova-sidebar-preload.js')) ?: '1'; @endphp
    <script src="{{ asset('assets/nova-sidebar-preload.js') }}?v={{ $novaSidebarPreloadVersion }}" data-nova-sidebar-key="administracion"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ @filemtime(public_path('assets/nova-ui.css')) ?: '1' }}" rel="stylesheet">
    @php $novaAdminCssVersion = @filemtime(public_path('assets/nova-admin.css')) ?: '1'; @endphp
    <link href="{{ asset('assets/nova-admin.css') }}?v={{ $novaAdminCssVersion }}" rel="stylesheet">
</head>
<body class="nova-page">
    @php $currentNovaRole = strtolower((string) session('nova_user.role', 'usuario')); @endphp
    <div class="rm-shell">
        <nav class="navbar navbar-expand-lg navbar-dark rm-navbar">
            <div class="container-fluid px-4">
                <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="{{ route('administracion.index') }}">
                    <span class="rm-brand-mark"><i class="bi bi-person-gear"></i></span>
                    <span>Administracion</span>
                </a>
                <button class="nova-sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#novaSidebar" aria-controls="novaSidebar" aria-label="Abrir menú lateral">
                    <i class="bi bi-list"></i>
                </button>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#novaUsersTopbar" aria-controls="novaUsersTopbar" aria-expanded="false" aria-label="Alternar navegacion">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="novaUsersTopbar">
                    <div class="rm-top-actions mt-3 mt-lg-0">
                        @include('nova.partials.session-control')
                        <span class="text-white-50 fw-bold"><i class="bi bi-person-circle"></i> @include('nova.partials.current-user-name')</span>
                        <a class="btn btn-outline-light" href="{{ route('home') }}"><i class="bi bi-house-door"></i>NOVA</a>
                        <form method="POST" action="{{ route('logout') }}" style="display:inline">
                            @csrf
                            <button class="btn btn-outline-light" type="submit"><i class="bi bi-box-arrow-right"></i>Salir</button>
                        </form>
                    </div>
                </div>
            </div>
        </nav>

        @php
            $adminSections = [
                'centro' => ['label' => 'Centro', 'icon' => config('navigation-icons.centro'), 'description' => 'Resumen rapido de usuarios, salud, accesos y Telegram.'],
                'configuracion' => ['label' => 'Configuracion', 'icon' => config('navigation-icons.configuracion'), 'description' => 'Ajustes globales de sesion, salud y notificaciones administrativas.'],
                'onlyoffice' => ['label' => 'OnlyOffice', 'icon' => config('navigation-icons.onlyoffice'), 'description' => 'Configura el editor en linea utilizado por Procedimientos.'],
                'salud' => ['label' => 'Salud', 'icon' => config('navigation-icons.salud'), 'description' => 'Chequeos de servicios y dependencias criticas.'],
                'auditoria' => ['label' => 'Auditoria', 'icon' => config('navigation-icons.auditoria'), 'description' => 'Eventos recientes y acciones registradas en administracion.'],
                'telegram' => ['label' => 'Telegram', 'icon' => config('navigation-icons.telegram'), 'description' => 'Configura el bot global y revisa el estado del servicio.'],
                'telegram-mensajes' => ['label' => 'Mensajes Telegram', 'icon' => config('navigation-icons.mensajes_telegram'), 'description' => 'Edita las respuestas programadas que envia el bot.'],
                'usuarios' => ['label' => 'Usuarios', 'icon' => config('navigation-icons.usuarios'), 'description' => 'Crea usuarios, revisa integraciones personales y administra estados.'],
                'accesos' => ['label' => 'Accesos', 'icon' => config('navigation-icons.accesos'), 'description' => 'Define a que vistas NOVA puede entrar cada usuario.'],
            ];
            $activeAdminSection = $adminSections[$section] ?? $adminSections['centro'];
        @endphp
        <div class="nova-layout">
            <aside class="nova-sidebar offcanvas-lg offcanvas-start" id="novaSidebar" tabindex="-1" aria-labelledby="novaSidebarLabel">
                <div class="offcanvas-header d-lg-none border-bottom py-3">
                    <strong class="offcanvas-title fw-bold" id="novaSidebarLabel">Administracion</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                </div>
                <nav class="nova-sidebar-body" aria-label="Secciones Administracion">
                    @foreach ($adminSections as $sectionKey => $item)
                        <a class="nova-sidebar-link {{ $section === $sectionKey ? 'active' : '' }}"
                           href="{{ route('administracion.section', $sectionKey) }}"
                           @if ($section === $sectionKey) aria-current="page" @endif>
                            <i class="bi {{ $item['icon'] }} nova-sidebar-icon"></i>
                            <span>{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </nav>
                @include('nova.partials.sidebar-compact-control', ['sidebarId' => 'novaSidebar'])
            </aside>

            <main class="nova-content rm-main">
                <section class="card rm-hero mb-4">
                    <div class="card-body p-3 p-lg-4 d-flex align-items-center gap-3 flex-wrap">
                        <div class="d-flex align-items-center gap-3">
                            <span class="rm-hero-icon"><i class="bi {{ $activeAdminSection['icon'] }}"></i></span>
                            <div>
                                <h1 class="rm-page-title">{{ $activeAdminSection['label'] }}</h1>
                                <p class="rm-page-subtitle">{{ $activeAdminSection['description'] }}</p>
                            </div>
                        </div>
                        <span class="rm-hero-retention"><i class="bi bi-shield-check"></i>NOVA global</span>
                    </div>
                </section>

                @if ($section === 'centro')
                    @php
                        $activeUsers = collect($users)->where('status', 'activo')->count();
                        $healthOk = collect($healthChecks)->where('status', 'ok')->count();
                        $healthWarn = collect($healthChecks)->where('status', 'warn')->count();
                        $healthError = collect($healthChecks)->where('status', 'error')->count();
                        $projectRows = $accessMatrix['matrix'] ?? [];
                        $queuedMessages = (int) data_get($telegramListener, 'queue.outbox', 0);
                        $failedMessages = (int) data_get($telegramListener, 'queue.failed', 0);
                    @endphp
                    <div class="control-grid">
                        <section class="control-card">
                            <i class="bi bi-people"></i>
                            <h3>Usuarios activos</h3>
                            <strong>{{ $activeUsers }}</strong>
                            <span>{{ count($users) }} usuario(s) centralizados.</span>
                        </section>
                        <section class="control-card">
                            <i class="bi bi-activity"></i>
                            <h3>Salud</h3>
                            <strong>{{ $healthError > 0 ? $healthError : $healthWarn }}</strong>
                            <span>{{ $healthOk }} OK / {{ $healthWarn }} alerta(s) / {{ $healthError }} error(es)</span>
                        </section>
                        <section class="control-card">
                            <i class="bi bi-shield-lock"></i>
                            <h3>Accesos</h3>
                            <strong>{{ count($projectRows) }}</strong>
                            <span>Usuario(s) evaluados en la matriz NOVA.</span>
                        </section>
                        <section class="control-card">
                            <i class="bi bi-telegram"></i>
                            <h3>Telegram</h3>
                            <strong>{{ count($telegramCommands ?? []) }}</strong>
                            <span>{{ $queuedMessages }} por enviar / {{ $failedMessages }} fallido(s).</span>
                        </section>
                    </div>

                    <section class="card nova-card rm-work-panel rm-panel mb-3">
                        <div class="rm-section-head">
                            <div>
                                <h2>Acciones rapidas</h2>
                                <p>Atajos a las tareas administrativas mas usadas.</p>
                            </div>
                        </div>
                        <div class="control-actions">
                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'salud') }}"><i class="bi bi-activity"></i>Ver salud</a>
                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'auditoria') }}"><i class="bi bi-journal-text"></i>Ver auditoria</a>
                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'telegram') }}"><i class="bi bi-telegram"></i>Configurar Telegram</a>
                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'accesos') }}"><i class="bi bi-shield-lock"></i>Revisar accesos</a>
                        </div>
                    </section>

                    <div class="row g-3">
                        <div class="col-12 col-xl-7">
                            <section class="card nova-card rm-work-panel rm-panel h-100">
                                <div class="rm-section-head">
                                    <div>
                                        <h2>Comandos Telegram</h2>
                                        <p>Comandos que puede usar el bot global.</p>
                                    </div>
                                    <a class="btn btn-sm btn-outline-secondary fw-bold" href="{{ route('administracion.section', 'telegram-mensajes') }}"><i class="bi bi-pencil-square"></i>Editar mensajes</a>
                                </div>
                                <div class="command-list">
                                    @forelse (($telegramCommands ?? []) as $command)
                                        <div class="command-row">
                                            <div>
                                                <code>{{ $command['command'] ?? '' }}</code>
                                                @foreach (($command['aliases'] ?? []) as $alias)
                                                    <span class="command-alias">{{ $alias }}</span>
                                                @endforeach
                                            </div>
                                            <div>
                                                <strong>{{ $command['module'] ?? '-' }}</strong>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="nova-muted fw-semibold">No hay comandos configurados.</div>
                                    @endforelse
                                </div>
                            </section>
                        </div>
                        <div class="col-12 col-xl-5">
                            <section class="card nova-card rm-work-panel rm-panel h-100">
                                <div class="rm-section-head">
                                    <div>
                                        <h2>Auditoria reciente</h2>
                                        <p>Ultimos eventos registrados por NOVA.</p>
                                    </div>
                                    <a class="btn btn-sm btn-outline-secondary fw-bold" href="{{ route('administracion.section', 'auditoria') }}"><i class="bi bi-clock-history"></i>Ver todo</a>
                                </div>
                                <div class="security-console-wrap">
                                    <div class="security-console-toolbar"><span class="security-console-dot" aria-hidden="true"></span><span>Auditoría NOVA :: eventos recientes</span></div>
                                    <div class="table-responsive">
                                    <table class="table security-console mb-0">
                                        <thead><tr><th>Fecha</th><th>Evento</th><th>Usuario</th></tr></thead>
                                        <tbody>
                                            @forelse ($auditItems as $item)
                                                <tr>
                                                    <td class="console-time">{{ $item['at'] ?? '' }}</td>
                                                    <td><span class="console-tag">{{ strtoupper((string) ($item['event'] ?? '')) }}</span></td>
                                                    <td class="console-details">{{ $item['user_name'] ?? '-' }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="3">No hay eventos registrados.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table></div>
                                </div>
                            </section>
                        </div>
                    </div>
                @endif

                @if ($section === 'configuracion')
                    <div class="config-grid">
                        <section class="card nova-card rm-work-panel rm-panel is-wide">
                            <div class="rm-section-head">
                                <div>
                                    <h2>Configuracion global</h2>
                                    <p>Ajustes transversales que afectan la experiencia de administracion.</p>
                                </div>
                            </div>
                            <form method="post" action="{{ route('administracion.config.update') }}">
                                @csrf
                                <input type="hidden" name="action" value="settings">
                                <div class="row g-3 align-items-end">
                                    <div class="col-12">
                                        <label class="form-label" for="session_timeout">Tiempo de sesion</label>
                                        <input class="form-control" id="session_timeout" name="session_timeout" type="number" min="60" step="1" value="{{ $settings['session_timeout'] ?? 3600 }}">
                                        <div class="form-text fw-semibold">Tiempo en segundos antes de pedir reautenticacion.</div>
                                    </div>
                                    <div class="col-12 d-flex align-items-end">
                                        <div class="form-check form-switch fw-bold">
                                            <input class="form-check-input" type="checkbox" role="switch" id="notification_enabled" name="notification_enabled" value="1" @checked(!empty($settings['notification_enabled']))>
                                            <label class="form-check-label" for="notification_enabled">Notificar administradores por Telegram</label>
                                            <div class="form-text fw-semibold">Envia avisos a usuarios con rol admin y Chat ID configurado.</div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i>Guardar configuracion</button>
                                    </div>
                                </div>
                            </form>
                        </section>
                    </div>
                @endif

                @if ($section === 'onlyoffice')
                    <section class="card nova-card rm-work-panel rm-panel">
                        <div class="rm-section-head">
                            <div><h2>Editor OnlyOffice</h2><p>Conexion global del modulo Procedimientos. El secreto JWT se almacena cifrado y nunca vuelve a mostrarse.</p></div>
                            <span class="nova-badge {{ empty($onlyOffice['enabled']) ? 'is-neutral' : (!empty($onlyOffice['configured']) ? 'is-success' : 'is-warning') }}">{{ empty($onlyOffice['enabled']) ? 'Desactivado' : (!empty($onlyOffice['configured']) ? 'Operativo' : 'Pendiente') }}</span>
                        </div>
                        <div class="integration-meta mb-3">
                            <div><dt>Servidor</dt><dd>{{ $onlyOffice['url'] ?: 'Sin configurar' }}</dd></div>
                            <div><dt>JWT</dt><dd>{{ !empty($onlyOffice['secret_configured']) ? 'Configurado y cifrado' : 'Pendiente' }}</dd></div>
                        </div>
                        <div class="onlyoffice-action-row">
                            <button class="btn-nova btn-nova-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#onlyOfficeDrawer" aria-controls="onlyOfficeDrawer"><i class="bi bi-pencil-square"></i>Editar</button>
                            <form method="post" action="{{ route('administracion.onlyoffice.test') }}">
                                @csrf
                                <button class="btn-nova btn-nova-info" type="submit" {{ empty($onlyOffice['enabled']) ? 'disabled' : '' }}><i class="bi bi-shield-check"></i>Probar servidor y clave</button>
                            </form>
                            <form class="onlyoffice-toggle-form" method="post" action="{{ route('administracion.config.update') }}">
                                @csrf
                                <input type="hidden" name="action" value="onlyoffice_toggle">
                                <label class="form-check form-switch onlyoffice-enabled-switch" for="onlyoffice_enabled">
                                    <input class="form-check-input" type="checkbox" role="switch" id="onlyoffice_enabled" name="onlyoffice_enabled" value="1" @checked(!empty($onlyOffice['enabled'])) onchange="this.form.submit()">
                                    <span>Servicio {{ !empty($onlyOffice['enabled']) ? 'activo' : 'desactivado' }}</span>
                                </label>
                            </form>
                        </div>
                    </section>

                    <div class="offcanvas offcanvas-end integration-drawer" tabindex="-1" id="onlyOfficeDrawer" aria-labelledby="onlyOfficeDrawerTitle">
                        <div class="offcanvas-header">
                            <div class="integration-drawer-title"><span class="integration-icon"><i class="bi bi-file-earmark-word"></i></span><div><h2 class="offcanvas-title" id="onlyOfficeDrawerTitle">Editar OnlyOffice</h2><span class="integration-status {{ !empty($onlyOffice['configured']) ? 'is-ready' : 'is-empty' }}"><i class="bi bi-circle-fill"></i>{{ !empty($onlyOffice['configured']) ? 'Configurado' : 'Pendiente' }}</span></div></div>
                            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                        </div>
                        <div class="offcanvas-body">
                            <form id="onlyOfficeForm" method="post" action="{{ route('administracion.config.update') }}" class="integration-form">
                                @csrf
                                <input type="hidden" name="action" value="onlyoffice">
                                <div><label class="form-label" for="onlyoffice_url">Servidor OnlyOffice</label><input class="form-control" id="onlyoffice_url" name="onlyoffice_url" type="url" value="{{ $onlyOffice['url'] ?? '' }}" placeholder="https://onlyoffice.ejemplo.cl" required><div class="form-text">URL base del Document Server, sin rutas internas.</div></div>
                                <div><label class="form-label" for="onlyoffice_jwt_secret">Secreto JWT</label><input class="form-control" id="onlyoffice_jwt_secret" name="onlyoffice_jwt_secret" type="password" autocomplete="new-password" placeholder="{{ !empty($onlyOffice['secret_configured']) ? 'Configurado; deja vacío para conservarlo' : 'Ingresa el secreto compartido' }}"><div class="form-text">{{ !empty($onlyOffice['secret_configured']) ? 'Solo escribe un valor para reemplazar el secreto cifrado.' : 'Aún no existe un secreto configurado.' }}</div></div>
                            </form>
                        </div>
                        <div class="offcanvas-footer nova-drawer-actions"><button class="btn-nova btn-nova-secondary" type="button" data-bs-dismiss="offcanvas"><i class="bi bi-x-lg"></i>Cancelar</button><button class="btn-nova btn-nova-primary" type="submit" form="onlyOfficeForm"><i class="bi bi-shield-lock"></i>Guardar</button></div>
                    </div>
                @endif

                @if ($section === 'salud')
                    <section class="card nova-card rm-work-panel rm-panel">
                        <div class="rm-section-head">
                            <div>
                                <h2>Estado de servicios</h2>
                                <p>Se recalcula al cargar la pagina. Las alertas automaticas corren cada 5 minutos si el scheduler esta activo.</p>
                            </div>
                            <form method="post" action="{{ route('administracion.health.notify') }}">
                                @csrf
                                <button class="btn-nova btn-nova-primary" type="submit">
                                    <i class="bi bi-send"></i>Enviar estado principal
                                </button>
                            </form>
                        </div>
                        <div class="table-responsive rm-table-wrap">
                            <table class="table mb-0">
                                <thead><tr><th>Chequeo</th><th>Estado</th><th>Detalle</th></tr></thead>
                                <tbody>
                                    @foreach ($healthChecks as $check)
                                        @php $status = $check['status'] ?? 'warn'; @endphp
                                        <tr>
                                            <td><strong>{{ $check['name'] ?? '' }}</strong></td>
                                            <td><span class="health-dot is-{{ $status }}"><i class="bi bi-circle-fill"></i>{{ strtoupper($status) }}</span></td>
                                            <td>{{ $check['detail'] ?? '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                @if ($section === 'auditoria')
                    <section class="card nova-card rm-work-panel rm-panel">
                        <div class="rm-section-head">
                            <div>
                                <h2>Auditoria global</h2>
                                <p>Historial de acciones administrativas relevantes.</p>
                            </div>
                        </div>
                        <div class="table-responsive rm-table-wrap">
                            <table class="table mb-0">
                                <thead><tr><th>Fecha</th><th>Evento</th><th>Usuario</th><th>Detalle</th><th>IP</th></tr></thead>
                                <tbody>
                                    @forelse ($auditItems as $item)
                                        <tr>
                                            <td class="console-time">{{ $item['at'] ?? '' }}</td>
                                            <td><span class="console-tag">{{ strtoupper((string) ($item['event'] ?? '')) }}</span></td>
                                            <td class="console-details">{{ $item['user_name'] ?? '-' }}</td>
                                            <td class="console-details">{{ $item['message'] ?? '' }}</td>
                                            <td class="console-details">{{ $item['ip'] ?? '' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5">No hay eventos registrados.</td></tr>
                                    @endforelse
                                </tbody>
                            </table></div>
                        </div>
                    </section>
                @endif

                @if ($section === 'telegram')
                    @php
                        $webhookActive = (bool) data_get($telegramListener, 'webhook.active', false);
                        $webhookAvailable = (bool) data_get($telegramListener, 'webhook.available', false);
                        $pendingUpdates = data_get($telegramListener, 'webhook.pending');
                        $queuedMessages = (int) data_get($telegramListener, 'queue.outbox', 0);
                        $failedMessages = (int) data_get($telegramListener, 'queue.failed', 0);
                        $webhookError = (string) data_get($telegramListener, 'webhook.error', '');
                    @endphp
                    <div class="telegram-admin-grid">
                        <section class="card nova-card rm-work-panel rm-panel">
                            <div class="rm-section-head">
                                <div>
                                    <h2>Telegram global</h2>
                                    <p>Token y proxy usados por el bot central.</p>
                                </div>
                                <span class="config-status {{ $telegramConfigured ? 'is-ok' : 'is-warn' }}">
                                    <i class="bi {{ $telegramConfigured ? 'bi-check-circle' : 'bi-exclamation-triangle' }}"></i>{{ $telegramConfigured ? 'Bot activo' : 'Pendiente' }}
                                </span>
                            </div>
                            <form method="post" action="{{ route('administracion.config.update') }}">
                                @csrf
                                <input type="hidden" name="action" value="telegram">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold" for="config-telegram-token">TELEGRAM_BOT_TOKEN</label>
                                        <input class="form-control" id="config-telegram-token" name="bot_token" type="password" autocomplete="off" placeholder="{{ $telegramConfigured ? 'Dejar en blanco para conservar' : 'Token de BotFather' }}">
                                        <div class="form-text fw-semibold">Si ya esta configurado, deja este campo vacio para mantener el token actual.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold" for="config-telegram-proxy">TELEGRAM_PROXY_URL</label>
                                        <input class="form-control" id="config-telegram-proxy" name="proxy_url" value="{{ old('proxy_url', $telegramConfig['proxy_url'] ?? '') }}" placeholder="Opcional, ejemplo: http://proxy:8080">
                                        <div class="form-text fw-semibold">Opcional. Usalo solo si el servidor necesita proxy para salir a internet.</div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex flex-wrap gap-2">
                                            <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i>Guardar Telegram</button>
                                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'telegram-mensajes') }}"><i class="bi bi-chat-square-text"></i>Mensajes Telegram</a>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </section>

                        <section class="card nova-card rm-work-panel rm-panel">
                            <div class="rm-section-head">
                                <div>
                                    <h2>Servicio Telegram</h2>
                                    <p>Estado operativo del listener y la cola de mensajes.</p>
                                </div>
                                <span class="config-status is-ok">
                                    <i class="bi bi-box-seam"></i>Docker
                                </span>
                            </div>
                            <div class="telegram-listener-grid mb-3">
                                <div class="telegram-listener-metric is-ok">
                                    <i class="bi bi-box-seam"></i>
                                    <div>
                                        <span>Servicio</span>
                                        <strong>Dockerizado</strong>
                                    </div>
                                </div>
                                <div class="telegram-listener-metric {{ $webhookActive ? 'is-warn' : 'is-ok' }}">
                                    <i class="bi {{ $webhookActive ? 'bi-link-45deg' : 'bi-unlink' }}"></i>
                                    <div>
                                        <span>Webhook</span>
                                        <strong>{{ $webhookActive ? 'Activo' : ($webhookAvailable ? 'Inactivo' : 'Sin datos') }}</strong>
                                    </div>
                                </div>
                                <div class="telegram-listener-metric">
                                    <i class="bi bi-inboxes"></i>
                                    <div>
                                        <span>Cola Telegram</span>
                                        <strong>{{ $pendingUpdates === null ? '-' : $pendingUpdates }}</strong>
                                    </div>
                                </div>
                                <div class="telegram-listener-metric {{ $queuedMessages > 0 ? 'is-warn' : 'is-ok' }}">
                                    <i class="bi bi-send"></i>
                                    <div>
                                        <span>Por enviar</span>
                                        <strong>{{ $queuedMessages }}</strong>
                                    </div>
                                </div>
                                <div class="telegram-listener-metric {{ $failedMessages > 0 ? 'is-bad' : '' }}">
                                    <i class="bi bi-exclamation-octagon"></i>
                                    <div>
                                        <span>Fallidos</span>
                                        <strong>{{ $failedMessages }}</strong>
                                    </div>
                                </div>
                            </div>
                            <div class="telegram-listener-actions mb-3">
                                <form method="post" action="{{ route('administracion.telegram.listener') }}">
                                    @csrf
                                    <input type="hidden" name="action" value="delete_webhook">
                                    <button class="btn-nova btn-nova-warning fw-bold" type="submit" @disabled(!$webhookActive || !$telegramConfigured)><i class="bi bi-unlink"></i>Quitar webhook</button>
                                </form>
                                <a class="btn btn-outline-secondary fw-bold" href="{{ route('administracion.section', 'telegram') }}"><i class="bi bi-arrow-clockwise"></i>Refrescar</a>
                            </div>
                            @if ($webhookError !== '')
                                <div class="nova-alert-card is-warning"><i class="bi bi-exclamation-triangle-fill"></i> {{ $webhookError }}</div>
                            @endif
                        </section>
                    </div>
                @endif

                @if ($section === 'telegram-mensajes')
                    @php
                        $emachMessageVariants = [
                            ['key' => 'emach_success_entrada', 'label' => 'Entrada', 'icon' => '🟢'],
                            ['key' => 'emach_success_salida', 'label' => 'Salida', 'icon' => '🔴'],
                            ['key' => 'emach_success', 'label' => 'Otro tipo', 'icon' => '⚪'],
                        ];
                        $messageLabels = [
                            'help_header' => 'Encabezado de ayuda',
                            'status' => 'Respuesta de /estado',
                            'test' => 'Respuesta de /test',
                            'tic_success' => 'Reporte TIC creado',
                            'tic_unavailable' => 'TIC no disponible',
                            'tic_error' => 'Error TIC',
                            'tic_mode_activated' => 'Modo TIC activado',
                            'tic_mode_deactivated' => 'Modo TIC desactivado',
                            'tic_mode_status_active' => 'Estado modo TIC activo',
                            'tic_mode_status_inactive' => 'Estado modo TIC inactivo',
                            'tic_mode_invalid_format' => 'Formato TIC inválido',
                            'emach_success_entrada' => 'Marcación EMACH',
                            'emach_missing_chat_id' => 'EMACH sin Chat ID',
                            'emach_user_lookup_error' => 'EMACH sin conexión NOVA',
                            'emach_missing_credentials' => 'EMACH sin credenciales',
                            'emach_empty' => 'EMACH sin marcaciones',
                            'emach_error' => 'Error EMACH',
                            'disabled' => 'Comando desactivado',
                            'unknown' => 'Comando desconocido',
                        ];
                        $commandMessageMap = [
                            'help' => 'help_header',
                            'status' => 'status',
                            'emach' => 'emach_success_entrada',
                            'tic' => 'tic_success',
                            'test' => 'test',
                        ];
                        $messageHelp = [
                            'help_header' => 'Primera línea que aparece cuando alguien solicita ayuda.',
                            'status' => 'Confirma que el bot esta activo y responde.',
                            'test' => 'Mensaje simple para probar que Telegram responde.',
                            'tic_success' => 'Confirmación cuando se crea un reporte TIC pendiente.',
                            'tic_unavailable' => 'Se muestra si NOVA no puede cargar el módulo TIC.',
                            'tic_error' => 'Se muestra si falla la creación del reporte TIC.',
                            'tic_mode_activated' => 'Confirma que los mensajes con formato serán reportes hasta el final del día.',
                            'tic_mode_deactivated' => 'Confirma la salida del modo de recepción diaria.',
                            'tic_mode_status_active' => 'Indica que el modo diario está activo y su vencimiento.',
                            'tic_mode_status_inactive' => 'Indica cómo activar el modo diario.',
                            'tic_mode_invalid_format' => 'Se muestra cuando un mensaje diario no contiene los tres campos requeridos.',
                            'emach_success_entrada' => 'Personaliza el mensaje según el tipo de la última marcación encontrada.',
                            'emach_missing_chat_id' => 'Se muestra si el Chat ID que escribio al bot no esta asociado a un usuario NOVA.',
                            'emach_user_lookup_error' => 'Se muestra si el listener de Docker no puede consultar usuarios NOVA en la base de datos.',
                            'emach_missing_credentials' => 'Se muestra si el usuario no tiene credenciales EMACH guardadas.',
                            'emach_empty' => 'Se muestra si no hay marcaciones durante el mes actual.',
                            'emach_error' => 'Se muestra si la consulta EMACH falla.',
                            'disabled' => 'Se muestra cuando el comando existe pero esta apagado.',
                            'unknown' => 'Se muestra cuando el bot no reconoce lo que escribieron.',
                        ];
                        $messagePlaceholdersMap = [
                            'status' => ['{fecha}' => 'Fecha y hora actual'],
                            'test' => ['{fecha}' => 'Fecha y hora actual'],
                            'tic_success' => ['{asunto}' => 'Problema reportado', '{categoria}' => 'Categoría detectada', '{unidad}' => 'Ubicación o unidad'],
                            'tic_error' => ['{error}' => 'Detalle del error'],
                            'tic_mode_activated' => ['{hasta}' => 'Fecha y hora de término'],
                            'tic_mode_status_active' => ['{hasta}' => 'Fecha y hora de término'],
                            'emach_success_entrada' => ['{fecha}' => 'Fecha de marcación', '{hora}' => 'Hora de marcación', '{tipo}' => 'Tipo informado por EMACH', '{reloj}' => 'Reloj utilizado'],
                            'emach_error' => ['{error}' => 'Detalle del error'],
                        ];
                        $systemMessageKeys = array_diff(array_keys($messageLabels), array_values($commandMessageMap));
                        $messages = $telegramCommandSettings['messages'] ?? [];
                        $messageRows = [];
                        foreach (($telegramCommands ?? []) as $command) {
                            $commandKey = (string) ($command['key'] ?? '');
                            $messageKey = $commandMessageMap[$commandKey] ?? '';
                            if ($messageKey === '') {
                                continue;
                            }
                            $messageRows[] = [
                                'type' => 'command',
                                'key' => $messageKey,
                                'label' => $messageLabels[$messageKey] ?? 'Respuesta',
                                'summary' => $messageHelp[$messageKey] ?? 'Mensaje del comando.',
                                'command_key' => $commandKey,
                                'command' => (string) ($command['command'] ?? ''),
                                'aliases' => $command['aliases'] ?? [],
                                'module' => (string) ($command['module'] ?? ''),
                                'description' => (string) ($command['description'] ?? ''),
                                'input' => (string) ($command['input'] ?? ''),
                                'enabled' => (bool) ($command['enabled'] ?? true),
                                'variants' => $commandKey === 'emach' ? $emachMessageVariants : [],
                            ];
                        }
                        foreach ($systemMessageKeys as $key) {
                            $messageRows[] = [
                                'type' => 'system',
                                'key' => $key,
                                'label' => $messageLabels[$key] ?? $key,
                                'summary' => $messageHelp[$key] ?? 'Mensaje de sistema.',
                                'command_key' => '',
                                'command' => '',
                                'aliases' => [],
                                'module' => 'Sistema',
                                'description' => '',
                                'input' => '',
                                'enabled' => true,
                                'variants' => [],
                            ];
                        }
                        $firstMessageKey = (string) ($messageRows[0]['key'] ?? '');
                    @endphp
                    <form method="post" action="{{ route('administracion.config.update') }}">
                        @csrf
                        <input type="hidden" name="action" value="telegram_messages">
                        <div class="telegram-message-grid">
                            <section class="card nova-card rm-work-panel rm-panel">
                            <div class="rm-section-head">
                                <div>
                                    <h2>Mensajes de Telegram</h2>
                                    <p>Selecciona una respuesta, revisa su contenido y guarda todos los cambios al finalizar.</p>
                                    </div>
                                    <span class="config-status is-ok"><i class="bi bi-pencil-square"></i>Editables</span>
                                </div>

                                <div class="telegram-message-editor-layout" data-telegram-message-editor>
                                    <aside class="telegram-message-picker" aria-label="Mensajes programados">
                                        <div class="telegram-message-picker-head">
                                            <div><h3 class="telegram-message-picker-title">Respuestas disponibles</h3><p>Comandos y mensajes automáticos</p></div>
                                            <span>{{ count($messageRows) }}</span>
                                        </div>
                                        @foreach ($messageRows as $row)
                                            <button class="telegram-message-option {{ $row['key'] === $firstMessageKey ? 'is-active' : '' }}" type="button" data-telegram-message-option="{{ $row['key'] }}">
                                                <strong>
                                                    <i class="bi {{ $row['type'] === 'command' ? 'bi-command' : 'bi-chat-square-text' }}"></i>
                                                    {{ $row['label'] }}
                                                </strong>
                                                <span>
                                                    @if ($row['type'] === 'command')
                                                        <code>{{ $row['command'] }}</code> <span aria-hidden="true">·</span> {{ $row['module'] }}
                                                    @else
                                                        Sistema
                                                    @endif
                                                </span>
                                            </button>
                                        @endforeach
                                    </aside>

                                    <div>
                                        @foreach ($messageRows as $row)
                                        @php
                                            $messageKey = (string) $row['key'];
                                            $messageValue = old("messages.{$messageKey}", $messages[$messageKey] ?? '');
                                            $messagePlaceholders = $messagePlaceholdersMap[$messageKey] ?? [];
                                            $messageVariants = $row['variants'] ?? [];
                                        @endphp
                                            <article class="telegram-message-editor {{ $messageKey === $firstMessageKey ? 'is-active' : '' }}" data-telegram-message-panel="{{ $messageKey }}">
                                                <div class="telegram-message-editor-head">
                                                    <div>
                                                        <h3>{{ $row['label'] }}</h3>
                                                        <p>{{ $row['summary'] }}</p>
                                                    </div>
                                                    @if ($row['type'] === 'command')
                                                        <span class="telegram-message-command">{{ $row['command'] }}</span>
                                                    @else
                                                        <span class="config-status"><i class="bi bi-gear"></i>Sistema</span>
                                                    @endif
                                                </div>

                                                @if ($row['type'] === 'command')
                                                    <div class="telegram-message-context">
                                                        <div><span>Descripción</span><strong>{{ $row['description'] }}</strong></div>
                                                        <div><span>Formato esperado</span><strong>{{ $row['input'] }}</strong></div>
                                                        @foreach (($row['aliases'] ?? []) as $alias)
                                                            <span class="command-alias">{{ $alias }}</span>
                                                        @endforeach
                                                    </div>
                                                    <label class="form-check form-switch telegram-command-message-toggle" for="telegram-command-{{ $row['command_key'] }}">
                                                        <input type="hidden" name="commands[{{ $row['command_key'] }}][enabled]" value="0">
                                                        <input class="form-check-input" id="telegram-command-{{ $row['command_key'] }}" type="checkbox" role="switch" name="commands[{{ $row['command_key'] }}][enabled]" value="1" @checked($row['enabled'])>
                                                        <span>Comando activo</span>
                                                    </label>
                                                @endif

                                                @if ($messageVariants !== [])
                                                    <div class="telegram-message-variant-picker" role="tablist" aria-label="Tipo de marcación EMACH">
                                                        @foreach ($messageVariants as $variant)
                                                            <button
                                                                class="telegram-message-variant-option {{ $loop->first ? 'is-active' : '' }}"
                                                                id="telegram-emach-tab-{{ $variant['key'] }}"
                                                                type="button"
                                                                role="tab"
                                                                aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                                                                aria-controls="telegram-emach-panel-{{ $variant['key'] }}"
                                                                data-telegram-emach-variant-option="{{ $variant['key'] }}"
                                                            >
                                                                <span aria-hidden="true">{{ $variant['icon'] }}</span>
                                                                <strong>{{ $variant['label'] }}</strong>
                                                            </button>
                                                        @endforeach
                                                    </div>

                                                    @foreach ($messageVariants as $variant)
                                                        @php
                                                            $variantKey = (string) $variant['key'];
                                                            $variantValue = old("messages.{$variantKey}", $messages[$variantKey] ?? '');
                                                        @endphp
                                                        <div
                                                            class="telegram-message-variant-panel {{ $loop->first ? 'is-active' : '' }}"
                                                            id="telegram-emach-panel-{{ $variantKey }}"
                                                            role="tabpanel"
                                                            aria-labelledby="telegram-emach-tab-{{ $variantKey }}"
                                                            data-telegram-emach-variant="{{ $variantKey }}"
                                                        >
                                                            <label for="telegram-message-{{ $variantKey }}">Mensaje para {{ $variant['label'] }}</label>
                                                            <textarea class="form-control" id="telegram-message-{{ $variantKey }}" name="messages[{{ $variantKey }}]" rows="7" spellcheck="true">{{ $variantValue }}</textarea>
                                                            <p class="telegram-edit-help"><i class="bi bi-info-circle"></i> NOVA elegirá esta plantilla automáticamente cuando el tipo sea <strong>{{ $variant['label'] }}</strong>.</p>
                                                            <div class="telegram-placeholder-list" aria-label="Campos disponibles">
                                                                <span>Datos disponibles:</span>
                                                                @foreach ($messagePlaceholders as $placeholder => $description)
                                                                    <code title="{{ $description }}">{{ $placeholder }}</code>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                @else
                                                    <div>
                                                        <label for="telegram-message-{{ $messageKey }}">Mensaje programado</label>
                                                        <textarea class="form-control" id="telegram-message-{{ $messageKey }}" name="messages[{{ $messageKey }}]" rows="7" spellcheck="true">{{ $messageValue }}</textarea>
                                                        <p class="telegram-edit-help"><i class="bi bi-info-circle"></i> Los campos entre llaves, como <code>{fecha}</code>, son completados automáticamente por NOVA.</p>
                                                        @if ($messagePlaceholders !== [])
                                                            <div class="telegram-placeholder-list" aria-label="Campos disponibles">
                                                                <span>Datos disponibles:</span>
                                                                @foreach ($messagePlaceholders as $placeholder => $description)
                                                                    <code title="{{ $description }}">{{ $placeholder }}</code>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </div>
                                                @endif
                                            </article>
                                        @endforeach
                                    </div>
                                </div>
                            </section>
                            <div class="telegram-save-bar">
                                <div>
                                    <strong>Guardar cambios</strong>
                                    <span>Se actualizarán las respuestas y los comandos activos del bot.</span>
                                </div>
                                <button class="btn-nova btn-nova-primary fw-bold" type="submit"><i class="bi bi-save"></i>Guardar mensajes</button>
                            </div>
                        </div>
                    </form>
                @endif

                @if ($section === 'usuarios')
                <div class="user-grid">
            <div class="nova-modal-backdrop" data-user-modal aria-hidden="true">
            <form class="nova-confirm nova-user-form form-panel" method="post" action="{{ route('administracion.users.update') }}">
                @csrf
                <input type="hidden" name="id" data-user-id>

                <div class="form-title nova-user-form__body" style="margin-bottom: 0;">
                    <h2 data-user-form-title>Crear usuario</h2>
                    <span class="nova-badge" data-user-mode>Nuevo</span>
                    <button class="modal-close" type="button" aria-label="Cerrar" data-user-close>&times;</button>
                </div>

                <div class="nova-user-form__body">
                    <div class="form-section-title">Identificacion</div>
                    <div class="form-section">
                        <div class="field">
                            <label for="rut">RUT</label>
                            <input class="form-control" id="rut" name="rut" placeholder="12.345.678-9" maxlength="12" data-user-rut>
                            <div class="field-help" data-user-rut-help>Ingrese un RUT valido.</div>
                        </div>
                        <div class="field">
                            <label for="redmine_id">Redmine ID</label>
                            <input class="form-control" id="redmine_id" name="redmine_id" type="number" readonly
                                placeholder="Se asigna al importar desde Redmine" aria-describedby="redmine-id-help" data-user-redmine-id>
                            <div class="field-help" id="redmine-id-help">Solo se actualiza mediante la importación desde Redmine TIC o Mantención.</div>
                        </div>
                        <div class="field">
                            <label for="username">Usuario acceso</label>
                            <input class="form-control" id="username" name="username" readonly data-user-username>
                        </div>
                    </div>

                    <div class="form-section is-two">
                        <div class="field">
                            <label for="name">Nombre</label>
                            <input class="form-control" id="name" name="name" required data-user-name>
                        </div>
                        <div class="field">
                            <label for="apellido">Apellidos</label>
                            <input class="form-control" id="apellido" name="apellido" required data-user-apellido>
                        </div>
                    </div>

                    <div class="form-section-title">Acceso</div>
                    <div class="form-section is-two">
                        <div class="field">
                            <label for="role">Permiso vista principal</label>
                            <select class="form-select" id="role" name="role" data-user-role>
                                <option value="usuario">Usuario</option>
                                <option value="admin">Admin</option>
                                @if ($currentNovaRole === 'root')
                                    <option value="root">Root</option>
                                @endif
                            </select>
                        </div>
                        <div class="field">
                            <label for="status">Estado</label>
                            <select class="form-select" id="status" name="status" data-user-status>
                                <option value="activo">activo</option>
                                <option value="baneado">baneado</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title" data-user-create-password>Clave inicial</div>
                    <div class="form-section" data-user-create-password>
                        <div class="field">
                            <label for="password">Contrasena</label>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password">
                        </div>
                        <div class="field">
                            <label for="password_confirmation">Validar contrasena</label>
                            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="Repetir contrasena">
                        </div>
                    </div>

                    <!-- <div class="form-section-title">Integraciones personales</div>
                    <div class="nova-alert-card is-info mb-0">
                        <i class="bi bi-person-lock"></i>
                        <span>Cada usuario debe ingresar sus propias credenciales desde el modulo correspondiente. Administracion solo ve si estan configuradas.</span>
                    </div> -->
                </div>
                <div class="nova-user-form__footer">
                    <button class="btn btn-outline-secondary" type="button" data-user-close>Cancelar</button>
                    <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i>Guardar</button>
                </div>
            </form>
            </div>

            <div class="nova-table-card">
                <div class="nova-table-toolbar">
                    <span class="nova-table-toolbar-title">Usuarios NOVA</span>
                    <div class="nova-table-search">
                        <i class="bi bi-search"></i>
                        <input type="search" placeholder="Buscar nombre, usuario o RUT" data-user-search>
                    </div>
                    <select class="form-select form-select-sm" style="width:auto;min-width:130px" data-role-filter aria-label="Filtrar rol NOVA">
                        <option value="">Rol: todos</option>
                        <option value="root">Root</option>
                        <option value="admin">Admin</option>
                        <option value="usuario">Usuario</option>
                    </select>
                    <select class="form-select form-select-sm" style="width:auto;min-width:130px" data-status-filter aria-label="Filtrar estado">
                        <option value="">Estado: todos</option>
                        <option value="activo">activo</option>
                        <option value="baneado">baneado</option>
                    </select>
                    <select class="form-select form-select-sm" style="width:auto;min-width:120px" data-user-page-size aria-label="Filas por pagina">
                        <option value="10">10 filas</option>
                        <option value="25">25 filas</option>
                        <option value="50">50 filas</option>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-user-filter-clear title="Limpiar filtros" aria-label="Limpiar filtros"><i class="bi bi-x-circle"></i></button>
                    <span class="ms-auto nova-user-meta"><span data-user-count>{{ count($users) }}</span> visible(s)</span>
                    <button class="btn-nova btn-nova-primary" type="button" data-user-new><i class="bi bi-plus-circle"></i>Nuevo</button>
                </div>
                <div class="table-responsive">
                    <table class="nova-user-table">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Nombre</th>
                                <th>RUT</th>
                                <th>Integraciones</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th class="nova-col-hide-md">Ultimo ingreso</th>
                                <th class="nova-col-actions">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($users as $user)
                            @php
                                $novaRole = match (strtolower(trim((string) ($user['role'] ?? 'usuario')))) {
                                    'root' => 'root',
                                    'admin', 'administrador' => 'admin',
                                    default => 'usuario',
                                };
                                $novaRoleLabel = match ($novaRole) {
                                    'root' => 'Root',
                                    'admin' => 'Admin',
                                    default => 'Usuario',
                                };
                                $novaRoleBadge = in_array($novaRole, ['root', 'admin'], true) ? 'is-admin' : 'is-usuario';
                                $userStatus = $user['status'] ?? 'activo';
                                $emachCredentials = is_array($user['emach_credentials'] ?? null) ? $user['emach_credentials'] : [];
                                $hasEmachCredentials = trim((string) ($emachCredentials['user'] ?? '')) !== '' && trim((string) ($emachCredentials['password'] ?? '')) !== '';
                                $nextcloudCredentials = is_array($user['nextcloud_credentials'] ?? null) ? $user['nextcloud_credentials'] : [];
                                $hasNextcloudCredentials = trim((string) ($nextcloudCredentials['user'] ?? '')) !== '' && trim((string) ($nextcloudCredentials['password'] ?? '')) !== '';
                                $telegramSettings = is_array($user['telegram_settings'] ?? null) ? $user['telegram_settings'] : [];
                                $telegramChatId = trim((string) ($user['telegram_id_chat'] ?? ($telegramSettings['chat_id'] ?? '')));
                                $hasTelegramSettings = preg_match('/^-?[1-9]\d{4,}$/', $telegramChatId) === 1;
                                $userInitials = strtoupper(mb_substr($user['name'] ?? 'U', 0, 1) . mb_substr($user['apellido'] ?? '', 0, 1));
                                $userRutDisplay = trim((string) ($user['rut'] ?? ''));
                                $userAccessDisplay = trim((string) ($user['username'] ?? ''));
                                $userRedmineDisplay = trim((string) ($user['redmine_id'] ?? ''));
                                $showRedmineBelow = $userRedmineDisplay !== '' && $userAccessDisplay !== $userRedmineDisplay;
                                $ultimoLogin = trim((string) ($user['ultimo_login_at'] ?? ''));
                            @endphp
                            <tr data-user-row
                                data-user-row-id="{{ $user['id'] ?? '' }}"
                                data-user-row-rut="{{ $user['rut'] ?? '' }}"
                                data-user-row-username="{{ $user['username'] ?? '' }}"
                                data-user-row-role="{{ $novaRole }}"
                                data-user-row-status="{{ $userStatus }}"
                                data-user-sort="{{ trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: ($user['username'] ?? '') }}"
                                data-search="{{ strtolower(($user['id'] ?? '') . ' ' . ($user['username'] ?? '') . ' ' . ($user['rut'] ?? '') . ' ' . ($user['rut_sin_dv'] ?? '') . ' ' . ($user['redmine_id'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) }}">
                                <td>
                                    <div class="nova-user-cell">
                                        <div class="nova-user-avatar">{{ $userInitials }}</div>
                                        <div>
                                            <div class="nova-user-name">{{ $userAccessDisplay !== '' ? $userAccessDisplay : '-' }}</div>
                                            @if ($showRedmineBelow)
                                                <div class="nova-user-meta">{{ $userRedmineDisplay }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="nova-user-name">{{ trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) }}</div>
                                </td>
                                <td><span class="nova-user-meta">{{ $userRutDisplay !== '' ? $userRutDisplay : '-' }}</span></td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <span class="integration-user-badge emach-credential-badge {{ $hasEmachCredentials ? '' : 'is-missing' }}" title="{{ $hasEmachCredentials ? 'EMACH configurado' : 'Sin credenciales EMACH' }}">
                                            <i class="bi {{ $hasEmachCredentials ? 'bi-key-fill' : 'bi-key' }}"></i>EMACH
                                        </span>
                                        <span class="integration-user-badge telegram-user-badge {{ $hasTelegramSettings ? '' : 'is-missing' }}" title="{{ $hasTelegramSettings ? 'Telegram vinculado' : 'Sin Telegram' }}">
                                            <i class="bi {{ $hasTelegramSettings ? 'bi-telegram' : 'bi-chat' }}"></i>TG
                                        </span>
                                        <span class="integration-user-badge nextcloud-user-badge {{ $hasNextcloudCredentials ? '' : 'is-missing' }}" title="{{ $hasNextcloudCredentials ? 'Nextcloud configurado' : 'Sin credenciales Nextcloud' }}">
                                            <i class="bi {{ $hasNextcloudCredentials ? 'bi-cloud-fill' : 'bi-cloud-slash' }}"></i>NC
                                        </span>
                                    </div>
                                </td>
                                <td><span class="nova-badge {{ $novaRoleBadge }}">{{ $novaRoleLabel }}</span></td>
                                <td><span class="nova-badge {{ $userStatus === 'baneado' ? 'is-baneado' : 'is-activo' }}" data-user-status-badge>{{ $userStatus }}</span></td>
                                <td class="nova-col-hide-md">
                                    @if ($ultimoLogin !== '')
                                        <span class="nova-date-meta">{{ \Carbon\Carbon::parse($ultimoLogin)->format('d/m/Y H:i') }}</span>
                                    @else
                                        <span class="nova-date-meta">-</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="nova-table-actions">
                                        @if ($novaRole !== 'root' || $currentNovaRole === 'root')
                                            <button class="btn-action btn-action-edit" type="button"
                                            data-user-edit
                                            data-id="{{ $user['id'] ?? '' }}"
                                            data-redmine-id="{{ $user['redmine_id'] ?? '' }}"
                                            data-username="{{ $user['username'] ?? '' }}"
                                            data-name="{{ $user['name'] ?? '' }}"
                                            data-apellido="{{ $user['apellido'] ?? '' }}"
                                            data-rut="{{ $user['rut'] ?? '' }}"
                                            data-role="{{ $novaRole }}"
                                            data-status="{{ $user['status'] ?? 'activo' }}"
                                            title="Editar usuario"
                                            aria-label="Editar usuario">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn-action btn-action-password" type="button"
                                            title="Cambiar contrasena"
                                            data-password-open
                                            data-id="{{ $user['id'] ?? '' }}"
                                            data-username="{{ $user['username'] ?? '' }}"
                                            data-display-name="{{ trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) }}"
                                            aria-label="Cambiar contrasena">
                                            <i class="bi bi-key"></i>
                                        </button>
                                        <form method="post" action="{{ route('administracion.users.update') }}" data-confirm-form data-user-status-toggle data-confirm-message="{{ $userStatus === 'baneado' ? 'Activar este usuario?' : 'Marcar usuario como baneado?' }}">
                                            @csrf
                                            <input type="hidden" name="action" value="{{ $userStatus === 'baneado' ? 'activate' : 'delete' }}">
                                            <input type="hidden" name="id" value="{{ $user['id'] ?? '' }}">
                                            @if ($userStatus === 'baneado')
                                                <button class="btn-action btn-action-activate" type="submit" title="Activar" aria-label="Activar usuario"><i class="bi bi-check-circle"></i></button>
                                            @else
                                                <button class="btn-action btn-action-ban" type="submit" title="Banear" aria-label="Banear usuario"><i class="bi bi-slash-circle"></i></button>
                                            @endif
                                            </form>
                                        @else
                                            <span class="nova-badge is-admin" title="Solo otro root puede modificar esta cuenta">
                                                <i class="bi bi-shield-lock"></i> Protegido
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="nova-table-empty">
                                    <i class="bi bi-people" style="font-size:1.4rem;display:block;margin-bottom:.4rem;opacity:.35"></i>No hay usuarios NOVA registrados.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="nova-user-pagination">
                    <span class="nova-user-meta" data-user-page-info>Pagina 1 de 1</span>
                    <div class="nova-user-page-actions">
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-user-page-prev><i class="bi bi-chevron-left"></i>Anterior</button>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-user-page-next>Siguiente<i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>
            </div>
                </div>
                @endif

                @if ($section === 'accesos')
                    @php
                        $accessModules = $accessMatrix['modules'] ?? [];
                        $accessRows = $accessMatrix['matrix'] ?? [];
                        $savedIdentity = (string) session('access_selected_identity', '');
                        $availableIdentities = collect($accessRows)->pluck('identity')->map(static fn ($identity) => (string) $identity);
                        $firstIdentity = $savedIdentity !== '' && $availableIdentities->contains($savedIdentity)
                            ? $savedIdentity
                            : (string) ($accessRows[0]['identity'] ?? '');
                        $accessSearchOptions = collect($accessRows)->map(function ($row) {
                            $user = $row['user'] ?? [];
                            $identity = (string) ($row['identity'] ?? '');
                            $displayName = trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: ($user['username'] ?? '');
                            $account = trim((string) ($user['username'] ?? $user['rut_sin_dv'] ?? ''));
                            $redmineId = trim((string) ($user['redmine_id'] ?? ''));
                            $visibleId = $redmineId !== '' ? $redmineId : ($account !== '' ? $account : $identity);

                            return [
                                'label' => 'ID ' . $visibleId . ' · ' . $displayName,
                                'value' => $identity,
                                'search' => implode(' ', array_filter([$displayName, $account, $redmineId])),
                            ];
                        })->values()->all();
                    @endphp
                    <form method="post" action="{{ route('administracion.access.update') }}">
                        @csrf
                        <input id="access-selected-identity" type="hidden" name="selected_identity" value="{{ $firstIdentity }}" data-access-selected-identity>
                        <section class="card nova-card rm-work-panel access-management-panel">
                            <div class="access-panel-head">
                                <div>
                                    <h2>Accesos a vistas NOVA</h2>
                                    <p class="access-help">Selecciona un usuario y marca las vistas que puede usar.</p>
                                </div>
                                <div class="access-tools">
                                    <div class="nova-search-select" data-search-select data-preserve-value-on-clear data-options="{{ json_encode($accessSearchOptions, JSON_UNESCAPED_UNICODE) }}" data-value-input="#access-selected-identity">
                                        <input class="form-control" type="search" placeholder="Buscar por nombre, cuenta o ID Redmine" data-search-select-input data-access-user-combobox aria-label="Seleccionar usuario" autocomplete="off">
                                        <button class="nova-search-select__clear" type="button" data-search-select-clear aria-label="Limpiar usuario" title="Limpiar usuario"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                        <div class="nova-search-select__menu" data-search-select-menu role="listbox" hidden></div>
                                    </div>
                                </div>
                                <button class="btn-nova btn-nova-primary" type="submit" data-access-save><i class="bi bi-save"></i>Guardar accesos</button>
                            </div>
                            <div class="access-list">
                                @forelse ($accessRows as $row)
                                    @php
                                        $user = $row['user'] ?? [];
                                        $identity = (string) ($row['identity'] ?? '');
                                        $displayName = trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: ($user['username'] ?? '');
                                        $account = trim((string) ($user['username'] ?? $user['rut_sin_dv'] ?? ''));
                                        $redmineId = trim((string) ($user['redmine_id'] ?? ''));
                                        $visibleId = $redmineId !== '' ? $redmineId : ($account !== '' ? $account : $identity);
                                        $accessSearchLabel = 'ID ' . $visibleId . ' · ' . $displayName;
                                        $selectedCount = collect($row['access'] ?? [])->filter(fn ($item) => $item['allowed'] ?? false)->count();
                                    @endphp
                                    <article class="access-user-panel {{ $identity === $firstIdentity ? 'is-active' : '' }}" data-access-user-panel="{{ $identity }}" data-access-user-label="{{ $accessSearchLabel }}">
                                        <div class="access-user-summary">
                                            <div>
                                                <h3>{{ $displayName }}</h3>
                                                <div class="access-view-meta">
                                                    {{ $user['username'] ?? '' }}
                                                    @if (!empty($user['rut']))
                                                        / {{ $user['rut'] }}
                                                    @endif
                                                    @if (!empty($user['redmine_id']))
                                                        / ID Redmine {{ $user['redmine_id'] }}
                                                    @endif
                                                </div>
                                            </div>
                                            <span class="nova-badge" data-user-access-count="{{ $identity }}">{{ $selectedCount }} acceso(s)</span>
                                        </div>
                                        <div class="access-module-grid">
                                            @forelse ($accessModules as $moduleKey => $module)
                                                @php
                                                    $cell = $row['access'][$moduleKey] ?? ['allowed' => false, 'source' => 'sin acceso'];
                                                    $source = (string) ($cell['source'] ?? 'sin acceso');
                                                    $sourceClass = $source === 'manual' ? 'is-manual' : (in_array($source, ['redmine'], true) ? 'is-default' : '');
                                                    $sourceLabel = ['redmine' => 'Redmine', 'manual' => 'Manual'][$source] ?? 'Sin base';
                                                @endphp
                                                <article class="access-view-card">
                                                    <div class="access-view-head">
                                                        <span>
                                                            <span class="access-view-title d-block">{{ $module['name'] ?? $moduleKey }}</span>
                                                            <span class="access-view-meta d-block">{{ $moduleKey }}</span>
                                                        </span>
                                                        <label class="access-user-option">
                                                            <input class="form-check-input" type="checkbox" name="access[{{ $identity }}][{{ $moduleKey }}]" value="1" data-access-user-checkbox="{{ $identity }}" @checked($cell['allowed'] ?? false)>
                                                            <span class="access-source {{ $sourceClass }}">{{ $sourceLabel }}</span>
                                                        </label>
                                                    </div>
                                                </article>
                                            @empty
                                                <div class="nova-muted fw-semibold">No hay vistas delegables configuradas.</div>
                                            @endforelse
                                        </div>
                                    </article>
                                @empty
                                    <div class="nova-muted fw-semibold">No hay usuarios para administrar accesos.</div>
                                @endforelse
                            </div>
                        </section>
                    </form>
                @endif
            </main><!-- /.nova-content -->
        </div><!-- /.nova-layout -->
    </div><!-- /.rm-shell -->
    @if (session('status'))
        <div data-nova-flash="success" data-nova-flash-message="{{ session('status') }}" hidden></div>
    @endif
    @if (session('error'))
        <div data-nova-flash="error" data-nova-flash-message="{{ session('error') }}" hidden></div>
    @endif
    @if ($errors->any())
        <div data-nova-flash="error" data-nova-flash-message="{{ $errors->first() }}" hidden></div>
    @endif
    <div class="nova-modal-backdrop" data-confirm-modal aria-hidden="true">
        <div class="nova-confirm" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
            <div class="nova-confirm__body">
                <h2 id="confirm-title">Confirmar accion</h2>
                <p data-confirm-text>Confirma la accion sobre este usuario.</p>
            </div>
            <div class="nova-confirm__actions">
                <button class="btn btn-outline-secondary" type="button" data-confirm-cancel>Cancelar</button>
                <button class="btn-nova btn-nova-primary" type="button" data-confirm-accept>Confirmar</button>
            </div>
        </div>
    </div>
    <div class="nova-modal-backdrop" data-password-modal aria-hidden="true">
        <form class="nova-confirm" method="post" action="{{ route('administracion.users.update') }}" role="dialog" aria-modal="true" aria-labelledby="password-title">
            @csrf
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="id" data-password-user-id>
            <div class="nova-confirm__body">
                <h2 id="password-title">Cambiar contrasena</h2>
                <p class="nova-muted" data-password-user-text>Selecciona un usuario.</p>
                <div class="field">
                    <label for="password-new">Nueva contrasena</label>
                    <input class="form-control" id="password-new" name="password" type="password" autocomplete="new-password" required data-password-new>
                </div>
                <div class="field">
                    <label for="password-new-confirm">Validar contrasena</label>
                    <input class="form-control" id="password-new-confirm" name="password_confirmation" type="password" autocomplete="new-password" required data-password-confirm>
                </div>
            </div>
            <div class="nova-confirm__actions">
                <button class="btn btn-outline-secondary" type="button" data-password-close>Cancelar</button>
                <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-key"></i>Actualizar</button>
            </div>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('assets/nova-ui.js') }}?v={{ @filemtime(public_path('assets/nova-ui.js')) ?: '1' }}"></script>
    <script>
        const form = document.querySelector('.form-panel');
        const formTitle = document.querySelector('[data-user-form-title]');
        const formMode = document.querySelector('[data-user-mode]');
        const userModal = document.querySelector('[data-user-modal]');
        const passwordModal = document.querySelector('[data-password-modal]');
        const passwordUserText = document.querySelector('[data-password-user-text]');
        const passwordUserId = document.querySelector('[data-password-user-id]');
        const passwordNew = document.querySelector('[data-password-new]');
        const passwordConfirm = document.querySelector('[data-password-confirm]');
        const setValue = (selector, value) => {
            const field = form?.querySelector(selector);
            if (field) field.value = value || '';
        };
        const setCreatePasswordVisible = (visible) => {
            form?.querySelectorAll('[data-user-create-password]').forEach((element) => {
                element.hidden = !visible;
            });
        };
        const openUserModal = () => {
            userModal?.classList.add('is-open');
            userModal?.setAttribute('aria-hidden', 'false');
            setTimeout(() => form?.querySelector('[data-user-rut]')?.focus(), 60);
        };
        const closeUserModal = () => {
            userModal?.classList.remove('is-open');
            userModal?.setAttribute('aria-hidden', 'true');
        };
        const resetUserForm = () => {
            form?.reset();
            setValue('[data-user-id]', '');
            setValue('[data-user-redmine-id]', '');
            setValue('[data-user-username]', '');
            setCreatePasswordVisible(true);
            rutField?.classList.remove('is-invalid');
            if (formTitle) formTitle.textContent = 'Crear usuario';
            if (formMode) formMode.textContent = 'Nuevo';
        };
        const rutAccessUser = (rut) => {
            const raw = String(rut || '').trim();
            const clean = raw.replace(/[^0-9kK]/g, '').toLowerCase();
            if (!clean) return '';
            return clean.slice(0, -1);
        };
        const formatRut = (rut) => {
            const clean = String(rut || '').replace(/[^0-9kK]/g, '').toUpperCase();
            if (clean.length <= 1) return clean;

            const number = clean.slice(0, -1);
            const dv = clean.slice(-1);
            const dotted = number.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            return `${dotted}-${dv}`;
        };
        const isValidRut = (rut) => {
            const clean = String(rut || '').replace(/[^0-9kK]/g, '').toLowerCase();
            if (!/^\d{7,8}[0-9k]$/.test(clean)) return false;

            const number = clean.slice(0, -1);
            const dv = clean.slice(-1);
            let factor = 2;
            let sum = 0;

            for (let i = number.length - 1; i >= 0; i -= 1) {
                sum += Number(number[i]) * factor;
                factor = factor === 7 ? 2 : factor + 1;
            }

            const rest = 11 - (sum % 11);
            const expected = rest === 11 ? '0' : rest === 10 ? 'k' : String(rest);
            return expected === dv;
        };
        const normalizeRut = (rut) => String(rut || '').replace(/[^0-9kK]/g, '').toLowerCase();
        const rutHelp = form?.querySelector('[data-user-rut-help]');
        const duplicateRutUser = () => {
            const currentId = form?.querySelector('[data-user-id]')?.value || '';
            const rut = normalizeRut(rutField?.value);
            const username = rutAccessUser(rutField?.value);
            if (!rut || !isValidRut(rutField?.value)) return null;

            return Array.from(document.querySelectorAll('[data-user-row]')).find((row) => {
                const rowId = row.dataset.userRowId || '';
                if (currentId !== '' && rowId === currentId) return false;

                return normalizeRut(row.dataset.userRowRut) === rut
                    || String(row.dataset.userRowUsername || '').toLowerCase() === username;
            }) || null;
        };
        const updateRutState = (showInvalid = true) => {
            if (!rutField) return;
            const hasValue = rutField.value.trim() !== '';
            const valid = isValidRut(rutField.value);
            const duplicate = valid ? duplicateRutUser() : null;
            const currentId = form?.querySelector('[data-user-id]')?.value || '';
            const usernameField = form?.querySelector('[data-user-username]');

            if (rutHelp) {
                rutHelp.textContent = duplicate ? 'Este RUT ya esta registrado.' : 'Ingrese un RUT valido.';
            }

            rutField.classList.toggle('is-invalid', showInvalid && hasValue && (!valid || duplicate !== null));
            if (valid) {
                setValue('[data-user-username]', rutAccessUser(rutField.value));
            } else if (currentId === '') {
                setValue('[data-user-username]', '');
            } else if (usernameField && usernameField.value === '') {
                setValue('[data-user-username]', form?.querySelector('[data-user-redmine-id]')?.value || '');
            }
        };
        const rutField = form?.querySelector('[data-user-rut]');
        rutField?.addEventListener('input', () => {
            const cursorAtEnd = rutField.selectionStart === rutField.value.length;
            rutField.value = formatRut(rutField.value);
            updateRutState(false);
            if (cursorAtEnd) {
                rutField.setSelectionRange(rutField.value.length, rutField.value.length);
            }
        });
        rutField?.addEventListener('blur', () => {
            rutField.value = formatRut(rutField.value);
            updateRutState(true);
        });
        form?.addEventListener('submit', (event) => {
            updateRutState(true);
            const currentId = form?.querySelector('[data-user-id]')?.value || '';
            const hasRut = (rutField?.value || '').trim() !== '';
            const mustValidateRut = currentId === '' || hasRut;
            if (rutField && mustValidateRut && (!isValidRut(rutField.value) || duplicateRutUser() !== null)) {
                event.preventDefault();
                rutField.focus();
            }
        });

        document.querySelectorAll('[data-user-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                setValue('[data-user-id]', button.dataset.id);
                setValue('[data-user-redmine-id]', button.dataset.redmineId);
                setValue('[data-user-username]', button.dataset.username);
                setValue('[data-user-name]', button.dataset.name);
                setValue('[data-user-apellido]', button.dataset.apellido);
                setValue('[data-user-rut]', button.dataset.rut);
                if (rutField) {
                    rutField.value = formatRut(rutField.value);
                }
                updateRutState(false);
                setValue('[data-user-role]', button.dataset.role);
                setValue('[data-user-status]', button.dataset.status);
                setValue('#password', '');
                setValue('#password_confirmation', '');
                setCreatePasswordVisible(false);
                if (formTitle) formTitle.textContent = 'Editar usuario';
                if (formMode) formMode.textContent = 'Editando';
                openUserModal();
            });
        });

        document.querySelectorAll('[data-password-open]').forEach((button) => {
            button.addEventListener('click', () => {
                if (passwordUserId) passwordUserId.value = button.dataset.id || '';
                if (passwordUserText) {
                    const label = button.dataset.displayName || button.dataset.username || 'Usuario seleccionado';
                    passwordUserText.textContent = `${label} / Usuario acceso ${button.dataset.username || '-'}`;
                }
                if (passwordNew) passwordNew.value = '';
                if (passwordConfirm) passwordConfirm.value = '';
                passwordModal?.classList.add('is-open');
                passwordModal?.setAttribute('aria-hidden', 'false');
                setTimeout(() => passwordNew?.focus(), 60);
            });
        });

        document.querySelectorAll('[data-password-close]').forEach((button) => {
            button.addEventListener('click', () => {
                passwordModal?.classList.remove('is-open');
                passwordModal?.setAttribute('aria-hidden', 'true');
            });
        });

        document.querySelector('[data-user-new]')?.addEventListener('click', () => {
            resetUserForm();
            openUserModal();
        });

        document.querySelectorAll('[data-user-close]').forEach((button) => {
            button.addEventListener('click', () => {
                closeUserModal();
            });
        });

        const normalizeSearch = (value) => String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^0-9a-z k]/g, ' ');
        const rows = Array.from(document.querySelectorAll('[data-user-row]'));
        const visibleCount = document.querySelector('[data-user-count]');
        const searchInput = document.querySelector('[data-user-search]');
        const roleFilter = document.querySelector('[data-role-filter]');
        const statusFilter = document.querySelector('[data-status-filter]');
        const pageSizeSelect = document.querySelector('[data-user-page-size]');
        const pageInfo = document.querySelector('[data-user-page-info]');
        const pagePrev = document.querySelector('[data-user-page-prev]');
        const pageNext = document.querySelector('[data-user-page-next]');
        const usersTableBody = document.querySelector('.nova-user-table tbody');
        let currentUserPage = 1;

        const statusSortRank = (row) => row.dataset.userRowStatus === 'baneado' ? 1 : 0;
        rows
            .sort((a, b) => {
                const statusDiff = statusSortRank(a) - statusSortRank(b);
                if (statusDiff !== 0) return statusDiff;
                return normalizeSearch(a.dataset.userSort || a.dataset.userRowUsername).localeCompare(normalizeSearch(b.dataset.userSort || b.dataset.userRowUsername), 'es');
            })
            .forEach((row) => usersTableBody?.appendChild(row));

        const applyUserFilters = () => {
            const query = normalizeSearch(searchInput?.value || '');
            const role = roleFilter?.value || '';
            const status = statusFilter?.value || '';
            const pageSize = Math.max(10, parseInt(pageSizeSelect?.value || '10', 10) || 10);
            const filteredRows = rows.filter((row) => {
                const haystack = normalizeSearch(row.dataset.search);
                const matchSearch = query === '' || haystack.includes(query);
                const matchRole = role === '' || row.dataset.userRowRole === role;
                const matchStatus = status === '' || row.dataset.userRowStatus === status;
                return matchSearch && matchRole && matchStatus;
            });
            const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
            currentUserPage = Math.min(Math.max(1, currentUserPage), totalPages);
            const start = (currentUserPage - 1) * pageSize;
            const end = start + pageSize;
            const pageRows = new Set(filteredRows.slice(start, end));

            rows.forEach((row) => {
                row.style.display = pageRows.has(row) ? '' : 'none';
            });

            if (visibleCount) visibleCount.textContent = String(filteredRows.length);
            if (pageInfo) {
                const first = filteredRows.length === 0 ? 0 : start + 1;
                const last = Math.min(end, filteredRows.length);
                pageInfo.textContent = `${first}-${last} de ${filteredRows.length} | Pagina ${currentUserPage} de ${totalPages}`;
            }
            if (pagePrev) pagePrev.disabled = currentUserPage <= 1;
            if (pageNext) pageNext.disabled = currentUserPage >= totalPages;
        };
        const resetUserPageAndApply = () => {
            currentUserPage = 1;
            applyUserFilters();
        };
        searchInput?.addEventListener('input', resetUserPageAndApply);
        roleFilter?.addEventListener('change', resetUserPageAndApply);
        statusFilter?.addEventListener('change', resetUserPageAndApply);
        pageSizeSelect?.addEventListener('change', resetUserPageAndApply);
        pagePrev?.addEventListener('click', () => {
            currentUserPage -= 1;
            applyUserFilters();
        });
        pageNext?.addEventListener('click', () => {
            currentUserPage += 1;
            applyUserFilters();
        });
        document.querySelector('[data-user-filter-clear]')?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            if (roleFilter) roleFilter.value = '';
            if (statusFilter) statusFilter.value = '';
            resetUserPageAndApply();
        });
        applyUserFilters();

        const accessUserCombobox = document.querySelector('[data-access-user-combobox]');
        const accessIdentityField = document.querySelector('[data-access-selected-identity]');
        const accessSaveButton = document.querySelector('[data-access-save]');
        const accessPanels = Array.from(document.querySelectorAll('[data-access-user-panel]'));
        const accessLabelByIdentity = new Map(accessPanels.map((panel) => [panel.dataset.accessUserPanel, panel.dataset.accessUserLabel || '']));
        let activeAccessIdentity = String(accessIdentityField?.value || accessPanels[0]?.dataset.accessUserPanel || '').trim();
        const setActiveAccessUser = (identity) => {
            if (!accessLabelByIdentity.has(identity)) return;
            activeAccessIdentity = identity;
            if (accessIdentityField) accessIdentityField.value = identity;
            if (accessUserCombobox) accessUserCombobox.value = '';
            if (accessSaveButton) accessSaveButton.disabled = false;

            accessPanels.forEach((panel) => {
                const active = panel.dataset.accessUserPanel === identity;
                panel.classList.toggle('is-active', active);
                panel.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                    input.disabled = !active;
                });
            });
        };
        const preserveActiveAccessUser = () => {
            if (accessIdentityField) accessIdentityField.value = activeAccessIdentity;
            if (accessSaveButton) accessSaveButton.disabled = !activeAccessIdentity;
        };
        const identityFromCombobox = () => {
            return String(accessIdentityField?.value || '').trim();
        };
        const updateUserAccessCount = (identity) => {
            const counter = document.querySelector(`[data-user-access-count="${identity}"]`);
            if (!counter) return;

            const count = Array.from(document.querySelectorAll(`[data-access-user-checkbox="${identity}"]`)).filter((input) => input.checked).length;
            counter.textContent = `${count} acceso(s)`;
        };
        accessUserCombobox?.addEventListener('input', () => {
            const identity = identityFromCombobox();
            if (identity !== '') setActiveAccessUser(identity);
        });
        accessUserCombobox?.addEventListener('change', () => {
            const identity = identityFromCombobox();
            if (identity !== '') setActiveAccessUser(identity);
        });
        accessUserCombobox?.addEventListener('nova:search-select-clear', preserveActiveAccessUser);
        document.querySelectorAll('[data-access-user-checkbox]').forEach((checkbox) => {
            checkbox.addEventListener('change', () => updateUserAccessCount(checkbox.dataset.accessUserCheckbox));
        });
        setActiveAccessUser(activeAccessIdentity);

        const telegramMessageOptions = Array.from(document.querySelectorAll('[data-telegram-message-option]'));
        const telegramMessagePanels = Array.from(document.querySelectorAll('[data-telegram-message-panel]'));
        const setActiveTelegramMessage = (key) => {
            telegramMessageOptions.forEach((option) => {
                option.classList.toggle('is-active', option.dataset.telegramMessageOption === key);
            });
            telegramMessagePanels.forEach((panel) => {
                panel.classList.toggle('is-active', panel.dataset.telegramMessagePanel === key);
            });
        };
        telegramMessageOptions.forEach((option) => {
            option.addEventListener('click', () => {
                setActiveTelegramMessage(option.dataset.telegramMessageOption || '');
            });
        });

        document.querySelectorAll('[data-telegram-emach-variant-option]').forEach((option) => {
            option.addEventListener('click', () => {
                const editor = option.closest('[data-telegram-message-panel]');
                const variant = option.dataset.telegramEmachVariantOption || '';
                editor?.querySelectorAll('[data-telegram-emach-variant-option]').forEach((candidate) => {
                    const active = candidate.dataset.telegramEmachVariantOption === variant;
                    candidate.classList.toggle('is-active', active);
                    candidate.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                editor?.querySelectorAll('[data-telegram-emach-variant]').forEach((panel) => {
                    panel.classList.toggle('is-active', panel.dataset.telegramEmachVariant === variant);
                });
            });
        });

        document.querySelector('.nova-sidebar .nova-sidebar-link.active')?.scrollIntoView({ block: 'nearest' });

        const applyUserStatusState = (form, status) => {
            const row = form.closest('[data-user-row]');
            const badge = row?.querySelector('[data-user-status-badge]');
            const button = form.querySelector('button[type="submit"]');
            const icon = button?.querySelector('i.bi');
            const action = form.querySelector('input[name="action"]');
            const banned = status === 'baneado';

            if (row) row.dataset.userRowStatus = status;
            if (badge) {
                badge.textContent = status;
                badge.classList.toggle('is-baneado', banned);
                badge.classList.toggle('is-activo', !banned);
            }
            if (action) action.value = banned ? 'activate' : 'delete';
            if (button) {
                button.classList.toggle('is-activate', banned);
                button.classList.toggle('is-ban', !banned);
                button.title = banned ? 'Activar' : 'Banear';
                button.setAttribute('aria-label', button.title);
            }
            if (icon) icon.className = `bi ${banned ? 'bi-check-circle' : 'bi-slash-circle'}`;
            form.dataset.confirmMessage = banned ? 'Activar este usuario?' : 'Marcar usuario como baneado?';
        };

        const submitUserStatusAction = async (form) => {
            const row = form.closest('[data-user-row]');
            const button = form.querySelector('button[type="submit"]');
            const previousStatus = row?.dataset.userRowStatus === 'baneado' ? 'baneado' : 'activo';
            const nextStatus = previousStatus === 'baneado' ? 'activo' : 'baneado';
            const data = new FormData(form);

            applyUserStatusState(form, nextStatus);
            if (button) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.classList.add('is-submitting');
            }

            try {
                const response = await fetch(form.getAttribute('action') || window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: data,
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || payload.ok === false) {
                    throw new Error(payload.message || 'No se pudo actualizar el usuario.');
                }
                window.NovaToast?.success(payload.message || 'Estado del usuario actualizado.');
                applyUserFilters();
            } catch (error) {
                applyUserStatusState(form, previousStatus);
                window.NovaToast?.error(error.message || 'No se pudo actualizar el usuario.');
            } finally {
                if (button) {
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                    button.classList.remove('is-submitting');
                }
            }
        };


        const confirmModal = document.querySelector('[data-confirm-modal]');
        const confirmText = document.querySelector('[data-confirm-text]');
        const confirmAccept = document.querySelector('[data-confirm-accept]');
        const confirmCancel = document.querySelector('[data-confirm-cancel]');
        let pendingForm = null;

        document.querySelectorAll('[data-confirm-form]').forEach((actionForm) => {
            actionForm.addEventListener('submit', (event) => {
                event.preventDefault();
                pendingForm = actionForm;
                if (confirmText) {
                    confirmText.textContent = actionForm.dataset.confirmMessage || 'Confirma la accion sobre este usuario.';
                }
                confirmModal?.classList.add('is-open');
                confirmModal?.setAttribute('aria-hidden', 'false');
            });
        });

        confirmCancel?.addEventListener('click', () => {
            pendingForm = null;
            confirmModal?.classList.remove('is-open');
            confirmModal?.setAttribute('aria-hidden', 'true');
        });

        confirmAccept?.addEventListener('click', () => {
            const submitForm = pendingForm;
            pendingForm = null;
            confirmModal?.classList.remove('is-open');
            confirmModal?.setAttribute('aria-hidden', 'true');
            if (submitForm?.matches('[data-user-status-toggle]')) {
                void submitUserStatusAction(submitForm);
            } else {
                submitForm?.submit();
            }
        });
    </script>
</body>
</html>
