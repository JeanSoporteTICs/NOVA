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
    <style>
        body { min-height: 100vh; margin: 0; background: #eef3fb; }
        .telegram-page { padding: 0 24px 44px; }
        .telegram-topbar {
            display: flex; align-items: center; justify-content: space-between; gap: 18px;
            min-height: 72px; margin: 0 -24px 24px; padding: 12px 24px;
            background: linear-gradient(115deg, #102033 0%, #229ed9 64%, #14b8a6 100%);
            box-shadow: 0 16px 36px rgba(31, 47, 86, .22);
        }
        .telegram-brand { display: flex; align-items: center; gap: 14px; color: #fff; }
        .telegram-brand-mark {
            display: grid; width: 48px; height: 48px; place-items: center; border-radius: 12px;
            background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.24);
        }
        .telegram-brand h1 { margin: 0; font-size: 1.45rem; font-weight: 900; }
        .telegram-brand span { color: rgba(255,255,255,.7); font-weight: 700; }
        .telegram-hero {
            border: 0; border-radius: 12px; color: #fff;
            background: linear-gradient(135deg, #12324f, #229ed9);
            box-shadow: 0 24px 58px rgba(15, 23, 42, .16);
        }
        .telegram-card {
            border: 1px solid #d7e2ef; border-radius: 12px;
            background: rgba(255,255,255,.96); box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
        }
        .telegram-card .form-control, .telegram-card .form-select {
            min-height: 46px; border-color: #c9d7e8; border-radius: 10px; font-weight: 750;
        }
        .telegram-submit {
            min-height: 42px; border: 0; border-radius: 10px; font-weight: 900;
            background: linear-gradient(135deg, #229ed9, #14b8a6);
        }
        .telegram-status-pill {
            display: inline-flex; align-items: center; gap: .45rem; min-height: 34px;
            padding: .4rem .8rem; border-radius: 999px; border: 1px solid rgba(255,255,255,.35);
            background: rgba(255,255,255,.16); color: #fff; font-weight: 900;
        }
        .telegram-listener-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
        .telegram-listener-metric {
            display: flex; align-items: center; gap: .7rem; min-height: 76px;
            padding: .9rem; border: 1px solid #d7e2ef; border-radius: 10px; background: #f8fafc;
        }
        .telegram-listener-metric i {
            display: grid; width: 38px; height: 38px; place-items: center; border-radius: 10px;
            background: #e0f2fe; color: #0369a1;
        }
        .telegram-listener-metric strong { display: block; color: #111827; font-size: 1rem; line-height: 1.1; }
        .telegram-listener-metric span { color: #64748b; font-size: .78rem; font-weight: 900; text-transform: uppercase; }
        .telegram-listener-metric.is-ok i { background: #dcfce7; color: #15803d; }
        .telegram-listener-metric.is-warn i { background: #fef3c7; color: #b45309; }
        .telegram-listener-metric.is-bad i { background: #fee2e2; color: #dc2626; }
        .telegram-listener-actions { display: flex; flex-wrap: wrap; gap: .55rem; }
        .telegram-log-tail {
            min-height: 104px; max-height: 180px; overflow: auto;
            padding: .85rem; border-radius: 10px; background: #020617; color: #86efac;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .82rem;
            white-space: pre-wrap;
        }
        .telegram-command-table thead th { background: #eaf8fd; color: #334155; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }
        .telegram-command { color: #0f172a; font-weight: 950; white-space: nowrap; }
        .telegram-aliases { display: flex; gap: .35rem; flex-wrap: wrap; }
        .telegram-aliases span { display: inline-flex; min-height: 22px; align-items: center; padding: .1rem .45rem; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: .72rem; font-weight: 900; }
        @media (max-width: 720px) {
            .telegram-listener-grid { grid-template-columns: 1fr; }
        }
    </style>
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
            <a class="btn btn-outline-light" href="{{ route('logout') }}"><i class="bi bi-box-arrow-right"></i> Salir</a>
        </div>
    </header>

    <section class="card telegram-hero mb-4">
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
        <div class="alert alert-success fw-semibold"><i class="bi bi-check-circle"></i> {{ session('telegram_status') }}</div>
    @endif
    @if (session('telegram_error'))
        <div class="alert alert-warning fw-semibold"><i class="bi bi-exclamation-triangle"></i> {{ session('telegram_error') }}</div>
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
                            <button class="btn btn-primary telegram-submit" type="submit"><i class="bi bi-save"></i> Guardar</button>
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
                        <button class="btn btn-outline-primary w-100" type="submit" @disabled(!$configured || !($userTelegram['stored'] ?? false))><i class="bi bi-send"></i> Enviar mensaje de prueba</button>
                    </form>
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
                                <button class="btn btn-outline-warning fw-bold" type="submit" @disabled(!$webhookActive || !$configured)><i class="bi bi-unlink"></i> Quitar webhook</button>
                            </form>
                            <a class="btn btn-outline-secondary fw-bold" href="{{ route('telegram.admin') }}"><i class="bi bi-arrow-clockwise"></i> Refrescar</a>
                        </div>
                        @if ($webhookError !== '')
                            <div class="alert alert-warning fw-semibold"><i class="bi bi-exclamation-triangle"></i> {{ $webhookError }}</div>
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
</body>
</html>
