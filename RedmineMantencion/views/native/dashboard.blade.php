@extends('redmine_mantencion::native.layout')

@push('styles')
    @php
        $dashboardCss = base_path('RedmineMantencion/assets/css/dashboard.css');
    @endphp
    <link rel="stylesheet" href="{{ url('/redmine-mantencion/assets/css/dashboard.css') }}?v={{ @filemtime($dashboardCss) ?: 1 }}">
@endpush

@section('content')
@php
    $canEdit = !empty($permissions['reportes_editar']) || !empty($permissions['all']);
    $canDelete = !empty($permissions['reportes_eliminar']) || !empty($permissions['all']);
    $canHours = !empty($permissions['horas_extra_editar']) || !empty($permissions['all']);
    $canImportCore = !empty($permissions['reportes_importar_core']) || !empty($permissions['all']);
    $canSelect = $canEdit || $canDelete;
    $today = now('America/Santiago')->toDateString();
    $redmineStatusName = 'No definido';
    foreach (($config['estados'] ?? []) as $statusOption) {
        if ((string) ($statusOption['id'] ?? '') === (string) ($config['status_id'] ?? '')) {
            $redmineStatusName = (string) ($statusOption['nombre'] ?? $redmineStatusName);
            break;
        }
    }
@endphp
<div class="container-fluid py-4">
<div class="dashboard-shell">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-speedometer2',
        'title' => 'Reportes',
        'subtitle' => 'Panel de estados locales',
        'badges' => [
            ['icon' => 'bi-clock-history', 'label' => 'Retención automática: '.($config['retencion_horas'] ?? 24).' h'],
            ['icon' => 'bi-arrow-repeat', 'label' => 'Estado Redmine: '.$redmineStatusName],
        ],
    ])

    @if (session('mantencion_status'))
        <div data-nova-flash="{{ session('mantencion_status_type', 'success') }}" data-nova-flash-message="{{ session('mantencion_status') }}" hidden></div>
    @endif

    @if ($canImportCore)
        <form id="core-import-form" class="dashboard-panel" data-app-no-loading="1" data-no-page-loader="true">
            @csrf
            <input type="hidden" name="action" value="import_core_history">
            <input type="hidden" name="core_assigned_name" value="{{ $context['viewer_name'] }}">
            <div class="dashboard-panel__header">
                <div><h2 class="dashboard-panel__title">Consulta rápida a CORE</h2><p class="dashboard-panel__desc">Trae solicitudes por rango de fechas y usuario asignado con un flujo más claro.</p></div>
            </div>
            <div class="dashboard-import-grid">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4"><label class="form-label">CORE desde</label><input type="date" name="core_desde" class="form-control" value="{{ request('core_desde', $today) }}"></div>
                    <div class="col-md-4"><label class="form-label">CORE hasta</label><input type="date" name="core_hasta" class="form-control" value="{{ request('core_hasta', $today) }}"></div>
                    <div class="col-md-4"><label class="form-label">Asignado CORE</label><input class="form-control" value="{{ $context['viewer_name'] }}" readonly></div>
                </div>
                <button type="submit" class="btn-nova btn-nova-primary dashboard-import-button" @disabled(!empty($context['maintenance']['enabled']))><i class="bi bi-cloud-download"></i> Importar desde CORE</button>
            </div>
        </form>
    @endif

    <div class="dashboard-stats" id="status-filters" aria-label="Estados de reportes">
        <section class="dashboard-stat dashboard-stat--pending is-active" data-filter="pendiente" data-status-filter="pendiente" role="button" tabindex="0">
            <div class="dashboard-stat__top"><span class="dashboard-stat__icon"><i class="bi bi-hourglass-split"></i></span><div class="dashboard-stat__content"><div class="dashboard-stat__value">{{ $counts['pendiente'] }}</div><div class="dashboard-stat__label">Pendientes por revisar</div></div></div>
        </section>
        <section class="dashboard-stat dashboard-stat--processed" data-filter="procesado" data-status-filter="procesado" role="button" tabindex="0">
            <div class="dashboard-stat__top"><span class="dashboard-stat__icon"><i class="bi bi-check2-circle"></i></span><div class="dashboard-stat__content"><div class="dashboard-stat__value">{{ $counts['procesado'] }}</div><div class="dashboard-stat__label">Procesados correctamente</div></div></div>
        </section>
        <section class="dashboard-stat dashboard-stat--error" data-filter="error" data-status-filter="error" role="button" tabindex="0">
            <div class="dashboard-stat__top"><span class="dashboard-stat__icon"><i class="bi bi-exclamation-octagon"></i></span><div class="dashboard-stat__content"><div class="dashboard-stat__value">{{ $counts['error'] }}</div><div class="dashboard-stat__label">Errores pendientes</div></div></div>
        </section>
    </div>

    <section class="card dashboard-table-card" id="dashboard-table-card">
        <div class="card-body">
            @if ($canSelect)
                <div class="dashboard-toolbar px-3 pt-3">
                    <div class="dashboard-toolbar__actions">
                        <span class="dashboard-selection"><i class="bi bi-check2-square"></i> Seleccionados: <strong id="selection-count">0</strong></span>
                        <div class="dashboard-toolbar__button-group">
                            @if ($canEdit)
                                <button type="button" class="btn-nova btn-nova-success btn-icon dashboard-command" data-bulk-action="process_selected" data-status-action="pendiente" disabled><i class="bi bi-check2-circle"></i> Enviar reportes a Redmine</button>
                                <button type="button" class="btn-nova btn-nova-warning btn-icon dashboard-command d-none" data-bulk-action="archive_selected" data-status-action="procesado" disabled><i class="bi bi-archive"></i> Archivar</button>
                                <button type="button" class="btn-nova btn-nova-secondary btn-icon dashboard-command d-none" data-bulk-action="reset_errors" data-status-action="error"><i class="bi bi-arrow-counterclockwise"></i> Reintentar errores</button>
                            @endif
                            @if ($canDelete)<button type="button" class="btn-nova btn-nova-danger btn-icon dashboard-command" data-bulk-action="delete_selected" data-status-action="pendiente procesado" disabled><i class="bi bi-trash3"></i> Eliminar seleccionados</button>@endif
                        </div>
                    </div>
                </div>
            @endif

            <div class="table-responsive rm-table-wrap dashboard-table-wrap">
            <table class="table table-striped align-middle w-100 dashboard-table" id="reports-table">
                <thead>
                    <tr>
                        @if ($canSelect)<th class="dashboard-select-cell"><div class="dashboard-select-control"><input class="form-check-input" type="checkbox" id="select-all" aria-label="Seleccionar todos"></div></th>@endif
                        <th>Redmine ID</th>
                        <th>Asunto</th>
                        <th>Solicitante</th>
                        <th>Fecha creación</th>
                        <th>Categoría</th>
                        <th>Departamento</th>
                        <th>Estado local</th>
                        <th class="nova-col-actions text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($messages as $message)
                        @php
                            $status = strtolower((string) ($message['estado'] ?? 'pendiente'));
                            $source = strtolower(trim((string) ($message['fuente'] ?? '')));
                            $isCore = $source === 'core' || trim((string) ($message['id_core'] ?? '')) !== '';
                            $isManual = $source === 'manual' || str_starts_with((string) ($message['id'] ?? ''), 'manual-');
                            $coreStatus = strtolower(Illuminate\Support\Str::ascii(trim((string) ($message['core_estado'] ?? ''))));
                            $coreIndicator = $isCore ? match ($coreStatus) {
                                'en revision' => ['label' => 'En Revisión', 'icon' => 'bi-hourglass-split', 'badge' => 'warning'],
                                'gestionada' => ['label' => 'Gestionada', 'icon' => 'bi-check-circle-fill', 'badge' => 'success'],
                                'rechazada' => ['label' => 'Rechazada', 'icon' => 'bi-x-circle-fill', 'badge' => 'danger'],
                                default => null,
                            } : null;
                            $coreReview = ($coreIndicator['label'] ?? '') === 'En Revisión';
                            [$localStatusClass, $localStatusIcon] = match ($status) {
                                'pendiente' => ['pending', 'bi-hourglass-split'],
                                'procesado' => ['processed', 'bi-check2'],
                                default => ['error', 'bi-exclamation-lg'],
                            };
                        @endphp
                        <tr data-report-row data-id="{{ $message['id'] }}" data-status="{{ $status }}" data-search="{{ mb_strtolower(implode(' ', [$message['asunto'] ?? '', $message['solicitante'] ?? '', $message['categoria'] ?? '', $message['id_core'] ?? '']), 'UTF-8') }}">
                            @if ($canSelect)<td class="dashboard-select-cell"><div class="dashboard-select-control"><input class="form-check-input report-check" type="checkbox" value="{{ $message['id'] }}" aria-label="Seleccionar {{ $message['id'] }}">@if($coreIndicator)<span class="badge rounded-circle text-bg-{{ $coreIndicator['badge'] }} p-2" title="CORE: {{ $coreIndicator['label'] }}" aria-label="CORE: {{ $coreIndicator['label'] }}"><i class="bi {{ $coreIndicator['icon'] }}"></i></span>@elseif($isManual)<span class="badge rounded-circle p-2 dashboard-source-indicator is-manual" title="Origen: Creación manual" aria-label="Origen: Creación manual"><i class="bi bi-pencil-fill"></i></span>@endif</div></td>@endif
                            <td>{{ $message['redmine_id'] ?? '' }}</td>
                            <td><div class="dashboard-table__subject" title="{{ $message['asunto'] ?: 'Sin asunto' }}">{{ $message['asunto'] ?: 'Sin asunto' }}</div>@if($coreReview)<span class="dashboard-table__meta text-warning">CORE: En Revisión</span>@endif</td>
                            <td>{{ $message['solicitante'] ?: '—' }}</td>
                            <td>{{ $message['fecha'] ?: $message['fecha_inicio'] ?: '—' }}</td>
                            <td>{{ $message['categoria'] ?: '—' }}</td>
                            <td>{{ ($message['core_departamento'] ?? '') ?: ($message['unidad'] ?? '') ?: '—' }}</td>
                            <td class="text-center"><span class="dashboard-status-icon dashboard-status-icon--{{ $localStatusClass }}" title="{{ ucfirst($status) }}"><i class="bi {{ $localStatusIcon }}"></i></span></td>
                            <td class="nova-col-actions">
                                <div class="dashboard-row-actions">
                                    @if ($canEdit)<button class="btn-action btn-action-view dashboard-action dashboard-action--edit" type="button" data-bs-toggle="modal" data-bs-target="#detalleModal" data-edit='@json($message)' title="Detalle / Editar" aria-label="Detalle o editar"><i class="bi bi-pencil-square"></i></button>@endif
                                    @if ($canHours)
                                        <input class="btn-check report-hours" type="checkbox" id="hours-{{ md5((string) $message['id']) }}" @checked(!empty($message['hora_extra'])) data-id="{{ $message['id'] }}">
                                        <label class="btn-action btn-action-sync dashboard-action dashboard-action--hours {{ !empty($message['hora_extra']) ? 'btn-hora-extra--on' : 'btn-hora-extra--off' }}" for="hours-{{ md5((string) $message['id']) }}" title="Hora extra" aria-label="Alternar hora extra"><i class="bi bi-clock"></i></label>
                                    @endif
                                    @if ($canDelete)<button class="btn-action btn-action-delete dashboard-action dashboard-action--delete" type="button" data-delete="{{ $message['id'] }}" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3"></i></button>@endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canSelect ? 9 : 8 }}" class="nova-empty"><i class="bi bi-inbox fs-3 d-block mb-2"></i>No hay solicitudes disponibles.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </section>
