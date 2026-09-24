"""Archive feedback in real Chromium with production JS, synthetic DOM and deferred responses.
Run with Python Playwright. Optional PLAYWRIGHT_CHROMIUM_EXECUTABLE selects an installed browser.
No application server, credentials, database or real archive requests are used.
"""
import os
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[2]
MANT = (ROOT / 'resources/views/redmine-mantencion/dashboard.blade.php').read_text()
TIC = (ROOT / 'RedmineTic/views/native-sections/dashboard.blade.php').read_text()
HELPER = (ROOT / 'public/assets/nova-ui.js').read_text().split('// Shared feedback for dashboard archive requests')[1]
OVERLAY = '''<div class="nova-integration-overlay" id="nova-integration-overlay" role="status" aria-live="polite" aria-hidden="true">
<div class="nova-integration-card"><span class="nova-integration-icon"><i></i></span>
<strong id="nova-integration-title"></strong><span id="nova-integration-detail"></span>
<div class="nova-integration-bar"><i></i></div></div></div>'''


def between(source, start, end):
    i = source.index(start)
    return source[i:source.index(end, i)]


def setup(page, module):
    page.set_content(OVERLAY + '''<form id="process-form" method="post" action="https://example.test/archive" data-dashboard-bulk-form>
    <input name="_token" value="synthetic-token"><input id="process-action" name="action" value="archive_selected">
    <input name="dashboard_action" value="delete_selected"><input id="process-ids" name="ids" value="1,2">
    </form><button id="archive-btn" type="button">Archivar</button>
    <button id="process-btn">Enviar</button><button id="delete-selected-btn" disabled>Eliminar</button><button id="reset-errors-btn">Reintentar</button>
    <nav id="status-filters"><a data-filter="procesado" class="is-active"></a></nav>
    <table class="dashboard-table"><tbody><tr data-id="1" data-status="procesado"><td><input class="msg-check" type="checkbox" value="1" checked></td></tr>
    <tr data-id="2" data-status="procesado"><td><input class="msg-check" type="checkbox" value="2" checked></td></tr>
    <tr data-id="3" data-status="procesado" style="display:none"><td><input class="msg-check" type="checkbox" value="3" checked></td></tr></tbody></table>''')
    page.add_style_tag(path=str(ROOT / 'public/assets/nova-ui.css'))
    page.evaluate('''() => {
        window.appUi={setLoading: value => window.loading=value};
        window.messages=[]; window.NovaToast={show: value=>messages.push(value), warning: message=>messages.push({message})};
        window.sent=[]; window.fetch=(url, options)=>{
            sent.push(Object.fromEntries(options.body));
            return new Promise((resolve,reject)=>{window.complete=resolve;window.fail=reject;});
        };
        HTMLFormElement.prototype.submit=function(){sent.push(Object.fromEntries(new FormData(this)));};
    }''')
    if module == 'tic':
        native = (ROOT / 'RedmineTic/views/native.blade.php').read_text()
        overlay_js = between(native, '        const integrationOverlay =', '        const integrationCopyForForm')
    else:
        native = (ROOT / 'RedmineMantencion/views/partials/navbar.php').read_text()
        overlay_js = "const integrationOverlay = document.getElementById('nova-integration-overlay');\n" + between(native, '  window.appUi.setIntegrationLoading =', '  // Toda navegación')
    page.add_script_tag(content=overlay_js)
    page.add_script_tag(content='// Shared feedback for dashboard archive requests' + HELPER)


def busy(page):
    expect(page.locator('#nova-integration-overlay')).to_be_visible()
    expect(page.locator('#nova-integration-title')).to_have_text('Archivando reportes')
    expect(page.locator('#nova-integration-detail')).to_contain_text('2 reportes')
    expect(page.locator('#archive-btn')).to_be_disabled()
    expect(page.locator('#archive-btn')).to_contain_text('Archivando')
    expect(page.locator('#process-form')).to_have_attribute('aria-busy', 'true')


