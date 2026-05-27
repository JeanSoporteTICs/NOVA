@php
    $total = (int) ($stats['total'] ?? 0);
    $byDate = $stats['by_date'] ?? [];
    $byMonth = $stats['by_month'] ?? [];
    $maxDaily = max(1, (int) ($stats['max_daily'] ?? 0));
    $linePoints = [];
    $dateIndex = 0;
    $dateCount = max(1, count($byDate) - 1);
    foreach ($byDate as $date => $count) {
        $x = 24 + (($dateIndex / $dateCount) * 552);
        $y = 176 - (((int) $count / $maxDaily) * 136);
        $linePoints[] = round($x, 1) . ',' . round($y, 1);
        $dateIndex++;
    }
    $userRows = $stats['by_assignee'] ?? [];
    $userTotal = max(1, array_sum($userRows));
    $userColors = ['#2563eb', '#14b8a6', '#f59e0b', '#fb7185', '#8b5cf6', '#06b6d4', '#84cc16', '#f97316'];
    $segments = [];
    $cursor = 0;
    $userIndex = 0;
    foreach ($userRows as $name => $count) {
        $next = $cursor + (((int) $count / $userTotal) * 100);
        $color = $userColors[$userIndex % count($userColors)];
        $segments[] = $color . ' ' . round($cursor, 2) . '% ' . round($next, 2) . '%';
        $cursor = $next;
        $userIndex++;
    }
    $donutBackground = $segments ? implode(', ', $segments) : '#e2e8f0 0 100%';
    $filters = $stats['filters'] ?? ['desde' => '', 'hasta' => ''];
    $isRedmineApi = ($stats['source'] ?? '') === 'redmine-api';
    $hasFetchedRedmineApi = !$isRedmineApi || !empty($stats['fetched']);
    $statusOptions = $stats['status_options'] ?? [
        ['value' => 'open', 'label' => 'Abiertos'],
        ['value' => 'closed', 'label' => 'Cerrados'],
        ['value' => 'all', 'label' => 'Todos'],
    ];
    $trackerOptions = $stats['tracker_options'] ?? [['value' => 'all', 'label' => 'Todos']];
    $priorityOptions = $stats['priority_options'] ?? [['value' => 'all', 'label' => 'Todos']];
    $maintenanceActive = !empty($redmineMaintenance['enabled']);
    $statusSelection = (string) ($filters['status_scope'] ?? 'all');
    $trackerSelection = (string) ($filters['tracker_scope'] ?? 'all');
    $prioritySelection = (string) ($filters['priority_scope'] ?? 'all');
    $dateInputValue = static function (string $date): string {
        if ($date === '') {
            return '';
        }
        foreach (['d-m-Y', 'Y-m-d'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $date);
            if ($parsed) {
                return $parsed->format('Y-m-d');
            }
        }
        return '';
    };
    $formatStatsDate = static function (string $date): string {
        try {
            return $date !== '' ? (new DateTimeImmutable($date))->format('d-m-Y') : '';
        } catch (Throwable) {
            return $date;
        }
    };
    $currentYear = (int) now('America/Santiago')->format('Y');
    $currentQuarter = (int) ceil(((int) now('America/Santiago')->format('n')) / 3);
    $rankRowsWithRecords = static function (array $rows): array {
        return array_filter($rows, static function ($count, $name): bool {
            return trim((string) $name) !== '' && (int) $count > 0;
        }, ARRAY_FILTER_USE_BOTH);
    };
    $statusRows = $rankRowsWithRecords($stats['by_status'] ?? []);
    $priorityRows = $rankRowsWithRecords($stats['by_priority'] ?? []);
    $trackerRows = $rankRowsWithRecords($stats['by_tracker'] ?? []);
    $categoryRows = $rankRowsWithRecords($stats['by_category'] ?? []);
    $categoryOptionRows = $rankRowsWithRecords($stats['category_options'] ?? $categoryRows);
    $unitRows = $rankRowsWithRecords($stats['by_unit'] ?? []);
    $topCategories = array_slice($categoryRows, 0, 10, true);
    $selectedCategories = array_values(array_filter(array_map('strval', (array) ($filters['category_scope'] ?? [])), static fn (string $value): bool => trim($value) !== ''));
    $categoryFilterActive = filter_var($filters['category_filter'] ?? false, FILTER_VALIDATE_BOOL);
    $hasCategorySelection = $categoryFilterActive;
    $selectedCategoryLookup = array_fill_keys(array_map(static fn (string $name): string => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($name)), $selectedCategories), true);
    $categoryChipRows = $hasCategorySelection
        ? array_filter($categoryOptionRows, static fn ($count, $name): bool => isset($selectedCategoryLookup[\Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) $name))]), ARRAY_FILTER_USE_BOTH)
        : $categoryOptionRows;
    $categorySelectedTotal = $total;
    $countSince = static function (array $rows, DateTimeImmutable $limit): int {
        $total = 0;
        foreach ($rows as $date => $count) {
            try {
                $parsed = new DateTimeImmutable((string) $date);
            } catch (Throwable) {
                continue;
            }
            if ($parsed >= $limit) {
                $total += (int) $count;
            }
        }

        return $total;
    };
    $todayStats = new DateTimeImmutable(now('America/Santiago')->format('Y-m-d'));
    $quickTwoMonths = $countSince($byDate, $todayStats->modify('-2 months'));
    $quickSixMonths = $countSince($byDate, $todayStats->modify('-6 months'));
    $quickLastYear = $countSince($byDate, $todayStats->modify('-1 year'));
    $rankSections = [
        'by_category' => ['label' => 'Categorias', 'icon' => 'bi-tags', 'color' => '#5b7cfa'],
        'by_unit' => ['label' => 'Unidades solicitantes', 'icon' => 'bi-building', 'color' => '#06b6d4'],
        'by_assignee' => ['label' => 'Asignados', 'icon' => 'bi-person-check', 'color' => '#2563eb'],
    ];
    $chartSort = in_array((string) request('chart_sort', 'alpha'), ['alpha', 'total_desc', 'total_asc'], true)
        ? (string) request('chart_sort', 'alpha')
        : 'alpha';
    $showChartTotals = request()->boolean('show_chart_totals', true);
    $chartRows = static function (array $rows, string $sort): array {
        if ($sort === 'total_desc') {
            arsort($rows);
        } elseif ($sort === 'total_asc') {
            asort($rows);
        } else {
            ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return array_slice($rows, 0, 10, true);
    };
    $chartPoints = static function (array $rows, float $startX = 42, float $plotWidth = 620, float $baseY = 204, float $height = 154): array {
        $count = count($rows);
        $max = max(1, $rows ? max($rows) : 0);
        $points = [];
        $step = $count > 1 ? $plotWidth / ($count - 1) : 0;
        $index = 0;
        foreach ($rows as $value) {
            $x = $startX + ($step * $index);
            $y = $baseY - (((int) $value / $max) * $height);
            $points[] = ['x' => round($x, 1), 'y' => round($y, 1), 'value' => (int) $value];
            $index++;
        }

        return $points;
    };
    $smoothChartPaths = static function (array $points, float $baseY, float $topY = 0): array {
        if ($points === []) {
            return ['line' => '', 'area' => ''];
        }
        if (count($points) === 1) {
            $point = $points[0];
            $line = 'M ' . $point['x'] . ' ' . $point['y'];
            $area = 'M ' . $point['x'] . ' ' . $baseY . ' L ' . $point['x'] . ' ' . $point['y'] . ' L ' . $point['x'] . ' ' . $baseY . ' Z';

            return ['line' => $line, 'area' => $area];
        }

        $line = 'M ' . $points[0]['x'] . ' ' . $points[0]['y'];
        $last = count($points) - 1;
        for ($i = 0; $i < $last; $i++) {
            $p0 = $points[max(0, $i - 1)];
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $p3 = $points[min($last, $i + 2)];
            $cp1x = round($p1['x'] + (($p2['x'] - $p0['x']) / 6), 1);
            $cp1y = round(min($baseY, max($topY, $p1['y'] + (($p2['y'] - $p0['y']) / 6))), 1);
            $cp2x = round($p2['x'] - (($p3['x'] - $p1['x']) / 6), 1);
            $cp2y = round(min($baseY, max($topY, $p2['y'] - (($p3['y'] - $p1['y']) / 6))), 1);
            $line .= ' C ' . $cp1x . ' ' . $cp1y . ', ' . $cp2x . ' ' . $cp2y . ', ' . $p2['x'] . ' ' . $p2['y'];
        }

        $first = $points[0];
        $end = $points[$last];
        $area = 'M ' . $first['x'] . ' ' . $baseY . ' ' . preg_replace('/^M /', 'L ', $line) . ' L ' . $end['x'] . ' ' . $baseY . ' Z';

        return ['line' => $line, 'area' => $area];
    };
@endphp

<div data-stats-content>
    @if ($isRedmineApi && !empty($stats['error']))
        <div class="alert alert-danger fw-semibold">{{ $stats['error'] }}</div>
    @endif
    <section class="rm-stats-layout">
        <article class="nova-card rm-stats-hero">
            <div>
                <span class="rm-stats-eyebrow">{{ $isRedmineApi ? 'Redmine API' : 'Resumen' }}</span>
                <h2>{{ number_format($total, 0, ',', '.') }} reportes</h2>
                <p>{{ $isRedmineApi ? 'Consulta directa a Redmine' : 'Actualizado ' . ($stats['updated_at'] ?? '-') }}</p>
            </div>
            <div class="rm-stats-kpis">
                <div><strong>{{ count($byDate) }}</strong><span>Dias con datos</span></div>
                <div><strong>{{ count($byMonth) }}</strong><span>Meses</span></div>
                <div><strong>{{ $maxDaily }}</strong><span>Max. diario</span></div>
            </div>
        </article>

        @if ($isRedmineApi)
            <form class="nova-card rm-stats-panel" method="get" action="{{ $redmineRoute('redmine.native.section', $section ?? 'estadisticas-api') }}" data-redmine-api-import-form>
                <input type="hidden" name="fetch" value="1">
                <div class="rm-stats-panel-head mb-3">
                    <div>
                        <h3><i class="bi bi-calendar-range"></i> Rango de importacion</h3>
                        <p>Los datos se importan directamente desde Redmine para el rango definido.</p>
                    </div>
                </div>
                <div class="rm-api-import-row">
                    <input type="date" name="desde" class="form-control form-control-sm" value="{{ $dateInputValue((string) ($filters['desde'] ?? '')) }}" aria-label="Fecha inicio">
                    <input type="date" name="hasta" class="form-control form-control-sm" value="{{ $dateInputValue((string) ($filters['hasta'] ?? '')) }}" aria-label="Fecha fin">
                    <select name="status_scope" class="form-select form-select-sm" aria-label="Estado">
                        @foreach ($statusOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($statusSelection === (string) $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <select name="tracker_scope" class="form-select form-select-sm" aria-label="Tipo">
                        @foreach ($trackerOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($trackerSelection === (string) $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <select name="priority_scope" class="form-select form-select-sm" aria-label="Prioridad">
                        @foreach ($priorityOptions as $option)
                            <option value="{{ $option['value'] }}" @selected($prioritySelection === (string) $option['value'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary" data-redmine-api-import-button @disabled($maintenanceActive) title="{{ $maintenanceActive ? 'Modulo en mantencion: sincronizacion bloqueada' : 'Importar datos desde Redmine' }}"><i class="bi bi-cloud-arrow-down"></i><span>Importar</span></button>
                </div>
                @if ($maintenanceActive)
                    <div class="alert alert-warning small fw-semibold mb-0 mt-3">Modo mantencion activo: la sincronizacion desde Redmine API esta bloqueada. La vista usa los datos guardados.</div>
                @endif
                <div class="rm-api-loading-overlay" role="status" aria-live="polite" aria-label="Importando datos desde Redmine">
                    <div class="rm-api-loading-card">
                        <img src="{{ asset('assets/img/redmine.gif') }}" alt="Redmine">
                        <div>
                            <strong>Importando datos desde Redmine</strong>
                            <span>La consulta puede tardar segun el rango seleccionado.</span>
                        </div>
                        <div class="rm-api-loading-bar"><i></i></div>
                    </div>
                </div>
            </form>
        @endif

        @if ($isRedmineApi && !$hasFetchedRedmineApi)
            <article class="nova-card rm-stats-panel">
                <div class="rm-empty-state">Define un rango y presiona Obtener datos para consultar Redmine.</div>
            </article>
        @else
        @unless ($isRedmineApi)
        <section class="rm-stats-charts">
            <article class="nova-card rm-stats-panel rm-line-panel rm-stats-rank-card" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#stats-date-modal" aria-label="Ver detalle de reportes por fecha">
                <div class="rm-stats-panel-head">
                    <div><h3>Reportes por fecha</h3><p>Evolucion diaria</p></div>
                    <span>{{ count($byDate) }} puntos</span>
                </div>
                @if ($linePoints)
                    <svg class="rm-line-chart" viewBox="0 0 600 210" role="img" aria-label="Grafico de reportes por fecha">
                        @for ($i = 0; $i <= 4; $i++)
                            <line x1="24" y1="{{ 40 + ($i * 34) }}" x2="576" y2="{{ 40 + ($i * 34) }}" />
                        @endfor
                        <polyline points="{{ implode(' ', $linePoints) }}" />
                        @foreach ($linePoints as $point)
                            @php [$cx, $cy] = explode(',', $point); @endphp
                            <circle cx="{{ $cx }}" cy="{{ $cy }}" r="3" />
                        @endforeach
                    </svg>
                    <div class="rm-chart-axis">
                        <span>{{ array_key_first($byDate) }}</span>
                        <span>{{ array_key_last($byDate) }}</span>
                    </div>
                @else
                    <div class="rm-empty-state">Sin datos por fecha.</div>
                @endif
            </article>

            <article class="nova-card rm-stats-panel">
                <div class="rm-stats-panel-head">
                    <div><h3>Reportes por usuario</h3><p>Distribucion por asignado</p></div>
                </div>
                <div class="rm-donut-wrap">
                    <div class="rm-donut" style="--donut-bg: {{ $donutBackground }};">
                        <strong>{{ number_format(array_sum($userRows), 0, ',', '.') }}</strong>
                        <span>reportes</span>
                    </div>
                    <div class="rm-donut-list">
                        @foreach ($userRows as $name => $count)
                            <div>
                                <span><i style="background: {{ $userColors[$loop->index % count($userColors)] }}"></i>{{ $name }}</span>
                                <strong>{{ $count }}</strong>
                            </div>
                        @endforeach
                    </div>
                </div>
            </article>
        </section>
        @endunless
        @endif

        @if ($isRedmineApi && $hasFetchedRedmineApi)
        <section class="rm-api-summary-grid">
            <article class="nova-card rm-api-hero-card rm-api-click-card" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#stats-list-by-category-modal" aria-label="Ver detalle por categoria">
                <span>Tickets en rango</span>
                <strong>{{ number_format($total, 0, ',', '.') }}</strong>
                <p>Detalle por categoria (click para ver).</p>
            </article>
            <article class="nova-card rm-api-hero-card rm-api-click-card is-cyan" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#stats-list-by-unit-modal" aria-label="Ver detalle por unidad">
                <span>Unidades en rango</span>
                <strong>{{ number_format(count($unitRows), 0, ',', '.') }}</strong>
                <p>Detalle por unidad (click para ver).</p>
            </article>

            <article class="nova-card rm-api-card rm-api-card-wide">
                <div class="rm-api-card-head">
                    <h3>Totales rapidos</h3>
                    <span>Consultan datos importados</span>
                </div>
                <div class="rm-api-quick-grid">
                    <div><span>Ultimos 2 meses</span><strong>{{ number_format($quickTwoMonths, 0, ',', '.') }}</strong></div>
                    <div><span>Ultimos 6 meses</span><strong>{{ number_format($quickSixMonths, 0, ',', '.') }}</strong></div>
                    <div><span>Ultimo ano</span><strong>{{ number_format($quickLastYear, 0, ',', '.') }}</strong></div>
                </div>
            </article>

            <article class="nova-card rm-api-card rm-api-card-wide rm-api-click-card" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#stats-category-filter-modal" aria-label="Seleccionar categorias para filtrar estadisticas">
                <div class="rm-api-card-head">
                    <h3>Suma por categorias (seleccion)</h3>
                    <span>Usa las categorias del rango actual</span>
                </div>
                <div class="rm-api-selected-total">Total seleccionado: <strong>{{ number_format($categorySelectedTotal, 0, ',', '.') }}</strong></div>
                <p>Haz clic en la tarjeta para elegir categorias.</p>
                <div class="rm-api-chip-row">
                    @foreach (array_slice($categoryChipRows, 0, 6, true) as $name => $count)
                        <span>{{ $name }}</span>
                    @endforeach
                    @if (count($categoryChipRows) > 6)
                        <span>+{{ count($categoryChipRows) - 6 }} mas</span>
                    @endif
                </div>
            </article>

            <article class="nova-card rm-api-card">
                <div class="rm-api-card-head"><h3>Desglose por estado</h3></div>
                <div class="rm-api-mini-list">
                    @forelse ($statusRows as $name => $count)
                        <div><span>{{ $name }}</span><strong>{{ number_format((int) $count, 0, ',', '.') }}</strong></div>
                    @empty
                        <p>Sin datos.</p>
                    @endforelse
                </div>
            </article>

            <article class="nova-card rm-api-card">
                <div class="rm-api-card-head"><h3>Prioridades principales</h3></div>
                <div class="rm-api-mini-list">
                    @forelse ($priorityRows as $name => $count)
                        <div><span>{{ $name }}</span><strong>{{ number_format((int) $count, 0, ',', '.') }}</strong></div>
                    @empty
                        <p>Sin datos.</p>
                    @endforelse
                </div>
            </article>

            <article class="nova-card rm-api-card">
                <div class="rm-api-card-head"><h3>Trackers dominantes</h3></div>
                <div class="rm-api-mini-list">
                    @forelse ($trackerRows as $name => $count)
                        <div><span>{{ $name }}</span><strong>{{ number_format((int) $count, 0, ',', '.') }}</strong></div>
                    @empty
                        <p>Sin datos.</p>
                    @endforelse
                </div>
            </article>

            <article class="nova-card rm-api-card rm-api-top-card rm-api-click-card" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#stats-top-categories-modal" aria-label="Ver top 10 categorias completo">
                <div class="rm-api-card-head">
                    <h3>Top 10 categorias</h3>
                    <span>Mayor numero de tickets en el rango <b>{{ number_format($categorySelectedTotal, 0, ',', '.') }}</b></span>
                </div>
                <table class="rm-api-top-table">
                    <thead><tr><th>#</th><th>Categoria</th><th>Total</th></tr></thead>
                    <tbody>
                        @forelse (array_slice($topCategories, 0, 3, true) as $name => $count)
                            <tr><td>{{ $loop->iteration }}</td><td>{{ $name }}</td><td>{{ number_format((int) $count, 0, ',', '.') }}</td></tr>
                        @empty
                            <tr><td colspan="3">Sin datos.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <p>Click en cualquier parte del card para ver el top 10 completo.</p>
            </article>
        </section>
        @endif

        @unless ($isRedmineApi)
        <form class="nova-card rm-stats-panel rm-timeline-box" method="get" action="{{ $redmineRoute('redmine.native.section', $section ?? 'estadisticas') }}" data-stats-filter-form>
            <div class="rm-timeline-header">
                <span>Fecha</span>
                <span>Meses</span>
            </div>
            <div class="rm-timeline-actions">
                <div class="text-muted fw-bold">Trimestre {{ $currentQuarter }} {{ $currentYear }}</div>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('month')">Mes actual</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('year')">Ano actual</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('30d')">Ultimos 30 dias</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('today')">Hoy</button>
                </div>
            </div>
            <div class="rm-timeline-months" id="month-range">
                @foreach ([1 => 'ENE', 2 => 'FEB', 3 => 'MAR', 4 => 'ABR', 5 => 'MAY', 6 => 'JUN', 7 => 'JUL', 8 => 'AGO', 9 => 'SEPT', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC'] as $month => $label)
                    <button type="button" data-month="{{ $month }}" onclick="selectMonthRange({{ $month }})">{{ $label }}</button>
                @endforeach
            </div>
            <div class="rm-timeline-dates">
                <input type="text" name="desde" class="form-control form-control-sm" value="{{ $filters['desde'] ?? '' }}" placeholder="dd-mm-aaaa" inputmode="numeric" aria-label="Fecha inicio">
                <input type="text" name="hasta" class="form-control form-control-sm" value="{{ $filters['hasta'] ?? '' }}" placeholder="dd-mm-aaaa" inputmode="numeric" aria-label="Fecha fin">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i>Aplicar</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setPeriodo('clear')">Limpiar</button>
            </div>
            <div class="rm-timeline-footer">
                <span>Inicio</span>
                <span>Hoy</span>
                <span>Fin</span>
            </div>
        </form>
        @endunless

        @if ($hasFetchedRedmineApi)
        <section class="nova-card rm-interactive-charts-head">
            <div>
                <h3>Graficos interactivos</h3>
                <p>Top 10 categorias, unidades y asignados. Haz clic en cada grafico para ver todos los valores.</p>
            </div>
            <form method="get" action="{{ $redmineRoute('redmine.native.section', $section ?? 'estadisticas') }}" class="rm-chart-controls">
                @foreach ($filters as $filterKey => $filterValue)
                    @if ($filterValue !== '')
                        @if (is_array($filterValue))
                            @foreach ($filterValue as $filterItem)
                                <input type="hidden" name="{{ $filterKey }}[]" value="{{ $filterItem }}">
                            @endforeach
                        @else
                            <input type="hidden" name="{{ $filterKey }}" value="{{ $filterValue }}">
                        @endif
                    @endif
                @endforeach
                <label for="chart-sort">Ordenar:</label>
                <select id="chart-sort" name="chart_sort" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="alpha" @selected($chartSort === 'alpha')>Alfabetico</option>
                    <option value="total_desc" @selected($chartSort === 'total_desc')>Mayor total</option>
                    <option value="total_asc" @selected($chartSort === 'total_asc')>Menor total</option>
                </select>
                <label class="form-check rm-chart-total-toggle">
                    <input type="hidden" name="show_chart_totals" value="0">
                    <input class="form-check-input" type="checkbox" name="show_chart_totals" value="1" @checked($showChartTotals) onchange="this.form.submit()">
                    <span class="form-check-label">Mostrar totales</span>
                </label>
            </form>
        </section>
        <section class="rm-stats-ranks">
        @foreach ($rankSections as $key => $meta)
            @php
                $rows = $rankRowsWithRecords($stats[$key] ?? []);
                $previewRows = $chartRows($rows, $chartSort);
                $max = max(1, $rows ? max($rows) : 0);
                $modalId = 'stats-modal-' . $key;
                $points = $chartPoints($previewRows, 54, 612, 204, 154);
                $paths = $smoothChartPaths($points, 204, 50);
            @endphp
            <article class="rm-interactive-chart rm-stats-rank-card" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}" aria-label="Ver detalle de {{ $meta['label'] }}" style="--chart-color: {{ $meta['color'] }};">
                <div class="rm-interactive-chart-title">
                    <div>
                        <h3>{{ $meta['label'] }}</h3>
                        <p>Top 10 en el rango</p>
                    </div>
                    <span>Click para ver todas</span>
                </div>
                @if ($previewRows)
                    <svg class="rm-category-chart" viewBox="0 0 704 238" role="img" aria-label="Grafico de {{ $meta['label'] }}">
                        @for ($i = 0; $i <= 4; $i++)
                            @php
                                $gridY = 50 + ($i * 38.5);
                                $axisValue = max(0, round($max - (($max / 4) * $i)));
                            @endphp
                            <line class="rm-category-grid-y" x1="54" y1="{{ $gridY }}" x2="666" y2="{{ $gridY }}" />
                            <text class="rm-category-y-label" x="42" y="{{ $gridY + 4 }}">{{ $axisValue }}</text>
                        @endfor
                        @foreach ($points as $point)
                            <line class="rm-category-grid-x" x1="{{ $point['x'] }}" y1="50" x2="{{ $point['x'] }}" y2="204" />
                        @endforeach
                        @if ($paths['area'] !== '')
                            <path class="rm-category-area" d="{{ $paths['area'] }}" />
                            <path class="rm-category-line" d="{{ $paths['line'] }}" />
                        @endif
                        @foreach ($previewRows as $name => $count)
                            @php $point = $points[$loop->index] ?? null; @endphp
                            @if ($point)
                                <g class="rm-category-point">
                                    <title>{{ $name }}: {{ number_format((int) $count, 0, ',', '.') }} ticket(s)</title>
                                    <circle class="rm-category-point-hit" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="5" />
                                    <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="1.35" />
                                </g>
                                @if ($showChartTotals)
                                    <text x="{{ $point['x'] }}" y="{{ max(16, $point['y'] - 10) }}">{{ $point['value'] }}</text>
                                @endif
                            @endif
                        @endforeach
                    </svg>
                    <div class="rm-category-axis">
                        @foreach ($previewRows as $name => $count)
                            <span title="{{ $name }}">{{ $name }}</span>
                        @endforeach
                    </div>
                @else
                    <div class="rm-empty-state">Sin datos.</div>
                @endif
            </article>
        @endforeach
        </section>
        @endif
    </section>

    @if ($hasFetchedRedmineApi)
    @if ($isRedmineApi)
    <div class="modal fade" id="stats-category-filter-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form class="modal-content" method="get" action="{{ $redmineRoute('redmine.native.section', $section ?? 'estadisticas-api') }}">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5">Seleccionar categorias</h2>
                        <div class="text-muted fw-semibold">{{ count($categoryOptionRows) }} categoria(s) disponibles</div>
                    </div>
                    <button type="button" class="btn-close" data-nova-modal-close data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    @foreach ($filters as $filterKey => $filterValue)
                        @continue(in_array($filterKey, ['category_scope', 'category_filter'], true) || $filterValue === '')
                        @if (is_array($filterValue))
                            @foreach ($filterValue as $filterItem)
                                <input type="hidden" name="{{ $filterKey }}[]" value="{{ $filterItem }}">
                            @endforeach
                        @else
                            <input type="hidden" name="{{ $filterKey }}" value="{{ $filterValue }}">
                        @endif
                    @endforeach
                    <input type="hidden" name="chart_sort" value="{{ $chartSort }}">
                    <input type="hidden" name="show_chart_totals" value="{{ $showChartTotals ? 1 : 0 }}">
                    <input type="hidden" name="category_filter" value="1">
                    <div class="rm-category-select-controls">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" data-category-filter-all @checked(!$categoryFilterActive || count($selectedCategories) >= count($categoryOptionRows))>
                            <span class="form-check-label">Marcar/Desmarcar todas</span>
                        </label>
                        <input class="form-control form-control-sm" type="search" placeholder="Buscar categoria..." data-category-filter-search>
                    </div>
                    <div class="rm-category-check-list" data-category-filter-list>
                        @forelse ($categoryOptionRows as $name => $count)
                            @php
                                $categoryKey = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) $name));
                                $isChecked = !$hasCategorySelection || isset($selectedCategoryLookup[$categoryKey]);
                            @endphp
                            <label class="rm-category-check-row" data-category-name="{{ \Illuminate\Support\Str::lower($name) }}">
                                <input class="form-check-input" type="checkbox" name="category_scope[]" value="{{ $name }}" @checked($isChecked) data-category-filter-item>
                                <span>{{ $name }} ({{ number_format((int) $count, 0, ',', '.') }})</span>
                            </label>
                        @empty
                            <div class="rm-empty-state">No hay categorias para seleccionar.</div>
                        @endforelse
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-nova-modal-close data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-sm btn-primary">Guardar seleccion</button>
                </div>
            </form>
        </div>
    </div>

    @foreach ([
        'stats-list-by-category-modal' => ['title' => 'Categorias en rango', 'label' => 'Categoria', 'rows' => $categoryRows],
        'stats-list-by-unit-modal' => ['title' => 'Unidades en rango', 'label' => 'Unidad', 'rows' => $unitRows],
    ] as $listModalId => $listModal)
    <div class="modal fade" id="{{ $listModalId }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5">{{ $listModal['title'] }}</h2>
                        <div class="text-muted fw-semibold">{{ count($listModal['rows']) }} valor(es) con tickets</div>
                    </div>
                    <button type="button" class="btn-close" data-nova-modal-close data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="rm-list-modal-controls">
                        <label>
                            <span>Ordenar:</span>
                            <select class="form-select form-select-sm" data-rm-list-sort>
                                <option value="original">Original</option>
                                <option value="alpha">Alfabetico</option>
                                <option value="desc">Cantidad (mayor a menor)</option>
                                <option value="asc">Cantidad (menor a mayor)</option>
                            </select>
                        </label>
                        <label>
                            <span>Filtrar:</span>
                            <input class="form-control form-control-sm" type="search" placeholder="Buscar" data-rm-list-search>
                        </label>
                    </div>
                    <table class="rm-api-top-table" data-rm-list-table>
                        <thead><tr><th>#</th><th>{{ $listModal['label'] }}</th><th>Total</th></tr></thead>
                        <tbody>
                            @forelse ($listModal['rows'] as $name => $count)
                                <tr data-name="{{ \Illuminate\Support\Str::lower($name) }}" data-total="{{ (int) $count }}" data-original="{{ $loop->index }}">
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $name }}</td>
                                    <td>{{ number_format((int) $count, 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3">Sin datos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-nova-modal-close data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    @endforeach

    <div class="modal fade" id="stats-top-categories-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5">Top 10 categorias</h2>
                        <div class="text-muted fw-semibold">Mayor numero de tickets en el rango</div>
                    </div>
                    <button type="button" class="btn-close" data-nova-modal-close data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <table class="rm-api-top-table">
                        <thead><tr><th>#</th><th>Categoria</th><th>Total</th></tr></thead>
                        <tbody>
                            @forelse ($topCategories as $name => $count)
                                <tr><td>{{ $loop->iteration }}</td><td>{{ $name }}</td><td>{{ number_format((int) $count, 0, ',', '.') }}</td></tr>
                            @empty
                                <tr><td colspan="3">Sin datos.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-nova-modal-close data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    @endif

    @foreach ($rankSections as $key => $meta)
    @php
        $rows = $rankRowsWithRecords($stats[$key] ?? []);
        if ($chartSort === 'total_desc') {
            arsort($rows);
        } elseif ($chartSort === 'total_asc') {
            asort($rows);
        } else {
            ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
        }
        $max = max(1, $rows ? max($rows) : 0);
        $modalId = 'stats-modal-' . $key;
        $modalPoints = $chartPoints($rows, 64, 1112, 352, 312);
        $modalPaths = $smoothChartPaths($modalPoints, 352, 40);
        $modalLabelStep = max(1, (int) ceil(max(1, count($rows)) / 70));
    @endphp
    <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5">Grafico completo de {{ \Illuminate\Support\Str::lower($meta['label']) }}</h2>
                    </div>
                    <button type="button" class="btn-close" data-nova-modal-close data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body rm-stats-full-modal">
                    @if ($rows)
                        <section class="rm-modal-chart-panel" style="--chart-color: {{ $meta['color'] }};">
                            <svg class="rm-category-chart rm-category-chart-modal" viewBox="0 0 1200 450" role="img" aria-label="Grafico completo de {{ $meta['label'] }}" preserveAspectRatio="none">
                                @for ($i = 0; $i <= 4; $i++)
                                    @php
                                        $gridY = 40 + ($i * 78);
                                        $axisValue = max(0, round($max - (($max / 4) * $i)));
                                    @endphp
                                    <line class="rm-category-grid-y" x1="64" y1="{{ $gridY }}" x2="1176" y2="{{ $gridY }}" />
                                    <text class="rm-category-y-label" x="48" y="{{ $gridY + 4 }}">{{ $axisValue }}</text>
                                @endfor
                                @foreach ($modalPoints as $point)
                                    <line class="rm-category-grid-x" x1="{{ $point['x'] }}" y1="40" x2="{{ $point['x'] }}" y2="352" />
                                @endforeach
                                @if ($modalPaths['area'] !== '')
                                    <path class="rm-category-area" d="{{ $modalPaths['area'] }}" />
                                    <path class="rm-category-line" d="{{ $modalPaths['line'] }}" />
                                @endif
                                @foreach ($rows as $name => $count)
                                    @php $point = $modalPoints[$loop->index] ?? null; @endphp
                                    @if ($point)
                                        <g class="rm-category-point">
                                            <title>{{ $name }}: {{ number_format((int) $count, 0, ',', '.') }} ticket(s)</title>
                                            <circle class="rm-category-point-hit" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="5" />
                                            <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="1.35" />
                                        </g>
                                    @endif
                                @endforeach
                                @foreach ($rows as $name => $count)
                                    @php $point = $modalPoints[$loop->index] ?? null; @endphp
                                    @if ($point && $loop->index % $modalLabelStep === 0)
                                        <text class="rm-category-x-label" x="{{ $point['x'] }}" y="396" transform="rotate(-24 {{ $point['x'] }} 396)">
                                            <title>{{ $name }}</title>{{ \Illuminate\Support\Str::limit($name, 22) }}
                                        </text>
                                    @endif
                                @endforeach
                            </svg>
                        </section>
                    @endif
                </div>
                <div class="modal-footer rm-chart-modal-footer">
                    <span>Incluye todos los valores con tickets dentro del rango seleccionado.</span>
                    <button type="button" class="btn btn-sm btn-secondary" data-nova-modal-close data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    @endforeach

    @unless ($isRedmineApi)
    <div class="modal fade" id="stats-date-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5"><i class="bi bi-graph-up"></i> Reportes por fecha</h2>
                    <div class="text-muted fw-semibold">{{ count($byDate) }} fecha(s) con datos</div>
                </div>
                <button type="button" class="btn-close" data-nova-modal-close data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                @if ($linePoints)
                    <svg class="rm-line-chart rm-line-chart-large" viewBox="0 0 600 210" role="img" aria-label="Grafico ampliado de reportes por fecha">
                        @for ($i = 0; $i <= 4; $i++)
                            <line x1="24" y1="{{ 40 + ($i * 34) }}" x2="576" y2="{{ 40 + ($i * 34) }}" />
                        @endfor
                        <polyline points="{{ implode(' ', $linePoints) }}" />
                        @foreach ($linePoints as $point)
                            @php [$cx, $cy] = explode(',', $point); @endphp
                            <circle cx="{{ $cx }}" cy="{{ $cy }}" r="3" />
                        @endforeach
                    </svg>
                    <div class="rm-chart-axis mb-3">
                        <span>{{ array_key_first($byDate) }}</span>
                        <span>{{ array_key_last($byDate) }}</span>
                    </div>
                    <div class="rm-date-detail-list">
                        @foreach ($byDate as $date => $count)
                            <div class="rm-date-detail-row">
                                <span>{{ $formatStatsDate($date) }}</span>
                                <div><i style="width: {{ max(3, round(((int) $count / $maxDaily) * 100)) }}%"></i></div>
                                <strong>{{ $count }}</strong>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rm-empty-state">Sin datos por fecha.</div>
                @endif
            </div>
        </div>
    </div>
    </div>
    @endunless
    @endif
</div>

<script>
    let pendingMonthStart = null;
    const getStatsForm = () => document.querySelector('[data-stats-filter-form]');
    const getStatsContent = () => document.querySelector('[data-stats-content]');
    const cleanRedmineApiFetchParam = () => {
        const url = new URL(window.location.href);
        if (url.searchParams.get('fetch') !== '1' || !url.pathname.includes('/estadisticas-api')) {
            return;
        }

        url.searchParams.delete('fetch');
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    };
    cleanRedmineApiFetchParam();
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-redmine-api-import-form]')) {
            return;
        }

        document.body.classList.add('rm-api-is-importing');
        form.classList.add('is-importing');
        form.setAttribute('aria-busy', 'true');
        const button = form.querySelector('[data-redmine-api-import-button]');
        if (button) {
            button.querySelector('span').textContent = 'Importando';
        }
    });
    const refreshListModal = (modal) => {
        const table = modal.querySelector('[data-rm-list-table]');
        if (!table) return;

        const tbody = table.tBodies[0];
        const sort = modal.querySelector('[data-rm-list-sort]')?.value || 'original';
        const query = (modal.querySelector('[data-rm-list-search]')?.value || '').trim().toLowerCase();
        const rows = Array.from(tbody.querySelectorAll('tr[data-name]'));
        rows.sort((a, b) => {
            if (sort === 'alpha') return a.dataset.name.localeCompare(b.dataset.name, 'es');
            if (sort === 'desc') return Number(b.dataset.total || 0) - Number(a.dataset.total || 0);
            if (sort === 'asc') return Number(a.dataset.total || 0) - Number(b.dataset.total || 0);
            return Number(a.dataset.original || 0) - Number(b.dataset.original || 0);
        });
        rows.forEach((row) => {
            const visible = !query || row.dataset.name.includes(query);
            row.hidden = !visible;
            tbody.appendChild(row);
        });
        let index = 1;
        rows.forEach((row) => {
            if (row.hidden) return;
            row.cells[0].textContent = String(index);
            index++;
        });
    };
    document.addEventListener('input', (event) => {
        const control = event.target;
        if (control instanceof HTMLElement && control.matches('[data-rm-list-search]')) {
            refreshListModal(control.closest('.modal'));
        }
    });
    document.addEventListener('change', (event) => {
        const control = event.target;
        if (control instanceof HTMLElement && control.matches('[data-rm-list-sort]')) {
            refreshListModal(control.closest('.modal'));
        }
    });
    const updateCategoryFilterState = (modal) => {
        if (!modal) return;
        const all = modal.querySelector('[data-category-filter-all]');
        const items = Array.from(modal.querySelectorAll('[data-category-filter-item]'));
        if (!(all instanceof HTMLInputElement) || items.length === 0) return;

        const checked = items.filter((item) => item.checked).length;
        all.checked = checked === items.length;
        all.indeterminate = checked > 0 && checked < items.length;
    };
    document.addEventListener('input', (event) => {
        const control = event.target;
        if (!(control instanceof HTMLInputElement) || !control.matches('[data-category-filter-search]')) {
            return;
        }

        const query = control.value.trim().toLowerCase();
        control.closest('.modal')?.querySelectorAll('[data-category-name]').forEach((row) => {
            row.hidden = query !== '' && !String(row.dataset.categoryName || '').includes(query);
        });
    });
    document.addEventListener('change', (event) => {
        const control = event.target;
        if (!(control instanceof HTMLInputElement)) {
            return;
        }
        if (control.matches('[data-category-filter-all]')) {
            control.closest('.modal')?.querySelectorAll('[data-category-filter-item]').forEach((item) => {
                item.checked = control.checked;
            });
            updateCategoryFilterState(control.closest('.modal'));
            return;
        }
        if (control.matches('[data-category-filter-item]')) {
            updateCategoryFilterState(control.closest('.modal'));
        }
    });
    const padDate = (value) => String(value).padStart(2, '0');
    const setDateRange = (from, to, submit = true) => {
        const statsForm = getStatsForm();
        if (!statsForm) return;
        statsForm.querySelector('[name="desde"]').value = from || '';
        statsForm.querySelector('[name="hasta"]').value = to || '';
        highlightSelectedMonths();
        if (submit) submitStatsFilters(statsForm);
    };
    const submitStatsFilters = async (form) => {
        const content = getStatsContent();
        if (!content) {
            form.submit();
            return;
        }

        const params = new URLSearchParams(new FormData(form));
        const url = `${form.action}?${params.toString()}`;
        content.classList.add('is-loading');

        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextContent = doc.querySelector('[data-stats-content]');
            if (!response.ok || !nextContent) {
                throw new Error('No se pudo actualizar estadisticas.');
            }
            content.innerHTML = nextContent.innerHTML;
            window.history.pushState({}, '', url);
            cleanRedmineApiFetchParam();
            pendingMonthStart = null;
            highlightSelectedMonths();
        } catch (error) {
            form.submit();
        } finally {
            getStatsContent()?.classList.remove('is-loading');
        }
    };
    const formatDate = (date) => `${padDate(date.getDate())}-${padDate(date.getMonth() + 1)}-${date.getFullYear()}`;
    const parseStatsDate = (value) => {
        const match = String(value || '').trim().match(/^(\d{2})-(\d{2})-(\d{4})$/);
        if (!match) return null;
        const [, day, month, year] = match;
        const parsed = new Date(Number(year), Number(month) - 1, Number(day));
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    };
    const monthDateRange = (startMonth, endMonth) => {
        const now = new Date();
        const start = Math.min(startMonth, endMonth);
        const end = Math.max(startMonth, endMonth);
        return [
            formatDate(new Date(now.getFullYear(), start - 1, 1)),
            formatDate(new Date(now.getFullYear(), end, 0)),
        ];
    };
    const highlightMonthRange = (startMonth, endMonth) => {
        const start = Math.min(startMonth, endMonth);
        const end = Math.max(startMonth, endMonth);
        document.querySelectorAll('[data-month]').forEach((button) => {
            const month = Number(button.dataset.month || 0);
            button.classList.toggle('is-range-start', month === start);
            button.classList.toggle('is-range-end', month === end);
            button.classList.toggle('is-range', month >= start && month <= end);
            button.classList.toggle('is-pending', pendingMonthStart === month);
        });
    };
    const highlightSelectedMonths = () => {
        const statsForm = getStatsForm();
        if (!statsForm) return;
        const from = statsForm.querySelector('[name="desde"]').value;
        const to = statsForm.querySelector('[name="hasta"]').value;
        document.querySelectorAll('[data-month]').forEach((button) => {
            button.classList.remove('is-range', 'is-range-start', 'is-range-end', 'is-pending');
        });
        if (!from || !to) return;
        const fromDate = parseStatsDate(from);
        const toDate = parseStatsDate(to);
        if (!fromDate || !toDate) return;
        if (Number.isNaN(fromDate.getTime()) || Number.isNaN(toDate.getTime()) || fromDate.getFullYear() !== toDate.getFullYear()) return;
        highlightMonthRange(fromDate.getMonth() + 1, toDate.getMonth() + 1);
    };

    function selectMonthRange(month) {
        if (!pendingMonthStart) {
            pendingMonthStart = month;
            highlightMonthRange(month, month);
            const [from, to] = monthDateRange(month, month);
            setDateRange(from, to, false);
            return;
        }

        const [from, to] = monthDateRange(pendingMonthStart, month);
        pendingMonthStart = null;
        setDateRange(from, to);
    }

    function setPeriodo(period) {
        const now = new Date();
        if (period === 'clear') {
            pendingMonthStart = null;
            setDateRange('', '');
            return;
        }
        if (period === 'today') {
            const today = formatDate(now);
            setDateRange(today, today);
            return;
        }
        if (period === '30d') {
            const from = new Date(now);
            from.setDate(from.getDate() - 29);
            setDateRange(formatDate(from), formatDate(now));
            return;
        }
        if (period === 'month') {
            setDateRange(formatDate(new Date(now.getFullYear(), now.getMonth(), 1)), formatDate(new Date(now.getFullYear(), now.getMonth() + 1, 0)));
            return;
        }
        if (period === 'year') {
            setDateRange(`01-01-${now.getFullYear()}`, `31-12-${now.getFullYear()}`);
        }
    }

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-stats-filter-form]');
        if (!form) return;
        event.preventDefault();
        pendingMonthStart = null;
        submitStatsFilters(form);
    });

    highlightSelectedMonths();
</script>

