<?php

namespace RedmineTic\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\Nova\Services\ProjectAccessGuard;
use App\Modulos\Telegram\Services\TelegramService;
use App\Repositories\Reports\AutomaticReportRecipientRepository;
use App\Support\Reports\AutomaticReportSchedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RedmineTic\Repositories\RedmineConfigRepository;
use RedmineTic\Repositories\RedmineDataRepository;
use RedmineTic\Services\QuickReportService;
use RedmineTic\Services\StaleNewReportNotifier;

class RedmineDashboardController extends Controller
{
    private const TIC_SECTIONS = [
        'dashboard' => 'Reportes',
        'webhook' => 'Reporte manual',
        'reporte-rapido' => 'Reporte rapido',
        'horas-extra' => 'Horas extra',
        'historico' => 'Historico',
        'usuarios' => 'Usuarios',
        'configuracion' => 'Configuracion',
        'estadisticas' => 'Estadisticas',
        'actividad' => 'Actividad',
    ];

    private const MANTENCION_SECTIONS = [
        'dashboard' => 'Reportes',
        'horas-extra' => 'Horas extra',
        'historico' => 'Historico',
        'usuarios' => 'Usuarios',
        'configuracion' => 'Configuracion',
        'estadisticas' => 'Estadisticas',
        'actividad' => 'Actividad',
    ];

    private const SECTION_PERMISSIONS = [
        'dashboard' => 'mensajes_acceso',
        'webhook' => 'simulador',
        'reporte-rapido' => 'reporte_rapido',
        'horas-extra' => 'horas_extra',
        'historico' => 'historico',
        'usuarios' => 'usuarios',
        'configuracion' => 'configuracion',
        'estadisticas' => 'estadisticas',
        'actividad' => 'actividad',
    ];

    private const CONFIG_PANEL_PERMISSIONS = [
        'resumen' => 'cfg_resumen',
        'conexion' => 'cfg_conexion',
        'proyecto' => 'cfg_proyecto',
        'redmine' => 'cfg_redmine',
        'campos' => 'cfg_campos',
        'retencion' => 'cfg_retencion',
        'informes' => 'cfg_informes',
        'mantencion' => 'cfg_mantencion',
        'roles' => 'cfg_roles',
        'usuarios-permisos' => 'cfg_usuarios',
        'categorias' => 'cfg_categorias',
        'unidades' => 'cfg_unidades',
    ];

    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            $projectKey = $this->projectKey($request);
            $projectName = (string) data_get(config('modules.'.$projectKey, []), 'name', 'Redmine');
            URL::defaults(['redmineProject' => $projectKey]);

            $user = $request->session()->get('nova_user', []);
            $access = app(ProjectAccessGuard::class);

            $projectUser = is_array($user) ? $access->projectUser($projectKey, $user) : null;
            if (! is_array($projectUser)) {
                return redirect()->route('home')->with('access_error', $access->deniedMessage($projectName));
            }

            $request->session()->put('redmine_project_user', array_merge($user, [
                'id' => (string) ($projectUser['id'] ?? $user['id'] ?? ''),
                'role' => (string) ($projectUser['rol'] ?? $user['role'] ?? 'usuario'),
                'legacy' => $projectUser,
                'project_key' => $projectKey,
            ]));

