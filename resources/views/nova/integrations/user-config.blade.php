<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $moduleConfig['title'] }} | Mis integraciones</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
</head>
<body class="nova-page integration-page">
<div class="rm-shell">
    <nav class="navbar navbar-expand-lg navbar-dark rm-navbar integration-navbar">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="{{ $homeUrl }}">
                <span class="rm-brand-mark"><i class="bi {{ $moduleConfig['icon'] }}"></i></span>
                <span>{{ $moduleConfig['title'] }}</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#integrationTopbar" aria-controls="integrationTopbar" aria-expanded="false" aria-label="Alternar navegacion">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="integrationTopbar">
                <div class="rm-top-actions integration-top-actions mt-3 mt-lg-0">
                    @include('nova.partials.session-control')
                    <span class="text-white-50 fw-bold"><i class="bi bi-person-circle"></i> {{ session('nova_user.name') }}</span>
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

    <main class="rm-layout">
        <section class="card card-hero sb-page-hero rm-hero nova-system-hero mb-3">
            <div class="card-body p-3 p-lg-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div class="d-flex align-items-center gap-3">
                    <span class="rm-hero-icon"><i class="bi bi-person-lock"></i></span>
                    <div>
                        <h1 class="rm-page-title text-white">Mis integraciones</h1>
                        <p class="mb-0 text-white-50 fw-semibold">{{ $moduleConfig['subtitle'] }}</p>
                    </div>
                </div>
                <span class="rm-hero-retention"><i class="bi bi-shield-check"></i> Solo tu usuario</span>
            </div>
        </section>

        @if (session('integration_status'))
            <div class="alert alert-success fw-semibold"><i class="bi bi-check-circle"></i> {{ session('integration_status') }}</div>
        @endif
        @if (session('integration_error'))
            <div class="alert alert-warning fw-semibold"><i class="bi bi-exclamation-triangle"></i> {{ session('integration_error') }}</div>
        @endif

        <section class="integration-grid integration-grid--{{ count($integrationDefinitions) }} mb-3">
            @foreach ($integrationDefinitions as $type => $definition)
                @php
                    $state = $integrations[$type] ?? ['stored' => false, 'has_secret' => false, 'masked_external_user' => '', 'updated_at' => ''];
                    $stored = (bool) ($state['stored'] ?? false);
                    $maskedExternal = (string) ($state['masked_external_user'] ?? '');
                    $updatedAt = (string) ($state['updated_at'] ?? '');
                @endphp
                <article class="integration-card nova-card">
                    <div class="integration-card-head">
                        <div class="integration-title">
                            <span class="integration-icon"><i class="bi {{ $definition['icon'] }}"></i></span>
                            <div>
                                <h2 class="h5 fw-black mb-1">{{ $definition['label'] }}</h2>
                                <p class="text-muted fw-semibold mb-0">{{ $definition['description'] }}</p>
                            </div>
                        </div>
                        <span class="integration-status {{ $stored ? 'is-ready' : 'is-pending' }}">
                            {{ $stored ? 'Configurada' : 'Pendiente' }}
                        </span>
                    </div>

                    <div class="integration-meta">
                        <div>
                            <span>Usuario</span>
                            <strong>{{ $definition['external_label'] === '' ? 'No aplica' : ($maskedExternal !== '' ? $maskedExternal : 'Pendiente') }}</strong>
                        </div>
                        <div>
                            <span>Secreto</span>
                            <strong>{{ !empty($state['has_secret']) ? 'Guardado' : 'Pendiente' }}</strong>
                        </div>
                        <div>
                            <span>Actualizacion</span>
                            <strong>{{ $updatedAt !== '' ? $updatedAt : '-' }}</strong>
                        </div>
                    </div>

                    <form method="post" action="{{ $postUrl }}" class="integration-form">
                        @csrf
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="type" value="{{ $type }}">
                        @if ($definition['external_label'] !== '')
                            <div>
                                <label class="form-label fw-bold">{{ $definition['external_label'] }}</label>
                                <input class="form-control" name="external_user" autocomplete="username" value="{{ old('type') === $type ? old('external_user') : '' }}" placeholder="{{ $maskedExternal !== '' ? $maskedExternal : $definition['external_label'] }}">
                            </div>
                        @else
                            <input type="hidden" name="external_user" value="">
                        @endif
                        <div>
                            <label class="form-label fw-bold">{{ $definition['secret_label'] }}</label>
                            <input class="form-control" name="secret" type="password" autocomplete="new-password" placeholder="{{ !empty($state['has_secret']) ? $definition['secret_placeholder'] : $definition['secret_label'] }}">
                            <div class="form-text">Por seguridad nunca se muestra el valor guardado.</div>
                        </div>
                        <div>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Guardar</button>
                        </div>
                    </form>
                    <form method="post" action="{{ $postUrl }}" class="integration-delete-form" data-app-confirm="Eliminar credencial {{ $definition['label'] }}?">
                        @csrf
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="type" value="{{ $type }}">
                        <button class="btn btn-outline-danger" type="submit" @disabled(!$stored)><i class="bi bi-trash"></i> Eliminar</button>
                    </form>
                </article>
            @endforeach
        </section>

        <section class="integration-summary nova-card p-4">
            <div class="rm-section-head">
                <div>
                    <h2><i class="bi bi-list-check"></i> Integraciones configuradas</h2>
                    <p>Resumen visible para soporte sin exponer claves ni tokens.</p>
                </div>
                <span class="badge text-bg-primary rounded-pill">{{ collect($integrations)->filter(fn ($item) => !empty($item['stored']))->count() }} activa(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Integracion</th>
                            <th>Usuario</th>
                            <th>Secreto</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($integrationDefinitions as $type => $definition)
                            @php $state = $integrations[$type] ?? []; @endphp
                            <tr>
                                <td><strong>{{ $definition['label'] }}</strong></td>
                                <td>{{ $definition['external_label'] === '' ? '-' : (($state['masked_external_user'] ?? '') ?: 'Pendiente') }}</td>
                                <td>{{ !empty($state['has_secret']) ? 'Guardado' : 'Pendiente' }}</td>
                                <td><span class="badge {{ !empty($state['stored']) ? 'text-bg-success' : 'text-bg-secondary' }}">{{ !empty($state['stored']) ? 'Configurada' : 'Pendiente' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute('data-app-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});
</script>
</body>
</html>
