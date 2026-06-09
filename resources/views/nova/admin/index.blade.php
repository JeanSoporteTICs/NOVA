<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Administracion - NOVA</title>
    @include('nova.partials.favicon')
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
    <style>
        body { margin: 0; min-height: 100vh; background: #eef3fb; color: #0f172a; }
        .rm-shell { min-height: 100vh; }
        .rm-navbar { min-height: 68px; background: linear-gradient(115deg, #1f2f56 0%, #314ed8 62%, #4966ff 100%); box-shadow: 0 16px 36px rgba(31, 47, 86, .22); }
        .rm-brand-mark { display: inline-grid; width: 42px; height: 42px; place-items: center; border-radius: 12px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.24); color: #fff; }
        .rm-top-actions { margin-left: auto; display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .rm-layout { width: min(1760px, 100%); margin: 0 auto; padding: 20px 24px 44px; }
        .rm-main { min-width: 0; }
        .admin-nav-shell { position: sticky; top: 0; z-index: 20; margin: -2px -4px 16px; padding: 4px; background: rgba(238, 243, 251, .88); backdrop-filter: blur(10px); }
        .admin-section-nav { display: flex; gap: 8px; overflow-x: auto; padding: 4px 2px 8px; scrollbar-width: thin; }
        .admin-section-nav .nav-link { display: inline-flex; align-items: center; gap: 8px; flex: 0 0 auto; min-height: 42px; border: 1px solid #e2e8f0; border-radius: 999px; padding: 8px 13px; background: #fff; color: #334155; font-weight: 850; box-shadow: 0 8px 20px rgba(15,23,42,.04); }
        .admin-section-nav .nav-link:hover { border-color: #bfdbfe; color: #1d4ed8; transform: translateY(-1px); }
        .admin-section-nav .nav-link.active { background: #1d4ed8; border-color: #1d4ed8; color: #fff; box-shadow: 0 14px 30px rgba(37, 99, 235, .22); }
        .admin-section-nav .nav-link span { white-space: nowrap; }
        .rm-hero { border: 0; border-radius: 18px; color: #fff; background: linear-gradient(130deg, #2563eb 0%, #0891b2 58%, #059669 100%); box-shadow: 0 18px 34px rgba(49, 91, 170, .14); overflow: hidden; }
        .rm-hero-icon { display: grid; width: 46px; height: 46px; place-items: center; flex: 0 0 auto; border-radius: 14px; background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28); font-size: 1.25rem; }
        .rm-page-title { margin: 0; color: #fff; font-size: clamp(1.55rem, 3vw, 2.25rem); font-weight: 800; }
        .rm-page-subtitle { margin: 4px 0 0; color: rgba(255,255,255,.84); font-size: .94rem; font-weight: 750; }
        .rm-hero-retention { display: inline-flex; align-items: center; gap: 7px; margin-left: auto; min-height: 36px; padding: 7px 11px; border-radius: 999px; border: 1px solid rgba(255,255,255,.35); background: rgba(255,255,255,.14); color: #fff; font-size: .86rem; font-weight: 900; white-space: nowrap; }
        .rm-work-panel { border-radius: 14px; overflow: hidden; }
        .rm-panel { padding: 16px; }
        .nova-card, .platform-card, .control-card { border-color: #dbe4f0; box-shadow: 0 12px 28px rgba(15, 23, 42, .05); }
        .rm-table-wrap .table thead th { background: #eaf8fd; color: #435061; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }
        .rm-table-wrap .table tbody tr { transition: background .14s ease; }
        .rm-table-wrap .table tbody tr:hover { background: #f8fafc; }
        .rm-section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .rm-section-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; }
        .rm-section-head p { margin: 4px 0 0; color: var(--nova-muted); }
        .admin-empty-row { padding: 18px; color: #64748b; font-weight: 800; }
        .user-grid { display: block; }
        .form-panel { display: flex; flex-direction: column; max-height: calc(100vh - 40px); }
        .form-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .form-title h2 { margin: 0; font-size: 1.08rem; font-weight: 900; color: #0f172a; }
        .form-section { display: grid; gap: 12px; margin-bottom: 14px; }
        .form-section.is-two { grid-template-columns: 1fr 1fr; }
        .form-section-title { margin: 16px 0 10px; color: #64748b; font-size: .78rem; font-weight: 900; text-transform: uppercase; letter-spacing: .04em; }
        .config-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .config-grid .is-wide { grid-column: 1 / -1; }
        .config-status { display: inline-flex; align-items: center; gap: 7px; min-height: 30px; padding: 4px 9px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: .78rem; font-weight: 900; }
        .config-status.is-ok { background: #dcfce7; color: #166534; }
        .config-status.is-warn { background: #fef3c7; color: #92400e; }
        .config-code { display: grid; gap: 6px; margin-top: 12px; padding: 12px; border-radius: 10px; background: #0f172a; color: #86efac; font-size: .82rem; font-weight: 800; word-break: break-word; }
        .config-code code { white-space: pre-wrap; }
        .platform-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
        .platform-card { padding: 16px; border: 1px solid #dbe4f0; border-radius: 14px; background: #fff; }
        .platform-card h3 { margin: 0; font-size: 1rem; font-weight: 900; color: #0f172a; }
        .platform-metric { margin-top: 10px; font-size: 1.85rem; font-weight: 950; color: #1d4ed8; }
        .control-grid { display: grid; grid-template-columns: repeat(4, minmax(190px, 1fr)); gap: 14px; margin-bottom: 16px; }
        .control-card { display: grid; gap: 8px; min-height: 132px; padding: 16px; border: 1px solid #dbe4f0; border-radius: 14px; background: #fff; box-shadow: 0 10px 26px rgba(15, 23, 42, .04); }
        .control-card:hover, .platform-card:hover { transform: translateY(-1px); box-shadow: 0 16px 34px rgba(15, 23, 42, .07); }
        .control-card h3 { margin: 0; color: #334155; font-size: .82rem; font-weight: 950; text-transform: uppercase; letter-spacing: .04em; }
        .control-card strong { color: #0f172a; font-size: 1.8rem; line-height: 1; font-weight: 950; }
        .control-card span { color: #64748b; font-size: .85rem; font-weight: 800; }
        .control-card i { color: #2563eb; font-size: 1.2rem; }
        .control-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .command-list { display: grid; gap: 10px; }
        .command-row { display: grid; grid-template-columns: 160px 1fr; gap: 12px; align-items: center; padding: 12px; border: 1px solid #dbe4f0; border-radius: 12px; background: #fff; }
        .command-row code { color: #1d4ed8; font-weight: 950; }
        .command-alias { display: inline-flex; min-height: 22px; align-items: center; margin-right: 4px; padding: 2px 7px; border-radius: 999px; background: #f1f5f9; color: #64748b; font-size: .72rem; font-weight: 900; }
        .health-dot { display: inline-flex; align-items: center; gap: 7px; min-height: 26px; padding: 3px 8px; border-radius: 999px; font-size: .75rem; font-weight: 900; }
        .health-dot.is-ok { background: #dcfce7; color: #166534; }
        .health-dot.is-warn { background: #fef3c7; color: #92400e; }
        .health-dot.is-error { background: #fee2e2; color: #991b1b; }
        .telegram-admin-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(420px, .72fr); gap: 16px; align-items: start; }
        .telegram-listener-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .telegram-listener-metric { display: flex; align-items: center; gap: 12px; min-height: 76px; padding: 12px; border: 1px solid #d7e2ef; border-radius: 12px; background: #f8fafc; }
        .telegram-listener-metric i { display: grid; width: 40px; height: 40px; place-items: center; flex: 0 0 auto; border-radius: 12px; background: #e0f2fe; color: #0369a1; }
        .telegram-listener-metric strong { display: block; color: #111827; font-size: 1rem; line-height: 1.1; }
        .telegram-listener-metric span { color: #64748b; font-size: .74rem; font-weight: 900; text-transform: uppercase; }
        .telegram-listener-metric.is-ok i { background: #dcfce7; color: #15803d; }
        .telegram-listener-metric.is-warn i { background: #fef3c7; color: #b45309; }
        .telegram-listener-metric.is-bad i { background: #fee2e2; color: #dc2626; }
        .telegram-listener-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .telegram-log-tail { min-height: 150px; max-height: 260px; overflow: auto; padding: 12px; border-radius: 12px; background: #020617; color: #86efac; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .82rem; white-space: pre-wrap; }
        .telegram-message-grid { display: grid; gap: 16px; align-items: start; }
        .telegram-message-editor-layout { display: grid; grid-template-columns: minmax(260px, 340px) minmax(0, 1fr); gap: 16px; align-items: start; }
        .telegram-message-picker { display: grid; gap: 8px; padding: 10px; border: 1px solid #dbe4f0; border-radius: 14px; background: #f8fafc; }
        .telegram-message-picker-title { margin: 0 0 2px; color: #0f172a; font-size: .9rem; font-weight: 950; }
        .telegram-message-option { width: 100%; display: grid; gap: 4px; padding: 10px 11px; border: 1px solid transparent; border-radius: 10px; background: transparent; color: #334155; text-align: left; }
        .telegram-message-option:hover { background: #fff; border-color: #dbe4f0; }
        .telegram-message-option.is-active { background: #dbeafe; border-color: #93c5fd; color: #1d4ed8; }
        .telegram-message-option strong { display: flex; align-items: center; gap: 7px; font-size: .86rem; font-weight: 950; }
        .telegram-message-option span { color: #64748b; font-size: .74rem; font-weight: 800; }
        .telegram-message-option code { color: inherit; font-size: .78rem; font-weight: 950; }
        .telegram-message-editor { display: none; padding: 16px; border: 1px solid #dbe4f0; border-radius: 14px; background: #fff; }
        .telegram-message-editor.is-active { display: block; }
        .telegram-message-editor-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .telegram-message-editor-head h3 { margin: 0; color: #0f172a; font-size: 1.05rem; font-weight: 950; }
        .telegram-message-editor-head p { margin: 4px 0 0; color: #64748b; font-size: .84rem; font-weight: 750; }
        .telegram-message-command { display: inline-flex; min-height: 30px; align-items: center; padding: 3px 9px; border-radius: 999px; background: #dbeafe; color: #1d4ed8; font-size: .9rem; font-weight: 950; }
        .telegram-message-editor label { color: #334155; font-size: .78rem; font-weight: 950; text-transform: uppercase; letter-spacing: .03em; }
        .telegram-message-editor textarea { min-height: 260px; margin-top: 7px; font-weight: 750; resize: vertical; }
        .telegram-command-message-toggle { display: inline-flex; align-items: center; gap: 9px; color: #334155; font-size: .82rem; font-weight: 950; }
        .telegram-command-message-toggle .form-check-input { width: 2.4rem; height: 1.25rem; margin: 0; }
        .telegram-edit-help { margin: 8px 0 0; color: #64748b; font-size: .8rem; font-weight: 750; }
        .telegram-placeholder-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .telegram-placeholder-list code { display: inline-flex; min-height: 23px; align-items: center; padding: 2px 7px; border-radius: 999px; background: #e0f2fe; color: #075985; font-size: .72rem; font-weight: 950; }
        .telegram-placeholder-list span { align-self: center; color: #64748b; font-size: .74rem; font-weight: 900; }
        .telegram-save-bar { position: sticky; bottom: 12px; z-index: 4; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 16px; padding: 12px 14px; border: 1px solid #bfdbfe; border-radius: 14px; background: rgba(239, 246, 255, .96); box-shadow: 0 14px 30px rgba(15, 23, 42, .12); }
        .telegram-save-bar strong { display: block; color: #0f172a; font-size: .9rem; font-weight: 950; }
        .telegram-save-bar span { color: #475569; font-size: .8rem; font-weight: 800; }
        .telegram-command-toggle { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; padding: 12px; border: 1px solid #dbe4f0; border-radius: 12px; background: #fff; }
        .telegram-command-toggle code { color: #1d4ed8; font-weight: 950; }
        .telegram-command-toggle .form-check-input { width: 2.4rem; height: 1.25rem; margin: 0; }
        .emach-time-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .emach-time-card { padding: 14px; border: 1px solid #dbe4f0; border-radius: 14px; background: #f8fafc; }
        .emach-time-card h3 { margin: 0 0 10px; color: #0f172a; font-size: .98rem; font-weight: 900; }
        .emach-time-card .form-label { color: #334155; font-size: .78rem; font-weight: 900; }
        .emach-interval-preview { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .emach-interval-preview span { display: inline-flex; align-items: center; gap: 6px; min-height: 28px; padding: 4px 9px; border-radius: 999px; background: #e0f2fe; color: #075985; font-size: .78rem; font-weight: 900; }
        .field { margin-bottom: 12px; }
        .field label { display: block; margin-bottom: 6px; color: #334155; font-size: .86rem; font-weight: 800; }
        .field-help { display: none; margin-top: 6px; color: #b91c1c; font-size: .78rem; font-weight: 800; }
        .form-control.is-invalid + .field-help { display: block; }
        .table td, .table th { vertical-align: middle; }
        .table-panel-head { display: grid; grid-template-columns: minmax(190px, 1fr) minmax(360px, 720px) auto; align-items: center; gap: 14px; padding: 16px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .table-panel-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; }
        .user-filters { display: grid; grid-template-columns: minmax(260px, 1fr) 150px 150px 42px; gap: 10px; align-items: center; justify-content: end; }
        .user-search { width: 100%; position: relative; }
        .user-search i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #64748b; }
        .user-search input { padding-left: 38px; }
        .column-filter { width: 100%; }
        .user-primary-action { justify-self: end; }
        .access-panel-head { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(280px, 460px) auto; align-items: center; gap: 14px; padding: 16px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .access-panel-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; }
        .access-help { margin: 4px 0 0; color: var(--nova-muted); font-size: .84rem; font-weight: 700; }
        .access-tools { display: grid; grid-template-columns: minmax(240px, 1fr); gap: 10px; align-items: center; }
        .access-list { display: grid; gap: 14px; padding: 16px; }
        .access-user-panel { display: none; gap: 14px; }
        .access-user-panel.is-active { display: grid; }
        .access-user-summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px; border: 1px solid #dbe4f0; border-radius: 14px; background: #f8fafc; }
        .access-user-summary h3 { margin: 0; color: #0f172a; font-size: 1rem; font-weight: 900; }
        .access-module-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
        .access-view-card { display: grid; gap: 14px; padding: 16px; border: 1px solid #dbe4f0; border-radius: 14px; background: #fff; box-shadow: 0 10px 26px rgba(15, 23, 42, .04); }
        .access-view-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
        .access-view-title { margin: 0; font-size: 1rem; font-weight: 900; color: #0f172a; }
        .access-view-meta { margin-top: 3px; color: #64748b; font-size: .8rem; font-weight: 700; }
        .access-user-option { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; padding: 0; border-radius: 10px; cursor: pointer; }
        .access-user-option:hover { background: #f1f5f9; }
        .access-user-option .form-check-input { margin: 0; width: 1.05rem; height: 1.05rem; }
        .access-user-name { color: #0f172a; font-size: .86rem; font-weight: 900; }
        .access-user-meta { color: #64748b; font-size: .74rem; font-weight: 700; }
        .access-source { display: inline-flex; min-height: 20px; align-items: center; padding: 2px 6px; border-radius: 999px; background: #f1f5f9; color: #64748b; font-size: .68rem; font-weight: 900; }
        .access-source.is-default { background: #dcfce7; color: #166534; }
        .access-source.is-manual { background: #dbeafe; color: #1d4ed8; }
        .emach-credential-badge { display: inline-flex; align-items: center; gap: 6px; min-height: 24px; padding: 3px 8px; border-radius: 999px; background: #e0f2fe; color: #075985; font-size: .72rem; font-weight: 900; white-space: nowrap; }
        .emach-credential-badge.is-missing { background: #f1f5f9; color: #64748b; }
        .telegram-user-badge { display: inline-flex; align-items: center; gap: 6px; min-height: 24px; padding: 3px 8px; border-radius: 999px; background: #dcfce7; color: #166534; font-size: .72rem; font-weight: 900; white-space: nowrap; }
        .telegram-user-badge.is-missing { background: #f1f5f9; color: #64748b; }
        .row-actions { display: flex; gap: 7px; justify-content: flex-end; }
        .nova-toast-stack { position: fixed; right: 22px; bottom: 22px; z-index: 1080; display: grid; gap: 10px; width: min(380px, calc(100vw - 32px)); }
        .nova-toast { border-radius: 16px; border: 1px solid #cbd5e1; background: #fff; box-shadow: 0 20px 50px rgba(15, 23, 42, .18); padding: 14px 16px; display: flex; align-items: flex-start; gap: 10px; font-weight: 800; color: #0f172a; animation: toastIn .18s ease-out; }
        .nova-toast.is-success { border-color: #bbf7d0; background: #f0fdf4; color: #166534; }
        .nova-toast.is-danger { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
        .nova-modal-backdrop { position: fixed; inset: 0; z-index: 1070; display: none; align-items: center; justify-content: center; padding: 18px; background: rgba(15, 23, 42, .54); }
        .nova-modal-backdrop.is-open { display: flex; }
        .nova-confirm { width: min(460px, 100%); border-radius: 18px; border: 0; background: #fff; box-shadow: 0 28px 70px rgba(15, 23, 42, .28); overflow: hidden; }
        .nova-user-form { width: min(760px, 100%); overflow: hidden; }
        .nova-user-form__body { overflow: auto; padding: 22px; }
        .nova-user-form__footer { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 18px; background: #f8fafc; border-top: 1px solid #e2e8f0; }
        .modal-close { border: 0; background: transparent; color: #64748b; font-size: 1.45rem; line-height: 1; padding: 0; }
        .nova-confirm__body { padding: 22px; }
        .nova-confirm__body h2 { margin: 0 0 8px; font-size: 1.1rem; font-weight: 900; }
        .nova-confirm__body p { margin: 0; color: #475569; }
        .nova-confirm__actions { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 18px; background: #f8fafc; border-top: 1px solid #e2e8f0; }
        @keyframes toastIn { from { transform: translateY(10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @media (max-width: 900px) {
            .rm-layout { padding: 14px 12px 32px; }
            .admin-nav-shell { margin-left: -8px; margin-right: -8px; }
            .user-grid { grid-template-columns: 1fr; }
            .control-grid { grid-template-columns: 1fr; }
            .command-row { grid-template-columns: 1fr; }
            .config-grid { grid-template-columns: 1fr; }
            .telegram-admin-grid { grid-template-columns: 1fr; }
            .telegram-message-grid { grid-template-columns: 1fr; }
            .telegram-message-editor-layout { grid-template-columns: 1fr; }
            .telegram-listener-grid { grid-template-columns: 1fr; }
            .emach-time-grid { grid-template-columns: 1fr; }
            .form-section.is-two { grid-template-columns: 1fr; }
            .table-panel-head { grid-template-columns: 1fr; align-items: stretch; }
            .user-filters { grid-template-columns: 1fr; }
            .user-search, .column-filter, .user-filters { width: 100%; }
            .user-primary-action { justify-self: stretch; }
            .access-panel-head { grid-template-columns: 1fr; align-items: stretch; }
            .access-tools { grid-template-columns: 1fr; }
            .access-list { grid-template-columns: 1fr; }
            .access-user-summary { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body class="nova-page">
    <div class="rm-shell">
        <nav class="navbar navbar-expand-lg navbar-dark rm-navbar">
            <div class="container-fluid px-4">
                <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="{{ route('administracion.index') }}">
                    <span class="rm-brand-mark"><i class="bi bi-person-gear"></i></span>
                    <span>Administracion</span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#novaUsersTopbar" aria-controls="novaUsersTopbar" aria-expanded="false" aria-label="Alternar navegacion">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="novaUsersTopbar">
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
                @php
                    $adminSections = [
                        'centro' => ['label' => 'Centro', 'icon' => 'bi-speedometer2', 'description' => 'Resumen rapido de usuarios, salud, accesos y Telegram.'],
                        'configuracion' => ['label' => 'Configuracion', 'icon' => 'bi-sliders', 'description' => 'Ajustes globales de sesion, salud y notificaciones administrativas.'],
                        'plataforma' => ['label' => 'Plataforma', 'icon' => 'bi-diagram-3', 'description' => 'Vista general de usuarios NOVA, fuentes y estado de la plataforma.'],
                        'salud' => ['label' => 'Salud', 'icon' => 'bi-activity', 'description' => 'Chequeos de servicios y dependencias criticas.'],
                        'auditoria' => ['label' => 'Auditoria', 'icon' => 'bi-journal-text', 'description' => 'Eventos recientes y acciones registradas en administracion.'],
                        'respaldos' => ['label' => 'Respaldos', 'icon' => 'bi-archive', 'description' => 'Crea y revisa copias de archivos criticos.'],
                        'telegram' => ['label' => 'Telegram', 'icon' => 'bi-telegram', 'description' => 'Configura el bot global y revisa el estado del servicio.'],
                        'telegram-mensajes' => ['label' => 'Mensajes Telegram', 'icon' => 'bi-chat-square-text', 'description' => 'Edita las respuestas programadas que envia el bot.'],
                        'emach' => ['label' => 'EMACH', 'icon' => 'bi-heart-pulse', 'description' => 'Define frecuencias de consulta y ventanas horarias EMACH.'],
                        'usuarios' => ['label' => 'Usuarios', 'icon' => 'bi-people', 'description' => 'Crea usuarios, actualiza credenciales y administra estados.'],
                        'accesos' => ['label' => 'Accesos', 'icon' => 'bi-shield-lock', 'description' => 'Define a que vistas NOVA puede entrar cada usuario.'],
                    ];
                    $activeAdminSection = $adminSections[$section] ?? $adminSections['centro'];
                @endphp

                <div class="admin-nav-shell">
                    <nav class="admin-section-nav" aria-label="Secciones Administracion">
                        @foreach ($adminSections as $sectionKey => $item)
                            <a class="nav-link {{ $section === $sectionKey ? 'active' : '' }}" href="{{ route('administracion.section', $sectionKey) }}" @if ($section === $sectionKey) aria-current="page" @endif>
                                <i class="bi {{ $item['icon'] }}"></i><span>{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                </div>

                <section class="card rm-hero mb-4">
                    <div class="card-body p-3 p-lg-4 d-flex align-items-center gap-3 flex-wrap">
                        <div class="d-flex align-items-center gap-3">
                            <span class="rm-hero-icon"><i class="bi {{ $activeAdminSection['icon'] }}"></i></span>
                            <div>
                                <h1 class="rm-page-title">{{ $activeAdminSection['label'] }}</h1>
                                <p class="rm-page-subtitle">{{ $activeAdminSection['description'] }}</p>
                            </div>
                        </div>
                        <span class="rm-hero-retention"><i class="bi bi-shield-check"></i>NOVA global</span>
                    </div>
                </section>

                @if ($section === 'centro')
                    @php
                        $activeUsers = collect($users)->where('status', 'activo')->count();
                        $healthOk = collect($healthChecks)->where('status', 'ok')->count();
                        $healthWarn = collect($healthChecks)->where('status', 'warn')->count();
                        $healthError = collect($healthChecks)->where('status', 'error')->count();
                        $projectRows = $accessMatrix['matrix'] ?? [];
                        $queuedMessages = (int) data_get($telegramListener, 'queue.outbox', 0);
                        $failedMessages = (int) data_get($telegramListener, 'queue.failed', 0);
                    @endphp
                    <div class="control-grid">
                        <section class="control-card">
                            <i class="bi bi-people"></i>
                            <h3>Usuarios activos</h3>
                            <strong>{{ $activeUsers }}</strong>
                            <span>{{ count($users) }} usuario(s) centralizados.</span>
                        </section>
                        <section class="control-card">
                            <i class="bi bi-activity"></i>
                            <h3>Salud</h3>
                            <strong>{{ $healthError > 0 ? $healthError : $healthWarn }}</strong>
                            <span>{{ $healthOk }} OK / {{ $healthWarn }} alerta(s) / {{ $healthError }} error(es)</span>
                        </section>
                        <section class="control-card">
                            <i class="bi bi-shield-lock"></i>
                            <h3>Accesos</h3>
                            <strong>{{ count($projectRows) }}</strong>
                            <span>Usuario(s) evaluados en la matriz NOVA.</span>
                        </section>
                        <section class="control-card">
                            <i class="bi bi-telegram"></i>
                            <h3>Telegram</h3>
                            <strong>{{ count($telegramCommands ?? []) }}</strong>
                            <span>{{ $queuedMessages }} por enviar / {{ $failedMessages }} fallido(s).</span>
                        </section>
                    </div>

                    <section class="card nova-card rm-work-panel rm-panel mb-3">
                        <div class="rm-section-head">
                            <div>
                                <h2>Acciones rapidas</h2>
                                <p>Atajos a las tareas administrativas mas usadas.</p>
                            </div>
                        </div>
                        <div class="control-actions">
                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'salud') }}"><i class="bi bi-activity"></i>Ver salud</a>
                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'auditoria') }}"><i class="bi bi-journal-text"></i>Ver auditoria</a>
                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'respaldos') }}"><i class="bi bi-archive"></i>Crear respaldo</a>
                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'telegram') }}"><i class="bi bi-telegram"></i>Configurar Telegram</a>
                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'accesos') }}"><i class="bi bi-shield-lock"></i>Revisar accesos</a>
                        </div>
                    </section>

                    <div class="row g-3">
                        <div class="col-12 col-xl-7">
                            <section class="card nova-card rm-work-panel rm-panel h-100">
                                <div class="rm-section-head">
                                    <div>
                                        <h2>Comandos Telegram</h2>
                                        <p>Comandos que puede usar el bot global.</p>
                                    </div>
                                    <a class="btn btn-sm btn-outline-secondary fw-bold" href="{{ route('administracion.section', 'telegram-mensajes') }}"><i class="bi bi-pencil-square"></i>Editar mensajes</a>
                                </div>
                                <div class="command-list">
                                    @forelse (($telegramCommands ?? []) as $command)
                                        <div class="command-row">
                                            <div>
                                                <code>{{ $command['command'] ?? '' }}</code>
                                                @foreach (($command['aliases'] ?? []) as $alias)
                                                    <span class="command-alias">{{ $alias }}</span>
                                                @endforeach
                                            </div>
                                            <div>
                                                <strong>{{ $command['module'] ?? '-' }}</strong>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="nova-muted fw-semibold">No hay comandos configurados.</div>
                                    @endforelse
                                </div>
                            </section>
                        </div>
                        <div class="col-12 col-xl-5">
                            <section class="card nova-card rm-work-panel rm-panel h-100">
                                <div class="rm-section-head">
                                    <div>
                                        <h2>Auditoria reciente</h2>
                                        <p>Ultimos eventos registrados por NOVA.</p>
                                    </div>
                                    <a class="btn btn-sm btn-outline-secondary fw-bold" href="{{ route('administracion.section', 'auditoria') }}"><i class="bi bi-clock-history"></i>Ver todo</a>
                                </div>
                                <div class="table-responsive rm-table-wrap">
                                    <table class="table mb-0">
                                        <thead><tr><th>Fecha</th><th>Evento</th><th>Usuario</th></tr></thead>
                                        <tbody>
                                            @forelse ($auditItems as $item)
                                                <tr>
                                                    <td>{{ $item['at'] ?? '' }}</td>
                                                    <td><span class="nova-badge">{{ $item['event'] ?? '' }}</span></td>
                                                    <td>{{ $item['user_name'] ?? '-' }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="3">No hay eventos registrados.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </div>
                    </div>
                @endif

                @if ($section === 'configuracion')
                    <div class="config-grid">
                        <section class="card nova-card rm-work-panel rm-panel is-wide">
                            <div class="rm-section-head">
                                <div>
                                    <h2>Configuracion global</h2>
                                    <p>Ajustes transversales que afectan la experiencia de administracion.</p>
                                </div>
                            </div>
                            <form method="post" action="{{ route('administracion.config.update') }}">
                                @csrf
                                <input type="hidden" name="action" value="settings">
                                <div class="row g-3 align-items-end">
                                    <div class="col-12">
                                        <label class="form-label" for="session_timeout">Tiempo de sesion</label>
                                        <input class="form-control" id="session_timeout" name="session_timeout" type="number" min="60" step="1" value="{{ $settings['session_timeout'] ?? 3600 }}">
                                        <div class="form-text fw-semibold">Tiempo en segundos antes de pedir reautenticacion.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="health_warning_threshold">Umbral de alertas salud</label>
                                        <input class="form-control" id="health_warning_threshold" name="health_warning_threshold" type="number" min="1" step="1" value="{{ $settings['health_warning_threshold'] ?? 1 }}">
                                        <div class="form-text fw-semibold">Cantidad de avisos necesarios para marcar alerta.</div>
                                    </div>
                                    <div class="col-md-6 d-flex align-items-end">
                                        <div class="form-check form-switch fw-bold">
                                            <input class="form-check-input" type="checkbox" role="switch" id="notification_enabled" name="notification_enabled" value="1" @checked(!empty($settings['notification_enabled']))>
                                            <label class="form-check-label" for="notification_enabled">Notificaciones Telegram administrativas</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar configuracion</button>
                                    </div>
                                </div>
                            </form>
                        </section>
                    </div>
                @endif

                @if ($section === 'plataforma')
                    @php
                        $activeUsers = collect($users)->where('status', 'activo')->count();
                        $bannedUsers = collect($users)->where('status', 'baneado')->count();
                        $adminUsers = collect($users)->filter(fn ($u) => in_array($u['role'] ?? 'usuario', ['admin', 'root', 'gestor', 'administrador'], true))->count();
                        $projectRows = $accessMatrix['matrix'] ?? [];
                    @endphp
                    <div class="platform-grid">
                        <section class="platform-card">
                            <h3>Usuarios NOVA</h3>
                            <div class="platform-metric">{{ count($users) }}</div>
                            <div class="nova-muted fw-semibold">{{ $activeUsers }} activos / {{ $bannedUsers }} baneados</div>
                        </section>
                        <section class="platform-card">
                            <h3>Administradores</h3>
                            <div class="platform-metric">{{ $adminUsers }}</div>
                        </section>
                        <section class="platform-card">
                            <h3>Matriz de accesos</h3>
                            <div class="platform-metric">{{ count($projectRows) }}</div>
                        </section>
                    </div>
                    <section class="card nova-card rm-work-panel rm-panel mt-3">
                        <div class="rm-section-head">
                            <div>
                                <h2>Fuente principal de usuarios</h2>
                                <p>Usuarios normalizados que NOVA usa para accesos e integraciones.</p>
                            </div>
                        </div>
                        <div class="table-responsive rm-table-wrap">
                            <table class="table mb-0">
                                <thead><tr><th>Usuario</th><th>Nombre</th><th>Fuente</th><th>Proyectos</th><th>Estado</th></tr></thead>
                                <tbody>
                                    @foreach ($users as $user)
                                        <tr>
                                            <td><strong>{{ $user['username'] ?? '' }}</strong></td>
                                            <td>{{ trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) }}</td>
                                            <td>{{ $user['source'] ?? 'nova' }}</td>
                                            <td>{{ implode(', ', array_keys(is_array($user['projects'] ?? null) ? $user['projects'] : [])) ?: '-' }}</td>
                                            <td><span class="nova-badge {{ ($user['status'] ?? 'activo') === 'baneado' ? 'is-danger' : 'is-success' }}">{{ $user['status'] ?? 'activo' }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                @if ($section === 'salud')
                    <section class="card nova-card rm-work-panel rm-panel">
                        <div class="rm-section-head">
                            <div>
                                <h2>Estado de servicios</h2>
                                <p>Revisa primero cualquier estado distinto de OK.</p>
                            </div>
                        </div>
                        <div class="table-responsive rm-table-wrap">
                            <table class="table mb-0">
                                <thead><tr><th>Chequeo</th><th>Estado</th><th>Detalle</th></tr></thead>
                                <tbody>
                                    @foreach ($healthChecks as $check)
                                        @php $status = $check['status'] ?? 'warn'; @endphp
                                        <tr>
                                            <td><strong>{{ $check['name'] ?? '' }}</strong></td>
                                            <td><span class="health-dot is-{{ $status }}"><i class="bi bi-circle-fill"></i>{{ strtoupper($status) }}</span></td>
                                            <td>{{ $check['detail'] ?? '' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                @if ($section === 'auditoria')
                    <section class="card nova-card rm-work-panel rm-panel">
                        <div class="rm-section-head">
                            <div>
                                <h2>Auditoria global</h2>
                                <p>Historial de acciones administrativas relevantes.</p>
                            </div>
                        </div>
                        <div class="table-responsive rm-table-wrap">
                            <table class="table mb-0">
                                <thead><tr><th>Fecha</th><th>Evento</th><th>Usuario</th><th>Detalle</th><th>IP</th></tr></thead>
                                <tbody>
                                    @forelse ($auditItems as $item)
                                        <tr>
                                            <td>{{ $item['at'] ?? '' }}</td>
                                            <td><span class="nova-badge">{{ $item['event'] ?? '' }}</span></td>
                                            <td>{{ $item['user_name'] ?? '-' }}</td>
                                            <td>{{ $item['message'] ?? '' }}</td>
                                            <td>{{ $item['ip'] ?? '' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5">No hay eventos registrados.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                @if ($section === 'respaldos')
                    <section class="card nova-card rm-work-panel rm-panel mb-3">
                        <div class="rm-section-head">
                            <div>
                                <h2>Crear respaldo</h2>
                                <p>Genera una copia inmediata de archivos criticos.</p>
                            </div>
                        </div>
                        <form method="post" action="{{ route('administracion.backups.create') }}" class="row g-3 align-items-end">
                            @csrf
                            <div class="col-md-8">
                                <label class="form-label">Archivo</label>
                                <select class="form-select" name="target">
                                    <option value="all">Todos los archivos criticos</option>
                                    @foreach ($backupTargets as $target)
                                        <option value="{{ $target['key'] }}">{{ $target['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-primary w-100" type="submit"><i class="bi bi-archive"></i>Crear respaldo</button>
                            </div>
                        </form>
                    </section>
                    <section class="card nova-card rm-work-panel rm-panel">
                        <div class="rm-section-head"><div><h2>Respaldos recientes</h2><p>Ultimas copias creadas desde administracion.</p></div></div>
                        <div class="table-responsive rm-table-wrap">
                            <table class="table mb-0">
                                <thead><tr><th>Fecha</th><th>Archivo</th><th>Tamano</th><th>Ruta</th></tr></thead>
                                <tbody>
                                    @forelse ($backupItems as $item)
                                        <tr>
                                            <td>{{ $item['created_at'] }}</td>
                                            <td>{{ $item['name'] }}</td>
                                            <td>{{ number_format(((int) $item['size']) / 1024, 1) }} KB</td>
                                            <td><code>{{ $item['path'] }}</code></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4">No hay respaldos recientes.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif

                @if ($section === 'telegram')
                    @php
                        $webhookActive = (bool) data_get($telegramListener, 'webhook.active', false);
                        $webhookAvailable = (bool) data_get($telegramListener, 'webhook.available', false);
                        $pendingUpdates = data_get($telegramListener, 'webhook.pending');
                        $queuedMessages = (int) data_get($telegramListener, 'queue.outbox', 0);
                        $failedMessages = (int) data_get($telegramListener, 'queue.failed', 0);
                        $webhookError = (string) data_get($telegramListener, 'webhook.error', '');
                    @endphp
                    <div class="telegram-admin-grid">
                        <section class="card nova-card rm-work-panel rm-panel">
                            <div class="rm-section-head">
                                <div>
                                    <h2>Telegram global</h2>
                                    <p>Token y proxy usados por el bot central.</p>
                                </div>
                                <span class="config-status {{ $telegramConfigured ? 'is-ok' : 'is-warn' }}">
                                    <i class="bi {{ $telegramConfigured ? 'bi-check-circle' : 'bi-exclamation-triangle' }}"></i>{{ $telegramConfigured ? 'Bot activo' : 'Pendiente' }}
                                </span>
                            </div>
                            <form method="post" action="{{ route('administracion.config.update') }}">
                                @csrf
                                <input type="hidden" name="action" value="telegram">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold" for="config-telegram-token">TELEGRAM_BOT_TOKEN</label>
                                        <input class="form-control" id="config-telegram-token" name="bot_token" type="password" autocomplete="off" placeholder="{{ $telegramConfigured ? 'Dejar en blanco para conservar' : 'Token de BotFather' }}">
                                        <div class="form-text fw-semibold">Si ya esta configurado, deja este campo vacio para mantener el token actual.</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold" for="config-telegram-proxy">TELEGRAM_PROXY_URL</label>
                                        <input class="form-control" id="config-telegram-proxy" name="proxy_url" value="{{ old('proxy_url', $telegramConfig['proxy_url'] ?? '') }}" placeholder="Opcional, ejemplo: http://proxy:8080">
                                        <div class="form-text fw-semibold">Opcional. Usalo solo si el servidor necesita proxy para salir a internet.</div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex flex-wrap gap-2">
                                            <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar Telegram</button>
                                            <a class="btn btn-outline-primary fw-bold" href="{{ route('administracion.section', 'telegram-mensajes') }}"><i class="bi bi-chat-square-text"></i>Mensajes Telegram</a>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </section>

                        <section class="card nova-card rm-work-panel rm-panel">
                            <div class="rm-section-head">
                                <div>
                                    <h2>Servicio Telegram</h2>
                                    <p>Estado operativo del listener y la cola de mensajes.</p>
                                </div>
                                <span class="config-status is-ok">
                                    <i class="bi bi-box-seam"></i>Docker
                                </span>
                            </div>
                            <div class="telegram-listener-grid mb-3">
                                <div class="telegram-listener-metric is-ok">
                                    <i class="bi bi-box-seam"></i>
                                    <div>
                                        <span>Servicio</span>
                                        <strong>Dockerizado</strong>
                                    </div>
                                </div>
                                <div class="telegram-listener-metric {{ $webhookActive ? 'is-warn' : 'is-ok' }}">
                                    <i class="bi {{ $webhookActive ? 'bi-link-45deg' : 'bi-unlink' }}"></i>
                                    <div>
                                        <span>Webhook</span>
                                        <strong>{{ $webhookActive ? 'Activo' : ($webhookAvailable ? 'Inactivo' : 'Sin datos') }}</strong>
                                    </div>
                                </div>
                                <div class="telegram-listener-metric">
                                    <i class="bi bi-inboxes"></i>
                                    <div>
                                        <span>Cola Telegram</span>
                                        <strong>{{ $pendingUpdates === null ? '-' : $pendingUpdates }}</strong>
                                    </div>
                                </div>
                                <div class="telegram-listener-metric {{ $queuedMessages > 0 ? 'is-warn' : 'is-ok' }}">
                                    <i class="bi bi-send"></i>
                                    <div>
                                        <span>Por enviar</span>
                                        <strong>{{ $queuedMessages }}</strong>
                                    </div>
                                </div>
                                <div class="telegram-listener-metric {{ $failedMessages > 0 ? 'is-bad' : '' }}">
                                    <i class="bi bi-exclamation-octagon"></i>
                                    <div>
                                        <span>Fallidos</span>
                                        <strong>{{ $failedMessages }}</strong>
                                    </div>
                                </div>
                            </div>
                            <div class="telegram-listener-actions mb-3">
                                <form method="post" action="{{ route('administracion.telegram.listener') }}">
                                    @csrf
                                    <input type="hidden" name="action" value="delete_webhook">
                                    <button class="btn btn-outline-warning fw-bold" type="submit" @disabled(!$webhookActive || !$telegramConfigured)><i class="bi bi-unlink"></i>Quitar webhook</button>
                                </form>
                                <a class="btn btn-outline-secondary fw-bold" href="{{ route('administracion.section', 'telegram') }}"><i class="bi bi-arrow-clockwise"></i>Refrescar</a>
                            </div>
                            @if ($webhookError !== '')
                                <div class="alert alert-warning fw-semibold"><i class="bi bi-exclamation-triangle"></i> {{ $webhookError }}</div>
                            @endif
                        </section>
                    </div>
                @endif

                @if ($section === 'telegram-mensajes')
                    @php
                        $messageLabels = [
                            'help_header' => 'Encabezado de ayuda',
                            'status' => 'Respuesta de /estado',
                            'test' => 'Respuesta de /test',
                            'tic_success' => 'Reporte TIC creado',
                            'tic_unavailable' => 'TIC no disponible',
                            'tic_error' => 'Error TIC',
                            'emach_success' => 'Marcacion EMACH',
                            'emach_missing_credentials' => 'EMACH sin credenciales',
                            'emach_empty' => 'EMACH sin marcaciones',
                            'emach_error' => 'Error EMACH',
                            'disabled' => 'Comando desactivado',
                            'unknown' => 'Comando desconocido',
                        ];
                        $commandMessageMap = [
                            'help' => 'help_header',
                            'status' => 'status',
                            'emach' => 'emach_success',
                            'tic' => 'tic_success',
                            'test' => 'test',
                        ];
                        $messageHelp = [
                            'help_header' => 'Primera linea que aparece cuando alguien pide ayuda.',
                            'status' => 'Confirma que el bot esta activo y responde.',
                            'test' => 'Mensaje simple para probar que Telegram responde.',
                            'tic_success' => 'Confirmacion cuando se crea un reporte TIC pendiente.',
                            'tic_unavailable' => 'Se muestra si NOVA no puede cargar el modulo TIC.',
                            'tic_error' => 'Se muestra si falla la creacion del reporte TIC.',
                            'emach_success' => 'Respuesta con la ultima marcacion encontrada.',
                            'emach_missing_credentials' => 'Se muestra si el usuario no tiene credenciales EMACH guardadas.',
                            'emach_empty' => 'Se muestra si no hay marcaciones en el mes actual.',
                            'emach_error' => 'Se muestra si la consulta EMACH falla.',
                            'disabled' => 'Se muestra cuando el comando existe pero esta apagado.',
                            'unknown' => 'Se muestra cuando el bot no reconoce lo que escribieron.',
                        ];
                        $messagePlaceholdersMap = [
                            'status' => ['{fecha}' => 'Fecha y hora actual'],
                            'test' => ['{fecha}' => 'Fecha y hora actual'],
                            'tic_success' => ['{asunto}' => 'Problema reportado', '{categoria}' => 'Categoria detectada', '{unidad}' => 'Ubicacion o unidad'],
                            'tic_error' => ['{error}' => 'Detalle del error'],
                            'emach_success' => ['{fecha}' => 'Fecha de marcacion', '{hora}' => 'Hora de marcacion', '{tipo}' => 'Entrada o salida', '{reloj}' => 'Reloj usado'],
                            'emach_error' => ['{error}' => 'Detalle del error'],
                        ];
                        $systemMessageKeys = array_diff(array_keys($messageLabels), array_values($commandMessageMap));
                        $messages = $telegramCommandSettings['messages'] ?? [];
                        $messageRows = [];
                        foreach (($telegramCommands ?? []) as $command) {
                            $commandKey = (string) ($command['key'] ?? '');
                            $messageKey = $commandMessageMap[$commandKey] ?? '';
                            if ($messageKey === '') {
                                continue;
                            }
                            $messageRows[] = [
                                'type' => 'command',
                                'key' => $messageKey,
                                'label' => $messageLabels[$messageKey] ?? 'Respuesta',
                                'summary' => $messageHelp[$messageKey] ?? 'Mensaje del comando.',
                                'command_key' => $commandKey,
                                'command' => (string) ($command['command'] ?? ''),
                                'aliases' => $command['aliases'] ?? [],
                                'module' => (string) ($command['module'] ?? ''),
                                'description' => (string) ($command['description'] ?? ''),
                                'input' => (string) ($command['input'] ?? ''),
                                'enabled' => (bool) ($command['enabled'] ?? true),
                            ];
                        }
                        foreach ($systemMessageKeys as $key) {
                            $messageRows[] = [
                                'type' => 'system',
                                'key' => $key,
                                'label' => $messageLabels[$key] ?? $key,
                                'summary' => $messageHelp[$key] ?? 'Mensaje de sistema.',
                                'command_key' => '',
                                'command' => '',
                                'aliases' => [],
                                'module' => 'Sistema',
                                'description' => '',
                                'input' => '',
                                'enabled' => true,
                            ];
                        }
                        $firstMessageKey = (string) ($messageRows[0]['key'] ?? '');
                    @endphp
                    <form method="post" action="{{ route('administracion.config.update') }}">
                        @csrf
                        <input type="hidden" name="action" value="telegram_messages">
                        <div class="telegram-message-grid">
                            <section class="card nova-card rm-work-panel rm-panel">
                            <div class="rm-section-head">
                                <div>
                                    <h2>Mensajes programados Telegram</h2>
                                    <p>Selecciona un mensaje de la lista para cargarlo y editar su respuesta.</p>
                                    </div>
                                    <span class="config-status is-ok"><i class="bi bi-pencil-square"></i>Editables</span>
                                </div>

                                <div class="telegram-message-editor-layout" data-telegram-message-editor>
                                    <aside class="telegram-message-picker" aria-label="Mensajes programados">
                                        <h3 class="telegram-message-picker-title">Lista de mensajes</h3>
                                        @foreach ($messageRows as $row)
                                            <button class="telegram-message-option {{ $row['key'] === $firstMessageKey ? 'is-active' : '' }}" type="button" data-telegram-message-option="{{ $row['key'] }}">
                                                <strong>
                                                    <i class="bi {{ $row['type'] === 'command' ? 'bi-command' : 'bi-chat-square-text' }}"></i>
                                                    {{ $row['label'] }}
                                                </strong>
                                                <span>
                                                    @if ($row['type'] === 'command')
                                                        <code>{{ $row['command'] }}</code> · {{ $row['module'] }}
                                                    @else
                                                        Sistema
                                                    @endif
                                                </span>
                                            </button>
                                        @endforeach
                                    </aside>

                                    <div>
                                        @foreach ($messageRows as $row)
                                        @php
                                            $messageKey = (string) $row['key'];
                                            $messageValue = old("messages.{$messageKey}", $messages[$messageKey] ?? '');
                                            $messagePlaceholders = $messagePlaceholdersMap[$messageKey] ?? [];
                                        @endphp
                                            <article class="telegram-message-editor {{ $messageKey === $firstMessageKey ? 'is-active' : '' }}" data-telegram-message-panel="{{ $messageKey }}">
                                                <div class="telegram-message-editor-head">
                                                    <div>
                                                        <h3>{{ $row['label'] }}</h3>
                                                        <p>{{ $row['summary'] }}</p>
                                                    </div>
                                                    @if ($row['type'] === 'command')
                                                        <span class="telegram-message-command">{{ $row['command'] }}</span>
                                                    @else
                                                        <span class="config-status"><i class="bi bi-gear"></i>Sistema</span>
                                                    @endif
                                                </div>

                                                @if ($row['type'] === 'command')
                                                    <div class="mb-3">
                                                        <div class="telegram-edit-help">{{ $row['description'] }}</div>
                                                        <div class="telegram-edit-help">Formato: {{ $row['input'] }}</div>
                                                        @foreach (($row['aliases'] ?? []) as $alias)
                                                            <span class="command-alias">{{ $alias }}</span>
                                                        @endforeach
                                                    </div>
                                                    <label class="telegram-command-message-toggle mb-3" for="telegram-command-{{ $row['command_key'] }}">
                                                        <input type="hidden" name="commands[{{ $row['command_key'] }}][enabled]" value="0">
                                                        <input class="form-check-input" id="telegram-command-{{ $row['command_key'] }}" type="checkbox" name="commands[{{ $row['command_key'] }}][enabled]" value="1" @checked($row['enabled'])>
                                                        <span>Comando activo</span>
                                                    </label>
                                                @endif

                                                <div>
                                                    <label for="telegram-message-{{ $messageKey }}">Mensaje programado</label>
                                                    <textarea class="form-control" id="telegram-message-{{ $messageKey }}" name="messages[{{ $messageKey }}]" rows="4">{{ $messageValue }}</textarea>
                                                    <p class="telegram-edit-help">Los campos entre llaves, como <code>{fecha}</code>, son datos que NOVA completa automaticamente.</p>
                                                    @if ($messagePlaceholders !== [])
                                                        <div class="telegram-placeholder-list" aria-label="Campos disponibles">
                                                            <span>Datos disponibles:</span>
                                                            @foreach ($messagePlaceholders as $placeholder => $description)
                                                                <code title="{{ $description }}">{{ $placeholder }}</code>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                            </article>
                                        @endforeach
                                    </div>
                                </div>
                            </section>
                            <div class="telegram-save-bar">
                                <div>
                                    <strong>Guardar cambios</strong>
                                    <span>Se actualizaran respuestas y comandos activos para el bot Telegram.</span>
                                </div>
                                <button class="btn btn-primary fw-bold" type="submit"><i class="bi bi-save"></i>Guardar mensajes</button>
                            </div>
                        </div>
                    </form>
                @endif

                @if ($section === 'emach')
                    @php
                        $emachSchedule = (string) ($emachConfig['schedule'] ?? '07:00-09:30=15,16:30-19:30=15');
                        $emachSlowInterval = (string) ($emachConfig['slow_interval'] ?? '300');
                        $scheduleParts = array_values(array_filter(array_map('trim', explode(',', $emachSchedule))));
                        $firstWindow = $scheduleParts[0] ?? '07:00-09:30=15';
                        $secondWindow = $scheduleParts[1] ?? '16:30-19:30=15';
                        [$firstRange, $firstSeconds] = array_pad(explode('=', $firstWindow, 2), 2, '15');
                        [$secondRange, $secondSeconds] = array_pad(explode('=', $secondWindow, 2), 2, '15');
                        [$firstStart, $firstEnd] = array_pad(explode('-', $firstRange, 2), 2, '');
                        [$secondStart, $secondEnd] = array_pad(explode('-', $secondRange, 2), 2, '');
                    @endphp
                    <section class="card nova-card rm-work-panel rm-panel">
                        <div class="rm-section-head">
                            <div>
                                <h2>EMACH global</h2>
                                <p>Define cuando NOVA consulta con mayor frecuencia y cuando baja el ritmo.</p>
                            </div>
                            <span class="config-status is-ok">
                                <i class="bi bi-clock-history"></i>Frecuencia configurada
                            </span>
                        </div>
                        <form method="post" action="{{ route('administracion.config.update') }}" data-emach-timing-form>
                            @csrf
                            <input type="hidden" name="action" value="emach">
                            <input type="hidden" name="schedule" value="{{ old('schedule', $emachSchedule) }}" data-emach-schedule>
                            <div class="emach-time-grid">
                                <article class="emach-time-card">
                                    <h3><i class="bi bi-sunrise"></i> Entrada</h3>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label" for="emach-entry-start">Desde</label>
                                            <input class="form-control" id="emach-entry-start" type="time" value="{{ $firstStart }}" data-emach-window-start>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label" for="emach-entry-end">Hasta</label>
                                            <input class="form-control" id="emach-entry-end" type="time" value="{{ $firstEnd }}" data-emach-window-end>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label" for="emach-entry-interval">Cada segundos</label>
                                            <input class="form-control" id="emach-entry-interval" type="number" min="15" value="{{ $firstSeconds }}" data-emach-window-interval>
                                        </div>
                                    </div>
                                </article>
                                <article class="emach-time-card">
                                    <h3><i class="bi bi-sunset"></i> Salida</h3>
                                    <div class="row g-3">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label" for="emach-exit-start">Desde</label>
                                            <input class="form-control" id="emach-exit-start" type="time" value="{{ $secondStart }}" data-emach-window-start>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label" for="emach-exit-end">Hasta</label>
                                            <input class="form-control" id="emach-exit-end" type="time" value="{{ $secondEnd }}" data-emach-window-end>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label" for="emach-exit-interval">Cada segundos</label>
                                            <input class="form-control" id="emach-exit-interval" type="number" min="15" value="{{ $secondSeconds }}" data-emach-window-interval>
                                        </div>
                                    </div>
                                </article>
                            </div>
                            <div class="row g-3 align-items-end mt-1">
                                <div class="col-12 col-lg-3">
                                    <label class="form-label fw-bold" for="config-emach-slow">Fuera de esos horarios</label>
                                    <input class="form-control" id="config-emach-slow" name="slow_interval" type="number" min="15" value="{{ old('slow_interval', $emachSlowInterval) }}">
                                    <div class="form-text fw-semibold">Intervalo lento en segundos.</div>
                                </div>
                                <div class="col-12 col-lg-3">
                                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-save"></i>Guardar tiempos</button>
                                </div>
                            </div>
                            <div class="emach-interval-preview">
                                <span><i class="bi bi-lightning-charge"></i><span data-emach-preview>{{ $emachSchedule }}</span></span>
                                <span><i class="bi bi-hourglass-split"></i>Lento: {{ $emachSlowInterval }}s</span>
                            </div>
                        </form>
                        <div class="config-code">{{ $emachConfigPath }}</div>
                    </section>
                @endif

                @if ($section === 'usuarios')
                <div class="user-grid">
            <div class="nova-modal-backdrop" data-user-modal aria-hidden="true">
            <form class="nova-confirm nova-user-form form-panel" method="post" action="{{ route('administracion.users.update') }}">
                @csrf
                <input type="hidden" name="id" data-user-id>
                <input type="hidden" name="redmine_id" data-user-redmine-id>

                <div class="form-title nova-user-form__body" style="margin-bottom: 0;">
                    <h2 data-user-form-title>Crear usuario</h2>
                    <span class="nova-badge" data-user-mode>Nuevo</span>
                    <button class="modal-close" type="button" aria-label="Cerrar" data-user-close>&times;</button>
                </div>

                <div class="nova-user-form__body">
                    <div class="form-section-title">Identificacion</div>
                    <div class="form-section">
                        <div class="field">
                            <label for="rut">RUT</label>
                            <input class="form-control" id="rut" name="rut" placeholder="12.345.678-9" maxlength="12" data-user-rut>
                            <div class="field-help" data-user-rut-help>Ingrese un RUT valido.</div>
                        </div>
                        <div class="field">
                            <label for="username">Usuario acceso</label>
                            <input class="form-control" id="username" name="username" readonly data-user-username>
                        </div>
                    </div>

                    <div class="form-section is-two">
                        <div class="field">
                            <label for="name">Nombre</label>
                            <input class="form-control" id="name" name="name" required data-user-name>
                        </div>
                        <div class="field">
                            <label for="apellido">Apellidos</label>
                            <input class="form-control" id="apellido" name="apellido" required data-user-apellido>
                        </div>
                    </div>

                    <div class="form-section-title">Acceso</div>
                    <div class="form-section is-two">
                        <div class="field">
                            <label for="role">Permiso vista principal</label>
                            <select class="form-select" id="role" name="role" data-user-role>
                                <option value="usuario">Usuario</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="status">Estado</label>
                            <select class="form-select" id="status" name="status" data-user-status>
                                <option value="activo">activo</option>
                                <option value="baneado">baneado</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title" data-user-create-password>Clave inicial</div>
                    <div class="form-section" data-user-create-password>
                        <div class="field">
                            <label for="password">Contrasena</label>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password">
                        </div>
                        <div class="field">
                            <label for="password_confirmation">Validar contrasena</label>
                            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="Repetir contrasena">
                        </div>
                    </div>

                    <div class="form-section-title">Credenciales EMACH</div>
                    <div class="form-section is-two">
                        <div class="field">
                            <label for="emach_user">Usuario EMACH</label>
                            <input class="form-control" id="emach_user" name="emach_user" autocomplete="off" placeholder="RUT trabajador" data-user-emach-user>
                        </div>
                        <div class="field">
                            <label for="emach_password">Contrasena EMACH</label>
                            <input class="form-control" id="emach_password" name="emach_password" type="password" autocomplete="new-password" placeholder="Dejar vacia para conservar" data-user-emach-password>
                        </div>
                    </div>

                    <div class="form-section-title">Telegram</div>
                    <div class="form-section">
                        <div class="field">
                            <label for="telegram_chat_id">Chat ID Telegram</label>
                            <input class="form-control" id="telegram_chat_id" name="telegram_chat_id" autocomplete="off" placeholder="7449883192" data-user-telegram-chat-id>
                        </div>
                    </div>
                </div>
                <div class="nova-user-form__footer">
                    <button class="btn btn-outline-secondary" type="button" data-user-close>Cancelar</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar</button>
                </div>
            </form>
            </div>

            <section class="card nova-card rm-work-panel">
                <div class="table-panel-head">
                    <div>
                        <h2>Usuarios registrados</h2>
                        <div class="nova-muted small"><span data-user-count>{{ count($users) }}</span> usuario(s) visibles</div>
                        <div class="nova-muted small">Busca, filtra y edita datos de acceso, EMACH o Telegram.</div>
                    </div>
                    <div class="user-filters">
                        <div class="user-search">
                            <i class="bi bi-search"></i>
                            <input class="form-control" type="search" placeholder="Buscar por nombre, ID o usuario acceso" data-user-search>
                        </div>
                        <select class="form-select column-filter" data-role-filter aria-label="Filtrar permiso NOVA">
                            <option value="">Permiso: todos</option>
                            <option value="admin">Admin</option>
                            <option value="usuario">Usuario</option>
                        </select>
                        <select class="form-select column-filter" data-status-filter aria-label="Filtrar estado">
                            <option value="">Estado: todos</option>
                            <option value="activo">activo</option>
                            <option value="baneado">baneado</option>
                        </select>
                        <button class="btn btn-outline-secondary" type="button" data-user-filter-clear title="Limpiar filtros"><i class="bi bi-x-circle"></i></button>
                    </div>
                    <button class="btn btn-primary user-primary-action" type="button" data-user-new><i class="bi bi-plus-circle"></i>Nuevo usuario</button>
                </div>
                <div class="table-responsive rm-table-wrap">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Nombre</th>
                                <th>ID Redmine</th>
                                <th>EMACH</th>
                                <th>Telegram</th>
                                <th>Permiso NOVA</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($users as $user)
                            @php
                                $novaRole = in_array(($user['role'] ?? 'usuario'), ['admin', 'administrador', 'gestor', 'root'], true) ? 'admin' : 'usuario';
                                $userStatus = $user['status'] ?? 'activo';
                                $emachCredentials = is_array($user['emach_credentials'] ?? null) ? $user['emach_credentials'] : [];
                                $hasEmachCredentials = trim((string) ($emachCredentials['user'] ?? '')) !== '' && trim((string) ($emachCredentials['password'] ?? '')) !== '';
                                $telegramSettings = is_array($user['telegram_settings'] ?? null) ? $user['telegram_settings'] : [];
                                $hasTelegramSettings = trim((string) ($telegramSettings['chat_id'] ?? '')) !== '';
                            @endphp
                            <tr data-user-row
                                data-user-row-id="{{ $user['id'] ?? '' }}"
                                data-user-row-rut="{{ $user['rut'] ?? '' }}"
                                data-user-row-username="{{ $user['username'] ?? '' }}"
                                data-user-row-role="{{ $novaRole }}"
                                data-user-row-status="{{ $userStatus }}"
                                data-search="{{ strtolower(($user['id'] ?? '') . ' ' . ($user['username'] ?? '') . ' ' . ($user['rut'] ?? '') . ' ' . ($user['rut_sin_dv'] ?? '') . ' ' . ($user['redmine_id'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) }}">
                                <td>
                                    <strong>{{ $user['username'] ?? '' }}</strong>
                                    <div class="nova-muted small">{{ $user['rut'] ?? '' }}</div>
                                </td>
                                <td>{{ trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) }}</td>
                                <td>{{ $user['redmine_id'] ?? '-' }}</td>
                                <td>
                                    <span class="emach-credential-badge {{ $hasEmachCredentials ? '' : 'is-missing' }}">
                                        <i class="bi {{ $hasEmachCredentials ? 'bi-key-fill' : 'bi-key' }}"></i>{{ $hasEmachCredentials ? 'Guardadas' : 'Sin datos' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="telegram-user-badge {{ $hasTelegramSettings ? '' : 'is-missing' }}">
                                        <i class="bi {{ $hasTelegramSettings ? 'bi-telegram' : 'bi-chat' }}"></i>{{ $hasTelegramSettings ? 'Chat ID' : 'Sin datos' }}
                                    </span>
                                </td>
                                <td><span class="nova-badge {{ $novaRole === 'admin' ? 'is-success' : '' }}">{{ $novaRole === 'admin' ? 'Admin' : 'Usuario' }}</span></td>
                                <td><span class="nova-badge {{ $userStatus === 'baneado' ? 'is-danger' : '' }}">{{ $userStatus }}</span></td>
                                <td>
                                    <div class="row-actions">
                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-user-edit
                                            data-id="{{ $user['id'] ?? '' }}"
                                            data-redmine-id="{{ $user['redmine_id'] ?? '' }}"
                                            data-username="{{ $user['username'] ?? '' }}"
                                            data-name="{{ $user['name'] ?? '' }}"
                                            data-apellido="{{ $user['apellido'] ?? '' }}"
                                            data-rut="{{ $user['rut'] ?? '' }}"
                                            data-emach-user="{{ $emachCredentials['user'] ?? '' }}"
                                            data-telegram-chat-id="{{ $telegramSettings['chat_id'] ?? '' }}"
                                            data-role="{{ $novaRole }}"
                                            data-status="{{ $user['status'] ?? 'activo' }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary" type="button"
                                            title="Cambiar contrasena"
                                            data-password-open
                                            data-id="{{ $user['id'] ?? '' }}"
                                            data-username="{{ $user['username'] ?? '' }}"
                                            data-display-name="{{ trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) }}">
                                            <i class="bi bi-key"></i>
                                        </button>
                                        <form method="post" action="{{ route('administracion.users.update') }}" data-confirm-form data-confirm-message="{{ $userStatus === 'baneado' ? 'Activar este usuario?' : 'Marcar usuario como baneado?' }}">
                                            @csrf
                                            <input type="hidden" name="action" value="{{ $userStatus === 'baneado' ? 'activate' : 'delete' }}">
                                            <input type="hidden" name="id" value="{{ $user['id'] ?? '' }}">
                                            @if ($userStatus === 'baneado')
                                                <button class="btn btn-sm btn-outline-success" type="submit" title="Activar"><i class="bi bi-check-circle"></i></button>
                                            @else
                                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Banear"><i class="bi bi-slash-circle"></i></button>
                                            @endif
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">No hay usuarios NOVA registrados.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
                </div>
                @endif

                @if ($section === 'accesos')
                    @php
                        $accessModules = $accessMatrix['modules'] ?? [];
                        $accessRows = $accessMatrix['matrix'] ?? [];
                        $firstIdentity = (string) ($accessRows[0]['identity'] ?? '');
                    @endphp
                    <form method="post" action="{{ route('administracion.access.update') }}">
                        @csrf
                        <input type="hidden" name="selected_identity" value="{{ $firstIdentity }}" data-access-selected-identity>
                        <section class="card nova-card rm-work-panel">
                            <div class="access-panel-head">
                                <div>
                                    <h2>Accesos a vistas NOVA</h2>
                                    <p class="access-help">Selecciona un usuario y marca las vistas que puede usar.</p>
                                </div>
                                <div class="access-tools">
                                    <input class="form-control" type="search" list="access-user-list" placeholder="Escribir para buscar usuario" data-access-user-combobox aria-label="Seleccionar usuario">
                                    <datalist id="access-user-list">
                                        @foreach ($accessRows as $row)
                                            @php
                                                $user = $row['user'] ?? [];
                                                $identity = (string) ($row['identity'] ?? '');
                                                $displayName = trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: ($user['username'] ?? '');
                                                $optionLabel = $displayName;
                                            @endphp
                                            <option value="{{ $optionLabel }}" data-identity="{{ $identity }}"></option>
                                        @endforeach
                                    </datalist>
                                </div>
                                <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar accesos</button>
                            </div>
                            <div class="access-list">
                                @forelse ($accessRows as $row)
                                    @php
                                        $user = $row['user'] ?? [];
                                        $identity = (string) ($row['identity'] ?? '');
                                        $displayName = trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: ($user['username'] ?? '');
                                        $selectedCount = collect($row['access'] ?? [])->filter(fn ($item) => $item['allowed'] ?? false)->count();
                                    @endphp
                                    <article class="access-user-panel {{ $loop->first ? 'is-active' : '' }}" data-access-user-panel="{{ $identity }}">
                                        <div class="access-user-summary">
                                            <div>
                                                <h3>{{ $displayName }}</h3>
                                                <div class="access-view-meta">
                                                    {{ $user['username'] ?? '' }}
                                                    @if (!empty($user['rut']))
                                                        / {{ $user['rut'] }}
                                                    @endif
                                                    @if (!empty($user['redmine_id']))
                                                        / ID Redmine {{ $user['redmine_id'] }}
                                                    @endif
                                                </div>
                                            </div>
                                            <span class="nova-badge" data-user-access-count="{{ $identity }}">{{ $selectedCount }} acceso(s)</span>
                                        </div>
                                        <div class="access-module-grid">
                                            @forelse ($accessModules as $moduleKey => $module)
                                                @php
                                                    $cell = $row['access'][$moduleKey] ?? ['allowed' => false, 'source' => 'sin acceso'];
                                                    $source = (string) ($cell['source'] ?? 'sin acceso');
                                                    $sourceClass = $source === 'manual' ? 'is-manual' : (in_array($source, ['redmine'], true) ? 'is-default' : '');
                                                    $sourceLabel = ['redmine' => 'Redmine', 'manual' => 'Manual'][$source] ?? 'Sin base';
                                                @endphp
                                                <article class="access-view-card">
                                                    <div class="access-view-head">
                                                        <span>
                                                            <span class="access-view-title d-block">{{ $module['name'] ?? $moduleKey }}</span>
                                                            <span class="access-view-meta d-block">{{ $moduleKey }}</span>
                                                        </span>
                                                        <label class="access-user-option">
                                                            <input class="form-check-input" type="checkbox" name="access[{{ $identity }}][{{ $moduleKey }}]" value="1" data-access-user-checkbox="{{ $identity }}" @checked($cell['allowed'] ?? false)>
                                                            <span class="access-source {{ $sourceClass }}">{{ $sourceLabel }}</span>
                                                        </label>
                                                    </div>
                                                </article>
                                            @empty
                                                <div class="nova-muted fw-semibold">No hay vistas delegables configuradas.</div>
                                            @endforelse
                                        </div>
                                    </article>
                                @empty
                                    <div class="nova-muted fw-semibold">No hay usuarios para administrar accesos.</div>
                                @endforelse
                            </div>
                        </section>
                    </form>
                @endif
            </main>
        </div>
    </div>
    <div class="nova-toast-stack" aria-live="polite" aria-atomic="true">
        @if (session('status'))
            <div class="nova-toast is-success" data-toast><i class="bi bi-check-circle-fill"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="nova-toast is-danger" data-toast><i class="bi bi-exclamation-triangle-fill"></i><span>{{ session('error') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="nova-toast is-danger" data-toast><i class="bi bi-exclamation-triangle-fill"></i><span>{{ $errors->first() }}</span></div>
        @endif
    </div>
    <div class="nova-modal-backdrop" data-confirm-modal aria-hidden="true">
        <div class="nova-confirm" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
            <div class="nova-confirm__body">
                <h2 id="confirm-title">Confirmar accion</h2>
                <p data-confirm-text>Confirma la accion sobre este usuario.</p>
            </div>
            <div class="nova-confirm__actions">
                <button class="btn btn-outline-secondary" type="button" data-confirm-cancel>Cancelar</button>
                <button class="btn btn-primary" type="button" data-confirm-accept>Confirmar</button>
            </div>
        </div>
    </div>
    <div class="nova-modal-backdrop" data-password-modal aria-hidden="true">
        <form class="nova-confirm" method="post" action="{{ route('administracion.users.update') }}" role="dialog" aria-modal="true" aria-labelledby="password-title">
            @csrf
            <input type="hidden" name="action" value="password">
            <input type="hidden" name="id" data-password-user-id>
            <div class="nova-confirm__body">
                <h2 id="password-title">Cambiar contrasena</h2>
                <p class="nova-muted" data-password-user-text>Selecciona un usuario.</p>
                <div class="field">
                    <label for="password-new">Nueva contrasena</label>
                    <input class="form-control" id="password-new" name="password" type="password" autocomplete="new-password" required data-password-new>
                </div>
                <div class="field">
                    <label for="password-new-confirm">Validar contrasena</label>
                    <input class="form-control" id="password-new-confirm" name="password_confirmation" type="password" autocomplete="new-password" required data-password-confirm>
                </div>
            </div>
            <div class="nova-confirm__actions">
                <button class="btn btn-outline-secondary" type="button" data-password-close>Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-key"></i>Actualizar</button>
            </div>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const form = document.querySelector('.form-panel');
        const formTitle = document.querySelector('[data-user-form-title]');
        const formMode = document.querySelector('[data-user-mode]');
        const userModal = document.querySelector('[data-user-modal]');
        const passwordModal = document.querySelector('[data-password-modal]');
        const passwordUserText = document.querySelector('[data-password-user-text]');
        const passwordUserId = document.querySelector('[data-password-user-id]');
        const passwordNew = document.querySelector('[data-password-new]');
        const passwordConfirm = document.querySelector('[data-password-confirm]');
        const setValue = (selector, value) => {
            const field = form?.querySelector(selector);
            if (field) field.value = value || '';
        };
        const setCreatePasswordVisible = (visible) => {
            form?.querySelectorAll('[data-user-create-password]').forEach((element) => {
                element.hidden = !visible;
            });
        };
        const openUserModal = () => {
            userModal?.classList.add('is-open');
            userModal?.setAttribute('aria-hidden', 'false');
            setTimeout(() => form?.querySelector('[data-user-rut]')?.focus(), 60);
        };
        const closeUserModal = () => {
            userModal?.classList.remove('is-open');
            userModal?.setAttribute('aria-hidden', 'true');
        };
        const resetUserForm = () => {
            form?.reset();
            setValue('[data-user-id]', '');
            setValue('[data-user-redmine-id]', '');
            setValue('[data-user-username]', '');
            setValue('[data-user-emach-user]', '');
            setValue('[data-user-emach-password]', '');
            setValue('[data-user-telegram-chat-id]', '');
            setCreatePasswordVisible(true);
            rutField?.classList.remove('is-invalid');
            if (formTitle) formTitle.textContent = 'Crear usuario';
            if (formMode) formMode.textContent = 'Nuevo';
        };
        const rutAccessUser = (rut) => {
            const raw = String(rut || '').trim();
            const clean = raw.replace(/[^0-9kK]/g, '').toLowerCase();
            if (!clean) return '';
            return clean.slice(0, -1);
        };
        const formatRut = (rut) => {
            const clean = String(rut || '').replace(/[^0-9kK]/g, '').toUpperCase();
            if (clean.length <= 1) return clean;

            const number = clean.slice(0, -1);
            const dv = clean.slice(-1);
            const dotted = number.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            return `${dotted}-${dv}`;
        };
        const isValidRut = (rut) => {
            const clean = String(rut || '').replace(/[^0-9kK]/g, '').toLowerCase();
            if (!/^\d{7,8}[0-9k]$/.test(clean)) return false;

            const number = clean.slice(0, -1);
            const dv = clean.slice(-1);
            let factor = 2;
            let sum = 0;

            for (let i = number.length - 1; i >= 0; i -= 1) {
                sum += Number(number[i]) * factor;
                factor = factor === 7 ? 2 : factor + 1;
            }

            const rest = 11 - (sum % 11);
            const expected = rest === 11 ? '0' : rest === 10 ? 'k' : String(rest);
            return expected === dv;
        };
        const normalizeRut = (rut) => String(rut || '').replace(/[^0-9kK]/g, '').toLowerCase();
        const rutHelp = form?.querySelector('[data-user-rut-help]');
        const duplicateRutUser = () => {
            const currentId = form?.querySelector('[data-user-id]')?.value || '';
            const rut = normalizeRut(rutField?.value);
            const username = rutAccessUser(rutField?.value);
            if (!rut || !isValidRut(rutField?.value)) return null;

            return Array.from(document.querySelectorAll('[data-user-row]')).find((row) => {
                const rowId = row.dataset.userRowId || '';
                if (currentId !== '' && rowId === currentId) return false;

                return normalizeRut(row.dataset.userRowRut) === rut
                    || String(row.dataset.userRowUsername || '').toLowerCase() === username;
            }) || null;
        };
        const updateRutState = (showInvalid = true) => {
            if (!rutField) return;
            const hasValue = rutField.value.trim() !== '';
            const valid = isValidRut(rutField.value);
            const duplicate = valid ? duplicateRutUser() : null;
            const currentId = form?.querySelector('[data-user-id]')?.value || '';
            const usernameField = form?.querySelector('[data-user-username]');

            if (rutHelp) {
                rutHelp.textContent = duplicate ? 'Este RUT ya esta registrado.' : 'Ingrese un RUT valido.';
            }

            rutField.classList.toggle('is-invalid', showInvalid && hasValue && (!valid || duplicate !== null));
            if (valid) {
                setValue('[data-user-username]', rutAccessUser(rutField.value));
            } else if (currentId === '') {
                setValue('[data-user-username]', '');
            } else if (usernameField && usernameField.value === '') {
                setValue('[data-user-username]', form?.querySelector('[data-user-redmine-id]')?.value || '');
            }
        };
        const rutField = form?.querySelector('[data-user-rut]');
        rutField?.addEventListener('input', () => {
            const cursorAtEnd = rutField.selectionStart === rutField.value.length;
            rutField.value = formatRut(rutField.value);
            updateRutState(false);
            if (cursorAtEnd) {
                rutField.setSelectionRange(rutField.value.length, rutField.value.length);
            }
        });
        rutField?.addEventListener('blur', () => {
            rutField.value = formatRut(rutField.value);
            updateRutState(true);
        });
        form?.addEventListener('submit', (event) => {
            updateRutState(true);
            const currentId = form?.querySelector('[data-user-id]')?.value || '';
            const hasRut = (rutField?.value || '').trim() !== '';
            const mustValidateRut = currentId === '' || hasRut;
            if (rutField && mustValidateRut && (!isValidRut(rutField.value) || duplicateRutUser() !== null)) {
                event.preventDefault();
                rutField.focus();
            }
        });

        document.querySelectorAll('[data-user-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                setValue('[data-user-id]', button.dataset.id);
                setValue('[data-user-redmine-id]', button.dataset.redmineId);
                setValue('[data-user-username]', button.dataset.username);
                setValue('[data-user-name]', button.dataset.name);
                setValue('[data-user-apellido]', button.dataset.apellido);
                setValue('[data-user-rut]', button.dataset.rut);
                if (rutField) {
                    rutField.value = formatRut(rutField.value);
                }
                updateRutState(false);
                setValue('[data-user-role]', button.dataset.role);
                setValue('[data-user-status]', button.dataset.status);
                setValue('[data-user-emach-user]', button.dataset.emachUser);
                setValue('[data-user-emach-password]', '');
                setValue('[data-user-telegram-chat-id]', button.dataset.telegramChatId);
                setValue('#password', '');
                setValue('#password_confirmation', '');
                setCreatePasswordVisible(false);
                if (formTitle) formTitle.textContent = 'Editar usuario';
                if (formMode) formMode.textContent = 'Editando';
                openUserModal();
            });
        });

        document.querySelectorAll('[data-password-open]').forEach((button) => {
            button.addEventListener('click', () => {
                if (passwordUserId) passwordUserId.value = button.dataset.id || '';
                if (passwordUserText) {
                    const label = button.dataset.displayName || button.dataset.username || 'Usuario seleccionado';
                    passwordUserText.textContent = `${label} / Usuario acceso ${button.dataset.username || '-'}`;
                }
                if (passwordNew) passwordNew.value = '';
                if (passwordConfirm) passwordConfirm.value = '';
                passwordModal?.classList.add('is-open');
                passwordModal?.setAttribute('aria-hidden', 'false');
                setTimeout(() => passwordNew?.focus(), 60);
            });
        });

        document.querySelectorAll('[data-password-close]').forEach((button) => {
            button.addEventListener('click', () => {
                passwordModal?.classList.remove('is-open');
                passwordModal?.setAttribute('aria-hidden', 'true');
            });
        });

        document.querySelector('[data-user-new]')?.addEventListener('click', () => {
            resetUserForm();
            openUserModal();
        });

        document.querySelectorAll('[data-user-close]').forEach((button) => {
            button.addEventListener('click', () => {
                closeUserModal();
            });
        });

        const normalizeSearch = (value) => String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^0-9a-z k]/g, ' ');
        const rows = Array.from(document.querySelectorAll('[data-user-row]'));
        const visibleCount = document.querySelector('[data-user-count]');
        const searchInput = document.querySelector('[data-user-search]');
        const roleFilter = document.querySelector('[data-role-filter]');
        const statusFilter = document.querySelector('[data-status-filter]');
        const applyUserFilters = () => {
            const query = normalizeSearch(searchInput?.value || '');
            const role = roleFilter?.value || '';
            const status = statusFilter?.value || '';
            let count = 0;

            rows.forEach((row) => {
                const haystack = normalizeSearch(row.dataset.search);
                const matchSearch = query === '' || haystack.includes(query);
                const matchRole = role === '' || row.dataset.userRowRole === role;
                const matchStatus = status === '' || row.dataset.userRowStatus === status;
                const visible = matchSearch && matchRole && matchStatus;
                row.style.display = visible ? '' : 'none';
                if (visible) count += 1;
            });

            if (visibleCount) visibleCount.textContent = String(count);
        };
        searchInput?.addEventListener('input', applyUserFilters);
        roleFilter?.addEventListener('change', applyUserFilters);
        statusFilter?.addEventListener('change', applyUserFilters);
        document.querySelector('[data-user-filter-clear]')?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            if (roleFilter) roleFilter.value = '';
            if (statusFilter) statusFilter.value = '';
            applyUserFilters();
        });

        const accessUserCombobox = document.querySelector('[data-access-user-combobox]');
        const accessUserOptions = Array.from(document.querySelectorAll('#access-user-list option'));
        const accessIdentityField = document.querySelector('[data-access-selected-identity]');
        const accessPanels = Array.from(document.querySelectorAll('[data-access-user-panel]'));
        const accessLabelByIdentity = new Map(accessUserOptions.map((option) => [option.dataset.identity, option.value]));
        const setActiveAccessUser = (identity) => {
            if (accessIdentityField) accessIdentityField.value = identity || '';
            if (accessUserCombobox && accessLabelByIdentity.has(identity)) {
                accessUserCombobox.value = accessLabelByIdentity.get(identity);
            }

            accessPanels.forEach((panel) => {
                const active = panel.dataset.accessUserPanel === identity;
                panel.classList.toggle('is-active', active);
                panel.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                    input.disabled = !active;
                });
            });
        };
        const identityFromCombobox = () => {
            const typed = String(accessUserCombobox?.value || '').trim();
            const option = accessUserOptions.find((item) => item.value === typed);
            return option?.dataset.identity || '';
        };
        const updateUserAccessCount = (identity) => {
            const counter = document.querySelector(`[data-user-access-count="${identity}"]`);
            if (!counter) return;

            const count = Array.from(document.querySelectorAll(`[data-access-user-checkbox="${identity}"]`)).filter((input) => input.checked).length;
            counter.textContent = `${count} acceso(s)`;
        };
        accessUserCombobox?.addEventListener('input', () => {
            const identity = identityFromCombobox();
            if (identity !== '') {
                setActiveAccessUser(identity);
            }
        });
        accessUserCombobox?.addEventListener('change', () => {
            const identity = identityFromCombobox();
            if (identity !== '') {
                setActiveAccessUser(identity);
            }
        });
        document.querySelectorAll('[data-access-user-checkbox]').forEach((checkbox) => {
            checkbox.addEventListener('change', () => updateUserAccessCount(checkbox.dataset.accessUserCheckbox));
        });
        setActiveAccessUser(accessIdentityField?.value || accessUserOptions[0]?.dataset.identity || '');

        const emachTimingForm = document.querySelector('[data-emach-timing-form]');
        const emachScheduleField = document.querySelector('[data-emach-schedule]');
        const emachPreview = document.querySelector('[data-emach-preview]');
        const updateEmachSchedule = () => {
            if (!emachTimingForm || !emachScheduleField) return;
            const cards = Array.from(emachTimingForm.querySelectorAll('.emach-time-card'));
            const chunks = cards.map((card) => {
                const start = card.querySelector('[data-emach-window-start]')?.value || '';
                const end = card.querySelector('[data-emach-window-end]')?.value || '';
                const interval = Math.max(15, parseInt(card.querySelector('[data-emach-window-interval]')?.value || '15', 10) || 15);
                return start && end ? `${start}-${end}=${interval}` : '';
            }).filter(Boolean);
            emachScheduleField.value = chunks.join(',');
            if (emachPreview) emachPreview.textContent = emachScheduleField.value || 'Sin ventanas rapidas';
        };
        emachTimingForm?.querySelectorAll('input').forEach((input) => {
            input.addEventListener('input', updateEmachSchedule);
            input.addEventListener('change', updateEmachSchedule);
        });
        emachTimingForm?.addEventListener('submit', updateEmachSchedule);
        updateEmachSchedule();

        const telegramMessageOptions = Array.from(document.querySelectorAll('[data-telegram-message-option]'));
        const telegramMessagePanels = Array.from(document.querySelectorAll('[data-telegram-message-panel]'));
        const setActiveTelegramMessage = (key) => {
            telegramMessageOptions.forEach((option) => {
                option.classList.toggle('is-active', option.dataset.telegramMessageOption === key);
            });
            telegramMessagePanels.forEach((panel) => {
                panel.classList.toggle('is-active', panel.dataset.telegramMessagePanel === key);
            });
        };
        telegramMessageOptions.forEach((option) => {
            option.addEventListener('click', () => {
                setActiveTelegramMessage(option.dataset.telegramMessageOption || '');
            });
        });

        document.querySelector('.admin-section-nav .nav-link.active')?.scrollIntoView({
            block: 'nearest',
            inline: 'center',
        });

        document.querySelectorAll('[data-toast]').forEach((toast) => {
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(10px)';
                toast.style.transition = 'opacity .18s ease, transform .18s ease';
                setTimeout(() => toast.remove(), 220);
            }, 4500);
        });

        const confirmModal = document.querySelector('[data-confirm-modal]');
        const confirmText = document.querySelector('[data-confirm-text]');
        const confirmAccept = document.querySelector('[data-confirm-accept]');
        const confirmCancel = document.querySelector('[data-confirm-cancel]');
        let pendingForm = null;

        document.querySelectorAll('[data-confirm-form]').forEach((actionForm) => {
            actionForm.addEventListener('submit', (event) => {
                event.preventDefault();
                pendingForm = actionForm;
                if (confirmText) {
                    confirmText.textContent = actionForm.dataset.confirmMessage || 'Confirma la accion sobre este usuario.';
                }
                confirmModal?.classList.add('is-open');
                confirmModal?.setAttribute('aria-hidden', 'false');
            });
        });

        confirmCancel?.addEventListener('click', () => {
            pendingForm = null;
            confirmModal?.classList.remove('is-open');
            confirmModal?.setAttribute('aria-hidden', 'true');
        });

        confirmAccept?.addEventListener('click', () => {
            const submitForm = pendingForm;
            pendingForm = null;
            confirmModal?.classList.remove('is-open');
            confirmModal?.setAttribute('aria-hidden', 'true');
            submitForm?.submit();
        });
    </script>
</body>
</html>
