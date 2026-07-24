<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Actividad {{ $moduleName }} | NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ @filemtime(public_path('assets/nova-ui.css')) ?: '1' }}" rel="stylesheet">
</head>
<body class="{{ $module === 'emach' ? 'emach-page' : '' }}">
@if($module === 'emach')
    @php
        $activeNav = 'actividad';
    @endphp
    <?php include base_path('Emach/views/partials/navbar.php'); ?>
    <main class="nova-content"><div class="container-fluid py-4">
@else
    <main class="telegram-page">
    @php
        $telegramActiveNav = 'actividad';
        $mode = 'user';
    @endphp
    @include('nova.telegram.navigation')
    <div class="nova-content telegram-content">
@endif
    <section class="nova-system-hero module-log-hero mb-4">
        <div class="nova-system-head">
            <span class="nova-system-icon"><i class="bi bi-activity"></i></span>
            <div><small>REGISTRO OPERACIONAL</small><h1>Actividad {{ $moduleName }}</h1></div>
        </div>
        <a class="btn btn-outline-light" href="{{ $module === 'telegram' ? route('telegram.index') : route('emach.index') }}"><i class="bi bi-arrow-left"></i> Volver</a>
    </section>

    <section class="security-console-wrap">
        <div class="security-console-toolbar">
            <span class="security-console-dot" aria-hidden="true"></span>
            <span>Actividad {{ $moduleName }} :: base de datos</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle security-console module-log-console">
                <thead><tr><th>Fecha</th><th>Evento</th><th>Usuario</th><th>Detalle</th><th>Contexto</th></tr></thead>
                <tbody>
                @forelse($rows as $row)
                    <tr>
                        <td class="console-time">{{ $row->registrado_at }}</td>
                        <td><span class="console-tag">{{ strtoupper($row->evento) }}</span></td>
                        <td class="console-user">
                            <div class="d-flex align-items-center gap-2">
                                <span class="console-user-icon"><i class="bi {{ $row->usuario_id ? 'bi-person' : 'bi-gear' }}"></i></span>
                                <div>
                                    <strong class="d-block">{{ $row->usuario_nombre }}</strong>
                                    @if($row->usuario_id && $row->usuario_nombre === 'Usuario no encontrado')
                                        <small class="text-muted">ID {{ $row->usuario_id }}</small>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="console-details">{{ $row->detalle ?: '-' }}</td>
                        <td class="console-context">
                            @forelse($row->contexto_items as $item)
                                <span class="console-context-item">
                                    <strong>{{ $item['label'] }}:</strong> {{ $item['value'] }}
                                </span>
                            @empty
                                <span class="text-muted">Sin información adicional</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="nova-empty">Todavía no hay movimientos registrados.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="module-log-pagination">{{ $rows->links() }}</div>
    </section>
@if($module === 'emach')
    </div></main></div>
@else
    </div></div></main>
@endif
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('assets/nova-ui.js') }}"></script>
</body>
</html>
