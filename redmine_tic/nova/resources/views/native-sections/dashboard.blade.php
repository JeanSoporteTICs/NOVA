@php
    $activeDashboardFilter = $summary['filter'] ?? $dashboardFilter ?? 'todos';
    $dashboardRoute = static fn (string $estado) => $redmineRoute('redmine.native.section', ['section' => 'dashboard', 'estado' => $estado]);
    $showSendAction = in_array($activeDashboardFilter, ['todos', 'pendientes'], true);
    $showArchiveAction = in_array($activeDashboardFilter, ['todos', 'pendientes', 'procesados'], true);
    $showRetryAction = $activeDashboardFilter === 'errores';
    $fmtDate = static function ($value): string {
        $value = trim((string) $value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date) {
                return $date->format('d-m-Y');
            }
        }

        return $value ?: '-';
    };
@endphp

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
        <div class="rm-section-head">
            <div>
                <h2>Solicitudes activas</h2>
                <p>Gestiona la cola actual con estados locales y acciones disponibles.</p>
            </div>
            <div class="rm-filter-summary">
                @if ($activeDashboardFilter !== 'todos')
                    <a class="btn btn-outline-secondary" href="{{ $dashboardRoute('todos') }}"><i class="bi bi-grid-3x3-gap"></i>Ver todos</a>
                @endif
                <span class="nova-badge is-success">
                    <i class="bi bi-table"></i> Filas visibles: {{ $summary['visible_total'] ?? $summary['active_total'] }}
                </span>
            </div>
        </div>

        <form class="mb-4" method="post" action="{{ $redmineRoute('redmine.native.dashboard.action') }}" data-dashboard-bulk-form>
            @csrf
            <input type="hidden" name="dashboard_action" value="archive_selected">
            <div class="rm-bulk-row">
                <div>
                    <span class="btn btn-outline-secondary w-100" data-dashboard-selected-count><i class="bi bi-check2-square"></i>Seleccionados: 0</span>
                </div>
                <input type="hidden" id="ids" name="ids" data-dashboard-selected-ids>
                <div>
                    <div class="rm-toolbar-actions">
                        @if ($showSendAction)
                            <button class="btn btn-success" name="dashboard_action" value="process_selected" type="submit" data-dashboard-send-redmine><i class="bi bi-check-circle"></i>Enviar a Redmine</button>
                        @endif
                        @if ($showArchiveAction)
                            <button class="btn btn-warning" name="dashboard_action" value="archive_selected" type="submit"><i class="bi bi-archive"></i>Archivar</button>
                        @endif
                        <button class="btn btn-danger" name="dashboard_action" value="delete_selected" type="submit"><i class="bi bi-trash"></i>Eliminar</button>
                        @if ($showRetryAction)
                            <button class="btn btn-outline-secondary" name="dashboard_action" value="reset_errors" type="submit"><i class="bi bi-arrow-repeat"></i>Reintentar</button>
                        @endif
                    </div>
                </div>
            </div>
        </form>

        <div class="table-responsive rm-table-wrap">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th><input type="checkbox" aria-label="Seleccionar todos" data-dashboard-select-all></th>
                        <th>Redmine ID</th>
                        <th>Asunto</th>
                        <th>Solicitante</th>
                        <th>Fecha creacion</th>
                        <th>Tipo</th>
                        <th>Establecimiento</th>
                        <th>Departamento</th>
                        <th>Asignado</th>
                        <th>Estado local</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($reports as $report)
                    @php $hasHoursExtra = in_array(strtolower($report['hora_extra'] ?? ''), ['si','1','true'], true); @endphp
                    <tr>
                        <td><input type="checkbox" value="{{ $report['id'] ?? '' }}" aria-label="Seleccionar solicitud" data-dashboard-row-check></td>
                        <td>{{ $report['redmine_id'] ?? '-' }}</td>
                        <td>{{ $report['asunto'] ?? $report['mensaje'] ?? '-' }}</td>
                        <td>{{ $report['solicitante'] ?? '-' }}</td>
                        <td>{{ $fmtDate($report['fecha_inicio'] ?? $report['fecha'] ?? '') }}</td>
                        <td>{{ $report['tipo'] ?? '-' }}</td>
                        <td>{{ $report['unidad_solicitante'] ?? '-' }}</td>
                        <td>{{ $report['unidad'] ?? '-' }}</td>
                        <td>{{ $report['asignado_nombre'] ?? '-' }}</td>
                        <td><span class="nova-badge">{{ $report['estado'] ?? 'sin estado' }}</span></td>
                        <td>
                            <div class="nova-row-actions">
                                <button class="btn btn-primary nova-btn-icon" type="button"
                                    data-nova-modal-open="editar-solicitud"
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
                                    data-report-numero="{{ $report['numero'] ?? '' }}"
                                    data-report-mensaje=""
                                    data-report-descripcion="{{ $report['descripcion'] ?? '' }}">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <form method="post" action="{{ $redmineRoute('redmine.native.dashboard.action') }}">
                                    @csrf
                                    <input type="hidden" name="dashboard_action" value="toggle_hours_extra">
                                    <input type="hidden" name="id" value="{{ $report['id'] ?? '' }}">
                                    <input type="hidden" name="hora_extra" value="{{ $hasHoursExtra ? '0' : '1' }}">
                                    <button class="btn {{ $hasHoursExtra ? 'btn-warning' : 'btn-outline-secondary' }} nova-btn-icon" type="submit" title="{{ $hasHoursExtra ? 'Quitar hora extra' : 'Marcar hora extra' }}" aria-label="{{ $hasHoursExtra ? 'Quitar hora extra' : 'Marcar hora extra' }}"><i class="bi {{ $hasHoursExtra ? 'bi-clock-fill' : 'bi-clock' }}"></i></button>
                                </form>
                                <form method="post" action="{{ $redmineRoute('redmine.native.dashboard.action') }}">
                                    @csrf
                                    <input type="hidden" name="dashboard_action" value="delete">
                                    <input type="hidden" name="id" value="{{ $report['id'] ?? '' }}">
                                    <button class="btn btn-danger nova-btn-icon" type="submit"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11">No hay solicitudes activas.</td></tr>
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
                            <input class="form-control bg-body-secondary" name="_estado_readonly" readonly>
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

                        <div class="col-12 col-md-3"><label class="form-label">Unidad</label><input class="form-control" name="unidad" list="rm-units"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Unidad Solicitante</label><input class="form-control" name="unidad_solicitante" list="rm-units"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Estado Redmine</label><input class="form-control bg-body-secondary" name="_estado_redmine_readonly" readonly></div>
                        <div class="col-12 col-md-3"><label class="form-label">Hora extra</label><select class="form-select" name="hora_extra"><option value="NO">No</option><option value="SI">Si</option></select></div>

                        <div class="col-12 col-md-3"><label class="form-label">Fecha Inicio</label><input class="form-control" type="date" name="fecha_inicio"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Fecha Fin</label><input class="form-control" type="date" name="fecha_fin"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Tiempo Estimado</label><input class="form-control" name="tiempo_estimado"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Fecha</label><input class="form-control" type="date" name="fecha"></div>

                        <div class="col-12 col-md-3"><label class="form-label">Hora</label><input class="form-control" type="time" step="1" name="hora"></div>
                        <div class="col-12 col-md-3"><label class="form-label">Numero</label><input class="form-control" name="numero"></div>
                        <div class="col-12"><label class="form-label">Mensaje</label><textarea class="form-control" name="mensaje" rows="3"></textarea></div>
                        <input type="hidden" name="descripcion">
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="detail-drawer-footer">
                <button class="btn btn-outline-secondary" type="button" data-nova-modal-close><i class="bi bi-x-lg"></i>Cerrar</button>
                <button class="btn btn-success" type="submit"><i class="bi bi-check2-circle"></i>Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    const dashboardSelectedInput = document.querySelector('[data-dashboard-selected-ids]');
    const dashboardSelectedCount = document.querySelector('[data-dashboard-selected-count]');
    const dashboardSelectAll = document.querySelector('[data-dashboard-select-all]');
    const dashboardRowChecks = Array.from(document.querySelectorAll('[data-dashboard-row-check]'));
    const syncDashboardSelection = () => {
        const selectedIds = dashboardRowChecks
            .filter((input) => input.checked && input.value)
            .map((input) => input.value);

        if (dashboardSelectedInput) {
            dashboardSelectedInput.value = selectedIds.join(',');
        }
        if (dashboardSelectedCount) {
            dashboardSelectedCount.innerHTML = `<i class="bi bi-check2-square"></i>Seleccionados: ${selectedIds.length}`;
        }
        if (dashboardSelectAll) {
            dashboardSelectAll.checked = dashboardRowChecks.length > 0 && selectedIds.length === dashboardRowChecks.length;
            dashboardSelectAll.indeterminate = selectedIds.length > 0 && selectedIds.length < dashboardRowChecks.length;
        }
    };

    dashboardSelectAll?.addEventListener('change', () => {
        dashboardRowChecks.forEach((input) => {
            input.checked = dashboardSelectAll.checked;
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
    syncDashboardSelection();
    document.querySelector('[data-dashboard-bulk-form]')?.addEventListener('submit', (event) => {
        const submitter = event.submitter;
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
            form.elements._estado_readonly.value = button.dataset.reportEstado || '';
            form.elements._estado_redmine_readonly.value = button.dataset.reportEstadoRedmine || '';
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
            form.elements.numero.value = button.dataset.reportNumero || '';
            form.elements.mensaje.value = button.dataset.reportDescripcion || '';
            form.elements.descripcion.value = button.dataset.reportDescripcion || '';
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
    });
</script>

<datalist id="rm-categories">@foreach ($categories as $category)<option value="{{ $category['nombre'] ?? '' }}"></option>@endforeach</datalist>
<datalist id="rm-units">@foreach ($units as $unit)<option value="{{ $unit['nombre'] ?? '' }}"></option>@endforeach</datalist>
