<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · @yield('title') | NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ @filemtime(public_path('assets/nova-ui.css')) ?: '1' }}" rel="stylesheet">
    <?php include base_path('resources/views/partials/nova-v2-assets.php'); ?>
</head>
<body class="nova-page nova-v2">
    {{-- Páginas de error NOVA v2: sin sesión ni BD, para que sirvan también ante un 500. --}}
    <main class="nova-v2-notice" id="page-content" aria-labelledby="error-title">
        <section class="nova-v2-notice__card">
            <span class="nova-v2-notice__icon is-@yield('tone', 'info')" aria-hidden="true"><i class="bi @yield('icon', 'bi-exclamation-circle')"></i></span>
            <span class="nova-v2-eyebrow">Error @yield('code')</span>
            <h1 class="nova-v2-title" id="error-title">@yield('title')</h1>
            {{-- El detalle de abort() (p. ej. "No tienes permiso para ver Reportes.") se muestra salvo en 500. --}}
            @php($errorDetail = isset($exception) ? trim((string) $exception->getMessage()) : '')
            <p class="nova-v2-subtitle">@yield('message')</p>
            @if ($errorDetail !== '' && ! in_array($errorDetail, ['Forbidden', 'Not Found', 'Unauthorized', 'Service Unavailable', 'Server Error', 'Too Many Attempts.', 'Page Expired', 'CSRF token mismatch.'], true) && $__env->yieldContent('code') !== '500')
                <p class="nova-v2-notice__detail">{{ $errorDetail }}</p>
            @endif
            <div class="nova-v2-notice__actions">
                @hasSection('actions')
                    @yield('actions')
                @else
                    <button type="button" class="btn btn-outline-secondary" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/') }}')"><i class="bi bi-arrow-left"></i> Volver</button>
                    <a class="btn btn-primary" href="{{ url('/') }}"><i class="bi bi-house"></i> Ir al inicio</a>
                @endif
            </div>
        </section>
    </main>
</body>
</html>