</div>
</div>

<div class="modal fade detail-drawer-modal" id="detalleModal" tabindex="-1" aria-labelledby="detalleModal-title" aria-hidden="true">
    <div class="modal-dialog detail-drawer-dialog modal-dialog-scrollable"><div class="modal-content">
        <form id="edit-report-form">
            <div class="modal-header"><div><p class="detail-drawer-kicker">Reporte seleccionado</p><h2 class="modal-title fs-5" id="detalleModal-title"><span class="detail-drawer-icon"><i class="bi bi-pencil-square"></i></span>Detalle / Editar</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <div class="detail-drawer-view is-active" id="drawer-detail-view">
                <div class="row g-3">
                    <input type="hidden" name="id">
                    <div class="col-md-4"><label class="form-label">Tipo</label><input class="form-control" id="md-tipo" disabled></div>
                    <div class="col-md-4"><label class="form-label">Estado</label><input class="form-control" id="md-estado" disabled></div>
                    <div class="col-md-4"><label class="form-label">Prioridad</label><input class="form-control" id="md-prioridad" disabled></div>

                    <div class="col-12"><label class="form-label">Asunto</label><textarea class="form-control" name="asunto" rows="2" required></textarea></div>

                    <div class="col-md-6"><label class="form-label">Categorías</label><select class="form-select" name="categoria"><option value="">Sin categoría</option>@foreach($categories as $category)<option value="{{ $category['nombre'] }}">{{ $category['nombre'] }}</option>@endforeach</select></div>
                    <div class="col-md-6">
                        <label class="form-label">Asignado a</label>
                        <select class="form-select" name="asignado_a"><option value="">Sin asignar</option>@foreach($users as $user)<option value="{{ $user['id'] }}">{{ $user['nombre'] }}</option>@endforeach</select>
                        <div class="form-text fw-semibold" data-current-assignee></div>
                    </div>

                    <div class="col-md-6"><label class="form-label">Solicitante</label><input class="form-control" name="solicitante"></div>
                    <div class="col-md-3"><label class="form-label">Establecimiento</label><input class="form-control" id="md-establecimiento"></div>
                    <div class="col-md-3"><label class="form-label">Departamento</label><input class="form-control" id="md-departamento"></div>
                    <input type="hidden" name="unidad" id="md-unidad">

                    <div class="col-md-4"><label class="form-label">Estado Redmine</label><input class="form-control" value="{{ $redmineStatusName }}" disabled></div>
                    <div class="col-md-4">
                        <label class="form-label">Hora extra</label>
                        <select class="form-select" name="hora_extra"><option value="0">No</option><option value="1">Sí</option></select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Tiempo Estimado</label><input class="form-control" name="tiempo_estimado" inputmode="decimal"></div>

                    <div class="col-md-3"><label class="form-label">Fecha Inicio</label><input class="form-control" type="date" name="fecha_inicio"></div>
                    <div class="col-md-3"><label class="form-label">Fecha Fin</label><input class="form-control" type="date" name="fecha_fin"></div>
                    <div class="col-md-3"><label class="form-label">Fecha</label><input class="form-control" type="date" name="fecha"></div>
                    <div class="col-md-3"><label class="form-label">Hora</label><input class="form-control" type="time" name="hora"></div>

                    <div class="col-md-4"><label class="form-label">Número</label><input class="form-control" name="numero"></div>
                    <div class="col-md-8"><label class="form-label">Correo</label><input class="form-control" type="email" name="correo"></div>

                    <div class="col-12 d-none" id="md-descripcion-wrap">
                        <label class="form-label d-block">Descripción</label>
                        <input type="hidden" name="descripcion" id="md-descripcion">
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#descripcionModal"><i class="bi bi-text-paragraph"></i> Editar descripción</button>
                    </div>

                    <div class="col-12" id="md-preview-wrap">
                        <label class="form-label d-block">Vista previa de la tabla</label>
                        <button type="button" class="btn btn-outline-primary" id="open-preview-modal-btn"><i class="bi bi-table"></i> Ver tabla</button>
                    </div>
                </div>
                </div>

                <div class="detail-drawer-view" id="drawer-table-view" aria-hidden="true">
                    <div class="detail-drawer-table-header">
                        <div>
                            <p class="detail-drawer-kicker">Vista previa</p>
                            <h6 class="detail-drawer-table-title">Detalle del reporte</h6>
                            <p class="detail-drawer-table-subtitle">Resumen de los datos actuales del reporte.</p>
                        </div>
                        <button type="button" class="btn btn-outline-primary" id="back-to-detail-btn"><i class="bi bi-arrow-left"></i> Volver al detalle</button>
                    </div>
                    <div class="table-responsive detail-preview-wrap">
                        <table class="table table-sm mb-0 align-middle">
                            <thead id="md-preview-head"><tr><th>Tipo solicitud</th><th>Solicitante</th><th>Categoría</th><th>Unidad</th><th>Descripción</th></tr></thead>
                            <tbody id="md-preview-body"><tr><td colspan="5" class="text-muted text-center">Sin detalle para previsualizar.</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="detail-drawer-footer"><button type="button" class="btn-nova btn-nova-secondary btn-icon dashboard-command" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button><button class="btn-nova btn-nova-primary btn-icon dashboard-command" type="submit"><i class="bi bi-check2"></i> Guardar cambios</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="descripcionModal" tabindex="-1" aria-labelledby="descripcionModal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="descripcionModal-title">Editar descripción</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body">
            <div class="nova-description-tabs" role="tablist" aria-label="Vista de descripción">
                <button type="button" class="nova-description-tab is-active" id="dashboard-description-edit-tab" role="tab" aria-selected="true"><i class="bi bi-pencil"></i> Modificar</button>
                <button type="button" class="nova-description-tab" id="dashboard-description-preview-tab" role="tab" aria-selected="false"><i class="bi bi-table"></i> Previsualizar</button>
            </div>
            <div id="dashboard-description-edit-panel" role="tabpanel" aria-labelledby="dashboard-description-edit-tab">
                <textarea id="md-descripcion-editor" class="form-control nova-description-editor" rows="10"></textarea>
                <div class="form-text">Al pegar celdas desde Excel se convertirán automáticamente en una tabla.</div>
            </div>
            <div class="nova-description-preview" id="dashboard-description-preview" role="tabpanel" aria-labelledby="dashboard-description-preview-tab" hidden></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button><button type="button" class="btn-nova btn-nova-primary" id="save-descripcion-btn">Guardar descripción</button></div>
    </div></div>
