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
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #eef3fb;
        }

        .login {
            width: min(420px, calc(100% - 32px));
            padding: 0;
            overflow: hidden;
        }

        .login-hero {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 22px;
            color: #fff;
            background: linear-gradient(130deg, #4f86f7 0%, #2f9ed9 48%, #31c5ae 100%);
        }

        .login-mark {
            display: grid;
            width: 48px;
            height: 48px;
            place-items: center;
            border-radius: 14px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.28);
            font-size: 1.3rem;
        }

        .login-body {
            padding: 24px;
            background: #fff;
        }

        h1 {
            margin: 0;
            font-size: 28px;
            letter-spacing: 0;
            color: #fff;
            font-weight: 800;
        }

        p {
            margin: 4px 0 0;
            color: rgba(255,255,255,.76);
            line-height: 1.4;
        }

        label {
            display: block;
            margin: 0 0 7px;
            font-size: 14px;
            font-weight: 700;
        }

        .field {
            margin-bottom: 16px;
        }

        .error {
            margin: 0 0 16px;
            color: var(--nova-danger);
            font-size: 14px;
        }
    </style>
</head>
<body class="nova-page">
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
