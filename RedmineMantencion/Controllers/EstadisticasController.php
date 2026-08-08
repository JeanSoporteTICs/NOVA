<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionEstadisticasService;
use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\View\View;

class EstadisticasController extends Controller
{
    public function __construct(private readonly MantencionEstadisticasService $estadisticas)
    {
    }

    /**
     * Estadísticas. Migrado desde RedmineMantencion/views/Estadisticas/estadisticas.php.
     */
    public function index(): View
    {
        require_once base_path('RedmineMantencion/controllers/auth.php');
        require_once base_path('RedmineMantencion/controllers/dashboard.php');

        if (!auth_can('estadisticas')) {
            return redirect(legacy_app_url());
        }

        // Si es gestor, solo ve sus propios reportes
        $role = auth_get_user_role();
        $currentUserId = auth_get_user_id();
        if ($role === 'gestor') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $_POST['usuario'] = $currentUserId;
            } else {
                $_GET['usuario'] = $currentUserId;
            }
        }
        unset($_POST['unidad'], $_GET['unidad']);

        $stats = $this->estadisticas->handle();
        $h = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $csrf = legacy_csrf_token();
        $statsActionUrl = function_exists('url') ? url('/redmine-mantencion/app/estadisticas') : legacy_app_url('app/estadisticas');
        // valores de filtro (para mostrar rango aplicado)
        $fmtDMY = function ($dateStr) {
            $dt = DateTime::createFromFormat('Y-m-d', $dateStr);

            return $dt ? $dt->format('d-m-Y') : $dateStr;
        };
        $desdeVal = $_POST['desde'] ?? $_GET['desde'] ?? '';
        $hastaVal = $_POST['hasta'] ?? $_GET['hasta'] ?? '';
        $desdeVal = $this->estadisticas->normalizeDate($desdeVal);
        $hastaVal = $this->estadisticas->normalizeDate($hastaVal);
        if ($desdeVal !== '' && $hastaVal !== '' && $desdeVal > $hastaVal) {
            [$desdeVal, $hastaVal] = [$hastaVal, $desdeVal];
        }
        $periodoLabel = 'Todos';
        if ($desdeVal && $hastaVal) {
            $periodoLabel = $fmtDMY($desdeVal) . ' a ' . $fmtDMY($hastaVal);
        } elseif ($desdeVal || $hastaVal) {
            $periodoLabel = $fmtDMY($desdeVal ?: $hastaVal);
        }
        // Formato de fecha/hora Chile continental
        $actualizadoTxt = '';
        if (!empty($stats['actualizado'])) {
            try {
                $dt = new DateTime($stats['actualizado']);
                $dt->setTimezone(new DateTimeZone('America/Santiago'));
                $actualizadoTxt = $dt->format('d-m-Y H:i:s') . ' (Chile continental)';
            } catch (Exception $e) {
                $actualizadoTxt = $stats['actualizado'];
            }
        }
        $yearNow = date('Y');
        $monthNow = (int) date('n');
        $timelineReference = $this->estadisticas->normalizeDate($desdeVal) ?: date('Y-m-d');
        $timelineReferenceYear = (int) substr($timelineReference, 0, 4);
        $timelineReferenceMonth = (int) substr($timelineReference, 5, 2);
        $cats = [];
        $users = [];
        $selectedUserId = $_POST['usuario'] ?? $_GET['usuario'] ?? '';
        $selectedUserLabel = '';
        $selectedCategoria = $_POST['categoria'] ?? $_GET['categoria'] ?? '';
        $catalogRepo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
        $cats = $catalogRepo !== null ? $catalogRepo->categoriaNames() : [];
        sort($cats, SORT_NATURAL | SORT_FLAG_CASE);
        $parsed = function_exists('auth_central_users_for_mantencion') ? auth_central_users_for_mantencion() : [];
        if (is_array($parsed)) {
            foreach ($parsed as $u) {
                if (!is_array($u)) {
                    continue;
                }
                $estadoUsuario = strtolower(trim((string) ($u['estado'] ?? $u['estado_usuario'] ?? 'activo')));
                if (!in_array($estadoUsuario, ['activo', 'active'], true)) {
                    continue;
                }
                $id = (string) ($u['id'] ?? '');
                $nombre = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''));
                if ($id !== '') {
                    $users[$id] = $nombre ?: $id;
                }
            }
        }
        asort($users, SORT_NATURAL | SORT_FLAG_CASE);
        $selectedUserLabel = '';
        if ($selectedUserId !== '') {
            if (isset($users[$selectedUserId])) {
                $selectedUserLabel = ($users[$selectedUserId] ?? '') . ' (ID ' . $selectedUserId . ')';
            } elseif (in_array($selectedUserId, $users, true)) {
                $selectedUserLabel = $selectedUserId;
            }
        }
        $userNameMap = $users;

        return view('redmine-mantencion.estadisticas', get_defined_vars());
    }
}
