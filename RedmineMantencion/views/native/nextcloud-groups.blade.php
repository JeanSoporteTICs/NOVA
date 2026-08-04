@extends('redmine_mantencion::native.layout')

@push('styles')
    @php
        $nextcloudUsersCss = base_path('RedmineMantencion/assets/css/nextcloud-usuarios.css');
    @endphp
    <link rel="stylesheet" href="{{ url('/redmine-mantencion/assets/css/nextcloud-usuarios.css') }}?v={{ @filemtime($nextcloudUsersCss) ?: 1 }}">
@endpush

@section('content')
@php
    $previewRows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
    $previewGroups = is_array($preview['groups'] ?? null) ? $preview['groups'] : [];
    $hasInvalidPreview = false;
    foreach ($previewRows as $row) {
        if (($row['group'] ?? '') === '' || empty($row['email_valid']) || !empty($row['duplicate'])) {
            $hasInvalidPreview = true;
            break;
        }
    }
    $resultRows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
@endphp
<div class="container-fluid py-4">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-people-fill',
        'title' => 'Grupos Nextcloud',
        'subtitle' => 'Carga masiva de usuarios en Nextcloud desde CSV o XLSX, con asignación de grupos existentes.',
    ])
    @if(session('mantencion_status'))<div data-nova-flash="{{ session('mantencion_status_type','success') }}" data-nova-flash-message="{{ session('mantencion_status') }}" hidden></div>@endif
    @if($errors->any())<div data-nova-flash="danger" data-nova-flash-message="{{ $errors->first() }}" hidden></div>@endif

    <section class="card nextcloud-panel mb-3">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-4 bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center" style="width:48px;height:48px;"><i class="bi bi-file-earmark-spreadsheet fs-4"></i></div>
                    <div><h5 class="mb-0">Archivo de usuarios</h5><div class="text-muted small">Sube un CSV o XLSX con los usuarios a crear.</div></div>
                </div>
                <span class="badge text-bg-light border">API OCS</span>
            </div>
            <form method="POST" action="{{ route('redmine.mantencion.nextcloud.groups.preview') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <label class="form-label mb-0">Archivo CSV o XLSX</label>
                        <a class="btn-nova btn-nova-success" href="{{ url('/redmine-mantencion/assets/templates/plantilla-usuarios-nextcloud-v2.xlsx') }}" download><i class="bi bi-file-earmark-excel"></i> Descargar plantilla</a>
                    </div>
                    <input type="file" name="archivo" class="form-control" accept=".csv,.xlsx" required>
                    <div class="form-text">Columnas: usuario/userid, nombre/display_name, email, grupo y password opcional.</div>
                </div>
                <div class="mb-3 col-md-4"><label class="form-label">Grupo predeterminado</label><input class="form-control" name="grupo" placeholder="Ej. Mantención"></div>
                <button class="btn-nova btn-nova-primary" @disabled(!empty($context['maintenance']['enabled']))><i class="bi bi-eye"></i> Previsualizar usuarios</button>
            </form>
        </div>
    </section>

    @if($previewRows)
        <section class="card nextcloud-panel mb-3">
            <div class="card-body p-4">
                <form method="POST" action="{{ route('redmine.mantencion.nextcloud.groups.confirm') }}" id="nextcloud-preview-form">
                    @csrf
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-4 bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:48px;height:48px;"><i class="bi bi-table fs-4"></i></div>
                            <div><h5 class="mb-0">Previsualización de envío</h5><div class="text-muted small">Marca filas para cambiar su grupo o elimina las que no quieras crear. Los correos inválidos deben corregirse en el archivo y volver a cargarse.</div></div>
                        </div>
                        <button type="submit" class="btn-nova btn-nova-success" id="nextcloud-confirm-btn" data-maintenance="{{ !empty($context['maintenance']['enabled']) ? '1' : '0' }}" @disabled(!empty($context['maintenance']['enabled']) || $hasInvalidPreview)><i class="bi bi-cloud-arrow-up"></i> Confirmar creación</button>
                    </div>

                    <div class="nextcloud-group-tools mb-3">
                        <div class="row g-2 align-items-start">
                            <div class="col-lg-6">
                                <label class="form-label">Buscar grupo existente</label>
                                <input type="search" class="form-control" id="nextcloud-group-search" placeholder="Buscar grupo en tiempo real" autocomplete="off" list="nextcloud-group-list">
                                <datalist id="nextcloud-group-list"></datalist>
                            </div>
                            <div class="col-lg-2">
                                <label class="form-label">Cuota</label>
                                <select class="form-select" id="nextcloud-bulk-quota">
                                    <option value="">Predeterminada</option>
                                    <option value="none">Ilimitado</option>
                                    <option value="1 GB">1 GB</option>
                                    <option value="5 GB">5 GB</option>
                                    <option value="10 GB">10 GB</option>
                                </select>
                            </div>
                            <div class="col-lg-2 d-grid pt-lg-4"><button type="button" class="btn-nova btn-nova-primary" id="nextcloud-apply-changes" disabled><i class="bi bi-check2-square"></i> Aplicar cambios</button></div>
                        </div>
                        <div class="form-text mt-2">Solo se pueden aplicar grupos existentes consultados desde Nextcloud.</div>
                    </div>

                    <div class="table-responsive border rounded-4 overflow-hidden">
                        <table class="table table-sm mb-0 align-middle nextcloud-preview-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:44px;"><input type="checkbox" class="form-check-input" id="nextcloud-check-all" aria-label="Seleccionar todos"></th>
                                    <th>Nombre de usuario</th><th>Nombre a desplegar</th><th>Correo</th><th>Grupo</th><th>Cuota</th><th>Contraseña</th><th style="width:58px;">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($previewRows as $idx => $row)
                                    <tr data-nextcloud-row>
                                        <td><input type="checkbox" class="form-check-input nextcloud-row-check" aria-label="Seleccionar fila"></td>
                                        <td class="fw-bold">
                                            {{ $row['userid'] ?? '' }}
                                            @if(!empty($row['userid_normalized']) && ($row['raw_userid'] ?? '') !== '')<span class="badge text-bg-info ms-1">Normalizado desde {{ $row['raw_userid'] }}</span>@endif
                                            @if(!empty($row['duplicate']))<span class="badge text-bg-danger ms-1">Duplicado</span>@endif
                                            <input type="hidden" name="users[{{ $idx }}][userid]" value="{{ $row['userid'] ?? '' }}">
                                            <input type="hidden" name="users[{{ $idx }}][display_name]" value="{{ $row['display_name'] ?? '' }}">
                                            <input type="hidden" name="users[{{ $idx }}][email]" value="{{ $row['email'] ?? '' }}">
                                            <input type="hidden" name="users[{{ $idx }}][password]" value="{{ $row['password'] ?? '' }}">
                                            <input type="hidden" name="users[{{ $idx }}][group]" value="{{ $row['group'] ?? '' }}" class="nextcloud-row-group-input">
                                        </td>
                                        <td>{{ $row['display_name'] ?? '' }}</td>
                                        <td>{{ $row['email'] ?? '' }}@if(empty($row['email_valid']))<span class="badge text-bg-danger ms-1">Correo inválido</span>@endif</td>
                                        <td>
                                            @if(($row['group'] ?? '') !== '')
                                                <span class="badge text-bg-success nextcloud-group-badge" data-group-label>{{ $row['group'] }}</span>
                                            @else
                                                <span class="badge text-bg-warning nextcloud-group-badge" data-group-label>Sin coincidencia</span>
                                            @endif
                                        </td>
                                        <td>
                                            <select name="users[{{ $idx }}][quota]" class="form-select form-select-sm nextcloud-row-quota">
                                                <option value="">Predeterminada</option>
                                                <option value="none">Ilimitado</option>
                                                <option value="1 GB">1 GB</option>
                                                <option value="5 GB">5 GB</option>
                                                <option value="10 GB">10 GB</option>
                                            </select>
                                        </td>
                                        <td class="fw-bold text-primary">{{ $row['password'] ?? '' }}</td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger nextcloud-remove-row" aria-label="Eliminar fila"><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </section>
    @endif

    @if($resultRows)
        <section class="card nextcloud-panel mb-3">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-4 bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:48px;height:48px;"><i class="bi bi-table fs-4"></i></div>
                        <div><h5 class="mb-0">Resultado de importación</h5><div class="text-muted small">Todos los usuarios enviados, indicando si fueron creados, ya existían o fallaron. Queda disponible en el Historial.</div></div>
                    </div>
                    <button type="button" class="btn btn-outline-primary" data-copy-table="#nextcloud-result-table"><i class="bi bi-clipboard"></i> Copiar tabla</button>
                </div>
                <div class="table-responsive border rounded-4 overflow-hidden">
                    <table class="table table-sm mb-0 align-middle" id="nextcloud-result-table">
                        <thead class="table-light"><tr><th>Estado</th><th>Nombre de usuario</th><th>Nombre a desplegar</th><th>Correo</th><th>Grupo</th><th>Detalle</th></tr></thead>
                        <tbody>
                            @foreach($resultRows as $row)
                                @php
                                    $status = $row['status'] ?? '';
                                    [$badge, $label, $rowClass] = match($status) {
                                        'created' => ['success', 'Creado', 'table-success'],
                                        'existing' => ['warning', 'Ya existe', 'table-warning'],
                                        default => ['danger', 'No creado', ''],
                                    };
                                @endphp
                                <tr class="{{ $rowClass }}">
                                    <td><span class="badge text-bg-{{ $badge }}">{{ $label }}</span></td>
                                    <td>{{ $row['userid'] ?? '' }}</td>
                                    <td>{{ $row['display_name'] ?? '' }}</td>
                                    <td>{{ $row['email'] ?? '' }}</td>
                                    <td>{{ $row['group'] ?? '' }}</td>
                                    <td>{{ $row['message'] ?? '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif
</div>

<div class="nextcloud-loading-overlay" id="nextcloud-loading-overlay" role="status" aria-live="polite" aria-hidden="true">
    <div class="nextcloud-loading-card">
        <div class="nextcloud-loading-media"><img src="{{ url('/redmine-mantencion/assets/img/Nextcloud.gif') }}" alt=""></div>
        <div class="nextcloud-loading-body">
            <h3 class="nextcloud-loading-title">Creando usuarios en Nextcloud</h3>
            <p class="nextcloud-loading-text" id="nextcloud-loading-text">Conectando con la API OCS...</p>
            <div class="nextcloud-loading-progress" aria-label="Progreso de creación"><div class="nextcloud-loading-progress-bar" id="nextcloud-loading-progress-bar"></div></div>
            <div class="nextcloud-loading-meta"><span id="nextcloud-loading-step">Preparando</span><span id="nextcloud-loading-percent">0%</span></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const search = document.getElementById('nextcloud-group-search');
    const groupList = document.getElementById('nextcloud-group-list');
    const bulkQuota = document.getElementById('nextcloud-bulk-quota');
    const applyChanges = document.getElementById('nextcloud-apply-changes');
    const checkAll = document.getElementById('nextcloud-check-all');
    const confirmBtn = document.getElementById('nextcloud-confirm-btn');
    const previewForm = document.getElementById('nextcloud-preview-form');
    const loadingOverlay = document.getElementById('nextcloud-loading-overlay');
    const loadingProgressBar = document.getElementById('nextcloud-loading-progress-bar');
    const loadingPercent = document.getElementById('nextcloud-loading-percent');
    const loadingText = document.getElementById('nextcloud-loading-text');
    const loadingStep = document.getElementById('nextcloud-loading-step');
    const getRows = () => Array.from(document.querySelectorAll('[data-nextcloud-row]'));
    const groups = @json($previewGroups);
    let selectedGroup = '';
    let quotaChanged = false;
    let progressTimer = null;
    const groupStopwords = new Set(['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'a', 'al', 'en', 'por', 'para']);

    const normalizeGroupText = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const groupTokens = value => normalizeGroupText(value).split(/[^a-z0-9]+/).filter(t => t && !groupStopwords.has(t)).filter((t, i, items) => items.indexOf(t) === i);
    const groupKey = value => normalizeGroupText(value).replace(/[^a-z0-9]+/g, '');

    const groupScore = (term, group) => {
        const needle = groupKey(term);
        const hay = groupKey(group);
        if (!needle || !hay) return 0;
        if (needle === hay) return 100;
        const needleTokens = groupTokens(term);
        const groupTokensValue = groupTokens(group);
        const needleTokenKey = needleTokens.join('');
        const groupTokenKey = groupTokensValue.join('');
        if (needleTokenKey && needleTokenKey === groupTokenKey) return 98;
        if (needleTokenKey && groupTokenKey.includes(needleTokenKey)) return 90;
        if (groupTokenKey && needleTokenKey.includes(groupTokenKey)) return 86;
        const overlap = needleTokens.filter(t => groupTokensValue.includes(t));
        if (needleTokens.length && groupTokensValue.length) {
            const needleCoverage = overlap.length / needleTokens.length;
            const groupCoverage = overlap.length / groupTokensValue.length;
            const partialMatches = needleTokens.filter(t => t.length >= 2 && groupTokensValue.some(g => g.startsWith(t) || g.includes(t)));
            const partialCoverage = new Set(partialMatches).size / needleTokens.length;
            if (needleCoverage === 1 && groupCoverage === 1) return 96;
            if (needleCoverage === 1) return 82;
            if (partialCoverage === 1) return 80;
            if (partialCoverage >= 0.5 && partialMatches.length >= 2) return 72;
            if (groupCoverage === 1 && overlap.length >= 2) return 78;
            if (overlap.length >= 2) return 68;
        }
        if (hay.includes(needle)) return 74;
        if (needle.includes(hay)) return 70;
        return 0;
    };

    const bestGroupMatch = term => {
        const matches = groups.map(group => ({ group, score: groupScore(term, group) }))
            .filter(item => item.score >= 68)
            .sort((a, b) => b.score - a.score || a.group.length - b.group.length || a.group.localeCompare(b.group, 'es'));
        return matches.length ? matches[0].group : '';
    };

    const showLoading = () => {
        if (!loadingOverlay || !loadingProgressBar) return;
        const steps = [
            {at: 10, text: 'Conectando con la API OCS...', step: 'Validando credenciales'},
            {at: 28, text: 'Preparando usuarios seleccionados...', step: 'Armando solicitudes'},
            {at: 52, text: 'Creando cuentas en Nextcloud...', step: 'Enviando usuarios'},
            {at: 76, text: 'Asignando grupos y cuotas...', step: 'Aplicando configuración'},
            {at: 92, text: 'Finalizando y registrando resultado...', step: 'Guardando historial'},
        ];
        let progress = 0, stepIndex = 0;
        const setProgress = value => {
            progress = Math.min(94, Math.max(progress, value));
            loadingProgressBar.style.width = `${progress}%`;
            if (loadingPercent) loadingPercent.textContent = `${Math.round(progress)}%`;
        };
        const setStep = item => {
            if (loadingText) loadingText.textContent = item.text;
            if (loadingStep) loadingStep.textContent = item.step;
        };
        loadingOverlay.classList.add('is-visible');
        loadingOverlay.setAttribute('aria-hidden', 'false');
        setProgress(6);
        setStep(steps[0]);
        clearInterval(progressTimer);
        progressTimer = setInterval(() => {
            const target = steps[stepIndex] || steps[steps.length - 1];
            if (progress < target.at) { setProgress(progress + Math.max(1, (target.at - progress) * .18)); return; }
            if (stepIndex < steps.length - 1) { stepIndex += 1; setStep(steps[stepIndex]); return; }
            setProgress(progress + .35);
        }, 420);
    };

    const updateConfirmState = () => {
        if (!confirmBtn) return;
        const rows = getRows();
        const missingGroup = rows.length === 0 || rows.some(row => {
            const input = row.querySelector('.nextcloud-row-group-input');
            return !input || input.value.trim() === '';
        });
        const invalidEmail = rows.some(row => row.querySelector('td .badge.text-bg-danger'));
        const duplicateRows = rows.some(row => Array.from(row.querySelectorAll('.badge')).some(b => b.textContent.trim() === 'Duplicado'));
        confirmBtn.disabled = confirmBtn.dataset.maintenance === '1' || missingGroup || invalidEmail || duplicateRows;
    };

    const hasSelectedRows = () => document.querySelectorAll('.nextcloud-row-check:checked').length > 0;
    const updateApplyState = () => { if (applyChanges) applyChanges.disabled = (!selectedGroup && !quotaChanged) || !hasSelectedRows(); };

    if (search) {
        search.addEventListener('input', () => {
            const term = search.value.trim();
            selectedGroup = bestGroupMatch(term);
            updateApplyState();
            if (!groupList) return;
            groupList.innerHTML = '';
            if (normalizeGroupText(term).length < 1) return;
            groups.map(group => ({group, score: groupScore(term, group)}))
                .filter(item => item.score > 0 || normalizeGroupText(item.group).includes(normalizeGroupText(term)))
                .sort((a, b) => b.score - a.score || a.group.length - b.group.length || a.group.localeCompare(b.group, 'es'))
                .slice(0, 30)
                .forEach(({group}) => { const option = document.createElement('option'); option.value = group; groupList.appendChild(option); });
        });
        search.addEventListener('change', () => {
            const match = bestGroupMatch(search.value.trim());
            if (match) { selectedGroup = match; search.value = match; }
            updateApplyState();
        });
    }

    checkAll?.addEventListener('change', () => {
        document.querySelectorAll('.nextcloud-row-check').forEach(check => { check.checked = checkAll.checked; });
        updateApplyState();
    });
    document.querySelectorAll('.nextcloud-row-check').forEach(check => check.addEventListener('change', updateApplyState));

    document.querySelectorAll('.nextcloud-remove-row').forEach(button => button.addEventListener('click', () => {
        button.closest('[data-nextcloud-row]')?.remove();
        if (checkAll) {
            const checks = Array.from(document.querySelectorAll('.nextcloud-row-check'));
            checkAll.checked = checks.length > 0 && checks.every(c => c.checked);
        }
        updateApplyState();
        updateConfirmState();
    }));

    if (bulkQuota) bulkQuota.addEventListener('change', () => { quotaChanged = true; updateApplyState(); });

    applyChanges?.addEventListener('click', () => {
        if (!selectedGroup && !quotaChanged) return;
        document.querySelectorAll('.nextcloud-row-check:checked').forEach(check => {
            const row = check.closest('[data-nextcloud-row]');
            const groupInput = row?.querySelector('.nextcloud-row-group-input');
            const label = row?.querySelector('[data-group-label]');
            const quota = row?.querySelector('.nextcloud-row-quota');
            if (selectedGroup && groupInput && label) {
                groupInput.value = selectedGroup;
                label.textContent = selectedGroup;
                label.classList.remove('text-bg-warning');
                label.classList.add('text-bg-success');
            }
            if (quotaChanged && bulkQuota && quota) quota.value = bulkQuota.value;
        });
        quotaChanged = false;
        updateApplyState();
        updateConfirmState();
    });

    document.querySelectorAll('[data-copy-table]').forEach(button => button.addEventListener('click', async () => {
        const table = document.querySelector(button.dataset.copyTable);
        if (!table) return;
        const rowsText = Array.from(table.querySelectorAll('tr')).map(row => Array.from(row.children).map(cell => cell.innerText.trim()).join('\t')).join('\n');
        try {
            await navigator.clipboard.writeText(rowsText);
            button.innerHTML = '<i class="bi bi-check2"></i> Copiado';
            setTimeout(() => { button.innerHTML = '<i class="bi bi-clipboard"></i> Copiar tabla'; }, 2000);
        } catch (error) {
            const area = document.createElement('textarea');
            area.value = rowsText;
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
    }));

    previewForm?.addEventListener('submit', () => {
        showLoading();
        previewForm.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; });
    });

    updateConfirmState();
    updateApplyState();
})();
</script>
@endpush
