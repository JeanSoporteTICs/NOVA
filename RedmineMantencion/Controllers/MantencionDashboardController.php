<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Modulos\Nova\Services\ProjectAccessGuard;
use App\Modulos\RedmineMantencion\Repositories\MantencionActivityRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionCatalogRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionHoursExtraRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;
use App\Modulos\RedmineMantencion\Services\MantencionAccessService;
use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use App\Modulos\RedmineMantencion\Services\MantencionManualReportService;
use App\Modulos\RedmineMantencion\Services\MantencionRedmineIssueService;
use App\Modulos\RedmineMantencion\Services\MantencionReportScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MantencionDashboardController extends Controller
{
    public function __construct(
        private readonly MantencionAccessService $access,
        private readonly MantencionReportRepository $reports,
        private readonly MantencionReportScopeService $scope,
        private readonly MantencionConfigRepository $config,
        private readonly MantencionCatalogRepository $catalogs,
        private readonly MantencionRedmineIssueService $redmine,
        private readonly MantencionCoreImportService $core,
        private readonly MantencionHoursExtraRepository $hours,
        private readonly MantencionActivityRepository $activity,
        private readonly UserIntegrationRepository $integrations,
        private readonly MantencionManualReportService $manualReports,
    ) {}

    public function index(Request $request, ProjectAccessGuard $projectAccess): View|RedirectResponse
    {
        $context = $this->context($request, $projectAccess);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'mensajes_acceso'), 403);

        $messages = $this->scope->visible($this->reports->activeMessages(), $context);
        $counts = ['pendiente' => 0, 'procesado' => 0, 'error' => 0];
        foreach ($messages as $message) {
            $status = strtolower(trim((string) ($message['estado'] ?? '')));
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return view('redmine_mantencion::native.dashboard', [
            'context' => $context,
            'permissions' => $context['permissions'],
            'messages' => $messages,
            'counts' => $counts,
            'categories' => $this->catalogs->categorias(),
            'users' => $this->manualReports->activeUsers(),
            'config' => $this->config->loadAll() ?? [],
            'activeSection' => 'dashboard',
            'pageTitle' => 'Reportes · Redmine Mantención',
        ]);
    }

    public function action(Request $request, ProjectAccessGuard $projectAccess): JsonResponse|RedirectResponse
    {
        $context = $this->context($request, $projectAccess);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'mensajes_acceso'), 403);

        $action = trim((string) ($request->input('action') ?: $request->route('action')));
        $permission = self::requiredPermission($action);
        abort_if($permission === '', 422, 'Acción no válida.');
        abort_unless($this->access->can($context, $permission), 403);

        if (! empty($context['maintenance']['enabled'])) {
            return $this->respond($request, false, 'La plataforma está en mantención; no se realizaron cambios.', [], 423);
        }

        $all = $this->reports->activeMessages();
        $submittedIds = $this->ids($request);
        $ids = $this->scope->allowedIds($submittedIds, $all, $context);
        if (! in_array($action, ['reset_errors', 'import_core_history'], true) && $ids === []) {
            return $this->respond($request, false, 'No hay reportes accesibles seleccionados.', [], 422);
        }

        return match ($action) {
            'update' => $this->update($request, $context, $ids[0]),
            'delete' => $this->delete($request, $context, [$ids[0]], false),
            'delete_selected' => $this->delete($request, $context, $ids, false),
            'archive_selected' => $this->archive($request, $context, $ids),
            'reset_errors' => $this->resetErrors($request, $context, $all),
            'toggle_hora_extra' => $this->toggleHours($request, $context, $ids[0]),
            'process_selected' => $this->send($request, $context, $ids),
            'import_core_history' => $this->importCore($request, $context),
        };
    }

    public static function requiredPermission(string $action): string
    {
        return match ($action) {
            'update', 'process_selected', 'archive_selected', 'reset_errors' => 'reportes_editar',
            'delete', 'delete_selected' => 'reportes_eliminar',
            'toggle_hora_extra' => 'horas_extra_editar',
            'import_core_history' => 'reportes_importar_core',
            default => '',
        };
    }

    /** @param array<string,mixed> $context */
    private function update(Request $request, array $context, string $id): JsonResponse|RedirectResponse
    {
        $saved = $this->reports->updateMessage($id, $request->only([
            'asunto', 'descripcion', 'categoria', 'fecha', 'hora', 'fecha_inicio', 'fecha_fin',
            'tiempo_estimado', 'hora_extra', 'asignado_a', 'solicitante',
            'unidad', 'numero', 'correo',
        ]));
        if ($saved) {
            $this->log('REPORT_UPDATE', 'Reporte actualizado: '.$id, $context);
        }

        return $this->respond($request, $saved, $saved ? 'Reporte actualizado.' : 'No se pudo actualizar el reporte.', ['ids' => [$id]], $saved ? 200 : 422);
    }

    /** @param array<string,mixed> $context @param array<int,string> $ids */
    private function delete(Request $request, array $context, array $ids, bool $archivedOnly): JsonResponse|RedirectResponse
    {
        $count = $this->reports->deleteMessages($ids, $archivedOnly);
        if ($count > 0) {
            $this->log(count($ids) > 1 ? 'REPORT_DELETE_BULK' : 'REPORT_DELETE', $count.' reporte(s) eliminado(s).', $context);
        }

        return $this->respond($request, $count > 0, $count.' reporte(s) eliminado(s).', ['ids' => $ids]);
    }

    /** @param array<string,mixed> $context @param array<int,string> $ids */
    private function archive(Request $request, array $context, array $ids): JsonResponse|RedirectResponse
    {
        $messages = [];
        foreach ($ids as $id) {
            $message = $this->reports->findMessage($id, false);
            if (is_array($message)) {
                $messages[] = $message;
            }
        }
        $count = $this->reports->archiveMessages($ids);
        foreach ($messages as $message) {
            if ($count > 0 && ! empty($message['hora_extra'])) {
                $message['estado'] = 'archivado';
                $this->hours->syncMessage($message);
            }
        }
        if ($count > 0) {
            $this->log('REPORT_ARCHIVE', $count.' reporte(s) archivado(s).', $context);
        }

        return $this->respond($request, $count > 0, $count.' reporte(s) archivado(s).', ['ids' => $ids]);
    }

    /** @param array<string,mixed> $context @param array<int,array<string,mixed>> $all */
    private function resetErrors(Request $request, array $context, array $all): JsonResponse|RedirectResponse
    {
        $errorIds = array_column(array_filter(
            $this->scope->visible($all, $context),
            static fn (array $message): bool => strtolower((string) ($message['estado'] ?? '')) === 'error',
        ), 'id');
        $requested = $this->ids($request);
        $ids = $requested === [] ? $errorIds : array_values(array_intersect($errorIds, $requested));
        $count = $this->reports->resetErrorMessages(array_map('strval', $ids));
        if ($count > 0) {
            $this->log('REPORT_RESET_ERRORS', $count.' error(es) vuelto(s) a pendiente.', $context);
        }

        return $this->respond($request, $count > 0, $count.' error(es) marcado(s) como pendiente.', ['ids' => $ids]);
    }

    /** @param array<string,mixed> $context */
    private function toggleHours(Request $request, array $context, string $id): JsonResponse|RedirectResponse
    {
        $current = $this->reports->findMessage($id, false);
        $enabled = $request->has('hora_extra')
            ? $request->boolean('hora_extra')
            : ! is_array($current) || empty($current['hora_extra']);
        $saved = $this->reports->setHoursExtra($id, $enabled);
        if (! $enabled) {
            $this->hours->detachMessageId($id);
        }
        if ($saved) {
            $this->log('HORA_EXTRA', 'Reporte '.$id.': '.($enabled ? 'activada' : 'desactivada'), $context);
        }

        return $this->respond($request, $saved, $enabled ? 'Hora extra activada.' : 'Hora extra desactivada.', ['id' => $id, 'hora_extra' => $enabled]);
    }

    /** @param array<string,mixed> $context @param array<int,string> $ids */
    private function send(Request $request, array $context, array $ids): JsonResponse|RedirectResponse
    {
        $credential = $this->integrations->credentialForUserId((int) ($context['central_user_id'] ?? 0));
        if (trim((string) $credential['secret']) === '') {
            return $this->respond($request, false, 'Configura tu API Key personal de Redmine en Cuentas conectadas antes de enviar reportes.', [
                'success' => 0, 'failed' => 0, 'blocked' => 0, 'redmine_ids' => [],
            ], 422);
        }
        $success = 0;
        $blocked = 0;
        $errors = [];
        $ticketIds = [];
        foreach ($ids as $id) {
            $message = $this->reports->findMessage($id, false);
            if (! is_array($message) || strtolower((string) ($message['estado'] ?? '')) !== 'pendiente') {
                continue;
            }
            if ($this->redmine->isCoreInReview($message)) {
                $blocked++;

                continue;
            }

            $result = $this->redmine->send($message, (string) $credential['secret']);
            if ($result['ok']) {
                $this->reports->markProcessed($id, $result['ticket_id']);
                $success++;
                $ticketIds[] = $result['ticket_id'];
                $this->log('REDMINE_SEND', 'Reporte '.$id.' enviado como ticket '.$result['ticket_id'].'.', $context);
            } else {
                $this->reports->markError($id);
                $errors[] = $id.': '.$result['error'];
                $this->log('REDMINE_SEND_FAIL', 'Fallo en reporte '.$id.' (HTTP '.$result['http_code'].').', $context);
            }
        }

        $parts = [];
        if ($success > 0) {
            $parts[] = $success.' ticket(s) enviado(s).';
        }
        if ($errors !== []) {
            $parts[] = 'Fallaron '.count($errors).' reporte(s): '.implode(' ', $errors);
        }
        // Deliberately appended last: the frontend presents this response only
        // after the integration progress reaches 100%, including mixed sends.
        if ($blocked > 0) {
            $parts[] = $blocked.' reporte(s) permanecen pendientes porque están En Revisión en CORE.';
        }
        if ($parts === []) {
            $parts[] = 'No se enviaron tickets.';
        }

        return $this->respond($request, $errors === [], implode(' ', $parts), [
            'success' => $success,
            'failed' => count($errors),
            'blocked' => $blocked,
            'redmine_ids' => $ticketIds,
        ], $errors === [] ? 200 : 422);
    }

    /** @param array<string,mixed> $context */
    private function importCore(Request $request, array $context): JsonResponse|RedirectResponse
    {
        $stored = $this->integrations->credentialForUserId((int) ($context['central_user_id'] ?? 0), 'core');
        $user = trim((string) ($request->input('core_user') ?: $stored['user']));
        $password = (string) ($request->input('core_password') ?: $stored['secret']);
        $result = $this->core->import($user, $password, (string) $request->input('core_desde'), (string) $request->input('core_hasta'), $context);
        if ($result['ok'] && $request->boolean('remember_core') && $user !== '' && $password !== '') {
            $this->integrations->saveCredentialForSession((array) ($context['nova_user'] ?? []), 'core', $user, $password);
        }
        if ($result['ok']) {
            $this->log('CORE_IMPORT', 'CORE: '.$result['imported'].' nuevos, '.$result['updated'].' actualizados.', $context);
        } else {
            $this->log('CORE_IMPORT_FAIL', $result['error'], $context);
        }
        $message = $result['ok']
            ? 'Importación CORE completada. Nuevos: '.$result['imported'].' · actualizados: '.$result['updated'].'.'
            : $result['error'];

        return $this->respond($request, $result['ok'], $message, $result, $result['ok'] ? 200 : 422);
    }

    /** @return array<int,string> */
    private function ids(Request $request): array
    {
        $ids = $request->input('ids', $request->input('id', []));
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        return array_values(array_filter(array_unique(array_map(
            static fn (mixed $id): string => trim((string) $id),
            is_array($ids) ? $ids : [],
        ))));
    }

    /** @param array<string,mixed> $context */
    private function log(string $tag, string $details, array $context): void
    {
        $this->activity->record($tag, $details, (string) ($context['viewer_name'] ?? ''), (string) ($context['viewer_id'] ?? ''));
    }

    /** @param array<string,mixed> $extra */
    private function respond(Request $request, bool $ok, string $message, array $extra = [], int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => $ok, 'message' => $message] + $extra, $status);
        }

        return redirect()->route('redmine.mantencion.dashboard')
            ->with('mantencion_status', $message)
            ->with('mantencion_status_type', $ok ? 'success' : 'danger');
    }

    /** @return array<string,mixed>|RedirectResponse */
    private function context(Request $request, ProjectAccessGuard $projectAccess): array|RedirectResponse
    {
        return $this->access->context($request)
            ?? redirect()->route('home')->with('access_error', $projectAccess->deniedMessage('Redmine Mantención'));
    }
}
