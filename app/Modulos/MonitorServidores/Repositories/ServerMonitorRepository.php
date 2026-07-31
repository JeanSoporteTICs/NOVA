<?php

namespace App\Modulos\MonitorServidores\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ServerMonitorRepository
{
    /**
     * @return array<int,object>
     */
    public function servers(bool $onlyActive = false): array
    {
        if (! Schema::hasTable('monitoreo_servidores')) {
            return [];
        }

        $query = DB::table('monitoreo_servidores')
            ->orderByDesc('activo')
            ->orderBy('nombre');

        if ($onlyActive) {
            $query->where('activo', 1);
        }

        return $query->get()->all();
    }

    public function server(int $id): ?object
    {
        if ($id <= 0 || ! Schema::hasTable('monitoreo_servidores')) {
            return null;
        }

        return DB::table('monitoreo_servidores')->where('id', $id)->first();
    }

    public function lockServer(int $id): ?object
    {
        return DB::table('monitoreo_servidores')->where('id', $id)->lockForUpdate()->first();
    }

    /**
     * @return array<int,object>
     */
    public function dueServers(int $limit = 100): array
    {
        if (! Schema::hasTable('monitoreo_servidores')) {
            return [];
        }

        return DB::table('monitoreo_servidores')
            ->where('activo', 1)
            ->where(function ($query): void {
                $query->whereNull('ultimo_chequeo_at')
                    ->orWhereRaw('ultimo_chequeo_at <= DATE_SUB(NOW(), INTERVAL intervalo_segundos SECOND)');
            })
            ->orderByRaw('ultimo_chequeo_at IS NULL DESC')
            ->orderBy('ultimo_chequeo_at')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $values
     */
    public function createServer(array $values): int
    {
        $values['creado_at'] = now();
        $values['actualizado_at'] = now();

        return (int) DB::table('monitoreo_servidores')->insertGetId($values);
    }

    /**
     * @param  array<string,mixed>  $values
     */
    public function updateServer(int $id, array $values, bool $resetState = false): bool
    {
        return DB::transaction(function () use ($id, $values, $resetState): bool {
            $current = $this->lockServer($id);
            if (! $current) {
                throw new \RuntimeException('El servidor solicitado ya no existe.');
            }

            $incidentClosed = $resetState && strtolower(trim((string) $current->estado)) === 'abajo';
            if ($resetState) {
                $values = array_merge($values, [
                    'estado' => 'pendiente',
                    'fallos_consecutivos' => 0,
                    'latencia_ms' => null,
                    'ultimo_error' => null,
                    'ultimo_chequeo_at' => null,
                    'ultima_respuesta_at' => null,
                    'caido_desde' => null,
                    'alertado_caida_at' => null,
                ]);
            }
            $values['actualizado_at'] = now();
            DB::table('monitoreo_servidores')->where('id', $id)->update($values);

            if ($incidentClosed) {
                $this->createEvent([
                    'servidor_id' => $id,
                    'tipo' => 'configuracion',
                    'estado_anterior' => 'abajo',
                    'estado_nuevo' => 'pendiente',
                    'detalle' => 'Incidente cerrado por cambio del destino o de su configuración de conectividad.',
                    'latencia_ms' => null,
                    'ocurrido_at' => now(),
                ]);
            }

            return $incidentClosed;
        });
    }

    public function deleteServer(int $id): void
    {
        DB::table('monitoreo_servidores')->where('id', $id)->delete();
    }

    /**
     * @param  array<string,mixed>  $values
     */
    public function saveProbeState(int $id, array $values): void
    {
        $values['actualizado_at'] = now();
        DB::table('monitoreo_servidores')->where('id', $id)->update($values);
    }

    /**
     * @param  array<string,mixed>  $values
     */
    public function createEvent(array $values): int
    {
        $values['creado_at'] = now();
        $values['actualizado_at'] = now();

        return (int) DB::table('monitoreo_servidor_eventos')->insertGetId($values);
    }

    public function saveNotificationResult(int $eventId, int $sent, int $failed): void
    {
        DB::table('monitoreo_servidor_eventos')->where('id', $eventId)->update([
            'notificado_at' => $sent > 0 ? now() : null,
            'destinatarios_notificados' => $sent,
            'fallos_notificacion' => $failed,
            'actualizado_at' => now(),
        ]);
    }

    public function saveWorkerHeartbeat(string $instance, int $checks, ?string $error = null): void
    {
        if (! Schema::hasTable('monitoreo_workers')) {
            return;
        }

        DB::table('monitoreo_workers')->updateOrInsert(
            ['instancia' => mb_substr(trim($instance), 0, 160)],
            [
                'ultimo_ciclo_at' => now(),
                'servidores_comprobados' => max(0, $checks),
                'ultimo_error' => $error !== null && trim($error) !== '' ? mb_substr(trim($error), 0, 2000) : null,
                'actualizado_at' => now(),
            ]
        );
    }

    /**
     * @return array{total:int,up:int,down:int,pending:int,degraded:int,maintenance:int}
     */
    public function stats(): array
    {
        $rows = collect($this->servers(true));

        return [
            'total' => $rows->count(),
            'up' => $rows->where('estado', 'arriba')->count(),
            'down' => $rows->where('estado', 'abajo')->count(),
            'pending' => $rows->where('estado', 'pendiente')->count(),
            'degraded' => $rows->where('estado', 'degradado')->count(),
            'maintenance' => $rows->where('estado', 'mantenimiento')->count(),
        ];
    }

    /**
     * @return array<int,object>
     */
    public function recentEvents(int $limit = 20): array
    {
        if (! Schema::hasTable('monitoreo_servidor_eventos')) {
            return [];
        }

        return DB::table('monitoreo_servidor_eventos as eventos')
            ->join('monitoreo_servidores as servidores', 'servidores.id', '=', 'eventos.servidor_id')
            ->select('eventos.*', 'servidores.nombre as servidor_nombre', 'servidores.host as servidor_host')
            ->orderByDesc('eventos.ocurrido_at')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->all();
    }

    /**
     * @return array<int,object>
     */
    public function serverEvents(int $serverId, int $limit = 100): array
    {
        if ($serverId <= 0 || ! Schema::hasTable('monitoreo_servidor_eventos')) {
            return [];
        }

        return DB::table('monitoreo_servidor_eventos')
            ->where('servidor_id', $serverId)
            ->orderByDesc('ocurrido_at')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->all();
    }

    public function latestWorker(): ?object
    {
        if (! Schema::hasTable('monitoreo_workers')) {
            return null;
        }

        return DB::table('monitoreo_workers')->orderByDesc('ultimo_ciclo_at')->first();
    }

    /**
     * @return array<int,object>
     */
    public function automaticAdministrators(): array
    {
        if (! Schema::hasTable('usuarios_nova')) {
            return [];
        }

        return DB::table('usuarios_nova')
            ->whereIn('rol', array_map('strval', config('nova.module_admin_roles', ['admin', 'root'])))
            ->where(function ($query): void {
                $query->whereNull('estado')->orWhereIn('estado', ['activo', 'active']);
            })
            ->orderBy('nombre')
            ->orderBy('apellido')
            ->get(['id', 'nombre', 'apellido', 'usuario', 'rol', 'telegram_id_chat'])
            ->all();
    }

    /**
     * @return array<int,object>
     */
    public function selectableRecipients(): array
    {
        if (! Schema::hasTable('usuarios_nova')) {
            return [];
        }

        $query = DB::table('usuarios_nova as usuarios')
            ->leftJoin('monitoreo_alerta_usuarios as alertas', 'alertas.usuario_id', '=', 'usuarios.id')
            ->where(function ($builder): void {
                $builder->whereNull('usuarios.estado')
                    ->orWhereIn('usuarios.estado', ['activo', 'active']);
            })
            ->whereNotIn('usuarios.rol', array_map('strval', config('nova.module_admin_roles', ['admin', 'root'])))
            ->orderBy('usuarios.nombre')
            ->orderBy('usuarios.apellido');

        return $query->get([
            'usuarios.id',
            'usuarios.nombre',
            'usuarios.apellido',
            'usuarios.usuario',
            'usuarios.rol',
            'usuarios.telegram_id_chat',
            'alertas.activo as alerta_activa',
        ])->all();
    }

    /**
     * @param  array<int,int>  $userIds
     */
    public function syncAdditionalRecipients(array $userIds): void
    {
        if (! Schema::hasTable('monitoreo_alerta_usuarios')) {
            return;
        }

        $allowed = DB::table('usuarios_nova')
            ->whereIn('id', array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0))))
            ->whereNotNull('telegram_id_chat')
            ->where('telegram_id_chat', '<>', '')
            ->whereNotIn('rol', array_map('strval', config('nova.module_admin_roles', ['admin', 'root'])))
            ->where(function ($query): void {
                $query->whereNull('estado')->orWhereIn('estado', ['activo', 'active']);
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        DB::transaction(function () use ($allowed): void {
            DB::table('monitoreo_alerta_usuarios')->delete();
            $now = now();
            foreach ($allowed as $userId) {
                DB::table('monitoreo_alerta_usuarios')->insert([
                    'usuario_id' => $userId,
                    'activo' => 1,
                    'recibir_caidas' => 1,
                    'recibir_recuperaciones' => 1,
                    'creado_at' => $now,
                    'actualizado_at' => $now,
                ]);
            }
        });
    }

    /**
     * @return array<int,object>
     */
    public function alertRecipients(string $eventType): array
    {
        if (! Schema::hasTable('usuarios_nova')) {
            return [];
        }

        $adminRoles = array_map('strval', config('nova.module_admin_roles', ['admin', 'root']));
        $eventColumn = $eventType === 'recuperacion' ? 'recibir_recuperaciones' : 'recibir_caidas';

        return DB::table('usuarios_nova as usuarios')
            ->leftJoin('monitoreo_alerta_usuarios as alertas', 'alertas.usuario_id', '=', 'usuarios.id')
            ->where(function ($query) use ($adminRoles, $eventColumn): void {
                $query->whereIn('usuarios.rol', $adminRoles)
                    ->orWhere(function ($extra) use ($eventColumn): void {
                        $extra->where('alertas.activo', 1)->where('alertas.'.$eventColumn, 1);
                    });
            })
            ->where(function ($query): void {
                $query->whereNull('usuarios.estado')
                    ->orWhereIn('usuarios.estado', ['activo', 'active']);
            })
            ->whereNotNull('usuarios.telegram_id_chat')
            ->where('usuarios.telegram_id_chat', '<>', '')
            ->select('usuarios.id', 'usuarios.nombre', 'usuarios.apellido', 'usuarios.telegram_id_chat')
            ->distinct()
            ->get()
            ->all();
    }

    public function workerIsHealthy(int $maxAgeSeconds = 90): bool
    {
        $worker = $this->latestWorker();
        if (! $worker || empty($worker->ultimo_ciclo_at)) {
            return false;
        }

        return Carbon::parse($worker->ultimo_ciclo_at)->greaterThanOrEqualTo(now()->subSeconds($maxAgeSeconds));
    }
}
