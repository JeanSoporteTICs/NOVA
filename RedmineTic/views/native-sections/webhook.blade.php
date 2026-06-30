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
    $currentAssigneeName = trim((string) (($currentAssignee['nombre'] ?? $sessionUser['nombre'] ?? '') . ' ' . ($currentAssignee['apellido'] ?? $sessionUser['apellido'] ?? '')));
    $today = now('America/Santiago')->format('Y-m-d');
    $timeNow = now('America/Santiago')->format('H:i');
@endphp

<section class="rm-module-head">
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
</section>

<section class="row g-3 align-items-start rm-manual-view">
    <div class="col-12">
        <form class="card nova-card rm-panel h-100 rm-manual-panel" method="post" action="{{ $redmineRoute('redmine.native.webhook.action') }}">
            @csrf
            <div class="rm-section-head">
                <div>
                    <h2>Crear reporte manual</h2>
                    <p>El reporte queda en pendientes para revisar, editar o enviar a Redmine.</p>
                </div>
                <span class="nova-badge is-warning"><i class="bi bi-inbox"></i>Pendiente</span>
            </div>

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="manual-asunto">Problema</label>
                    <input class="form-control" id="manual-asunto" name="asunto" maxlength="220" required placeholder="Ej: Impresora no imprime">
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="manual-unidad">Ubicacion</label>
                    <input class="form-control" id="manual-unidad" name="unidad" maxlength="180" placeholder="Ej: SOME HBV">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="manual-unidad-solicitante">Unidad solicitante</label>
                    <input class="form-control" id="manual-unidad-solicitante" name="unidad_solicitante" list="manual-units" maxlength="180" placeholder="Ej: SOME HBV">
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="manual-solicitante">Solicitante</label>
                    <input class="form-control" id="manual-solicitante" name="solicitante" maxlength="160" placeholder="Nombre de quien solicita">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="manual-categoria">Categoria</label>
                    <input class="form-control" id="manual-categoria" name="categoria" list="manual-categories" maxlength="180" placeholder="Ej: Equipos">
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="manual-tipo">Tipo</label>
                    <input class="form-control" id="manual-tipo" name="tipo" value="Soporte" maxlength="80">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="manual-prioridad">Prioridad</label>
                    <select class="form-select" id="manual-prioridad" name="prioridad">
                        <option value="NORMAL">NORMAL</option>
                        <option value="BAJA">BAJA</option>
                        <option value="ALTA">ALTA</option>
                        <option value="URGENTE">URGENTE</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="manual-asignado">Asignar a</label>
                    <select class="form-select" id="manual-asignado" name="asignado_a">
                        <option value="{{ $currentAssigneeId }}">Mi usuario{{ $currentAssigneeName !== '' ? ': ' . $currentAssigneeName : '' }}</option>
                        @foreach ($activeUsers as $user)
                            @php($displayName = trim((string) (($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? ''))) ?: (string) ($user['id'] ?? ''))
                            @php($userId = trim((string) ($user['redmine_id'] ?? $user['id'] ?? '')))
                            @if (ctype_digit($userId))
                                <option value="{{ $userId }}" @selected($userId !== '' && $userId === $currentAssigneeId)>{{ $displayName }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="manual-fecha-inicio">Fecha inicio</label>
                    <input class="form-control" id="manual-fecha-inicio" type="date" name="fecha_inicio" value="{{ $today }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="manual-fecha-fin">Fecha fin</label>
                    <input class="form-control" id="manual-fecha-fin" type="date" name="fecha_fin" value="{{ $today }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="manual-fecha">Fecha reporte</label>
                    <input class="form-control" id="manual-fecha" type="date" name="fecha" value="{{ $today }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="manual-hora">Hora</label>
                    <input class="form-control" id="manual-hora" type="time" name="hora" value="{{ $timeNow }}">
                </div>

                <div class="col-12">
                    <label class="form-label" for="manual-descripcion">Descripcion</label>
                    <textarea class="form-control" id="manual-descripcion" name="descripcion" rows="5" maxlength="4000" placeholder="Detalle breve del problema, contacto, equipo afectado u observaciones"></textarea>
                </div>

                <div class="col-12">
                    <div class="manual-extra-row">
                        <label class="form-check form-switch manual-switch" for="manual-hora-extra">
                            <input class="form-check-input" type="checkbox" id="manual-hora-extra" name="hora_extra" value="SI" data-manual-extra-toggle>
                            <span>Hora extra</span>
                        </label>
                        <div class="manual-extra-time" data-manual-extra-time hidden>
                            <input class="form-control" id="manual-tiempo-estimado" type="text" name="tiempo_estimado" placeholder="Ej: 1:30" aria-label="Tiempo estimado">
                        </div>
                    </div>
                </div>
            </div>

            <datalist id="manual-categories">
                @foreach ($categoryOptions as $option)
                    <option value="{{ $option }}"></option>
                @endforeach
            </datalist>
            <datalist id="manual-units">
                @foreach ($unitOptions as $option)
                    <option value="{{ $option }}"></option>
                @endforeach
            </datalist>

            <div class="rm-manual-actions">
                <button class="btn-nova btn-nova-success" type="submit"><i class="bi bi-plus-circle"></i>Crear pendiente</button>
                <a class="btn btn-outline-secondary" href="{{ $redmineRoute('redmine.native.section', 'dashboard') }}"><i class="bi bi-inboxes"></i>Ver pendientes</a>
            </div>
        </form>
    </div>

</section>

<style>
    .rm-manual-view .rm-panel { padding: 14px; }
    .rm-manual-view .rm-section-head { margin-bottom: 10px; }
    .rm-manual-view .rm-section-head p { margin-top: 2px; }
    .rm-manual-view .rm-kv { grid-template-columns: 92px minmax(0, 1fr); padding: 7px 0; }
    .rm-manual-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
    .manual-extra-row { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; padding-top: 2px; }
    .manual-switch { display: inline-flex; align-items: center; gap: 9px; min-height: 40px; margin: 0; font-weight: 800; color: #334155; white-space: nowrap; }
    .manual-switch .form-check-input { width: 2.6rem; height: 1.35rem; margin: 0; }
    .manual-extra-time { flex: 0 1 260px; min-width: 220px; }
    @media (max-width: 575.98px) {
        .manual-extra-row { align-items: stretch; }
        .manual-extra-time { flex-basis: 100%; min-width: 0; }
    }
</style>

<script>
    (() => {
        const toggle = document.querySelector('[data-manual-extra-toggle]');
        const timeField = document.querySelector('[data-manual-extra-time]');
        const sync = () => {
            if (!timeField || !toggle) return;
            timeField.hidden = !toggle.checked;
        };
        toggle?.addEventListener('change', sync);
        sync();
    })();
</script>
