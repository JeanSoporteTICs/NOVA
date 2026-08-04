<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\Nova\Services\ProjectAccessGuard;
use App\Modulos\RedmineMantencion\Services\MantencionAccessService;
use App\Modulos\RedmineMantencion\Services\MantencionManualReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MantencionManualController extends Controller
{
    public function __construct(
        private readonly MantencionAccessService $access,
        private readonly MantencionManualReportService $manualReports,
    ) {}

    public function index(Request $request, ProjectAccessGuard $projectAccess): View|RedirectResponse
    {
        $context = $this->context($request, $projectAccess);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'simulador'), 403);

        return view('redmine_mantencion::native.manual', array_merge(
            $this->manualReports->formData($context),
            [
                'context' => $context,
                'permissions' => $context['permissions'],
                'activeSection' => 'manual',
                'pageTitle' => 'Pendiente manual',
            ],
        ));
    }

    public function store(Request $request, ProjectAccessGuard $projectAccess): RedirectResponse
    {
        $context = $this->context($request, $projectAccess);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'simulador'), 403);

        if (! empty($context['maintenance']['enabled'])) {
            return redirect()->route('redmine.mantencion.manual')
                ->withInput()
                ->with('mantencion_status', 'La plataforma está en mantención. No se pueden crear pendientes manuales.')
                ->with('mantencion_status_type', 'warning');
        }

        $result = $this->manualReports->create($request->all(), $context);
        if (! $result['saved']) {
            return redirect()->route('redmine.mantencion.manual')
                ->withInput()
                ->withErrors(['manual' => $result['error']]);
        }

        return redirect()->route('redmine.mantencion.manual')
            ->with('mantencion_status', 'Pendiente manual creado correctamente.')
            ->with('mantencion_status_type', 'success');
    }

    /** @return array<string,mixed>|RedirectResponse */
    private function context(Request $request, ProjectAccessGuard $projectAccess): array|RedirectResponse
    {
        $context = $this->access->context($request);
        if ($context !== null) {
            return $context;
        }

        return redirect()->route('home')->with(
            'access_error',
            $projectAccess->deniedMessage('Redmine Mantención')
        );
    }
}
