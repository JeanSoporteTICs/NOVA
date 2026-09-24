<?php

namespace App\Modulos\RedmineMantencion\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConfigurationWriteException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No fue posible guardar la configuración. No se aplicaron cambios a la configuración; intenta nuevamente.');
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['ok' => false, 'error' => $this->getMessage()], 503);
        }
        if (! $request->isMethod('POST')) {
            return new Response($this->getMessage(), 503);
        }

        $flashKey = match ((string) $request->input('action', '')) {
            'maintenance_settings' => 'mantencion_maintenance_flash',
            'save_nextcloud_config' => 'mantencion_nextcloud_flash',
            default => 'mantencion_config_flash',
        };

        return redirect()->back(303)->with($flashKey, $this->getMessage());
    }
}
