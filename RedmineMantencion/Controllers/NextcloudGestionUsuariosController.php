<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionNextcloudService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class NextcloudGestionUsuariosController extends Controller
{
    public function __construct(private readonly MantencionNextcloudService $nextcloud) {}

    public function index(): View
    {
        $this->bootAndAuthorize();

        // Reutiliza el catálogo guardado desde Configuración > Nextcloud > Grupos.
        // Entrar a esta pantalla nunca debe disparar una consulta remota.
        $nextcloudCfg = $this->nextcloud->nextcloud_config();
        $nextcloudGroups = $this->nextcloud->nextcloud_cached_groups();
        $directoryError = '';
        $nextcloudServer = trim((string) parse_url((string) ($nextcloudCfg['url'] ?? ''), PHP_URL_HOST));
        $flash = session()->pull('mantencion_nextcloud_management_flash', []);
        $flash = is_array($flash) ? $flash : [];
        $h = fn ($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
        $csrf = legacy_csrf_token();
        $managementUrl = route('redmine.mantencion.nextcloud-users.manage');
        $groupUsersUrl = route('redmine.mantencion.nextcloud-users.group-users');
        $credentialsUrl = route('integrations.redmine_mantencion');
        $groupsConfigUrl = route('redmine.mantencion.section', [
            'section' => 'configuracion',
            'panel' => 'nextcloud',
        ]);
        $maintenanceMode = function_exists('maintenance_mode_enabled') && maintenance_mode_enabled();
        $hasSavedNextcloudCredentials = ! empty($nextcloudCfg['has_password']);

        return view('redmine-mantencion.integraciones-nextcloud-gestion-usuarios', get_defined_vars());
    }

    public function update(): RedirectResponse
    {
        $this->bootAndAuthorize();
        if (function_exists('csrf_validate')) {
            csrf_validate();
        }
        if (function_exists('maintenance_mode_block_if_enabled')) {
            maintenance_mode_block_if_enabled();
        }

        $result = $this->nextcloud->nextcloud_change_user_password(request()->only([
            'userid',
            'password',
            'password_confirmation',
        ]));
        session()->put('mantencion_nextcloud_management_flash', [
            'type' => (string) ($result['type'] ?? (! empty($result['ok']) ? 'success' : 'error')),
            'message' => (string) ($result['message'] ?? 'No fue posible guardar los cambios.'),
        ]);

        $returnGroup = trim((string) request()->input('return_group', ''));
        $returnGroup = function_exists('mb_substr') ? mb_substr($returnGroup, 0, 255) : substr($returnGroup, 0, 255);

        return redirect()->route(
            'redmine.mantencion.nextcloud-users.manage',
            $returnGroup !== '' ? ['group' => $returnGroup] : [],
            303
        );
    }

    public function groupUsers(): JsonResponse
    {
        $this->bootAndAuthorize();
        $result = $this->nextcloud->nextcloud_group_users((string) request()->query('group', ''));

        if (isset($result['error'])) {
            return response()->json([
                'ok' => false,
                'message' => (string) $result['error'],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'group' => (string) ($result['group'] ?? ''),
            'users' => is_array($result['users'] ?? null) ? $result['users'] : [],
        ]);
    }

    private function bootAndAuthorize(): void
    {
        require_once base_path('RedmineMantencion/controllers/auth.php');
        require_once base_path('RedmineMantencion/controllers/nextcloud.php');

        if (! auth_can('integraciones_nextcloud')) {
            abort(403, 'No tienes permiso para administrar Nextcloud.');
        }
    }
}
