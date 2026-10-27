<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ @filemtime(public_path('assets/nova-ui.css')) ?: '1' }}" rel="stylesheet">
    <?php include base_path('resources/views/partials/nova-v2-assets.php'); ?>
</head>
<body class="nova-page nova-v2">
    <main class="nova-v2-notice" id="page-content" aria-labelledby="welcome-title">
        <section class="nova-v2-notice__card">
            <span class="nova-v2-notice__icon is-info" aria-hidden="true"><i class="bi bi-grid-1x2"></i></span>
            <span class="nova-v2-eyebrow">Plataforma de gestión interna</span>
            <h1 class="nova-v2-title" id="welcome-title">NOVA</h1>
            <p class="nova-v2-subtitle">Accede a tus módulos desde el inicio de NOVA.</p>
            <div class="nova-v2-notice__actions">
                @if (session()->has('nova_user'))
                    <a class="btn btn-primary" href="{{ url('/') }}"><i class="bi bi-house"></i> Ir al inicio</a>
                @else
                    <a class="btn btn-primary" href="{{ url('/login') }}"><i class="bi bi-box-arrow-in-right"></i> Iniciar sesión</a>
                @endif
            </div>
        </section>
    </main>
</body>
</html>
