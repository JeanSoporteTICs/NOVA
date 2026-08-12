<?php

use App\Modulos\Nova\Controllers\HoursExtraController;
use App\Modulos\Nova\Controllers\LegacyProjectController;
use App\Modulos\RedmineMantencion\Controllers\DashboardController as MantencionDashboardController;
use App\Modulos\RedmineMantencion\Controllers\HistoricoController as MantencionHistoricoController;
use App\Modulos\RedmineMantencion\Controllers\HorasExtraController as MantencionHorasExtraController;
use App\Modulos\RedmineMantencion\Controllers\PendientesController as MantencionPendientesController;
use App\Modulos\RedmineMantencion\Controllers\UsuariosController as MantencionUsuariosController;
use App\Modulos\RedmineMantencion\Controllers\EstadisticasController as MantencionEstadisticasController;
use App\Modulos\RedmineMantencion\Controllers\ConfiguracionController as MantencionConfiguracionController;
use App\Modulos\RedmineMantencion\Controllers\ActivityController as MantencionActivityController;
use App\Modulos\RedmineMantencion\Controllers\NextcloudUsuariosController as MantencionNextcloudUsuariosController;
use App\Modulos\RedmineMantencion\Controllers\NextcloudHistorialController as MantencionNextcloudHistorialController;
use App\Modulos\RedmineMantencion\Controllers\NextcloudGestionUsuariosController as MantencionNextcloudGestionUsuariosController;
use App\Modulos\Nova\Controllers\ModuleAdminController;
use App\Modulos\Nova\Controllers\NovaAdministrationController;
use App\Modulos\Nova\Controllers\NovaAuthController;
use App\Modulos\Telegram\Controllers\TelegramController;
use App\Modulos\Shared\Controllers\ModuleLogController;
use App\Modulos\Nova\Controllers\UserIntegrationController;
use App\Modulos\Procedimientos\Controllers\ProcedimientosController;
use App\Modulos\MonitorServidores\Controllers\ServerMonitorController;
use App\Modulos\Nova\Repositories\ModuleRegistry;
use App\Modulos\Nova\Repositories\NovaAccessRepository;
use App\Modulos\Nova\Services\ProjectAccessGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RedmineTic\Controllers\RedmineDashboardController;

