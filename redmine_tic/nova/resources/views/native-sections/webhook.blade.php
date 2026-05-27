@php
    $webhookResult = session('redmine_webhook_result');
    $currentUserId = (string) data_get(session('redmine_project_user', []), 'id', session('nova_user.id', ''));
    $loggedNumber = '';
    foreach (($users ?? []) as $user) {
        if ((string) ($user['id'] ?? '') === $currentUserId) {
            $loggedNumber = (string) ($user['numero_celular'] ?? '');
            break;
        }
    }
@endphp

<section class="row g-3 align-items-start rm-webhook-view">
    <div class="col-12 col-xxl-9">
        <article class="card nova-card rm-panel h-100 rm-webhook-panel">
            <div class="rm-section-head">
                <div>
                    <h2>Enviar mensaje al webhook</h2>
                    <p>Envía el texto al servicio Python para que interprete problema, ubicación y solicitante.</p>
                </div>
                <span class="nova-badge"><i class="bi bi-broadcast"></i> Python</span>
            </div>

            <form method="post" action="{{ $redmineRoute('redmine.native.webhook.action') }}">
                @csrf
                <div class="row g-3">
                    <div class="col-12 col-xl-5">
                        <label class="form-label" for="webhook-url">URL del webhook</label>
                        <input class="form-control" id="webhook-url" name="webhook_url" value="{{ old('webhook_url', $webhookUrl ?? 'http://localhost:8000/webhook') }}" placeholder="http://localhost:8000/webhook" required>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <label class="form-label" for="webhook-numero">Numero remitente</label>
                        <input class="form-control" id="webhook-numero" name="numero" value="{{ old('numero', $loggedNumber) }}" placeholder="+569..." required>
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="form-label" for="webhook-usuario">Usuario</label>
                        <select class="form-select" id="webhook-usuario">
                            <option value="">Seleccionar</option>
                            @foreach (($users ?? []) as $user)
                                @php
                                    $name = trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? ''));
                                    $phone = (string) ($user['numero_celular'] ?? '');
                                @endphp
                                <option value="{{ $phone }}">{{ $name ?: ($user['id'] ?? 'Usuario') }}{{ $phone ? ' - ' . $phone : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="webhook-fecha">Fecha</label>
                        <input class="form-control" id="webhook-fecha" type="date" name="fecha" value="{{ old('fecha', now('America/Santiago')->format('Y-m-d')) }}">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="webhook-hora">Hora</label>
                        <input class="form-control" id="webhook-hora" type="time" name="hora" value="{{ old('hora', now('America/Santiago')->format('H:i')) }}">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="webhook-mensaje">Mensaje</label>
                        <textarea class="form-control rm-webhook-message" id="webhook-mensaje" name="mensaje" rows="2" placeholder="Ej: impresora no imprime, SOME HBV, Francisca Perez" required>{{ old('mensaje') }}</textarea>
                    </div>
                    <div class="col-12">
                        <div class="rm-form-actions">
                            <button class="btn btn-success" type="submit"><i class="bi bi-send"></i>Enviar al webhook</button>
                            <a class="btn btn-outline-secondary" href="{{ $redmineRoute('redmine.dashboard') }}"><i class="bi bi-inboxes"></i>Ver cola</a>
                        </div>
                    </div>
                </div>
            </form>
        </article>
    </div>

    <div class="col-12 col-xxl-3">
        <article class="card nova-card rm-panel rm-webhook-panel">
            <div class="rm-section-head">
                <div>
                    <h2>Formato esperado</h2>
                </div>
            </div>
            <div class="rm-kv"><span>Problema</span><strong>Primera parte del mensaje</strong></div>
            <div class="rm-kv"><span>Ubicacion</span><strong>Segunda parte del mensaje</strong></div>
            <div class="rm-kv"><span>Solicitante</span><strong>Tercera parte del mensaje</strong></div>
            <div class="alert alert-info py-2 px-3 mt-3 mb-0">
                Ejemplo: <strong>computador lento, SOME HBV, Juan Perez</strong>
            </div>
        </article>

        @if (is_array($webhookResult))
            <article class="card nova-card rm-panel rm-webhook-panel mt-3">
                <div class="rm-section-head">
                    <div>
                        <h2>Ultima respuesta</h2>
                        <p>Resultado devuelto por el servicio Python.</p>
                    </div>
                    <span class="nova-badge {{ ($webhookResult['ok'] ?? false) ? 'is-success' : '' }}">HTTP {{ $webhookResult['http_code'] ?? 0 }}</span>
                </div>
                <pre class="rm-log rm-webhook-log">{{ json_encode([
                    'url' => $webhookResult['url'] ?? '',
                    'ok' => $webhookResult['ok'] ?? false,
                    'body' => $webhookResult['body'] ?? '',
                    'error' => $webhookResult['error'] ?? '',
                    'payload' => $webhookResult['payload'] ?? [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </article>
        @endif
    </div>
</section>

<style>
    .rm-webhook-view .rm-panel { padding: 14px; }
    .rm-webhook-view .rm-section-head { margin-bottom: 10px; }
    .rm-webhook-view .rm-section-head p { margin-top: 2px; }
    .rm-webhook-view .form-label { margin-bottom: .3rem; }
    .rm-webhook-message { min-height: 70px; resize: vertical; }
    .rm-webhook-log { max-height: 170px; font-size: .78rem; }
    .rm-webhook-view .rm-kv { grid-template-columns: 108px minmax(0, 1fr); padding: 7px 0; }
</style>

<script>
    (() => {
        const userSelect = document.getElementById('webhook-usuario');
        const numberInput = document.getElementById('webhook-numero');
        if (!userSelect || !numberInput) return;

        userSelect.addEventListener('change', () => {
            if (userSelect.value) numberInput.value = userSelect.value;
        });
    })();
</script>

