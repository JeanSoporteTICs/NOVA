@php
    $telegramActiveNav = $telegramActiveNav ?? 'inicio';
    $telegramMode = $mode ?? 'user';
    $telegramIsAdmin = in_array((string) session('nova_user.role', 'usuario'), config('nova.module_admin_roles', []), true);
@endphp
<header class="telegram-topbar">
    <div class="telegram-brand">
        <span class="telegram-brand-mark"><i class="bi bi-telegram fs-4"></i></span>
        <div>
            <h1>Telegram</h1>
            <span>{{ $telegramMode === 'admin' ? 'Administración global' : 'Configuración personal' }}</span>
        </div>
    </div>
    <button class="nova-sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#telegramSidebar" aria-controls="telegramSidebar" aria-label="Abrir menú lateral">
        <i class="bi bi-list"></i>
    </button>
    <div class="d-flex align-items-center gap-2 flex-wrap ms-auto">
        @include('nova.partials.session-control')
        <span class="nova-navbar-user"><i class="bi bi-person-circle"></i> @include('nova.partials.current-user-name')</span>
        <a class="btn btn-outline-light" href="{{ route('home') }}"><i class="bi bi-house-door"></i> NOVA</a>
        <form method="POST" action="{{ route('logout') }}" class="d-inline m-0">
            @csrf
            <button class="btn btn-outline-light" type="submit"><i class="bi bi-box-arrow-right"></i> Salir</button>
        </form>
    </div>
</header>

<div class="nova-layout telegram-layout">
    <aside class="nova-sidebar offcanvas-lg offcanvas-start" id="telegramSidebar" tabindex="-1" aria-labelledby="telegramSidebarLabel">
        <div class="offcanvas-header d-lg-none border-bottom py-3">
            <strong class="offcanvas-title fw-bold" id="telegramSidebarLabel">Telegram</strong>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <nav class="nova-sidebar-body" aria-label="Navegación Telegram">
            <a class="nova-sidebar-link {{ $telegramActiveNav === 'inicio' ? 'active' : '' }}" href="{{ route('telegram.index') }}" @if($telegramActiveNav === 'inicio') aria-current="page" @endif>
                <i class="bi {{ config('navigation-icons.telegram') }} nova-sidebar-icon"></i><span>Mi Telegram</span>
            </a>
            @if($telegramIsAdmin)
                <a class="nova-sidebar-link {{ $telegramActiveNav === 'actividad' ? 'active' : '' }}" href="{{ route('telegram.log') }}" @if($telegramActiveNav === 'actividad') aria-current="page" @endif>
                    <i class="bi {{ config('navigation-icons.actividad') }} nova-sidebar-icon"></i><span>Actividad</span>
                </a>
            @endif
        </nav>
        @include('nova.partials.sidebar-compact-control', ['sidebarId' => 'telegramSidebar'])
    </aside>
