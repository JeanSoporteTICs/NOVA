@php
    $canPermission = static fn (string $permission): bool => array_key_exists($permission, $permissions)
        ? !empty($permissions[$permission])
        : !empty($permissions['all']);
    $can = static fn (string|array $permission): bool => collect((array) $permission)->contains($canPermission);
    $maintenance = is_array($context['maintenance'] ?? null) ? $context['maintenance'] : [];
    $navigation = [
        ['key' => 'dashboard', 'label' => 'Reportes', 'icon' => 'bi-inboxes', 'permission' => 'mensajes_acceso', 'url' => route('redmine.mantencion.dashboard')],
        ['key' => 'manual', 'label' => 'Pendiente manual', 'icon' => 'bi-pencil-square', 'permission' => 'simulador', 'url' => route('redmine.mantencion.manual')],
        ['key' => 'horas-extra', 'label' => 'Horas extra', 'icon' => 'bi-clock-history', 'permission' => 'horas_extra', 'url' => route('redmine.mantencion.hours')],
        ['key' => 'historico', 'label' => 'Histórico', 'icon' => 'bi-archive', 'permission' => 'historico', 'url' => route('redmine.mantencion.history')],
        ['key' => 'integraciones', 'label' => 'Cuentas conectadas', 'icon' => 'bi-person-lock', 'permission' => 'mis_integraciones', 'url' => route('integrations.redmine_mantencion')],
        ['key' => 'usuarios', 'label' => 'Usuarios', 'icon' => 'bi-people', 'permission' => 'usuarios', 'url' => route('redmine.mantencion.users')],
        ['key' => 'configuracion', 'label' => 'Configuración', 'icon' => 'bi-sliders', 'permission' => ['configuracion', 'categorias', 'cfg_categorias', 'cfg_unidades', 'cfg_trackers', 'cfg_prioridades', 'cfg_estados', 'cfg_roles', 'cfg_conexion'], 'url' => route('redmine.mantencion.config')],
        ['key' => 'integraciones', 'label' => 'Integraciones', 'icon' => 'bi-diagram-3', 'permission' => 'integraciones_nextcloud', 'children' => [
            ['key' => 'nextcloud', 'label' => 'Nextcloud', 'icon' => 'bi-cloud', 'permission' => 'integraciones_nextcloud', 'url' => route('redmine.mantencion.config').'#config-nextcloud'],
            ['key' => 'nextcloud-groups', 'label' => 'Grupos', 'icon' => 'bi-people', 'permission' => 'integraciones_nextcloud', 'url' => route('redmine.mantencion.nextcloud.groups')],
            ['key' => 'nextcloud-history', 'label' => 'Historial', 'icon' => 'bi-clock-history', 'permission' => 'integraciones_nextcloud', 'url' => route('redmine.mantencion.nextcloud.history')],
        ]],
        ['key' => 'estadisticas', 'label' => 'Estadísticas', 'icon' => 'bi-bar-chart-line', 'permission' => 'estadisticas', 'url' => route('redmine.mantencion.stats')],
        ['key' => 'actividad', 'label' => 'Actividad reciente', 'icon' => 'bi-activity', 'permission' => 'actividad', 'url' => route('redmine.mantencion.activity')],
    ];
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Redmine Mantención' }}</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    @php
        $themePath = base_path('RedmineMantencion/assets/theme.css');
        $novaUiPath = public_path('assets/nova-ui.css');
    @endphp
    <link href="{{ url('/redmine-mantencion/assets/theme.css') }}?v={{ @filemtime($themePath) ?: 1 }}" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ @filemtime($novaUiPath) ?: 1 }}" rel="stylesheet">
    @stack('styles')
