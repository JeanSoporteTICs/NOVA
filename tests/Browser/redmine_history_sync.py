"""Run with Python Playwright + Chromium; no web server, credentials or Redmine calls."""
import os
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'public/assets/redmine-history-sync.js'
HTML = '''<style>.d-none{display:none!important}</style><div id="panel" class="historico-redmine-sync d-none" role="status">
<div class="historico-redmine-sync__header"><span class="historico-redmine-sync__title"><i class="bi bi-arrow-repeat"></i><span data-redmine-sync-title>Consultando estados en Redmine</span></span><b id="redmine-sync-count"></b></div>
<div class="progress"><div id="redmine-sync-bar" class="progress-bar"></div></div></div>
<span data-redmine-id="1" data-saved-status="Nueva">Nueva</span><span data-redmine-id="2" data-saved-status="En curso">En curso</span>'''
START = '''() => NovaRedmineHistory.start({endpoint:'https://example.test/history',
 badges:[...document.querySelectorAll('[data-redmine-id]')], panel:document.querySelector('#panel'),
 count:document.querySelector('#redmine-sync-count'), bar:document.querySelector('#redmine-sync-bar'),
 render:(badge,status)=>{if(status.available)badge.dataset.savedStatus=status.name; badge.textContent=badge.dataset.savedStatus;}
})'''

