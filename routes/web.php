<?php

use App\Http\Controllers\LegacyProjectController;
use App\Http\Controllers\ModuleAdminController;
use App\Http\Controllers\NovaAdministrationController;
use App\Http\Controllers\NovaAuthController;
use App\Http\Controllers\NovaUserController;
use App\Http\Controllers\TelegramController;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ProjectAccessGuard;
use App\Support\Nova\NovaAccessRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RedmineTic\Http\Controllers\RedmineDashboardController;

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
Route::post('/login', [NovaAuthController::class, 'login'])->name('login.store');
Route::match(['GET', 'POST'], '/logout', [NovaAuthController::class, 'logout'])->name('logout');
Route::post('/session/extend', [NovaAuthController::class, 'extendSession'])->name('session.extend');

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

Route::get('/admin/modules', [ModuleAdminController::class, 'index'])->name('modules.index');
Route::post('/admin/modules', [ModuleAdminController::class, 'update'])->name('modules.update');
Route::get('/administracion', [NovaAdministrationController::class, 'index'])->name('administracion.index');
Route::get('/administracion/{section}', [NovaAdministrationController::class, 'index'])->name('administracion.section');
Route::post('/administracion/configuracion', [NovaAdministrationController::class, 'updateSettings'])->name('administracion.config.update');
Route::post('/administracion/respaldos', [NovaAdministrationController::class, 'createBackup'])->name('administracion.backups.create');
Route::post('/administracion/telegram/listener', [NovaAdministrationController::class, 'telegramListener'])->name('administracion.telegram.listener');
Route::post('/administracion/usuarios', [NovaAdministrationController::class, 'updateUsers'])->name('administracion.users.update');
Route::post('/administracion/accesos', [NovaAdministrationController::class, 'updateAccess'])->name('administracion.access.update');
Route::get('/admin/users', fn () => redirect()->route('administracion.section', 'usuarios'))->name('nova-users.index');
Route::post('/admin/users', [NovaAdministrationController::class, 'updateUsers'])->name('nova-users.update');
Route::get('/usuarios_nova', fn () => redirect()->route('administracion.section', 'usuarios'))->name('nova-users.project');
Route::get('/telegram', [TelegramController::class, 'index'])->name('telegram.index');
Route::get('/telegram/admin', fn () => redirect()->route('administracion.section', 'telegram'))->name('telegram.admin');
Route::get('/telegram/mensajes', fn () => redirect()->route('administracion.section', 'telegram-mensajes'))->name('telegram.messages');
Route::post('/telegram/configuracion', [TelegramController::class, 'update'])->name('telegram.update');
Route::post('/telegram/admin/configuracion', [TelegramController::class, 'updateAdmin'])->name('telegram.admin.update');
Route::post('/telegram/admin/listener', [TelegramController::class, 'listener'])->name('telegram.admin.listener');
Route::post('/telegram/test', [TelegramController::class, 'test'])->name('telegram.test');

Route::get('/redmine_tic/health.php', fn () => response()->json([
    'ok' => true,
    'module' => 'redmine_tic',
    'type' => 'native',
    'base_path' => data_get(config('modules.redmine_tic', []), 'path', base_path('redmine_tic')),
]))->name('redmine.health');
Route::get('/redmine_tic/nativo', fn () => redirect()->route('redmine.native.dashboard'));
Route::get('/redmine_tic/nativo/{section}', fn (string $section) => redirect()->route('redmine.native.section', ['section' => $section]));
Route::get('/redmine_tic/app', [RedmineDashboardController::class, 'index'])->name('redmine.native.dashboard');
Route::get('/redmine_tic/app/configuracion', [RedmineDashboardController::class, 'show'])
    ->defaults('section', 'configuracion');
