@php
    $recipientPanel = is_array($reportRecipients ?? null) ? $reportRecipients : ['users' => [], 'totals' => []];
    $recipientUsers = is_array($recipientPanel['users'] ?? null) ? $recipientPanel['users'] : [];
    $recipientTotals = is_array($recipientPanel['totals'] ?? null) ? $recipientPanel['totals'] : [];
    usort($recipientUsers, static function (array $left, array $right): int {
        $leftPending = empty($left['has_telegram']) || trim((string) ($left['redmine_id'] ?? '')) === '' ? 0 : 1;
        $rightPending = empty($right['has_telegram']) || trim((string) ($right['redmine_id'] ?? '')) === '' ? 0 : 1;
        if ($leftPending !== $rightPending) {
            return $leftPending <=> $rightPending;
        }

        $leftName = mb_strtolower(trim((string) (($left['nombre'] ?? '').' '.($left['apellido'] ?? ''))));
        $rightName = mb_strtolower(trim((string) (($right['nombre'] ?? '').' '.($right['apellido'] ?? ''))));

        return $leftName <=> $rightName;
    });
@endphp

<section class="nova-report-recipients" data-report-recipient-panel>
    <input type="hidden" name="report_recipients_configured" value="1">

    <header class="nova-report-recipients-head">
        <div class="nova-report-recipients-title">
            <span class="nova-report-recipients-icon"><i class="bi bi-people-fill"></i></span>
            <div>
                <small>Usuarios</small>
                <h3>Destinatarios y jefaturas</h3>
            </div>
        </div>
        <div class="nova-report-recipient-stats" aria-label="Resumen de destinatarios">
            <div><strong data-report-stat="recipients">{{ (int) ($recipientTotals['recipients'] ?? 0) }}</strong><span>Informes</span></div>
            <div><strong data-report-stat="managers">{{ (int) ($recipientTotals['managers'] ?? 0) }}</strong><span>Jefaturas</span></div>
            <div class="{{ (int) ($recipientTotals['missing_telegram'] ?? 0) > 0 ? 'is-warning' : '' }}"><strong>{{ (int) ($recipientTotals['missing_telegram'] ?? 0) }}</strong><span>Sin Telegram</span></div>
        </div>
    </header>

    <div class="nova-report-recipient-toolbar">
        <label class="nova-report-recipient-search">
            <i class="bi bi-search"></i>
            <span class="visually-hidden">Buscar usuario</span>
            <input type="search" class="form-control" placeholder="Buscar por nombre, usuario o rol" autocomplete="off" data-report-user-search>
        </label>
        <div class="nova-report-filter-tabs" role="group" aria-label="Filtrar usuarios">
            <button type="button" class="is-active" data-report-filter="all" aria-pressed="true">Todos</button>
            <button type="button" data-report-filter="individual" aria-pressed="false">Con informe</button>
            <button type="button" data-report-filter="manager" aria-pressed="false">Jefaturas</button>
            <button type="button" data-report-filter="missing_telegram" aria-pressed="false">Sin Telegram</button>
            <button type="button" data-report-filter="missing_redmine" aria-pressed="false">Sin Redmine</button>
        </div>
    </div>

    @if ($recipientUsers === [])
        <div class="nova-empty-state">
            <i class="bi bi-person-plus"></i>
            <h3>No hay usuarios disponibles</h3>
            <p>Otorga acceso al módulo desde Administración para incorporarlos a esta nómina.</p>
        </div>
    @else
        <div class="nova-report-recipient-table" role="table" aria-label="Administración de destinatarios">
            <div class="nova-report-recipient-row is-header" role="row">
                <span role="columnheader">Usuario</span>
                <span role="columnheader">Telegram</span>
                <span role="columnheader">Informe individual</span>
                <span role="columnheader">Jefatura</span>
            </div>
            @foreach ($recipientUsers as $recipientUser)
                @php
                    $userId = (int) ($recipientUser['id'] ?? 0);
                    $fullName = trim((string) (($recipientUser['nombre'] ?? '').' '.($recipientUser['apellido'] ?? '')));
                    $fullName = $fullName !== '' ? $fullName : (string) ($recipientUser['usuario'] ?? 'Usuario');
                    $initials = collect(preg_split('/\s+/u', $fullName) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr((string) $part, 0, 1)))->implode('');
                    $hasTelegram = ! empty($recipientUser['has_telegram']);
                    $hasRedmine = trim((string) ($recipientUser['redmine_id'] ?? '')) !== '';
                    $canReceive = $hasTelegram && $hasRedmine;
                    $searchText = mb_strtolower($fullName.' '.($recipientUser['usuario'] ?? '').' '.($recipientUser['rol'] ?? ''));
                @endphp
                <div class="nova-report-recipient-row {{ $canReceive ? '' : 'has-configuration-warning' }}" role="row" data-report-user-row data-search="{{ $searchText }}" data-has-telegram="{{ $hasTelegram ? '1' : '0' }}" data-has-redmine="{{ $hasRedmine ? '1' : '0' }}">
                    <div class="nova-report-user" role="cell">
                        <span class="nova-report-user-avatar">{{ $initials ?: 'U' }}</span>
                        <span>
                            <strong>{{ $fullName }}</strong>
                            <small>{{ $recipientUser['usuario'] ?? 'Sin usuario' }} · {{ ucfirst((string) ($recipientUser['rol'] ?? 'usuario')) }}</small>
                        </span>
                    </div>
                    <div role="cell" data-label="Telegram">
                        @if ($hasTelegram)
                            <span class="nova-status-badge is-success"><i class="bi bi-telegram"></i>Configurado</span>
                        @else
                            <span class="nova-status-badge is-warning"><i class="bi bi-exclamation-triangle"></i>Pendiente</span>
                        @endif
                    </div>
                    <label class="nova-report-role-toggle {{ $canReceive ? '' : 'is-disabled' }}" role="cell" data-label="Informe individual">
                        <input class="rm-switch" type="checkbox" name="report_recipients[]" value="{{ $userId }}" data-report-recipient-toggle @checked(!empty($recipientUser['receives_report']) && $canReceive) @disabled(!$canReceive)>
                        <span>
                            <strong>Recibe sus tickets</strong>
                            @unless ($hasRedmine)<small>Falta ID Redmine</small>@endunless
                        </span>
                    </label>
                    <label class="nova-report-role-toggle {{ $hasTelegram ? 'is-manager' : 'is-disabled' }}" role="cell" data-label="Jefatura">
                        <input class="rm-switch" type="checkbox" name="report_managers[]" value="{{ $userId }}" data-report-manager-toggle @checked(!empty($recipientUser['is_manager']) && $hasTelegram) @disabled(!$hasTelegram)>
                        <span>
                            <strong>Resumen de equipo</strong>
                        </span>
                    </label>
                </div>
            @endforeach
        </div>
        <footer class="nova-report-pagination" aria-label="Paginación de usuarios">
            <label class="nova-report-page-size">
                <span>Mostrar</span>
                <select class="form-select" data-report-page-size aria-label="Usuarios por página">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                <span>usuarios</span>
            </label>
            <div class="nova-report-page-navigation">
                <span data-report-page-summary></span>
                <button class="btn-nova btn-nova-secondary" type="button" data-report-page-prev aria-label="Página anterior"><i class="bi bi-chevron-left"></i></button>
                <strong data-report-page-status aria-live="polite"></strong>
                <button class="btn-nova btn-nova-secondary" type="button" data-report-page-next aria-label="Página siguiente"><i class="bi bi-chevron-right"></i></button>
            </div>
        </footer>
        <div class="nova-report-dirty-bar" data-report-dirty-bar hidden>
            <span><i class="bi bi-pencil-square"></i><strong data-report-dirty-count>0 cambios pendientes</strong></span>
            <div>
                <button class="btn-nova btn-nova-secondary" type="button" data-report-discard>Descartar</button>
                <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i>Guardar cambios</button>
            </div>
        </div>
    @endif
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-report-recipient-panel]').forEach((panel) => {
        const rows = Array.from(panel.querySelectorAll('[data-report-user-row]'));
        const search = panel.querySelector('[data-report-user-search]');
        const pageSizeSelect = panel.querySelector('[data-report-page-size]');
        const previousButton = panel.querySelector('[data-report-page-prev]');
        const nextButton = panel.querySelector('[data-report-page-next]');
        const pageSummary = panel.querySelector('[data-report-page-summary]');
        const pageStatus = panel.querySelector('[data-report-page-status]');
        const filterButtons = Array.from(panel.querySelectorAll('[data-report-filter]'));
        const dirtyBar = panel.querySelector('[data-report-dirty-bar]');
        const dirtyCount = panel.querySelector('[data-report-dirty-count]');
        const recipients = Array.from(panel.querySelectorAll('[data-report-recipient-toggle]'));
        const managers = Array.from(panel.querySelectorAll('[data-report-manager-toggle]'));
        const roleInputs = [...recipients, ...managers];
        const initialValues = new Map(roleInputs.map((input) => [input, input.checked]));
        let currentPage = 1;
        let activeFilter = 'all';
        const updateStats = () => {
            const recipientStat = panel.querySelector('[data-report-stat="recipients"]');
            const managerStat = panel.querySelector('[data-report-stat="managers"]');
            if (recipientStat) recipientStat.textContent = String(recipients.filter((input) => input.checked).length);
            if (managerStat) managerStat.textContent = String(managers.filter((input) => input.checked).length);
        };
        const updateDirtyState = () => {
            const changes = roleInputs.filter((input) => input.checked !== initialValues.get(input)).length;
            if (dirtyBar) dirtyBar.hidden = changes === 0;
            if (dirtyCount) dirtyCount.textContent = `${changes} ${changes === 1 ? 'cambio pendiente' : 'cambios pendientes'}`;
        };
        const matchesActiveFilter = (row) => {
            if (activeFilter === 'individual') return Boolean(row.querySelector('[data-report-recipient-toggle]')?.checked);
            if (activeFilter === 'manager') return Boolean(row.querySelector('[data-report-manager-toggle]')?.checked);
            if (activeFilter === 'missing_telegram') return row.dataset.hasTelegram !== '1';
            if (activeFilter === 'missing_redmine') return row.dataset.hasRedmine !== '1';
            return true;
        };
        const renderPage = () => {
            const query = search?.value.trim().toLocaleLowerCase('es') || '';
            const pageSize = Number.parseInt(pageSizeSelect?.value || '10', 10);
            const matchingRows = rows.filter((row) => (query === '' || String(row.dataset.search || '').includes(query)) && matchesActiveFilter(row));
            const totalPages = Math.max(1, Math.ceil(matchingRows.length / pageSize));
            currentPage = Math.min(Math.max(1, currentPage), totalPages);
            const start = (currentPage - 1) * pageSize;
            const end = Math.min(start + pageSize, matchingRows.length);
            const visibleRows = new Set(matchingRows.slice(start, end));

            rows.forEach((row) => { row.hidden = !visibleRows.has(row); });
            if (pageSummary) pageSummary.textContent = matchingRows.length === 0 ? '0 usuarios' : `${start + 1}-${end} de ${matchingRows.length}`;
            if (pageStatus) pageStatus.textContent = `Página ${currentPage} de ${totalPages}`;
            if (previousButton) previousButton.disabled = currentPage === 1;
            if (nextButton) nextButton.disabled = currentPage === totalPages;
        };
        roleInputs.forEach((input) => input.addEventListener('change', () => {
            updateStats();
            updateDirtyState();
            renderPage();
        }));
        search?.addEventListener('input', () => {
            currentPage = 1;
            renderPage();
        });
        pageSizeSelect?.addEventListener('change', () => {
            currentPage = 1;
            renderPage();
        });
        filterButtons.forEach((button) => button.addEventListener('click', () => {
            activeFilter = button.dataset.reportFilter || 'all';
            filterButtons.forEach((candidate) => {
                const selected = candidate === button;
                candidate.classList.toggle('is-active', selected);
                candidate.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            currentPage = 1;
            renderPage();
        }));
        panel.querySelector('[data-report-discard]')?.addEventListener('click', () => {
            roleInputs.forEach((input) => { input.checked = Boolean(initialValues.get(input)); });
            updateStats();
            updateDirtyState();
            renderPage();
        });
        previousButton?.addEventListener('click', () => { currentPage -= 1; renderPage(); });
        nextButton?.addEventListener('click', () => { currentPage += 1; renderPage(); });
        updateStats();
        updateDirtyState();
        renderPage();
    });

    document.querySelectorAll('[data-report-schedule-form]').forEach((form) => {
        const enabled = form.querySelector('[data-report-schedule-enabled]');
        const day = form.querySelector('[data-report-schedule-day]');
        const time = form.querySelector('[data-report-schedule-time]');
        const preview = form.querySelector('[data-report-next-run]');
        const dayLabels = { 1: 'lunes', 2: 'martes', 3: 'miércoles', 4: 'jueves', 5: 'viernes', 6: 'sábado', 7: 'domingo' };
        const syncScheduleState = () => {
            const active = Boolean(enabled?.checked);
            [day, time].forEach((field) => { if (field) field.disabled = !active; });
            form.classList.toggle('is-paused', !active);
            if (preview) preview.innerHTML = active
                ? `<i class="bi bi-calendar-check"></i><span>Próximo envío: <strong>${dayLabels[day?.value] || 'lunes'} a las ${time?.value || '09:00'}</strong></span>`
                : '<i class="bi bi-pause-circle"></i><span>Los mensajes automáticos están pausados.</span>';
        };
        enabled?.addEventListener('change', syncScheduleState);
        day?.addEventListener('change', syncScheduleState);
        time?.addEventListener('input', syncScheduleState);
        syncScheduleState();
    });
});
</script>
