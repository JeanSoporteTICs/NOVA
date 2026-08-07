<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionPendientesService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class PendientesController extends Controller
{
    public function __construct(private readonly MantencionPendientesService $pendientes)
    {
    }

    /**
     * Pendiente Manual. Migrado desde RedmineMantencion/views/Pendientes/manual.php.
     */
    public function index(): View|RedirectResponse
    {
        require_once base_path('RedmineMantencion/controllers/pendiente_manual.php');

        if (!auth_can('simulador')) {
            return redirect(legacy_app_url());
        }

        $h = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $csrf = legacy_csrf_token();
        $laravelCsrf = function_exists('csrf_token') ? csrf_token() : '';
        $manualPendingUrl = function_exists('url')
            ? url('/redmine-mantencion/app/manual')
            : legacy_app_url('app/manual');

        $result = $this->pendientes->handleManualPending();
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        [$cfg, $users, $categorias, $form, $flash, $error] = $result;

        $maintenanceMode = function_exists('maintenance_mode_enabled') && maintenance_mode_enabled();
        $canAssignOtherUsers = dashboard_can_assign_other_users();
        $currentUser = dashboard_current_user();
        $assignMeUserId = $this->pendientes->normalizeUserId((string) ($currentUser['id'] ?? auth_get_user_id() ?? ''), $users);
        if ($assignMeUserId === '') {
            $currentUserName = trim((string) ($currentUser['nombre_completo'] ?? ''));
            foreach ($users as $user) {
                if ($currentUserName !== '' && strcasecmp($currentUserName, trim((string) ($user['nombre'] ?? ''))) === 0) {
                    $assignMeUserId = (string) ($user['id'] ?? '');
                    break;
                }
            }
        }
        $categoryOptions = array_values(array_unique(array_filter(array_map(
            fn ($categoria) => trim((string) (is_array($categoria) ? ($categoria['nombre'] ?? $categoria['id'] ?? '') : $categoria)),
            is_array($categorias) ? $categorias : []
        ))));
        $categoryOptionsJson = htmlspecialchars(
            json_encode($categoryOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
            ENT_QUOTES,
            'UTF-8'
        );

        $pendientesService = $this->pendientes;

        return view('redmine-mantencion.pendientes-manual', get_defined_vars());
    }
}
