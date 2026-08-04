@extends('redmine_mantencion::native.layout')

@section('content')
<div class="container-fluid py-4">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-shield-lock',
        'title' => 'Actividad reciente',
        'subtitle' => 'Consultas, movimientos, accesos y eventos operativos registrados en Mantención.',
    ])

    @if (session('mantencion_status'))
        <div data-nova-flash="{{ session('mantencion_status_type', 'success') }}" data-nova-flash-message="{{ session('mantencion_status') }}" hidden></div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <div class="security-activity-intro">
                <div>
                    <span class="security-activity-eyebrow"><i class="bi bi-database-check"></i> Auditoría en base de datos</span>
                    <p class="text-muted mb-0">Consulta accesos, cambios, importaciones, envíos a Redmine y eventos operativos de Mantención.</p>
                </div>
                <div class="security-activity-total">
                    <strong>{{ number_format($totalEvents, 0, ',', '.') }}</strong>
                    <span>{{ $hasFilters ? 'coincidencias' : 'eventos registrados' }}</span>
                </div>
            </div>

            <form method="GET" action="{{ route('redmine.mantencion.activity') }}" class="security-activity-filters" aria-label="Filtros de actividad">
                <div class="security-activity-filter is-search">
                    <label for="activity-search"><i class="bi bi-search"></i> Buscar</label>
                    <input id="activity-search" name="buscar" class="form-control" type="search" value="{{ $filters['buscar'] }}" placeholder="Detalle, evento, canal o ID">
                </div>
                <div class="security-activity-filter">
                    <label for="activity-tag"><i class="bi bi-tag"></i> Evento</label>
                    <select id="activity-tag" name="tag" class="form-select">
                        <option value="">Todos los eventos</option>
                        <option value="NEXTCLOUD" @selected($filters['tag'] === 'NEXTCLOUD')>Todos los eventos Nextcloud</option>
                        @foreach ($eventTags as $tag)
                            <option value="{{ $tag }}" @selected($filters['tag'] === strtoupper((string) $tag))>{{ $tag }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="security-activity-filter">
                    <label for="activity-channel"><i class="bi bi-diagram-3"></i> Canal</label>
                    <select id="activity-channel" name="canal" class="form-select">
                        <option value="">Todos los canales</option>
                        @foreach ($eventChannels as $channel)
                            <option value="{{ $channel }}" @selected($filters['canal'] === strtolower((string) $channel))>{{ ucfirst((string) $channel) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="security-activity-filter">
                    <label for="activity-from"><i class="bi bi-calendar-event"></i> Desde</label>
                    <input id="activity-from" name="desde" class="form-control" type="date" value="{{ $filters['desde'] }}">
                </div>
                <div class="security-activity-filter">
                    <label for="activity-to"><i class="bi bi-calendar-check"></i> Hasta</label>
                    <input id="activity-to" name="hasta" class="form-control" type="date" value="{{ $filters['hasta'] }}">
                </div>
                <div class="security-activity-filter is-size">
                    <label for="activity-per-page"><i class="bi bi-list-ol"></i> Por página</label>
                    <select id="activity-per-page" name="per_page" class="form-select">
                        @foreach ([25, 50, 100] as $option)
                            <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="security-activity-filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Aplicar filtros</button>
                    <a href="{{ route('redmine.mantencion.activity') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Limpiar</a>
                </div>
            </form>

            <div class="security-activity-resultbar">
                <div>
                    <i class="bi bi-eye"></i>
                    Mostrando {{ count($events) }} de {{ number_format($totalEvents, 0, ',', '.') }} eventos
                    <span class="security-activity-filtered">{{ $canViewAll ? 'Todos los usuarios' : 'Solo mis registros' }}</span>
                    @if ($hasFilters)<span class="security-activity-filtered">Filtros activos</span>@endif
                </div>
                @if ($canDelete)
                    <form method="POST" action="{{ route('redmine.mantencion.activity.clear') }}" class="mb-0" data-app-confirm="¿Eliminar toda la actividad reciente? Esta acción no se puede deshacer.">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger btn-sm" @disabled(!empty($context['maintenance']['enabled']))>
                            <i class="bi bi-trash3"></i> Vaciar mi bitácora
                        </button>
                    </form>
                @endif
            </div>

            @if ($events === [])
                <div class="nova-empty-state security-activity-empty">
                    <i class="bi bi-search"></i>
                    <h3>{{ $hasFilters ? 'No hay coincidencias' : 'Todavía no hay eventos' }}</h3>
                    <p>{{ $hasFilters ? 'Prueba ampliando las fechas o quitando alguno de los filtros.' : 'Los nuevos eventos de Mantención aparecerán aquí.' }}</p>
                    @if ($hasFilters)<a href="{{ route('redmine.mantencion.activity') }}" class="btn btn-outline-primary"><i class="bi bi-arrow-counterclockwise"></i> Limpiar filtros</a>@endif
                </div>
            @else
                <div class="security-console-wrap">
                    <div class="security-console-toolbar">
                        <span class="security-console-dot" aria-hidden="true"></span>
                        <span>Actividad Mantención :: página {{ $page }} de {{ $totalPages }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle security-console security-operational-console">
                            <thead><tr><th>Fecha / hora</th><th>Usuario</th><th>Acción</th><th>Resultado</th><th>Detalle seguro</th></tr></thead>
                            <tbody>
                            @foreach ($events as $event)
                                @php
                                    try {
                                        $eventTime = \Illuminate\Support\Carbon::parse($event['ts'])->timezone('America/Santiago')->format('d-m-Y H:i:s');
                                    } catch (\Throwable) {
                                        $eventTime = $event['ts'];
                                    }
                                    $result = $event['result'] ?? 'info';
                                @endphp
                                <tr>
                                    <td class="console-time">{{ $eventTime ?: '----' }}</td>
                                    <td class="console-user"><i class="bi {{ ($event['user'] ?? '') === 'Sistema' ? 'bi-cpu' : 'bi-person-circle' }}"></i> {{ $event['user'] ?? 'Sistema' }}</td>
                                    <td><span class="console-tag" title="{{ $event['tag'] ?? '' }}">{{ $event['action'] ?? 'Evento' }}</span></td>
                                    <td><span class="security-result is-{{ $result }}"><i class="bi {{ $result === 'success' ? 'bi-check-circle' : ($result === 'error' ? 'bi-x-circle' : 'bi-info-circle') }}"></i>{{ $result === 'success' ? 'Correcto' : ($result === 'error' ? 'Error' : 'Informativo') }}</span></td>
                                    <td class="console-details">{{ $event['details'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($totalPages > 1)
                        <div class="security-activity-pagination">
                            <span>Página {{ $page }} de {{ $totalPages }}</span>
                            <nav aria-label="Paginación de actividad">
                                <a class="btn btn-sm btn-outline-light {{ $page <= 1 ? 'disabled' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => max(1, $page - 1)]) }}" @if($page <= 1) aria-disabled="true" tabindex="-1" @endif><i class="bi bi-chevron-left"></i> Anterior</a>
                                <a class="btn btn-sm btn-outline-light {{ $page >= $totalPages ? 'disabled' : '' }}" href="{{ request()->fullUrlWithQuery(['page' => min($totalPages, $page + 1)]) }}" @if($page >= $totalPages) aria-disabled="true" tabindex="-1" @endif>Siguiente <i class="bi bi-chevron-right"></i></a>
                            </nav>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
