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
    @php
        $novaUiVersion = @filemtime(public_path('assets/nova-ui.css')) ?: '1';
    @endphp
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ $novaUiVersion }}" rel="stylesheet">
</head>
<body>
<main class="telegram-page">
    @php
        $telegramActiveNav = 'inicio';
    @endphp
    @include('nova.telegram.navigation')
    <div class="nova-content telegram-content">

    <section class="card telegram-hero nova-system-hero mb-4">
        <div class="card-body p-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div>
                <h2 class="h3 fw-black mb-1">{{ $mode === 'admin' ? 'Telegram Admin' : 'Mi Telegram' }}</h2>
            </div>
            <div class="telegram-hero-actions">
                @if ($mode === 'user')
                    <button class="btn btn-outline-light telegram-help-trigger" type="button" data-bs-toggle="modal" data-bs-target="#telegramChatIdHelp">
                        <i class="bi bi-question-circle"></i>
                        Cómo obtener mi Chat ID
                    </button>
                @endif
                <span class="telegram-status-pill">
                    <i class="bi {{ ($mode === 'admin' ? $configured : ($configured && ($userTelegram['stored'] ?? false))) ? 'bi-check-circle' : 'bi-exclamation-triangle' }}"></i>
                    {{ $mode === 'admin'
                        ? ($configured ? 'Bot configurado' : 'Bot pendiente')
                        : (($configured && ($userTelegram['stored'] ?? false)) ? 'Listo' : 'Pendiente') }}
                </span>
            </div>
        </div>
    </section>

    @if (session('telegram_status'))
        <div data-nova-flash="telegram" data-nova-flash-message="{{ session('telegram_status') }}" hidden></div>
    @endif
    @if (session('telegram_error'))
        <div data-nova-flash="warning" data-nova-flash-message="{{ session('telegram_error') }}" hidden></div>
    @endif
    @if (session('telegram_status'))
        <div class="nova-alert-card is-success mb-3" role="status">
            <i class="bi bi-check-circle-fill"></i>
            <span>{{ session('telegram_status') }}</span>
        </div>
    @endif
    @if (session('telegram_error'))
        <div class="nova-alert-card is-warning mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>{{ session('telegram_error') }}</span>
        </div>
    @endif

    <section class="row g-3 telegram-config-grid">
        <div class="col-12 col-xl-7">
            <article class="card telegram-card h-100">
                <div class="card-body p-4">
                    <div class="telegram-panel-head">
                        <span><i class="bi {{ $mode === 'admin' ? 'bi-shield-lock' : 'bi-person-badge' }}"></i></span>
                        <div>
                            <small>{{ $mode === 'admin' ? 'Bot NOVA' : 'Cuenta personal' }}</small>
                            <h2>{{ $mode === 'admin' ? 'Configuracion global' : 'Mis datos Telegram' }}</h2>
                        </div>
                    </div>
                    @if ($mode === 'user')
                        <div class="telegram-chat-summary">
                            <div class="telegram-chat-summary-icon">
                                <i class="bi {{ ($userTelegram['stored'] ?? false) ? 'bi-check2-circle' : 'bi-exclamation-circle' }}"></i>
                            </div>
                            <div>
                                <span>TELEGRAM_CHAT_ID</span>
                                <strong>{{ ($userTelegram['stored'] ?? false) ? 'Configurado' : 'Pendiente' }}</strong>
                                <p>{{ ($userTelegram['stored'] ?? false) ? 'Tu Chat ID está guardado en tus integraciones personales.' : 'Configura tu Chat ID desde Mis integraciones para recibir mensajes.' }}</p>
                            </div>
                        </div>
                    @else
                    <form class="row g-3" method="post" action="{{ route('telegram.admin.update') }}">
                        @csrf
                        <div class="col-12">
                            <label class="form-label fw-bold" for="bot-token">TELEGRAM_BOT_TOKEN</label>
                            <input class="form-control" id="bot-token" name="bot_token" type="password" autocomplete="off" placeholder="{{ $configured ? 'Dejar en blanco para conservar' : 'Token de BotFather' }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold" for="proxy-url">TELEGRAM_PROXY_URL</label>
                            <input class="form-control" id="proxy-url" name="proxy_url" value="{{ old('proxy_url', $config['proxy_url'] ?? '') }}" placeholder="Opcional, ejemplo: http://proxy:8080">
                        </div>
                        <div class="col-12">
                            <button class="btn-nova btn-nova-primary telegram-submit" type="submit"><i class="bi bi-save"></i> Guardar</button>
                        </div>
                    </form>
                    @endif
                </div>
            </article>
        </div>

        <div class="col-12 col-xl-5">
            <article class="card telegram-card h-100">
                <div class="card-body p-4">
                    <div class="telegram-panel-head">
                        <span><i class="bi {{ $mode === 'admin' ? 'bi-activity' : 'bi-send' }}"></i></span>
                        <div>
                            <small>{{ $mode === 'admin' ? 'Monitoreo' : 'Validacion' }}</small>
                            <h2>{{ $mode === 'admin' ? 'Estado de usuarios' : 'Pruebas y uso' }}</h2>
                        </div>
                    </div>
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
                            Configura tu TELEGRAM_CHAT_ID en Mis integraciones para habilitar la prueba.
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

    <section class="card telegram-card mt-3">
        <div class="card-body p-4">
            <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
                <div class="telegram-panel-head mb-0">
                    <span><i class="bi bi-terminal"></i></span>
                    <div>
                        <small>Referencia</small>
                        <h2>Comandos Telegram</h2>
                    </div>
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

    </div>
    </div>
