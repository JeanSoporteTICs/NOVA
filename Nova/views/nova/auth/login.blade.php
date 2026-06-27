<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar - NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
</head>
<body class="nova-page nova-login-page">
    <main class="login nova-card">
        <section class="login-hero">
            <span class="login-mark"><i class="bi bi-grid-1x2-fill"></i></span>
            <div>
                <h1>NOVA</h1>
                <p>Ingreso principal</p>
            </div>
        </section>

        <section class="login-body">
            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            <form method="post" action="{{ route('login.store') }}">
                @csrf
                <div class="field">
                    <label for="username">Usuario acceso, ID Redmine o RUT</label>
                    <input class="form-control" id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus>
                </div>
                <div class="field">
                    <label for="password">Contrasena</label>
                    <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary nova-w-full" type="submit"><i class="bi bi-box-arrow-in-right"></i>Ingresar</button>
            </form>
        </section>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
