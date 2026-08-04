@php
    $activeDashboardFilter = $summary['filter'] ?? $dashboardFilter ?? 'todos';
    $dashboardRoute = static fn (string $estado) => $redmineRoute('redmine.native.section', ['section' => 'dashboard', 'estado' => $estado]);
    $showSendAction = in_array($activeDashboardFilter, ['todos', 'pendientes'], true);
    $showArchiveAction = $activeDashboardFilter === 'procesados';
    $showRetryAction = $activeDashboardFilter === 'errores';
    $processedActionsLocked = $activeDashboardFilter === 'procesados';
    $permissionEnabled = static function (string $key) use ($effectivePermissions): bool {
        if (array_key_exists($key, (array) $effectivePermissions)) {
            return in_array($effectivePermissions[$key], [true, 1, '1', 'si'], true);
        }

        return !empty($effectivePermissions['all']);
    };
    $canEditReports = $permissionEnabled('reportes_editar');
    $canDeleteReports = $permissionEnabled('reportes_eliminar');
    $canEditHoursExtra = $permissionEnabled('horas_extra_editar');
    $fmtDate = static function ($value): string {
        $value = trim((string) $value);
        $value = preg_split('/\s+/', $value)[0] ?? $value;
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date) {
                return $date->format('d-m-Y');
            }
        }

        return $value ?: '-';
    };
    $unitOptions = collect($units ?? [])->map(fn ($row) => trim((string) ($row['nombre'] ?? $row['id'] ?? '')))->filter()->unique()->values();
@endphp

<section class="rm-module-head">
    <span class="rm-module-head-icon"><i class="bi bi-inboxes"></i></span>
    <div>
        <small>Cola operativa</small>
        <h2>Reportes TIC</h2>
        <p>Gestiona pendientes, procesados y errores antes de enviar o archivar.</p>
    </div>
</section>

<section class="row g-3 mb-4" aria-label="Indicadores">
    <div class="col-12 col-lg-4">
        <a class="card nova-card rm-stat-card rm-filter-card {{ $activeDashboardFilter === 'pendientes' ? 'active' : '' }}" href="{{ $dashboardRoute('pendientes') }}">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="rm-stat-icon is-pending"><i class="bi bi-hourglass-split"></i></span>
                <div>
                    <strong class="fs-2 lh-1">{{ $summary['pending'] }}</strong>
                    <div class="fw-bold nova-muted mt-2">Pendientes por revisar</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-lg-4">
        <a class="card nova-card rm-stat-card rm-filter-card {{ $activeDashboardFilter === 'procesados' ? 'active' : '' }}" href="{{ $dashboardRoute('procesados') }}">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="rm-stat-icon is-success"><i class="bi bi-check-circle"></i></span>
                <div>
                    <strong class="fs-2 lh-1">{{ $summary['processed'] }}</strong>
                    <div class="fw-bold nova-muted mt-2">Procesados correctamente</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-lg-4">
        <a class="card nova-card rm-stat-card rm-filter-card {{ $activeDashboardFilter === 'errores' ? 'active' : '' }}" href="{{ $dashboardRoute('errores') }}">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="rm-stat-icon is-danger"><i class="bi bi-exclamation-octagon"></i></span>
                <div>
                    <strong class="fs-2 lh-1">{{ $summary['errors'] }}</strong>
                    <div class="fw-bold nova-muted mt-2">Errores pendientes</div>
                </div>
            </div>
        </a>
    </div>
</section>

