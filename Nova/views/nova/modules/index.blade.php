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
    @php
        $novaUiVersion = @filemtime(public_path('assets/nova-ui.css')) ?: '1';
    @endphp
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ $novaUiVersion }}" rel="stylesheet">
</head>
<body class="nova-page">
    <main class="shell nova-shell">
        <nav class="navbar navbar-expand-lg navbar-dark rm-navbar">
            <div class="container-fluid px-4">
                <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="{{ route('modules.index') }}">
                    <span class="rm-brand-mark"><i class="bi bi-sliders"></i></span>
                    <span>Modulos NOVA</span>
                </a>
                <div class="rm-top-actions">
                    @include('nova.partials.session-control')
                    <span class="nova-navbar-user"><i class="bi bi-person-circle"></i> {{ session('nova_user.name') }}</span>
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
            <div data-nova-flash="success" data-nova-flash-message="{{ session('status') }}" hidden></div>
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

        <form method="post" action="{{ route('modules.update') }}" data-modules-form>
            @csrf
            <section class="nova-card module-order-panel">
                <div class="nova-system-toolbar is-between module-toolbar">
                    <div class="module-toolbar-summary">
                        <strong>{{ count($modules) }} modulo(s)</strong>
                        <span>Orden de aparicion en el inicio NOVA.</span>
                    </div>
                    <div class="module-toolbar-actions">
                        <button class="btn-nova btn-nova-secondary" type="button" data-module-sort-alpha>
                            <i class="bi bi-sort-alpha-down"></i>Orden A-Z
                        </button>
                        <button class="btn-nova btn-nova-secondary" type="button" data-module-reset-order>
                            <i class="bi bi-arrow-counterclockwise"></i>Restaurar
                        </button>
                    </div>
                </div>

                <div class="module-order-list" data-module-list>
                    @foreach ($modules as $key => $module)
                    @php
                        $moduleState = $state[$key] ?? [];
                    @endphp
                        <article class="module-order-item" data-module-item data-module-key="{{ $key }}" data-module-name="{{ $module['name'] }}">
                            <div class="module-order-rank" data-module-rank>{{ $loop->iteration }}</div>
                            <div class="module-order-main">
                                <div class="module-order-head">
                                    <div>
                                        <div class="module-name">{{ $module['name'] }}</div>
                                        <div class="module-key">{{ $key }}</div>
                                    </div>
                                    <span class="nova-badge">{{ $module['type'] ?? 'legacy' }}</span>
                                </div>
                                <label class="module-label-field">
                                    <span>Nombre visible</span>
                                    <input class="form-control" type="text" name="labels[{{ $key }}]" value="{{ $moduleState['label'] ?? '' }}" placeholder="{{ $module['name'] }}">
                                </label>
                            </div>
                            <div class="module-order-state">
                                <label class="module-switch">
                                    <input class="form-check-input" type="checkbox" name="enabled[]" value="{{ $key }}" @checked($module['enabled'] ?? true)>
                                    <span>Activo</span>
                                </label>
                                <input type="hidden" name="order[{{ $key }}]" value="{{ $module['order'] ?? ($loop->iteration * 10) }}" data-module-order>
                                <div class="module-order-controls">
                                    <button class="module-icon-btn" type="button" data-module-up title="Subir" aria-label="Subir {{ $module['name'] }}">
                                        <i class="bi bi-arrow-up"></i>
                                    </button>
                                    <button class="module-icon-btn" type="button" data-module-down title="Bajar" aria-label="Bajar {{ $module['name'] }}">
                                        <i class="bi bi-arrow-down"></i>
                                    </button>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="actions nova-system-toolbar">
                    <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i>Guardar cambios</button>
                </div>
            </section>
        </form>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('assets/nova-ui.js') }}"></script>
    <script>
        (() => {
            const list = document.querySelector('[data-module-list]');
            const form = document.querySelector('[data-modules-form]');
            if (!list || !form) return;

            const originalOrder = Array.from(list.querySelectorAll('[data-module-item]')).map((item) => item.dataset.moduleKey || '');
            const items = () => Array.from(list.querySelectorAll('[data-module-item]'));

            const refresh = () => {
                items().forEach((item, index, all) => {
                    const rank = item.querySelector('[data-module-rank]');
                    const order = item.querySelector('[data-module-order]');
                    const up = item.querySelector('[data-module-up]');
                    const down = item.querySelector('[data-module-down]');
                    if (rank) rank.textContent = String(index + 1);
                    if (order) order.value = String((index + 1) * 10);
                    if (up) up.disabled = index === 0;
                    if (down) down.disabled = index === all.length - 1;
                });
            };

            const move = (item, direction) => {
                if (direction < 0 && item.previousElementSibling) {
                    list.insertBefore(item, item.previousElementSibling);
                }
                if (direction > 0 && item.nextElementSibling) {
                    list.insertBefore(item.nextElementSibling, item);
                }
                refresh();
            };

            list.addEventListener('click', (event) => {
                const button = event.target.closest('[data-module-up], [data-module-down]');
                if (!button) return;
                const item = button.closest('[data-module-item]');
                if (!item) return;
                move(item, button.hasAttribute('data-module-up') ? -1 : 1);
            });

            document.querySelector('[data-module-sort-alpha]')?.addEventListener('click', () => {
                items()
                    .sort((a, b) => (a.dataset.moduleName || '').localeCompare(b.dataset.moduleName || '', 'es'))
                    .forEach((item) => list.appendChild(item));
                refresh();
            });

            document.querySelector('[data-module-reset-order]')?.addEventListener('click', () => {
                originalOrder.forEach((key) => {
                    const item = items().find((candidate) => candidate.dataset.moduleKey === key);
                    if (item) list.appendChild(item);
                });
                refresh();
            });

            form.addEventListener('submit', refresh);
            refresh();
        })();
    </script>
</body>
</html>