$modulePattern = implode('|', array_map(
    static fn (string $key): string => preg_quote($key, '/'),
    array_keys(config('modules', []))
));
$legacyModulePattern = implode('|', array_map(
    static fn (string $key): string => preg_quote($key, '/'),
    array_keys(array_filter(config('modules', []), static fn (array $module): bool => ($module['type'] ?? 'legacy') === 'legacy'))
));

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/login', [NovaAuthController::class, 'showLogin'])->name('login');
Route::post('/login', [NovaAuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
Route::post('/logout', [NovaAuthController::class, 'logout'])->name('logout');
Route::post('/session/extend', [NovaAuthController::class, 'extendSession'])
    ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
    ->middleware('throttle:5,1')
    ->name('session.extend');

Route::get('/procedimientos/document/{token}', [ProcedimientosController::class, 'document'])->name('procedimientos.document');
Route::post('/procedimientos/callback/{token}', [ProcedimientosController::class, 'callback'])
    ->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class)
    ->name('procedimientos.callback');

Route::get('/{project}/assets/{path}', [LegacyProjectController::class, 'asset'])
    ->where('project', $modulePattern)
    ->where('path', '.*');

Route::middleware('nova.auth')->group(function () use ($modulePattern, $legacyModulePattern) {
Route::get('/', function (ModuleRegistry $modules, NovaAccessRepository $access) {
    $user = session('nova_user', []);
    $projects = $modules->enabled();
    if (is_array($user)) {
        $projects = array_filter($projects, static fn (array $module, string $key): bool => $access->canAccess($user, $key), ARRAY_FILTER_USE_BOTH);
    }

    return view('nova.home', [
        'projects' => $projects,
        'users' => $modules->userMatrix(),
    ]);
})->name('home');

Route::get('/horas-extra', [HoursExtraController::class, 'index'])->name('horas-extra.index');
Route::post('/horas-extra', [HoursExtraController::class, 'update'])->name('horas-extra.update');
Route::get('/monitoreo-servidores', [ServerMonitorController::class, 'dashboard'])->name('monitor.dashboard');
Route::get('/monitoreo-servidores/estado', [ServerMonitorController::class, 'status'])->name('monitor.status');
Route::get('/monitoreo-servidores/servidores', [ServerMonitorController::class, 'servers'])->name('monitor.servers');
Route::post('/monitoreo-servidores/servidores', [ServerMonitorController::class, 'store'])->name('monitor.servers.store');
Route::post('/monitoreo-servidores/servidores/comprobar-todos', [ServerMonitorController::class, 'checkAll'])->name('monitor.servers.check-all');
Route::post('/monitoreo-servidores/servidores/probar-destino', [ServerMonitorController::class, 'testDestination'])->middleware('throttle:10,1')->name('monitor.servers.test');
Route::get('/monitoreo-servidores/servidores/{server}', [ServerMonitorController::class, 'show'])->whereNumber('server')->name('monitor.servers.show');
Route::put('/monitoreo-servidores/servidores/{server}', [ServerMonitorController::class, 'update'])->whereNumber('server')->name('monitor.servers.update');
Route::delete('/monitoreo-servidores/servidores/{server}', [ServerMonitorController::class, 'destroy'])->whereNumber('server')->name('monitor.servers.destroy');
Route::post('/monitoreo-servidores/servidores/{server}/comprobar', [ServerMonitorController::class, 'check'])->whereNumber('server')->name('monitor.servers.check');
Route::get('/monitoreo-servidores/destinatarios', [ServerMonitorController::class, 'recipients'])->name('monitor.recipients');
Route::post('/monitoreo-servidores/destinatarios', [ServerMonitorController::class, 'updateRecipients'])->name('monitor.recipients.update');
Route::get('/mis-integraciones', [UserIntegrationController::class, 'show'])->defaults('module', 'nova')->name('integrations.nova');
Route::post('/mis-integraciones', [UserIntegrationController::class, 'update'])->defaults('module', 'nova')->name('integrations.nova.update');
Route::get('/procedimientos', [ProcedimientosController::class, 'index'])->name('procedimientos.index');
Route::match(['GET', 'POST'], '/procedimientos/browser', [ProcedimientosController::class, 'browser'])->name('procedimientos.browser');
Route::get('/procedimientos/editor', [ProcedimientosController::class, 'editor'])->name('procedimientos.editor');

Route::get('/admin/modules', [ModuleAdminController::class, 'index'])->name('modules.index');
Route::post('/admin/modules', [ModuleAdminController::class, 'update'])->name('modules.update');
Route::get('/administracion', [NovaAdministrationController::class, 'index'])->name('administracion.index');
Route::get('/administracion/{section}', [NovaAdministrationController::class, 'index'])->name('administracion.section');
Route::post('/administracion/configuracion', [NovaAdministrationController::class, 'updateSettings'])->name('administracion.config.update');
Route::post('/administracion/salud/notificar', [NovaAdministrationController::class, 'notifyHealth'])->name('administracion.health.notify');
Route::post('/administracion/onlyoffice/test', [NovaAdministrationController::class, 'testOnlyOffice'])->name('administracion.onlyoffice.test');
Route::post('/administracion/telegram/listener', [NovaAdministrationController::class, 'telegramListener'])->name('administracion.telegram.listener');
Route::post('/administracion/usuarios', [NovaAdministrationController::class, 'updateUsers'])->name('administracion.users.update');
Route::post('/administracion/accesos', [NovaAdministrationController::class, 'updateAccess'])->name('administracion.access.update');
Route::get('/admin/users', fn () => redirect()->route('administracion.section', 'usuarios'))->name('nova-users.index');
Route::post('/admin/users', [NovaAdministrationController::class, 'updateUsers'])->name('nova-users.update');
Route::get('/usuarios_nova', fn () => redirect()->route('administracion.section', 'usuarios'))->name('nova-users.project');
Route::get('/telegram', [TelegramController::class, 'index'])->name('telegram.index');
Route::get('/telegram/log', [ModuleLogController::class, 'index'])->defaults('module', 'telegram')->name('telegram.log');
Route::get('/telegram/admin', fn () => redirect()->route('administracion.section', 'telegram'))->name('telegram.admin');
Route::get('/telegram/mensajes', fn () => redirect()->route('administracion.section', 'telegram-mensajes'))->name('telegram.messages');
Route::post('/telegram/admin/configuracion', [TelegramController::class, 'updateAdmin'])->name('telegram.admin.update');
Route::post('/telegram/admin/listener', [TelegramController::class, 'listener'])->name('telegram.admin.listener');
Route::post('/telegram/test', [TelegramController::class, 'test'])->name('telegram.test');
Route::get('/emach/configuracion', [UserIntegrationController::class, 'show'])->defaults('module', 'emach')->name('integrations.emach');
Route::post('/emach/configuracion', [UserIntegrationController::class, 'update'])->defaults('module', 'emach')->name('integrations.emach.update');
Route::post('/emach/horas-extra-sugerencia', [UserIntegrationController::class, 'emachOvertimeSuggestion'])->name('emach.overtime-suggestion');
Route::get('/emach', fn (ProjectAccessGuard $access, LegacyProjectController $controller) => $controller->index('emach', $access))->name('emach.index');
Route::get('/emach/log', [ModuleLogController::class, 'index'])->defaults('module', 'emach')->name('emach.log');
Route::post('/emach', fn (Request $request, LegacyProjectController $controller) => $controller->passthrough($request, 'emach', 'index.php'))->name('emach.query');
Route::match(['GET', 'POST'], '/emach/horario.php', fn (Request $request, LegacyProjectController $controller) => $controller->passthrough($request, 'emach', 'horario.php'))->name('emach.schedule');

Route::get('/redmine_tic/health.php', fn () => response()->json([
    'ok' => true,
    'module' => 'redmine_tic',
    'type' => 'native',
    'base_path' => data_get(config('modules.redmine_tic', []), 'path', base_path('redmine_tic')),
]))->name('redmine.health');
Route::get('/redmine_tic/nativo', fn () => redirect()->route('redmine.native.dashboard'));
Route::get('/redmine_tic/nativo/{section}', fn (string $section) => redirect()->route('redmine.native.section', ['section' => $section]));
Route::get('/redmine_tic/app', [RedmineDashboardController::class, 'index'])->name('redmine.native.dashboard');
Route::get('/redmine_tic/app/mis-integraciones', [UserIntegrationController::class, 'show'])->defaults('module', 'redmine_tic')->name('integrations.redmine_tic');
Route::post('/redmine_tic/app/mis-integraciones', [UserIntegrationController::class, 'update'])->defaults('module', 'redmine_tic')->name('integrations.redmine_tic.update');
Route::get('/redmine_tic/app/configuracion', [RedmineDashboardController::class, 'show'])
    ->defaults('section', 'configuracion');
Route::get('/redmine_tic/app/{section}', [RedmineDashboardController::class, 'show'])->name('redmine.native.section');
Route::post('/redmine_tic/app/dashboard', [RedmineDashboardController::class, 'dashboardAction'])->name('redmine.native.dashboard.action');
Route::post('/redmine_tic/app/usuarios', [RedmineDashboardController::class, 'userAction'])->name('redmine.native.users.action');
Route::post('/redmine_tic/app/categorias', [RedmineDashboardController::class, 'categoryAction'])->name('redmine.native.categories.action');
Route::post('/redmine_tic/app/unidades', [RedmineDashboardController::class, 'unitAction'])->name('redmine.native.units.action');
Route::post('/redmine_tic/app/configuracion', [RedmineDashboardController::class, 'configurationAction'])->name('redmine.native.config.action');
Route::get('/redmine_tic/app/historico/estados', [RedmineDashboardController::class, 'historyStatuses'])->name('redmine.native.history.statuses');
Route::post('/redmine_tic/app/historico', [RedmineDashboardController::class, 'historyAction'])->name('redmine.native.history.action');
Route::post('/redmine_tic/app/horas-extra', [RedmineDashboardController::class, 'hoursAction'])->name('redmine.native.hours.action');
Route::post('/redmine_tic/app/actividad', [RedmineDashboardController::class, 'activityAction'])->name('redmine.native.activity.action');
Route::post('/redmine_tic/app/webhook', [RedmineDashboardController::class, 'webhookAction'])->name('redmine.native.webhook.action');
Route::get('/redmine_tic', fn () => redirect()->route('redmine.native.dashboard'))->name('redmine.dashboard');
Route::match(['GET', 'POST'], '/redmine_tic/{path}', fn () => redirect()->route('redmine.native.dashboard'))
    ->where('path', '^(?!(?:app|nativo)(?:/|$)).*')
    ->name('redmine.path');
Route::get('/redmine-mantencion/health.php', fn () => response()->json([
    'ok' => true,
    'module' => 'redmine-mantencion',
    'type' => 'native',
    'base_path' => data_get(config('modules.redmine-mantencion', []), 'path', base_path('redmine-mantencion')),
]))->name('redmine.mantencion.health');
Route::get('/redmine-mantencion', fn () => redirect()->route('redmine.mantencion.dashboard'));
Route::post('/redmine-mantencion/app/dashboard/hora-extra', [MantencionDashboardController::class, 'toggleHoursExtra'])
    ->name('redmine.mantencion.dashboard.hours-extra');
Route::match(['GET', 'POST'], '/redmine-mantencion/app', [MantencionDashboardController::class, 'index'])
    ->name('redmine.mantencion.dashboard');
Route::get('/redmine-mantencion/app/mis-integraciones', [UserIntegrationController::class, 'show'])->defaults('module', 'redmine-mantencion')->name('integrations.redmine_mantencion');
Route::post('/redmine-mantencion/app/mis-integraciones', [UserIntegrationController::class, 'update'])->defaults('module', 'redmine-mantencion')->name('integrations.redmine_mantencion.update');
Route::get('/redmine-mantencion/app/integraciones-nextcloud-usuarios/administrar', [MantencionNextcloudGestionUsuariosController::class, 'index'])
    ->name('redmine.mantencion.nextcloud-users.manage');
Route::get('/redmine-mantencion/app/integraciones-nextcloud-usuarios/administrar/grupo', [MantencionNextcloudGestionUsuariosController::class, 'groupUsers'])
    ->middleware('throttle:30,1')
    ->name('redmine.mantencion.nextcloud-users.group-users');
Route::post('/redmine-mantencion/app/integraciones-nextcloud-usuarios/administrar', [MantencionNextcloudGestionUsuariosController::class, 'update'])
    ->middleware('throttle:10,1')
    ->name('redmine.mantencion.nextcloud-users.update');
Route::match(['GET', 'POST'], '/nc_browser_ajax.php', fn () => redirect()->route('procedimientos.index'))
    ->name('redmine.mantencion.nc-browser-legacy');
Route::match(['GET', 'POST'], '/redmine-mantencion/app/{section}', function (Request $request, MantencionDashboardController $dashboard, MantencionHistoricoController $historico, MantencionHorasExtraController $horasExtra, MantencionPendientesController $pendientes, MantencionUsuariosController $usuarios, MantencionEstadisticasController $estadisticas, MantencionConfiguracionController $configuracion, MantencionActivityController $actividad, MantencionNextcloudUsuariosController $nextcloudUsuarios, MantencionNextcloudHistorialController $nextcloudHistorial, string $section) {
    return match ($section) {
        'dashboard', 'reportes' => $dashboard->index(),
        'historico' => $historico->index(),
        'horas-extra' => $horasExtra->index(),
        'manual', 'pendiente-manual' => $pendientes->index(),
        'usuarios' => $usuarios->index(),
        'estadisticas' => $estadisticas->index(),
        'configuracion' => $configuracion->index(),
        'actividad' => $actividad->index(),
        'integraciones-nextcloud-usuarios' => $nextcloudUsuarios->index(),
        'integraciones-nextcloud-historial' => $nextcloudHistorial->index(),
        'procedimientos' => redirect()->route('procedimientos.index'),
        default => abort(404),
    };
})->name('redmine.mantencion.section');
// Reemplaza el antiguo catch-all `/redmine-mantencion/{path}` (aceptaba
// cualquier ruta bajo views/, controllers/ o la raíz del módulo y la
// resolvía contra el filesystem). Estas son las únicas rutas fuera de
// /app/... que siguen teniendo un consumidor real:
//   - login.php / logout.php: passthrough() ya las intercepta y redirige a
//     las rutas centrales de NOVA (`login`/`logout`); auth.php redirige aquí
//     explícitamente en fallos de CSRF (ver csrf_validate()).
//   - views/Procedimientos/nc_browser_ajax.php: endpoint AJAX del explorador
//     de archivos Nextcloud, usado por RedmineMantencion/views/Procedimientos/
//     _nc_browser.php — incluido tanto por la vista legacy de Procedimientos
//     como por el módulo nativo App\Modulos\Procedimientos (resources/views/
//     procedimientos/index.blade.php hace un include() directo de ese parcial).
//   - views/Integraciones/Nextcloud.php: shim de redirección enlazado desde
//     el mismo parcial (_nc_browser.php) hacia configuración > Nextcloud.
// Cualquier otro archivo bajo RedmineMantencion/ (entrypoints huérfanos como
// index.php/session_touch.php, vistas sin ruta como Categorias/categorias.php)
// deja de ser alcanzable por URL — no tenían ningún enlace real apuntándoles.
Route::match(['GET', 'POST'], '/redmine-mantencion/login.php', fn (Request $request, LegacyProjectController $controller) => $controller->passthrough($request, 'redmine-mantencion', 'login.php'))
    ->name('redmine.mantencion.login-legacy');
Route::match(['GET', 'POST'], '/redmine-mantencion/logout.php', fn (Request $request, LegacyProjectController $controller) => $controller->passthrough($request, 'redmine-mantencion', 'logout.php'))
    ->name('redmine.mantencion.logout-legacy');
Route::match(['GET', 'POST'], '/redmine-mantencion/views/Procedimientos/nc_browser_ajax.php', fn (Request $request, LegacyProjectController $controller) => $controller->passthrough($request, 'redmine-mantencion', 'views/Procedimientos/nc_browser_ajax.php'))
    ->name('redmine.mantencion.nc-browser-ajax');
Route::match(['GET', 'POST'], '/redmine-mantencion/views/Integraciones/Nextcloud.php', fn (Request $request, LegacyProjectController $controller) => $controller->passthrough($request, 'redmine-mantencion', 'views/Integraciones/Nextcloud.php'))
    ->name('redmine.mantencion.nextcloud-config-shim');
Route::get('/redmine', fn () => redirect()->route('redmine.dashboard'));
Route::get('/redmine/nativo', fn () => redirect()->route('redmine.native.dashboard'));
Route::get('/redmine/nativo/{section}', fn (string $section) => redirect()->route('redmine.native.section', ['section' => $section]));
Route::get('/redmine/app', fn () => redirect()->route('redmine.dashboard'));
Route::get('/redmine/app/{section}', fn (string $section) => redirect()->route('redmine.native.section', ['section' => $section]));
Route::get('/redmine/health.php', fn () => redirect()->route('redmine.health'));

if ($legacyModulePattern !== '') {
    Route::get('/{project}', [LegacyProjectController::class, 'index'])
        ->where('project', $legacyModulePattern);

    Route::match(['GET', 'POST'], '/{project}/{path}', [LegacyProjectController::class, 'passthrough'])
        ->where('project', $legacyModulePattern)
        ->where('path', '.*');
}
});