<section class="card nova-card rm-work-panel mb-4">
    <div class="card-body p-4">
        <form class="dashboard-toolbar px-3 pt-3 mb-4" method="post" action="{{ $redmineRoute('redmine.native.dashboard.action') }}" data-dashboard-bulk-form>
            @csrf
            <input type="hidden" name="dashboard_action" value="delete_selected">
            <div class="dashboard-toolbar__actions">
                <span class="dashboard-selection" data-dashboard-selected-count><i class="bi bi-check2-square"></i>Seleccionados: 0</span>
                <input type="hidden" id="ids" name="ids" data-dashboard-selected-ids>
                <div class="dashboard-toolbar__button-group rm-toolbar-actions">
                    @if ($processedActionsLocked && ($canEditReports || $canDeleteReports))
                        <button class="btn-nova btn-nova-primary btn-icon" type="button" data-dashboard-toggle-processed-actions aria-pressed="false">
                            <i class="bi bi-unlock"></i> Habilitar edición
                        </button>
                    @endif
                    @if ($showSendAction && $canEditReports)
                        <button class="btn-nova btn-nova-success btn-icon" name="dashboard_action" value="process_selected" type="submit" data-dashboard-send-redmine><i class="bi bi-check2-circle"></i>Enviar reportes a Redmine</button>
                    @endif
                    @if ($showArchiveAction && $canEditReports)
                        <button class="btn-nova btn-nova-warning btn-icon" name="dashboard_action" value="archive_selected" type="submit"><i class="bi bi-archive"></i>Archivar</button>
                    @endif
                    @if ($canDeleteReports)
                        <button class="btn-nova btn-nova-danger btn-icon" name="dashboard_action" value="delete_selected" type="submit"><i class="bi bi-trash3"></i>Eliminar seleccionados</button>
                    @endif
                    @if ($showRetryAction && $canEditReports)
                        <button class="btn-nova btn-nova-secondary btn-icon" name="dashboard_action" value="reset_errors" type="submit" title="Cambiar errores seleccionados a pendiente"><i class="bi bi-arrow-counterclockwise"></i>Reintentar errores</button>
                    @endif
                </div>
            </div>
        </form>

        <div class="table-responsive rm-table-wrap rm-table-wrap--tic-dashboard">
            <table class="table table-striped align-middle w-100 rm-dashboard-table rm-dashboard-table--tic">
                <colgroup>
                    <col class="rm-dashboard-col-select">
                    <col class="rm-dashboard-col-redmine">
                    <col class="rm-dashboard-col-subject">
                    <col class="rm-dashboard-col-requester">
                    <col class="rm-dashboard-col-date">
                    <col class="rm-dashboard-col-type">
                    <col class="rm-dashboard-col-unit">
                    <col class="rm-dashboard-col-request-unit">
                    <col class="rm-dashboard-col-status">
                    <col class="rm-dashboard-col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th class="rm-dashboard-select-cell">
                            <div class="rm-dashboard-select-control">
                                <input type="checkbox" aria-label="Seleccionar todos" data-dashboard-select-all>
                            </div>
                        </th>
                        <th>Redmine ID</th>
                        <th>Asunto</th>
                        <th>Solicitante</th>
                        <th>Fecha creación</th>
                        <th>Tipo solicitud</th>
                        <th>Unidad</th>
                        <th>Unidad solicitante</th>
                        <th>Estado local</th>
                        <th class="nova-col-actions text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($reports as $report)
                    @php
                        $reportId = (string) ($report['id'] ?? '');
                        $hasHoursExtra = in_array(strtolower($report['hora_extra'] ?? ''), ['si','1','true'], true);
                        $estadoLocal = strtolower(trim((string) ($report['estado'] ?? '')));
                        $errorLogText = (string) (($errorLogsByReport ?? [])[$reportId] ?? 'Sin registros de error para este reporte.');
                        $errorLogTarget = 'dashboard-error-log-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $reportId);
                        $reportOrigin = strtolower(trim((string) ($report['origen'] ?? '')));
                        $reportSource = $reportOrigin === 'telegram' || trim((string) ($report['chat_id_telegram'] ?? '')) !== ''
                            ? 'telegram'
                            : 'manual';
                    @endphp
                    <tr>
                        <td class="rm-dashboard-select-cell">
                            <div class="rm-dashboard-select-control">
                                <input type="checkbox" value="{{ $report['id'] ?? '' }}" aria-label="Seleccionar solicitud" data-dashboard-row-check>
                                @if ($reportSource === 'telegram')
                                    <span class="badge rounded-circle p-2 action-tooltip rm-dashboard-source-indicator is-telegram"
                                        title="Origen: Telegram"
                                        aria-label="Origen: Telegram">
                                        <i class="bi bi-telegram"></i>
                                    </span>
                                @else
                                    <span class="badge rounded-circle p-2 action-tooltip rm-dashboard-source-indicator is-manual"
                                        title="Origen: Creación manual"
                                        aria-label="Origen: Creación manual">
                                        <i class="bi bi-pencil-fill"></i>
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td>{{ $report['redmine_id'] ?? '-' }}</td>
                        <td>
                            <div class="rm-dashboard-subject" title="{{ $report['asunto'] ?? $report['mensaje'] ?? '-' }}">
                                {{ $report['asunto'] ?? $report['mensaje'] ?? '-' }}
                            </div>
                        </td>
                        <td>{{ $report['solicitante'] ?? '-' }}</td>
                        <td>{{ $fmtDate($report['fecha_inicio'] ?? $report['fecha'] ?? '') }} {{ $report['hora'] ?? '' }}</td>
                        <td>{{ $report['tipo'] ?? '-' }}</td>
                        <td>{{ $report['unidad'] ?? '-' }}</td>
                        <td>{{ $report['unidad_solicitante'] ?? '-' }}</td>
                        <td class="text-center">
                            <span class="rm-dashboard-status {{ $estadoLocal === 'procesado' ? 'is-processed' : ($estadoLocal === 'error' ? 'is-error' : 'is-pending') }}" title="{{ $estadoLocal ?: 'sin estado' }}">
                                <i class="bi {{ $estadoLocal === 'procesado' ? 'bi-check2' : ($estadoLocal === 'error' ? 'bi-exclamation-triangle' : 'bi-hourglass-split') }}"></i>
                            </span>
                        </td>
                        <td class="nova-col-actions">
                            <div class="nova-row-actions">
                                @if ($estadoLocal === 'error')
                                    <button class="btn-action btn-action-view action-tooltip" type="button"
                                        data-dashboard-error-log-button
                                        data-dashboard-error-log-target="{{ $errorLogTarget }}"
                                        title="Ver log del reporte"
                                        aria-label="Ver log del reporte">
                                        <i class="bi bi-journal-text"></i>
                                    </button>
                                    <template id="{{ $errorLogTarget }}">{{ $errorLogText }}</template>
                                @endif
                                @if ($canEditReports)
                                <button class="btn-action btn-action-edit action-tooltip" type="button"
                                    title="Ver y editar solicitud"
                                    aria-label="Ver y editar solicitud"
                                    data-nova-modal-open="editar-solicitud"
                                    @if($processedActionsLocked) disabled data-processed-action @endif
                                    data-report-id="{{ $report['id'] ?? '' }}"
                                    data-report-tipo="{{ $report['tipo'] ?? '' }}"
                                    data-report-estado="{{ $report['estado'] ?? '' }}"
                                    data-report-estado-redmine="{{ $report['estado_redmine'] ?? $report['redmine_estado'] ?? $report['status_name'] ?? 'Nueva' }}"
                                    data-report-asunto="{{ $report['asunto'] ?? $report['mensaje'] ?? '' }}"
                                    data-report-prioridad="{{ $report['prioridad'] ?? 'NORMAL' }}"
                                    data-report-categoria="{{ $report['categoria'] ?? '' }}"
                                    data-report-solicitante="{{ $report['solicitante'] ?? '' }}"
                                    data-report-unidad="{{ $report['unidad'] ?? '' }}"
                                    data-report-unidad-solicitante="{{ $report['unidad_solicitante'] ?? '' }}"
                                    data-report-asignado="{{ $report['asignado_a'] ?? '' }}"
                                    data-report-asignado-nombre="{{ $report['asignado_nombre'] ?? '' }}"
                                    data-report-hora-extra="{{ $report['hora_extra'] ?? 'NO' }}"
                                    data-report-fecha-inicio="{{ $report['fecha_inicio'] ?? $report['fecha'] ?? '' }}"
                                    data-report-fecha-fin="{{ $report['fecha_fin'] ?? $report['fecha_inicio'] ?? $report['fecha'] ?? '' }}"
                                    data-report-tiempo-estimado="{{ $report['tiempo_estimado'] ?? '' }}"
                                    data-report-fecha="{{ $report['fecha'] ?? $report['fecha_inicio'] ?? '' }}"
                                    data-report-hora="{{ $report['hora'] ?? '' }}"
                                    data-report-chat-id-telegram="{{ $report['chat_id_telegram'] ?? $report['numero'] ?? '' }}"
                                    data-report-mensaje="{{ $report['mensaje'] ?? '' }}"
                                    data-report-descripcion="{{ $report['descripcion'] ?? '' }}">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                @endif
                                @if ($canEditHoursExtra)
                                <form method="post" action="{{ $redmineRoute('redmine.native.dashboard.action') }}"
                                      data-no-page-loader="true"
                                      data-optimistic-toggle
                                      data-toggle-active-icon="bi-clock-fill" data-toggle-inactive-icon="bi-clock"
                                      data-toggle-active-class="btn-hora-extra--on" data-toggle-inactive-class="btn-hora-extra--off"
                                      data-toggle-active-title="Quitar hora extra" data-toggle-inactive-title="Marcar hora extra">
                                    @csrf
                                    <input type="hidden" name="dashboard_action" value="toggle_hours_extra">
                                    <input type="hidden" name="id" value="{{ $report['id'] ?? '' }}">
                                    <input type="hidden" name="hora_extra" value="{{ $hasHoursExtra ? '0' : '1' }}">
                                    <button class="btn-action btn-action-sync action-tooltip {{ $hasHoursExtra ? 'btn-hora-extra--on' : 'btn-hora-extra--off' }}" type="submit" title="{{ $hasHoursExtra ? 'Quitar hora extra' : 'Marcar hora extra' }}" aria-label="{{ $hasHoursExtra ? 'Quitar hora extra' : 'Marcar hora extra' }}" @if($processedActionsLocked) disabled data-processed-action @endif><i class="bi {{ $hasHoursExtra ? 'bi-clock-fill' : 'bi-clock' }}"></i></button>
                                </form>
                                @endif
                                @if ($canDeleteReports)
                                <form method="post" action="{{ $redmineRoute('redmine.native.dashboard.action') }}">
                                    @csrf
                                    <input type="hidden" name="dashboard_action" value="delete">
                                    <input type="hidden" name="id" value="{{ $report['id'] ?? '' }}">
                                    <button class="btn-action btn-action-delete action-tooltip" type="submit" title="Eliminar" aria-label="Eliminar" @if($processedActionsLocked) disabled data-processed-action @endif><i class="bi bi-trash3"></i></button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="nova-empty"><i class="bi bi-inboxes" style="font-size:1.4rem;display:block;margin-bottom:.4rem;opacity:.35"></i>No hay solicitudes activas en la cola.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal fade" id="redmine-send-loading-modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rm-redmine-send-modal">
            <div class="modal-body">
                <img src="{{ asset('assets/img/redmine.gif') }}" alt="Redmine">
                <strong>Enviando solicitudes a Redmine</strong>
                <span>Espera mientras se procesan los tickets seleccionados.</span>
                <div class="rm-redmine-send-bar"><i></i></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="dashboard-error-log-modal" tabindex="-1" aria-labelledby="dashboard-error-log-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <p class="detail-drawer-kicker">Reporte con error</p>
                    <h2 class="modal-title" id="dashboard-error-log-title">
                        <span class="detail-drawer-icon"><i class="bi bi-journal-text"></i></span>
                        Log de envío
                    </h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <pre class="bg-light border rounded p-3 mb-0 small text-break" data-dashboard-error-log-content>Sin registros de error para este reporte.</pre>
            </div>
        </div>
    </div>
