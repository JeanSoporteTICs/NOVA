@php
    $redmineRoute = static function (string $name, array|string $parameters = []): string {
        if (is_string($parameters)) {
            $parameters = ['section' => $parameters];
        }

        return route($name, $parameters);
    };
    $sectionIcons = [
        'dashboard' => 'bi-inboxes',
        'webhook' => 'bi-pencil-square',
        'horas-extra' => 'bi-clock',
        'historico' => 'bi-archive',
        'usuarios' => 'bi-people',
        'configuracion' => 'bi-sliders',
        'estadisticas' => 'bi-bar-chart-line',
        'estadisticas-api' => 'bi-window',
        'actividad' => 'bi-activity',
    ];
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Redmine - {{ $sectionLabel }}</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
    <style>
        body { min-height: 100vh; background: #eef3fb; }
        body.rm-maintenance-active form[method="post"]:not([data-maintenance-allowed="1"]) button[type="submit"],
        body.rm-maintenance-active form[method="post"]:not([data-maintenance-allowed="1"]) input,
        body.rm-maintenance-active form[method="post"]:not([data-maintenance-allowed="1"]) select,
        body.rm-maintenance-active form[method="post"]:not([data-maintenance-allowed="1"]) textarea,
        body.rm-maintenance-active [data-nova-modal-open] { cursor: not-allowed; opacity: .58; }
        .rm-shell { min-height: 100vh; }
        .rm-navbar { min-height: 68px; background: linear-gradient(115deg, #1f2f56 0%, #314ed8 62%, #4966ff 100%); box-shadow: 0 16px 36px rgba(31, 47, 86, .22); }
        .rm-brand-mark { display: inline-grid; width: 42px; height: 42px; place-items: center; border-radius: 12px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.24); color: #fff; }
        .rm-top-actions { margin-left: auto; display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .rm-layout { width: 100%; margin: 0; padding: 24px 24px 44px; }
        .rm-section-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
        .rm-section-nav .nav-link { display: inline-flex; align-items: center; gap: 8px; min-height: 42px; border-radius: 10px; padding: 8px 12px; background: #fff; color: #334155; font-weight: 800; box-shadow: 0 8px 20px rgba(15,23,42,.05); }
        .rm-section-nav .nav-link.active { background: var(--nova-primary); color: #fff; box-shadow: 0 14px 30px rgba(37, 99, 235, .22); }
        .rm-main { min-width: 0; }
        .rm-page-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
        .rm-page-title { margin: 0; color: #0f172a; font-size: clamp(1.55rem, 3vw, 2.25rem); font-weight: 800; }
        .rm-hero { border: 0; color: #fff; background: linear-gradient(130deg, #4f86f7 0%, #2f9ed9 48%, #31c5ae 100%); box-shadow: 0 18px 34px rgba(49, 91, 170, .14); }
        .rm-hero-icon { display: grid; width: 46px; height: 46px; place-items: center; flex: 0 0 auto; border-radius: 14px; background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28); font-size: 1.25rem; }
        .rm-hero-retention { display: inline-flex; align-items: center; gap: 7px; margin-left: auto; min-height: 36px; padding: 7px 11px; border-radius: 999px; border: 1px solid rgba(255,255,255,.35); background: rgba(255,255,255,.14); color: #fff; font-size: .86rem; font-weight: 900; white-space: nowrap; }
        .rm-stat-card { min-height: 116px; }
        .rm-filter-card { color: inherit; text-decoration: none; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
        .rm-filter-card:hover { color: inherit; transform: translateY(-1px); box-shadow: 0 18px 34px rgba(15,23,42,.1); }
        .rm-filter-card.active { border-color: var(--nova-primary); box-shadow: 0 0 0 3px rgba(37, 99, 235, .14), 0 18px 34px rgba(37,99,235,.14); }
        .rm-stat-icon { display: grid; width: 58px; height: 58px; place-items: center; flex: 0 0 auto; border-radius: 16px; color: #fff; font-size: 1.55rem; }
        .rm-stat-icon.is-pending { background: #ff8a0a; }
        .rm-stat-icon.is-success { background: #18c16f; }
        .rm-stat-icon.is-danger { background: #fb5565; }
        .rm-section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .rm-section-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; }
        .rm-section-head p { margin: 4px 0 0; color: var(--nova-muted); }
        .rm-work-panel { border-radius: 14px; }
        .rm-filter-summary { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .rm-bulk-row { display: grid; grid-template-columns: auto minmax(260px, 1fr); align-items: center; gap: 12px; }
        .rm-toolbar-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .rm-redmine-send-modal { border: 0; border-radius: 16px; box-shadow: 0 26px 70px rgba(15, 23, 42, .28); }
        .rm-redmine-send-modal .modal-body { display: grid; justify-items: center; gap: 8px; padding: 26px; text-align: center; }
        .rm-redmine-send-modal img { width: 118px; height: 118px; object-fit: contain; }
        .rm-redmine-send-modal strong { display: block; color: #0f172a; font-size: 1rem; font-weight: 900; }
        .rm-redmine-send-modal span { display: block; color: #64748b; font-size: .84rem; font-weight: 700; }
        .rm-redmine-send-bar { position: relative; height: 8px; margin-top: 10px; overflow: hidden; border-radius: 999px; background: #dbeafe; }
        .rm-redmine-send-bar i { position: absolute; inset: 0 auto 0 0; width: 42%; border-radius: inherit; background: linear-gradient(90deg, #2563eb, #0ea5e9, #14b8a6); animation: rm-api-loading 1.05s ease-in-out infinite; }
        .rm-redmine-send-modal .rm-redmine-send-bar { width: min(360px, 100%); }
        .rm-form-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
        .rm-hours-group td { background: #f5f8ff; border-top: 2px solid #d5defb; border-bottom: 2px solid #d5defb; }
        .rm-hours-group-inner { display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; flex-wrap: wrap; }
        .rm-table-wrap .table thead th { background: #eaf8fd; color: #435061; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }
        .rm-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .rm-panel { padding: 16px; }
        .rm-kv { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--nova-line); }
        .rm-kv:last-child { border-bottom: 0; }
        .rm-kv span { color: var(--nova-muted); font-weight: 700; }
        .rm-log { max-height: 520px; overflow: auto; margin: 0; padding: 14px; background: #0f172a; color: #e2e8f0; border-radius: var(--nova-radius); font-size: .84rem; }
        .rm-stats-layout { display: grid; gap: 16px; }
        [data-stats-content] { transition: opacity .16s ease; }
        [data-stats-content].is-loading { opacity: .55; pointer-events: none; }
        .rm-stats-hero { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px; background: linear-gradient(120deg, #2563eb, #0ea5e9 52%, #14b8a6); color: #fff; }
        .rm-stats-eyebrow { display: block; margin-bottom: 4px; color: rgba(255,255,255,.72); font-weight: 900; text-transform: uppercase; font-size: .72rem; }
        .rm-stats-hero h2 { margin: 0; font-size: 2rem; font-weight: 900; }
        .rm-stats-hero p { margin: 3px 0 0; color: rgba(255,255,255,.78); font-weight: 800; }
        .rm-stats-kpis { display: grid; grid-template-columns: repeat(3, minmax(100px, 1fr)); gap: 10px; }
        .rm-stats-kpis div { min-width: 112px; padding: 10px 12px; border: 1px solid rgba(255,255,255,.25); border-radius: 12px; background: rgba(255,255,255,.14); }
        .rm-stats-kpis strong { display: block; font-size: 1.2rem; line-height: 1; }
        .rm-stats-kpis span { display: block; margin-top: 4px; color: rgba(255,255,255,.74); font-size: .78rem; font-weight: 800; }
        .rm-stats-charts { display: grid; grid-template-columns: minmax(0, 2fr) minmax(320px, 1fr); gap: 16px; }
        .rm-stats-ranks { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 16px; align-items: stretch; }
        .rm-stats-panel { padding: 16px; }
        .rm-stats-panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
        .rm-stats-panel-head h3 { display: flex; align-items: center; gap: 7px; margin: 0; color: #0f172a; font-size: .98rem; font-weight: 900; }
        .rm-stats-panel-head p { margin: 3px 0 0; color: var(--nova-muted); font-weight: 700; }
        .rm-stats-panel-head > span { padding: 5px 9px; border-radius: 999px; background: #eef6ff; color: var(--nova-primary); font-size: .76rem; font-weight: 900; white-space: nowrap; }
        .rm-line-chart { display: block; width: 100%; min-height: 260px; }
        .rm-line-chart line { stroke: #e2e8f0; stroke-width: 1; }
        .rm-line-chart polyline { fill: none; stroke: #2563eb; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
        .rm-line-chart circle { fill: #fff; stroke: #2563eb; stroke-width: 2; }
        .rm-line-chart-large { min-height: 360px; }
        .rm-chart-axis { display: flex; justify-content: space-between; color: var(--nova-muted); font-size: .76rem; font-weight: 800; }
        .rm-date-detail-list { display: grid; gap: 9px; }
        .rm-date-detail-row { display: grid; grid-template-columns: 110px minmax(0, 1fr) 56px; gap: 10px; align-items: center; }
        .rm-date-detail-row span, .rm-date-detail-row strong { color: #334155; font-size: .84rem; font-weight: 900; }
        .rm-date-detail-row div { height: 10px; border-radius: 999px; background: #e2e8f0; overflow: hidden; }
        .rm-date-detail-row i { display: block; height: 100%; border-radius: inherit; background: #2563eb; }
        .rm-donut-wrap { display: grid; grid-template-columns: 160px minmax(0, 1fr); gap: 16px; align-items: center; }
        .rm-api-summary-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 14px; align-items: stretch; }
        .rm-api-summary-grid > .nova-card { height: 100%; }
        .rm-api-hero-card { grid-column: span 6; min-height: 142px; padding: 24px 26px; background: rgba(255, 255, 255, .82); box-shadow: 0 18px 42px rgba(15, 23, 42, .08); }
        .rm-api-hero-card span,
        .rm-api-card-head span,
        .rm-api-card p { color: #667085; font-size: .84rem; font-weight: 700; }
        .rm-api-hero-card strong { display: block; margin-top: 8px; color: #1473ff; font-size: 2.15rem; line-height: 1; font-weight: 900; }
        .rm-api-hero-card.is-cyan strong { color: #06b6d4; }
        .rm-api-hero-card p { margin: 10px 0 0; color: #4b5563; font-weight: 700; }
        .rm-api-card { grid-column: span 3; min-height: 130px; padding: 14px; background: rgba(255, 255, 255, .82); box-shadow: 0 18px 42px rgba(15, 23, 42, .07); }
        .rm-api-card-wide { grid-column: span 6; }
        .rm-api-top-card { grid-column: span 3; }
        .rm-api-click-card { cursor: pointer; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
        .rm-api-click-card:hover { border-color: var(--nova-primary); box-shadow: 0 18px 42px rgba(37, 99, 235, .12); transform: translateY(-1px); }
        .rm-api-click-card:focus-visible { outline: none; box-shadow: var(--nova-focus); }
        .rm-api-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-bottom: 10px; margin-bottom: 12px; border-bottom: 1px solid #d1d5db; }
        .rm-api-card-head h3 { margin: 0; color: #1f2937; font-size: .95rem; font-weight: 900; }
        .rm-api-card-head b { display: inline-block; margin-left: 6px; padding: 2px 7px; border-radius: 6px; background: #dbeafe; color: #1473ff; }
        .rm-api-quick-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .rm-api-quick-grid div { min-height: 72px; padding: 12px 14px; border-radius: 6px; background: #dbeafe; }
        .rm-api-quick-grid div:nth-child(2) { background: #dcf7ff; }
        .rm-api-quick-grid div:nth-child(3) { background: #dff1ea; }
        .rm-api-quick-grid span { display: block; color: #667085; font-size: .78rem; font-weight: 700; }
        .rm-api-quick-grid strong { display: block; margin-top: 3px; color: #1473ff; font-size: 1.25rem; line-height: 1; font-weight: 900; }
        .rm-api-selected-total { color: #1f2937; font-weight: 900; }
        .rm-api-selected-total strong { color: #1473ff; }
        .rm-api-chip-row { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 8px; }
        .rm-api-chip-row span { padding: 3px 7px; border: 1px solid #d1d5db; border-radius: 6px; background: #f8fafc; color: #4b5563; font-size: .72rem; font-weight: 900; }
        .rm-api-chip-row span:last-child { border-color: #6b7280; background: #6b7280; color: #fff; }
        .rm-api-mini-list { display: grid; gap: 8px; align-content: start; }
        .rm-api-mini-list div { display: flex; align-items: center; justify-content: space-between; gap: 10px; color: #1f2937; font-weight: 800; }
        .rm-api-mini-list strong { font-weight: 900; }
        .rm-api-top-table { width: 100%; border-collapse: collapse; }
        .rm-api-top-table thead th { padding: 10px 12px; background: #eaf8fd; color: #435061; font-size: .72rem; text-transform: uppercase; }
        .rm-api-top-table td { padding: 11px 12px; border-bottom: 1px solid #e5e7eb; color: #111827; font-weight: 800; }
        .rm-api-top-table th:first-child,
        .rm-api-top-table td:first-child { width: 56px; text-align: center; color: #667085; }
        .rm-api-top-table th:last-child,
        .rm-api-top-table td:last-child { width: 90px; text-align: right; font-weight: 900; }
        .rm-list-modal-controls { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; margin-bottom: 14px; }
        .rm-list-modal-controls label { display: grid; gap: 5px; color: #4b5563; font-size: .82rem; font-weight: 900; }
        .rm-list-modal-controls .form-select { min-width: 210px; }
        .rm-list-modal-controls .form-control { min-width: 260px; }
        .rm-category-select-controls { display: grid; grid-template-columns: auto minmax(220px, 1fr); gap: 12px; align-items: center; margin-bottom: 14px; }
        .rm-category-select-controls .form-check { display: inline-flex; align-items: center; gap: 7px; margin: 0; color: #1f2937; font-weight: 800; white-space: nowrap; }
        .rm-category-select-controls .form-control { min-height: 38px; border-radius: 12px; }
        .rm-category-check-list { display: grid; max-height: 430px; overflow: auto; padding: 8px; border: 1px solid #d7e0ea; border-radius: 8px; background: #fff; }
        .rm-category-check-row { display: flex; align-items: center; gap: 8px; min-height: 28px; margin: 0; color: #1f2937; font-weight: 700; }
        .rm-category-check-row span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rm-interactive-charts-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 16px; background: #f8fafc; }
        .rm-interactive-charts-head h3 { margin: 0; color: #1f2937; font-size: .98rem; font-weight: 900; }
        .rm-interactive-charts-head p { margin: 3px 0 0; color: #6b7280; font-size: .82rem; font-weight: 700; }
        .rm-chart-controls { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .rm-chart-controls label { color: #4b5563; font-size: .8rem; font-weight: 900; }
        .rm-chart-controls .form-select { width: 160px; border-radius: 14px; background-color: #fff; }
        .rm-chart-total-toggle { display: inline-flex; align-items: center; gap: 6px; margin: 0; white-space: nowrap; }
        .rm-interactive-chart { grid-column: span 6; min-width: 0; padding: 12px; border: 1px solid #d7e0ea; border-radius: 8px; background: #fff; cursor: pointer; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
        .rm-stats-ranks > .rm-interactive-chart:last-child:nth-child(odd) { grid-column: span 12; }
        .rm-interactive-chart:hover { border-color: var(--nova-primary); box-shadow: 0 16px 32px rgba(37, 99, 235, .12); transform: translateY(-1px); }
        .rm-interactive-chart:focus-visible { outline: none; box-shadow: var(--nova-focus); }
        .rm-interactive-chart-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 4px; }
        .rm-interactive-chart-title h3 { margin: 0; color: #1f2937; font-size: .92rem; font-weight: 900; }
        .rm-interactive-chart-title p,
        .rm-interactive-chart-title span { margin: 2px 0 0; color: #6b7280; font-size: .76rem; font-weight: 700; }
        .rm-interactive-chart-title span { white-space: nowrap; }
        .rm-category-chart { display: block; width: 100%; height: 360px; }
        .rm-category-chart-modal { width: 100%; height: 100%; min-height: 0; }
        .rm-category-chart .rm-category-grid-y,
        .rm-category-chart .rm-category-grid-x { stroke: #e5e7eb; stroke-width: 1; }
        .rm-category-area { fill: var(--chart-color); opacity: .16; }
        .rm-category-line { fill: none; stroke: var(--chart-color); stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
        .rm-category-chart circle { fill: #fff; stroke: var(--chart-color); stroke-width: 1.3; }
        .rm-category-point { cursor: help; }
        .rm-category-point-hit { fill: transparent; stroke: none; pointer-events: all; }
        .rm-category-point:hover circle:not(.rm-category-point-hit) { fill: var(--chart-color); stroke-width: 1.8; }
        .rm-category-chart text { fill: #4b5563; font-size: 11px; font-weight: 800; text-anchor: middle; }
        .rm-category-chart .rm-category-y-label { fill: #6b7280; font-size: 10px; font-weight: 700; text-anchor: end; }
        .rm-category-chart .rm-category-x-label { fill: #4b5563; font-size: 10px; font-weight: 700; text-anchor: end; }
        .rm-category-axis { display: grid; grid-template-columns: repeat(10, minmax(0, 1fr)); gap: 2px; min-height: 38px; margin: -8px 0 0 20px; }
        .rm-category-axis span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #4b5563; font-size: .66rem; font-weight: 700; transform: rotate(-10deg); transform-origin: top left; }
        .rm-category-axis-modal { grid-template-columns: repeat(auto-fit, minmax(72px, 1fr)); min-height: 48px; }
        .rm-category-axis-modal .is-muted-label { visibility: hidden; }
        .modal-fullscreen .modal-content { height: 100vh; }
        .rm-stats-full-modal { display: flex; height: auto; min-height: 0; flex: 1 1 auto; overflow: hidden; padding: 0; background: #fff; }
        .rm-modal-chart-panel,
        .rm-modal-detail-panel { min-width: 0; padding: 14px; border: 1px solid #d7e0ea; border-radius: 8px; background: #fff; }
        .rm-modal-chart-panel { width: 100%; height: 100%; min-height: 0; padding: 0; border: 0; border-radius: 0; }
        .rm-modal-detail-panel { min-height: 0; overflow: auto; }
        .rm-chart-modal-footer { min-height: 68px; background: #f5f7fb; }
        .rm-chart-modal-footer span { margin-right: auto; color: #6b7280; font-size: .82rem; font-weight: 700; }
        .rm-timeline-box { display: grid; gap: 12px; }
        .rm-timeline-header, .rm-timeline-footer, .rm-timeline-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .rm-timeline-header span:first-child { color: #0f172a; font-weight: 900; }
        .rm-timeline-header span:last-child, .rm-timeline-footer { color: var(--nova-muted); font-size: .78rem; font-weight: 900; text-transform: uppercase; }
        .rm-timeline-actions > div:last-child { display: flex; gap: 8px; flex-wrap: wrap; }
        .rm-timeline-months { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 5px; }
        .rm-timeline-months button { min-height: 34px; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; color: #475569; font-size: .74rem; font-weight: 900; }
        .rm-timeline-months button:hover { border-color: var(--nova-primary); color: var(--nova-primary); background: #eef6ff; }
        .rm-timeline-months button.is-range { border-color: #93c5fd; background: #dbeafe; color: #1d4ed8; }
        .rm-timeline-months button.is-range-start,
        .rm-timeline-months button.is-range-end,
        .rm-timeline-months button.is-pending { border-color: var(--nova-primary); background: var(--nova-primary); color: #fff; box-shadow: 0 8px 18px rgba(37, 99, 235, .2); }
        .rm-timeline-dates { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .rm-timeline-dates .form-control { max-width: 180px; }
        .rm-api-import-row { display: grid; grid-template-columns: repeat(5, minmax(140px, 180px)) minmax(160px, 1fr); gap: 8px; align-items: end; }
        .rm-api-import-row .form-control,
        .rm-api-import-row .form-select { width: 100%; min-width: 0; }
        .rm-api-import-row .btn { white-space: nowrap; min-height: 31px; }
        .rm-api-loading-overlay { position: fixed; inset: 0; z-index: 2400; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(15, 23, 42, .44); backdrop-filter: blur(4px); }
        body.rm-api-is-importing .rm-api-loading-overlay { display: flex; }
        .rm-api-loading-card { width: min(460px, 100%); padding: 22px; border: 1px solid #d7e0ea; border-radius: 12px; background: #fff; box-shadow: 0 26px 70px rgba(15, 23, 42, .28); text-align: center; }
        .rm-api-loading-card img { width: 118px; height: 118px; object-fit: contain; margin-bottom: 12px; }
        .rm-api-loading-card strong { display: block; color: #0f172a; font-size: 1rem; font-weight: 900; }
        .rm-api-loading-card span { display: block; margin-top: 4px; color: #64748b; font-size: .84rem; font-weight: 700; }
        .rm-api-loading-bar { position: relative; height: 9px; margin-top: 18px; overflow: hidden; border-radius: 999px; background: #e2e8f0; }
        .rm-api-loading-bar i { position: absolute; inset: 0 auto 0 0; width: 42%; border-radius: inherit; background: linear-gradient(90deg, #2563eb, #0ea5e9, #14b8a6); animation: rm-api-loading 1.05s ease-in-out infinite; }
        [data-redmine-api-import-form].is-importing [data-redmine-api-import-button] { pointer-events: none; opacity: .82; }
        [data-redmine-api-import-form].is-importing [data-redmine-api-import-button] i { animation: rm-api-spin .8s linear infinite; }
        @keyframes rm-api-loading {
            0% { transform: translateX(-105%); }
            50% { transform: translateX(70%); }
            100% { transform: translateX(245%); }
        }
        @keyframes rm-api-spin {
            to { transform: rotate(360deg); }
        }
        .rm-donut { width: 160px; height: 160px; border-radius: 50%; display: grid; place-items: center; background: conic-gradient(var(--donut-bg)); position: relative; }
        .rm-donut::after { content: ""; position: absolute; inset: 28px; border-radius: 50%; background: #fff; }
        .rm-donut strong, .rm-donut span { position: relative; z-index: 1; grid-column: 1; grid-row: 1; }
        .rm-donut strong { align-self: center; margin-top: -12px; color: #0f172a; font-size: 1.7rem; font-weight: 900; }
        .rm-donut span { align-self: center; margin-top: 30px; color: var(--nova-muted); font-size: .75rem; font-weight: 900; }
        .rm-donut-list { display: grid; gap: 8px; }
        .rm-donut-list div { display: flex; justify-content: space-between; gap: 8px; padding-bottom: 8px; border-bottom: 1px solid var(--nova-line); }
        .rm-donut-list span { display: inline-flex; align-items: center; gap: 7px; color: #334155; font-weight: 800; }
        .rm-donut-list span i { display: inline-block; flex: 0 0 auto; width: 10px; height: 10px; border-radius: 999px; }
        .rm-donut-list strong { color: #0f172a; }
        .rm-rank-list { display: grid; gap: 10px; }
        .rm-rank-row { display: grid; gap: 6px; }
        .rm-rank-row div { display: flex; justify-content: space-between; gap: 10px; }
        .rm-rank-row span { min-width: 0; color: #334155; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rm-rank-row strong { color: #0f172a; font-weight: 900; }
        .rm-rank-row i { display: block; height: 8px; border-radius: 999px; background: var(--rank-color); }
        .rm-stats-rank-card { cursor: pointer; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
        .rm-stats-rank-card:hover { border-color: var(--nova-primary); box-shadow: 0 18px 34px rgba(37, 99, 235, .12); transform: translateY(-1px); }
        .rm-stats-rank-card:focus-visible { outline: none; box-shadow: var(--nova-focus); }
        .rm-rank-more { margin-top: 10px; color: var(--nova-primary); font-size: .82rem; font-weight: 900; }
        .rm-rank-list-modal { gap: 13px; }
        .rm-rank-list-modal .rm-rank-row span { white-space: normal; }
        .rm-rank-list-modal .rm-rank-row i { height: 10px; }
        .rm-toast { position: fixed; right: 22px; bottom: 22px; z-index: 2000; display: flex; align-items: flex-start; gap: 10px; max-width: min(420px, calc(100vw - 32px)); padding: 14px 16px; border: 1px solid #bfdbfe; border-radius: 14px; background: #eff6ff; color: #0f172a; box-shadow: 0 22px 48px rgba(15, 23, 42, .18); font-weight: 800; transition: opacity .18s ease, transform .18s ease; }
        .rm-toast i { color: var(--nova-primary); font-size: 1.1rem; margin-top: 1px; }
        .rm-toast.is-success { border-color: #86efac; background: #ecfdf5; color: #14532d; }
        .rm-toast.is-success i { color: #16a34a; }
        .rm-toast.is-info { border-color: #fde68a; background: #fffbeb; color: #713f12; }
        .rm-toast.is-info i { color: #d97706; }
        .rm-toast.is-danger { border-color: #fecaca; background: #fef2f2; color: #7f1d1d; }
        .rm-toast.is-danger i { color: #dc2626; }
        .rm-toast.is-hiding { opacity: 0; transform: translateY(8px); pointer-events: none; }
        @media (max-width: 991.98px) {
            .rm-layout { padding: 18px 12px 36px; }
            .rm-stats-hero,
            .rm-stats-charts,
            .rm-stats-ranks,
            .rm-api-summary-grid,
            .rm-api-import-row,
            .rm-donut-wrap { grid-template-columns: 1fr; }
            .rm-api-hero-card,
            .rm-api-card,
            .rm-api-card-wide,
            .rm-api-top-card,
            .rm-interactive-chart,
            .rm-stats-ranks > .rm-interactive-chart:last-child:nth-child(odd) { grid-column: auto; }
            .rm-api-quick-grid { grid-template-columns: 1fr; }
            .rm-interactive-charts-head { align-items: stretch; flex-direction: column; }
            .rm-chart-controls { justify-content: flex-start; }
            .rm-category-chart { height: 300px; }
            .rm-category-chart-modal { height: 100%; }
            .rm-category-select-controls { grid-template-columns: 1fr; }
            .rm-stats-full-modal { height: auto; padding: 0; }
            .rm-stats-hero { align-items: stretch; }
            .rm-stats-kpis { grid-template-columns: 1fr; }
            .rm-timeline-months { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .rm-section-nav { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
            .rm-section-nav .nav-link { white-space: nowrap; }
            .rm-page-head { flex-direction: column; }
            .rm-grid { grid-template-columns: 1fr; }
            .rm-filter-summary { justify-content: flex-start; }
            .rm-bulk-row { grid-template-columns: 1fr; }
            .rm-form-actions { justify-content: flex-start; }
            .rm-form-actions .btn { width: 100%; }
            .rm-toolbar-actions { justify-content: flex-start; }
            .rm-toolbar-actions .btn { width: 100%; }
        }

        /* Visual parity with Redmine Mantencion for /NOVA/public/index.php/redmine_tic */
        body.nova-page {
            background:
                radial-gradient(circle at 12% 8%, rgba(37, 99, 235, .08), transparent 28rem),
                radial-gradient(circle at 88% 12%, rgba(20, 184, 166, .08), transparent 26rem),
                #eef3fa !important;
            color: #111827;
            font-family: var(--nova-font);
        }
        .rm-navbar {
            min-height: 78px !important;
            border: 1px solid rgba(255,255,255,.12) !important;
            border-radius: 0 0 22px 22px !important;
            background: #102033 !important;
            box-shadow: 0 22px 54px rgba(15, 23, 42, .24) !important;
        }
        .rm-navbar .navbar-brand { font-weight: 900 !important; letter-spacing: 0 !important; }
        .rm-brand-mark,
        .rm-hero-icon {
            border-radius: 14px !important;
            background: rgba(255,255,255,.16) !important;
            border: 1px solid rgba(255,255,255,.28) !important;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.2) !important;
        }
        .rm-section-nav { gap: .55rem !important; margin-bottom: 1rem !important; }
        .rm-section-nav .nav-link {
            min-height: 40px !important;
            border: 1px solid rgba(215, 226, 239, .92) !important;
            border-radius: 8px !important;
            background: rgba(255,255,255,.94) !important;
            color: #334155 !important;
            font-weight: 900 !important;
            box-shadow: 0 8px 18px rgba(15, 23, 42, .05) !important;
            transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease, border-color .18s ease !important;
        }
        .rm-section-nav .nav-link:hover,
        .rm-section-nav .nav-link.active {
            border-color: rgba(37, 99, 235, .22) !important;
            background: #2563eb !important;
            color: #fff !important;
            box-shadow: 0 14px 28px rgba(37, 99, 235, .22) !important;
            transform: translateY(-1px);
        }
        .rm-hero {
            position: relative;
            overflow: hidden;
            border: 0 !important;
            border-radius: 12px !important;
            background: linear-gradient(135deg, #1f4f7e, #244a75), #244a75 !important;
            box-shadow: 0 24px 58px rgba(37, 99, 235, .20) !important;
        }
        .rm-hero::after {
            content: "";
            position: absolute;
            right: -5rem;
            top: -6rem;
            width: 16rem;
            height: 16rem;
            border-radius: 50%;
            background: rgba(255,255,255,.14);
            pointer-events: none;
        }
        .rm-hero .card-body,
        .rm-hero h1,
        .rm-hero-retention { position: relative; z-index: 1; }
        .rm-hero-retention {
            border-radius: 999px !important;
            font-weight: 900 !important;
            background: rgba(255,255,255,.16) !important;
        }
        .nova-card,
        .card:not(.rm-hero),
        .rm-work-panel,
        .rm-panel,
        .rm-stats-panel,
        .rm-api-card,
        .rm-api-hero-card,
        .rm-interactive-chart,
        .rm-option-panel,
        .rm-option-card,
        .rm-catalog-panel,
        .rm-role-permission-item,
        .rm-scope-card {
            border: 1px solid rgba(215, 226, 239, .92) !important;
            border-radius: 12px !important;
            background: rgba(255,255,255,.94) !important;
            box-shadow: 0 12px 30px rgba(15, 23, 42, .08) !important;
        }
        .nova-card:hover,
        .card:hover,
        .rm-work-panel:hover,
        .rm-filter-card:hover,
        .rm-interactive-chart:hover,
        .rm-option-panel:hover { box-shadow: 0 24px 56px rgba(15, 23, 42, .13) !important; }
        .rm-stat-card {
            min-height: 126px !important;
            background: linear-gradient(180deg, rgba(255,255,255,.97), rgba(248,250,255,.9)) !important;
        }
        .rm-filter-card.active {
            border-color: rgba(37, 99, 235, .28) !important;
            box-shadow: 0 26px 54px rgba(37, 99, 235, .16) !important;
            transform: translateY(-2px);
        }
        .rm-stat-icon {
            width: 72px !important;
            height: 72px !important;
            border-radius: 22px !important;
            box-shadow: 0 14px 28px rgba(15, 23, 42, .14) !important;
        }
        .rm-stat-icon.is-pending { background: linear-gradient(135deg, #f59e0b, #f97316) !important; }
        .rm-stat-icon.is-success { background: linear-gradient(135deg, #10b981, #22c55e) !important; }
        .rm-stat-icon.is-danger { background: linear-gradient(135deg, #ef4444, #fb7185) !important; }
        .rm-section-head h2,
        .rm-stats-panel-head h3,
        .rm-api-card-head h3 { color: #0f172a !important; font-weight: 900 !important; }
        .rm-section-head p,
        .rm-stats-panel-head p { color: #526071 !important; font-weight: 600 !important; }
        .rm-table-wrap { border-radius: 12px; overflow: hidden; }
        .rm-table-wrap .table thead th,
        .rm-api-top-table thead th {
            background: #eef4ff !important;
            color: #1e3a8a !important;
            font-size: .78rem !important;
            font-weight: 900 !important;
            letter-spacing: 0 !important;
            text-transform: uppercase;
        }
        .table > :not(caption) > * > *,
        .rm-api-top-table td { border-bottom-color: rgba(215, 226, 239, .95) !important; }
        .table tbody tr:hover > * { background: #f8fbff !important; }
        .btn,
        button.btn,
        a.btn {
            min-height: 40px;
            border-radius: 8px !important;
            font-weight: 900 !important;
            box-shadow: 0 10px 22px rgba(15, 23, 42, .06);
            transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease, border-color .18s ease;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(15, 23, 42, .12);
        }
        .btn-primary { border-color: transparent !important; background: linear-gradient(135deg, #2563eb, #1d4ed8) !important; color: #fff !important; }
        .btn-success { border-color: transparent !important; background: linear-gradient(135deg, #16a34a, #22c55e) !important; color: #fff !important; }
        .btn-warning { border-color: transparent !important; background: linear-gradient(135deg, #f59e0b, #facc15) !important; color: #553600 !important; }
        .btn-danger { border-color: transparent !important; background: linear-gradient(135deg, #dc2626, #fb7185) !important; color: #fff !important; }
        .btn-outline-secondary,
        .btn-secondary {
            background: #fff !important;
            border-color: #d7e2ef !important;
            color: #334155 !important;
        }
        .form-control,
        .form-select,
        textarea,
        select {
            border-radius: 8px !important;
            border-color: #cbd8e8 !important;
            background-color: #fff !important;
        }
        .form-control:focus,
        .form-select:focus,
        .form-check-input:focus {
            border-color: #2563eb !important;
            box-shadow: 0 0 0 .22rem rgba(37, 99, 235, .22) !important;
        }
        .form-check-input:checked {
            border-color: #2563eb !important;
            background-color: #2563eb !important;
        }
        .nova-badge,
        .badge { border-radius: 999px !important; font-weight: 900 !important; }
        .modal-content,
        .rm-redmine-send-modal,
        .rm-webhook-test-modal {
            border-radius: 12px !important;
            border: 1px solid rgba(215, 226, 239, .95) !important;
            box-shadow: 0 28px 70px rgba(15, 23, 42, .22) !important;
        }
        .modal-header {
            background: linear-gradient(135deg, rgba(37, 99, 235, .10), rgba(20, 184, 166, .08)), #fff !important;
        }
        #editar-solicitud {
            --drawer-width: min(960px, 92vw);
        }
        #editar-solicitud .detail-drawer-dialog {
            width: var(--drawer-width);
            max-width: var(--drawer-width);
            min-height: 100vh;
            margin: 0 0 0 auto;
        }
        #editar-solicitud.fade .detail-drawer-dialog {
            transform: translateX(100%);
            transition: transform .28s cubic-bezier(.22, 1, .36, 1);
        }
        #editar-solicitud.show .detail-drawer-dialog {
            transform: translateX(0);
        }
        #editar-solicitud .modal-content {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            max-height: 100vh;
            border: 0 !important;
            border-radius: 18px 0 0 18px !important;
            box-shadow: -24px 0 64px rgba(15, 23, 42, .2) !important;
            overflow: hidden;
        }
        #editar-solicitud .modal-content > form {
            display: flex;
            flex: 1 1 auto;
            min-height: 0;
            flex-direction: column;
        }
        #editar-solicitud .modal-header {
            align-items: flex-start;
            padding: 1.15rem 1.25rem;
            background: linear-gradient(135deg, rgba(37, 99, 235, .12), rgba(20, 184, 166, .1)), #fff !important;
            border-bottom: 1px solid rgba(15, 23, 42, .08);
        }
        #editar-solicitud .modal-title {
            display: flex;
            align-items: center;
            gap: .6rem;
            margin: 0;
            color: #0f172a;
            font-size: 1.12rem;
            font-weight: 900;
        }
        .detail-drawer-kicker {
            margin: 0 0 .25rem;
            color: #2563eb;
            font-size: .76rem;
            font-weight: 900;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .detail-drawer-subtitle {
            margin: .25rem 0 0;
            color: #64748b;
            font-size: .88rem;
            font-weight: 700;
        }
        .detail-drawer-icon {
            display: inline-flex;
            width: 36px;
            height: 36px;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            color: #fff;
            background: linear-gradient(135deg, #2563eb, #14b8a6);
            box-shadow: 0 12px 24px rgba(37, 99, 235, .24);
        }
        #editar-solicitud .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding: 1.2rem 1.25rem 2rem;
            background: #f8fafc;
        }
        #editar-solicitud .modal-body .row {
            padding: 1rem;
            border: 1px solid rgba(148, 163, 184, .24);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .06);
        }
        #editar-solicitud .form-control,
        #editar-solicitud .form-select {
            min-height: 44px;
        }
        #editar-solicitud textarea.form-control {
            min-height: 96px;
            line-height: 1.45;
            resize: vertical;
        }
        #editar-solicitud .modal-footer {
            flex-shrink: 0;
            gap: .65rem;
            padding: 1rem 1.25rem;
            background: rgba(255, 255, 255, .96);
            border-top: 1px solid rgba(15, 23, 42, .08);
            box-shadow: 0 -16px 30px rgba(15, 23, 42, .06);
            z-index: 2;
        }
        #editar-solicitud .modal-footer .btn {
            min-width: 142px;
        }
        .detail-drawer-view {
            display: none;
        }
        .detail-drawer-view.is-active {
            display: block;
        }
        .detail-drawer-modal {
            --drawer-width: min(960px, 92vw);
        }
        .detail-drawer-modal .detail-drawer-dialog {
            width: var(--drawer-width);
            max-width: var(--drawer-width);
            min-height: 100vh;
            margin: 0 0 0 auto;
        }
        .detail-drawer-modal.fade .detail-drawer-dialog {
            transform: translateX(100%);
            transition: transform .28s cubic-bezier(.22, 1, .36, 1);
        }
        .detail-drawer-modal.show .detail-drawer-dialog {
            transform: translateX(0);
        }
        .detail-drawer-modal .modal-content {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            max-height: 100vh;
            border: 0 !important;
            border-radius: 18px 0 0 18px !important;
            box-shadow: -24px 0 64px rgba(15, 23, 42, .2) !important;
            overflow: hidden;
        }
        .detail-drawer-modal .modal-content > form {
            display: flex;
            flex: 1 1 auto;
            min-height: 0;
            flex-direction: column;
        }
        .detail-drawer-modal .modal-header {
            align-items: flex-start;
            padding: 1.15rem 1.25rem;
            background: linear-gradient(135deg, rgba(37, 99, 235, .12), rgba(20, 184, 166, .1)), #fff !important;
            border-bottom: 1px solid rgba(15, 23, 42, .08);
        }
        .detail-drawer-modal .modal-title {
            display: flex;
            align-items: center;
            gap: .6rem;
            margin: 0;
            color: #0f172a;
            font-size: 1.12rem;
            font-weight: 900;
        }
        .detail-drawer-modal .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            padding: 1.2rem 1.25rem 2rem;
            background: #f8fafc;
        }
        .detail-drawer-modal .modal-body > .row,
        .detail-drawer-panel {
            padding: 1rem;
            border: 1px solid rgba(148, 163, 184, .24);
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .06);
        }
        .detail-drawer-modal .form-control,
        .detail-drawer-modal .form-select {
            min-height: 44px;
        }
        .detail-drawer-modal textarea.form-control {
            min-height: 96px;
            line-height: 1.45;
            resize: vertical;
        }
        .detail-drawer-modal .modal-footer {
            flex-shrink: 0;
            gap: .65rem;
            padding: 1rem 1.25rem;
            background: rgba(255, 255, 255, .96);
            border-top: 1px solid rgba(15, 23, 42, .08);
            box-shadow: 0 -16px 30px rgba(15, 23, 42, .06);
            z-index: 2;
        }
        .detail-drawer-modal .modal-footer .btn {
            min-width: 142px;
        }
        @media (max-width: 575.98px) {
            #editar-solicitud {
                --drawer-width: 100vw;
            }
            .detail-drawer-modal {
                --drawer-width: 100vw;
            }
            #editar-solicitud .modal-content {
                border-radius: 0 !important;
            }
            .detail-drawer-modal .modal-content {
                border-radius: 0 !important;
            }
            #editar-solicitud .modal-footer .btn {
                width: 100%;
            }
            .detail-drawer-modal .modal-footer .btn {
                width: 100%;
            }
        }
        .rm-log {
            border: 1px solid #22c55e !important;
            background: #020617 !important;
            color: #4ade80 !important;
            font-family: "JetBrains Mono", "Consolas", monospace !important;
            box-shadow: inset 0 0 0 1px rgba(34, 197, 94, .16), 0 18px 38px rgba(2, 6, 23, .18);
        }
        @media (max-width: 760px) {
            .rm-navbar { border-radius: 0 0 16px 16px !important; }
            .rm-stat-icon { width: 58px !important; height: 58px !important; }
        }
        .card.card-hero.sb-page-hero.rm-hero {
            color: #fff !important;
            background: linear-gradient(135deg, #1f4f7e, #244a75), #244a75 !important;
            border: 0 !important;
        }
        .card.card-hero.sb-page-hero.rm-hero .rm-page-title,
        .card.card-hero.sb-page-hero.rm-hero .rm-hero-retention {
            color: #fff !important;
        }
    </style>
</head>
<body class="nova-page {{ !empty($redmineMaintenance['enabled']) ? 'rm-maintenance-active' : '' }}">
    <div class="rm-shell">
        <nav class="navbar navbar-expand-lg navbar-dark rm-navbar">
            <div class="container-fluid px-4">
                <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="{{ $redmineRoute('redmine.dashboard') }}">
                    <span class="rm-brand-mark"><i class="bi bi-layout-sidebar-inset"></i></span>
                    <span>{{ $redmineProjectName ?? 'Redmine TICS' }}</span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#rmTopbar" aria-controls="rmTopbar" aria-expanded="false" aria-label="Alternar navegacion">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="rmTopbar">
                    <div class="rm-top-actions mt-3 mt-lg-0">
                        @include('nova.partials.session-control')
                        <span class="text-white-50 fw-bold"><i class="bi bi-person-circle"></i> {{ session('nova_user.name') }}</span>
                        <a class="btn btn-outline-light" href="{{ route('home') }}"><i class="bi bi-house-door"></i>NOVA</a>
                        <a class="btn btn-outline-light" href="{{ route('logout') }}"><i class="bi bi-box-arrow-right"></i>Salir</a>
                    </div>
                </div>
            </div>
        </nav>

        <div class="rm-layout">
            <main class="rm-main">
                <nav class="rm-section-nav" aria-label="Secciones Redmine">
                    @foreach ($sections as $key => $label)
                        <a class="nav-link {{ $section === $key ? 'active' : '' }}" href="{{ $redmineRoute('redmine.native.section', $key) }}">
                            <i class="bi {{ $sectionIcons[$key] ?? 'bi-window' }}"></i>
                            {{ $label }}
                        </a>
                    @endforeach
                </nav>

                <section class="card card-hero sb-page-hero rm-hero mb-3">
                    <div class="card-body p-3 p-lg-4 d-flex align-items-center gap-3 flex-wrap">
                        <div class="d-flex align-items-center gap-3">
                            <span class="rm-hero-icon"><i class="bi bi-speedometer2"></i></span>
                            <div>
                                <h1 class="rm-page-title text-white">{{ $sectionLabel }}</h1>
                            </div>
                        </div>
                        <span class="rm-hero-retention"><i class="bi bi-archive"></i>Retencion procesados: {{ $redmineRetentionHours ?? 24 }} hora(s)</span>
                    </div>
                </section>

                @if (!empty($redmineMaintenance['enabled']))
                    <div class="alert alert-warning fw-bold" role="status">
                        <i class="bi bi-tools"></i>
                        Modulo en mantencion{{ !empty($redmineMaintenance['until_text']) ? ' hasta ' . $redmineMaintenance['until_text'] : '' }}. La edicion de datos esta desactivada.
                    </div>
                @endif

                @if ($section === 'dashboard')
                    @include('redmine_tic::native-sections.dashboard')
                @elseif ($section === 'usuarios')
                    @include('redmine_tic::native-sections.users')
                @elseif ($section === 'configuracion')
                    @include('redmine_tic::native-sections.config')
                @elseif ($section === 'horas-extra')
                    @include('redmine_tic::native-sections.hours')
                @elseif ($section === 'historico')
                    @include('redmine_tic::native-sections.history')
                @elseif ($section === 'estadisticas' || $section === 'estadisticas-api')
                    @include('redmine_tic::native-sections.stats')
                @elseif ($section === 'actividad')
                    @include('redmine_tic::native-sections.activity')
                @else
                    @include('redmine_tic::native-sections.webhook')
                @endif
            </main>
        </div>
    </div>
    @if (session('redmine_status'))
        @php
            $redmineStatusText = (string) session('redmine_status');
            $redmineStatusType = (string) session('redmine_status_type', '');
            if (!in_array($redmineStatusType, ['success', 'info', 'danger'], true)) {
                $lowerStatus = Str::lower($redmineStatusText);
                $redmineStatusType = Str::contains($lowerStatus, ['error', 'no se', 'no pudo', 'falta', 'http ', 'bloque', 'desactivada'])
                    ? 'danger'
                    : (Str::contains($lowerStatus, ['sin cambios', 'solo se sincronizan', 'selecciona']) ? 'info' : 'success');
            }
            $redmineStatusIcon = $redmineStatusType === 'danger' ? 'bi-x-circle-fill' : ($redmineStatusType === 'info' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill');
        @endphp
        <div class="rm-toast is-{{ $redmineStatusType }}" role="status" aria-live="polite" data-redmine-toast>
            <i class="bi {{ $redmineStatusIcon }}"></i>
            <span>{{ session('redmine_status') }}</span>
        </div>
    @endif

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const closeNovaModal = (modal) => {
            if (!modal) return;
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            modal.removeAttribute('aria-modal');
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
        };

        document.querySelectorAll('[data-nova-modal-close]').forEach((trigger) => {
            trigger.addEventListener('click', () => closeNovaModal(trigger.closest('.modal')));
        });

        document.querySelectorAll('.modal').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (modal.dataset.novaSessionModal === '') return;
                if (event.target === modal) closeNovaModal(modal);
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            document.querySelectorAll('.modal.show:not([data-nova-session-modal])').forEach(closeNovaModal);
        });

        if (document.body.classList.contains('rm-maintenance-active')) {
            document.querySelectorAll('form').forEach((form) => {
                if (form.dataset.maintenanceAllowed === '1') return;
                if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
                form.querySelectorAll('input, select, textarea, button').forEach((control) => {
                    if (control.matches('[data-nova-modal-close], [data-bs-dismiss="modal"]')) return;
                    control.disabled = true;
                    control.title = 'Modulo en mantencion';
                });
            });
            document.querySelectorAll('[data-nova-modal-open]').forEach((button) => {
                button.disabled = true;
                button.title = 'Modulo en mantencion';
            });
        }

        const redmineToast = document.querySelector('[data-redmine-toast]');
        if (redmineToast) {
            window.setTimeout(() => {
                redmineToast.classList.add('is-hiding');
                window.setTimeout(() => redmineToast.remove(), 220);
            }, 3000);
        }

    </script>
</body>
</html>
