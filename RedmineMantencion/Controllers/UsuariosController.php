<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionUsuariosService;
use Illuminate\Contracts\View\View;

class UsuariosController extends Controller
{
    public function __construct(private readonly MantencionUsuariosService $usuarios)
    {
    }

    /**
     * Usuarios. Migrado desde RedmineMantencion/views/Usuarios/usuarios.php.
     */
    public function index(): View
    {
        require_once base_path('RedmineMantencion/controllers/usuarios.php');

        if (!auth_can('usuarios')) {
            abort(403, 'No tienes permiso para ver Usuarios.');
        }

        [$usuarios, $flash, $importPreview] = $this->usuarios->handle_usuarios();
        $usuariosActivos = count(array_filter($usuarios, static fn ($u): bool => strtolower(trim((string) ($u['estado'] ?? 'activo'))) !== 'baneado'));
        $usuariosBaneados = count(array_filter($usuarios, static fn ($u): bool => strtolower(trim((string) ($u['estado'] ?? 'activo'))) === 'baneado'));
        $h = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $csrf = legacy_csrf_token();
        $usersActionUrl = function_exists('url') ? url('/redmine-mantencion/app/usuarios') : legacy_app_url('app/usuarios');
        $maintenanceMode = function_exists('maintenance_mode_enabled') && maintenance_mode_enabled();

        return view('redmine-mantencion.usuarios', get_defined_vars());
    }
}