            return $next($request);
        });
    }

    public function index(Request $request, RedmineDataRepository $redmine): View|RedirectResponse
    {
        $this->prepare($request, $redmine);
        $permissions = $this->effectivePermissions($request, $redmine);

        if (! $this->can($permissions, self::SECTION_PERMISSIONS['dashboard'])) {
            foreach ($this->sectionsFor($redmine->projectKey()) as $section => $label) {
                if ($section !== 'dashboard'
                    && $this->can($permissions, self::SECTION_PERMISSIONS[$section] ?? '')) {
                    return redirect()->route(
                        'redmine.native.section',
                        $this->routeParameters($redmine, ['section' => $section])
                    );
                }
            }

            abort(403);
        }

        return $this->show($request, 'dashboard', $redmine);
    }

    public function show(Request $request, string $section, RedmineDataRepository $redmine): View
    {
        $this->prepare($request, $redmine);
        $sections = $this->sectionsFor($redmine->projectKey());
        abort_unless(array_key_exists($section, $sections), 404);

        $permissions = $this->effectivePermissions($request, $redmine);
        abort_unless($this->can($permissions, self::SECTION_PERMISSIONS[$section] ?? ''), 403);
        if ($section === 'configuracion') {
            $allowedPanels = array_keys(array_filter(
                self::CONFIG_PANEL_PERMISSIONS,
                fn (string $permission): bool => $this->can($permissions, $permission)
            ));
            $panel = strtolower(trim((string) $request->query('panel', $allowedPanels[0] ?? '')));
            abort_unless($this->can($permissions, self::CONFIG_PANEL_PERMISSIONS[$panel] ?? 'cfg_resumen'), 403);
        }
        $sections = array_filter(
            $sections,
            fn (string $label, string $key): bool => $this->can($permissions, self::SECTION_PERMISSIONS[$key] ?? ''),
            ARRAY_FILTER_USE_BOTH
        );

        $dashboardFilter = $section === 'dashboard' ? (string) $request->query('estado', 'pendientes') : 'todos';

        $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
        $config = $redmine->configuration();

        $sectionData = $redmine->nativeSectionData($section, $dashboardFilter, $request->query(), is_array($user) ? $user : []);
        if ($section === 'configuracion') {
            $sectionData['reportRecipients'] = app(AutomaticReportRecipientRepository::class)
                ->panelData($redmine->projectKey());
        }
        if ($section === 'actividad') {
            $sectionData['activityData'] = $redmine->activityData(
                $request->query(),
                is_array($user) ? (string) ($user['id'] ?? '') : '',
                $this->can($permissions, 'actividad_todos')
            );
        }

        return view('redmine_tic::native', array_merge($sectionData, [
            'section' => $section,
            'sectionLabel' => $sections[$section],
            'sections' => $sections,
            'redmineProjectKey' => $redmine->projectKey(),
            'redmineProjectName' => $redmine->projectName(),
            'dashboardFilter' => $dashboardFilter,
            'redmineMaintenance' => $redmine->dashboardSummary()['maintenance'],
            'redmineRetentionHours' => max(1, (int) ($config['retencion_horas'] ?? 24)),
            'allowedConfigPanels' => array_keys(array_filter(
                self::CONFIG_PANEL_PERMISSIONS,
                fn (string $permission): bool => $this->can($permissions, $permission)
            )),
            'effectivePermissions' => $permissions,
            'canHistoryActionsPermission' => $this->can($permissions, 'historico_acciones'),
        ]));
    }

    public function dashboardAction(Request $request, RedmineDataRepository $redmine): RedirectResponse|JsonResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'mensajes_acceso');
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $action = (string) $request->input('dashboard_action', $request->input('action', ''));
        $requiredPermission = match ($action) {
            'delete', 'delete_selected' => 'reportes_eliminar',
            'toggle_hours_extra' => 'horas_extra_editar',
            default => 'reportes_editar',
        };
        $this->authorizePermission(
            $request,
            $redmine,
            $requiredPermission
        );
        $ids = $this->ids($request->input('ids', []));
        $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
        $user = is_array($user) ? $user : [];
        $updatePayload = $request->all();
        if (! $this->can($this->effectivePermissions($request, $redmine), 'horas_extra_editar')) {
            unset($updatePayload['hora_extra'], $updatePayload['tiempo_estimado']);
        }

        // Captured only for 'toggle_hours_extra', the one action currently submitted via AJAX
        // (optimistic UI toggle). Other actions still use the full-page redirect flow below and
        // don't need a precise success flag, so this stays null for them.
        $toggleHoursExtraSuccess = null;

        $message = match ($action) {
            'update' => $redmine->canAccessActiveReport((string) $request->input('id'), $user) && $redmine->updateReport($updatePayload) ? 'Solicitud actualizada.' : 'No se encontro la solicitud o no tienes acceso.',
            'delete' => $redmine->deleteReport($redmine->canAccessActiveReport((string) $request->input('id'), $user) ? (string) $request->input('id') : '').' solicitud(es) eliminada(s).',
            'delete_selected' => $redmine->deleteReports($redmine->filterAccessibleActiveReportIds($ids, $user)).' solicitud(es) eliminada(s).',
            'archive_selected' => $redmine->archiveReports($redmine->filterAccessibleActiveReportIds($ids, $user)).' solicitud(es) archivada(s).',
            'process_selected' => $this->sendReports($request, $redmine, $redmine->filterAccessibleActiveReportIds($ids, $user)),
            'reset_errors' => $redmine->resetErrors($redmine->filterAccessibleActiveReportIds($ids, $user)).' error(es) marcados como pendientes.',
            'toggle_hours_extra' => ($toggleHoursExtraSuccess = $redmine->canAccessActiveReport((string) $request->input('id'), $user) && $redmine->toggleHoursExtra((string) $request->input('id'), $request->boolean('hora_extra'))) ? 'Hora extra actualizada.' : 'No se encontro la solicitud o no tienes acceso.',
            default => 'Accion no reconocida.',
        };

        if ($action !== 'process_selected' && $action !== '') {
            $redmine->recordActivity('reporte_'.$action, [
                'user_id' => (string) ($user['id'] ?? ''),
                'message_id' => (string) $request->input('id', implode(',', $ids)),
                'result' => str_contains($message, 'No se') ? 'error' : 'success',
            ]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['ok' => $toggleHoursExtraSuccess ?? true, 'message' => $message]);
        }

        return back()->with('redmine_status', $message);
    }

    public function userAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'usuarios');
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $action = (string) $request->input('action', 'save');
        $this->authorizePermission($request, $redmine, $action === 'delete' ? 'usuarios_eliminar' : 'usuarios_editar');
        if ($action === 'preview_redmine') {
            $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
            $result = $redmine->previewUsersFromRedmine(is_array($user) ? ($user['id'] ?? null) : null);
            $message = $result['ok']
                ? 'Selecciona los usuarios que quieres importar desde Redmine.'
                : 'No se pudo consultar Redmine: '.$result['error'];

            return back()
                ->with('redmine_status', $message)
                ->with('redmine_status_type', $result['ok'] ? 'info' : 'danger')
                ->with('redmine_import_preview', $result['ok'] ? $result['items'] : []);
        }

        if ($action === 'sync_redmine') {
            $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
            $selectedIds = array_values(array_filter(array_map('strval', (array) $request->input('remote_user_ids', []))));
            $result = $redmine->syncUsersFromRedmine(is_array($user) ? ($user['id'] ?? null) : null, $selectedIds);
            $redmine->recordActivity($result['ok'] ? 'sincronizacion_usuarios_ok' : 'sincronizacion_usuarios_error', [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'error' => $result['error'],
            ]);
            $message = $result['ok']
                ? $result['created'].' usuario(s) creado(s), '.$result['updated'].' actualizado(s) desde Redmine.'
                : 'No se pudo sincronizar con Redmine: '.$result['error'];

            return back()
                ->with('redmine_status', $message)
                ->with('redmine_status_type', $result['ok'] ? 'success' : 'danger');
        }

        if ($action === 'save' && $request->boolean('_creating')) {
            return back()
                ->with('redmine_status', 'La creacion de usuarios se realiza unicamente desde NOVA.')
                ->with('redmine_status_type', 'warning');
        }

        if ($action === 'delete') {
            $deleted = $redmine->deleteUser((string) $request->input('id'));
            $message = $deleted > 0 ? 'Acceso al proyecto quitado.' : 'No se encontro el usuario.';
        } elseif ($action === 'toggle_status') {
            $result = $redmine->toggleUserStatus((string) $request->input('id'));
            $message = $result['ok']
                ? 'Estado cambiado a '.$result['nuevo_estado'].'.'
                : 'No se encontro el usuario.';
        } elseif ($action === 'save') {
            $result = $redmine->saveUser($request->all());
            $message = $result['ok'] ? 'Rol de proyecto actualizado.' : $result['error'];
        } else {
            $message = 'Accion de usuario no permitida.';
        }

        return back()->with('redmine_status', $message);
    }

    public function categoryAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'cfg_categorias');
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $action = (string) $request->input('action', 'save');
        if ($action === 'sync_remote') {
            $result = $redmine->syncCategoriesFromRedmine((string) data_get($request->session()->get('redmine_project_user', []), 'id', $request->session()->get('nova_user.id', '')));
            $redmine->recordActivity($result['ok'] ? 'sincronizacion_categorias_ok' : 'sincronizacion_categorias_error', [
                'count' => $result['count'],
                'changed' => $result['changed'],
                'error' => $result['error'],
            ]);
            $message = $result['ok']
                ? ($result['changed'] ? 'Categorias sincronizadas desde Redmine: cambios aplicados ('.$result['count'].' registro(s)).' : 'Categorias sincronizadas desde Redmine: sin cambios, todo estaba actualizado ('.$result['count'].' registro(s)).')
                : $result['error'];
            $statusType = $result['ok'] ? ($result['changed'] ? 'success' : 'info') : 'danger';
        } else {
            $message = 'Las categorias solo se sincronizan desde Redmine.';
            $statusType = 'info';
        }

        return redirect()
            ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'categorias']))
            ->with('redmine_status', $message)
            ->with('redmine_status_type', $statusType);
    }

    public function unitAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'cfg_unidades');
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $action = (string) $request->input('action', 'save');
        if ($action === 'sync_remote') {
            $result = $redmine->syncUnitsFromRedmine((string) data_get($request->session()->get('redmine_project_user', []), 'id', $request->session()->get('nova_user.id', '')));
            $redmine->recordActivity($result['ok'] ? 'sincronizacion_unidades_ok' : 'sincronizacion_unidades_error', [
                'count' => $result['count'],
                'changed' => $result['changed'],
                'error' => $result['error'],
            ]);
            $message = $result['ok']
                ? ($result['changed'] ? 'Unidades sincronizadas desde Redmine: cambios aplicados ('.$result['count'].' registro(s)).' : 'Unidades sincronizadas desde Redmine: sin cambios, todo estaba actualizado ('.$result['count'].' registro(s)).')
                : $result['error'];
            $statusType = $result['ok'] ? ($result['changed'] ? 'success' : 'info') : 'danger';
        } else {
            $message = 'Las unidades solo se sincronizan desde Redmine.';
            $statusType = 'info';
        }

        return redirect()
            ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'unidades']))
            ->with('redmine_status', $message)
            ->with('redmine_status_type', $statusType);
    }

    public function configurationAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'configuracion');
        $panel = strtolower(trim((string) $request->query('panel', 'resumen')));
        $this->authorizePermission($request, $redmine, self::CONFIG_PANEL_PERMISSIONS[$panel] ?? 'cfg_resumen');
        if ($redmine->maintenanceModeEnabled() && ! $this->isMaintenanceSettingsRequest($request)) {
            return $this->maintenanceBlock($redmine);
        }

        if ($request->input('config_action') === 'send_reports_now') {
            $this->saveReportRecipients($request, $redmine->projectKey());
            $result = app(StaleNewReportNotifier::class)->runManual();
            $message = sprintf(
                'Comprobación TIC finalizada: %d informe(s) individual(es) y %d resumen(es) de jefatura enviados; %d responsable(s) sin pendientes, %d omitido(s), %d error(es) y %d ticket(s) sin estado sincronizado.',
                (int) ($result['sent'] ?? 0),
                (int) ($result['manager_sent'] ?? 0),
                (int) ($result['empty'] ?? 0),
                (int) ($result['skipped'] ?? 0),
                (int) ($result['failed'] ?? 0),
                (int) ($result['unsynced'] ?? 0)
            );

            return redirect()
                ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'informes']), 303)
                ->with('redmine_status', $message)
                ->with('redmine_status_type', (int) ($result['failed'] ?? 0) > 0 ? 'danger' : 'success');
        }

        if ($request->input('config_action') === 'save_user_permissions') {
            $userId = (string) $request->input('user_id', '');
            $userRole = trim((string) $request->input('user_role', ''));
            $permissions = $this->permissionPayload($request);
            if (strtolower($userRole) === 'root') {
                $permissions['all'] = true;
            }
            if ($request->boolean('apply_role_permissions') && $userRole !== '') {
                $rolePermissions = $redmine->roles()[$userRole] ?? null;
                if (is_array($rolePermissions)) {
                    $permissions = $rolePermissions;
                }
            }
            $novaRole = strtolower(trim((string) data_get($request->session()->get('nova_user'), 'role', 'usuario')));
            $currentPermissions = [];
            if ($novaRole !== 'root') {
                $currentUser = collect($redmine->users())
                    ->first(fn (array $user): bool => (string) ($user['id'] ?? '') === $userId);
                $currentPermissions = is_array($currentUser['permisos'] ?? null)
                    ? $currentUser['permisos']
                    : [];
            }
            $permissions = $this->preserveRestrictedScopes($permissions, $currentPermissions, $novaRole);
            $updated = $redmine->saveUserPermissions(
                $userId,
                $userRole,
                $permissions
            );

            return redirect()
                ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'usuarios-permisos']), 303)
                ->with('redmine_status', $updated ? 'Permisos de usuario guardados.' : 'No se encontro el usuario seleccionado.')
                ->with('redmine_selected_user_permissions', $userId);
        }

        if ($request->input('config_action') === 'save_role_permissions') {
            $roleName = trim((string) $request->input('role_name', ''));
            $permissions = $this->permissionPayload($request);
            if (strtolower($roleName) === 'root') {
                $permissions['all'] = true;
            }
            $novaRole = strtolower(trim((string) data_get($request->session()->get('nova_user'), 'role', 'usuario')));
            $currentPermissions = [];
            if ($novaRole !== 'root') {
                $currentPermissions = is_array($redmine->roles()[$roleName] ?? null)
                    ? $redmine->roles()[$roleName]
                    : [];
            }
            $permissions = $this->preserveRestrictedScopes($permissions, $currentPermissions, $novaRole);
            $updated = $redmine->saveRolePermissions(
                $roleName,
                $permissions
            );

            return redirect()
                ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'roles']), 303)
                ->with('redmine_status', $updated ? 'Permisos de rol guardados.' : 'No se encontro el rol seleccionado.')
                ->with('redmine_open_role_permissions', $roleName)
                ->with('redmine_selected_role', $roleName);
        }

        if ($request->input('config_action') === 'delete_role') {
            $roleName = trim((string) $request->input('role_name', ''));
            $result = $redmine->deleteRole($roleName);

            return redirect()
                ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'roles']), 303)
                ->with('redmine_status', $result['ok'] ? 'Rol eliminado.' : $result['error'])
                ->with('redmine_status_type', $result['ok'] ? 'success' : 'danger');
        }

        $config = $redmine->configuration();
        foreach ([
            'platform_url',
            'categories_url',
            'unidades_url',
            'webhook_url',
            'project_id',
            'project_name',
            'tracker_id',
            'priority_id',
            'status_id',
            'cf_solicitante',
            'cf_unidad',
            'cf_unidad_solicitante',
            'cf_hora_extra',
            'retencion_horas',
            'informes_nuevos_dia',
            'informes_nuevos_hora',
            'maintenance_until',
        ] as $field) {
            if ($request->has($field)) {
                $config[$field] = $request->input($field);
            }
        }
        if ($panel === 'informes') {
            $this->saveReportRecipients($request, $redmine->projectKey());
            if ($request->has('report_schedule_configured')) {
                $config['informes_nuevos_habilitado'] = $request->boolean('informes_nuevos_habilitado');
                $schedule = AutomaticReportSchedule::settings([
                    'informes_nuevos_dia' => $request->input('informes_nuevos_dia', $config['informes_nuevos_dia'] ?? '1'),
                    'informes_nuevos_hora' => $request->input('informes_nuevos_hora', $config['informes_nuevos_hora'] ?? '09:00'),
                ]);
                $config['informes_nuevos_dia'] = $schedule['day'];
                $config['informes_nuevos_hora'] = $schedule['time'];
            }
        }
        if ($request->has('maintenance_mode')) {
            $maintenanceMode = $request->boolean('maintenance_mode');
            $config['maintenance_mode'] = $maintenanceMode;
            if (! $maintenanceMode) {
                $config['maintenance_until'] = '';
            } else {
                $maintenanceUntil = trim((string) $request->input('maintenance_until', ''));
                if ($maintenanceUntil !== '') {
                    try {
                        $until = Carbon::parse($maintenanceUntil, 'America/Santiago');
                    } catch (\Throwable) {
                        return back()
                            ->withInput()
                            ->with('redmine_status', 'La fecha de mantencion no es valida.')
                            ->with('redmine_status_type', 'danger');
                    }

                    if ($until->lt(now('America/Santiago')->copy()->startOfMinute())) {
                        return back()
                            ->withInput()
                            ->with('redmine_status', 'La fecha de mantencion hasta no puede ser anterior al dia y hora actual.')
                            ->with('redmine_status_type', 'danger');
                    }
                    $config['maintenance_until'] = $until->format('Y-m-d\TH:i');
                }
            }
        }

        if (($blockedDefaultMessage = $this->defaultOptionDeleteMessage($request, $config)) !== null) {
            return back()
                ->with('redmine_status', $blockedDefaultMessage)
                ->with('redmine_status_type', 'info')
                ->with('redmine_open_options', (string) $request->input('opt_type', ''));
        }

        $optionType = (string) $request->input('opt_type', '');
        $optionAction = (string) $request->input('opt_action', '');
        if (in_array($optionType, ['trackers', 'prioridades', 'estados'], true) && in_array($optionAction, ['create', 'update', 'delete', 'set_default'], true)) {
            $configRepository = new RedmineConfigRepository($redmine->projectKey(), $redmine->projectName());
            $this->applyRedmineOptionAction($request, $configRepository);

            return back()
                ->with('redmine_status', 'Configuracion guardada.')
                ->with('redmine_open_options', $optionType);
        }

        $redmine->saveConfiguration($config);
        if ($request->has('maintenance_mode')) {
            $this->syncModuleMaintenanceState($redmine->projectKey(), ! empty($config['maintenance_mode']));
        }

        $response = back()->with('redmine_status', 'Configuracion guardada.');

        return $response;
    }

    private function saveReportRecipients(Request $request, string $moduleKey): void
    {
        if (! $request->has('report_recipients_configured')) {
            return;
        }

        app(AutomaticReportRecipientRepository::class)->sync(
            $moduleKey,
            (array) $request->input('report_recipients', []),
            (array) $request->input('report_managers', [])
        );
    }

    public function historyAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'historico');
        $this->authorizePermission($request, $redmine, 'historico_acciones');
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $action = (string) $request->input('action', 'delete');
        if ($action === 'sync_redmine_statuses') {
            $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
            $result = $redmine->synchronizeAllIssueStatuses(is_array($user) ? (string) ($user['id'] ?? '') : '');
            $message = $result['error'] !== ''
                ? $result['error']
                : sprintf(
                    'Estados Redmine sincronizados: %d ticket(s) consultado(s) y %d registro(s) actualizado(s).',
                    $result['requested'],
                    $result['updated']
                );

            return back()
                ->with('redmine_status', $message)
                ->with('redmine_status_type', $result['error'] === '' ? 'success' : 'danger');
        }

        if ($action === 'update_redmine_status') {
            $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
            $result = $redmine->updateHistoryIssueStatuses(
                $this->ids($request->input('redmine_ids', [])),
                (int) $request->input('status_id', 0),
                is_array($user) ? $user : []
            );

            $message = $result['updated'].' reporte(s) actualizado(s)';
            if ($result['status_name'] !== '') {
                $message .= ' a “'.$result['status_name'].'” en Redmine';
            }
            $message .= '.';
            if ($result['errors'] !== []) {
                $visibleErrors = array_slice($result['errors'], 0, 5);
                $message .= ' No actualizados: '.implode(' ', $visibleErrors);
                if (count($result['errors']) > 5) {
                    $message .= ' y '.(count($result['errors']) - 5).' más.';
                }
            }

            return back()
                ->with('redmine_status', $message)
                ->with('redmine_status_type', $result['updated'] > 0 ? 'success' : 'danger');
        }

        $deleted = $redmine->deleteArchivedReport((string) $request->input('id'));

        return back()->with('redmine_status', $deleted.' registro(s) historico(s) eliminado(s).');
    }

    public function historyStatuses(Request $request, RedmineDataRepository $redmine): JsonResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'historico');

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(static function (string $id): string {
                $id = trim($id);

                return preg_match('/^\d+$/', $id) ? $id : '';
            })
            ->filter()
            ->unique()
            ->take(100)
            ->values()
            ->all();

        $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
        $statuses = $redmine->issueStatuses($ids, is_array($user) ? (string) ($user['id'] ?? '') : '');
        $redmine->persistIssueStatuses($statuses);

        return response()->json(['ok' => true, 'statuses' => $statuses]);
    }

    public function hoursAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'horas_extra');
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        abort_unless((string) $request->input('action', 'save') === 'save', 422, 'Accion de horas extra no permitida.');
        $this->authorizePermission($request, $redmine, 'horas_extra_editar');
        $source = (string) $request->input('_source_file');

        $redmine->saveHoursGroup($source, $request->all());

        return back()->with('redmine_status', 'Grupo de horas extra guardado.');
    }

    public function activityAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'actividad');
        $this->authorizePermission($request, $redmine, 'actividad_eliminar');
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $userId = (string) data_get($request->session()->get('redmine_project_user', []), 'id', '');
        $deleted = $redmine->clearActivityForUser($userId);
        $redmine->recordActivity('actividad_limpiada', [
            'user_id' => $userId,
            'result' => 'success',
            'count' => $deleted,
        ]);

        return back()->with('redmine_status', $deleted.' evento(s) propios eliminados de la bitácora.');
    }

    public function webhookAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'simulador');
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $payload = $request->validate([
            'asunto' => ['required', 'string', 'max:220'],
            'descripcion' => ['nullable', 'string', 'max:4000'],
            'solicitante' => ['nullable', 'string', 'max:160'],
            'unidad' => ['nullable', 'string', 'max:180'],
            'unidad_solicitante' => ['nullable', 'string', 'max:180'],
            'categoria' => ['nullable', 'string', 'max:180'],
            'prioridad' => ['nullable', 'string', 'max:80'],
            'tipo' => ['nullable', 'string', 'max:80'],
            'asignado_a' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'fecha' => ['nullable', 'date'],
            'hora' => ['nullable', 'date_format:H:i'],
            'chat_id_telegram' => ['nullable', 'string', 'max:120'],
            'numero' => ['nullable', 'string', 'max:120'],
            'mensaje' => ['nullable', 'string', 'max:500'],
            'hora_extra' => ['nullable', 'string', 'max:8'],
            'tiempo_estimado' => ['nullable', 'string', 'max:40'],
        ]);
        if (trim((string) ($payload['chat_id_telegram'] ?? '')) === '' && trim((string) ($payload['numero'] ?? '')) !== '') {
            $payload['chat_id_telegram'] = trim((string) $payload['numero']);
        }
        unset($payload['numero']);
        $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
        if (is_array($user) && trim((string) ($payload['asignado_a'] ?? '')) === '') {
            $payload['asignado_a'] = (string) ($user['redmine_id'] ?? $user['id'] ?? '');
        }
        $payload['origen'] = 'manual';
        $report = $redmine->createSimulatedReport($payload);
        $redmine->recordActivity('reporte_manual_creado', [
            'user_id' => is_array($user) ? (string) ($user['id'] ?? '') : '',
            'message_id' => (string) ($report['id'] ?? ''),
            'asunto' => (string) ($payload['asunto'] ?? ''),
            'categoria' => (string) ($payload['categoria'] ?? ''),
        ]);

        return back()
            ->with('redmine_status', 'Reporte manual creado en pendientes.')
            ->with('redmine_status_type', 'success')
            ->with('redmine_created_report_id', $report['id'] ?? '');
    }

    public function quickReportAction(
        Request $request,
        RedmineDataRepository $redmine,
        QuickReportService $quickReports,
        TelegramService $telegram
    ): RedirectResponse {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'reporte_rapido');
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $route = route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'reporte-rapido']));
        $action = (string) $request->input('quick_action', 'preview');

        if ($action === 'preview') {
            $validated = $request->validate([
                'quick_input' => ['required', 'string', 'max:700'],
                'quick_description' => ['nullable', 'string', 'max:4000'],
                'asignado_a' => ['required', 'integer', 'min:1', 'max:4294967295'],
            ]);
            $this->storeQuickReportNotes($request, (string) ($validated['quick_description'] ?? ''));
            $recipient = $quickReports->assignedRecipient($redmine->users(), (string) $validated['asignado_a']);
            if ($recipient === null) {
                return redirect($route)
                    ->withInput()
                    ->withErrors(['asignado_a' => 'Selecciona un responsable activo de Redmine TIC.']);
            }
            $preview = $quickReports->createDraft(
                (string) $validated['quick_input'],
                $redmine->categories(),
                $redmine->units(),
                (string) $validated['asignado_a']
            );
            if (! $preview['ok']) {
                return redirect($route)
                    ->withInput()
                    ->withErrors(['quick_input' => $preview['error']]);
            }

            return redirect($route)
                ->withInput([
                    'quick_input' => $preview['input'],
                    'quick_description' => (string) ($validated['quick_description'] ?? ''),
                ])
                ->with('redmine_quick_preview', $preview['draft']);
        }

        abort_unless($action === 'send', 422, 'Accion de reporte rapido no permitida.');
        $activeUnits = $quickReports->catalogNames($redmine->units());
        $payload = $request->validate([
            'asunto' => ['required', 'string', 'max:220'],
            'descripcion' => ['nullable', 'string', 'max:4000'],
            'solicitante' => ['nullable', 'string', 'max:160'],
            'unidad' => ['nullable', 'string', 'max:180'],
            'unidad_solicitante' => ['required', 'string', 'max:180', Rule::in($activeUnits)],
            'categoria' => ['nullable', 'string', 'max:180'],
            'prioridad' => ['nullable', 'string', 'max:80'],
            'tipo' => ['nullable', 'string', 'max:80'],
            'asignado_a' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'fecha' => ['nullable', 'date'],
            'hora' => ['nullable', 'date_format:H:i'],
            'chat_id_telegram' => ['nullable', 'string', 'max:120'],
            'mensaje' => ['nullable', 'string', 'max:700'],
            'hora_extra' => ['nullable', 'string', 'max:8'],
            'tiempo_estimado' => ['nullable', 'string', 'max:40'],
            'quick_input' => ['nullable', 'string', 'max:700'],
        ], [
            'unidad_solicitante.in' => 'La unidad solicitante seleccionada ya no existe en la lista vigente.',
        ]);
        $recipient = $quickReports->assignedRecipient($redmine->users(), (string) $payload['asignado_a']);
        if ($recipient === null) {
            return redirect($route)
                ->withInput($payload + ['quick_preview' => '1'])
                ->with('redmine_quick_preview', $payload)
                ->withErrors(['asignado_a' => 'El responsable seleccionado ya no esta activo en Redmine TIC.']);
        }

        $payload['origen'] = 'manual_rapido';
        $payload['chat_id_telegram'] = '';
        $report = $redmine->createSimulatedReport($payload);
        $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
        $userId = is_array($user) ? (string) ($user['id'] ?? '') : '';
        $redmine->recordActivity('reporte_rapido_creado', [
            'user_id' => $userId,
            'message_id' => (string) ($report['id'] ?? ''),
            'asignado_a' => $recipient['id'],
            'asunto' => (string) ($report['asunto'] ?? ''),
        ]);
        $sent = $redmine->sendReportsToRedmine([(string) ($report['id'] ?? '')], $userId !== '' ? $userId : null);
        $redmineId = (string) ($sent['redmine_ids'][0] ?? '');
        if ((int) ($sent['success'] ?? 0) !== 1 || $redmineId === '') {
            $error = trim((string) ($sent['errors'][0] ?? 'Redmine no confirmo la creacion del ticket.'));

            return redirect($route)
                ->withInput($payload + ['quick_preview' => '1'])
                ->with('redmine_quick_preview', $payload)
                ->with('redmine_status', $error)
                ->with('redmine_status_type', 'danger');
        }

        $issueUrl = $redmine->redmineIssueUrl($redmineId);
        $telegramSent = false;
        if ($recipient['chat_id'] !== '') {
            try {
                $telegramSent = $telegram->sendToChat(
                    $recipient['chat_id'],
                    $quickReports->notificationMessage($report, $redmineId, $issueUrl)
                );
            } catch (\Throwable) {
                $telegramSent = false;
            }
        }
        $redmine->recordActivity($telegramSent ? 'reporte_rapido_telegram_ok' : 'reporte_rapido_telegram_pendiente', [
            'user_id' => $userId,
            'message_id' => (string) ($report['id'] ?? ''),
            'redmine_id' => $redmineId,
            'asignado_a' => $recipient['id'],
            'telegram_configurado' => $recipient['chat_id'] !== '',
        ]);

        $status = 'Reporte Redmine #'.$redmineId.' creado correctamente.';
        if ($telegramSent) {
            $status .= ' Notificacion enviada a '.$recipient['name'].'.';
        } elseif ($recipient['chat_id'] === '') {
            $status .= ' El responsable no tiene Chat ID de Telegram configurado.';
        } else {
            $status .= ' No fue posible enviar la notificacion Telegram.';
        }

        return redirect($route)
            ->with('redmine_status', $status)
            ->with('redmine_status_type', $telegramSent ? 'success' : 'info')
            ->with('redmine_quick_result', [
                'redmine_id' => $redmineId,
                'url' => $issueUrl,
                'responsable' => $recipient['name'],
                'telegram_sent' => $telegramSent,
                'telegram_configured' => $recipient['chat_id'] !== '',
            ]);
    }

    public function quickReportNotes(Request $request, RedmineDataRepository $redmine): JsonResponse
    {
        $this->prepare($request, $redmine);
        $this->authorizePermission($request, $redmine, 'reporte_rapido');

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        $notes = (string) ($validated['notes'] ?? '');
        $this->storeQuickReportNotes($request, $notes);

        return response()->json([
            'ok' => true,
            'saved_at' => now('America/Santiago')->format('H:i'),
        ]);
    }

    private function storeQuickReportNotes(Request $request, string $notes): void
    {
        if (trim($notes) === '') {
            $request->session()->forget('redmine_tic.quick_report_notes');

            return;
        }

        $request->session()->put('redmine_tic.quick_report_notes', $notes);
    }

    /**
     * @param  mixed  $value
     * @return string[]
     */
    private function ids($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return array_values(array_filter(array_map('trim', (array) $value)));
    }

    private function prepare(Request $request, RedmineDataRepository $redmine): void
    {
        $redmine->forProject($this->projectKey($request));
    }

    private function projectKey(Request $request): string
    {
        $projectKey = (string) $request->route('redmineProject', 'redmine_tic');

        return array_key_exists($projectKey, config('modules', [])) ? $projectKey : 'redmine_tic';
    }

    /**
     * @param  array<string,mixed>  $parameters
     * @return array<string,mixed>
     */
    private function routeParameters(RedmineDataRepository $redmine, array $parameters = []): array
    {
        return $parameters;
    }

    /**
     * @return array<string,string>
     */
    private function sectionsFor(string $projectKey): array
    {
        return $projectKey === 'redmine-mantencion' ? self::MANTENCION_SECTIONS : self::TIC_SECTIONS;
    }

    /** @return array<string,mixed> */
    private function effectivePermissions(Request $request, RedmineDataRepository $redmine): array
    {
        $user = $request->session()->get('redmine_project_user', []);
        $permissions = data_get($user, 'legacy.permisos', []);
        if (is_array($permissions) && $permissions !== []) {
            return $permissions;
        }

        $role = (string) data_get($user, 'legacy.rol', data_get($user, 'role', ''));
        $rolePermissions = $redmine->roles()[$role] ?? [];

        return is_array($rolePermissions) ? $rolePermissions : [];
    }

    /** @param array<string,mixed> $permissions */
    private function can(array $permissions, string $permission): bool
    {
        // `all` is injected by ProjectAccessGuard only for NOVA root users.
        // It must take precedence over stale or inherited per-action values
        // from the TIC profile; otherwise the UI is visible to an admin but
        // the POST action is rejected with 403.
        if (! empty($permissions['all'])) {
            return true;
        }

        $explicitPermissions = [
            'actividad', 'actividad_eliminar', 'actividad_todos',
            'reportes_editar', 'reportes_eliminar',
            'horas_extra_editar',
            'historico_acciones', 'usuarios_editar', 'usuarios_eliminar',
        ];
        if (in_array($permission, $explicitPermissions, true) && array_key_exists($permission, $permissions)) {
            $value = $permissions[$permission];

            return $value === true || $value === 1 || $value === '1' || $value === 'si';
        }

        $value = $permissions[$permission] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'todos' || $value === 'asignados';
    }

    private function authorizePermission(Request $request, RedmineDataRepository $redmine, string $permission): void
    {
        abort_unless($this->can($this->effectivePermissions($request, $redmine), $permission), 403);
    }

    private function maintenanceBlock(RedmineDataRepository $redmine): ?RedirectResponse
    {
        if (! $redmine->maintenanceModeEnabled()) {
            return null;
        }

        return back()->with('redmine_status', 'Modulo en mantencion: la edicion de datos esta desactivada temporalmente.');
    }

    private function isMaintenanceSettingsRequest(Request $request): bool
    {
        $allowed = ['_token', 'maintenance_mode', 'maintenance_until'];

        return collect(array_keys($request->except(['_token'])))->every(static fn (string $field): bool => in_array($field, $allowed, true));
    }

    private function syncModuleMaintenanceState(string $projectKey, bool $enabled): void
    {
        try {
            if (! Schema::hasTable('modulos_nova') || ! Schema::hasColumn('modulos_nova', 'en_mantencion')) {
                return;
            }

            DB::table('modulos_nova')
                ->where('clave_modulo', $projectKey)
                ->update(['en_mantencion' => $enabled ? 1 : 0]);
            Cache::forget('nova.modules.state');
        } catch (\Throwable) {
        }
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function defaultOptionDeleteMessage(Request $request, array $config): ?string
    {
        if ((string) $request->input('opt_action', '') !== 'delete') {
            return null;
        }

        $type = (string) $request->input('opt_type', '');
        $configKey = [
            'trackers' => 'tracker_id',
            'prioridades' => 'priority_id',
            'estados' => 'status_id',
        ][$type] ?? null;

        if ($configKey === null) {
            return null;
        }

        $id = trim((string) $request->input('opt_id', ''));
        if ($id === '') {
            return null;
        }

        $rows = array_values(array_filter((array) ($config[$type] ?? []), 'is_array'));
        $rowIsDefault = collect($rows)->contains(static function (array $row) use ($id): bool {
            return (string) ($row['id'] ?? '') === $id && ! empty($row['default']);
        });

        if ((string) ($config[$configKey] ?? '') !== $id && ! $rowIsDefault) {
            return null;
        }

        $label = [
            'trackers' => 'trackers',
            'prioridades' => 'prioridades',
            'estados' => 'estados',
        ][$type];

        return 'No se puede eliminar esta opcion porque esta definida como predeterminada. Selecciona otro valor predeterminado para '.$label.' antes de eliminarla.';
    }

    private function applyRedmineOptionAction(Request $request, RedmineConfigRepository $configRepository): bool
    {
        $type = (string) $request->input('opt_type', '');
        $action = (string) $request->input('opt_action', '');
        $databaseType = [
            'trackers' => 'tracker',
            'prioridades' => 'prioridad',
            'estados' => 'estado',
        ][$type] ?? null;

        if ($databaseType === null || ! in_array($action, ['create', 'update', 'delete', 'set_default'], true)) {
            return false;
        }

        $id = trim((string) $request->input('opt_id', ''));
        $name = trim((string) $request->input('opt_nombre', ''));
        if ($id === '' || ($action !== 'delete' && $action !== 'set_default' && $name === '')) {
            return false;
        }

        $makeDefault = $request->boolean('opt_default');

        return match ($action) {
            'create' => $configRepository->createOption($databaseType, $id, $name, $makeDefault),
            'update' => $configRepository->updateOption($databaseType, $id, $name, $makeDefault),
            'delete' => $configRepository->deleteOption($databaseType, $id),
            'set_default' => $configRepository->setDefaultOption($databaseType, $id),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function permissionPayload(Request $request): array
    {
        $scope = static fn (string $field): string => in_array((string) $request->input($field, 'asignados'), ['todos', 'asignados'], true)
            ? (string) $request->input($field, 'asignados')
            : 'asignados';
        $canViewActivity = $request->boolean('perm_actividad');

        return [
            'mensajes' => $scope('perm_mensajes_scope'),
            'mensajes_acceso' => $request->boolean('perm_mensajes_acceso'),
            'horas_extra' => $request->boolean('perm_horas_extra') ? $scope('perm_horas_scope') : '',
            'historico' => $request->boolean('perm_historico'),
            'historico_acciones' => $request->boolean('perm_historico_acciones'),
            'historico_scope' => $scope('perm_historico_scope'),
            'configuracion' => $request->boolean('perm_configuracion'),
            'estadisticas' => $request->boolean('perm_estadisticas'),
            'usuarios' => $request->boolean('perm_usuarios'),
            'simulador' => $request->boolean('perm_simulador'),
            'reporte_rapido' => $request->boolean('perm_reporte_rapido'),
            'reportes_editar' => $request->boolean('perm_reportes_editar'),
            'reportes_eliminar' => $request->boolean('perm_reportes_eliminar'),
            'horas_extra_editar' => $request->boolean('perm_horas_extra_editar'),
            'usuarios_editar' => $request->boolean('perm_usuarios_editar'),
            'usuarios_eliminar' => $request->boolean('perm_usuarios_eliminar'),
            'cfg_resumen' => $request->boolean('perm_cfg_resumen'),
            'cfg_conexion' => $request->boolean('perm_cfg_conexion'),
            'cfg_proyecto' => $request->boolean('perm_cfg_proyecto'),
            'cfg_redmine' => $request->boolean('perm_cfg_redmine'),
            'cfg_campos' => $request->boolean('perm_cfg_campos'),
            'cfg_retencion' => $request->boolean('perm_cfg_retencion'),
            'cfg_informes' => $request->boolean('perm_cfg_informes'),
            'cfg_mantencion' => $request->boolean('perm_cfg_mantencion'),
            'cfg_roles' => $request->boolean('perm_cfg_roles'),
            'cfg_usuarios' => $request->boolean('perm_cfg_usuarios'),
            'cfg_categorias' => $request->boolean('perm_cfg_categorias'),
            'cfg_unidades' => $request->boolean('perm_cfg_unidades'),
            'actividad' => $canViewActivity,
            'actividad_eliminar' => $canViewActivity && $request->boolean('perm_actividad_eliminar'),
            'actividad_todos' => $canViewActivity && $request->boolean('perm_actividad_todos'),
            'mis_integraciones' => $request->boolean('perm_mis_integraciones'),
        ];
    }

    /**
     * @param  array<string,mixed>  $permissions
     * @param  array<string,mixed>  $currentPermissions
     * @return array<string,mixed>
     */
    private function preserveRestrictedScopes(array $permissions, array $currentPermissions, string $novaRole): array
    {
        if (strtolower(trim($novaRole)) === 'root') {
            return $permissions;
        }

        $scope = static fn ($value): string => in_array($value, ['todos', 'asignados'], true)
            ? $value
            : 'asignados';
        $permissions['mensajes'] = $scope($currentPermissions['mensajes'] ?? null);
        $permissions['historico_scope'] = $scope($currentPermissions['historico_scope'] ?? null);
        $permissions['horas_extra'] = ($permissions['horas_extra'] ?? '') !== ''
            ? $scope($currentPermissions['horas_extra'] ?? null)
            : '';

        return $permissions;
    }

    /**
     * @param  string[]  $ids
     */
    private function sendReports(Request $request, RedmineDataRepository $redmine, array $ids): string
    {
        $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
        $result = $redmine->sendReportsToRedmine($ids, is_array($user) ? ($user['id'] ?? null) : null);
        $parts = [
            $result['success'].' ticket(s) enviados de '.$result['attempts'].' intento(s).',
        ];
        if ($result['redmine_ids']) {
            $parts[] = 'Redmine ID(s): '.implode(', ', $result['redmine_ids']).'.';
        }
        if ($result['errors']) {
            $parts[] = implode(' ', array_slice($result['errors'], 0, 3));
        }

        return implode(' ', $parts);
    }
}