with sync_playwright() as p:
    executable = os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE')
    browser = p.chromium.launch(headless=True, **({'executable_path': executable} if executable else {}))
    try:
        page = browser.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.set_content(HTML)
        page.add_style_tag(path=str(ROOT / 'public/assets/nova-ui.css'))
        page.add_style_tag(path=str(ROOT / 'public/assets/redmine-tic-history.css'))
        page.add_script_tag(path=str(SCRIPT))
        page.evaluate('''() => { window.calls=[]; window.fetch=async url=>{
          calls.push(new URL(url).searchParams.get('ids'));
          return {ok:true,json:async()=>calls.length===1
           ? {statuses:{1:{available:true,name:'Cerrada'}},pending_ids:['2'],complete:false,error:'Servicio caído'}
           : {statuses:{2:{available:true,name:'Resuelta'}},pending_ids:[],complete:true,error:''}};
        }; }''')
        page.evaluate(START)
        page.get_by_role('button', name='Continuar sincronización').wait_for(state='visible')
        assert page.locator('#redmine-sync-count').inner_text() == '1/2'
        assert page.locator('#panel').get_attribute('data-sync-state') == 'paused'
        assert page.locator('[data-redmine-sync-title]').inner_text() == 'Quedan estados por revisar'
        assert page.locator('[data-redmine-id="2"]').inner_text() == 'En curso'
        page.get_by_role('button', name='Continuar sincronización').click()
        page.get_by_role('button', name='Continuar sincronización').wait_for(state='hidden')
        assert page.evaluate('calls') == ['1,2', '2']
        assert page.locator('#redmine-sync-count').inner_text() == '2/2'
        assert page.locator('#panel').get_attribute('data-sync-state') == 'complete'
        assert page.locator('[data-redmine-sync-title]').inner_text() == 'Consulta de Redmine finalizada'
        assert page.locator('#panel .progress').is_hidden()
        assert page.locator('#panel button').is_hidden()
        assert page.locator('#panel .historico-redmine-sync__title i').evaluate('(el) => getComputedStyle(el).animationName') == 'none'
        page.screenshot(path='/tmp/nova-history-sync-complete.png')
        assert page.locator('[data-redmine-id="2"]').inner_text() == 'Resuelta'

        page.set_content(HTML)
        page.evaluate('''() => {window.calls=[];window.fetch=async url=>{calls.push(url);return {ok:false,status:503};};}''')
        page.evaluate(START)
        page.get_by_role('button', name='Reintentar consulta').wait_for(state='visible')
        assert page.locator('#panel').get_attribute('data-sync-state') == 'paused'
        assert page.locator('[data-redmine-sync-title]').inner_text() == 'No se pudo completar la consulta'
        assert len(page.evaluate('calls')) == 1
        assert page.locator('#redmine-sync-count').inner_text() == '0/2'
        assert page.locator('[data-redmine-id="1"]').inner_text() == 'Nueva'

        page.set_content(HTML)
        page.evaluate('''() => {window.calls=[];window.fetch=async url=>{
          calls.push(url);return calls.length === 1
            ? {ok:false,status:503}
            : {ok:true,json:async()=>({statuses:{1:{available:true,name:'Cerrada'},2:{available:true,name:'Resuelta'}},pending_ids:[],complete:true})};
        };}''')
        page.evaluate(START)
        page.get_by_role('button', name='Reintentar consulta').wait_for(state='visible')
        page.evaluate(START)
        page.wait_for_function("document.querySelector('#panel').dataset.syncState === 'complete'")
        assert page.locator('#panel button').count() == 1
        assert page.locator('#panel .historico-redmine-sync__message').count() == 1
        assert page.locator('#panel button').is_hidden()
        assert len(page.evaluate('calls')) == 2

        page.clock.install()
        page.set_content(HTML)
        page.evaluate('''() => {window.aborted=false;window.fetch=(url,options)=>new Promise((resolve,reject)=>{
          options.signal.addEventListener('abort',()=>{window.aborted=true;reject(new DOMException('Timeout','AbortError'));});
        });}''')
        page.evaluate(START)
        page.clock.fast_forward(30000)
        page.get_by_role('button', name='Reintentar consulta').wait_for(state='visible')
        assert page.evaluate('aborted') is True
        assert '30 segundos' in page.locator('#panel').inner_text()
        assert page.locator('[data-redmine-id="2"]').inner_text() == 'En curso'
        full_view = (ROOT / 'RedmineTic/views/native-sections/history.blade.php').read_text()
        start = full_view.index("    document.querySelector('[data-history-full-sync]')")
        end = full_view.index("    const modal = document.getElementById('historicoDetalleModal');", start)
        page.set_content('<form data-history-full-sync data-confirm-accepted="1" action="https://example.test/history"><input name="action" value="sync_redmine_statuses"><button>Sincronizar todos</button></form>')
        page.evaluate("() => { window.NovaToast={warning:()=>{},error:()=>{}}; window.appUi={setLoading:()=>{},setIntegrationLoading:()=>{}}; window.calls=[]; window.fetch=async(url,opts)=>{calls.push(opts.method);return {ok:true,json:async()=>({ok:false,complete:false,error:'Parcial',message:'Continúa'})};}; }")
        page.add_script_tag(content=full_view[start:end])
        page.get_by_role('button', name='Sincronizar todos').click()
        page.get_by_role('button', name='Continuar sincronización completa').wait_for(state='visible')
        assert page.evaluate('calls') == ['POST']
        assert page.get_by_role('button', name='Continuar sincronización completa').is_enabled()
        mant_page = browser.new_page()
        mant_page.on('pageerror', lambda e: errors.append(str(e)))
        mant_page.set_content(HTML)
        mant_page.add_style_tag(path=str(ROOT / 'RedmineMantencion/assets/theme.css'))
        mant_page.add_style_tag(path=str(ROOT / 'public/assets/nova-ui.css'))
        mant_page.add_script_tag(path=str(SCRIPT))
        mant_page.evaluate('''() => {window.fetch=async()=>({ok:true,json:async()=>({
          statuses:{1:{available:true,name:'Cerrada'},2:{available:true,name:'Resuelta'}},
          pending_ids:[],complete:true
        })});}''')
        mant_page.evaluate(START)
        mant_page.wait_for_function("document.querySelector('#panel').dataset.syncState === 'complete'")
        assert mant_page.locator('#panel button').is_hidden()
        assert mant_page.locator('#panel .progress').is_hidden()
        assert mant_page.locator('#panel .historico-redmine-sync__title i').evaluate('(el) => getComputedStyle(el).animationName') == 'none'
        mant_page.screenshot(path='/tmp/nova-history-sync-mantencion.png')
        mant_page.close()

        assert not errors, errors
        print('OK: estados activo/pausado/finalizado, reintento, reinicio sin duplicados y límite de 30 segundos en Chromium; TIC y Mantención.')
    finally:
        browser.close()