Route::get('/redmine_tic/app/{section}', [RedmineDashboardController::class, 'show'])->name('redmine.native.section');
Route::post('/redmine_tic/app/dashboard', [RedmineDashboardController::class, 'dashboardAction'])->name('redmine.native.dashboard.action');
Route::post('/redmine_tic/app/usuarios', [RedmineDashboardController::class, 'userAction'])->name('redmine.native.users.action');
Route::post('/redmine_tic/app/categorias', [RedmineDashboardController::class, 'categoryAction'])->name('redmine.native.categories.action');
Route::post('/redmine_tic/app/unidades', [RedmineDashboardController::class, 'unitAction'])->name('redmine.native.units.action');
Route::post('/redmine_tic/app/configuracion', [RedmineDashboardController::class, 'configurationAction'])->name('redmine.native.config.action');
Route::post('/redmine_tic/app/configuracion/importar', [RedmineDashboardController::class, 'configurationImportAction'])->name('redmine.native.config.import');
Route::post('/redmine_tic/app/configuracion/exportar', [RedmineDashboardController::class, 'configurationExportAction'])->name('redmine.native.config.export');
Route::get('/redmine_tic/app/historico/estados', [RedmineDashboardController::class, 'historyStatuses'])->name('redmine.native.history.statuses');
Route::post('/redmine_tic/app/historico', [RedmineDashboardController::class, 'historyAction'])->name('redmine.native.history.action');
Route::post('/redmine_tic/app/horas-extra', [RedmineDashboardController::class, 'hoursAction'])->name('redmine.native.hours.action');
Route::post('/redmine_tic/app/actividad', [RedmineDashboardController::class, 'activityAction'])->name('redmine.native.activity.action');
Route::post('/redmine_tic/app/webhook', [RedmineDashboardController::class, 'webhookAction'])->name('redmine.native.webhook.action');
Route::get('/redmine_tic', fn () => redirect()->route('redmine.native.dashboard'))->name('redmine.dashboard');
Route::match(['GET', 'POST'], '/redmine_tic/{path}', fn (Request $request, LegacyProjectController $controller, ?string $path = null) => $controller->passthrough($request, 'redmine_tic', $path))
    ->where('path', '^(?!(?:app|nativo)(?:/|$)).*')
    ->name('redmine.path');
Route::get('/redmine-mantencion/health.php', fn () => response()->json([
    'ok' => true,
    'module' => 'redmine-mantencion',
    'type' => 'native',
    'base_path' => data_get(config('modules.redmine-mantencion', []), 'path', base_path('redmine-mantencion')),
]))->name('redmine.mantencion.health');
Route::get('/redmine-mantencion', fn () => redirect()->route('redmine.mantencion.dashboard'));
Route::match(['GET', 'POST'], '/redmine-mantencion/app', fn (Request $request, LegacyProjectController $controller) => $controller->passthrough($request, 'redmine-mantencion', 'index.php'))
    ->name('redmine.mantencion.dashboard');
Route::match(['GET', 'POST'], '/redmine-mantencion/app/{section}', function (Request $request, LegacyProjectController $controller, string $section) {
    $path = match ($section) {
        'dashboard', 'reportes' => 'index.php',
        'manual', 'pendiente-manual' => 'views/Pendientes/manual.php',
        'horas-extra' => 'views/HorasExtra/horas_extra.php',
        'historico' => 'views/Historico/historico.php',
        'procedimientos' => 'views/Procedimientos/procedimientos.php',
        'usuarios' => 'views/Usuarios/usuarios.php',
        'configuracion' => 'views/Configuracion/configuracion.php',
        'estadisticas' => 'views/Estadisticas/estadisticas.php',
        'actividad' => 'views/Security/activity.php',
        default => abort(404),
    };

    return $controller->passthrough($request, 'redmine-mantencion', $path);
})->name('redmine.mantencion.section');
Route::match(['GET', 'POST'], '/redmine-mantencion/{path}', fn (Request $request, LegacyProjectController $controller, ?string $path = null) => $controller->passthrough($request, 'redmine-mantencion', $path))
    ->where('path', '^(?!app(?:/|$)).*')
    ->name('redmine.mantencion.path');
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
