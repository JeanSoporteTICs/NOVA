<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\Nova\Controllers\LegacyProjectController;
use Illuminate\Http\Request;

/**
 * Bridge controller for Redmine Mantencion.
 *
 * The legacy procedural PHP in redmine-mantencion/controllers/ handles the
 * actual request processing for this module. This controller wraps that
 * legacy layer via LegacyProjectController::passthrough() and serves as
 * the canonical entry point once full migration to native Laravel is completed.
 *
 * Bridge: routes/web.php still delegates to LegacyProjectController directly.
 * Future: each method here should replace the corresponding legacy PHP file.
 */
class MantencionController extends Controller
{
    public function __construct(private readonly LegacyProjectController $legacy)
    {
    }

    public function dashboard(Request $request)
    {
        return $this->legacy->passthrough($request, 'redmine-mantencion', 'index.php');
    }

    public function section(Request $request, string $section)
    {
        $path = match ($section) {
            'dashboard', 'reportes'    => 'index.php',
            'manual', 'pendiente-manual' => 'views/Pendientes/manual.php',
            'horas-extra'              => 'views/HorasExtra/horas_extra.php',
            'historico'                => 'views/Historico/historico.php',
            'procedimientos'           => 'views/Procedimientos/procedimientos.php',
            'usuarios'                 => 'views/Usuarios/usuarios.php',
            'integraciones-nextcloud-usuarios' => 'views/Integraciones/NextcloudUsuarios.php',
            'integraciones-nextcloud-historial' => 'views/Integraciones/NextcloudHistorial.php',
            'configuracion'            => 'views/Configuracion/configuracion.php',
            'estadisticas'             => 'views/Estadisticas/estadisticas.php',
            'actividad'                => 'views/Security/activity.php',
            default => abort(404),
        };

        return $this->legacy->passthrough($request, 'redmine-mantencion', $path);
    }
}