</head>
<body class="bg-light nova-page nova-mantencion-page {{ !empty($maintenance['enabled']) ? 'rm-maintenance-active' : '' }}">
    <nav class="navbar navbar-expand-lg navbar-dark sb-navbar sb-native-navbar">
        <div class="container-fluid px-4">
            <div class="sb-navbar-top">
                <a class="navbar-brand sb-navbar-brand" href="{{ route('redmine.mantencion.dashboard') }}">
                    <span class="sb-brand-mark"><i class="bi bi-layout-sidebar-inset"></i></span>
                    <span>Redmine Mantención</span>
                </a>
                <button class="nova-sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#novaSidebar" aria-controls="novaSidebar" aria-label="Abrir menú lateral">
                    <i class="bi bi-list"></i>
                </button>
                <div class="sb-nav-actions d-flex align-items-center gap-2">
                    @include('nova.partials.session-control')
                    @if (!empty($maintenance['enabled']))
                        <span class="sb-maintenance-badge d-none d-md-inline-flex"><i class="bi bi-tools"></i>Mantención activa</span>
                    @endif
                    <span class="sb-user-pill text-white-50 small d-none d-sm-inline"><i class="bi bi-person-circle"></i> <strong>{{ trim((string) $context['viewer_name']) ?: 'usuario' }}</strong></span>
                    <a class="btn btn-outline-light btn-sm sb-nova-home-btn" href="{{ route('home') }}"><i class="bi bi-house-door"></i> <span>NOVA</span></a>
                    <form method="POST" action="{{ route('logout') }}" class="d-inline" data-maintenance-allowed="1">
                        @csrf
                        <button type="submit" class="btn btn-outline-light btn-sm sb-logout-btn"><i class="bi bi-box-arrow-right"></i> <span>Salir</span></button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <div class="nova-layout">
        <aside class="nova-sidebar offcanvas-lg offcanvas-start" id="novaSidebar" tabindex="-1" aria-labelledby="novaSidebarLabel">
            <div class="offcanvas-header d-lg-none border-bottom py-3">
                <strong class="offcanvas-title fw-bold" id="novaSidebarLabel">Redmine Mantención</strong>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
            </div>
            <nav class="nova-sidebar-body" aria-label="Secciones Redmine Mantención">
                @foreach ($navigation as $item)
                    @continue(!$can($item['permission']))
                    @php
                        $children = collect($item['children'] ?? [])->filter(fn ($child) => $can($child['permission'] ?? ''))->values();
                        $itemActive = ($activeSection ?? '') === $item['key'] || $children->contains(fn ($child) => ($activeSection ?? '') === $child['key']);
                        $collapseId = 'sidebar-group-'.$item['key'];
                    @endphp
                    @if($children->isNotEmpty())
                        <div class="nova-sidebar-group">
                            <a class="nova-sidebar-link {{ $itemActive ? 'active' : '' }}" href="#{{ $collapseId }}" data-bs-toggle="collapse" aria-expanded="{{ $itemActive ? 'true' : 'false' }}" aria-controls="{{ $collapseId }}">
                                <i class="bi {{ $item['icon'] }} nova-sidebar-icon"></i><span>{{ $item['label'] }}</span><i class="bi bi-chevron-down nova-sidebar-chevron"></i>
                            </a>
                            <div class="collapse {{ $itemActive ? 'show' : '' }}" id="{{ $collapseId }}"><div class="nova-sidebar-sub">
                                @foreach($children as $child)
                                    <a class="nova-sidebar-link {{ ($activeSection ?? '') === $child['key'] ? 'active' : '' }}" href="{{ $child['url'] }}" @if(($activeSection ?? '') === $child['key']) aria-current="page" @endif><i class="bi {{ $child['icon'] }} nova-sidebar-icon"></i><span>{{ $child['label'] }}</span></a>
                                @endforeach
                            </div></div>
                        </div>
                    @else
                        <a class="nova-sidebar-link {{ $itemActive ? 'active' : '' }}" href="{{ $item['url'] }}" @if($itemActive) aria-current="page" @endif><i class="bi {{ $item['icon'] }} nova-sidebar-icon"></i><span>{{ $item['label'] }}</span></a>
                    @endif
                @endforeach
            </nav>
        </aside>

        <main class="nova-content" id="nova-main-content">
            <div class="app-page-loader" id="app-page-loader" aria-hidden="true"></div>
            @if (!empty($maintenance['enabled']))
                <div class="nova-alert-card is-warning mb-3" role="status">
                    <i class="bi bi-tools"></i>
                    <span>Módulo en mantención{{ !empty($maintenance['until_text']) ? ' hasta ' . $maintenance['until_text'] : '' }}. Los cambios están desactivados.</span>
                </div>
            @endif
            @yield('content')
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('assets/nova-ui.js') }}"></script>
    @stack('scripts')
</body>
</html>
