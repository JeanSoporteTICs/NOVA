@php
    $total = (int) ($stats['total'] ?? 0);
    $byDate = $stats['by_date'] ?? [];
    $byMonth = $stats['by_month'] ?? [];
    $maxDaily = max(1, (int) ($stats['max_daily'] ?? 0));
    $datePreviewBars = [];
    $dateModalBars = [];
    $dateRowsCount = count($byDate);
    $previewStep = 552 / max(1, $dateRowsCount);
    $previewBarWidth = max(1.5, $previewStep * 0.68);
    $dateModalCanvasWidth = max(1200, ($dateRowsCount * 34) + 128);
    $dateModalStep = ($dateModalCanvasWidth - 128) / max(1, $dateRowsCount);
    $dateModalBarWidth = max(12, min(24, $dateModalStep * 0.66));
    $dateModalLabelStep = max(1, (int) ceil(max(1, $dateRowsCount) / 60));
    $dateIndex = 0;
    foreach ($byDate as $date => $count) {
        $previewHeight = max(2, ((int) $count / $maxDaily) * 136);
        $modalHeight = max(3, ((int) $count / $maxDaily) * 312);
        $datePreviewBars[] = [
            'date' => (string) $date,
            'count' => (int) $count,
            'x' => round(24 + ($previewStep * $dateIndex) + (($previewStep - $previewBarWidth) / 2), 2),
            'width' => round($previewBarWidth, 2),
            'y' => round(176 - $previewHeight, 2),
            'height' => round($previewHeight, 2),
            'slot_x' => round(24 + ($previewStep * $dateIndex), 2),
            'slot_width' => round($previewStep, 2),
        ];
        $dateModalBars[] = [
            'date' => (string) $date,
            'count' => (int) $count,
            'x' => round(64 + ($dateModalStep * $dateIndex) + (($dateModalStep - $dateModalBarWidth) / 2), 2),
            'width' => round($dateModalBarWidth, 2),
            'y' => round(352 - $modalHeight, 2),
            'height' => round($modalHeight, 2),
            'slot_x' => round(64 + ($dateModalStep * $dateIndex), 2),
            'slot_width' => round($dateModalStep, 2),
        ];
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
    $chartBars = static function (array $rows, float $startX, float $plotWidth, float $baseY, float $height, float $maxBarWidth): array {
        $count = count($rows);
        $max = max(1, $rows ? max($rows) : 0);
        $bars = [];
        $step = $plotWidth / max(1, $count);
        $barWidth = max(10, min($maxBarWidth, $step * 0.58));
        $index = 0;
        foreach ($rows as $value) {
            $barHeight = max(3, ((int) $value / $max) * $height);
            $slotX = $startX + ($step * $index);
            $bars[] = [
                'x' => round($slotX + (($step - $barWidth) / 2), 2),
                'width' => round($barWidth, 2),
                'y' => round($baseY - $barHeight, 2),
                'height' => round($barHeight, 2),
                'slot_x' => round($slotX, 2),
                'slot_width' => round($step, 2),
                'value' => (int) $value,
            ];
            $index++;
        }

        return $bars;
    };
@endphp

<section class="rm-module-head">
    <span class="rm-module-head-icon"><i class="bi bi-bar-chart-line"></i></span>
    <div>
        <small>Analitica operacional</small>
        <h2>Estadisticas</h2>
        <p>Explora volumen de reportes por fecha, categoria, unidad y asignado.</p>
    </div>
    <div class="rm-module-meter">
        <strong>{{ number_format($total, 0, ',', '.') }}</strong>
        <span>tickets</span>
    </div>
</section>

<div data-stats-content>
    <section class="rm-stats-layout">
        <article class="nova-card rm-stats-hero">
            <div>
                <span class="rm-stats-eyebrow">Resumen</span>
                <h2>{{ number_format($total, 0, ',', '.') }} reportes</h2>
                <p>Actualizado {{ $stats['updated_at'] ?? '-' }}</p>
            </div>
            <div class="rm-stats-kpis">
                <div><strong>{{ count($byDate) }}</strong><span>Dias con datos</span></div>
                <div><strong>{{ count($byMonth) }}</strong><span>Meses</span></div>
                <div><strong>{{ $maxDaily }}</strong><span>Max. diario</span></div>
            </div>
        </article>

        <section class="rm-stats-charts">
            <article class="nova-card rm-stats-panel rm-line-panel rm-stats-rank-card" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#stats-date-modal" aria-label="Ver detalle de reportes por fecha">
                <div class="rm-stats-panel-head">
                    <div><h3>Reportes por fecha</h3><p>Volumen diario</p></div>
                    <span>{{ count($byDate) }} fechas</span>
                </div>
                @if ($datePreviewBars)
                    <svg class="rm-date-histogram" viewBox="0 0 600 210" role="img" aria-label="Histograma de reportes por fecha">
                        @for ($i = 0; $i <= 4; $i++)
                            <line class="rm-date-bar-grid" x1="24" y1="{{ 40 + ($i * 34) }}" x2="576" y2="{{ 40 + ($i * 34) }}" />
                        @endfor
                        @foreach ($datePreviewBars as $bar)
                            <g class="rm-date-bar-point" tabindex="0" role="img" data-rm-chart-point data-chart-label="{{ $formatStatsDate($bar['date']) }}" data-chart-value="{{ number_format($bar['count'], 0, ',', '.') }}" aria-label="{{ $formatStatsDate($bar['date']) }}: {{ number_format($bar['count'], 0, ',', '.') }} ticket(s)">
                                <rect class="rm-date-bar-hit" x="{{ $bar['slot_x'] }}" y="40" width="{{ $bar['slot_width'] }}" height="136" />
                                <rect class="rm-date-bar" x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['height'] }}" rx="1.5" />
                            </g>
                        @endforeach
                    </svg>
                    <div class="rm-chart-axis">
                        <span>{{ array_key_first($byDate) }}</span>
                        <span>{{ array_key_last($byDate) }}</span>
                    </div>
                @else
                    <div class="nova-empty-state">Sin datos por fecha.</div>
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

        <section class="nova-card rm-interactive-charts-head">
            <div>
                <h3>Graficos interactivos</h3>
                <p>Categorias y unidades del rango. Haz clic en cada grafico para ver todos los valores.</p>
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
                $bars = $chartBars($previewRows, 54, 612, 204, 154, 34);
            @endphp
            <article class="rm-interactive-chart rm-stats-rank-card" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}" aria-label="Ver detalle de {{ $meta['label'] }}" style="--chart-color: {{ $meta['color'] }};">
                <div class="rm-interactive-chart-title">
                    <div>
                        <h3>{{ $meta['label'] }}</h3>
                    </div>
                    <span>Click para ver todas</span>
                </div>
                @if ($previewRows)
                    <svg class="rm-category-chart rm-rank-bar-chart" viewBox="0 0 704 238" role="img" aria-label="Histograma de {{ $meta['label'] }}">
                        @for ($i = 0; $i <= 4; $i++)
                            @php
                                $gridY = 50 + ($i * 38.5);
                                $axisValue = max(0, round($max - (($max / 4) * $i)));
                            @endphp
                            <line class="rm-category-grid-y" x1="54" y1="{{ $gridY }}" x2="666" y2="{{ $gridY }}" />
                            <text class="rm-category-y-label" x="42" y="{{ $gridY + 4 }}">{{ $axisValue }}</text>
                        @endfor
                        @foreach ($previewRows as $name => $count)
                            @php $bar = $bars[$loop->index] ?? null; @endphp
                            @if ($bar)
                                <g class="rm-rank-bar-point" tabindex="0" role="img" data-rm-chart-point data-chart-label="{{ $name }}" data-chart-value="{{ number_format((int) $count, 0, ',', '.') }}" aria-label="{{ $name }}: {{ number_format((int) $count, 0, ',', '.') }} ticket(s)">
                                    <rect class="rm-rank-bar-hit" x="{{ $bar['slot_x'] }}" y="50" width="{{ $bar['slot_width'] }}" height="154" />
                                    <rect class="rm-rank-bar" x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['height'] }}" rx="3" />
                                </g>
                                @if ($showChartTotals)
                                    <text x="{{ $bar['x'] + ($bar['width'] / 2) }}" y="{{ max(16, $bar['y'] - 8) }}">{{ $bar['value'] }}</text>
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
                    <div class="nova-empty-state">Sin datos.</div>
                @endif
            </article>
        @endforeach
        </section>
    </section>

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
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-nova-modal-close data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    @endforeach

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
        $modalCanvasWidth = max(1200, (count($rows) * 84) + 128);
        $modalLongestLabel = 0;
        foreach (array_keys($rows) as $rowName) {
            $modalLongestLabel = max($modalLongestLabel, \Illuminate\Support\Str::length((string) $rowName));
        }
        $modalLabelDepth = max(100, (int) ceil($modalLongestLabel * 4.2));
        $modalCanvasHeight = max(680, 392 + $modalLabelDepth);
        $modalPlotBottom = $modalCanvasHeight - $modalLabelDepth - 28;
        $modalPlotHeight = $modalPlotBottom - 40;
        $modalLabelY = $modalPlotBottom + 26;
        $modalPlotWidth = $modalCanvasWidth - 128;
        $modalBars = $chartBars($rows, 64, $modalPlotWidth, $modalPlotBottom, $modalPlotHeight, 38);
    @endphp
    <div class="modal fade rm-stats-chart-modal" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable rm-stats-chart-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5">Grafico completo de {{ \Illuminate\Support\Str::lower($meta['label']) }}</h2>
                    </div>
                    <button type="button" class="btn-close" data-nova-modal-close data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body rm-stats-full-modal">
                    @if ($rows)
                        <div class="rm-rank-chart-scroll">
                        <section class="rm-modal-chart-panel" style="--chart-color: {{ $meta['color'] }}; --chart-canvas-width: {{ $modalCanvasWidth }}px; --chart-canvas-height: {{ $modalCanvasHeight }}px;">
                            <svg class="rm-category-chart rm-category-chart-modal rm-rank-bar-chart" viewBox="0 0 {{ $modalCanvasWidth }} {{ $modalCanvasHeight }}" role="img" aria-label="Histograma completo de {{ $meta['label'] }}" preserveAspectRatio="xMidYMid meet">
                                @for ($i = 0; $i <= 4; $i++)
                                    @php
                                        $gridY = 40 + ($i * ($modalPlotHeight / 4));
                                        $axisValue = max(0, round($max - (($max / 4) * $i)));
                                    @endphp
                                    <line class="rm-category-grid-y" x1="64" y1="{{ $gridY }}" x2="{{ $modalCanvasWidth - 24 }}" y2="{{ $gridY }}" />
                                    <text class="rm-category-y-label" x="48" y="{{ $gridY + 4 }}">{{ $axisValue }}</text>
                                @endfor
                                @foreach ($rows as $name => $count)
                                    @php $bar = $modalBars[$loop->index] ?? null; @endphp
                                    @if ($bar)
                                        <g class="rm-rank-bar-point" tabindex="0" role="img" data-rm-chart-point data-chart-label="{{ $name }}" data-chart-value="{{ number_format((int) $count, 0, ',', '.') }}" aria-label="{{ $name }}: {{ number_format((int) $count, 0, ',', '.') }} ticket(s)">
                                            <rect class="rm-rank-bar-hit" x="{{ $bar['slot_x'] }}" y="40" width="{{ $bar['slot_width'] }}" height="{{ $modalPlotHeight }}" />
                                            <rect class="rm-rank-bar" x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['height'] }}" rx="4" />
                                        </g>
                                    @endif
                                @endforeach
                                @foreach ($rows as $name => $count)
                                    @php $bar = $modalBars[$loop->index] ?? null; @endphp
                                    @if ($bar)
                                        <text class="rm-category-x-label" x="{{ $bar['x'] + ($bar['width'] / 2) }}" y="{{ $modalLabelY }}" transform="rotate(-42 {{ $bar['x'] + ($bar['width'] / 2) }} {{ $modalLabelY }})">
                                            <title>{{ $name }}</title>{{ $name }}
                                        </text>
                                    @endif
                                @endforeach
                            </svg>
                        </section>
                        </div>
                    @endif
                </div>
                <div class="modal-footer rm-chart-modal-footer">
                    <span>Incluye todos los valores con tickets dentro del rango seleccionado.</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-nova-modal-close data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    @endforeach

    <div class="modal fade rm-stats-chart-modal" id="stats-date-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable rm-stats-chart-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title fs-5"><i class="bi bi-bar-chart-fill"></i> Reportes por fecha</h2>
                    <div class="text-muted fw-semibold">{{ count($byDate) }} fecha(s) con datos</div>
                </div>
                <button type="button" class="btn-close" data-nova-modal-close data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                @if ($dateModalBars)
                    <div class="rm-date-chart-scroll">
                        <section class="rm-modal-date-chart-panel" style="--date-chart-width: {{ $dateModalCanvasWidth }}px;">
                            <svg class="rm-date-bar-chart" viewBox="0 0 {{ $dateModalCanvasWidth }} 450" role="img" aria-label="Histograma completo de reportes por fecha" preserveAspectRatio="xMidYMid meet">
                                @for ($i = 0; $i <= 4; $i++)
                                    @php
                                        $gridY = 40 + ($i * 78);
                                        $axisValue = max(0, round($maxDaily - (($maxDaily / 4) * $i)));
                                    @endphp
                                    <line class="rm-date-bar-grid" x1="64" y1="{{ $gridY }}" x2="{{ $dateModalCanvasWidth - 24 }}" y2="{{ $gridY }}" />
                                    <text class="rm-date-bar-y-label" x="48" y="{{ $gridY + 4 }}">{{ $axisValue }}</text>
                                @endfor
                                @foreach ($dateModalBars as $bar)
                                    <g class="rm-date-bar-point" tabindex="0" role="img" data-rm-chart-point data-chart-label="{{ $formatStatsDate($bar['date']) }}" data-chart-value="{{ number_format($bar['count'], 0, ',', '.') }}" aria-label="{{ $formatStatsDate($bar['date']) }}: {{ number_format($bar['count'], 0, ',', '.') }} ticket(s)">
                                        <rect class="rm-date-bar-hit" x="{{ $bar['slot_x'] }}" y="40" width="{{ $bar['slot_width'] }}" height="312" />
                                        <rect class="rm-date-bar" x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['height'] }}" rx="3" />
                                    </g>
                                    @if ($loop->index % $dateModalLabelStep === 0)
                                        <text class="rm-date-bar-x-label" x="{{ $bar['x'] + ($bar['width'] / 2) }}" y="386" transform="rotate(-42 {{ $bar['x'] + ($bar['width'] / 2) }} 386)">{{ $formatStatsDate($bar['date']) }}</text>
                                    @endif
                                @endforeach
                            </svg>
                        </section>
                    </div>
                @else
                    <div class="nova-empty-state">Sin datos por fecha.</div>
                @endif
            </div>
        </div>
    </div>
    </div>
