<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar - NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ @filemtime(public_path('assets/nova-ui.css')) ?: '1' }}" rel="stylesheet">
</head>
<body class="nova-page nova-login-page">
    <main class="login" aria-labelledby="login-title">
        <section class="login-hero" aria-label="Plataforma NOVA">
            <div class="login-hero__content">
                <p class="login-product-name">NOVA</p>
                <p class="login-hero__tagline">Plataforma de gestión interna</p>
            </div>
        </section>

        <section class="login-body">
            <div class="login-body__heading">
                <h2 id="login-title">Ingresar</h2>
                <p>Accede con tus credenciales institucionales.</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger login-error" role="alert" aria-live="polite">
                    <i class="bi bi-exclamation-circle"></i>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="post" action="{{ route('login.store') }}" class="login-form">
                @csrf
                <div class="field">
                    <label for="username">Usuario, ID o RUT</label>
                    <div class="login-input">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <input class="form-control" id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus>
                    </div>
                </div>
                <div class="field">
                    <label for="password">Contraseña</label>
                    <div class="login-input">
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                        <button class="login-password-toggle" type="button" aria-controls="password" aria-label="Mostrar contraseña" aria-pressed="false">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <button class="btn btn-primary nova-w-full login-submit" type="submit">
                    <span>Ingresar</span><i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <p class="login-security-note"><i class="bi bi-shield-check"></i> Conexión segura</p>
        </section>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const toggle = document.querySelector('.login-password-toggle');
            const password = document.getElementById('password');
            if (!toggle || !password) return;

            toggle.addEventListener('click', function () {
                const willShow = password.type === 'password';
                password.type = willShow ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', willShow ? 'true' : 'false');
                toggle.setAttribute('aria-label', willShow ? 'Ocultar contraseña' : 'Mostrar contraseña');
                const icon = toggle.querySelector('i');
                if (icon) icon.className = willShow ? 'bi bi-eye-slash' : 'bi bi-eye';
                password.focus();
            });
        }());
    </script>
</body>
</html>
