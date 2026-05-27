<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Modulos - NOVA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #eef3fb;
        }

        .shell {
            width: 100%;
            margin: 0 auto;
            padding: 0 24px 44px;
        }

        .rm-navbar { min-height: 68px; margin: 0 -24px 24px; padding: 12px 24px; background: linear-gradient(115deg, #1f2f56 0%, #314ed8 62%, #4966ff 100%); box-shadow: 0 16px 36px rgba(31, 47, 86, .22); }
        .rm-brand-mark { display: inline-grid; width: 42px; height: 42px; place-items: center; border-radius: 12px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.24); color: #fff; }
        .rm-top-actions { margin-left: auto; display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .rm-section-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
        .rm-section-nav .nav-link { display: inline-flex; align-items: center; gap: 8px; min-height: 42px; border-radius: 10px; padding: 8px 12px; background: #fff; color: #334155; font-weight: 800; box-shadow: 0 8px 20px rgba(15,23,42,.05); }
        .rm-section-nav .nav-link.active { background: var(--nova-primary); color: #fff; box-shadow: 0 14px 30px rgba(37, 99, 235, .22); }
        .rm-hero { border: 0; color: #fff; background: linear-gradient(130deg, #4f86f7 0%, #2f9ed9 48%, #31c5ae 100%); box-shadow: 0 18px 34px rgba(49, 91, 170, .14); }
        .rm-hero-icon { display: grid; width: 46px; height: 46px; place-items: center; flex: 0 0 auto; border-radius: 14px; background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28); font-size: 1.25rem; }
        .rm-page-title { margin: 0; color: #fff; font-size: clamp(1.55rem, 3vw, 2.25rem); font-weight: 800; }
        .rm-table-wrap .table thead th { background: #eaf8fd; color: #435061; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }

        .module-name {
            font-weight: 800;
        }

        .module-key {
            color: var(--nova-muted);
            font-size: 13px;
            margin-top: 3px;
        }

        input[type="number"] {
            max-width: 90px;
        }

        .actions {
            margin-top: 16px;
            display: flex;
            justify-content: flex-end;
        }

        @media (max-width: 760px) {
            th:nth-child(3), td:nth-child(3) {
                display: none;
            }
        }
    </style>
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
                    <a class="btn btn-outline-light" href="{{ route('logout') }}"><i class="bi bi-box-arrow-right"></i>Salir</a>
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

        <form method="post" action="{{ route('modules.update') }}">
            @csrf
            <div class="card nova-card nova-table-wrap rm-table-wrap">
                <table class="table mb-0">
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
                                    <div class="module-key">{{ $module['description'] }}</div>
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
            <div class="actions">
                <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar cambios</button>
            </div>
        </form>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
