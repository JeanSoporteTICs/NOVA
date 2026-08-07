<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionNextcloudService;
use Illuminate\Contracts\View\View;

class NextcloudHistorialController extends Controller
{
    public function __construct(private readonly MantencionNextcloudService $nextcloud)
    {
    }

    /**
     * Historial Nextcloud. Migrado desde
     * RedmineMantencion/views/Integraciones/NextcloudHistorial.php.
     */
    public function index(): View
    {
        require_once base_path('RedmineMantencion/controllers/auth.php');
        require_once base_path('RedmineMantencion/controllers/nextcloud.php');

        if (!auth_can('integraciones_nextcloud')) {
            abort(403, 'No tienes permiso para administrar Nextcloud.');
        }

        $h = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $flash = $this->nextcloud->nextcloud_consume_flash();
        $batches = $this->nextcloud->nextcloud_created_history_load();
        $historyGroups = [];
        foreach ($batches as $batch) {
            foreach (['result_users', 'created_users', 'existing_users', 'failed_users', 'users'] as $collection) {
                foreach ((array) ($batch[$collection] ?? []) as $item) {
                    $group = trim((string) ($item['group'] ?? ''));
                    if ($group !== '') {
                        $historyGroups[$group] = true;
                    }
                }
            }
        }
        $historyGroups = array_keys($historyGroups);
        natcasesort($historyGroups);
        $historyGroups = array_values($historyGroups);

        return view('redmine-mantencion.integraciones-nextcloud-historial', get_defined_vars());
    }
}
