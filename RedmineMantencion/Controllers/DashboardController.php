<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use App\Modulos\RedmineMantencion\Services\MantencionDashboardService;
use App\Modulos\RedmineMantencion\Services\MantencionRedmineSyncService;
use App\Modulos\RedmineMantencion\Services\MantencionRetentionService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly MantencionDashboardService $dashboardService,
        private readonly MantencionCoreImportService $coreImport,
        private readonly MantencionRedmineSyncService $redmineSync,
        private readonly MantencionRetentionService $retention,
    ) {
    }

    /**
     * Reportes (pantalla principal de Redmine Mantención). Migrado desde
     * RedmineMantencion/views/Dashboard/dashboard.php: esta parte era la
     * lógica de "preparación de datos" del archivo original (líneas 1-182),
     * ahora movida aquí. El HTML se conserva prácticamente intacto en
     * resources/views/redmine-mantencion/dashboard.blade.php.
     */
    public function index(): View|RedirectResponse
    {
        require_once base_path('RedmineMantencion/controllers/dashboard.php');

        if (!auth_can('mensajes_acceso')) {
            abort(403, 'No tienes permiso para ver Reportes.');
        }

        // Expuestos a la vista para las llamadas que antes eran funciones
        // globales y ahora viven en las clases de servicio (ver más abajo).
        $coreImportService = $this->coreImport;
        $redmineSyncService = $this->redmineSync;

        $flashSession = session()->pull('mantencion_flash');
        $openCoreCredentialsModal = (bool) session()->pull('mantencion_dashboard_open_core_credentials_modal', false);
        $coreRuntimeUserSession = trim((string) session()->pull('mantencion_dashboard_core_runtime_user', ''));

        $dashboardResult = $this->dashboardService->handle_request();
        if ($dashboardResult instanceof RedirectResponse) {
            return $dashboardResult;
        }
        [$messages, $flash, $securityLog] = $dashboardResult;
        if ($flashSession) {
            $flash = $flashSession;
        }

        $pendientes = array_filter($messages, fn ($m) => strtolower($m['estado'] ?? '') === 'pendiente');

        $procesados = array_filter($messages, fn ($m) => strtolower($m['estado'] ?? '') === 'procesado');

        $errores = array_filter($messages, fn ($m) => strtolower($m['estado'] ?? '') === 'error');

        $cfg = load_platform_config();
        $mantencionBaseUrl = function_exists('legacy_app_url')
            ? rtrim(legacy_app_url(), '/')
            : (function_exists('url') ? rtrim(url('/redmine-mantencion'), '/') : '/redmine-mantencion');
        $dashboardActionUrl = function_exists('route')
            ? route('redmine.mantencion.section', ['section' => 'dashboard'])
            : $mantencionBaseUrl . '/app/dashboard';
        $dashboardHoursExtraActionUrl = function_exists('route')
            ? route('redmine.mantencion.dashboard.hours-extra')
            : $dashboardActionUrl;
        $chileToday = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d');
        $coreDesde = $_GET['core_desde'] ?? $chileToday;
        $coreHasta = $_GET['core_hasta'] ?? $chileToday;
        $coreAssignedName = $this->coreImport->dashboard_can_select_core_assignee()
            ? (string) ($_GET['core_assigned_name'] ?? $this->coreImport->dashboard_default_core_assigned_name())
            : $this->coreImport->dashboard_default_core_assigned_name();
        $currentRole = auth_get_user_role();
        $canEditReports = auth_can('reportes_editar');
        $canDeleteReports = auth_can('reportes_eliminar');
        $canImportCore = auth_can('reportes_importar_core');
        $canEditHoursExtra = auth_can('horas_extra_editar');
        $canSelectReports = $canEditReports || $canDeleteReports;
        $canUnlockProcessedActions = $canSelectReports || $canEditHoursExtra;
        $hasSavedCoreCredentials = $this->coreImport->dashboard_core_has_saved_credentials();
        $maintenanceMode = function_exists('maintenance_mode_enabled') && maintenance_mode_enabled();

        $retencionHoras = $this->retention->get_retencion_horas();

        $userOptions = [];
        $userLookup = [];
        foreach (dashboard_active_mantencion_users() as $u) {
            $displayName = trim((string) ($u['nombre_completo'] ?? ''));
            if ($displayName === '' || empty($u['id'])) {
                continue;
            }
            $userOptions[] = [
                'id' => $u['id'],
                'nombre' => $displayName,
            ];
            $userLookup[$u['id']] = $displayName;
            $phoneKey = $this->normalizePhoneKey($u['numero_celular'] ?? '');
            if ($phoneKey !== '') {
                $userLookup[$phoneKey] = $displayName;
            }
            $rutKey = $this->normalizeRutKey($u['rut'] ?? '');
            if ($rutKey !== '') {
                $userLookup[$rutKey] = $displayName;
            }
        }
        $userMap = [];
        if (count($userOptions) > 0) {
            $userMap = array_combine(array_column($userOptions, 'id'), array_column($userOptions, 'nombre'));
        }

        $catOptions = [];
        $catalogRepo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
        $catOptions = $catalogRepo !== null ? $catalogRepo->categoriaNames() : [];

        $unitOptions = [];
        $unitOptions = $catalogRepo !== null ? $catalogRepo->unidadNames() : [];

        $tipoOptions = [];
        $prioridadOptions = [];
        $estadoOptions = ['pendiente', 'procesado', 'error']; // estados locales (dashboard)
        $estadoRedmineId = null;
        $estadoRedmineNombre = null;
        $logsByMessage = $this->redmineSync->load_redmine_logs_by_message();
        $cfgData = function_exists('load_platform_config') ? load_platform_config() : [];
        if (is_array($cfgData)) {
            foreach (($cfgData['trackers'] ?? []) as $t) {
                if (is_array($t) && isset($t['nombre'])) {
                    $tipoOptions[] = $t['nombre'];
                }
            }
            foreach (($cfgData['prioridades'] ?? []) as $pOpt) {
                if (is_array($pOpt) && isset($pOpt['nombre'])) {
                    $prioridadOptions[] = $pOpt['nombre'];
                }
            }
            // Estado de Redmine configurado
            $estadoRedmineId = $cfgData['status_id'] ?? null;
            if ($estadoRedmineId) {
                foreach (($cfgData['estados'] ?? []) as $eOpt) {
                    if (is_array($eOpt) && isset($eOpt['id']) && (int) $eOpt['id'] === (int) $estadoRedmineId) {
                        $estadoRedmineNombre = $eOpt['nombre'] ?? null;
                        break;
                    }
                }
            }
            // estados de Redmine se usan para configurar status_id, no para el flujo local del dashboard
        }

        $h = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $csrf = legacy_csrf_token();

        return view('redmine-mantencion.dashboard', get_defined_vars());
    }

    /**
     * Toggle de "hora extra" sobre un reporte del dashboard. Migrado desde
     * Nova/Controllers/LegacyProjectController::toggleMantencionHoursExtra().
     */
    public function toggleHoursExtra(Request $request): JsonResponse
    {
        require_once base_path('RedmineMantencion/controllers/dashboard.php');

        // /redmine-mantencion/* está exento del middleware VerifyCsrfToken
        // (ver Http/Middleware/VerifyCsrfToken::inExceptArray(), activado por
        // allowed_php_roots no vacío en config/modules.php para los shims
        // legacy restantes), así que esta ruta valida el token a mano.
        $submittedToken = (string) $request->input('_token', $request->header('X-CSRF-TOKEN', ''));
        if ($submittedToken === '' || !hash_equals((string) $request->session()->token(), $submittedToken)) {
            return response()->json(['ok' => false, 'message' => 'La validación de seguridad venció. Recarga la página.'], 419);
        }

        if (!auth_can('horas_extra_editar')) {
            return response()->json(['ok' => false, 'message' => 'No tienes permiso para editar Horas extra.'], 403);
        }

        $id = trim((string) $request->input('id', ''));
        $messages = load_messages();
        if ($id === '' || !dashboard_can_access_message($messages, $id)) {
            return response()->json(['ok' => false, 'message' => 'No se encontró la solicitud o no tienes acceso.'], 404);
        }

        $updatedMessage = null;
        $enabled = false;
        foreach ($messages as $message) {
            if ((string) ($message['id'] ?? '') !== $id) {
                continue;
            }
            $enabled = normalize_hour_extra_value($message['hora_extra'] ?? '') !== '1';
            $message['hora_extra'] = $enabled ? '1' : '0';
            $message['tiempo_estimado'] = $enabled ? '1' : '';
            $updatedMessage = $message;
            break;
        }

        if (!is_array($updatedMessage) || !dashboard_update_message_hora_extra($updatedMessage)) {
            return response()->json(['ok' => false, 'message' => 'No se pudo actualizar la hora extra.'], 422);
        }

        if ($enabled) {
            append_hours_extra_record($updatedMessage);
        } else {
            remove_hours_extra_record_by_id($id);
        }
        dashboard_log_action('HORA_EXTRA', ($enabled ? 'Activo' : 'Desactivo') . ' hora extra en reporte ID ' . $id);

        return response()->json([
            'ok' => true,
            'message' => $enabled ? 'Hora extra activada.' : 'Hora extra desactivada.',
            'row' => [
                'id' => $id,
                'hora_extra' => $enabled ? '1' : '0',
                'tiempo_estimado' => $updatedMessage['tiempo_estimado'],
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizePhoneKey($value): string
    {
        $digits = preg_replace('/\D/', '', $value ?? '');
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) > 9) {
            $digits = substr($digits, -9);
        }

        return $digits;
    }

    private function normalizeRutKey($value): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', $value ?? ''));
    }
}
