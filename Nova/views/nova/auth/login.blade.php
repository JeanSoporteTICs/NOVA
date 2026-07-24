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
    <main class="login" aria-labelledby="login-title">
        <section class="login-hero" aria-label="Plataforma NOVA">
            <div class="login-hero__content">
                <span class="login-brand">
                    <span class="login-mark"><i class="bi bi-grid-1x2-fill"></i></span>
                    <span>NOVA</span>
                </span>
                <div class="login-hero__copy">
                    <span class="login-eyebrow">Plataforma operacional</span>
                    <h1>Todo tu trabajo,<br>en un solo lugar.</h1>
                    <p>Accede a las herramientas y servicios internos desde una experiencia centralizada.</p>
                </div>
                <div class="login-module-list" aria-label="Módulos disponibles">
                    <span><i class="bi bi-ticket-perforated"></i> Redmine</span>
                    <span><i class="bi bi-clock-history"></i> EMACH</span>
                    <span><i class="bi bi-send"></i> Telegram</span>
                    <span><i class="bi bi-cloud"></i> Nextcloud</span>
                </div>
            </div>
        </section>

        <section class="login-body">
            <div class="login-body__heading">
                <span class="login-eyebrow">Acceso seguro</span>
                <h2 id="login-title">Inicia sesión</h2>
                <p>Usa tus credenciales institucionales para continuar.</p>
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
                    <label for="username">Usuario, ID Redmine o RUT</label>
                    <div class="login-input">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <input class="form-control" id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus placeholder="Ingresa tu identificador">
                    </div>
                </div>
                <div class="field">
                    <label for="password">Contraseña</label>
                    <div class="login-input">
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required placeholder="Ingresa tu contraseña">
                    </div>
                </div>
                <button class="btn btn-primary nova-w-full login-submit" type="submit">
                    <span>Ingresar a NOVA</span><i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <p class="login-security-note"><i class="bi bi-shield-check"></i> Conexión protegida por NOVA</p>
        </section>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
