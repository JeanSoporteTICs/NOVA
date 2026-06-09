@php
    $query = request()->query();
    $h = static fn ($value): string => e((string) ($value ?? ''));

    $normDate = static function ($value): string {
        $value = trim((string) $value);
        if ($value === '') return '';
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    };

    $fmtDate = static function ($value) use ($normDate): string {
        $date = $normDate($value);
        if ($date === '') return trim((string) $value) ?: '-';
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dt ? $dt->format('d-m-Y') : $date;
    };

    $normalizeText = static function ($value): string {
        $value = strtolower(trim((string) $value));
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return is_string($converted) ? $converted : $value;
    };

    $redmineIssueUrl = static function ($redmineId) use ($config): string {
        $id = preg_replace('/\D+/', '', trim((string) $redmineId)) ?? '';
        if ($id === '') return '';
        $platformUrl = trim((string) ($config['platform_url'] ?? ''));
        $parts = parse_url($platformUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
        $path = (string) ($parts['path'] ?? '');
        $projectsPos = strpos($path, '/projects/');
        $prefix = $projectsPos === false ? '' : substr($path, 0, $projectsPos);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $base = rtrim($parts['scheme'] . '://' . $parts['host'] . $port . $prefix, '/');
        return $base . '/issues/' . rawurlencode($id);
    };

    $sourceValue = static function (array $row): string {
        if (!empty($row['_history_is_hours_extra'])) return 'horas_extra';
        return 'reportes';
    };

    $sourceLabel = static function (array $row): string {
        if (!empty($row['_history_is_hours_extra'])) return 'Horas extra';
        return (string) ($row['_history_type'] ?? 'Archivado');
    };

    $statusClass = static function ($value) use ($normalizeText): string {
        $status = $normalizeText($value);
        if (str_contains($status, 'manual')) return 'historico-status--manual';
        if (str_contains($status, 'gestionad') || str_contains($status, 'proces') || str_contains($status, 'cerrad')) return 'historico-status--managed';
        if (str_contains($status, 'error') || str_contains($status, 'rechaz')) return 'historico-status--danger';
        return 'historico-status--neutral';
    };

    $statusIcon = static function (string $class): string {
        return match ($class) {
            'historico-status--manual' => 'bi-pencil-square',
            'historico-status--managed' => 'bi-check2-circle',
            'historico-status--danger' => 'bi-exclamation-triangle',
            default => 'bi-info-circle',
        };
    };

    $fDesde = $normDate($query['desde'] ?? '');
    $fHasta = $normDate($query['hasta'] ?? '');
    $fFuente = trim((string) ($query['fuente'] ?? ''));
    $fBusqueda = trim((string) ($query['buscar'] ?? ''));
    $fCategoria = trim((string) ($query['categoria'] ?? ''));
    $perPageOptions = [25, 50, 100];
    $perPage = (int) ($query['per_page'] ?? 25);
    if (!in_array($perPage, $perPageOptions, true)) $perPage = 25;
    $currentPage = max(1, (int) ($query['page'] ?? 1));

    $categories = [];
    $filtered = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $date = $normDate($row['fecha_inicio'] ?? $row['fecha'] ?? $row['_history_sort_date'] ?? '');
        $source = $sourceValue($row);
        $category = trim((string) ($row['categoria'] ?? $row['core_categoria'] ?? ''));
        if ($category !== '') $categories[$category] = $category;
        if ($date !== '' && $fDesde !== '' && $date < $fDesde) continue;
        if ($date !== '' && $fHasta !== '' && $date > $fHasta) continue;
        if ($fFuente !== '' && $source !== $fFuente) continue;
        if ($fCategoria !== '' && $category !== $fCategoria) continue;
        if ($fBusqueda !== '') {
            $needle = $normalizeText($fBusqueda);
            $haystack = $normalizeText(implode(' ', [
                $row['redmine_id'] ?? '',
                $row['asunto'] ?? '',
                $row['mensaje'] ?? '',
                $row['solicitante'] ?? '',
                $row['unidad_solicitante'] ?? '',
                $row['unidad'] ?? '',
                $row['asignado_nombre'] ?? '',
                $row['asignado_a'] ?? '',
                $category,
            ]));
            if ($needle !== '' && !str_contains($haystack, $needle)) continue;
        }
        $row['_history_date_norm'] = $date;
        $filtered[] = $row;
    }
    ksort($categories);

    $totalFiltered = count($filtered);
    $totalPages = max(1, (int) ceil($totalFiltered / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $pageOffset = ($currentPage - 1) * $perPage;
    $pagedRows = array_slice($filtered, $pageOffset, $perPage);
    $rangeStart = $totalFiltered > 0 ? $pageOffset + 1 : 0;
    $rangeEnd = min($totalFiltered, $pageOffset + count($pagedRows));
    $hoursRows = count(array_filter($filtered, static fn ($row): bool => is_array($row) && !empty($row['_history_is_hours_extra'])));
    $archivedRows = max(0, $totalFiltered - $hoursRows);

    $baseHistoryUrl = $redmineRoute('redmine.native.section', ['section' => 'historico']);
    $urlWithQuery = static function (array $changes = []) use ($query, $baseHistoryUrl): string {
        $next = array_merge($query, $changes);
        foreach ($next as $key => $value) {
            if ($value === '' || $value === null) unset($next[$key]);
        }
        return $baseHistoryUrl . ($next ? '?' . http_build_query($next) : '');
    };
    $pageUrl = static fn (int $page): string => $urlWithQuery(['page' => max(1, $page), 'per_page' => $perPage]);
    $chipUrl = static function (string $key) use ($query, $baseHistoryUrl, $perPage): string {
        $next = $query;
        unset($next[$key]);
        $next['page'] = 1;
        $next['per_page'] = $perPage;
        return $baseHistoryUrl . '?' . http_build_query($next);
    };

    $chips = [];
    if ($fDesde !== '') $chips[] = ['icon' => 'bi-calendar-event', 'label' => 'Desde ' . $fmtDate($fDesde), 'remove' => 'desde'];
    if ($fHasta !== '') $chips[] = ['icon' => 'bi-calendar-check', 'label' => 'Hasta ' . $fmtDate($fHasta), 'remove' => 'hasta'];
    if ($fFuente !== '') $chips[] = ['icon' => 'bi-inboxes', 'label' => 'Fuente ' . ($fFuente === 'horas_extra' ? 'Horas extra' : 'Reportes'), 'remove' => 'fuente'];
    if ($fBusqueda !== '') $chips[] = ['icon' => 'bi-search', 'label' => 'Busqueda ' . $fBusqueda, 'remove' => 'buscar'];
    if ($fCategoria !== '') $chips[] = ['icon' => 'bi-tags', 'label' => 'Categoria ' . $fCategoria, 'remove' => 'categoria'];

    $tableColspan = 12;
@endphp

<style>
    .historico-filter-card { border: 1px solid rgba(215,226,239,.95); background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(248,250,255,.92)); }
    .historico-filter-card .btn { min-height: 48px; white-space: nowrap; }
    .historico-summary { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; padding: 1rem 1.15rem; border-bottom: 1px solid rgba(15,23,42,.08); background: rgba(248,250,255,.9); }
    .historico-count { display: inline-flex; align-items: center; gap: .5rem; color: #0f172a; font-weight: 900; }
    .historico-summary__tools { display: flex; align-items: center; gap: .9rem; flex-wrap: wrap; justify-content: flex-end; }
    .historico-filter-chips { display: flex; flex-wrap: wrap; gap: .5rem; padding: .85rem 1.15rem; border-bottom: 1px solid rgba(15,23,42,.06); background: rgba(255,255,255,.72); }
    .historico-filter-chip { display: inline-flex; align-items: center; gap: .38rem; min-height: 32px; padding: .36rem .62rem; border-radius: 999px; border: 1px solid rgba(37,99,235,.18); background: rgba(37,99,235,.08); color: #1d4ed8; font-size: .82rem; font-weight: 900; text-decoration: none; }
    .historico-filter-chip:hover { background: #2563eb; color: #fff; }
    .historico-redmine-sync { padding: .9rem 1.15rem; border-bottom: 1px solid rgba(15,23,42,.08); background: #eff6ff; }
    .historico-redmine-sync__header { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: .55rem; color: #1d4ed8; font-weight: 900; }
    .historico-table-card { overflow: hidden; }
    .historico-table-card.is-compact .historico-table { font-size: .84rem; }
    .historico-table-card.is-compact .historico-table th,
    .historico-table-card.is-compact .historico-table td { padding-top: .45rem; padding-bottom: .45rem; }
    .historico-table { min-width: 1320px; }
    .historico-table th { white-space: nowrap; vertical-align: middle; }
    .historico-table td { vertical-align: middle; font-weight: 650; }
    .historico-date { display: inline-flex; align-items: center; gap: .35rem; min-width: 108px; color: #1e3a8a; font-weight: 900; }
    .historico-redmine-link { display: inline-flex; align-items: center; gap: .35rem; min-height: 30px; padding: .28rem .62rem; border-radius: 999px; border: 1px solid #bfdbfe; background: #eff6ff; color: #1d4ed8; font-weight: 900; text-decoration: none; }
    .historico-redmine-link:hover { background: #2563eb; color: #fff; }
    .historico-source-badge { display: inline-flex; align-items: center; gap: .35rem; border-radius: 999px; padding: .4rem .7rem; background: rgba(56,189,248,.14); color: #075985; border: 1px solid rgba(56,189,248,.22); font-weight: 900; white-space: nowrap; }
    .historico-source-badge.is-hours { background: #ecfdf5; color: #166534; border-color: rgba(34,197,94,.24); }
    .historico-status { display: inline-flex; align-items: center; gap: .35rem; max-width: 150px; border-radius: 999px; padding: .35rem .65rem; font-size: .78rem; font-weight: 900; }
    .historico-status--manual { background: #fff7ed; color: #9a3412; border: 1px solid rgba(249,115,22,.24); }
    .historico-status--managed { background: #ecfdf5; color: #047857; border: 1px solid rgba(16,185,129,.24); }
    .historico-status--danger { background: #fef2f2; color: #b91c1c; border: 1px solid rgba(239,68,68,.24); }
    .historico-status--neutral { background: #f8fafc; color: #334155; border: 1px solid #d7e2ef; }
    .historico-redmine-status { display: inline-flex; align-items: center; gap: .35rem; min-height: 32px; padding: .32rem .62rem; border-radius: 999px; font-size: .78rem; font-weight: 900; white-space: nowrap; }
    .historico-redmine-status small { opacity: .8; font-weight: 800; }
    .historico-redmine-status--syncing { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .historico-redmine-status--closed { background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; }
    .historico-redmine-status--open,
    .historico-redmine-status--new,
    .historico-redmine-status--progress { background: #ecfdf5; color: #166534; border: 1px solid rgba(34,197,94,.24); }
    .historico-redmine-status--resolved { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .historico-redmine-status--rejected,
    .historico-redmine-status--unknown { background: #f8fafc; color: #475569; border: 1px solid #d7e2ef; }
    .historico-pagination { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: .9rem 1.1rem; border-top: 1px solid #d7e2ef; background: #f8fafc; }
    .historico-pagination__left,
    .historico-page-size-form { display: flex; align-items: center; gap: .55rem; flex-wrap: wrap; }
    .historico-page-size-form .form-select { width: 86px; }
    .historico-pagination .page-link { min-width: 34px; min-height: 34px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; font-weight: 900; }
    .historico-pagination .page-item.active .page-link { background: #2563eb; border-color: #2563eb; color: #fff; box-shadow: 0 10px 18px rgba(37,99,235,.2); }
    .loader-overlay { position: absolute; inset: 0; z-index: 3; display: grid; place-items: center; background: rgba(248,250,252,.74); backdrop-filter: blur(2px); }
</style>

<form id="filter-form" class="card card-body shadow-sm mb-3 historico-filter-card" method="get" action="{{ $baseHistoryUrl }}" aria-live="polite">
    <input type="hidden" name="page" value="1">
    <input type="hidden" name="per_page" value="{{ $perPage }}">
    <div class="row g-3 align-items-end">
        <div class="col-md-2">
            <label class="form-label fw-bold" for="history-desde">Desde</label>
            <input id="history-desde" class="form-control" type="date" name="desde" value="{{ $fDesde }}">
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold" for="history-hasta">Hasta</label>
            <input id="history-hasta" class="form-control" type="date" name="hasta" value="{{ $fHasta }}">
        </div>
        <div class="col-md-2">
            <label class="form-label fw-bold" for="history-fuente">Fuente</label>
            <select id="history-fuente" class="form-select" name="fuente">
                <option value="">Todas</option>
                <option value="reportes" @selected($fFuente === 'reportes')>Reportes</option>
                <option value="horas_extra" @selected($fFuente === 'horas_extra')>Horas extra</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold" for="history-buscar">Buscar</label>
            <input id="history-buscar" class="form-control" type="search" name="buscar" value="{{ $fBusqueda }}" placeholder="ID, asunto, solicitante, unidad">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold" for="history-categoria">Categoria</label>
            <select id="history-categoria" class="form-select" name="categoria">
                <option value="">Todas</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected($fCategoria === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" id="btn-apply" class="btn btn-primary w-100"><i class="bi bi-funnel"></i>Filtrar</button>
        </div>
        <div class="col-md-2">
            <a class="btn btn-outline-secondary w-100" href="{{ $baseHistoryUrl }}"><i class="bi bi-x-circle"></i>Limpiar</a>
        </div>
    </div>
    <div id="filter-feedback" class="d-none mt-3 alert alert-info d-flex align-items-center" role="status" aria-live="polite">
        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
        Aplicando filtros...
    </div>
</form>

<div class="card shadow-sm historico-table-card" id="historico-table-card">
    <div class="historico-summary">
        <div>
            <span class="historico-count"><i class="bi bi-clock-history text-primary"></i>{{ $totalFiltered }} registros</span>
            <span class="text-muted ms-2">Mostrando {{ $rangeStart }}-{{ $rangeEnd }} de {{ $totalFiltered }}</span>
        </div>
        <div class="historico-summary__tools">
            <span class="historico-source-badge"><i class="bi bi-archive"></i>Archivados: {{ $archivedRows }}</span>
            <label class="form-check form-switch m-0">
                <input class="form-check-input" type="checkbox" role="switch" id="historico-compact-toggle">
                <span class="form-check-label fw-semibold">Modo compacto</span>
            </label>
            <div class="text-muted small fw-bold">Pagina {{ $currentPage }} de {{ $totalPages }}</div>
        </div>
    </div>
    @if (!empty($chips))
        <div class="historico-filter-chips" aria-label="Filtros activos">
            @foreach ($chips as $chip)
                <a class="historico-filter-chip" href="{{ $chipUrl($chip['remove']) }}" title="Quitar filtro">
                    <i class="bi {{ $chip['icon'] }}"></i>{{ $chip['label'] }}<i class="bi bi-x"></i>
                </a>
            @endforeach
        </div>
    @endif
    <div id="redmine-sync-panel" class="historico-redmine-sync d-none" role="status" aria-live="polite">
        <div class="historico-redmine-sync__header">
            <span><i class="bi bi-arrow-repeat"></i> Sincronizando estados con Redmine</span>
            <strong id="redmine-sync-count">0/0</strong>
        </div>
        <div class="progress" aria-hidden="true">
            <div id="redmine-sync-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
        </div>
    </div>
    <div class="card-body p-0 position-relative">
        <div class="table-responsive position-relative">
            <div id="table-loader" class="loader-overlay d-none" role="status" aria-live="polite">
                <div class="d-flex align-items-center gap-2">
                    <span class="spinner-border spinner-border-lg text-primary" role="status" aria-hidden="true"></span>
                    <strong>Cargando registros...</strong>
                </div>
            </div>
            <table class="table table-hover historico-table align-middle mb-0" role="grid" aria-label="Historico de reportes" aria-busy="false">
                <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>Redmine ID</th>
                        <th>Estado Redmine</th>
                        <th>Solicitante</th>
                        <th>Categoria</th>
                        <th>Establecimiento</th>
                        <th>Departamento</th>
                        <th>Asignado CORE</th>
                        <th>Estado CORE</th>
                        <th>Fuente</th>
                        <th>Detalle</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pagedRows as $row)
                        @php
                            $redmineId = trim((string) ($row['redmine_id'] ?? ''));
                            $issueUrl = $redmineIssueUrl($redmineId);
                            $source = $sourceValue($row);
                            $sourceText = $sourceLabel($row);
                            $coreEstado = trim((string) ($row['core_estado'] ?? $row['estado'] ?? ''));
                            $coreClass = $statusClass($coreEstado);
                            $detail = [
                                'asunto' => $row['asunto'] ?? $row['mensaje'] ?? '',
                                'solicitante' => $row['solicitante'] ?? '',
                                'descripcion' => $row['descripcion'] ?? $row['mensaje'] ?? '',
                                'redmine_id' => $redmineId,
                                'categoria' => $row['categoria'] ?? '',
                                'establecimiento' => $row['core_establecimiento'] ?? $row['unidad_solicitante'] ?? '',
                                'departamento' => $row['core_departamento'] ?? $row['unidad'] ?? '',
                                'asignado' => $row['core_usuario_asignado'] ?? $row['asignado_nombre'] ?? $row['asignado_a'] ?? '',
                                'estado' => $coreEstado,
                                'fuente' => $sourceText,
                            ];
                        @endphp
                        <tr>
                            <td><span class="historico-date"><i class="bi bi-calendar3"></i>{{ $fmtDate($row['_history_date_norm'] ?? $row['fecha_inicio'] ?? $row['fecha'] ?? '') }}</span></td>
                            <td>
                                @if ($redmineId !== '' && $issueUrl !== '')
                                    <a class="historico-redmine-link" href="{{ $issueUrl }}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>{{ $redmineId }}</a>
                                @else
                                    <span class="text-muted">{{ $redmineId !== '' ? $redmineId : '-' }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($redmineId !== '')
                                    <span class="historico-redmine-status historico-redmine-status--syncing js-redmine-status" data-redmine-id="{{ $redmineId }}" title="Sincronizando con Redmine">
                                        <i class="bi bi-arrow-repeat"></i><span>Sincronizando</span>
                                    </span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="text-truncate" style="max-width: 160px;" title="{{ $row['solicitante'] ?? '' }}">{{ $row['solicitante'] ?? '-' }}</td>
                            <td class="text-truncate" style="max-width: 200px;" title="{{ $row['categoria'] ?? '' }}">{{ $row['categoria'] ?? '-' }}</td>
                            <td class="text-truncate" style="max-width: 150px;" title="{{ $row['core_establecimiento'] ?? ($row['unidad_solicitante'] ?? '') }}">{{ $row['core_establecimiento'] ?? ($row['unidad_solicitante'] ?? '-') }}</td>
                            <td class="text-truncate" style="max-width: 150px;" title="{{ $row['core_departamento'] ?? ($row['unidad'] ?? '') }}">{{ $row['core_departamento'] ?? ($row['unidad'] ?? '-') }}</td>
                            <td class="text-truncate" style="max-width: 150px;" title="{{ $row['core_usuario_asignado'] ?? ($row['asignado_nombre'] ?? ($row['asignado_a'] ?? '')) }}">{{ $row['core_usuario_asignado'] ?? ($row['asignado_nombre'] ?? ($row['asignado_a'] ?? '-')) }}</td>
                            <td>
                                <span class="historico-status {{ $coreClass }} text-truncate" title="{{ $coreEstado }}">
                                    <i class="bi {{ $statusIcon($coreClass) }}"></i>{{ $coreEstado !== '' ? $coreEstado : 'Sin estado' }}
                                </span>
                            </td>
                            <td><span class="historico-source-badge {{ $source === 'horas_extra' ? 'is-hours' : '' }}">{{ $sourceText }}</span></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary historico-detail-btn" data-bs-toggle="modal" data-bs-target="#historicoDetalleModal" data-detail='@json($detail)'>
                                    <i class="bi bi-eye"></i>
                                </button>
                            </td>
                            <td>
                                @if (!empty($row['_history_can_delete']))
                                    <form method="post" action="{{ $redmineRoute('redmine.native.history.action') }}" class="m-0" data-app-confirm="Eliminar este registro del historico?">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $row['id'] ?? '' }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $tableColspan }}" class="text-center text-muted py-4">Sin registros para el criterio seleccionado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @php
            $windowStart = max(1, $currentPage - 2);
            $windowEnd = min($totalPages, $currentPage + 2);
        @endphp
        <nav class="historico-pagination" aria-label="Paginacion historico">
            <div class="historico-pagination__left">
                <form method="get" action="{{ $baseHistoryUrl }}" class="historico-page-size-form">
                    @foreach (['desde' => $fDesde, 'hasta' => $fHasta, 'fuente' => $fFuente, 'buscar' => $fBusqueda, 'categoria' => $fCategoria] as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <input type="hidden" name="page" value="1">
                    <label for="historico-per-page" class="form-label mb-0">Mostrar</label>
                    <select id="historico-per-page" name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
                        @foreach ($perPageOptions as $option)
                            <option value="{{ $option }}" @selected($option === $perPage)>{{ $option }}</option>
                        @endforeach
                    </select>
                    <span>registros</span>
                </form>
                <div class="text-muted fw-bold">{{ $rangeStart }}-{{ $rangeEnd }} de {{ $totalFiltered }} registros</div>
            </div>
            @if ($totalPages > 1)
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item {{ $currentPage <= 1 ? 'disabled' : '' }}"><a class="page-link" href="{{ $pageUrl(1) }}" aria-label="Primera">&laquo;</a></li>
                    <li class="page-item {{ $currentPage <= 1 ? 'disabled' : '' }}"><a class="page-link" href="{{ $pageUrl($currentPage - 1) }}">Anterior</a></li>
                    @for ($page = $windowStart; $page <= $windowEnd; $page++)
                        <li class="page-item {{ $page === $currentPage ? 'active' : '' }}"><a class="page-link" href="{{ $pageUrl($page) }}">{{ $page }}</a></li>
                    @endfor
                    <li class="page-item {{ $currentPage >= $totalPages ? 'disabled' : '' }}"><a class="page-link" href="{{ $pageUrl($currentPage + 1) }}">Siguiente</a></li>
                    <li class="page-item {{ $currentPage >= $totalPages ? 'disabled' : '' }}"><a class="page-link" href="{{ $pageUrl($totalPages) }}" aria-label="Ultima">&raquo;</a></li>
                </ul>
            @endif
        </nav>
    </div>
</div>

<div class="modal fade detail-drawer-modal" id="historicoDetalleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable detail-drawer-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <p class="detail-drawer-kicker">Registro archivado</p>
                    <h5 class="modal-title">
                        <span class="detail-drawer-icon"><i class="bi bi-archive"></i></span>
                        Detalle historico
                    </h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="detail-drawer-panel mb-3">
                    <div class="fw-bold fs-5" id="historico-detalle-titulo"></div>
                    <div class="text-muted small" id="historico-detalle-solicitante"></div>
                </div>
                <div class="table-responsive detail-drawer-panel mb-3">
                    <table class="table table-sm mb-0 align-middle">
                        <tbody id="historico-detalle-body"></tbody>
                    </table>
                </div>
                <div class="detail-drawer-panel">
                    <label for="historico-detalle-descripcion" class="form-label fw-bold">Descripcion</label>
                    <textarea id="historico-detalle-descripcion" class="form-control" rows="8" readonly></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i>Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('filter-form');
    const feedback = document.getElementById('filter-feedback');
    const table = document.querySelector('table[role="grid"]');
    const loader = document.getElementById('table-loader');
    const btnApply = document.getElementById('btn-apply');
    const setLoading = state => {
        feedback?.classList.toggle('d-none', !state);
        loader?.classList.toggle('d-none', !state);
        table?.setAttribute('aria-busy', state ? 'true' : 'false');
        if (btnApply) btnApply.disabled = state;
    };
    form?.addEventListener('submit', event => {
        event.preventDefault();
        setLoading(true);
        setTimeout(() => form.submit(), 60);
    });

    const card = document.getElementById('historico-table-card');
    const compactToggle = document.getElementById('historico-compact-toggle');
    const compactKey = 'redmine-tic-historico-compact';
    if (card && compactToggle) {
        const saved = localStorage.getItem(compactKey) === '1';
        card.classList.toggle('is-compact', saved);
        compactToggle.checked = saved;
        compactToggle.addEventListener('change', () => {
            card.classList.toggle('is-compact', compactToggle.checked);
            localStorage.setItem(compactKey, compactToggle.checked ? '1' : '0');
        });
    }

    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const normalizeStatus = value => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const redmineStatusTone = statusName => {
        const key = normalizeStatus(statusName);
        if (key.includes('nueva') || key.includes('new')) return 'historico-redmine-status--new';
        if (key.includes('curso') || key.includes('progress') || key.includes('proceso')) return 'historico-redmine-status--progress';
        if (key.includes('resuelt') || key.includes('resolved')) return 'historico-redmine-status--resolved';
        if (key.includes('rechaz') || key.includes('reject')) return 'historico-redmine-status--rejected';
        return 'historico-redmine-status--open';
    };
    const setBadgeStatus = (badge, status) => {
        const available = Boolean(status && status.available);
        const closed = Boolean(status && status.closed);
        const statusName = String((status && status.name) || '');
        const message = String((status && status.message) || '');
        const cssClass = !available ? 'historico-redmine-status--unknown' : (closed ? 'historico-redmine-status--closed' : redmineStatusTone(statusName));
        const iconClass = !available ? 'bi-question-circle' : (closed ? 'bi-lock-fill' : 'bi-folder2-open');
        const label = !available ? 'No disponible' : (closed ? 'Cerrado' : 'Abierto');
        const detail = available && !closed && statusName ? `<small>${escapeHtml(statusName)}</small>` : '';
        badge.className = `historico-redmine-status js-redmine-status ${cssClass}`;
        badge.title = available ? `Redmine: ${statusName}` : message;
        badge.innerHTML = `<i class="bi ${iconClass}"></i><span>${escapeHtml(label)}</span>${detail}`;
    };

    const statusBadges = Array.from(document.querySelectorAll('.js-redmine-status[data-redmine-id]'));
    const syncPanel = document.getElementById('redmine-sync-panel');
    const syncBar = document.getElementById('redmine-sync-bar');
    const syncCount = document.getElementById('redmine-sync-count');
    const syncRedmineStatuses = async () => {
        const ids = [...new Set(statusBadges.map(badge => badge.getAttribute('data-redmine-id')).filter(Boolean))];
        if (!ids.length) return;
        const chunkSize = 5;
        let done = 0;
        syncPanel?.classList.remove('d-none');
        if (syncCount) syncCount.textContent = `0/${ids.length}`;
        if (syncBar) syncBar.style.width = '0%';
        for (let index = 0; index < ids.length; index += chunkSize) {
            const chunk = ids.slice(index, index + chunkSize);
            try {
                const response = await fetch(`{{ $redmineRoute('redmine.native.history.statuses') }}?ids=${encodeURIComponent(chunk.join(','))}`, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store',
                });
                const payload = await response.json();
                const statuses = payload && payload.statuses ? payload.statuses : {};
                chunk.forEach(id => {
                    document.querySelectorAll(`.js-redmine-status[data-redmine-id="${CSS.escape(id)}"]`).forEach(badge => {
                        setBadgeStatus(badge, statuses[id] || { available: false, message: 'Sin respuesta desde Redmine' });
                    });
                });
            } catch (error) {
                chunk.forEach(id => {
                    document.querySelectorAll(`.js-redmine-status[data-redmine-id="${CSS.escape(id)}"]`).forEach(badge => {
                        setBadgeStatus(badge, { available: false, message: 'No se pudo sincronizar con Redmine' });
                    });
                });
            }
            done += chunk.length;
            const percent = Math.min(100, Math.round((done / ids.length) * 100));
            if (syncCount) syncCount.textContent = `${Math.min(done, ids.length)}/${ids.length}`;
            if (syncBar) syncBar.style.width = `${percent}%`;
        }
        setTimeout(() => syncPanel?.classList.add('d-none'), 1200);
    };
    syncRedmineStatuses();

    const modal = document.getElementById('historicoDetalleModal');
    modal?.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        if (!button) return;
        let detail = {};
        try { detail = JSON.parse(button.getAttribute('data-detail') || '{}'); } catch (error) { detail = {}; }
        document.getElementById('historico-detalle-titulo').textContent = detail.asunto || 'Detalle historico';
        document.getElementById('historico-detalle-solicitante').textContent = detail.solicitante ? `Solicitante: ${detail.solicitante}` : '';
        document.getElementById('historico-detalle-descripcion').value = detail.descripcion || '';
        const body = document.getElementById('historico-detalle-body');
        const labels = {
            redmine_id: 'Redmine ID',
            categoria: 'Categoria',
            establecimiento: 'Establecimiento',
            departamento: 'Departamento',
            asignado: 'Asignado CORE',
            estado: 'Estado CORE',
            fuente: 'Fuente',
        };
        body.innerHTML = Object.entries(labels).map(([key, label]) => `
            <tr><th class="table-light" style="width: 36%;">${escapeHtml(label)}</th><td>${escapeHtml(detail[key] || '-')}</td></tr>
        `).join('');
    });
});
</script>