</main>

@if ($mode === 'user')
<div class="modal fade telegram-help-modal" id="telegramChatIdHelp" tabindex="-1" aria-labelledby="telegramChatIdHelpTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div class="telegram-help-title">
                    <span><i class="bi bi-telegram"></i></span>
                    <div>
                        <small>Ayuda Telegram</small>
                        <h2 class="modal-title" id="telegramChatIdHelpTitle">Cómo obtener tu Chat ID</h2>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="telegram-help-intro">Tu Chat ID es el número que permite a NOVA enviarte mensajes de forma personal. Obtenlo con el bot NOVA siguiendo estos pasos:</p>
                <ol class="telegram-help-steps">
                    <li>
                        <span class="telegram-help-step-number">1</span>
                        <div>
                            <strong>Abre el bot NOVA</strong>
                            <p>En Telegram, busca y abre el bot que te indicó el administrador de NOVA.</p>
                        </div>
                    </li>
                    <li>
                        <span class="telegram-help-step-number">2</span>
                        <div>
                            <strong>Inicia la conversación</strong>
                            <p>Presiona <b>Iniciar</b> o envía el comando <code>/start</code>.</p>
                        </div>
                    </li>
                    <li>
                        <span class="telegram-help-step-number">3</span>
                        <div>
                            <strong>Solicita tu identificador</strong>
                            <p>Envía <code>/id</code>. El bot responderá con tu Chat ID.</p>
                        </div>
                    </li>
                    <li>
                        <span class="telegram-help-step-number">4</span>
                        <div>
                            <strong>Copia el número</strong>
                            <p>Copia solo los dígitos del mensaje, sin espacios ni texto adicional.</p>
                        </div>
                    </li>
                    <li>
                        <span class="telegram-help-step-number">5</span>
                        <div>
                            <strong>Guárdalo en NOVA</strong>
                            <p>Abre <b>Mis integraciones</b>, selecciona Telegram, pega el número en <b>Chat ID</b> y guarda los cambios.</p>
                        </div>
                    </li>
                </ol>
                <div class="telegram-help-finish">
                    <i class="bi bi-send-check"></i>
                    <p><strong>Último paso:</strong> vuelve a esta pantalla y usa “Enviar mensaje de prueba” para confirmar que quedó conectado.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                <a class="btn-nova btn-nova-primary" href="{{ route('integrations.nova') }}#integration-telegram">
                    <i class="bi bi-person-lock"></i>
                    Ir a Mis integraciones
                </a>
            </div>
        </div>
    </div>
</div>
@endif

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('assets/nova-ui.js') }}"></script>
</body>
</html>
