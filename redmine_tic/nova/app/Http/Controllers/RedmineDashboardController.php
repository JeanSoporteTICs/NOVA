<?php

namespace RedmineTic\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Modules\ProjectAccessGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use RedmineTic\Support\Redmine\RedmineDataRepository;

class RedmineDashboardController extends Controller
{
    private const TIC_SECTIONS = [
        'dashboard' => 'Reportes',
        'webhook' => 'Webhook',
        'horas-extra' => 'Horas extra',
        'historico' => 'Historico',
        'usuarios' => 'Usuarios',
        'configuracion' => 'Configuracion',
        'estadisticas' => 'Estadisticas',
        'estadisticas-api' => 'Redmine API',
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

    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            $projectKey = $this->projectKey($request);
            $projectName = (string) data_get(config('modules.' . $projectKey, []), 'name', 'Redmine');
            URL::defaults(['redmineProject' => $projectKey]);

            $user = $request->session()->get('nova_user', []);
            $access = app(ProjectAccessGuard::class);

            $projectUser = is_array($user) ? $access->projectUser($projectKey, $user) : null;
            if (!is_array($projectUser)) {
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

    public function index(Request $request, RedmineDataRepository $redmine): View
    {
        $this->prepare($request, $redmine);

        return $this->show($request, 'dashboard', $redmine);
    }

    public function show(Request $request, string $section, RedmineDataRepository $redmine): View
    {
        $this->prepare($request, $redmine);
        $sections = $this->sectionsFor($redmine->projectKey());
        abort_unless(array_key_exists($section, $sections), 404);

        $dashboardFilter = $section === 'dashboard' ? (string) $request->query('estado', 'todos') : 'todos';

        $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
        $config = $redmine->configuration();

        return view('redmine_tic::native', array_merge($redmine->nativeSectionData($section, $dashboardFilter, $request->query(), is_array($user) ? $user : []), [
            'section' => $section,
            'sectionLabel' => $sections[$section],
            'sections' => $sections,
            'redmineProjectKey' => $redmine->projectKey(),
            'redmineProjectName' => $redmine->projectName(),
            'dashboardFilter' => $dashboardFilter,
            'redmineMaintenance' => $redmine->dashboardSummary()['maintenance'],
            'redmineRetentionHours' => max(1, (int) ($config['retencion_horas'] ?? 24)),
        ]));
    }

    public function dashboardAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $action = (string) $request->input('dashboard_action', $request->input('action', ''));
        $ids = $this->ids($request->input('ids', []));

        $message = match ($action) {
            'update' => $redmine->updateReport($request->all()) ? 'Solicitud actualizada.' : 'No se encontro la solicitud.',
            'delete' => $redmine->deleteReport((string) $request->input('id')) . ' solicitud(es) eliminada(s).',
            'delete_selected' => $redmine->deleteReports($ids) . ' solicitud(es) eliminada(s).',
            'archive_selected' => $redmine->archiveReports($ids) . ' solicitud(es) archivada(s).',
            'process_selected' => $this->sendReports($request, $redmine, $ids),
            'reset_errors' => $redmine->resetErrors($ids) . ' error(es) marcados como pendientes.',
            'toggle_hours_extra' => $redmine->toggleHoursExtra((string) $request->input('id'), $request->boolean('hora_extra')) ? 'Hora extra actualizada.' : 'No se encontro la solicitud.',
            default => 'Accion no reconocida.',
        };

        return back()->with('redmine_status', $message);
    }

    public function userAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $action = (string) $request->input('action', 'save');
        if ($action === 'sync_redmine') {
            $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
            $result = $redmine->syncUsersFromRedmine(is_array($user) ? ($user['id'] ?? null) : null);
            $redmine->recordActivity($result['ok'] ? 'sincronizacion_usuarios_ok' : 'sincronizacion_usuarios_error', [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'error' => $result['error'],
            ]);
            $message = $result['ok']
                ? $result['created'] . ' usuario(s) creado(s), ' . $result['updated'] . ' actualizado(s) desde Redmine.'
                : 'No se pudo sincronizar con Redmine: ' . $result['error'];

            return back()->with('redmine_status', $message);
        }

        $message = $action === 'delete'
            ? $redmine->deleteUser((string) $request->input('id')) . ' usuario(s) eliminado(s).'
            : 'Usuario guardado.';

        if ($action !== 'delete') {
            $result = $redmine->saveUser($request->all());
            $message = $result['ok'] ? 'Usuario guardado.' : $result['error'];
        }

        return back()->with('redmine_status', $message);
    }

