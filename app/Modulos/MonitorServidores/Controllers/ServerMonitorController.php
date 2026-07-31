<?php

namespace App\Modulos\MonitorServidores\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\MonitorServidores\Repositories\ServerMonitorRepository;
use App\Modulos\MonitorServidores\Services\ServerMonitorService;
use App\Modulos\Nova\Services\ProjectAccessGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ServerMonitorController extends Controller
{
    public function dashboard(
        Request $request,
        ProjectAccessGuard $access,
        ServerMonitorRepository $repository,
        ServerMonitorService $monitor,
    ): View {
        $this->authorizeAccess($request, $access);

        return $this->render($request, $repository, $monitor, 'dashboard');
    }

    public function servers(
        Request $request,
        ProjectAccessGuard $access,
        ServerMonitorRepository $repository,
        ServerMonitorService $monitor,
    ): View {
        $this->authorizeAccess($request, $access);
        $this->authorizeManager($request);

        return $this->render($request, $repository, $monitor, 'servers');
    }

    public function recipients(
        Request $request,
        ProjectAccessGuard $access,
        ServerMonitorRepository $repository,
        ServerMonitorService $monitor,
    ): View {
        $this->authorizeAccess($request, $access);
        $this->authorizeManager($request);

        return $this->render($request, $repository, $monitor, 'recipients');
    }

    public function status(
        Request $request,
        ProjectAccessGuard $access,
        ServerMonitorRepository $repository,
        ServerMonitorService $monitor,
    ): JsonResponse {
        $this->authorizeAccess($request, $access);
        $worker = $repository->latestWorker();

        return response()->json([
            'stats' => $repository->stats(),
            'worker' => [
                'healthy' => $repository->workerIsHealthy(),
                'last_cycle' => $worker?->ultimo_ciclo_at,
                'checks' => (int) ($worker?->servidores_comprobados ?? 0),
                'error' => trim((string) ($worker?->ultimo_error ?? '')),
            ],
            'servers' => collect($repository->servers(true))->map(static function (object $server) use ($monitor): array {
                return [
                    'id' => (int) $server->id,
                    'state' => (string) $server->estado,
                    'latency_ms' => $server->latencia_ms !== null ? (int) $server->latencia_ms : null,
                    'last_check' => $server->ultimo_chequeo_at,
                    'last_check_text' => $server->ultimo_chequeo_at
                        ? Carbon::parse($server->ultimo_chequeo_at)->diffForHumans()
                        : 'Sin comprobar',
                    'failures' => (int) $server->fallos_consecutivos,
                    'target' => $monitor->targetLabel($server),
                ];
            })->values(),
        ]);
    }

    public function store(
        Request $request,
        ProjectAccessGuard $access,
        ServerMonitorRepository $repository,
    ): RedirectResponse {
        $this->authorizeAccess($request, $access);
        $this->authorizeManager($request);
        $values = $this->validatedServer($request);
        $values['creado_por'] = $this->databaseUserId($request);
        $repository->createServer($values);

        return redirect()->route('monitor.servers')->with('monitor_status', 'Servidor agregado. El Docker lo comprobará en el próximo ciclo.');
    }

    public function update(
        Request $request,
        ProjectAccessGuard $access,
        ServerMonitorRepository $repository,
        int $server,
    ): RedirectResponse {
        $this->authorizeAccess($request, $access);
        $this->authorizeManager($request);
        $current = $repository->server($server);
        abort_unless($current, 404);
        $values = $this->validatedServer($request);
        $connectivityFields = ['host', 'tipo', 'puerto', 'ruta', 'verificar_ssl', 'timeout_segundos'];
        $resetState = collect($connectivityFields)->contains(
            static fn (string $field): bool => (string) ($current->{$field} ?? '') !== (string) ($values[$field] ?? '')
        );
        $repository->updateServer($server, $values, $resetState);

        return redirect()->route('monitor.servers')->with('monitor_status', 'Servidor actualizado.');
    }

    public function destroy(
        Request $request,
        ProjectAccessGuard $access,
        ServerMonitorRepository $repository,
        int $server,
    ): RedirectResponse {
        $this->authorizeAccess($request, $access);
        $this->authorizeManager($request);
        abort_unless($repository->server($server), 404);
        $repository->deleteServer($server);

        return redirect()->route('monitor.servers')->with('monitor_status', 'Servidor eliminado junto con su historial de monitoreo.');
    }

    public function check(
        Request $request,
        ProjectAccessGuard $access,
        ServerMonitorService $monitor,
        int $server,
    ): RedirectResponse {
        $this->authorizeAccess($request, $access);
        $this->authorizeManager($request);

        try {
            $result = $monitor->checkServer($server);
            $key = $result['ok'] ? 'monitor_status' : 'monitor_warning';

            return redirect()->route('monitor.servers')->with($key, $result['message']);
        } catch (\Throwable $e) {
            return redirect()->route('monitor.servers')->with('monitor_error', 'No se pudo comprobar: '.$e->getMessage());
        }
    }

    public function checkAll(
        Request $request,
        ProjectAccessGuard $access,
        ServerMonitorService $monitor,
    ): RedirectResponse {
        $this->authorizeAccess($request, $access);
        $this->authorizeManager($request);

        $summary = $monitor->checkAllActive();
        if ($summary['total'] === 0) {
            return redirect()->route('monitor.servers')
                ->with('monitor_warning', 'No hay servidores activos para comprobar.');
        }

        $problems = $summary['unavailable'] + $summary['errors'];
        $message = 'Comprobación completada: '
            .$summary['available'].' disponible(s), '
            .$problems.' con error de '
            .$summary['total'].' servidor(es) activo(s).';

        return redirect()->route('monitor.servers')->with(
            $problems === 0 ? 'monitor_status' : 'monitor_warning',
            $message
        );
    }

    public function updateRecipients(
        Request $request,
        ProjectAccessGuard $access,
        ServerMonitorRepository $repository,
    ): RedirectResponse {
        $this->authorizeAccess($request, $access);
        $this->authorizeManager($request);
        $validated = $request->validate([
            'usuarios' => ['nullable', 'array'],
            'usuarios.*' => ['integer', 'min:1'],
        ]);
        $repository->syncAdditionalRecipients(array_map('intval', (array) ($validated['usuarios'] ?? [])));

        return redirect()->route('monitor.recipients')->with('monitor_status', 'Destinatarios adicionales actualizados.');
    }

    private function render(
        Request $request,
        ServerMonitorRepository $repository,
        ServerMonitorService $monitor,
        string $section,
    ): View {
        $role = strtolower(trim((string) data_get($request->session()->get('nova_user'), 'role', 'usuario')));
        $canManage = in_array($role, config('nova.module_admin_roles', []), true);
        $servers = $repository->servers();
        $editId = max(0, (int) $request->query('editar', 0));
        $editing = $editId > 0 ? $repository->server($editId) : null;

        return view('monitor-servidores.index', [
            'section' => $section,
            'canManage' => $canManage,
            'servers' => $servers,
            'stats' => $repository->stats(),
            'events' => $repository->recentEvents(),
            'worker' => $repository->latestWorker(),
            'workerHealthy' => $repository->workerIsHealthy(),
            'automaticAdmins' => $canManage ? $repository->automaticAdministrators() : [],
            'recipientUsers' => $canManage ? $repository->selectableRecipients() : [],
            'editing' => $editing,
            'targetLabels' => collect($servers)->mapWithKeys(
                static fn (object $server): array => [(int) $server->id => $monitor->targetLabel($server)]
            )->all(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function validatedServer(Request $request): array
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'host' => ['required', 'string', 'max:760'],
            'tipo' => ['required', Rule::in(['icmp', 'tcp', 'http', 'https'])],
            'puerto' => ['nullable', 'integer', 'min:1', 'max:65535', 'required_if:tipo,tcp'],
            'intervalo_segundos' => ['required', 'integer', 'min:30', 'max:86400'],
            'timeout_segundos' => ['required', 'integer', 'min:1', 'max:30'],
            'fallos_para_alertar' => ['required', 'integer', 'min:1', 'max:10'],
        ], [
            'puerto.required_if' => 'El puerto es obligatorio para comprobaciones TCP.',
        ]);

        $type = strtolower(trim((string) $validated['tipo']));
        $endpoint = $this->normalizeEndpoint(
            $type,
            trim((string) $validated['host']),
            isset($validated['puerto']) && $validated['puerto'] !== '' ? (int) $validated['puerto'] : null,
        );

        return [
            'nombre' => trim((string) $validated['nombre']),
            'host' => $endpoint['host'],
            'tipo' => $type,
            'puerto' => $endpoint['port'],
            'ruta' => $endpoint['path'],
            'verificar_ssl' => $type === 'https' && $request->boolean('verificar_ssl') ? 1 : 0,
            'intervalo_segundos' => (int) $validated['intervalo_segundos'],
            'timeout_segundos' => (int) $validated['timeout_segundos'],
            'fallos_para_alertar' => (int) $validated['fallos_para_alertar'],
            'activo' => $request->boolean('activo') ? 1 : 0,
        ];
    }

    /**
     * @return array{host:string,port:?int,path:?string}
     */
    private function normalizeEndpoint(string $type, string $destination, ?int $port): array
    {
        if (in_array($type, ['icmp', 'tcp'], true)) {
            if (mb_strlen($destination) > 255 || preg_match('/^[A-Za-z0-9._:-]+$/', $destination) !== 1) {
                throw ValidationException::withMessages([
                    'host' => 'Para '.strtoupper($type).' ingresa solo la IP o el host, sin protocolo ni ruta.',
                ]);
            }

            return ['host' => $destination, 'port' => $type === 'tcp' ? (int) $port : null, 'path' => null];
        }

        $candidate = preg_match('#^https?://#i', $destination) === 1
            ? $destination
            : $type.'://'.ltrim($destination, '/');
        $parts = parse_url($candidate);
        if (! is_array($parts)) {
            throw ValidationException::withMessages([
                'host' => 'Ingresa una URL válida, por ejemplo '.$type.'://servidor/health.',
            ]);
        }
        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
        $host = trim((string) ($parts['host'] ?? ''));

        if ($host === '') {
            throw ValidationException::withMessages([
                'host' => 'Ingresa una URL válida, por ejemplo '.$type.'://servidor/health.',
            ]);
        }
        if ($scheme !== $type) {
            throw ValidationException::withMessages([
                'host' => 'La URL debe usar '.$type.':// porque ese es el método seleccionado.',
            ]);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages([
                'host' => 'La URL de monitoreo no debe incluir usuario ni contraseña.',
            ]);
        }
        if (mb_strlen($host) > 255) {
            throw ValidationException::withMessages(['host' => 'El host de la URL es demasiado largo.']);
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = $path !== '' ? $path : '/';
        if (isset($parts['query']) && trim((string) $parts['query']) !== '') {
            $path .= '?'.$parts['query'];
        }
        if (mb_strlen($path) > 500) {
            throw ValidationException::withMessages(['host' => 'La ruta de la URL es demasiado larga.']);
        }

        return [
            'host' => $host,
            'port' => isset($parts['port']) ? (int) $parts['port'] : ($type === 'https' ? 443 : 80),
            'path' => $path,
        ];
    }

    private function authorizeAccess(Request $request, ProjectAccessGuard $access): void
    {
        $user = $request->session()->get('nova_user');
        abort_unless(is_array($user) && $access->canAccess('monitoreo-servidores', $user), 403);
    }

    private function authorizeManager(Request $request): void
    {
        $role = strtolower(trim((string) data_get($request->session()->get('nova_user'), 'role', 'usuario')));
        abort_unless(in_array($role, config('nova.module_admin_roles', []), true), 403);
    }

    private function databaseUserId(Request $request): ?int
    {
        $uuid = trim((string) data_get($request->session()->get('nova_user'), 'id', ''));
        if ($uuid === '') {
            return null;
        }

        $id = DB::table('usuarios_nova')->where('uuid', $uuid)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
