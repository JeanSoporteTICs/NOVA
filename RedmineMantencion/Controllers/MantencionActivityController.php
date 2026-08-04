<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\Nova\Services\ProjectAccessGuard;
use App\Modulos\RedmineMantencion\Repositories\MantencionActivityRepository;
use App\Modulos\RedmineMantencion\Services\MantencionAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MantencionActivityController extends Controller
{
    public function __construct(
        private readonly MantencionAccessService $access,
        private readonly MantencionActivityRepository $activity,
    ) {}

    public function index(Request $request, ProjectAccessGuard $projectAccess): View|RedirectResponse
    {
        $context = $this->access->context($request);
        if ($context === null) {
            return redirect()->route('home')->with(
                'access_error',
                $projectAccess->deniedMessage('Redmine Mantención')
            );
        }
        abort_unless($this->access->can($context, 'actividad'), 403);

        $filters = [
            'tag' => strtoupper(trim((string) $request->query('tag', ''))),
            'canal' => strtolower(trim((string) $request->query('canal', ''))),
            'buscar' => trim((string) $request->query('buscar', '')),
            'desde' => trim((string) $request->query('desde', '')),
            'hasta' => trim((string) $request->query('hasta', '')),
        ];
        $result = $this->activity->search(
            $filters,
            max(1, $request->integer('page', 1)),
            $request->integer('per_page', 50),
            (string) $context['viewer_name'],
            $this->access->can($context, 'actividad_todos'),
            (string) $context['viewer_id'],
        );

        return view('redmine_mantencion::native.activity', [
            'context' => $context,
            'permissions' => $context['permissions'],
            'filters' => $filters,
            'events' => $result['events'],
            'eventTags' => $result['tags'],
            'eventChannels' => $result['channels'],
            'totalEvents' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['per_page'],
            'totalPages' => $result['pages'],
            'canViewAll' => $this->access->can($context, 'actividad_todos'),
            'canDelete' => $this->access->can($context, 'actividad_eliminar'),
            'hasFilters' => collect($filters)->contains(fn (string $value): bool => $value !== ''),
            'activeSection' => 'actividad',
            'pageTitle' => 'Actividad reciente',
        ]);
    }

    public function clear(Request $request, ProjectAccessGuard $projectAccess): RedirectResponse
    {
        $context = $this->access->context($request);
        if ($context === null) {
            return redirect()->route('home')->with(
                'access_error',
                $projectAccess->deniedMessage('Redmine Mantención')
            );
        }
        abort_unless($this->access->can($context, 'actividad_eliminar'), 403);

        if (! empty($context['maintenance']['enabled'])) {
            $until = trim((string) ($context['maintenance']['until_text'] ?? ''));
            $message = 'La plataforma está en mantención. No se pueden eliminar eventos'
                .($until !== '' ? ' hasta '.$until : '').'.';

            return redirect()->route('redmine.mantencion.activity')
                ->with('mantencion_status', $message)
                ->with('mantencion_status_type', 'warning');
        }

        $deleted = $this->activity->clearForUser(
            (string) $context['viewer_name'],
            (string) $context['viewer_id'],
        );
        $this->activity->record(
            'ACTIVITY_CLEAR',
            sprintf(
                'Actividad reciente limpiada por %s (ID %s)',
                (string) $context['viewer_name'],
                (string) $context['viewer_id'],
            ),
            (string) $context['viewer_name'],
            (string) $context['viewer_id'],
        );

        return redirect()->route('redmine.mantencion.activity')
            ->with('mantencion_status', $deleted.' evento(s) propios eliminados de la bitácora.')
            ->with('mantencion_status_type', 'success');
    }
}
