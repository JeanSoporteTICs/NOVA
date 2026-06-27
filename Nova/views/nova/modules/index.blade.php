<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Modulos - NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
</head>
<body class="nova-page">
    <main class="shell nova-shell">
        <nav class="navbar navbar-expand-lg navbar-dark rm-navbar">
            <div class="container-fluid px-0">
                <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="{{ route('modules.index') }}">
                    <span class="rm-brand-mark"><i class="bi bi-sliders"></i></span>
                    <span>Modulos NOVA</span>
                </a>
                <div class="rm-top-actions">
                    @include('nova.partials.session-control')
                    <span class="text-white-50 fw-bold"><i class="bi bi-person-circle"></i> {{ session('nova_user.name') }}</span>
                    <a class="btn btn-outline-light" href="{{ route('home') }}"><i class="bi bi-house-door"></i>NOVA</a>
                    <form method="POST" action="{{ route('logout') }}" style="display:inline">
                        @csrf
                        <button class="btn btn-outline-light" type="submit"><i class="bi bi-box-arrow-right"></i>Salir</button>
                    </form>
                </div>
            </div>
        </nav>

        <nav class="rm-section-nav" aria-label="Secciones Modulos NOVA">
            <a class="nav-link active" href="{{ route('modules.index') }}"><i class="bi bi-sliders"></i>Modulos</a>
            <a class="nav-link" href="{{ route('home') }}"><i class="bi bi-grid"></i>Inicio</a>
        </nav>

        <section class="card rm-hero mb-4">
            <div class="card-body p-3 p-lg-4 d-flex align-items-center gap-3 flex-wrap">
                <span class="rm-hero-icon"><i class="bi bi-sliders"></i></span>
                <h1 class="rm-page-title">Modulos</h1>
            </div>
        </section>

        @if (session('status'))
            <div class="nova-card nova-card-pad nova-alert-success nova-mb">{{ session('status') }}</div>
        @endif

        <section class="nova-system-head" aria-label="Gestion de modulos">
            <span class="nova-system-icon" aria-hidden="true"><i class="bi bi-sliders"></i></span>
            <div>
                <small>Registro de modulos</small>
                <h2>Configuracion de modulos</h2>
                <p>Activa, ordena y ajusta nombres visibles sin cambiar rutas ni permisos.</p>
            </div>
            <span class="nova-system-meter"><strong>{{ count($modules) }}</strong><span>modulos</span></span>
        </section>

        <form method="post" action="{{ route('modules.update') }}">
            @csrf
            <div class="card nova-card nova-system-card nova-table-wrap rm-table-wrap">
                <table class="table modules-table mb-0">
                    <thead>
                        <tr>
                            <th>Activo</th>
                            <th>Modulo</th>
                            <th>Tipo</th>
                            <th>Nombre visible</th>
                            <th>Orden</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($modules as $key => $module)
                            @php($moduleState = $state[$key] ?? [])
                            <tr>
                                <td>
                                    <input type="checkbox" name="enabled[]" value="{{ $key }}" @checked($module['enabled'] ?? true)>
                                </td>
                                <td>
                                    <div class="module-name">{{ $module['name'] }}</div>
                                    <div class="module-key">{{ $key }}</div>
                                </td>
                                <td><span class="nova-badge">{{ $module['type'] ?? 'legacy' }}</span></td>
                                <td>
                                    <input type="text" name="labels[{{ $key }}]" value="{{ $moduleState['label'] ?? '' }}" placeholder="{{ $module['name'] }}">
                                </td>
                                <td>
                                    <input type="number" name="order[{{ $key }}]" value="{{ $module['order'] ?? 100 }}" min="0" step="1">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="actions nova-system-toolbar">
                <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar cambios</button>
            </div>
        </form>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
