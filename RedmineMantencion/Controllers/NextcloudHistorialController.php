<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionNextcloudService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class NextcloudHistorialController extends Controller
{
    public function __construct(private readonly MantencionNextcloudService $nextcloud)
    {
    }

    /**
     * Historial Nextcloud. Migrado desde
     * RedmineMantencion/views/Integraciones/NextcloudHistorial.php.
     */
    public function index(): View|RedirectResponse
    {
        require_once base_path('RedmineMantencion/controllers/auth.php');
        require_once base_path('RedmineMantencion/controllers/nextcloud.php');

        if (!auth_can('integraciones_nextcloud')) {
            abort(403, 'No tienes permiso para administrar Nextcloud.');
        }

        if (request()->isMethod('post') && request()->input('action') === 'create_manual_report') {
            csrf_validate();
            if (!auth_can('simulador')) {
                abort(403, 'No tienes permiso para crear reportes manuales.');
            }

            $draft = $this->nextcloud->nextcloud_manual_report_draft((int)request()->input('numero_lote', 0));
            if ($draft === null) {
                $this->nextcloud->nextcloud_set_flash('No fue posible encontrar el lote seleccionado.');

                return redirect()->to(url('/redmine-mantencion/app/integraciones-nextcloud-historial'), 303);
            }

            session()->put('mantencion_manual_pending_prefill', $draft);

            return redirect()->to(url('/redmine-mantencion/app/manual'), 303);
        }

        $h = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $csrf = legacy_csrf_token();
        $nextcloudHistoryActionUrl = url('/redmine-mantencion/app/integraciones-nextcloud-historial');
        $canCreateManualReport = auth_can('simulador');
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
