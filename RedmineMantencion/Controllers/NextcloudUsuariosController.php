<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionNextcloudService;
use Illuminate\Contracts\View\View;

class NextcloudUsuariosController extends Controller
{
    public function __construct(private readonly MantencionNextcloudService $nextcloud)
    {
    }

    /**
     * Crear usuarios Nextcloud por lotes. Migrado desde
     * RedmineMantencion/views/Integraciones/NextcloudUsuarios.php.
     */
    public function index(): View
    {
        require_once base_path('RedmineMantencion/controllers/auth.php');
        require_once base_path('RedmineMantencion/controllers/nextcloud.php');

        if (!auth_can('integraciones_nextcloud')) {
            abort(403, 'No tienes permiso para administrar Nextcloud.');
        }

        [$flash, $nextcloudCfg, $nextcloudGroups, $lastImport, $preview] = $this->nextcloud->handle_nextcloud();
        $h = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $csrf = legacy_csrf_token();
        $nextcloudUsersActionUrl = function_exists('url') ? url('/redmine-mantencion/app/integraciones-nextcloud-usuarios') : legacy_app_url('app/integraciones-nextcloud-usuarios');
        $maintenanceMode = function_exists('maintenance_mode_enabled') && maintenance_mode_enabled();
        $hasSavedNextcloudCredentials = (function_exists('auth_get_user_id') && nextcloud_credentials_has_saved((string) auth_get_user_id()))
            || (trim((string) ($nextcloudCfg['admin_user'] ?? '')) !== '' && trim((string) ($nextcloudCfg['admin_pass'] ?? '')) !== '');
        $previewUsers = is_array($preview['users'] ?? null) ? $preview['users'] : [];
        $hasInvalidPreview = false;
        foreach ($previewUsers as $item) {
            if (empty($item['groups']) || empty($item['email_valid']) || !empty($item['duplicate_in_file'])) {
                $hasInvalidPreview = true;
                break;
            }
        }

        return view('redmine-mantencion.integraciones-nextcloud-usuarios', get_defined_vars());
    }
}
