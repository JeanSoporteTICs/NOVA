@php
    $entries = (array) ($activityData['entries'] ?? []);
    $total = (int) ($activityData['total'] ?? 0);
    $page = (int) ($activityData['page'] ?? 1);
    $pages = (int) ($activityData['pages'] ?? 1);
    $perPage = (int) ($activityData['per_page'] ?? 50);
    $eventOptions = (array) ($activityData['events'] ?? []);
    $canDeleteActivity = array_key_exists('actividad_eliminar', (array) $effectivePermissions)
        ? !empty($effectivePermissions['actividad_eliminar'])
        : !empty($effectivePermissions['all']);
    $canViewAllActivity = array_key_exists('actividad_todos', (array) $effectivePermissions)
        ? !empty($effectivePermissions['actividad_todos'])
        : false;
    $filters = request()->only(['buscar', 'evento', 'desde', 'hasta', 'per_page']);
    $userNames = [];
    foreach ((array) ($users ?? []) as $activityUser) {
        $id = trim((string) ($activityUser['id'] ?? ''));
        if ($id !== '') $userNames[$id] = trim((string) (($activityUser['nombre'] ?? '') . ' ' . ($activityUser['apellido'] ?? '')));
    }
    $activityPageUrl = static fn (int $target): string => $redmineRoute('redmine.native.section', array_merge(
        ['section' => 'actividad'],
        array_filter($filters, static fn ($value): bool => $value !== ''),
        ['page' => max(1, $target)]
    ));
@endphp

<section class="rm-module-head">
    <span class="rm-module-head-icon is-red"><i class="bi bi-activity"></i></span>
    <div>
        <small>Auditoría operativa TIC</small>
        <h2>Actividad reciente</h2>
        <p>Acciones del módulo, integraciones y resultados, sin exponer payloads ni respuestas técnicas.</p>
    </div>
    <div class="rm-module-meter"><strong>{{ number_format($total, 0, ',', '.') }}</strong><span>eventos</span></div>
</section>

<section class="card nova-card rm-work-panel">
    <div class="card-body p-4">
        <form method="get" action="{{ $redmineRoute('redmine.native.section', 'actividad') }}" class="security-activity-filters">
            <div class="security-activity-filter is-search">
                <label for="tic-activity-search"><i class="bi bi-search"></i> Buscar</label>
                <input id="tic-activity-search" class="form-control" type="search" name="buscar" value="{{ request('buscar') }}" placeholder="Acción, ticket, categoría o usuario">
            </div>
            <div class="security-activity-filter">
                <label for="tic-activity-event"><i class="bi bi-tag"></i> Evento</label>
                <select id="tic-activity-event" class="form-select" name="evento">
                    <option value="">Todos los eventos</option>
                    @foreach ($eventOptions as $event)
                        <option value="{{ $event }}" @selected(request('evento') === $event)>{{ ucfirst(str_replace('_', ' ', $event)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="security-activity-filter">
                <label for="tic-activity-from"><i class="bi bi-calendar-event"></i> Desde</label>
                <input id="tic-activity-from" class="form-control" type="date" name="desde" value="{{ request('desde') }}">
            </div>
            <div class="security-activity-filter">
                <label for="tic-activity-to"><i class="bi bi-calendar-check"></i> Hasta</label>
                <input id="tic-activity-to" class="form-control" type="date" name="hasta" value="{{ request('hasta') }}">
            </div>
            <div class="security-activity-filter is-size">
                <label for="tic-activity-size"><i class="bi bi-list-ol"></i> Por página</label>
                <select id="tic-activity-size" class="form-select" name="per_page">
                    @foreach ([25, 50, 100] as $option)<option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>@endforeach
                </select>
            </div>
            <div class="security-activity-filter-actions">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Aplicar filtros</button>
                <a class="btn btn-outline-secondary" href="{{ $redmineRoute('redmine.native.section', 'actividad') }}"><i class="bi bi-arrow-counterclockwise"></i> Limpiar</a>
            </div>
        </form>

        <div class="security-activity-resultbar">
            <div><i class="bi bi-eye"></i> Mostrando {{ count($entries) }} de {{ number_format($total, 0, ',', '.') }} eventos <span class="security-activity-filtered">{{ $canViewAllActivity ? 'Todos los usuarios' : 'Solo mis registros' }}</span></div>
            @if ($canDeleteActivity)
                <form method="post" action="{{ $redmineRoute('redmine.native.activity.action') }}" data-app-confirm="¿Vaciar toda la bitácora de TIC?">
                    @csrf
                    <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash3"></i> Vaciar mi bitácora</button>
                </form>
            @endif
        </div>

        @if ($entries === [])
            <div class="nova-empty-state security-activity-empty"><i class="bi bi-search"></i><h3>Sin resultados</h3><p>No hay eventos que coincidan con los filtros seleccionados.</p></div>
        @else
            <div class="security-console-wrap">
                <div class="security-console-toolbar"><span class="security-console-dot" aria-hidden="true"></span><span>Actividad TIC :: página {{ $page }} de {{ $pages }}</span></div>
                <div class="table-responsive">
                    <table class="table align-middle security-console security-operational-console">
                        <thead><tr><th class="security-console-col-time">Fecha / hora</th><th>Usuario</th><th>Acción</th><th>Resultado</th><th>Detalle seguro</th></tr></thead>
                        <tbody>
                        @foreach ($entries as $entry)
                            @php
                                $userId = (string) ($entry['user_id'] ?? '');
                                $userLabel = trim((string) ($userNames[$userId] ?? '')) ?: ($userId !== '' ? 'Usuario ' . $userId : 'Sistema');
                                $result = (string) ($entry['result'] ?? 'info');
                            @endphp
                            <tr>
                                <td class="console-time">{{ $entry['ts'] ?? '-' }}</td>
                                <td class="console-user"><i class="bi {{ $userId !== '' ? 'bi-person-circle' : 'bi-cpu' }}"></i> {{ $userLabel }}</td>
                                <td><span class="console-tag">{{ $entry['action'] ?? 'Evento' }}</span></td>
                                <td><span class="security-result is-{{ $result }}"><i class="bi {{ $result === 'success' ? 'bi-check-circle' : ($result === 'error' ? 'bi-x-circle' : 'bi-info-circle') }}"></i>{{ $result === 'success' ? 'Correcto' : ($result === 'error' ? 'Error' : 'Informativo') }}</span></td>
                                <td class="console-details">{{ $entry['details'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($pages > 1)
                    <div class="security-activity-pagination"><span>Página {{ $page }} de {{ $pages }}</span><nav><a class="btn btn-sm btn-outline-light @if($page <= 1) disabled @endif" href="{{ $activityPageUrl($page - 1) }}">Anterior</a><a class="btn btn-sm btn-outline-light @if($page >= $pages) disabled @endif" href="{{ $activityPageUrl($page + 1) }}">Siguiente</a></nav></div>
                @endif
            </div>
        @endif
    </div>
</section>
