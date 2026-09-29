<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Repositories\MantencionPlantillaManualRepository;
use App\Modulos\RedmineMantencion\Services\MantencionPendientesService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;

class PendientesController extends Controller
{
    public function __construct(
        private readonly MantencionPendientesService $pendientes,
        private readonly MantencionPlantillaManualRepository $plantillas,
    )
    {
    }

    /**
     * Pendiente Manual. Migrado desde RedmineMantencion/views/Pendientes/manual.php.
     */
    public function index(): View|RedirectResponse|JsonResponse
    {
        require_once base_path('RedmineMantencion/controllers/pendiente_manual.php');

        if (!auth_can('simulador')) {
            return redirect(legacy_app_url());
        }

        $novaUserId = trim((string) (session('nova_user')['id'] ?? ''));
        if ($novaUserId === '') {
            $standaloneId = trim((string) (session('mantencion_standalone_user')['id'] ?? ''));
            $novaUserId = $standaloneId === '' ? '' : 'standalone:'.$standaloneId;
        }
        if ($novaUserId === '') {
            abort(403);
        }

        if (request()->isMethod('post') && request()->has('plantilla_accion')) {
            if (function_exists('csrf_validate')) {
                csrf_validate();
            }
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }

            $action = (string) request('plantilla_accion');
            if ($action === 'guardar' || $action === 'actualizar') {
                $nombre = trim((string) request('plantilla_nombre', ''));
                $asunto = trim((string) request('plantilla_asunto', ''));
                $categoria = $this->pendientes->normalizeCategoryName(
                    request('plantilla_categoria', ''),
                    $this->pendientes->categoryOptions()
                );
                $id = (int) request('plantilla_id', 0);
                if ($nombre === '' || $asunto === '' || $categoria === '' || mb_strlen($nombre) > 120 || mb_strlen($asunto) > 255 || ($action === 'actualizar' && $id < 1)) {
                    session()->flash('mantencion_plantilla_error', 'Ingresa nombre y asunto, y selecciona una categoría vigente.');
                } elseif ($this->plantillas->nameExists($novaUserId, $nombre, $action === 'actualizar' ? $id : null)) {
                    session()->flash('mantencion_plantilla_error', 'Ya tienes una plantilla con ese nombre.');
                } else {
                    try {
                        if ($action === 'actualizar') {
                            $saved = $this->plantillas->update($novaUserId, $id, $nombre, $asunto, $categoria);
                        } else {
                            $this->plantillas->create($novaUserId, $nombre, $asunto, $categoria);
                            $saved = true;
                        }
                        session()->flash($saved ? 'mantencion_plantilla_flash' : 'mantencion_plantilla_error',
                            $saved ? 'Plantilla guardada correctamente.' : 'No se encontró la plantilla para editar.');
                    } catch (QueryException) {
                        session()->flash('mantencion_plantilla_error', 'No se pudo guardar la plantilla. Revisa si el nombre ya existe.');
                    }
                }
            } elseif ($action === 'eliminar') {
                $this->plantillas->delete($novaUserId, (int) request('plantilla_id', 0));
                session()->flash('mantencion_plantilla_flash', 'Plantilla eliminada.');
            } else {
                abort(400);
            }

            if (request()->expectsJson()) {
                $error = session()->pull('mantencion_plantilla_error');
                $message = session()->pull('mantencion_plantilla_flash');

                return response()->json([
                    'ok' => $error === null,
                    'message' => $error ?? $message,
                    'plantillas' => $this->plantillas->all($novaUserId),
                ], $error === null ? 200 : 422);
            }

            return redirect(url('/redmine-mantencion/app/manual').'?plantillas=1');
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
        $flash = session()->pull('mantencion_plantilla_flash') ?: $flash;
        $error = session()->pull('mantencion_plantilla_error') ?: $error;
        $plantillasManuales = $this->plantillas->all($novaUserId);
        $plantillaModalOpen = request()->query('plantillas') === '1';

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
        $pendientesService = $this->pendientes;

        return view('redmine-mantencion.pendientes-manual', get_defined_vars());
    }
}
