@php
    $meta = $hoursMeta ?? [];
    $months = $meta['months'] ?? [];
    $selectedMonth = (string) ($meta['selectedMonth'] ?? '');
    $selectedYear = (string) ($meta['selectedYear'] ?? '');
    $emachSuggestions = $meta['emachSuggestions'] ?? [];
    $fmtDate = static function ($value): string {
        $value = trim((string) $value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date) return $date->format('d-m-Y');
        }
        return $value ?: '-';
    };
    $dateKey = static function ($value): string {
        $value = trim((string) $value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date) return $date->format('Y-m-d');
        }
        return '';
    };
    $fmtTime = static fn ($value): string => trim((string) $value) !== '' ? substr((string) $value, 0, 5) : '-';
    $minutesDiff = static function ($start, $end): ?int {
        $start = trim((string) $start);
        $end = trim((string) $end);
        if ($start === '' || $end === '') return null;
        $startTime = DateTimeImmutable::createFromFormat('H:i', substr($start, 0, 5)) ?: DateTimeImmutable::createFromFormat('H:i:s', $start);
        $endTime = DateTimeImmutable::createFromFormat('H:i', substr($end, 0, 5)) ?: DateTimeImmutable::createFromFormat('H:i:s', $end);
        if (!$startTime || !$endTime || $endTime <= $startTime) return null;
        return (int) round(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60);
    };
    $fmtMinutes = static fn ($mins): string => $mins === null ? '' : str_pad((string) floor($mins / 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT);
@endphp

<section class="rm-module-head">
    <span class="rm-module-head-icon is-cyan"><i class="bi bi-clock-history"></i></span>
    <div>
        <small>Control mensual</small>
        <h2>Horas extra</h2>
        <p>Revisa grupos, tiempos y ajustes asociados a reportes marcados como hora extra.</p>
    </div>
    <div class="rm-module-meter">
        <strong>{{ $meta['totalHours'] ?? '00:00' }}</strong>
    </div>
</section>

<div class="rm-section-head">
    <div>
        <h2>Horas extra</h2>
        <p>Reportes con hora extra agrupados por fecha.</p>
    </div>
    <!-- <span class="nova-badge is-success"><i class="bi bi-table"></i>{{ $meta['visibleCount'] ?? count($rows) }} grupos visibles</span> -->
</div>

<form class="card nova-card rm-panel mb-4" method="get" action="{{ $redmineRoute('redmine.native.section', 'horas-extra') }}">
    <input type="hidden" name="filters" value="1">
    <div class="row g-3 align-items-end">
        <div class="col-12 col-md-4 col-xl-3">
            <label class="form-label" for="horas-mes">Mes</label>
            <select class="form-select" id="horas-mes" name="mes">
                <option value="">Todos</option>
                @foreach ($months as $value => $label)
                    <option value="{{ $value }}" @selected($selectedMonth !== '' && (int) $selectedMonth === (int) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-4 col-xl-3">
            <label class="form-label" for="horas-anio">Año</label>
            <select class="form-select" id="horas-anio" name="anio">
                <option value="">Todos</option>
                @foreach (($meta['years'] ?? []) as $year)
                    <option value="{{ $year }}" @selected($selectedYear !== '' && (string) $selectedYear === (string) $year)>{{ $year }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-4 col-xl-6">
            <div class="rm-form-actions">
                <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-funnel"></i>Filtrar</button>
                <a class="btn btn-outline-secondary" href="{{ $redmineRoute('redmine.native.section', ['section' => 'horas-extra', 'filters' => 1, 'mes' => '', 'anio' => '']) }}"><i class="bi bi-x-circle"></i>Limpiar filtros</a>
            </div>
        </div>
    </div>
</form>

<section class="card nova-card rm-work-panel">
    <div class="card-body p-4">
        <div class="rm-section-head">
            <div>
                <h2>Listado</h2>
                <p>Total de horas: <strong>{{ $meta['totalHours'] ?? '00:00' }}</strong></p>
            </div>
            <button class="btn-nova btn-nova-secondary" id="copy-hours-table" type="button"><i class="bi bi-clipboard"></i>Copiar tabla</button>
        </div>

        <div class="table-responsive rm-table-wrap">
            <table class="table table-hover align-middle mb-0" id="extras-table" data-total-hours="{{ $meta['totalHours'] ?? '00:00' }}">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Detalle</th>
                        <th>N° Ticket</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $groupTotal = $fmtMinutes($minutesDiff($row['hora_inicio'] ?? '', $row['hora_fin'] ?? ''));
                            $groupDateKey = $dateKey($row['fecha'] ?? '');
                            $emachSuggestion = $groupDateKey !== '' ? ($emachSuggestions[$groupDateKey] ?? []) : [];
                        @endphp
                        <tr class="rm-hours-group">
                            <td colspan="3">
                                <div class="rm-hours-group-inner">
                                    <span>
                                        <strong>{{ $fmtDate($row['fecha'] ?? '') }}</strong>
                                        · Hora inicio: {{ $fmtTime($row['hora_inicio'] ?? '') }}
                                        | Hora término: {{ $fmtTime($row['hora_fin'] ?? '') }}
                                        @if ($groupTotal !== '')
                                            | Total de horas: {{ $groupTotal }}
                                        @endif
                                    </span>
                                    <button class="btn-nova btn-nova-primary" type="button"
                                        data-nova-modal-open="editar-horas"
                                        data-source-file="{{ $row['_source_file'] ?? '' }}"
                                        data-fecha="{{ $row['fecha'] ?? '' }}"
                                        data-display-fecha="{{ $fmtDate($row['fecha'] ?? '') }}"
                                        data-hora-inicio="{{ substr((string) ($row['hora_inicio'] ?? ''), 0, 5) }}"
                                        data-hora-fin="{{ substr((string) ($row['hora_fin'] ?? ''), 0, 5) }}"
                                        data-emach-ok="{{ !empty($emachSuggestion['ok']) ? '1' : '0' }}"
                                        data-emach-hora-inicio="{{ $emachSuggestion['hora_inicio'] ?? '' }}"
                                        data-emach-hora-fin="{{ $emachSuggestion['hora_fin'] ?? '' }}"
                                        data-emach-total="{{ $emachSuggestion['total'] ?? '' }}"
                                        data-emach-status="{{ $emachSuggestion['status'] ?? 'Sin datos EMACH para calcular esta fecha.' }}">
                                        <i class="bi bi-pencil-square"></i>Editar horas
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @foreach (($row['reports'] ?? []) as $report)
                            <tr>
                                <td>{{ $fmtDate($report['fecha_inicio'] ?? $report['fecha'] ?? $row['fecha'] ?? '') }}</td>
                                <td>{{ $report['asunto'] ?? $report['mensaje'] ?? '-' }}</td>
                                <td>{{ $report['redmine_id'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="3" class="nova-empty"><i class="bi bi-clock" style="font-size:1.4rem;display:block;margin-bottom:.4rem;opacity:.35"></i>No hay horas extra para el filtro seleccionado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="modal fade detail-drawer-modal" id="editar-horas" tabindex="-1" aria-labelledby="editar-horas-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable detail-drawer-dialog">
        <form class="modal-content" method="post" action="{{ $redmineRoute('redmine.native.hours.action') }}">
            @csrf
            <input type="hidden" name="_source_file">
            <input type="hidden" name="fecha">
            <div class="modal-header">
                <div>
                    <p class="detail-drawer-kicker">Horas extra</p>
                    <h2 class="modal-title" id="editar-horas-title">
                        <span class="detail-drawer-icon"><i class="bi bi-clock"></i></span>
                        Editar horas por fecha
                    </h2>
                </div>
                <button type="button" class="btn-close" data-nova-modal-close aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Fecha</label>
                        <input class="form-control" name="fecha_display" readonly>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Hora de inicio</label>
                        <input class="form-control" type="time" name="hora_inicio">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Hora de termino</label>
                        <input class="form-control" type="time" name="hora_fin">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-primary" type="button" data-emach-hours-calculate>
                            <i class="bi bi-calculator"></i>Calcular desde EMACH
                        </button>
                        <div class="form-text fw-semibold" data-emach-hours-status></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-nova-modal-close><i class="bi bi-x-lg"></i>Cancelar</button>
                <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i>Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('[data-nova-modal-open="editar-horas"]').forEach((button) => {
        button.addEventListener('click', () => {
            const modal = document.getElementById('editar-horas');
            if (!modal) return;
            const form = modal.querySelector('form');
            form.elements._source_file.value = button.dataset.sourceFile || '';
            form.elements.fecha.value = button.dataset.fecha || '';
            form.elements.fecha_display.value = button.dataset.displayFecha || '';
            form.elements.hora_inicio.value = button.dataset.horaInicio || '';
            form.elements.hora_fin.value = button.dataset.horaFin || '';
            modal.dataset.emachOk = button.dataset.emachOk || '0';
            modal.dataset.emachHoraInicio = button.dataset.emachHoraInicio || '';
            modal.dataset.emachHoraFin = button.dataset.emachHoraFin || '';
            modal.dataset.emachTotal = button.dataset.emachTotal || '';
            modal.dataset.emachStatus = button.dataset.emachStatus || '';
            const status = modal.querySelector('[data-emach-hours-status]');
            if (status) {
                status.classList.remove('text-success', 'text-danger');
                status.textContent = '';
            }
            modal.classList.add('show');
            modal.removeAttribute('aria-hidden');
            modal.setAttribute('aria-modal', 'true');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        });
    });

    (() => {
        const modal = document.getElementById('editar-horas');
        const button = modal?.querySelector('[data-emach-hours-calculate]');
        const status = modal?.querySelector('[data-emach-hours-status]');
        const form = modal?.querySelector('form');
        const endpoint = @json(route('emach.overtime-suggestion'));
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        if (!modal || !button || !status || !form) return;

        const applySuggestion = (suggestion, message) => {
            form.elements.hora_inicio.value = suggestion.hora_inicio || '';
            form.elements.hora_fin.value = suggestion.hora_fin || '';
            status.classList.remove('text-danger');
            status.classList.add('text-success');
            status.textContent = message || `Inicio desde tu salida programada y termino desde EMACH. Total: ${suggestion.total || '00:00'}.`;
        };

        button.addEventListener('click', async () => {
            status.classList.remove('text-success', 'text-danger');
            if (modal.dataset.emachOk === '1') {
                applySuggestion({
                    hora_inicio: modal.dataset.emachHoraInicio || '',
                    hora_fin: modal.dataset.emachHoraFin || '',
                    total: modal.dataset.emachTotal || '00:00',
                });
                return;
            }

            button.disabled = true;
            status.textContent = 'Consultando EMACH...';
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ fecha: form.elements.fecha.value || form.elements.fecha_display.value || '' }),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.ok) {
                    throw new Error(payload.message || modal.dataset.emachStatus || 'Sin datos EMACH para calcular esta fecha.');
                }

                modal.dataset.emachOk = '1';
                modal.dataset.emachHoraInicio = payload.hora_inicio || '';
                modal.dataset.emachHoraFin = payload.hora_fin || '';
                modal.dataset.emachTotal = payload.total || '00:00';
                modal.dataset.emachStatus = payload.message || '';
                applySuggestion(payload, `${payload.message || 'Calculado desde EMACH.'} Total: ${payload.total || '00:00'}.`);
            } catch (error) {
                status.classList.add('text-danger');
                status.textContent = error?.message || 'No se pudo consultar EMACH.';
            } finally {
                button.disabled = false;
            }
        });
    })();

    (() => {
        const copyButton = document.getElementById('copy-hours-table');
        const table = document.getElementById('extras-table');
        if (!copyButton || !table) return;

        const textFromCell = (cell) => (cell?.innerText || '').replace(/\s+/g, ' ').trim();
        const escapeHtml = (value) => String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        const copySuccess = () => {
            copyButton.innerHTML = '<i class="bi bi-check2"></i>Copiado';
            setTimeout(() => {
                copyButton.innerHTML = '<i class="bi bi-clipboard"></i>Copiar tabla';
            }, 1600);

        };
        copyButton.addEventListener('click', async () => {
            const rows = [['Fecha', 'Detalle', 'N° Ticket']];
            const htmlRows = [
                '<tr>',
                '<th style="border:1px solid #000;background:#d9d9d9;font-weight:700;text-align:left;padding:6px;">Fecha</th>',
                '<th style="border:1px solid #000;background:#d9d9d9;font-weight:700;text-align:left;padding:6px;">Detalle</th>',
                '<th style="border:1px solid #000;background:#d9d9d9;font-weight:700;text-align:left;padding:6px;">N° Ticket</th>',
                '</tr>',
            ];
            table.querySelectorAll('tbody tr').forEach((row) => {
                if (row.classList.contains('rm-hours-group')) {
                    const groupText = textFromCell(row.cells[0]);
                    rows.push([groupText, '', '']);
                    htmlRows.push(`<tr><td colspan="3" style="border:1px solid #000;background:#cfe0f7;font-weight:400;padding:6px;">${escapeHtml(groupText)}</td></tr>`);
                    return;
                }
                if (row.cells.length >= 3) {
                    const date = textFromCell(row.cells[0]);
                    const detail = textFromCell(row.cells[1]);
                    const ticket = textFromCell(row.cells[2]);
                    rows.push([date, detail, ticket]);
                    htmlRows.push(
                        '<tr>' +
                        `<td style="border:1px solid #000;background:#f3f6ff;padding:6px;">${escapeHtml(date)}</td>` +
                        `<td style="border:1px solid #000;background:#f3f6ff;padding:6px;">${escapeHtml(detail)}</td>` +
                        `<td style="border:1px solid #000;background:#f3f6ff;padding:6px;">${escapeHtml(ticket)}</td>` +
                        '</tr>'
                    );
                }
            });
            const totalHours = table.dataset.totalHours || '00:00';
            rows.push(['Total de horas extra realizadas', totalHours, '']);
            htmlRows.push(`<tr><td colspan="3" style="border:1px solid #000;background:#edf2fb;font-weight:700;padding:6px;">Total de horas extra realizadas: ${escapeHtml(totalHours)}</td></tr>`);
            const text = rows.map((row) => row.join('\t')).join('\n');
            const html = `<table style="border-collapse:collapse;font-family:Arial, sans-serif;font-size:12px;color:#000;">${htmlRows.join('')}</table>`;

            try {
                if (navigator.clipboard && window.ClipboardItem) {
                    await navigator.clipboard.write([
                        new ClipboardItem({
                            'text/html': new Blob([html], { type: 'text/html' }),
                            'text/plain': new Blob([text], { type: 'text/plain' }),
                        }),
                    ]);
                } else if (navigator.clipboard) {
                    await navigator.clipboard.writeText(text);
                } else {
                    throw new Error('Clipboard API no disponible');
                }
                copySuccess();
            } catch (error) {
                const container = document.createElement('div');
                container.contentEditable = 'true';
                container.innerHTML = html;
                container.style.position = 'fixed';
                container.style.left = '-9999px';
                document.body.appendChild(container);
                const range = document.createRange();
                range.selectNodeContents(container);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                document.execCommand('copy');
                selection.removeAllRanges();
                container.remove();
                copySuccess();
            }
        });
    })();
</script>
