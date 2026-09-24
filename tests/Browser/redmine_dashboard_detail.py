"""Exercise the production dashboard detail handlers in Chromium with synthetic data.

No application server, credentials, database, or external requests are used.
Run with Python Playwright; PLAYWRIGHT_CHROMIUM_EXECUTABLE may select a cached browser.
"""
import os
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[2]
TIC = (ROOT / 'RedmineTic/views/native-sections/dashboard.blade.php').read_text()
MANT = (ROOT / 'resources/views/redmine-mantencion/dashboard.blade.php').read_text()


def between(source, start, end):
    first = source.index(start)
    return source[first:source.index(end, first)]


TIC_HANDLER = between(TIC, '    const dashboardDetailUrl =', '    const restoreDashboardScroll =')
TIC_HANDLER = TIC_HANDLER.replace("@json($redmineRoute('redmine.native.dashboard.detail'))", "'http://localhost/tic-detail'")
MANT_HANDLER = between(MANT, '  let dashboardDetailRequest = 0;', "  if (openPreviewModalBtn) {\n    openPreviewModalBtn.addEventListener")
MANT_HANDLER = MANT_HANDLER.replace("<?= json_encode(route('redmine.mantencion.dashboard.detail'), JSON_UNESCAPED_SLASHES) ?>", "'http://localhost/mant-detail'")
MANT_HANDLER = MANT_HANDLER.replace("<?= $maintenanceMode ? 'true' : 'false' ?>", 'false')


def deferred_fetch(page):
    page.evaluate('''() => {
      window.requests=[];
      window.fetch=(url, options)=>new Promise((resolve,reject)=>{
        requests.push({url:String(url),options,resolve,reject});
      });
    }''')


def resolve(page, index, body, ok=True):
    page.evaluate('''([index,body,ok]) => {
      requests[index].resolve({ok,json:async()=>body});
    }''', [index, body, ok])


def test_tic(browser, errors):
    page = browser.new_page()
    page.on('pageerror', lambda error: errors.append(str(error)))
    fields = ['id','tipo','estado','asunto','prioridad','categoria','solicitante','unidad',
              'unidad_solicitante','asignado_a','hora_extra','fecha_inicio','fecha_fin',
              'tiempo_estimado','fecha','hora','mensaje','descripcion']
    controls = ''.join(f'<input name="{name}">' for name in fields)
    page.set_content('''<button id="open-1" data-nova-modal-open="editar-solicitud" data-report-id="1" data-report-asunto="Uno"></button>
        <button id="open-2" data-nova-modal-open="editar-solicitud" data-report-id="2" data-report-asunto="Dos"></button>
        <div id="editar-solicitud" aria-hidden="true"><form>''' + controls + '''<div data-dashboard-detail-status hidden></div>
        <button type="submit">Guardar</button></form></div>''')
    deferred_fetch(page)
    page.add_script_tag(content='''let currentDashboardAssigneeId='',ticDashboardDescriptionTabs=null;
      let processedActionsEnabled=true;
      const setDashboardSelectValue=(control,value)=>{control.value=value};
      const syncDashboardEstimatedTime=()=>{};
      const writeDashboardViewState=()=>{};
    ''' + TIC_HANDLER)
    page.locator('#open-1').click()
    expect(page.locator('#editar-solicitud [type="submit"]')).to_be_disabled()
    expect(page.locator('[data-dashboard-detail-status]')).to_contain_text('Cargando')
    assert page.evaluate('requests.length') == 1, errors
    assert page.evaluate('requests[0].url.endsWith("id=1")')
    assert page.evaluate('requests[0].options.cache') == 'no-store'
    page.locator('#open-2').click()
    resolve(page, 0, {'mensaje':'Viejo','descripcion':'Vieja'})
    expect(page.locator('#editar-solicitud [type="submit"]')).to_be_disabled()
    resolve(page, 1, {'mensaje':'Nuevo','descripcion':'Nueva'})
    expect(page.locator('#editar-solicitud [type="submit"]')).to_be_enabled()
    expect(page.locator('[name="mensaje"]')).to_have_value('Nuevo')
    expect(page.locator('[name="descripcion"]')).to_have_value('Nueva')
    page.locator('#open-1').click()
    resolve(page, 2, {}, False)
    expect(page.locator('#editar-solicitud [type="submit"]')).to_be_disabled()
    expect(page.locator('[data-dashboard-detail-status]')).to_contain_text('No se pudo')
    assert not errors, errors
    page.close()