with sync_playwright() as p:
    executable = os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE')
    browser = p.chromium.launch(headless=True, **({'executable_path': executable} if executable else {}))
    errors = []
    try:
        for outcome in ['success', 'http_error', 'network_error', 'invalid_json']:
            page = browser.new_page()
            page.on('pageerror', lambda error: errors.append(str(error)))
            setup(page, 'mantencion')
            page.add_script_tag(content='''const dashboardMaintenanceMode=false;
                function refreshDashboardCounters(){
                    document.getElementById('archive-btn').disabled = !document.querySelector('tr[data-id="1"]');
                }
            ''' + between(MANT, 'function escapeDashboardId', 'async function submitDashboardAction')
                + between(MANT, 'async function submitDashboardBulkAction', "document.querySelectorAll('form[data-dashboard-ajax=")
                + between(MANT, "const processForm = document.getElementById('process-form');", 'function filterRows')
                + between(MANT, "const archiveBtn = document.getElementById('archive-btn');\nif (archiveBtn", 'const deleteSelectedBtn ='))
            page.locator('#archive-btn').click()
            busy(page)
            # Even a programmatic second submission must not create a second request.
            page.evaluate("document.getElementById('process-form').requestSubmit()")
            assert len(page.evaluate('sent')) == 1
            payload = page.evaluate('sent[0]')
            assert payload['ids'] == '1,2' and payload['_token'] == 'synthetic-token' and payload['action'] == 'archive_selected'
            page.screenshot(path='/tmp/nova-archive-mantencion.png')
            if outcome == 'success':
                page.evaluate("complete({ok:true,json:async()=>({ok:true,action:'archive_selected',ids:['1','2'],message:'2 reportes archivados'})})")
            elif outcome == 'http_error':
                page.evaluate("complete({ok:false,json:async()=>({ok:false,message:'No se pudo archivar'})})")
            elif outcome == 'network_error':
                page.evaluate("fail(new Error('Conexión interrumpida'))")
            else:
                page.evaluate("complete({ok:true,json:async()=>{throw new Error('HTML inesperado')}})")
            expect(page.locator('#nova-integration-overlay')).to_be_hidden()
            expect(page.locator('#archive-btn')).to_have_text('Archivar')
            assert not page.locator('.is-row-updating').count()
            assert not page.locator('#process-form').get_attribute('aria-busy')
            expect(page.locator('#delete-selected-btn')).to_be_disabled()  # Originally disabled.
            if outcome == 'success':
                assert not page.locator('tr[data-id="1"]').count()
                expect(page.locator('#archive-btn')).to_be_disabled()
                assert page.evaluate('messages.at(-1).message') == '2 reportes archivados'
            else:
                expect(page.locator('#archive-btn')).to_be_enabled()
                assert page.locator('tr[data-id="1"]').count() == 1
                assert page.evaluate('messages.at(-1).type') == 'error'
            page.close()

        page = browser.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        setup(page, 'tic')
        page.evaluate('''() => {const button=document.getElementById('archive-btn');
            document.getElementById('process-form').append(button);
            button.type='submit'; button.name='dashboard_action';button.value='archive_selected';}''')
        page.add_script_tag(content='let processedActionsEnabled=true;\n' + between(TIC,
            "    document.querySelector('[data-dashboard-bulk-form]')?.addEventListener('submit'",
            "    document.querySelectorAll('[data-dashboard-error-log-button]')"))
        page.locator('#archive-btn').click()
        busy(page)
        page.evaluate("document.getElementById('process-form').requestSubmit(document.getElementById('archive-btn'))")
        page.wait_for_function('sent.length === 1')
        assert page.evaluate('sent[0].dashboard_action') == 'archive_selected'
        assert page.evaluate('sent[0].ids') == '1,2'
        assert page.evaluate('sent[0]._token') == 'synthetic-token'
        page.screenshot(path='/tmp/nova-archive-tic.png')
        page.evaluate("window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}))")
        expect(page.locator('#nova-integration-overlay')).to_be_hidden()
        expect(page.locator('#archive-btn')).to_be_enabled()
        expect(page.locator('#archive-btn')).to_have_text('Archivar')
        page.evaluate("document.getElementById('process-ids').value=''")
        page.locator('#archive-btn').click()
        assert len(page.evaluate('sent')) == 1
        expect(page.locator('#nova-integration-overlay')).to_be_hidden()
        assert 'Selecciona' in page.evaluate('messages.at(-1).message')
        page.evaluate("processedActionsEnabled=false;document.getElementById('archive-btn').setAttribute('data-processed-action','');document.getElementById('process-ids').value='1,2'")
        page.locator('#archive-btn').click()
        expect(page.locator('#nova-integration-overlay')).to_be_hidden()
        assert len(page.evaluate('sent')) == 1
        page.close()

        page = browser.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        setup(page, 'mantencion')
        fallback = between(MANT, '(function () {\n  if (window.__dashboardBulkControlsReady)', '</script>')
        fallback = fallback.replace("<?= $maintenanceMode ? 'true' : 'false' ?>", 'false')
        page.add_script_tag(content=fallback)
        page.locator('#archive-btn').click()
        # Fallback uses all visible processed rows, including row 3 made visible by its filter.
        expect(page.locator('#nova-integration-overlay')).to_be_visible()
        page.wait_for_function('sent.length === 1')
        assert page.evaluate('sent[0].action') == 'archive_selected'
        assert page.evaluate('sent[0].ids') == '1,2,3'
        page.close()
        assert not errors, errors
        print('OK: Mantención AJAX (éxito, HTTP, red, JSON), TIC POST, selección vacía, bloqueo, doble envío, Atrás y fallback. Sin solicitudes reales.')
    finally:
        browser.close()