    public function categoryAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
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
                ? ($result['changed'] ? 'Categorias sincronizadas desde Redmine: cambios aplicados (' . $result['count'] . ' registro(s)).' : 'Categorias sincronizadas desde Redmine: sin cambios, todo estaba actualizado (' . $result['count'] . ' registro(s)).')
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
                ? ($result['changed'] ? 'Unidades sincronizadas desde Redmine: cambios aplicados (' . $result['count'] . ' registro(s)).' : 'Unidades sincronizadas desde Redmine: sin cambios, todo estaba actualizado (' . $result['count'] . ' registro(s)).')
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
        if ($redmine->maintenanceModeEnabled() && !$this->isMaintenanceSettingsRequest($request)) {
            return $this->maintenanceBlock($redmine);
        }

        if ($request->input('config_action') === 'save_user_permissions') {
            $userId = (string) $request->input('user_id', '');
            $updated = $redmine->saveUserPermissions(
                $userId,
                (string) $request->input('user_role', ''),
                $this->permissionPayload($request)
            );

            return redirect()
                ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'usuarios-permisos', 'user_id' => $userId]))
                ->with('redmine_status', $updated ? 'Permisos de usuario guardados.' : 'No se encontro el usuario seleccionado.');
        }

        if ($request->input('config_action') === 'save_role_permissions') {
            $roleName = trim((string) $request->input('role_name', ''));
            $updated = $redmine->saveRolePermissions(
                $roleName,
                $this->permissionPayload($request)
            );

            return redirect()
                ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'roles', 'role' => $roleName]))
                ->with('redmine_status', $updated ? 'Permisos de rol guardados.' : 'No se encontro el rol seleccionado.')
                ->with('redmine_open_role_permissions', $roleName);
        }

        if ($request->input('config_action') === 'delete_role') {
            $roleName = trim((string) $request->input('role_name', ''));
            $result = $redmine->deleteRole($roleName);

            return redirect()
                ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'roles']))
                ->with('redmine_status', $result['ok'] ? 'Rol eliminado.' : $result['error']);
        }

        if ($request->input('config_action') === 'test_webhook') {
            $url = trim((string) $request->input('webhook_url', ''));
            $result = $redmine->testWebhookConnection($url);
            if ($result['ok']) {
                $request->session()->put('redmine_webhook_tested_url', $url);
            } else {
                $request->session()->forget('redmine_webhook_tested_url');
            }

            return redirect()
                ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'webhook']))
                ->withInput(['webhook_url' => $url])
                ->with('redmine_status', $result['ok'] ? 'Conexion webhook correcta. HTTP ' . $result['http_code'] . '.' : 'No se pudo conectar al webhook: ' . ($result['error'] ?: 'HTTP ' . $result['http_code']))
                ->with('redmine_status_type', $result['ok'] ? 'success' : 'danger')
                ->with('redmine_webhook_test_result', $result);
        }

        $config = $redmine->configuration();
        if ($request->input('config_action') === 'save_webhook') {
            $url = trim((string) $request->input('webhook_url', ''));
            if ($url === '' || $request->session()->get('redmine_webhook_tested_url') !== $url) {
                return redirect()
                    ->route('redmine.native.section', $this->routeParameters($redmine, ['section' => 'configuracion', 'panel' => 'webhook']))
                    ->withInput(['webhook_url' => $url])
                    ->with('redmine_status', 'Debes probar correctamente la conexion del webhook antes de guardar.')
                    ->with('redmine_status_type', 'danger');
            }
        }
        foreach ([
            'platform_url',
            'platform_token',
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
            'maintenance_until',
        ] as $field) {
            if ($request->has($field)) {
                $config[$field] = $request->input($field);
            }
        }
        if ($request->has('maintenance_mode')) {
            $maintenanceMode = $request->boolean('maintenance_mode');
            $config['maintenance_mode'] = $maintenanceMode;
            if (!$maintenanceMode) {
                $config['maintenance_until'] = '';
            } else {
                $maintenanceUntil = trim((string) $request->input('maintenance_until', ''));
                if ($maintenanceUntil !== '') {
                    try {
                        $until = \Carbon\Carbon::parse($maintenanceUntil, 'America/Santiago');
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
        $this->applyRedmineOptionAction($request, $config);
        $redmine->saveConfiguration($config);

        $response = back()->with('redmine_status', 'Configuracion guardada.');
        $optionType = (string) $request->input('opt_type', '');
        $optionAction = (string) $request->input('opt_action', '');
        if (in_array($optionType, ['trackers', 'prioridades', 'estados'], true) && in_array($optionAction, ['create', 'update', 'delete', 'set_default'], true)) {
            $response->with('redmine_open_options', $optionType);
        }

        return $response;
    }

    public function configurationExportAction(Request $request, RedmineDataRepository $redmine): Response|RedirectResponse
    {
        $this->prepare($request, $redmine);
        $selected = $this->maintenanceSections($request->input('maintenance_sections', []), $redmine);
        if ($selected === []) {
            return back()->with('redmine_status', 'Selecciona al menos una seccion para exportar.');
        }

        $bundle = $redmine->exportMaintenanceBundle($selected);
        $filename = 'mantencion-redmine-' . now('America/Santiago')->format('Ymd-His') . '.json';

        return response(json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function configurationImportAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $selected = $this->maintenanceSections($request->input('maintenance_sections', []), $redmine);
        if ($selected === []) {
            return back()->with('redmine_status', 'Selecciona al menos una seccion para importar.');
        }

        $file = $request->file('maintenance_file');
        if (!$file || !$file->isValid()) {
            return back()->with('redmine_status', 'No se pudo leer el archivo de importacion.');
        }

        $bundle = json_decode((string) file_get_contents($file->getRealPath()), true);
        if (!is_array($bundle)) {
            return back()->with('redmine_status', 'El archivo importado no es un respaldo JSON valido.');
        }

        try {
            $written = $redmine->importMaintenanceBundle($bundle, $selected);
        } catch (\Throwable $exception) {
            return back()->with('redmine_status', 'Error al importar: ' . $exception->getMessage());
        }

        return back()->with('redmine_status', 'Importacion completada. Archivos actualizados: ' . $written . '.');
    }

    public function historyAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $deleted = $redmine->deleteArchivedReport((string) $request->input('id'));

        return back()->with('redmine_status', $deleted . ' registro(s) historico(s) eliminado(s).');
    }

    public function hoursAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $action = (string) $request->input('action', 'save');
        $source = (string) $request->input('_source_file');
        if ($action === 'delete') {
            $deleted = $redmine->deleteHoursGroup($source, (string) $request->input('fecha'));
            return back()->with('redmine_status', $deleted . ' grupo(s) eliminado(s).');
        }

        $redmine->saveHoursGroup($source, $request->all());

        return back()->with('redmine_status', 'Grupo de horas extra guardado.');
    }

    public function activityAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $redmine->clearActivity();

        return back()->with('redmine_status', 'Registro de actividad limpiado.');
    }

    public function webhookAction(Request $request, RedmineDataRepository $redmine): RedirectResponse
    {
        $this->prepare($request, $redmine);
        if ($blocked = $this->maintenanceBlock($redmine)) {
            return $blocked;
        }

        $result = $redmine->sendWebhookMessage($request->all());
        $message = $result['ok']
            ? 'Mensaje enviado al webhook Python. HTTP ' . $result['http_code'] . '.'
            : 'No se pudo enviar al webhook Python: ' . ($result['error'] ?: 'HTTP ' . $result['http_code'] . ' - ' . $result['body']);

        return back()
            ->with('redmine_status', $message)
            ->with('redmine_status_type', $result['ok'] ? 'success' : 'danger')
            ->with('redmine_webhook_result', $result);
    }

    /**
     * @param mixed $value
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
     * @param array<string,mixed> $parameters
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

    /**
     * @param mixed $value
     * @return string[]
     */
    private function maintenanceSections($value, RedmineDataRepository $redmine): array
    {
        $available = array_keys($redmine->maintenanceSections());

        return array_values(array_intersect(array_map('strval', (array) $value), $available));
    }

    private function maintenanceBlock(RedmineDataRepository $redmine): ?RedirectResponse
    {
        if (!$redmine->maintenanceModeEnabled()) {
            return null;
        }

        return back()->with('redmine_status', 'Modulo en mantencion: la edicion de datos esta desactivada temporalmente.');
    }

    private function isMaintenanceSettingsRequest(Request $request): bool
    {
        $allowed = ['_token', 'maintenance_mode', 'maintenance_until'];

        return collect(array_keys($request->except(['_token'])))->every(static fn (string $field): bool => in_array($field, $allowed, true));
    }

    /**
     * @param array<string,mixed> $config
     */
    private function applyRedmineOptionAction(Request $request, array &$config): void
    {
        $type = (string) $request->input('opt_type', '');
        $action = (string) $request->input('opt_action', '');
        $configKey = [
            'trackers' => 'tracker_id',
            'prioridades' => 'priority_id',
            'estados' => 'status_id',
        ][$type] ?? null;

        if ($configKey === null || !in_array($action, ['create', 'update', 'delete', 'set_default'], true)) {
            return;
        }

        $rows = array_values(array_filter((array) ($config[$type] ?? []), 'is_array'));
        $id = trim((string) $request->input('opt_id', ''));
        $name = trim((string) $request->input('opt_nombre', ''));
        if ($id === '' || ($action !== 'delete' && $action !== 'set_default' && $name === '')) {
            return;
        }

        if ($action === 'delete') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['id'] ?? '') !== $id));
            if ((string) ($config[$configKey] ?? '') === $id) {
                $config[$configKey] = $rows[0]['id'] ?? null;
                if (isset($rows[0])) {
                    $rows[0]['default'] = true;
                }
            }
            $config[$type] = $rows;
            return;
        }

        if ($action === 'set_default') {
            foreach ($rows as &$row) {
                $row['default'] = (string) ($row['id'] ?? '') === $id;
            }
            unset($row);
            $config[$configKey] = is_numeric($id) ? (int) $id : $id;
            $config[$type] = $rows;
            return;
        }

        $makeDefault = $request->boolean('opt_default');
        if ($makeDefault) {
            foreach ($rows as &$row) {
                $row['default'] = false;
            }
            unset($row);
            $config[$configKey] = is_numeric($id) ? (int) $id : $id;
        }

        $payload = [
            'id' => is_numeric($id) ? (int) $id : $id,
            'nombre' => $name,
            'default' => $makeDefault,
        ];

        $updated = false;
        foreach ($rows as $index => $row) {
            if ((string) ($row['id'] ?? '') !== $id) {
                continue;
            }
            $rows[$index] = array_merge($row, $payload);
            $updated = true;
            break;
        }

        if (!$updated) {
            $rows[] = $payload;
        }
        $config[$type] = $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function permissionPayload(Request $request): array
    {
        $scope = static fn (string $field): string => in_array((string) $request->input($field, 'asignados'), ['todos', 'asignados'], true)
            ? (string) $request->input($field, 'asignados')
            : 'asignados';

        return [
            'mensajes' => $scope('perm_mensajes_scope'),
            'mensajes_acceso' => $request->boolean('perm_mensajes_acceso'),
            'horas_extra' => $request->boolean('perm_horas_extra') ? $scope('perm_horas_scope') : '',
            'historico' => $request->boolean('perm_historico'),
            'historico_acciones' => $request->boolean('perm_historico_acciones'),
            'historico_scope' => $scope('perm_historico_scope'),
            'configuracion' => $request->boolean('perm_configuracion'),
            'estadisticas' => $request->boolean('perm_estadisticas'),
            'estadisticas_manual' => $request->boolean('perm_estadisticas_manual'),
            'usuarios' => $request->boolean('perm_usuarios'),
            'categorias' => $request->boolean('perm_categorias'),
            'unidades' => $request->boolean('perm_unidades'),
            'simulador' => $request->boolean('perm_simulador'),
            'reportes_editar' => $request->boolean('perm_reportes_editar'),
            'reportes_eliminar' => $request->boolean('perm_reportes_eliminar'),
            'horas_extra_editar' => $request->boolean('perm_horas_extra_editar'),
            'horas_extra_eliminar' => $request->boolean('perm_horas_extra_eliminar'),
            'usuarios_editar' => $request->boolean('perm_usuarios_editar'),
            'usuarios_eliminar' => $request->boolean('perm_usuarios_eliminar'),
            'cfg_resumen' => $request->boolean('perm_cfg_resumen'),
            'cfg_conexion' => $request->boolean('perm_cfg_conexion'),
            'cfg_proyecto' => $request->boolean('perm_cfg_proyecto'),
            'cfg_redmine' => $request->boolean('perm_cfg_redmine'),
            'cfg_campos' => $request->boolean('perm_cfg_campos'),
            'cfg_retencion' => $request->boolean('perm_cfg_retencion'),
            'cfg_webhook' => $request->boolean('perm_cfg_webhook'),
            'cfg_sesion' => $request->boolean('perm_cfg_sesion'),
            'cfg_mantencion' => $request->boolean('perm_cfg_mantencion'),
            'cfg_trackers' => $request->boolean('perm_cfg_trackers'),
            'cfg_prioridades' => $request->boolean('perm_cfg_prioridades'),
            'cfg_estados' => $request->boolean('perm_cfg_estados'),
            'cfg_roles' => $request->boolean('perm_cfg_roles'),
            'cfg_usuarios' => $request->boolean('perm_cfg_usuarios'),
            'cfg_catalogos' => $request->boolean('perm_cfg_catalogos'),
            'cfg_categorias' => $request->boolean('perm_cfg_categorias'),
            'cfg_unidades' => $request->boolean('perm_cfg_unidades'),
            'actividad' => $request->boolean('perm_actividad'),
        ];
    }

    /**
     * @param string[] $ids
     */
    private function sendReports(Request $request, RedmineDataRepository $redmine, array $ids): string
    {
        $user = $request->session()->get('redmine_project_user', $request->session()->get('nova_user', []));
        $result = $redmine->sendReportsToRedmine($ids, is_array($user) ? ($user['id'] ?? null) : null);
        $parts = [
            $result['success'] . ' ticket(s) enviados de ' . $result['attempts'] . ' intento(s).',
        ];
        if ($result['redmine_ids']) {
            $parts[] = 'Redmine ID(s): ' . implode(', ', $result['redmine_ids']) . '.';
        }
        if ($result['errors']) {
            $parts[] = implode(' ', array_slice($result['errors'], 0, 3));
        }

        return implode(' ', $parts);
    }
}
