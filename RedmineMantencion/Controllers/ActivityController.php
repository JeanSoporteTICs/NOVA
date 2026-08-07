<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionSecurityService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Throwable;

class ActivityController extends Controller
{
    public function __construct(private readonly MantencionSecurityService $security)
    {
    }

    /**
     * Actividad reciente. Migrado desde RedmineMantencion/views/Security/activity.php.
     */
    public function index(): View|RedirectResponse
    {
        require_once base_path('RedmineMantencion/controllers/auth.php');
        require_once base_path('RedmineMantencion/controllers/maintenance.php');

        if (!auth_can('actividad')) {
            abort(403, 'No tienes permiso para ver Actividad reciente.');
        }

        $flash = session()->pull('mantencion_security_flash');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_validate();
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }
            if (($_POST['action'] ?? '') === 'clear_activity') {
                if (!auth_can('actividad_eliminar')) {
                    abort(403, 'No tienes permiso para eliminar la bitácora de actividad.');
                }
                $clearActor = mantencion_current_user() ?? [];
                $viewerName = trim((string) (($clearActor['nombre'] ?? '') . ' ' . ($clearActor['apellido'] ?? '')));
                $deleted = $this->security->clearUserEvents($viewerName, (string) ($clearActor['id'] ?? ''));
                if (function_exists('log_security_event')) {
                    log_security_event(
                        'ACTIVITY_CLEAR',
                        sprintf(
                            'Actividad reciente limpiada por %s (ID %s)',
                            (string) ($clearActor['nombre'] ?? 'usuario'),
                            (string) ($clearActor['id'] ?? '')
                        )
                    );
                }
                session()->put('mantencion_security_flash', $deleted . ' evento(s) propios eliminados de la bitácora.');
            }
            $activityUrl = function_exists('url') ? url('/redmine-mantencion/app/actividad') : legacy_app_url('app/actividad');

            return redirect($activityUrl);
        }

        $h = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $formatSecurityTimestamp = fn ($ts) => (function ($value) {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }
            try {
                $dt = new DateTimeImmutable($value);
            } catch (Throwable $_) {
                return $value;
            }

            return $dt->setTimezone(new DateTimeZone('America/Santiago'))->format('d-m-Y H:i:s');
        })($ts);
        $selectedTag = strtoupper(trim((string) ($_GET['tag'] ?? '')));
        $selectedChannel = strtolower(trim((string) ($_GET['canal'] ?? '')));
        $search = trim((string) ($_GET['buscar'] ?? ''));
        $dateFrom = trim((string) ($_GET['desde'] ?? ''));
        $dateTo = trim((string) ($_GET['hasta'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = (int) ($_GET['per_page'] ?? 50);
        $activityCurrentUser = mantencion_current_user() ?? [];
        $activityViewerName = trim((string) (($activityCurrentUser['nombre'] ?? '') . ' ' . ($activityCurrentUser['apellido'] ?? '')));
        $activityResult = $this->security->searchEvents([
            'tag' => $selectedTag,
            'canal' => $selectedChannel,
            'buscar' => $search,
            'desde' => $dateFrom,
            'hasta' => $dateTo,
        ], $page, $perPage, $activityViewerName, auth_can('actividad_todos'), (string) ($activityCurrentUser['id'] ?? ''));
        $events = $activityResult['events'];
        $eventTags = $activityResult['tags'];
        $eventChannels = $activityResult['channels'];
        $totalEvents = (int) $activityResult['total'];
        $page = (int) $activityResult['page'];
        $perPage = (int) $activityResult['per_page'];
        $totalPages = (int) $activityResult['pages'];
        $hasFilters = $selectedTag !== '' || $selectedChannel !== '' || $search !== '' || $dateFrom !== '' || $dateTo !== '';
        $pageUrl = static function (int $targetPage) use ($selectedTag, $selectedChannel, $search, $dateFrom, $dateTo, $perPage): string {
            return 'activity.php?' . http_build_query(array_filter([
                'tag' => $selectedTag,
                'canal' => $selectedChannel,
                'buscar' => $search,
                'desde' => $dateFrom,
                'hasta' => $dateTo,
                'per_page' => $perPage,
                'page' => max(1, $targetPage),
            ], static fn ($value): bool => $value !== ''));
        };
        $activeNav = 'security';
        $csrf = legacy_csrf_token();
        $activityActionUrl = function_exists('url') ? url('/redmine-mantencion/app/actividad') : legacy_app_url('app/actividad');

        return view('redmine-mantencion.actividad', get_defined_vars());
    }
}
