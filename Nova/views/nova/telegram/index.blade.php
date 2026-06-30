<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Telegram | NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
</head>
<body>
<main class="telegram-page">
    <header class="telegram-topbar">
        <div class="telegram-brand">
            <span class="telegram-brand-mark"><i class="bi bi-telegram fs-4"></i></span>
            <div>
                <h1>Telegram</h1>
                <span>{{ $mode === 'admin' ? 'Administracion global' : 'Configuracion personal' }}</span>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @include('nova.partials.session-control')
            @if ($mode === 'user' && in_array((string) session('nova_user.role', 'usuario'), config('nova.module_admin_roles', []), true))
                <a class="btn btn-outline-light" href="{{ route('telegram.admin') }}"><i class="bi bi-sliders"></i> Admin</a>
            @elseif ($mode === 'admin')
                <a class="btn btn-outline-light" href="{{ route('telegram.index') }}"><i class="bi bi-person"></i> Mi Telegram</a>
            @endif
            <a class="btn btn-outline-light" href="{{ route('home') }}"><i class="bi bi-house-door"></i> NOVA</a>
            <form method="POST" action="{{ route('logout') }}" style="display:inline">
                @csrf
                <button class="btn btn-outline-light" type="submit"><i class="bi bi-box-arrow-right"></i> Salir</button>
            </form>
        </div>
    </header>

    <section class="card telegram-hero nova-system-hero mb-4">
        <div class="card-body p-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div>
                <h2 class="h3 fw-black mb-1">{{ $mode === 'admin' ? 'Telegram Admin' : 'Mi Telegram' }}</h2>
            </div>
            <span class="telegram-status-pill">
                <i class="bi {{ ($mode === 'admin' ? $configured : ($configured && ($userTelegram['stored'] ?? false))) ? 'bi-check-circle' : 'bi-exclamation-triangle' }}"></i>
                {{ $mode === 'admin'
                    ? ($configured ? 'Bot configurado' : 'Bot pendiente')
                    : (($configured && ($userTelegram['stored'] ?? false)) ? 'Listo' : 'Pendiente') }}
            </span>
        </div>
    </section>

    @if (session('telegram_status'))
        <div data-nova-flash="telegram" data-nova-flash-message="{{ session('telegram_status') }}" hidden></div>
    @endif
    @if (session('telegram_error'))
        <div data-nova-flash="warning" data-nova-flash-message="{{ session('telegram_error') }}" hidden></div>
    @endif
    @if (session('telegram_status'))
        <div class="alert alert-success d-flex align-items-center gap-2" role="status">
            <i class="bi bi-check-circle-fill"></i>
            <span>{{ session('telegram_status') }}</span>
        </div>
    @endif
    @if (session('telegram_error'))
        <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>{{ session('telegram_error') }}</span>
        </div>
    @endif

    <section class="card telegram-card mb-3">
        <div class="card-body p-4">
            <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                <div>
                    <h2 class="h5 fw-black mb-1">Comandos Telegram</h2>
                </div>
                <span class="badge text-bg-primary rounded-pill">{{ count($telegramCommands ?? []) }} comando(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle telegram-command-table mb-0">
                    <thead>
                        <tr>
                            <th>Comando</th>
                            <th>Modulo</th>
                            <th>Entrada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($telegramCommands ?? []) as $command)
                            <tr>
                                <td>
                                    <div class="telegram-command">{{ $command['command'] ?? '' }}</div>
                                    @if (!empty($command['aliases']))
                                        <div class="telegram-aliases mt-1">
                                            @foreach ($command['aliases'] as $alias)
                                                <span>{{ $alias }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td><strong>{{ $command['module'] ?? '-' }}</strong></td>
                                <td>{{ $command['input'] ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3">No hay comandos configurados.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="row g-3">
        <div class="col-12 col-xl-7">
            <article class="card telegram-card h-100">
                <div class="card-body p-4">
                    <h2 class="h5 fw-black mb-1">{{ $mode === 'admin' ? 'Configuracion global' : 'Mis datos Telegram' }}</h2>
                    <form class="row g-3" method="post" action="{{ $mode === 'admin' ? route('telegram.admin.update') : route('telegram.update') }}">
                        @csrf
                        @if ($mode === 'admin')
                        <div class="col-12">
                            <label class="form-label fw-bold" for="bot-token">TELEGRAM_BOT_TOKEN</label>
                            <input class="form-control" id="bot-token" name="bot_token" type="password" autocomplete="off" placeholder="{{ $configured ? 'Dejar en blanco para conservar' : 'Token de BotFather' }}">
                        </div>
                        @else
                        <div class="col-12">
                            <label class="form-label fw-bold" for="chat-id">TELEGRAM_CHAT_ID</label>
                            <input class="form-control" id="chat-id" name="chat_id" value="{{ old('chat_id', $userTelegram['chat_id'] ?? '') }}" placeholder="7449883192">
                        </div>
                        @endif
                        @if ($mode === 'admin')
                        <div class="col-12">
                            <label class="form-label fw-bold" for="proxy-url">TELEGRAM_PROXY_URL</label>
                            <input class="form-control" id="proxy-url" name="proxy_url" value="{{ old('proxy_url', $config['proxy_url'] ?? '') }}" placeholder="Opcional, ejemplo: http://proxy:8080">
                        </div>
                        @endif
                        <div class="col-12">
                            <button class="btn-nova btn-nova-primary telegram-submit" type="submit"><i class="bi bi-save"></i> Guardar</button>
                        </div>
                    </form>
                </div>
            </article>
        </div>

        <div class="col-12 col-xl-5">
            <article class="card telegram-card h-100">
                <div class="card-body p-4">
                    <h2 class="h5 fw-black mb-3">{{ $mode === 'admin' ? 'Estado de usuarios' : 'Pruebas y uso' }}</h2>
                    @if ($mode === 'user')
                    <form method="post" action="{{ route('telegram.test') }}" class="mb-3">
                        @csrf
                        <button class="btn-nova btn-nova-info w-100" type="submit" @disabled(!$configured || !($userTelegram['stored'] ?? false))><i class="bi bi-send"></i> Enviar mensaje de prueba</button>
                    </form>
                    @if (!$configured)
                        <div class="nova-alert-card is-warning">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            Falta configurar TELEGRAM_BOT_TOKEN en administracion.
                        </div>
                    @elseif (!($userTelegram['stored'] ?? false))
                        <div class="nova-alert-card is-warning">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            Guarda tu TELEGRAM_CHAT_ID para habilitar la prueba.
                        </div>
                    @endif
                    @else
                        @php
                            $webhookActive = (bool) data_get($listener, 'webhook.active', false);
                            $webhookAvailable = (bool) data_get($listener, 'webhook.available', false);
                            $pendingUpdates = data_get($listener, 'webhook.pending');
                            $webhookError = (string) data_get($listener, 'webhook.error', '');
                        @endphp
                        <div class="telegram-listener-grid mb-3">
                            <div class="telegram-listener-metric is-ok">
                                <i class="bi bi-box-seam"></i>
                                <div>
                                    <span>Listener</span>
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
                                    <span>Cola</span>
                                    <strong>{{ $pendingUpdates === null ? '-' : $pendingUpdates }}</strong>
                                </div>
                            </div>
                        </div>
                        <div class="telegram-listener-actions mb-3">
                            <form method="post" action="{{ route('telegram.admin.listener') }}">
                                @csrf
                                <input type="hidden" name="action" value="delete_webhook">
                                <button class="btn-nova btn-nova-warning fw-bold" type="submit" @disabled(!$webhookActive || !$configured)><i class="bi bi-unlink"></i> Quitar webhook</button>
                            </form>
                            <a class="btn btn-outline-secondary fw-bold" href="{{ route('telegram.admin') }}"><i class="bi bi-arrow-clockwise"></i> Refrescar</a>
                        </div>
                        @if ($webhookError !== '')
                            <div class="nova-alert-card is-warning"><i class="bi bi-exclamation-triangle-fill"></i> {{ $webhookError }}</div>
                        @endif
                        <div class="table-responsive mb-3">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Usuario</th>
                                        <th>Telegram</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($users as $user)
                                        @php
                                            $settings = is_array($user['telegram_settings'] ?? null) ? $user['telegram_settings'] : [];
                                            $hasChat = trim((string) ($settings['chat_id'] ?? '')) !== '';
                                        @endphp
                                        <tr>
                                            <td>
                                                <strong>{{ trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: ($user['username'] ?? '') }}</strong>
                                                <div class="text-muted small">{{ $user['username'] ?? '' }}</div>
                                            </td>
                                            <td><span class="badge {{ $hasChat ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $hasChat ? 'Chat ID guardado' : 'Pendiente' }}</span></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="2">No hay usuarios NOVA.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </article>
        </div>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('assets/nova-ui.js') }}"></script>
</body>
</html>
