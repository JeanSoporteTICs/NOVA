<?php

namespace App\Modulos\Emach\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\Nova\Controllers\LegacyProjectController;
use Illuminate\Http\Request;

/**
 * Bridge controller for EMACH.
 *
 * The legacy PHP in emach/index.php handles planilla queries via emach/lib/client.php.
 * This controller is the migration target for that logic into a proper Laravel controller.
 * Credentials are already managed by UserIntegrationController (route: integrations.emach).
 *
 * Bridge: current routes still delegate through LegacyProjectController.
 * Future: index() should call EmachClientService directly and render a Blade view.
 */
class EmachController extends Controller
{
    public function __construct(private readonly LegacyProjectController $legacy)
    {
    }

    public function index(Request $request)
    {
        return $this->legacy->passthrough($request, 'emach', 'index.php');
    }
}