</div>

<script>
    let pendingMonthStart = null;
    const getStatsForm = () => document.querySelector('[data-stats-filter-form]');
    const getStatsContent = () => document.querySelector('[data-stats-content]');
    const chartPointTooltip = document.createElement('div');
    chartPointTooltip.className = 'rm-chart-point-tooltip';
    chartPointTooltip.hidden = true;
    chartPointTooltip.setAttribute('role', 'tooltip');
    chartPointTooltip.innerHTML = '<strong></strong><span></span>';
    document.body.appendChild(chartPointTooltip);
    const positionChartTooltip = (x, y) => {
        const gap = 14;
        const edge = 12;
        const width = chartPointTooltip.offsetWidth;
        const height = chartPointTooltip.offsetHeight;
        const left = Math.min(Math.max(edge, x + gap), window.innerWidth - width - edge);
        const top = y - height - gap < edge ? y + gap : y - height - gap;
        chartPointTooltip.style.left = `${left}px`;
        chartPointTooltip.style.top = `${Math.max(edge, top)}px`;
    };
    const showChartPointTooltip = (point, x, y) => {
        chartPointTooltip.querySelector('strong').textContent = point.dataset.chartLabel || 'Sin nombre';
        chartPointTooltip.querySelector('span').textContent = `${point.dataset.chartValue || '0'} ticket(s)`;
        chartPointTooltip.hidden = false;
        positionChartTooltip(x, y);
    };
    const hideChartPointTooltip = () => { chartPointTooltip.hidden = true; };
    document.addEventListener('pointerover', (event) => {
        const point = event.target instanceof Element ? event.target.closest('[data-rm-chart-point]') : null;
        if (!point) return;
        showChartPointTooltip(point, event.clientX, event.clientY);
    });
    document.addEventListener('pointermove', (event) => {
        const point = event.target instanceof Element ? event.target.closest('[data-rm-chart-point]') : null;
        if (point && !chartPointTooltip.hidden) positionChartTooltip(event.clientX, event.clientY);
    });
    document.addEventListener('pointerout', (event) => {
        const point = event.target instanceof Element ? event.target.closest('[data-rm-chart-point]') : null;
        const nextPoint = event.relatedTarget instanceof Element ? event.relatedTarget.closest('[data-rm-chart-point]') : null;
        if (point && point !== nextPoint) hideChartPointTooltip();
    });
    document.addEventListener('focusin', (event) => {
        const point = event.target instanceof Element ? event.target.closest('[data-rm-chart-point]') : null;
        if (!point) return;
        const bounds = point.getBoundingClientRect();
        showChartPointTooltip(point, bounds.left + (bounds.width / 2), bounds.top);
    });
    document.addEventListener('focusout', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-rm-chart-point]')) hideChartPointTooltip();
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
            document.querySelectorAll('body > .modal[id^="stats-"]').forEach((modal) => {
                window.bootstrap?.Modal.getInstance(modal)?.dispose();
                modal.remove();
            });
            content.innerHTML = nextContent.innerHTML;
            window.history.pushState({}, '', url);
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
