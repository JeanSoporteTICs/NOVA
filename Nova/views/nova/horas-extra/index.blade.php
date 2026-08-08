<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Horas Extra | NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}?v={{ @filemtime(public_path('assets/nova-ui.css')) ?: '1' }}" rel="stylesheet">
</head>
<body class="nova-page">
    <main class="nova-shell nova-shell-fluid">
        <header class="nova-topbar">
            <div class="nova-brand">
                <div class="nova-brand-mark" aria-hidden="true"><i class="bi bi-clock-history"></i></div>
                <div class="nova-brand-title">
                    <strong>Horas Extra</strong>
                    <span>Vista global (Mantención + TIC)</span>
                </div>
            </div>
            <nav class="nova-session" aria-label="Sesion">
                @include('nova.partials.session-control')
                <span class="nova-navbar-user"><i class="bi bi-person-circle"></i> @include('nova.partials.current-user-name')</span>
                <a class="btn btn-outline-light" href="{{ route('home') }}" title="NOVA">
                    <i class="bi bi-house-door"></i><span class="nova-navbar-label">NOVA</span>
                </a>
                <form method="POST" action="{{ route('logout') }}" style="display:inline">
                    @csrf
                    <button class="btn btn-outline-light" type="submit" title="Salir">
                        <i class="bi bi-box-arrow-right"></i><span class="nova-navbar-label">Salir</span>
                    </button>
                </form>
            </nav>
        </header>

        @if (session('horas_extra_status'))
            <div data-nova-flash="success" data-nova-flash-message="{{ session('horas_extra_status') }}" hidden></div>
        @endif
        @if (session('horas_extra_error'))
            <div data-nova-flash="error" data-nova-flash-message="{{ session('horas_extra_error') }}" hidden></div>
        @endif

        @if (! $canMantencion && ! $canTic)
            <section class="nova-card nova-card-pad">
                <div class="nova-empty-state">
                    <div class="nova-empty-state-icon"><i class="bi bi-clock-history"></i></div>
                    <h3>No tienes horas extra disponibles para visualizar</h3>
                    <p>No tienes acceso a Redmine Mantención ni a Redmine TIC. Si crees que esto es un error, contacta al administrador de NOVA.</p>
                </div>
            </section>
        @else
            <div class="nova-alert-card is-info mb-3 he-guidance">
                <span class="he-guidance-icon"><i class="bi bi-lightbulb"></i></span>
                <div>
                    <strong>Tu registro consolidado</strong>
                    <span>Las horas se informan por fecha. Para aprobar, agregar reportes o cerrar solicitudes, entra al módulo de origen.</span>
                </div>
            </div>

            <div class="nova-table-card he-workspace">
                <div class="nova-table-toolbar he-toolbar">
                    <div class="he-toolbar-heading">
                        <span>Registro mensual</span>
                        <strong>Mis horas extra</strong>
                    </div>
                    @php
                        $mesesNombre = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
                        $anioActual = (int) now()->format('Y');
                        $mesActual = (int) now()->format('n');
                        $anios = collect($rows)
                            ->pluck('fecha')
                            ->filter()
                            ->map(fn ($f) => (int) substr((string) $f, 0, 4))
                            ->push($anioActual)
                            ->unique()
                            ->sort()
                            ->values();
                    @endphp
                    <div class="he-filter he-filter-search">
                        <label for="he-search">Buscar</label>
                        <div class="nova-table-search">
                            <i class="bi bi-search"></i>
                            <input id="he-search" type="search" placeholder="ID, detalle o proyecto" data-he-search>
                        </div>
                    </div>
                    <div class="he-filter">
                        <label for="he-month">Periodo</label>
                        <div class="he-period-controls">
                            <select id="he-month" class="form-select form-select-sm" data-he-month aria-label="Mes">
                                @foreach ($mesesNombre as $num => $label)
                                    <option value="{{ $num }}" @selected($num === $mesActual)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <select class="form-select form-select-sm" data-he-year aria-label="Año">
                                @foreach ($anios as $anio)
                                    <option value="{{ $anio }}" @selected($anio === $anioActual)>{{ $anio }}</option>
                                @endforeach
                            </select>
                            <button class="btn-nova btn-nova-secondary btn-nova-icon-only" type="button" data-he-filter-clear title="Volver al mes actual" aria-label="Limpiar filtros">
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </button>
                        </div>
                    </div>
                    <div class="he-toolbar-summary">
                        <span class="he-visible-count"><i class="bi bi-calendar3"></i><strong data-he-count>{{ count($dateGroups) }}</strong> fechas</span>
                        <span class="he-month-total" title="Total de horas del mes visible">
                            <span class="he-month-total-icon"><i class="bi bi-stopwatch"></i></span>
                            <span><small>Total del mes</small><strong data-he-month-total>0h</strong></span>
                        </span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="nova-user-table" data-he-table>
                        <thead>
                            <tr>
                                <th>ID Redmine</th>
                                <th>Detalle</th>
                                <th>Proyecto</th>
                                <th class="nova-col-actions text-end">
                                    <span class="me-2">Acciones</span>
                                    <button type="button" class="he-action-button he-action-copy" data-he-copy title="Copiar tabla completa" aria-label="Copiar tabla completa">
                                        <span class="he-action-icon"><i class="bi bi-clipboard"></i></span>
                                        <span>Copiar</span>
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        @forelse ($dateGroups as $dateGroup)
                            @php
                                $fecha = (string) ($dateGroup['fecha'] ?? '');
                                $fechaDisplay = $fecha !== '' ? \Illuminate\Support\Carbon::parse($fecha)->format('d-m-Y') : '—';
                                $reportes = $dateGroup['reportes'] ?? [];
                                $horaInicio = (string) ($dateGroup['hora_inicio'] ?? '');
                                $horaFin = (string) ($dateGroup['hora_fin'] ?? '');
                                $totalHoras = (string) ($dateGroup['total_horas'] ?? '—');
                            @endphp
                            <tbody data-he-date data-he-fecha="{{ $fecha }}" data-he-fecha-display="{{ $fechaDisplay }}">
                                <tr class="rm-hours-group rm-hours-date"
                                    data-he-hora-inicio="{{ $horaInicio !== '' ? $horaInicio : '—' }}"
                                    data-he-hora-fin="{{ $horaFin !== '' ? $horaFin : '—' }}"
                                    data-he-total-horas="{{ $totalHoras }}">
                                    <td colspan="4">
                                        <div class="he-date-bar">
                                            <div class="he-date-heading">
                                                <span class="he-date-icon"><i class="bi bi-calendar-check"></i></span>
                                                <span><small>Fecha registrada</small><strong>{{ $fechaDisplay }}</strong></span>
                                            </div>
                                            <div class="he-time-line">
                                                <span><small>Inicio</small><strong>{{ $horaInicio !== '' ? $horaInicio : '—' }}</strong></span>
                                                <i class="bi bi-arrow-right"></i>
                                                <span><small>Término</small><strong>{{ $horaFin !== '' ? $horaFin : '—' }}</strong></span>
                                                <span class="he-day-total"><small>Total</small><strong>{{ $totalHoras }}</strong></span>
                                            </div>
                                            <button type="button" class="he-action-button he-action-edit"
                                                title="Editar horas"
                                                aria-label="Editar horas"
                                                data-he-edit-open
                                                data-origen="{{ $dateGroup['origen'] ?? '' }}"
                                                data-fecha="{{ $fecha }}"
                                                data-fecha-display="{{ $fechaDisplay }}"
                                                data-hora-inicio="{{ $horaInicio }}"
                                                data-hora-fin="{{ $horaFin }}">
                                                <span class="he-action-icon"><i class="bi bi-pencil-square"></i></span>
                                                <span>Editar horas</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @foreach ($reportes as $reporte)
                                    <tr data-search="{{ strtolower((($reporte['id_redmine'] ?? '') . ' ' . ($reporte['detalle'] ?? '') . ' ' . ($reporte['proyecto'] ?? ''))) }}">
                                        <td><span class="he-ticket-id"><i class="bi bi-box-arrow-up-right"></i>{{ $reporte['id_redmine'] ?? '—' }}</span></td>
                                        <td><span class="he-report-detail">{{ $reporte['detalle'] ?? '—' }}</span></td>
                                        <td>
                                            <span class="he-project-badge is-{{ $reporte['modulo'] ?? 'nova' }}">
                                                <i class="bi {{ ($reporte['modulo'] ?? '') === 'tic' ? 'bi-headset' : 'bi-tools' }}"></i>
                                                {{ $reporte['proyecto'] ?? '—' }}
                                            </span>
                                        </td>
                                        <td class="text-end"></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @empty
                            <tbody>
                                <tr><td colspan="4" class="admin-empty-row">No se encontraron grupos de horas extra en los módulos a los que tienes acceso.</td></tr>
                            </tbody>
                        @endforelse
                    </table>
                </div>
            </div>

            <div class="modal fade" id="editar-horas-global" tabindex="-1" aria-labelledby="editar-horas-global-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-scrollable">
                    <form class="modal-content" method="post" action="{{ route('horas-extra.update') }}">
                        @csrf
                        <input type="hidden" name="origen" data-he-form-origen>
                        <input type="hidden" name="fecha" data-he-form-fecha>
                        <div class="modal-header">
                            <div>
                                <p class="detail-drawer-kicker">Horas extra</p>
                                <h2 class="modal-title fs-5" id="editar-horas-global-title">Editar horas por fecha</h2>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Fecha</label>
                                    <input class="form-control" data-he-form-fecha-display readonly>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Hora de inicio</label>
                                    <input class="form-control" type="time" name="hora_inicio" data-he-form-hora-inicio>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Hora de término</label>
                                    <input class="form-control" type="time" name="hora_fin" data-he-form-hora-fin>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Total horas</label>
                                    <input class="form-control" type="text" data-he-form-total readonly>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-outline-primary" type="button" data-he-emach-calculate>
                                        <i class="bi bi-calculator"></i>Calcular con EMACH
                                    </button>
                                    <div class="form-text fw-semibold" data-he-emach-status></div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i>Cancelar</button>
                            <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i>Guardar cambios</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('assets/nova-ui.js') }}?v={{ @filemtime(public_path('assets/nova-ui.js')) ?: '1' }}"></script>
    <script>
        const heDates = Array.from(document.querySelectorAll('[data-he-date]'));
        const heSearch = document.querySelector('[data-he-search]');
        const heMonth = document.querySelector('[data-he-month]');
        const heYear = document.querySelector('[data-he-year]');
        const heCount = document.querySelector('[data-he-count]');
        const heMonthTotal = document.querySelector('[data-he-month-total]');
        const CURRENT_YEAR = String(new Date().getFullYear());
        const CURRENT_MONTH = String(new Date().getMonth() + 1);

        // Ultimo dia del mes 'mes' (1-12) del año 'anio'.
        const lastDayOfMonth = (anio, mes) => new Date(Number(anio), Number(mes), 0).getDate();

        const computeMonthRange = () => {
            const month = heMonth?.value || CURRENT_MONTH;
            const year = heYear?.value || CURRENT_YEAR;
            const from = `${year}-${String(month).padStart(2, '0')}-01`;
            const to = `${year}-${String(month).padStart(2, '0')}-${String(lastDayOfMonth(year, month)).padStart(2, '0')}`;
            return { from, to };
        };

        const parseHoursLabelToMinutes = (value) => {
            const text = String(value || '').trim().toLowerCase();
            if (!text || text === '—' || text === '-') return 0;

            const clock = text.match(/^(\d{1,3}):([0-5]\d)$/);
            if (clock) return (Number(clock[1]) * 60) + Number(clock[2]);

            const hours = text.match(/(\d+(?:[.,]\d+)?)\s*h/);
            const minutes = text.match(/(\d+)\s*m/);
            return Math.round((hours ? Number(hours[1].replace(',', '.')) * 60 : 0) + (minutes ? Number(minutes[1]) : 0));
        };

        const formatMinutesLabel = (minutes) => {
            const total = Math.max(0, Number(minutes) || 0);
            const hours = Math.floor(total / 60);
            const remainder = total % 60;
            return remainder > 0 ? `${hours}h ${remainder}m` : `${hours}h`;
        };

        const applyHoursExtraFilters = () => {
            const query = String(heSearch?.value || '').toLowerCase().trim();
            const { from, to } = computeMonthRange();
            let visibleDates = 0;
            let visibleMinutes = 0;

            heDates.forEach((dateBody) => {
                const fecha = dateBody.dataset.heFecha || '';
                const matchRange = fecha !== '' && fecha >= from && fecha <= to;
                if (!matchRange) {
                    dateBody.style.display = 'none';
                    return;
                }

                const reportRows = Array.from(dateBody.querySelectorAll('tr[data-search]'));
                const matchSearch = query === '' || reportRows.some((row) => (row.dataset.search || '').includes(query));
                dateBody.style.display = matchSearch ? '' : 'none';
                if (matchSearch) {
                    visibleDates += 1;
                    const dateRow = dateBody.querySelector('.rm-hours-date');
                    visibleMinutes += parseHoursLabelToMinutes(dateRow?.dataset.heTotalHoras || '');
                }
            });

            if (heCount) heCount.textContent = String(visibleDates);
            if (heMonthTotal) heMonthTotal.textContent = formatMinutesLabel(visibleMinutes);
        };

        heSearch?.addEventListener('input', applyHoursExtraFilters);
        heMonth?.addEventListener('change', applyHoursExtraFilters);
        heYear?.addEventListener('change', applyHoursExtraFilters);
        document.querySelector('[data-he-filter-clear]')?.addEventListener('click', () => {
            if (heSearch) heSearch.value = '';
            if (heMonth) heMonth.value = CURRENT_MONTH;
            if (heYear) heYear.value = CURRENT_YEAR;
            applyHoursExtraFilters();
        });
        applyHoursExtraFilters();

        // --- Modal "Editar horas": abrir, calcular duracion en vivo, calcular con EMACH ---
        (() => {
            const modalEl = document.getElementById('editar-horas-global');
            if (!modalEl) return;
            const modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
            const form = modalEl.querySelector('form');
            const fieldOrigen = modalEl.querySelector('[data-he-form-origen]');
            const fieldFecha = modalEl.querySelector('[data-he-form-fecha]');
            const fieldFechaDisplay = modalEl.querySelector('[data-he-form-fecha-display]');
            const fieldHoraInicio = modalEl.querySelector('[data-he-form-hora-inicio]');
            const fieldHoraFin = modalEl.querySelector('[data-he-form-hora-fin]');
            const fieldTotal = modalEl.querySelector('[data-he-form-total]');
            const emachButton = modalEl.querySelector('[data-he-emach-calculate]');
            const emachStatus = modalEl.querySelector('[data-he-emach-status]');

            const computeTotal = () => {
                const start = fieldHoraInicio.value;
                const end = fieldHoraFin.value;
                if (!start || !end) {
                    fieldTotal.value = '—';
                    return;
                }
                const [sh, sm] = start.split(':').map(Number);
                const [eh, em] = end.split(':').map(Number);
                let minutes = (eh * 60 + em) - (sh * 60 + sm);
                if (minutes < 0) minutes += 24 * 60;
                const hours = Math.floor(minutes / 60);
                const rem = minutes % 60;
                fieldTotal.value = rem > 0 ? `${hours}h ${rem}m` : `${hours}h`;
            };

            document.querySelectorAll('[data-he-edit-open]').forEach((button) => {
                button.addEventListener('click', () => {
                    fieldOrigen.value = button.dataset.origen || '';
                    fieldFecha.value = button.dataset.fecha || '';
                    fieldFechaDisplay.value = button.dataset.fechaDisplay || '';
                    fieldHoraInicio.value = button.dataset.horaInicio || '';
                    fieldHoraFin.value = button.dataset.horaFin || '';
                    if (emachStatus) {
                        emachStatus.classList.remove('text-success', 'text-danger');
                        emachStatus.textContent = '';
                    }
                    computeTotal();
                    if (modal) {
                        modal.show();
                    } else {
                        modalEl.classList.add('show');
                        modalEl.style.display = 'block';
                    }
                });
            });

            fieldHoraInicio?.addEventListener('input', computeTotal);
            fieldHoraFin?.addEventListener('input', computeTotal);

            // Reutiliza el mismo endpoint que ya usa Horas Extra TIC — no se
            // duplica la logica de calculo, solo se invoca desde esta vista.
            const emachEndpoint = @json(route('emach.overtime-suggestion'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            emachButton?.addEventListener('click', async () => {
                if (!emachStatus) return;
                emachStatus.classList.remove('text-success', 'text-danger');
                emachButton.disabled = true;
                emachStatus.textContent = 'Consultando EMACH...';
                try {
                    const response = await fetch(emachEndpoint, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ fecha: fieldFecha.value || '' }),
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || 'Sin datos EMACH para calcular esta fecha.');
                    }

                    fieldHoraInicio.value = payload.hora_inicio || '';
                    fieldHoraFin.value = payload.hora_fin || '';
                    computeTotal();
                    emachStatus.classList.add('text-success');
                    emachStatus.textContent = `${payload.message || 'Calculado desde EMACH.'} Total: ${payload.total || '00:00'}.`;
                } catch (error) {
                    emachStatus.classList.add('text-danger');
                    emachStatus.textContent = error?.message || 'No se pudo consultar EMACH.';
                } finally {
                    emachButton.disabled = false;
                }
            });
        })();

        // --- Copiar tabla completa (respeta los filtros activos), Excel-friendly como en Horas Extra TIC ---
        (() => {
            const copyButton = document.querySelector('[data-he-copy]');
            const table = document.querySelector('[data-he-table]');
            if (!copyButton || !table) return;

            const escapeHtml = (value) => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

            const textFromCell = (cell) => (cell?.innerText || '').replace(/\s+/g, ' ').trim();

            copyButton.addEventListener('click', async () => {
                const headers = ['ID Redmine', 'Detalle', 'Proyecto', 'Acciones'];
                const rows = [headers];
                const htmlRows = [
                    '<tr>' + headers.map((h) => `<th style="border:1px solid #000;background:#d9d9d9;font-weight:700;text-align:left;padding:6px;">${escapeHtml(h)}</th>`).join('') + '</tr>',
                ];
                const monthTotalLabel = heMonthTotal?.textContent || '0h';

                rows.push([`Total horas del mes: ${monthTotalLabel}`, '', '', '']);
                htmlRows.push(`<tr><td colspan="4" style="border:1px solid #000;background:#dbeafe;color:#1d4ed8;font-weight:700;padding:6px;">Total horas del mes: ${escapeHtml(monthTotalLabel)}</td></tr>`);

                table.querySelectorAll('tbody[data-he-date]').forEach((dateBody) => {
                    if (dateBody.style.display === 'none') return;

                    Array.from(dateBody.children).forEach((row) => {
                        if (row.style.display === 'none') return;

                        if (row.classList.contains('rm-hours-date')) {
                            const fechaDisplay = dateBody.dataset.heFechaDisplay || dateBody.dataset.heFecha || '';
                            const line = [
                                `Fecha: ${fechaDisplay}`,
                                `Hora inicio: ${row.dataset.heHoraInicio || '—'}`,
                                `Hora término: ${row.dataset.heHoraFin || '—'}`,
                                `Total horas: ${row.dataset.heTotalHoras || '—'}`,
                            ].join('   |   ');
                            rows.push([line, '', '', '']);
                            htmlRows.push(`<tr><td colspan="4" style="border:1px solid #000;background:#cfe0f7;font-weight:700;padding:6px;">${escapeHtml(line)}</td></tr>`);
                            return;
                        }

                        if (row.cells.length < 4) return;
                        const values = [textFromCell(row.cells[0]), textFromCell(row.cells[1]), textFromCell(row.cells[2]), textFromCell(row.cells[3])];
                        rows.push(values);
                        htmlRows.push('<tr>' + values.map((v) => `<td style="border:1px solid #000;padding:6px;">${escapeHtml(v)}</td>`).join('') + '</tr>');
                    });
                });

                const text = rows.map((row) => row.join('\t')).join('\n');
                const html = `<table style="border-collapse:collapse;font-family:Arial, sans-serif;font-size:12px;color:#000;">${htmlRows.join('')}</table>`;
                const originalHtml = copyButton.innerHTML;
                const copySuccess = () => {
                    copyButton.innerHTML = '<span class="he-action-icon"><i class="bi bi-check2"></i></span><span>Copiado</span>';
                    setTimeout(() => { copyButton.innerHTML = originalHtml; }, 1600);
                };

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
</body>
</html>
