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
        return \RedmineTic\Support\RedmineUrlSupport::redmineIssueUrl(
            (string) ($config['platform_url'] ?? ''),
            (string) $redmineId
        );
    };

    $sourceValue = static function (array $row): string {
        $origin = strtolower(trim((string) ($row['origen'] ?? '')));
        if ($origin === 'telegram' || trim((string) ($row['chat_id_telegram'] ?? '')) !== '') return 'telegram';
        return 'manual';
    };

    $sourceLabel = static fn (array $row): string => $sourceValue($row) === 'telegram' ? 'Telegram' : 'Manual';
    $sourceIcon = static fn (array $row): string => $sourceValue($row) === 'telegram' ? 'bi-telegram' : 'bi-pencil-square';

    $fDesde = $normDate($query['desde'] ?? '');
    $fHasta = $normDate($query['hasta'] ?? '');
    $fFuente = trim((string) ($query['fuente'] ?? ''));
    $fBusqueda = trim((string) ($query['buscar'] ?? ''));
    $fDescripcion = trim((string) ($query['descripcion'] ?? ''));
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
        if ($fDescripcion !== '') {
            $descriptionNeedle = $normalizeText($fDescripcion);
            $descriptionText = $normalizeText($row['descripcion'] ?? '');
            if ($descriptionNeedle !== '' && !str_contains($descriptionText, $descriptionNeedle)) continue;
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
    $visibleRows = count($pagedRows);
    $hoursRows = count(array_filter($filtered, static fn ($row): bool => is_array($row) && !empty($row['_history_is_hours_extra'])));
    $archivedRows = max(0, $totalFiltered - $hoursRows);
    $canHistoryActions = empty($redmineMaintenance['enabled'])
        && !empty($canHistoryActionsPermission);
    $historyScope = !empty($effectivePermissions['all'])
        || strtolower(trim((string) ($effectivePermissions['historico_scope'] ?? $effectivePermissions['historico'] ?? ''))) === 'todos'
            ? 'Todos'
            : 'Solo asignados';
    $redmineStatusOptions = [];
    foreach ((array) ($config['estados'] ?? []) as $statusOption) {
        if (!is_array($statusOption)) continue;
        $statusId = filter_var($statusOption['id'] ?? null, FILTER_VALIDATE_INT);
        $statusName = trim((string) ($statusOption['nombre'] ?? $statusOption['name'] ?? ''));
        if ($statusId === false || $statusId <= 0 || $statusName === '') continue;
        $redmineStatusOptions[$statusId] = ['id' => $statusId, 'name' => $statusName];
    }
    $redmineStatusOptions = array_values($redmineStatusOptions);

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
    if ($fFuente !== '') $chips[] = ['icon' => 'bi-inboxes', 'label' => 'Fuente ' . ($fFuente === 'telegram' ? 'Telegram' : 'Manual'), 'remove' => 'fuente'];
    if ($fBusqueda !== '') $chips[] = ['icon' => 'bi-search', 'label' => 'Busqueda ' . $fBusqueda, 'remove' => 'buscar'];
    if ($fDescripcion !== '') $chips[] = ['icon' => 'bi-card-text', 'label' => 'Descripcion ' . $fDescripcion, 'remove' => 'descripcion'];
    if ($fCategoria !== '') $chips[] = ['icon' => 'bi-tags', 'label' => 'Categoria ' . $fCategoria, 'remove' => 'categoria'];

    $tableColspan = $canHistoryActions ? 10 : 9;
@endphp

<section class="rm-module-head">
    <span class="rm-module-head-icon is-green"><i class="bi bi-archive"></i></span>
    <div>
        <small>Registro historico</small>
        <h2>Historico</h2>
        <p>Consulta reportes archivados y horas extra con filtros de fecha, fuente y categoria.</p>
    </div>
    <div class="rm-module-meter">
        <strong>{{ $totalFiltered }}</strong>
        <span>resultados</span>
    </div>
</section>

@php $redmineTicHistoryCssVersion = @filemtime(public_path('assets/redmine-tic-history.css')) ?: '1'; @endphp
<link href="{{ asset('assets/redmine-tic-history.css') }}?v={{ $redmineTicHistoryCssVersion }}" rel="stylesheet">

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
                <option value="manual" @selected($fFuente === 'manual')>Manual</option>
                <option value="telegram" @selected($fFuente === 'telegram')>Telegram</option>
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
        <div class="col-md-4">
            <label class="form-label fw-bold" for="history-descripcion">Buscar en descripcion</label>
            <input id="history-descripcion" class="form-control" type="search" name="descripcion" value="{{ $fDescripcion }}" placeholder="Texto contenido en la descripcion">
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
            <span class="text-muted ms-2">Mostrando {{ $visibleRows }} de {{ $totalFiltered }} registros</span>
        </div>
        <div class="historico-summary__tools">
            <span class="nova-status-badge is-info">
                <i class="bi bi-eye"></i> Alcance: {{ $historyScope }}
            </span>
            @if ($canHistoryActions)
                <form method="post" action="{{ $redmineRoute('redmine.native.history.action') }}" class="m-0" data-app-confirm="¿Consultar Redmine y actualizar únicamente el campo estado_redmine de todos los tickets TIC almacenados?" data-app-confirm-title="Sincronizar todos los estados Redmine" data-app-confirm-tone="info" data-app-confirm-text="Sincronizar">
                    @csrf
                    <input type="hidden" name="action" value="sync_redmine_statuses">
                    <button type="submit" class="btn-nova btn-nova-info" title="Actualiza únicamente estado_redmine en todos los reportes TIC">
                        <i class="bi bi-arrow-repeat"></i>Sincronizar todos los estados
                    </button>
                </form>
                <div class="dropdown historico-bulk-status">
                    <button
                        type="button"
                        class="btn-nova btn-nova-primary historico-bulk-status__button dropdown-toggle"
                        id="historico-bulk-status-button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        disabled>
                        <i class="bi bi-arrow-left-right"></i>
                        Cambiar estado
                        <span class="historico-selection-count" id="historico-selection-count">0</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end historico-status-menu" aria-labelledby="historico-bulk-status-button">
                        <li class="dropdown-header">Aplicar a seleccionados</li>
                        @foreach ($redmineStatusOptions as $statusOption)
                            <li class="js-bulk-status-option">
                                <button
                                    type="button"
                                    class="dropdown-item js-bulk-status-choice"
                                    data-status-id="{{ $statusOption['id'] }}"
                                    data-status-name="{{ $statusOption['name'] }}">
                                    <span class="historico-status-dot is-status-{{ $statusOption['id'] }}"></span>
                                    {{ $statusOption['name'] }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <form method="post" action="{{ $redmineRoute('redmine.native.history.action') }}" id="historico-bulk-status-form" class="d-none">
                    @csrf
                    <input type="hidden" name="action" value="update_redmine_status">
                    <input type="hidden" name="redmine_ids" id="historico-bulk-redmine-ids" value="">
                    <input type="hidden" name="status_id" id="historico-bulk-status-id" value="">
                </form>
            @endif
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
                <colgroup>
                    @if ($canHistoryActions)<col class="historico-col-select">@endif
                    <col class="historico-col-fecha">
                    <col class="historico-col-redmine">
                    <col class="historico-col-estado">
                    <col class="historico-col-solicitante">
                    <col class="historico-col-categoria">
                    <col class="historico-col-asunto">
                    <col class="historico-col-fuente">
                    <col class="historico-col-detalle">
                    <col class="historico-col-acciones">
                </colgroup>
                <thead class="table-light">
                    <tr>
                        @if ($canHistoryActions)
                            <th class="historico-select-cell">
                                <input
                                    class="form-check-input js-history-select-all"
                                    type="checkbox"
                                    id="historico-select-all"
                                    aria-label="Seleccionar todos los reportes abiertos"
                                    disabled>
                            </th>
                        @endif
                        <th>Fecha</th>
                        <th>Redmine ID</th>
                        <th>Estado Redmine</th>
                        <th>Solicitante</th>
                        <th>Categoria</th>
                        <th>Asunto</th>
                        <th>Fuente</th>
                        <th>Detalle</th>
                        <th class="historico-actions-cell">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pagedRows as $row)
                        @php
                            $redmineId = trim((string) ($row['redmine_id'] ?? ''));
                            $issueUrl = $redmineIssueUrl($redmineId);
                            $source = $sourceValue($row);
                            $sourceText = $sourceLabel($row);
                            $sourceIconClass = $sourceIcon($row);
                            $isHoursExtra = !empty($row['_history_is_hours_extra']) || in_array(strtolower(trim((string) ($row['hora_extra'] ?? ''))), ['si', 'sí', '1', 'true'], true);
                            $coreEstado = trim((string) ($row['core_estado'] ?? $row['estado'] ?? ''));
                            $unidadSolicitante = $row['unidad_solicitante'] ?? $row['core_establecimiento'] ?? $row['unidad'] ?? '';
                            $detail = [
                                'asunto' => $row['asunto'] ?? $row['mensaje'] ?? '',
                                'solicitante' => $row['solicitante'] ?? '',
                                'descripcion' => $row['descripcion'] ?? $row['mensaje'] ?? '',
                                'fecha' => $fmtDate($row['_history_date_norm'] ?? $row['fecha_inicio'] ?? $row['fecha'] ?? ''),
                                'redmine_id' => $redmineId,
                                'estado_redmine' => $row['estado_redmine'] ?? '',
                                'tipo' => $row['tipo'] ?? '',
                                'prioridad' => $row['prioridad'] ?? '',
                                'categoria' => $row['categoria'] ?? '',
                                'unidad_solicitante' => $unidadSolicitante,
                                'unidad' => $row['unidad'] ?? '',
                                'asignado' => $row['core_usuario_asignado'] ?? $row['asignado_nombre'] ?? $row['asignado_a'] ?? '',
                                'estado' => $coreEstado,
                                'fuente' => $sourceText,
                                'hora_extra' => $isHoursExtra ? 'Sí' : 'No',
                                'tiempo_estimado' => $row['tiempo_estimado'] ?? '',
                                'fecha_inicio' => $fmtDate($row['fecha_inicio'] ?? ''),
                                'fecha_fin' => $fmtDate($row['fecha_fin'] ?? ''),
                                'hora' => $row['hora'] ?? '',
                                'chat_id_telegram' => $row['chat_id_telegram'] ?? '',
                            ];
                        @endphp
                        <tr data-redmine-row="{{ $redmineId }}">
                            @if ($canHistoryActions)
                                <td class="historico-select-cell">
                                    @if ($redmineId !== '')
                                        <input
                                            class="form-check-input js-history-select"
                                            type="checkbox"
                                            value="{{ $redmineId }}"
                                            data-redmine-id="{{ $redmineId }}"
                                            aria-label="Seleccionar ticket Redmine {{ $redmineId }}"
                                            disabled>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                            @endif
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
                            <td class="historico-cell-truncate" title="{{ $row['solicitante'] ?? '' }}">{{ $row['solicitante'] ?? '-' }}</td>
                            <td class="historico-cell-truncate" title="{{ $row['categoria'] ?? '' }}">{{ $row['categoria'] ?? '-' }}</td>
                            <td class="historico-cell-truncate" title="{{ $row['asunto'] ?? ($row['mensaje'] ?? '') }}">{{ $row['asunto'] ?? ($row['mensaje'] ?? '-') }}</td>
                            <td>
                                <span class="historico-source-badge {{ $source === 'telegram' ? 'is-telegram' : 'is-manual' }}" title="Creado desde {{ $sourceText }}">
                                    <i class="bi {{ $sourceIconClass }}"></i>{{ $sourceText }}
                                    @if ($isHoursExtra)
                                        <span class="historico-overtime-icon" title="Hora extra" aria-label="Hora extra"><i class="bi bi-clock-fill"></i></span>
                                    @endif
                                </span>
                            </td>
                            <td>
                                <button type="button" class="btn-action btn-action-view historico-detail-btn" data-bs-toggle="modal" data-bs-target="#historicoDetalleModal" data-detail='@json($detail)' title="Ver detalle" aria-label="Ver detalle">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </td>
                            <td class="historico-actions-cell">
                                <div class="historico-row-actions">
                                    @if ($canHistoryActions && $redmineId !== '')
                                        <div class="dropdown">
                                            <button
                                                type="button"
                                                class="btn-action btn-action-sync dropdown-toggle no-caret js-redmine-status-menu d-none"
                                                data-redmine-id="{{ $redmineId }}"
                                                data-bs-toggle="dropdown"
                                                data-bs-boundary="viewport"
                                                aria-expanded="false"
                                                title="Cambiar estado en Redmine"
                                                aria-label="Cambiar estado del ticket {{ $redmineId }}">
                                                <i class="bi bi-arrow-left-right"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end historico-status-menu">
                                                <li class="dropdown-header">Cambiar estado #{{ $redmineId }}</li>
                                                @foreach ($redmineStatusOptions as $statusOption)
                                                    <li
                                                        class="js-row-status-option"
                                                        data-status-id="{{ $statusOption['id'] }}"
                                                        data-status-name="{{ $statusOption['name'] }}">
                                                        <form
                                                            method="post"
                                                            action="{{ $redmineRoute('redmine.native.history.action') }}"
                                                            class="m-0"
                                                            data-app-confirm="¿Cambiar el ticket #{{ $redmineId }} a {{ $statusOption['name'] }}?"
                                                            data-app-confirm-title="Cambiar estado en Redmine"
                                                            data-app-confirm-tone="info"
                                                            data-app-confirm-text="Cambiar estado">
                                                            @csrf
                                                            <input type="hidden" name="action" value="update_redmine_status">
                                                            <input type="hidden" name="redmine_ids" value="{{ $redmineId }}">
                                                            <input type="hidden" name="status_id" value="{{ $statusOption['id'] }}">
                                                            <button type="submit" class="dropdown-item">
                                                                <span class="historico-status-dot is-status-{{ $statusOption['id'] }}"></span>
                                                                {{ $statusOption['name'] }}
                                                            </button>
                                                        </form>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                    @if ($canHistoryActions && !empty($row['_history_can_delete']))
                                        <form method="post" action="{{ $redmineRoute('redmine.native.history.action') }}" class="m-0" data-app-confirm="Eliminar este registro del historico?">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $row['id'] ?? '' }}">
                                            <button type="submit" class="btn-action btn-action-delete" title="Eliminar registro" aria-label="Eliminar registro"><i class="bi bi-trash"></i></button>
                                        </form>
                                    @elseif (!$canHistoryActions || $redmineId === '')
                                        <span class="text-muted">-</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $tableColspan }}" class="nova-empty"><i class="bi bi-archive" style="font-size:1.4rem;display:block;margin-bottom:.4rem;opacity:.35"></i>Sin registros para el criterio seleccionado.</td></tr>
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
                    @foreach (['desde' => $fDesde, 'hasta' => $fHasta, 'fuente' => $fFuente, 'buscar' => $fBusqueda, 'descripcion' => $fDescripcion, 'categoria' => $fCategoria] as $name => $value)
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
                <div class="text-muted fw-bold">Mostrando {{ $visibleRows }} de {{ $totalFiltered }} registros</div>
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
                <dl class="historico-detail-facts" id="historico-detalle-body"></dl>
                <div class="detail-drawer-panel">
                    <div class="form-label fw-bold"><i class="bi bi-table me-2"></i>Descripcion / datos del reporte</div>
                    <div id="historico-detalle-descripcion" class="nova-description-preview historico-description-preview"></div>
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
    const selectAll = document.getElementById('historico-select-all');
    const rowCheckboxes = Array.from(document.querySelectorAll('.js-history-select[data-redmine-id]'));
    const bulkStatusButton = document.getElementById('historico-bulk-status-button');
    const selectionCount = document.getElementById('historico-selection-count');
    const bulkStatusForm = document.getElementById('historico-bulk-status-form');
    const bulkRedmineIds = document.getElementById('historico-bulk-redmine-ids');
    const bulkStatusId = document.getElementById('historico-bulk-status-id');
    const bulkStatusOptions = Array.from(document.querySelectorAll('.js-bulk-status-option'));

    document.querySelectorAll('.js-redmine-status-menu').forEach(trigger => {
        const dropdown = trigger.closest('.dropdown');
        const menu = dropdown?.querySelector('.historico-status-menu');
        if (!dropdown || !menu || !window.bootstrap?.Dropdown) return;

        trigger.addEventListener('show.bs.dropdown', () => {
            menu.classList.add('is-portal');
            document.body.appendChild(menu);
        });
        trigger.addEventListener('hidden.bs.dropdown', () => {
            menu.classList.remove('is-portal');
            dropdown.appendChild(menu);
        });
        window.bootstrap.Dropdown.getOrCreateInstance(trigger, {
            boundary: 'viewport',
            popperConfig(defaultConfig) {
                return { ...defaultConfig, strategy: 'fixed' };
            },
        });
    });

    const isOpenOptionName = name => ['abierto', 'abiertos', 'open'].includes(normalizeStatus(name).trim());
    const optionMatchesCheckbox = (option, checkbox) => {
        const statusId = option.getAttribute('data-status-id') || '';
        const statusName = normalizeStatus(option.getAttribute('data-status-name') || '').trim();
        const currentId = checkbox.dataset.currentStatusId || '';
        const currentName = normalizeStatus(checkbox.dataset.currentStatusName || '').trim();
        return (statusId !== '' && statusId === currentId)
            || (statusName !== '' && statusName === currentName);
    };
    const selectedOpenCheckboxes = () => rowCheckboxes.filter(checkbox => !checkbox.disabled && checkbox.checked);
    const refreshBulkOptions = selected => {
        bulkStatusOptions.forEach(option => {
            const statusName = option.getAttribute('data-status-name') || '';
            const allAlreadySelected = selected.length > 0
                && selected.every(checkbox => optionMatchesCheckbox(option, checkbox));
            const allAreOpen = selected.length > 0
                && selected.every(checkbox => checkbox.dataset.remoteOpen === '1');
            option.classList.toggle('d-none', allAlreadySelected || (allAreOpen && isOpenOptionName(statusName)));
        });
    };
    const refreshSelectionState = () => {
        const enabled = rowCheckboxes.filter(checkbox => !checkbox.disabled);
        const selected = selectedOpenCheckboxes();
        const selectedIds = [...new Set(selected.map(checkbox => checkbox.value).filter(Boolean))];
        if (selectionCount) selectionCount.textContent = String(selectedIds.length);
        refreshBulkOptions(selected);
        const hasVisibleOption = bulkStatusOptions.some(option => !option.classList.contains('d-none'));
        if (bulkStatusButton) bulkStatusButton.disabled = selectedIds.length === 0 || !hasVisibleOption;
        if (selectAll) {
            selectAll.disabled = enabled.length === 0;
            selectAll.checked = enabled.length > 0 && enabled.every(checkbox => checkbox.checked);
            selectAll.indeterminate = selected.length > 0 && selected.length < enabled.length;
        }
    };

    rowCheckboxes.forEach(checkbox => checkbox.addEventListener('change', refreshSelectionState));
    selectAll?.addEventListener('change', () => {
        rowCheckboxes.forEach(checkbox => {
            if (!checkbox.disabled) checkbox.checked = selectAll.checked;
        });
        refreshSelectionState();
    });
    document.querySelectorAll('.js-bulk-status-choice').forEach(choice => {
        choice.addEventListener('click', () => {
            const selectedIds = [...new Set(selectedOpenCheckboxes().map(checkbox => checkbox.value).filter(Boolean))];
            const statusId = choice.getAttribute('data-status-id') || '';
            const statusName = choice.getAttribute('data-status-name') || '';
            if (!selectedIds.length || !statusId || !bulkStatusForm || !bulkRedmineIds || !bulkStatusId) return;
            const submitBulkStatus = () => {
                bulkRedmineIds.value = selectedIds.join(',');
                bulkStatusId.value = statusId;
                bulkStatusForm.submit();
            };
            const message = `¿Cambiar ${selectedIds.length} ticket(s) seleccionado(s) a “${statusName}”?`;
            if (!window.appUi?.confirmAction) return;
            window.appUi.confirmAction(message, submitBulkStatus, {
                title: 'Cambiar estado en Redmine',
                acceptText: 'Cambiar estado',
                tone: 'info',
            });
        });
    });

    const setBadgeStatus = (badge, status) => {
        const available = Boolean(status && status.available);
        const closed = Boolean(status && status.closed);
        const statusId = String((status && status.id) || '');
        const statusName = String((status && status.name) || '');
        const message = String((status && status.message) || '');
        const cssClass = !available ? 'historico-redmine-status--unknown' : (closed ? 'historico-redmine-status--closed' : redmineStatusTone(statusName));
        const iconClass = !available ? 'bi-question-circle' : (closed ? 'bi-lock-fill' : 'bi-folder2-open');
        const label = !available ? 'No disponible' : (closed ? 'Cerrado' : 'Abierto');
        const detail = available && !closed && statusName ? `<small>${escapeHtml(statusName)}</small>` : '';
        badge.className = `historico-redmine-status js-redmine-status ${cssClass}`;
        badge.title = available ? `Redmine: ${statusName}` : message;
        badge.innerHTML = `<i class="bi ${iconClass}"></i><span>${escapeHtml(label)}</span>${detail}`;

        const redmineId = badge.getAttribute('data-redmine-id') || '';
        if (!redmineId) return;

        const open = available && !closed;
        document.querySelectorAll(`.js-history-select[data-redmine-id="${CSS.escape(redmineId)}"]`).forEach(checkbox => {
            checkbox.disabled = !open;
            checkbox.dataset.currentStatusId = statusId;
            checkbox.dataset.currentStatusName = statusName;
            checkbox.dataset.remoteOpen = open ? '1' : '0';
            if (!open) checkbox.checked = false;
        });
        document.querySelectorAll(`.js-redmine-status-menu[data-redmine-id="${CSS.escape(redmineId)}"]`).forEach(trigger => {
            const menu = trigger.closest('.dropdown')?.querySelector('.historico-status-menu');
            const options = Array.from(menu?.querySelectorAll('.js-row-status-option') || []);
            options.forEach(option => {
                const sameStatus = (option.getAttribute('data-status-id') || '') === statusId
                    || normalizeStatus(option.getAttribute('data-status-name') || '').trim() === normalizeStatus(statusName).trim();
                const redundantOpenOption = open && isOpenOptionName(option.getAttribute('data-status-name') || '');
                option.classList.toggle('d-none', sameStatus || redundantOpenOption);
            });
            const hasVisibleOption = options.some(option => !option.classList.contains('d-none'));
            trigger.classList.toggle('d-none', !open || !hasVisibleOption);
        });
        refreshSelectionState();
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
        const description = document.getElementById('historico-detalle-descripcion');
        if (window.NovaDescriptionTables?.render) {
            window.NovaDescriptionTables.render({ value: detail.descripcion || '' }, description);
        } else if (description) {
            description.textContent = detail.descripcion || 'Sin descripción.';
        }
        const body = document.getElementById('historico-detalle-body');
        const labels = {
            fecha: ['Fecha', 'bi-calendar3'],
            redmine_id: ['Redmine ID', 'bi-box-arrow-up-right'],
            estado_redmine: ['Estado Redmine', 'bi-folder2-open'],
            estado: ['Estado local', 'bi-check2-circle'],
            tipo: ['Tipo', 'bi-ticket-perforated'],
            prioridad: ['Prioridad', 'bi-flag'],
            categoria: ['Categoria', 'bi-tags'],
            unidad_solicitante: ['Unidad solicitante', 'bi-building'],
            unidad: ['Ubicación', 'bi-geo-alt'],
            asignado: ['Asignado', 'bi-person-check'],
            fuente: ['Fuente', 'bi-cloud-arrow-down'],
            hora_extra: ['Hora extra', 'bi-clock-history'],
            tiempo_estimado: ['Tiempo estimado', 'bi-hourglass-split'],
            fecha_inicio: ['Fecha inicio', 'bi-calendar-event'],
            fecha_fin: ['Fecha fin', 'bi-calendar-check'],
            hora: ['Hora', 'bi-clock'],
            chat_id_telegram: ['Chat ID Telegram', 'bi-telegram'],
        };
        body.innerHTML = Object.entries(labels).map(([key, meta]) => `
            <div><dt><i class="bi ${escapeHtml(meta[1])}"></i>${escapeHtml(meta[0])}</dt><dd>${escapeHtml(detail[key] || '-')}</dd></div>
        `).join('');
    });
});
</script>