def test_mantencion(browser, errors):
    page = browser.new_page()
    page.on('pageerror', lambda error: errors.append(str(error)))
    ids = ['md-id','md-tipo','md-tipo-hidden','md-estado','md-estado-hidden',
           'md-asunto','md-prioridad','md-prioridad-hidden','md-categoria',
           'md-asignado','md-solicitante','md-establecimiento','md-departamento',
           'md-hora_extra','md-fecha_inicio','md-fecha_fin','md-tiempo_estimado',
           'md-fecha','md-hora','md-numero','md-descripcion','md-core_email']
    controls = ''.join(f'<input id="{name}">' for name in ids)
    page.set_content('''<button id="open-1" data-dashboard-id="1" data-id="public-1" data-fuente="manual"></button>
      <button id="open-2" data-dashboard-id="2" data-id="public-2" data-fuente="manual"></button>
      <button id="open-core" data-dashboard-id="3" data-id="core-3" data-fuente="core"
              data-core_tipo_solicitud="Modificar Usuario"></button>
      <div id="detalleModal"><div class="modal-body"></div><form>''' + controls + '''
      <div data-dashboard-detail-status hidden></div><button type="submit">Guardar</button></form></div>
      <button id="open-preview-modal-btn"></button><button id="open-descripcion-modal-btn"></button>
      <table><thead id="md-preview-head"></thead><tbody id="md-preview-body"></tbody></table>''')
    deferred_fetch(page)
    page.add_script_tag(content='''const detalleModal=document.getElementById('detalleModal');
      const openPreviewModalBtn=document.getElementById('open-preview-modal-btn');
      const setDrawerView=()=>{};
      const setMantencionSelectValue=(control,value)=>{if(control) control.value=value};
      const syncMantencionDashboardHours=()=>{};
    ''' + MANT_HANDLER)
    page.evaluate("const e=new Event('show.bs.modal');e.relatedTarget=document.getElementById('open-1');document.getElementById('detalleModal').dispatchEvent(e)")
    assert page.evaluate('requests.length') == 1, errors
    expect(page.locator('#detalleModal [type="submit"]')).to_be_disabled()
    assert page.evaluate('requests[0].url.endsWith("database_id=1")')
    page.evaluate("const e=new Event('show.bs.modal');e.relatedTarget=document.getElementById('open-2');document.getElementById('detalleModal').dispatchEvent(e)")
    resolve(page, 0, {'descripcion':'Viejo','preview_rows':[],'preview_columns':[]})
    expect(page.locator('#detalleModal [type="submit"]')).to_be_disabled()
    resolve(page, 1, {'descripcion':'Nuevo','preview_rows':[{'detalle_descripcion':'Nueva vista'}],
                      'preview_columns':[{'label':'Descripción','key':'detalle_descripcion'}]})
    expect(page.locator('#detalleModal [type="submit"]')).to_be_enabled()
    expect(page.locator('#md-descripcion')).to_have_value('Nuevo')
    expect(page.locator('#md-preview-body')).to_contain_text('Nueva vista')
    page.evaluate("const e=new Event('show.bs.modal');e.relatedTarget=document.getElementById('open-1');document.getElementById('detalleModal').dispatchEvent(e)")
    resolve(page, 2, {}, False)
    expect(page.locator('#detalleModal [type="submit"]')).to_be_disabled()
    expect(page.locator('[data-dashboard-detail-status]')).to_contain_text('No se pudo')
    page.evaluate("const e=new Event('show.bs.modal');e.relatedTarget=document.getElementById('open-core');document.getElementById('detalleModal').dispatchEvent(e)")
    resolve(page, 3, {
        'descripcion': 'Solicitud CORE',
        'preview_rows': [{
            'detalle_tipo_solicitud': 'Modificar Perfil',
            'detalle_run': '12345678-9',
            'detalle_nombre': 'Persona de Prueba',
            'detalle_motivo': 'Realiza turnos en UTI 2',
            'detalle_establecimientos': '-',
            'detalle_otros_permisos': 'Favor activar módulo RCH'
        }],
        'preview_columns': []
    })
    expect(page.locator('#md-preview-head')).to_contain_text('Establecimientos')
    expect(page.locator('#md-preview-body')).to_contain_text('Persona de Prueba')
    expect(page.locator('#md-preview-body')).to_contain_text('12345678-9')
    expect(page.locator('#md-preview-body')).to_contain_text('Favor activar módulo RCH')
    assert page.locator('#md-preview-body td').count() == 6
    assert not errors, errors
    page.close()


with sync_playwright() as playwright:
    executable = os.environ.get('PLAYWRIGHT_CHROMIUM_EXECUTABLE')
    browser = playwright.chromium.launch(headless=True, **({'executable_path': executable} if executable else {}))
    errors = []
    try:
        test_tic(browser, errors)
        test_mantencion(browser, errors)
        print('OK: modales TIC/Mantención, carga diferida, respuesta obsoleta, éxito y error en Chromium.')
    finally:
        browser.close()