</div>

<div class="modal fade detail-drawer-modal" id="editar-solicitud" tabindex="-1" aria-labelledby="editar-solicitud-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable detail-drawer-dialog">
        <form class="modal-content" method="post" action="{{ $redmineRoute('redmine.native.dashboard.action') }}">
            @csrf
            <input type="hidden" name="dashboard_action" value="update">
            <div class="modal-header">
                <div>
                    <p class="detail-drawer-kicker">Reporte seleccionado</p>
                    <h2 class="modal-title" id="editar-solicitud-title">
                        <span class="detail-drawer-icon"><i class="bi bi-pencil-square"></i></span>
                        Detalle / Editar
                    </h2>
                </div>
                <button type="button" class="btn-close" data-nova-modal-close aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="detail-drawer-view is-active" id="drawer-detail-view">
                    <div class="row g-3">
                        <input type="hidden" name="id">
                        <div class="col-12 col-md-3"><label class="form-label">Tipo</label><input class="form-control" name="tipo"></div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Estado</label>
                            <select class="form-select" name="estado">
                                <option value="pendiente">pendiente</option>
                                <option value="procesado">procesado</option>
                                <option value="error">error</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6"><label class="form-label">Asunto</label><input class="form-control" name="asunto"></div>

                        <div class="col-12 col-md-3"><label class="form-label">Prioridad</label><input class="form-control" name="prioridad"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Categorias</label><input class="form-control" name="categoria" list="rm-categories"></div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Asignado a</label>
                            <select class="form-select" name="asignado_a"><option value="">Sin asignar</option>@foreach ($users as $user)<option value="{{ $user['id'] ?? '' }}">{{ trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')) }}</option>@endforeach</select>
                            <div class="form-text fw-semibold" data-current-assignee></div>
                        </div>
                        <div class="col-12 col-md-3"><label class="form-label">Solicitante</label><input class="form-control" name="solicitante"></div>

                        <div class="col-12 col-md-3"><label class="form-label">Unidad</label><input class="form-control" name="unidad"></div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Unidad Solicitante</label>
                            <div class="nova-search-select" data-search-select data-options='@json($unitOptions)'>
                                <input class="form-control" name="unidad_solicitante" autocomplete="off" data-search-select-input>
                                <div class="nova-search-select__menu" role="listbox" data-search-select-menu hidden></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Hora extra</label>
                            <select class="form-select" name="hora_extra" @disabled(!$canEditHoursExtra)>
                                <option value="NO">No</option>
                                <option value="SI">Si</option>
                            </select>
                            @if (!$canEditHoursExtra)
                                <div class="form-text"><i class="bi bi-lock"></i> Requiere el permiso Editar de Horas extra.</div>
                            @endif
                        </div>
                        <div class="col-12 col-md-3"><label class="form-label">Fecha Inicio</label><input class="form-control" type="date" name="fecha_inicio"></div>

                        <div class="col-12 col-md-3"><label class="form-label">Fecha Fin</label><input class="form-control" type="date" name="fecha_fin"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Tiempo Estimado</label><input class="form-control" type="text" name="tiempo_estimado" placeholder="Ej: 1:30" @disabled(!$canEditHoursExtra)></div>
                        <div class="col-12 col-md-3"><label class="form-label">Fecha</label><input class="form-control" type="date" name="fecha"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Hora</label><input class="form-control" type="time" step="1" name="hora"></div>

                        <div class="col-12">
                            <div class="nova-description-tabs" role="tablist" aria-label="Vista de descripción">
                                <button type="button" class="nova-description-tab is-active" id="tic-dashboard-description-edit-tab" role="tab" aria-selected="true"><i class="bi bi-pencil"></i>Modificar</button>
                                <button type="button" class="nova-description-tab" id="tic-dashboard-description-preview-tab" role="tab" aria-selected="false"><i class="bi bi-table"></i>Previsualizar</button>
                            </div>
                            <div id="tic-dashboard-description-edit-panel" role="tabpanel" aria-labelledby="tic-dashboard-description-edit-tab">
                                <label class="form-label">Mensaje</label>
                                <textarea class="form-control nova-description-editor" name="mensaje" rows="6"></textarea>
                                <div class="form-text">Al pegar celdas desde Excel se convertirán automáticamente en una tabla.</div>
                            </div>
                            <div class="nova-description-preview" id="tic-dashboard-description-preview" role="tabpanel" aria-labelledby="tic-dashboard-description-preview-tab" hidden></div>
                        </div>
                        <input type="hidden" name="descripcion">
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="detail-drawer-footer">
                <button class="btn btn-outline-secondary" type="button" data-nova-modal-close><i class="bi bi-x-lg"></i>Cerrar</button>
                <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-check2-circle"></i>Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    let ticDashboardDescriptionTabs = null;
    const initTicDashboardDescription = () => {
        ticDashboardDescriptionTabs = window.NovaDescriptionTables?.bind({
            input: document.querySelector('#editar-solicitud [name="mensaje"]'),
            editTab: document.getElementById('tic-dashboard-description-edit-tab'),
            previewTab: document.getElementById('tic-dashboard-description-preview-tab'),
            editPanel: document.getElementById('tic-dashboard-description-edit-panel'),
            previewPanel: document.getElementById('tic-dashboard-description-preview'),
        }) || null;
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTicDashboardDescription, { once: true });
    } else {
        initTicDashboardDescription();
    }

    const dashboardSelectedInput = document.querySelector('[data-dashboard-selected-ids]');
    const dashboardSelectedCount = document.querySelector('[data-dashboard-selected-count]');
    const dashboardSelectAll = document.querySelector('[data-dashboard-select-all]');
    const dashboardRowChecks = Array.from(document.querySelectorAll('[data-dashboard-row-check]'));
    const syncDashboardSelection = () => {
        const selectableChecks = dashboardRowChecks.filter((input) => !input.disabled);
        const selectedIds = dashboardRowChecks
            .filter((input) => !input.disabled && input.checked && input.value)
            .map((input) => input.value);

        if (dashboardSelectedInput) {
            dashboardSelectedInput.value = selectedIds.join(',');
        }
        if (dashboardSelectedCount) {
            dashboardSelectedCount.innerHTML = `<i class="bi bi-check2-square"></i>Seleccionados: ${selectedIds.length}`;
        }
        if (dashboardSelectAll) {
            dashboardSelectAll.checked = selectableChecks.length > 0 && selectedIds.length === selectableChecks.length;
            dashboardSelectAll.indeterminate = selectedIds.length > 0 && selectedIds.length < selectableChecks.length;
        }
    };

    dashboardSelectAll?.addEventListener('change', () => {
        dashboardRowChecks.forEach((input) => {
            if (!input.disabled) {
                input.checked = dashboardSelectAll.checked;
            }
        });
        syncDashboardSelection();
    });
    dashboardRowChecks.forEach((input) => {
        input.addEventListener('change', syncDashboardSelection);
    });
    dashboardSelectedInput?.addEventListener('input', () => {
        const selected = new Set(dashboardSelectedInput.value.split(',').map((id) => id.trim()).filter(Boolean));
        dashboardRowChecks.forEach((input) => {
            input.checked = selected.has(input.value);
        });
        syncDashboardSelection();
    });
    const processedActionControls = Array.from(document.querySelectorAll('[data-processed-action]'));
    const dashboardViewStateKey = `nova:tic-dashboard:${window.location.pathname}:view-state`;
    const readDashboardViewState = () => {
        try {
            return JSON.parse(sessionStorage.getItem(dashboardViewStateKey) || '{}');
        } catch (error) {
            return {};
        }
    };
    const writeDashboardViewState = (changes) => {
        try {
            sessionStorage.setItem(dashboardViewStateKey, JSON.stringify({
                ...readDashboardViewState(),
                ...changes,
            }));
        } catch (error) {
            // The dashboard remains usable when browser storage is unavailable.
        }
    };
    let processedActionsEnabled = readDashboardViewState().processedActionsEnabled === true;
    const setProcessedActionsEnabled = (enabled) => {
        processedActionsEnabled = enabled;
        writeDashboardViewState({ processedActionsEnabled: enabled });
        document.querySelector('.rm-work-panel')?.classList.toggle('is-processed-locked', !enabled);
        processedActionControls.forEach((control) => {
            control.disabled = !enabled;
            control.setAttribute('aria-disabled', enabled ? 'false' : 'true');
            if (!enabled && control.matches('[data-dashboard-row-check], [data-dashboard-select-all]')) {
                control.checked = false;
            }
        });
        const button = document.querySelector('[data-dashboard-toggle-processed-actions]');
        if (button) {
            button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            button.innerHTML = enabled
                ? '<i class="bi bi-lock"></i> Desactivar edición'
                : '<i class="bi bi-unlock"></i> Habilitar edición';
        }
        syncDashboardSelection();
    };
    const processedActionsToggle = document.querySelector('[data-dashboard-toggle-processed-actions]');
    if (processedActionsToggle && processedActionsToggle.dataset.processedToggleReady !== 'true') {
        processedActionsToggle.dataset.processedToggleReady = 'true';
        processedActionsToggle.addEventListener('click', () => {
            setProcessedActionsEnabled(!processedActionsEnabled);
        });
    }
    const processedWorkPanel = document.querySelector('.rm-work-panel');
    if (processedWorkPanel && processedWorkPanel.dataset.processedLockGuardReady !== 'true') {
        processedWorkPanel.dataset.processedLockGuardReady = 'true';
        processedWorkPanel.addEventListener('click', (event) => {
            if (!processedWorkPanel.classList.contains('is-processed-locked')) return;
            if (!event.target.closest('[data-processed-action]')) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);
        processedWorkPanel.addEventListener('change', (event) => {
            if (!processedWorkPanel.classList.contains('is-processed-locked')) return;
            const checkbox = event.target.closest('input[type="checkbox"][data-processed-action]');
            if (!checkbox) return;
            checkbox.checked = false;
            event.preventDefault();
            event.stopImmediatePropagation();
            syncDashboardSelection();
        }, true);
    }
    setProcessedActionsEnabled(processedActionsEnabled);
    document.querySelector('[data-dashboard-bulk-form]')?.addEventListener('submit', (event) => {
        const submitter = event.submitter;
        if (!processedActionsEnabled && submitter?.matches('[data-processed-action]')) {
            event.preventDefault();
            return;
        }
        if (!(submitter instanceof HTMLButtonElement) || submitter.value !== 'process_selected') {
            return;
        }

        event.preventDefault();
        const form = event.currentTarget;
        form.classList.add('is-sending-redmine');
        form.setAttribute('aria-busy', 'true');
        form.querySelector('[name="dashboard_action"]').value = 'process_selected';
        submitter.disabled = true;
        submitter.innerHTML = '<i class="bi bi-arrow-repeat"></i>Enviando';
        const modal = document.getElementById('redmine-send-loading-modal');
        if (modal && window.bootstrap?.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        } else if (modal) {
            modal.classList.add('show');
            modal.removeAttribute('aria-hidden');
            modal.setAttribute('aria-modal', 'true');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        }
        window.setTimeout(() => form.submit(), 3000);
    });

    document.querySelectorAll('[data-dashboard-error-log-button]').forEach((button) => {
        button.addEventListener('click', () => {
            const template = document.getElementById(button.dataset.dashboardErrorLogTarget || '');
            const content = document.querySelector('[data-dashboard-error-log-content]');
            if (content) {
                content.textContent = template?.content?.textContent?.trim() || 'Sin registros de error para este reporte.';
            }

            const modal = document.getElementById('dashboard-error-log-modal');
            if (modal && window.bootstrap?.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modal).show();
            } else if (modal) {
                modal.classList.add('show');
                modal.removeAttribute('aria-hidden');
                modal.setAttribute('aria-modal', 'true');
                modal.style.display = 'block';
                document.body.classList.add('modal-open');
            }
        });
    });

    document.querySelectorAll('[data-nova-modal-open="editar-solicitud"]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.getElementById('editar-solicitud');
            if (!modal) return;
            const form = modal.querySelector('form');
            const toDateInput = (value) => {
                const text = String(value || '').trim();
                if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text;
                const match = text.match(/^(\d{2})-(\d{2})-(\d{4})$/);
                return match ? `${match[3]}-${match[2]}-${match[1]}` : '';
            };
            form.elements.id.value = button.dataset.reportId || '';
            form.elements.tipo.value = button.dataset.reportTipo || '';
            form.elements.estado.value = button.dataset.reportEstado || 'pendiente';
            form.elements.asunto.value = button.dataset.reportAsunto || '';
            form.elements.prioridad.value = button.dataset.reportPrioridad || '';
            form.elements.categoria.value = button.dataset.reportCategoria || '';
            form.elements.solicitante.value = button.dataset.reportSolicitante || '';
            form.elements.unidad.value = button.dataset.reportUnidad || '';
            form.elements.unidad_solicitante.value = button.dataset.reportUnidadSolicitante || '';
            form.elements.asignado_a.value = button.dataset.reportAsignado || '';
            const currentAssignee = form.querySelector('[data-current-assignee]');
            if (currentAssignee) {
                currentAssignee.textContent = button.dataset.reportAsignadoNombre ? `Actual: ${button.dataset.reportAsignadoNombre}` : '';
            }
            form.elements.hora_extra.value = (button.dataset.reportHoraExtra || 'NO').toUpperCase() === 'SI' ? 'SI' : 'NO';
            form.elements.fecha_inicio.value = toDateInput(button.dataset.reportFechaInicio);
            form.elements.fecha_fin.value = toDateInput(button.dataset.reportFechaFin);
            form.elements.tiempo_estimado.value = button.dataset.reportTiempoEstimado || '';
            form.elements.fecha.value = toDateInput(button.dataset.reportFecha);
            form.elements.hora.value = button.dataset.reportHora || '';
            form.elements.mensaje.value = button.dataset.reportMensaje || button.dataset.reportDescripcion || '';
            form.elements.descripcion.value = button.dataset.reportDescripcion || '';
            ticDashboardDescriptionTabs?.show(false);
            modal.classList.add('show');
            modal.removeAttribute('aria-hidden');
            modal.setAttribute('aria-modal', 'true');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        });
    });
    document.querySelector('#editar-solicitud form')?.addEventListener('submit', (event) => {
        const form = event.currentTarget;
        if (form?.elements?.descripcion && form?.elements?.mensaje) {
            form.elements.descripcion.value = form.elements.mensaje.value;
        }
        writeDashboardViewState({
            processedActionsEnabled,
            restoreScrollY: window.scrollY || document.documentElement.scrollTop || 0,
        });
    });
    const restoreDashboardScroll = () => {
        const state = readDashboardViewState();
        if (typeof state.restoreScrollY !== 'number') return;
        const scrollY = Number(state.restoreScrollY);
        if (!Number.isFinite(scrollY) || scrollY < 0) return;
        writeDashboardViewState({ restoreScrollY: null });
        requestAnimationFrame(() => requestAnimationFrame(() => window.scrollTo(0, scrollY)));
    };
    if (document.readyState === 'complete') {
        restoreDashboardScroll();
    } else {
        window.addEventListener('load', restoreDashboardScroll, { once: true });
    }
    // Keep the row's "editar" button in sync with the Hora Extra toggle so opening
    // the edit modal right after toggling doesn't show the pre-toggle value.
    document.addEventListener('nova-optimistic-toggle:change', (event) => {
        const editButton = event.target.closest('tr')?.querySelector('[data-nova-modal-open="editar-solicitud"]');
        if (editButton) editButton.setAttribute('data-report-hora-extra', event.detail.active ? 'SI' : 'NO');
    });
    // bootstrap.bundle.min.js loads later in native.blade.php's own <script> tags,
    // so this waits for DOMContentLoaded (fires after that script has run) instead
    // of initializing immediately, which would find `bootstrap` undefined here.
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.action-tooltip').forEach((el) => {
            window.bootstrap?.Tooltip && new window.bootstrap.Tooltip(el);
        });
    });
</script>

<datalist id="rm-categories">@foreach ($categories as $category)<option value="{{ $category['nombre'] ?? '' }}"></option>@endforeach</datalist>
