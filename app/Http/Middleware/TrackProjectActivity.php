<?php

namespace App\Http\Middleware;

use App\Modulos\Nova\Repositories\NovaAuditRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TrackProjectActivity
{
    /** @var array<string,bool> */
    private array $tableAvailability = [];

    /** @var array<string,int|null> */
    private array $moduleIds = [];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldTrack($request)) {
            return $response;
        }

        try {
            $this->record($request, $response);
        } catch (Throwable) {
            // El registro nunca debe interrumpir la solicitud del usuario.
        }

        return $response;
    }

    private function shouldTrack(Request $request): bool
    {
        if ($request->isMethod('OPTIONS') || $request->is('*/assets/*') || $request->is('build/*')) {
            return false;
        }

        if (str_ends_with(strtolower($request->path()), '.map')) {
            return false;
        }

        if ($request->isMethod('GET')) {
            return $request->expectsJson() || $request->ajax();
        }

        return true;
    }

    private function record(Request $request, Response $response): void
    {
        $path = trim($request->path(), '/');
        $module = match (true) {
            str_starts_with($path, 'redmine_tic') => 'tic',
            str_starts_with($path, 'redmine-mantencion') => 'mantencion',
            str_starts_with($path, 'telegram') => 'telegram',
            str_starts_with($path, 'emach') => 'emach',
            default => 'nova',
        };
        $sessionUser = $request->session()->get('nova_user', []);
        $sessionUser = is_array($sessionUser) ? $sessionUser : [];
        $userId = trim((string) ($sessionUser['id'] ?? $sessionUser['uuid'] ?? ''));
        $context = [
            'metodo' => $request->method(),
            'estado_http' => $response->getStatusCode(),
        ];

        if ($module === 'nova') {
            app(NovaAuditRepository::class)->record(
                'movimiento_http',
                sprintf('%s /%s (%d)', $request->method(), $path, $response->getStatusCode()),
                $context,
                $request
            );
            return;
        }

        if ($module === 'telegram' || $module === 'emach') {
            $table = $module . '_log';
            if ($this->tableAvailable($table)) {
                DB::table($table)->insert([
                    'evento' => $request->isMethod('GET') ? 'consulta_datos' : 'movimiento',
                    'usuario_id' => $userId !== '' ? $userId : null,
                    'detalle' => sprintf('%s /%s', $request->method(), $path),
                    'contexto' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'registrado_at' => now(),
                ]);
            }
            return;
        }

        if ($module === 'mantencion' && $this->tableAvailable('mantencion_log')) {
            DB::table('mantencion_log')->insert([
                'canal' => 'http',
                'tipo' => $request->isMethod('GET') ? 'CONSULTA_DATOS' : 'MOVIMIENTO',
                'detalle' => sprintf('%s /%s', $request->method(), $path),
                'contexto' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'registrado_at' => now(),
            ]);
            return;
        }

        if ($module === 'tic' && $this->tableAvailable('tic_log')) {
            $moduleId = $this->moduleId('redmine_tic');
            if ($moduleId !== null) {
                $entry = ['ts' => now('America/Santiago')->format('Y-m-d H:i:s'), 'event' => 'http', 'context' => $context];
                DB::table('tic_log')->insert([
                    'modulo_id' => $moduleId,
                    'evento' => $request->isMethod('GET') ? 'consulta_datos' : 'movimiento',
                    'contexto' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'linea' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'creado_at' => now(),
                ]);
            }
        }
    }

    private function tableAvailable(string $table): bool
    {
        if (array_key_exists($table, $this->tableAvailability)) {
            return $this->tableAvailability[$table];
        }

        return $this->tableAvailability[$table] = Schema::hasTable($table);
    }

    private function moduleId(string $moduleKey): ?int
    {
        if (array_key_exists($moduleKey, $this->moduleIds)) {
            return $this->moduleIds[$moduleKey];
        }

        $moduleId = DB::table('modulos_nova')->where('clave_modulo', $moduleKey)->value('id');

        return $this->moduleIds[$moduleKey] = $moduleId !== null ? (int) $moduleId : null;
    }
}