</div>

<div class="core-import-overlay" id="redmine-overlay" role="status" aria-live="polite" aria-hidden="true">
    <div class="core-import-card">
        <div class="core-import-card__media">
            <img class="core-import-card__gif" id="dashboard-progress-gif" src="{{ url('/redmine-mantencion/assets/img/animacion-carga.gif') }}" data-core-src="{{ url('/redmine-mantencion/assets/img/animacion-carga.gif') }}" data-redmine-src="{{ url('/redmine-mantencion/assets/img/redmine.gif') }}" alt="" loading="eager">
        </div>
        <div class="core-import-card__header">
            <div class="core-import-card__icon"><i class="bi bi-cloud-download" id="integration-icon"></i></div>
            <div><h3 class="core-import-card__title" id="integration-title">Importando desde CORE</h3><p class="core-import-card__text" id="integration-copy">Preparando consulta.</p></div>
        </div>
        <div class="core-import-progress" aria-label="Progreso de la operación"><div class="core-import-progress__bar" id="redmine-progress"></div></div>
        <div class="core-import-card__meta"><span id="integration-step">Preparando</span><span id="integration-percent">0%</span></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const endpoint = @json(route('redmine.mantencion.dashboard.action'));
    const rows = [...document.querySelectorAll('[data-report-row]')];
    const selected = () => [...document.querySelectorAll('.report-check:checked')].map(input => input.value);
    const toast = (message, type = 'success') => window.appUi?.toast?.(message, type) || window.alert(message);
    const request = async payload => {
        try {
            const response = await fetch(endpoint, {method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(payload)});
            const data = await response.json().catch(() => ({ok: false, message: 'Respuesta inválida del servidor.'}));
            if (!response.ok && !data.message) data.message = 'No se pudo completar la acción.';
            return data;
        } catch (error) {
            return {ok: false, message: 'No se pudo conectar con el servidor. Revisa la conexión e inténtalo nuevamente.'};
        }
    };

    const integrationOverlay = document.getElementById('redmine-overlay');
    const integrationProgress = document.getElementById('redmine-progress');
    const integrationPercent = document.getElementById('integration-percent');
    const integrationStep = document.getElementById('integration-step');
    const integrationCopy = document.getElementById('integration-copy');
    const integrationTitle = document.getElementById('integration-title');
    const integrationIcon = document.getElementById('integration-icon');
    const progressGif = document.getElementById('dashboard-progress-gif');
    const progressModes = {
        core: {
            title: 'Importando desde CORE',
            icon: 'bi-cloud-download',
            steps: [
                [8, 'Conectando con CORE...', 'Abriendo sesión'],
                [24, 'Autenticando credenciales...', 'Validando acceso'],
                [42, 'Consultando solicitudes...', 'Leyendo datos'],
                [64, 'Procesando registros...', 'Normalizando solicitudes'],
                [82, 'Guardando importación...', 'Actualizando panel'],
                [94, 'Finalizando...', 'Esperando respuesta'],
            ],
        },
        redmine: {
            title: 'Enviando reportes a Redmine',
            icon: 'bi-send-check',
            steps: [
                [8, 'Preparando reportes...', 'Validando selección'],
                [24, 'Conectando con Redmine...', 'Abriendo conexión'],
                [46, 'Enviando reportes...', 'Creando tickets'],
                [68, 'Confirmando respuestas...', 'Registrando resultados'],
                [84, 'Actualizando estados locales...', 'Guardando cambios'],
                [94, 'Finalizando...', 'Esperando respuesta'],
            ],
        },
    };
    let progressTimer = null;
    let progressValue = 0;

    if (integrationOverlay?.parentElement !== document.body) document.body.appendChild(integrationOverlay);
    if (progressGif) {
        [progressGif.dataset.coreSrc, progressGif.dataset.redmineSrc].filter(Boolean).forEach(src => { const image = new Image(); image.src = src; });
    }

    const setProgress = (value, complete = false) => {
        progressValue = Math.min(complete ? 100 : 94, Math.max(progressValue, value));
        if (integrationProgress) integrationProgress.style.width = `${progressValue}%`;
        if (integrationPercent) integrationPercent.textContent = `${Math.round(progressValue)}%`;
    };
    const showProgress = mode => {
        const definition = progressModes[mode] || progressModes.core;
        const source = mode === 'redmine' ? progressGif?.dataset.redmineSrc : progressGif?.dataset.coreSrc;
        if (progressGif && source) progressGif.src = source;
        if (integrationTitle) integrationTitle.textContent = definition.title;
        if (integrationIcon) integrationIcon.className = `bi ${definition.icon}`;
        progressValue = 0;
        let stepIndex = 0;
        const showStep = index => {
            const step = definition.steps[index];
            if (integrationCopy) integrationCopy.textContent = step[1];
            if (integrationStep) integrationStep.textContent = step[2];
        };
        showStep(0);
        setProgress(6);
        integrationOverlay?.classList.add('is-visible');
        integrationOverlay?.setAttribute('aria-hidden', 'false');
        document.body.classList.add('nova-integration-loading');
        window.clearInterval(progressTimer);
        progressTimer = window.setInterval(() => {
            const target = definition.steps[Math.min(stepIndex, definition.steps.length - 1)][0];
            if (progressValue < target) {
                setProgress(progressValue + Math.max(1, (target - progressValue) * .18));
            } else if (stepIndex < definition.steps.length - 1) {
                stepIndex += 1;
                showStep(stepIndex);
            } else {
                setProgress(progressValue + .35);
            }
        }, 420);
    };
    const finishProgress = async success => {
        window.clearInterval(progressTimer);
        if (integrationProgress) integrationProgress.style.width = '100%';
        progressValue = 100;
        if (integrationPercent) integrationPercent.textContent = '100%';
        if (integrationCopy) integrationCopy.textContent = success ? 'Operación completada.' : 'La operación terminó con observaciones.';
        if (integrationStep) integrationStep.textContent = success ? 'Completado' : 'Revisa el resultado';
        await new Promise(resolve => setTimeout(resolve, 500));
        integrationOverlay?.classList.remove('is-visible');
        integrationOverlay?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('nova-integration-loading');
    };

    let statusFilter = 'pendiente';
    const updateSelection = () => {
        const count = selected().length;
        const counter = document.getElementById('selection-count');
        if (counter) counter.textContent = String(count);
        document.querySelectorAll('[data-bulk-action]').forEach(button => {
            if (button.dataset.bulkAction !== 'reset_errors') button.disabled = count === 0;
        });
    };
    const updateFilterUi = () => {
        document.querySelectorAll('[data-status-action]').forEach(button => {
            const visibleStatuses = (button.dataset.statusAction || '').split(/\s+/).filter(Boolean);
            button.classList.toggle('d-none', !visibleStatuses.includes(statusFilter));
        });
    };
    const applyFilters = () => {
        const search = (document.getElementById('report-search')?.value || '').trim().toLocaleLowerCase('es');
        rows.forEach(row => row.hidden = !((statusFilter === 'all' || row.dataset.status === statusFilter) && (!search || (row.dataset.search || '').includes(search))));
        updateFilterUi();
        updateSelection();
    };
    document.querySelectorAll('[data-status-filter]').forEach(button => button.addEventListener('click', () => {
        statusFilter = button.dataset.statusFilter;
        document.querySelectorAll('.report-check').forEach(input => { input.checked = false; });
        const selectAll = document.getElementById('select-all');
        if (selectAll) selectAll.checked = false;
        document.querySelectorAll('[data-status-filter]').forEach(item => item.classList.toggle('is-active', item === button));
        applyFilters();
    }));
    document.querySelectorAll('[data-status-filter]').forEach(button => button.addEventListener('keydown', event => {
        if (!['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        button.click();
    }));
    document.getElementById('report-search')?.addEventListener('input', applyFilters);
    document.getElementById('select-all')?.addEventListener('change', event => {
        document.querySelectorAll('.report-check').forEach(input => { if (!input.closest('tr').hidden) input.checked = event.target.checked; });
        updateSelection();
    });
    document.querySelectorAll('.report-check').forEach(input => input.addEventListener('change', updateSelection));

    document.querySelectorAll('[data-bulk-action]').forEach(button => button.addEventListener('click', async () => {
        const action = button.dataset.bulkAction;
        let ids = selected();
        if (action === 'reset_errors' && ids.length === 0) ids = rows.filter(row => row.dataset.status === 'error').map(row => row.dataset.id);
        if (!ids.length) return toast('Selecciona al menos un reporte.', 'warning');
        if (action === 'delete_selected' && !confirm('¿Eliminar los reportes seleccionados?')) return;
        if (action === 'process_selected') {
            showProgress('redmine');
            const data = await request({action, ids});
            await finishProgress(Boolean(data.ok));
            // Mixed-send CORE warnings intentionally appear only here, once the
            // progress bar has finished and the overlay has closed.
            toast(data.message, data.failed ? 'danger' : (data.blocked ? 'warning' : 'success'));
            setTimeout(() => window.location.reload(), 1000);
            return;
        }
        const data = await request({action, ids});
        toast(data.message, data.ok ? 'success' : 'danger');
        if (data.ok) setTimeout(() => window.location.reload(), 700);
    }));

    document.querySelectorAll('[data-delete]').forEach(button => button.addEventListener('click', async () => {
        if (!confirm('¿Eliminar este reporte?')) return;
        const data = await request({action: 'delete', id: button.dataset.delete});
        toast(data.message, data.ok ? 'success' : 'danger');
        if (data.ok) button.closest('tr')?.remove();
    }));
    document.querySelectorAll('.report-hours').forEach(input => input.addEventListener('change', async () => {
        const data = await request({action: 'toggle_hora_extra', id: input.dataset.id, hora_extra: input.checked});
        if (!data.ok) input.checked = !input.checked;
        const label = document.querySelector(`label[for="${input.id}"]`);
        label?.classList.toggle('btn-hora-extra--on', input.checked);
        label?.classList.toggle('btn-hora-extra--off', !input.checked);
        toast(data.message, data.ok ? 'success' : 'danger');
    }));

    const detalleModal = document.getElementById('detalleModal');
    const descripcionModal = document.getElementById('descripcionModal');
    const drawerDetailView = document.getElementById('drawer-detail-view');
    const drawerTableView = document.getElementById('drawer-table-view');
    const detailDrawerFooter = document.getElementById('detail-drawer-footer');
    const editForm = document.getElementById('edit-report-form');
    const descripcionEditor = document.getElementById('md-descripcion-editor');
    const descripcionHidden = document.getElementById('md-descripcion');

    const descriptionTabs = window.NovaDescriptionTables?.bind({
        input: descripcionEditor,
        editTab: document.getElementById('dashboard-description-edit-tab'),
        previewTab: document.getElementById('dashboard-description-preview-tab'),
        editPanel: document.getElementById('dashboard-description-edit-panel'),
        previewPanel: document.getElementById('dashboard-description-preview'),
    }) || null;

    const setDrawerView = view => {
        const showTable = view === 'table';
        drawerDetailView?.classList.toggle('is-active', !showTable);
        drawerDetailView?.setAttribute('aria-hidden', showTable ? 'true' : 'false');
        drawerTableView?.classList.toggle('is-active', showTable);
        drawerTableView?.setAttribute('aria-hidden', showTable ? 'false' : 'true');
        detailDrawerFooter?.classList.toggle('d-none', showTable);
        detalleModal?.querySelector('.modal-body')?.scrollTo({top: 0, behavior: 'smooth'});
    };

    const renderPreviewTable = data => {
        const head = document.getElementById('md-preview-head');
        const body = document.getElementById('md-preview-body');
        if (!head || !body) return;
        const cell = value => {
            value = String(value ?? '').trim();
            return `<td style="white-space:pre-wrap">${value ? escapeHtml(value) : '—'}</td>`;
        };
        const unidad = data.unidad || data.core_departamento || data.unidad_texto || '';
        const isManual = (data.fuente || '') === 'manual';
        head.innerHTML = '<tr><th>Tipo solicitud</th><th>Solicitante</th><th>Categoría</th><th>Unidad</th>'
            + (isManual ? '<th>Descripción</th>' : '') + '</tr>';
        body.innerHTML = '<tr>'
            + cell(data.tipo)
            + cell(data.solicitante)
            + cell(data.categoria)
            + cell(unidad)
            + (isManual ? cell(data.descripcion) : '')
            + '</tr>';
    };
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

    document.getElementById('open-preview-modal-btn')?.addEventListener('click', () => setDrawerView('table'));
    document.getElementById('back-to-detail-btn')?.addEventListener('click', () => setDrawerView('detail'));

    let reopenDetalleModalAfterDescripcion = false;
    descripcionModal?.addEventListener('show.bs.modal', () => {
        reopenDetalleModalAfterDescripcion = true;
        descriptionTabs?.show(false);
        if (descripcionEditor) descripcionEditor.value = descripcionHidden?.value || '';
    });
    descripcionModal?.addEventListener('hidden.bs.modal', () => {
        if (!reopenDetalleModalAfterDescripcion || !detalleModal) return;
        reopenDetalleModalAfterDescripcion = false;
        window.bootstrap?.Modal?.getOrCreateInstance(detalleModal)?.show();
    });
    document.getElementById('save-descripcion-btn')?.addEventListener('click', () => {
        if (descripcionHidden && descripcionEditor) descripcionHidden.value = descripcionEditor.value || '';
        window.bootstrap?.Modal?.getOrCreateInstance(descripcionModal)?.hide();
    });

    detalleModal?.addEventListener('show.bs.modal', () => setDrawerView('detail'));

    document.querySelectorAll('[data-edit]').forEach(button => button.addEventListener('click', () => {
        const data = JSON.parse(button.dataset.edit || '{}');
        const form = editForm;
        if (!form) return;
        ['id', 'asunto', 'categoria', 'solicitante', 'fecha_inicio', 'fecha_fin', 'tiempo_estimado', 'fecha', 'hora', 'numero', 'correo'].forEach(key => {
            if (form.elements[key]) form.elements[key].value = data[key] || '';
        });
        if (form.elements.asignado_a) form.elements.asignado_a.value = data.asignado_a || '';
        const currentAssignee = form.querySelector('[data-current-assignee]');
        if (currentAssignee) currentAssignee.textContent = data.asignado_nombre ? `Actual: ${data.asignado_nombre}` : '';
        if (form.elements.hora_extra) {
            const hv = String(data.hora_extra || '').toLowerCase();
            form.elements.hora_extra.value = ['1', 'si', 'sí', 'true'].includes(hv) ? '1' : '0';
        }
        const tipoField = document.getElementById('md-tipo');
        if (tipoField) tipoField.value = data.tipo || '';
        const estadoField = document.getElementById('md-estado');
        if (estadoField) estadoField.value = data.estado || '';
        const prioridadField = document.getElementById('md-prioridad');
        if (prioridadField) prioridadField.value = data.prioridad || '';
        const unidad = data.core_departamento || data.unidad || data.unidad_texto || '';
        const establecimientoField = document.getElementById('md-establecimiento');
        if (establecimientoField) establecimientoField.value = unidad;
        const departamentoField = document.getElementById('md-departamento');
        if (departamentoField) departamentoField.value = unidad;
        if (form.elements.unidad) form.elements.unidad.value = unidad;

        const isManual = (data.fuente || '') === 'manual';
        const descripcionWrap = document.getElementById('md-descripcion-wrap');
        descripcionWrap?.classList.toggle('d-none', !isManual);
        if (descripcionHidden) descripcionHidden.value = data.descripcion || '';
        if (descripcionEditor) descripcionEditor.value = data.descripcion || '';

        document.getElementById('md-preview-wrap')?.classList.toggle('d-none', isManual);

        renderPreviewTable(data);
    }));
    editForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const establecimientoField = document.getElementById('md-establecimiento');
        const departamentoField = document.getElementById('md-departamento');
        if (editForm.elements.unidad) {
            editForm.elements.unidad.value = (departamentoField?.value || establecimientoField?.value || '').trim();
        }
        const payload = Object.fromEntries(new FormData(event.currentTarget));
        const data = await request({action: 'update', ...payload});
        toast(data.message, data.ok ? 'success' : 'danger');
        if (data.ok) setTimeout(() => window.location.reload(), 700);
    });
    document.getElementById('core-import-form')?.addEventListener('submit', async event => {
        event.preventDefault();
        const payload = Object.fromEntries(new FormData(event.currentTarget));
        showProgress('core');
        const data = await request({action: 'import_core_history', ...payload});
        await finishProgress(Boolean(data.ok));
        toast(data.message, data.ok ? 'success' : 'danger');
        if (data.ok) setTimeout(() => window.location.reload(), 800);
    });
    applyFilters();
})();
</script>
@endpush
