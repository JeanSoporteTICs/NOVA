<?php

namespace App\Modulos\Nova\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\Nova\Repositories\NovaAccessRepository;
use App\Modulos\Nova\Services\HoursExtra\UnifiedHoursExtraService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Vista global de Horas Extra (Mantención + TIC). Accesible a cualquier
 * usuario autenticado en NOVA — NO requiere rol admin/root. Cada usuario solo
 * ve los módulos a los que ya tiene acceso (misma regla que decide qué
 * tarjetas de módulo ve en el inicio de NOVA), nunca datos de un módulo al
 * que no tiene acceso. Editar un grupo tampoco cruza módulos: cada corrección
 * se guarda con el adapter/repositorio dueño de ese origen, tras confirmar
 * que el usuario tiene acceso a ese mismo módulo.
 */
class HoursExtraController extends Controller
{
    /** @var array<string,string> */
    private const PROJECT_KEY_BY_ORIGIN = [
        'mantencion' => 'redmine-mantencion',
        'tic' => 'redmine_tic',
    ];

    public function index(Request $request, UnifiedHoursExtraService $hoursExtra, NovaAccessRepository $access): View
    {
        $user = $this->sessionUser($request);
        abort_unless($access->canAccess($user, 'horas-extra'), 403);
        $assignedUserId = trim((string) ($user['redmine_id'] ?? ''));

        $canMantencion = $access->canAccess($user, 'redmine-mantencion');
        $canTic = $access->canAccess($user, 'redmine_tic');

        $rows = match (true) {
            $canMantencion && $canTic => $hoursExtra->getAll($assignedUserId),
            $canMantencion => $hoursExtra->getMantencion($assignedUserId),
            $canTic => $hoursExtra->getTic($assignedUserId),
            default => [],
        };

        return view('nova.horas-extra.index', [
            'rows' => $rows,
            'dateGroups' => $hoursExtra->groupByDate($rows),
            'canMantencion' => $canMantencion,
            'canTic' => $canTic,
        ]);
    }

    public function update(Request $request, UnifiedHoursExtraService $hoursExtra, NovaAccessRepository $access): RedirectResponse
    {
        $user = $this->sessionUser($request);
        abort_unless($access->canAccess($user, 'horas-extra'), 403);
        $origen = (string) $request->input('origen', '');
        $projectKey = self::PROJECT_KEY_BY_ORIGIN[$origen] ?? null;

        if ($projectKey === null || !$access->canAccess($user, $projectKey)) {
            return redirect()->route('horas-extra.index')->with('horas_extra_error', 'No tienes acceso para modificar este grupo.');
        }

        $fecha = (string) $request->input('fecha', '');
        $horaInicio = (string) $request->input('hora_inicio', '');
        $horaFin = (string) $request->input('hora_fin', '');

        if ($fecha === '') {
            return redirect()->route('horas-extra.index')->with('horas_extra_error', 'Fecha invalida.');
        }

        $ok = $hoursExtra->updateGroupTime($origen, $fecha, $horaInicio, $horaFin);

        return redirect()
            ->route('horas-extra.index')
            ->with($ok ? 'horas_extra_status' : 'horas_extra_error', $ok ? 'Grupo actualizado.' : 'No se pudo actualizar el grupo.');
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionUser(Request $request): array
    {
        $user = $request->session()->get('nova_user');

        return is_array($user) ? $user : [];
    }
}
