<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Procedimientos</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ url('/redmine-mantencion/assets/theme.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
    <script defer src="{{ asset('assets/nova-ui.js') }}"></script>
</head>
<body class="nova-page bg-light">
    <nav class="navbar rm-navbar">
        <div class="container-fluid px-3 px-lg-4">
            <a class="navbar-brand text-white fw-bold d-flex align-items-center gap-3" href="{{ route('procedimientos.index') }}">
                <span class="rm-brand-mark"><i class="bi bi-journal-richtext"></i></span>
                <span>Procedimientos</span>
            </a>
            <div class="rm-top-actions">
                @include('nova.partials.session-control')
                <span class="nova-navbar-user"><i class="bi bi-person-circle"></i> {{ session('nova_user.name') }}</span>
                <a class="btn btn-outline-light" href="{{ route('home') }}"><i class="bi bi-house-door"></i>NOVA</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-outline-light" type="submit"><i class="bi bi-box-arrow-right"></i>Salir</button></form>
            </div>
        </div>
    </nav>
    <main class="rm-layout">
        <section class="card rm-hero mb-4">
            <div class="card-body p-3 p-lg-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div class="d-flex align-items-center gap-3">
                    <span class="rm-hero-icon"><i class="bi bi-cloud"></i></span>
                    <div><h1 class="rm-page-title">Procedimientos</h1><p class="rm-page-subtitle">Archivos Nextcloud con edicion en linea mediante OnlyOffice.</p></div>
                </div>
                @php $onlyOfficeOnline = ($onlyOfficeHealth['status'] ?? '') === 'online'; @endphp
                <span class="rm-hero-retention onlyoffice-status {{ $onlyOfficeOnline ? 'is-online' : 'is-offline' }}" title="{{ $onlyOfficeHealth['detail'] ?? '' }}">
                    <i class="bi bi-circle-fill" aria-hidden="true"></i>
                    OnlyOffice
                </span>
            </div>
        </section>
        @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
        @php
            require_once base_path('RedmineMantencion/controllers/nc_browser.php');
            $csrf = csrf_token();
            $canEditProcedures = true;
            $ncHasCredentialsOverride = !empty($nextcloudConfigured);
            $ncAjaxUrlOverride = route('procedimientos.browser');
            $ncIntegracionesUrlOverride = route('integrations.nova');
            $ncEditorUrlOverride = route('procedimientos.editor');
            $mantencionBaseUrl = rtrim(url('/redmine-mantencion'), '/');
            include base_path('RedmineMantencion/views/Procedimientos/_nc_browser.php');
        @endphp
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
