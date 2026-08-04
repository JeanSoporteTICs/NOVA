<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Modulos\Nova\Services\ProjectAccessGuard;
use App\Modulos\RedmineMantencion\Repositories\MantencionActivityRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionHoursExtraRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;
use App\Modulos\RedmineMantencion\Services\MantencionAccessService;
use App\Modulos\RedmineMantencion\Services\MantencionReportScopeService;
use App\Modulos\RedmineMantencion\Services\RedmineIssueStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MantencionSectionController extends Controller
{
    public function __construct(
        private readonly MantencionAccessService $access,
        private readonly MantencionReportRepository $reports,
        private readonly MantencionReportScopeService $scope,
        private readonly MantencionHoursExtraRepository $hours,
        private readonly MantencionConfigRepository $config,
        private readonly RedmineIssueStatusService $statuses,
        private readonly UserIntegrationRepository $integrations,
        private readonly MantencionActivityRepository $activity,
    ) {}

    public function history(Request $request, ProjectAccessGuard $guard): View|RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'historico'), 403);

        return view('redmine_mantencion::native.history', $this->base($context, 'historico', 'Histórico · Redmine Mantención') + [
            'messages' => $this->scope->visible($this->reports->archivedMessages(), $context),
            'statusOptions' => $this->statuses->statusOptions(),
            'platformUrl' => (string) (($this->config->loadAll() ?? [])['platform_url'] ?? ''),
        ]);
    }

    public function historyStatuses(Request $request, ProjectAccessGuard $guard): JsonResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return response()->json(['ok' => false, 'message' => 'Sesión no disponible.'], 401);
        }
        abort_unless($this->access->can($context, 'historico'), 403);

        $requested = $request->input('ids', []);
        if (is_string($requested)) {
            $requested = explode(',', $requested);
        }
        $ticketIds = array_slice(array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): string => trim((string) $id),
            is_array($requested) ? $requested : [],
        ), static fn (string $id): bool => preg_match('/^\d+$/', $id) === 1))), 0, 100);

        $visibleTickets = [];
        foreach ($this->scope->visible($this->reports->archivedMessages(), $context) as $message) {
            $ticket = trim((string) ($message['redmine_id'] ?? ''));
            if (preg_match('/^\d+$/', $ticket)) {
                $visibleTickets[$ticket] = true;
            }
        }

        $credential = $this->integrations->credentialForUserId((int) ($context['central_user_id'] ?? 0));
        $platformUrl = (string) (($this->config->loadAll() ?? [])['platform_url'] ?? '');
        $statuses = [];
        foreach ($ticketIds as $ticketId) {
            if (! isset($visibleTickets[$ticketId])) {
                continue;
            }
            $statuses[$ticketId] = $this->statuses->fetchStatus(
                $platformUrl,
                $ticketId,
                (string) $credential['secret'],
            );
        }

        return response()->json(['ok' => true, 'statuses' => $statuses]);
    }

    public function historyAction(Request $request, ProjectAccessGuard $guard): RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'historico'), 403);
        if (! empty($context['maintenance']['enabled'])) {
            return $this->back('La plataforma está en mantención.', 'warning');
        }

        $ids = $this->allowedArchivedIds($request, $context);
        $action = trim((string) $request->input('action'));
        if ($action === 'delete') {
            abort_unless($this->access->can($context, 'reportes_eliminar'), 403);
            $count = $this->reports->deleteMessages($ids, true);
            if ($count > 0) {
                $this->log('REPORT_DELETE', $count.' registro(s) eliminado(s) del histórico.', $context);
            }

            return $this->back($count.' registro(s) eliminado(s).', $count > 0 ? 'success' : 'warning');
        }
        if ($action !== 'update_redmine_status') {
            abort(422, 'Acción no válida.');
        }
        abort_unless($this->access->can($context, 'reportes_editar'), 403);

        $statusId = (int) $request->input('status_id');
        $statusName = $this->statuses->statusName($statusId);
        abort_if($statusName === null, 422, 'Estado no permitido.');
        $credential = $this->integrations->credentialForUserId((int) ($context['central_user_id'] ?? 0));
        $platformUrl = (string) (($this->config->loadAll() ?? [])['platform_url'] ?? '');
        $updated = 0;
        $errors = [];
        foreach ($ids as $id) {
            $message = $this->reports->findMessage($id, true);
            $ticket = trim((string) ($message['redmine_id'] ?? ''));
            if ($ticket === '') {
                continue;
            }
            $result = $this->statuses->updateStatus($platformUrl, $ticket, $statusId, (string) $credential['secret']);
            if ($result['ok']) {
                $this->reports->updateRedmineStatus($ticket, $statusId, $statusName);
                $updated++;
            } else {
                $errors[] = '#'.$ticket.': '.$result['error'];
            }
        }
        if ($updated > 0) {
            $this->log('REDMINE_STATUS', $updated.' estado(s) remoto(s) actualizado(s).', $context);
        }

        return $this->back($updated.' ticket(s) actualizado(s). '.implode(' ', $errors), $errors === [] ? 'success' : 'warning');
    }

    public function hours(Request $request, ProjectAccessGuard $guard): View|RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'horas_extra'), 403);

        $groups = [];
        foreach ($this->hours->groups() as $group) {
            $reports = $this->scope->visible((array) ($group['reports'] ?? []), $context);
            if ($reports !== []) {
                $group['reports'] = $reports;
                $groups[] = $group;
            }
        }

        return view('redmine_mantencion::native.hours', $this->base($context, 'horas-extra', 'Horas extra · Redmine Mantención') + ['groups' => $groups]);
    }

    public function hoursAction(Request $request, ProjectAccessGuard $guard): RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'horas_extra_editar'), 403);
        if (! empty($context['maintenance']['enabled'])) {
            return $this->back('La plataforma está en mantención.', 'warning');
        }

        $action = trim((string) $request->input('action'));
        if ($action === 'detach') {
            $id = trim((string) $request->input('id'));
            $message = $this->reports->findMessage($id, true);
            abort_unless(is_array($message) && $this->scope->canAccess($message, $context), 403);
            $ok = $this->hours->detachMessageId($id);
            if ($ok) {
                $this->log('HORA_EXTRA', 'Reporte '.$id.' retirado de horas extra.', $context);
            }

            return $this->back($ok ? 'Reporte retirado de horas extra.' : 'No se pudo retirar el reporte.', $ok ? 'success' : 'danger');
        }
        abort_unless($action === 'update_extra', 422);
        $ok = $this->hours->updateGroupHours(
            (string) $request->input('fecha'),
            (string) $request->input('hora_inicio'),
            (string) $request->input('hora_fin'),
        );
        if ($ok) {
            $this->log('HORA_EXTRA', 'Horario extra actualizado para '.$request->input('fecha').'.', $context);
        }

        return $this->back($ok ? 'Horario extra actualizado.' : 'No se pudo actualizar el horario.', $ok ? 'success' : 'danger');
    }

    public function stats(Request $request, ProjectAccessGuard $guard): View|RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'estadisticas'), 403);
        $messages = $this->scope->visible(array_merge($this->reports->activeMessages(), $this->reports->archivedMessages()), $context);
        $byStatus = $byCategory = $byMonth = [];
        foreach ($messages as $message) {
            $status = ucfirst(strtolower(trim((string) ($message['estado'] ?? 'Sin estado'))));
            $category = trim((string) ($message['categoria'] ?? '')) ?: 'Sin categoría';
            $date = trim((string) ($message['fecha'] ?? $message['fecha_inicio'] ?? ''));
            $month = preg_match('/^(\d{4}-\d{2})/', $date, $match) ? $match[1] : 'Sin fecha';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
            $byMonth[$month] = ($byMonth[$month] ?? 0) + 1;
        }
        arsort($byCategory);
        ksort($byMonth);

        return view('redmine_mantencion::native.stats', $this->base($context, 'estadisticas', 'Estadísticas · Redmine Mantención') + compact('messages', 'byStatus', 'byCategory', 'byMonth'));
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function base(array $context, string $section, string $title): array
    {
        return ['context' => $context, 'permissions' => $context['permissions'], 'activeSection' => $section, 'pageTitle' => $title];
    }

    /** @param array<string,mixed> $context @return array<int,string> */
    private function allowedArchivedIds(Request $request, array $context): array
    {
        $ids = $request->input('ids', $request->input('id', []));
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        return $this->scope->allowedIds((array) $ids, $this->reports->archivedMessages(), $context);
    }

    /** @param array<string,mixed> $context */
    private function log(string $tag, string $details, array $context): void
    {
        $this->activity->record($tag, $details, (string) $context['viewer_name'], (string) $context['viewer_id']);
    }

    private function back(string $message, string $type): RedirectResponse
    {
        return back()->with('mantencion_status', trim($message))->with('mantencion_status_type', $type);
    }

    /** @return array<string,mixed>|RedirectResponse */
    private function context(Request $request, ProjectAccessGuard $guard): array|RedirectResponse
    {
        return $this->access->context($request)
            ?? redirect()->route('home')->with('access_error', $guard->deniedMessage('Redmine Mantención'));
    }
}
