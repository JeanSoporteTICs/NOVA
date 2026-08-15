@php
    $categoryOptions = collect($categories ?? [])->map(fn ($row) => trim((string) ($row['nombre'] ?? $row['id'] ?? '')))->filter()->unique()->values();
    $unitOptions = collect($units ?? [])->map(fn ($row) => trim((string) ($row['nombre'] ?? $row['id'] ?? '')))->filter()->unique()->values();
    $activeUsers = collect($users ?? [])->filter(fn ($user) => strtolower(trim((string) ($user['estado_usuario'] ?? $user['estado'] ?? 'activo'))) === 'activo')->values();
    $sessionUser = session('redmine_project_user', session('nova_user', []));
    $sessionUser = is_array($sessionUser) ? $sessionUser : [];
    $sessionIds = collect([
        $sessionUser['redmine_id'] ?? null,
        $sessionUser['id'] ?? null,
        $sessionUser['uuid'] ?? null,
        $sessionUser['_nova_user_id'] ?? null,
    ])->map(fn ($value) => trim((string) $value))->filter()->values()->all();
    $currentAssignee = $activeUsers->first(function ($user) use ($sessionIds) {
        $ids = [
            trim((string) ($user['id'] ?? '')),
            trim((string) ($user['redmine_id'] ?? '')),
            trim((string) ($user['_nova_user_id'] ?? '')),
            trim((string) ($user['rut'] ?? '')),
            trim((string) ($user['rut_sin_dv'] ?? '')),
        ];

        return count(array_intersect(array_filter($ids), $sessionIds)) > 0;
    }) ?? [];
    $currentAssigneeId = trim((string) ($currentAssignee['redmine_id'] ?? $currentAssignee['id'] ?? $sessionUser['redmine_id'] ?? ''));
    $currentAssigneeId = ctype_digit($currentAssigneeId) ? $currentAssigneeId : '';
    $currentAssigneeName = trim((string) ($currentAssignee['nombre_completo'] ?? ''));
    if ($currentAssigneeName === '') {
        $currentAssigneeName = trim((string) (($currentAssignee['nombre'] ?? $sessionUser['nombre'] ?? $sessionUser['name'] ?? '') . ' ' . ($currentAssignee['apellido'] ?? $sessionUser['apellido'] ?? '')));
    }
    $assigneeOptions = $activeUsers->map(function ($user) {
        $userId = trim((string) ($user['redmine_id'] ?? $user['id'] ?? ''));
        if (!ctype_digit($userId)) {
            return null;
        }
        $displayName = trim((string) (($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')));
        if ($displayName === '') {
            $displayName = trim((string) ($user['usuario'] ?? $user['username'] ?? $userId));
        }

        return [
            'label' => $displayName,
            'value' => $userId,
        ];
    })->filter()->unique('value')->values();
    $today = now('America/Santiago')->format('Y-m-d');
    $timeNow = now('America/Santiago')->format('H:i');
@endphp

<!-- <section class="rm-module-head">
    <span class="rm-module-head-icon is-orange"><i class="bi bi-pencil-square"></i></span>
    <div>
        <small>Ingreso manual</small>
        <h2>Reporte manual</h2>
        <p>Crea un pendiente con datos completos para revisar y enviar a Redmine.</p>
    </div>
    <div class="rm-module-meter">
         <strong>{{ $activeUsers->count() }}</strong>
        <span>asignables</span> 
    </div>
</section> -->

<section class="row g-3 align-items-start rm-manual-view">
    <div class="col-12">
        <form class="card nova-card rm-panel h-100 rm-manual-panel" method="post" action="{{ $redmineRoute('redmine.native.webhook.action') }}">
            @csrf
            <div class="rm-section-head">
                <div>
                    <h2>Crear reporte manual</h2>
                    <p>El reporte queda en pendientes para revisar, editar o enviar a Redmine.</p>
                </div>
                <!-- <span class="nova-badge is-warning"><i class="bi bi-inbox"></i>Pendiente</span> -->
            </div>

            <div class="row g-3">
                <div class="col-md-6 rm-manual-horizontal-field">
                    <label class="form-label" for="manual-tipo">Tipo</label>
                    <input class="form-control" id="manual-tipo" name="tipo" value="Soporte" maxlength="80">
                </div>
                <div class="col-md-6 rm-manual-horizontal-field">
                    <label class="form-label" for="manual-prioridad">Prioridad</label>
                    <select class="form-select tic-webhook-select2" id="manual-prioridad" name="prioridad" data-tic-webhook-select2 data-placeholder="Seleccionar prioridad">
                        <option value="NORMAL">NORMAL</option>
                        <option value="BAJA">BAJA</option>
                        <option value="ALTA">ALTA</option>
                        <option value="URGENTE">URGENTE</option>
                    </select>
                </div>

                <div class="col-12 rm-manual-horizontal-field">
                    <label class="form-label" for="manual-asunto">Problema</label>
                    <input class="form-control" id="manual-asunto" name="asunto" maxlength="220" required placeholder="Ej: Impresora no imprime">
                </div>

                <div class="col-12 rm-manual-description-field">
                    <label class="form-label" for="manual-descripcion">Descripcion</label>
                    <div class="rm-manual-description-control">
                        <div class="nova-description-tabs" role="tablist" aria-label="Vista de descripción">
                            <button type="button" class="nova-description-tab is-active" id="tic-manual-description-edit-tab" role="tab" aria-selected="true"><i class="bi bi-pencil"></i>Modificar</button>
                            <button type="button" class="nova-description-tab" id="tic-manual-description-preview-tab" role="tab" aria-selected="false"><i class="bi bi-table"></i>Previsualizar</button>
                        </div>
                        <div id="tic-manual-description-edit-panel" role="tabpanel" aria-labelledby="tic-manual-description-edit-tab">
                            <textarea class="form-control nova-description-editor" id="manual-descripcion" name="descripcion" rows="5" maxlength="4000" placeholder="Detalle breve del problema, contacto, equipo afectado u observaciones"></textarea>
                            <!-- <div class="form-text">Al pegar celdas desde Excel se convertirán automáticamente en una tabla.</div> -->
                        </div>
                        <div class="nova-description-preview" id="tic-manual-description-preview" role="tabpanel" aria-labelledby="tic-manual-description-preview-tab" hidden></div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="rm-manual-field-stack">
                        <div class="rm-manual-horizontal-field">
                            <label class="form-label" for="manual-asignado">Asignar a</label>
                            <div class="rm-assignee-row">
                                <div class="rm-assignee-select">
                                    <select class="form-select tic-webhook-select2" id="manual-asignado" name="asignado_a" data-tic-webhook-select2 data-placeholder="Seleccionar usuario activo">
                                        <option value="">Sin asignar</option>
                                        @foreach ($assigneeOptions as $userOption)
                                            <option value="{{ $userOption['value'] }}" @selected((string) $userOption['value'] === $currentAssigneeId)>{{ $userOption['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="button"
                                    class="btn btn-outline-secondary rm-assign-me-btn"
                                    data-assign-me-tic
                                    data-assign-me-id="{{ $currentAssigneeId }}"
                                    data-assign-me-name="{{ $currentAssigneeName }}"
                                    @disabled($currentAssigneeId === '' && $currentAssigneeName === '')>
                                    <i class="bi bi-person-check"></i> Autoasignarme
                                </button>
                            </div>
                        </div>

                        <div class="rm-manual-horizontal-field">
                            <label class="form-label" for="manual-categoria">Categoria</label>
                            <select class="form-select tic-webhook-select2" id="manual-categoria" name="categoria" data-tic-webhook-select2 data-placeholder="Seleccionar categoria">
                                <option value=""></option>
                                @foreach ($categoryOptions as $categoryOption)
                                    <option value="{{ $categoryOption }}">{{ $categoryOption }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="rm-manual-horizontal-field">
                            <label class="form-label" for="manual-solicitante">Solicitante</label>
                            <input class="form-control" id="manual-solicitante" name="solicitante" maxlength="160" placeholder="Nombre de quien solicita">
                        </div>

                        <div class="rm-manual-inline-grid">
                            <div class="rm-manual-horizontal-field">
                                <label class="form-label" for="manual-unidad">Ubicacion</label>
                                <input class="form-control" id="manual-unidad" name="unidad" maxlength="180" placeholder="Ej: SOME HBV">
                            </div>
                            <div class="rm-manual-horizontal-field">
                                <label class="form-label" for="manual-unidad-solicitante">Unidad solicitante</label>
                                <select class="form-select tic-webhook-select2" id="manual-unidad-solicitante" name="unidad_solicitante" data-tic-webhook-select2 data-placeholder="Seleccionar unidad solicitante">
                                    <option value=""></option>
                                    @foreach ($unitOptions as $unitOption)
                                        <option value="{{ $unitOption }}">{{ $unitOption }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="manual-extra-row">
                            <div class="rm-manual-horizontal-field">
                                <label class="form-label" for="manual-hora-extra">Hora extra</label>
                                <select class="form-select tic-webhook-select2" id="manual-hora-extra" name="hora_extra" data-tic-webhook-select2 data-placeholder="Seleccionar hora extra">
                                    <option value="NO">No</option>
                                    <option value="SI">Sí</option>
                                </select>
                            </div>
                            <div class="rm-manual-horizontal-field">
                                <label class="form-label" for="manual-tiempo-estimado">Tiempo estimado</label>
                                <input class="form-control" id="manual-tiempo-estimado" type="text" name="tiempo_estimado" placeholder="Ej: 1:30">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="rm-manual-date-stack">
                        <div class="rm-manual-horizontal-field">
                            <label class="form-label" for="manual-fecha-inicio">Fecha inicio</label>
                            <input class="form-control" id="manual-fecha-inicio" type="date" name="fecha_inicio" value="{{ $today }}">
                        </div>
                        <div class="rm-manual-horizontal-field">
                            <label class="form-label" for="manual-fecha-fin">Fecha fin</label>
                            <input class="form-control" id="manual-fecha-fin" type="date" name="fecha_fin" value="{{ $today }}">
                        </div>
                        <div class="rm-manual-horizontal-field">
                            <label class="form-label" for="manual-fecha">Fecha reporte</label>
                            <input class="form-control" id="manual-fecha" type="date" name="fecha" value="{{ $today }}">
                        </div>
                        <div class="rm-manual-horizontal-field">
                            <label class="form-label" for="manual-hora">Hora</label>
                            <input class="form-control" id="manual-hora" type="time" name="hora" value="{{ $timeNow }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="rm-manual-actions">
                <button class="btn-nova btn-nova-success" type="submit"><i class="bi bi-plus-circle"></i>Crear pendiente</button>
                <a class="btn btn-outline-secondary" href="{{ $redmineRoute('redmine.native.section', 'dashboard') }}"><i class="bi bi-inboxes"></i>Ver pendientes</a>
            </div>
        </form>
    </div>

</section>

@php $redmineTicWebhookCssVersion = @filemtime(public_path('assets/redmine-tic-webhook.css')) ?: '1'; @endphp
<link href="{{ asset('assets/redmine-tic-webhook.css') }}?v={{ $redmineTicWebhookCssVersion }}" rel="stylesheet">

<script>
    const initTicWebhookSelect2 = () => {
        if (!window.jQuery?.fn?.select2) return;
        window.jQuery('[data-tic-webhook-select2]').each(function () {
            const select = window.jQuery(this);
            if (select.hasClass('select2-hidden-accessible')) return;
            select.select2({
                width: '100%',
                allowClear: false,
                dropdownCssClass: 'tic-select2-dropdown',
                placeholder: this.dataset.placeholder || 'Seleccionar',
                language: {
                    noResults: () => 'No se encontraron resultados',
                    searching: () => 'Buscando...'
                }
            });
        });
    };
    const initTicManualDescription = () => {
        window.NovaDescriptionTables?.bind({
            input: document.getElementById('manual-descripcion'),
            editTab: document.getElementById('tic-manual-description-edit-tab'),
            previewTab: document.getElementById('tic-manual-description-preview-tab'),
            editPanel: document.getElementById('tic-manual-description-edit-panel'),
            previewPanel: document.getElementById('tic-manual-description-preview'),
        });
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            initTicManualDescription();
            initTicWebhookSelect2();
        }, { once: true });
    } else {
        initTicManualDescription();
        initTicWebhookSelect2();
    }

    (() => {
        const button = document.querySelector('[data-assign-me-tic]');
        const assigneeSelect = document.getElementById('manual-asignado');
        if (!button || !assigneeSelect) return;

        button.addEventListener('click', () => {
            const assigneeId = button.dataset.assignMeId || '';
            const match = Array.from(assigneeSelect.options).find((option) => option.value === assigneeId);

            if (!match) {
                window.NovaToast?.warning?.('No se encontró tu usuario activo en TIC.');
                return;
            }

            assigneeSelect.value = assigneeId;
            if (window.jQuery?.fn?.select2) {
                window.jQuery(assigneeSelect).trigger('change');
            } else {
                assigneeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    })();
</script>
