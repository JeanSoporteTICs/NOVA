<?php

namespace App\Modulos\MonitorServidores\Services;

use App\Modulos\MonitorServidores\Repositories\ServerMonitorRepository;
use App\Modulos\Telegram\Services\TelegramService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ServerMonitorService
{
    public function __construct(
        private readonly ServerMonitorRepository $repository,
        private readonly ServerProbeService $probe,
        private readonly TelegramService $telegram,
    ) {}

    /**
     * @return array{checked:int,alerts:int,recoveries:int,errors:int}
     */
    public function runDue(string $instance): array
    {
        $summary = ['checked' => 0, 'alerts' => 0, 'recoveries' => 0, 'errors' => 0];

        try {
            foreach ($this->repository->dueServers() as $server) {
                $result = $this->checkServer((int) $server->id);
                $summary['checked']++;
                if (($result['event'] ?? '') === 'caida') {
                    $summary['alerts']++;
                } elseif (($result['event'] ?? '') === 'recuperacion') {
                    $summary['recoveries']++;
                }
                if (! ($result['ok'] ?? false)) {
                    $summary['errors']++;
                }
            }
            $this->repository->saveWorkerHeartbeat($instance, $summary['checked']);
        } catch (\Throwable $e) {
            $this->repository->saveWorkerHeartbeat($instance, $summary['checked'], $e->getMessage());
            throw $e;
        }

        return $summary;
    }

    /**
     * @return array{total:int,checked:int,available:int,unavailable:int,errors:int,alerts:int,recoveries:int}
     */
    public function checkAllActive(): array
    {
        $servers = $this->repository->servers(true);
        $summary = [
            'total' => count($servers),
            'checked' => 0,
            'available' => 0,
            'unavailable' => 0,
            'errors' => 0,
            'alerts' => 0,
            'recoveries' => 0,
        ];

        foreach ($servers as $server) {
            try {
                $result = $this->checkServer((int) $server->id);
                $summary['checked']++;
                $result['ok'] ? $summary['available']++ : $summary['unavailable']++;

                if (($result['event'] ?? null) === 'caida') {
                    $summary['alerts']++;
                } elseif (($result['event'] ?? null) === 'recuperacion') {
                    $summary['recoveries']++;
                }
            } catch (\Throwable) {
                $summary['errors']++;
            }
        }

        return $summary;
    }

    /**
     * @return array{ok:bool,event:?string,state:string,message:string}
     */
    public function checkServer(int $serverId): array
    {
        $server = $this->repository->server($serverId);
        if (! $server) {
            throw new \RuntimeException('El servidor solicitado no existe.');
        }

        $probeResult = $this->probe->probe($server);
        $transition = DB::transaction(function () use ($serverId, $probeResult): array {
            $current = $this->repository->lockServer($serverId);
            if (! $current) {
                throw new \RuntimeException('El servidor solicitado ya no existe.');
            }

            $now = now();
            $previous = strtolower(trim((string) ($current->estado ?? 'pendiente')));
            $eventType = null;
            $eventId = null;
            $newState = $previous;
            $failures = (int) ($current->fallos_consecutivos ?? 0);
            $values = [
                'ultimo_chequeo_at' => $now,
                'latencia_ms' => max(0, (int) ($probeResult['latency_ms'] ?? 0)),
            ];

            if ($probeResult['ok']) {
                $newState = 'arriba';
                $values = array_merge($values, [
                    'estado' => $newState,
                    'fallos_consecutivos' => 0,
                    'ultimo_error' => null,
                    'ultima_respuesta_at' => $now,
                    'caido_desde' => null,
                    'alertado_caida_at' => null,
                ]);
                if ($previous === 'abajo') {
                    $eventType = 'recuperacion';
                }
            } else {
                $failures++;
                $threshold = max(1, (int) ($current->fallos_para_alertar ?? 3));
                $newState = $failures >= $threshold ? 'abajo' : 'degradado';
                $values = array_merge($values, [
                    'estado' => $newState,
                    'fallos_consecutivos' => $failures,
                    'ultimo_error' => mb_substr(trim((string) ($probeResult['error'] ?? 'Sin respuesta.')), 0, 4000),
                ]);
                if ($newState === 'abajo' && $previous !== 'abajo') {
                    $eventType = 'caida';
                    $values['caido_desde'] = $now;
                    $values['alertado_caida_at'] = $now;
                }
            }

            $this->repository->saveProbeState($serverId, $values);

            if ($eventType !== null) {
                $eventId = $this->repository->createEvent([
                    'servidor_id' => $serverId,
                    'tipo' => $eventType,
                    'estado_anterior' => $previous,
                    'estado_nuevo' => $newState,
                    'detalle' => $eventType === 'caida'
                        ? mb_substr(trim((string) ($probeResult['error'] ?? 'Sin respuesta.')), 0, 4000)
                        : 'El servidor volvió a responder.',
                    'latencia_ms' => max(0, (int) ($probeResult['latency_ms'] ?? 0)),
                    'ocurrido_at' => $now,
                ]);
            }

            return [
                'event' => $eventType,
                'event_id' => $eventId,
                'state' => $newState,
                'failures' => $failures,
                'current' => $current,
            ];
        });

        if ($transition['event'] !== null && $transition['event_id'] !== null) {
            $this->notifyTransition(
                (int) $transition['event_id'],
                (string) $transition['event'],
                $transition['current'],
                $probeResult
            );
        }

        return [
            'ok' => (bool) $probeResult['ok'],
            'event' => $transition['event'],
            'state' => (string) $transition['state'],
            'message' => $probeResult['ok']
                ? 'Servidor disponible en '.(int) $probeResult['latency_ms'].' ms.'
                : (string) $probeResult['error'],
        ];
    }

    /**
     * @param  array{ok:bool,latency_ms:int,error:string,http_code:?int}  $probeResult
     */
    private function notifyTransition(int $eventId, string $eventType, object $server, array $probeResult): void
    {
        $recipients = $this->repository->alertRecipients($eventType);
        $sent = 0;
        $failed = 0;
        $message = $this->notificationMessage($eventType, $server, $probeResult);

        foreach ($recipients as $recipient) {
            try {
                if ($this->telegram->sendToChat((string) $recipient->telegram_id_chat, $message)) {
                    $sent++;
                } else {
                    $failed++;
                }
            } catch (\Throwable) {
                $failed++;
            }
        }

        $this->repository->saveNotificationResult($eventId, $sent, $failed);
    }

    /**
     * @param  array{ok:bool,latency_ms:int,error:string,http_code:?int}  $probeResult
     */
    private function notificationMessage(string $eventType, object $server, array $probeResult): string
    {
        $target = $this->targetLabel($server);
        $time = now()->timezone((string) config('app.timezone', 'America/Santiago'))->format('d-m-Y H:i:s');

        if ($eventType === 'recuperacion') {
            $downSince = ! empty($server->caido_desde)
                ? Carbon::parse($server->caido_desde)->diffForHumans(now(), true)
                : 'sin duración registrada';

            return "✅ [NOVA] SERVIDOR RECUPERADO\n"
                ."Servidor: {$server->nombre}\n"
                ."Destino: {$target}\n"
                .'Tiempo fuera: '.$downSince."\n"
                .'Latencia: '.(int) $probeResult['latency_ms']." ms\n"
                .'Detectado: '.$time;
        }

        return "🚨 [NOVA] SERVIDOR CAÍDO\n"
            ."Servidor: {$server->nombre}\n"
            ."Destino: {$target}\n"
            .'Intentos fallidos: '.max(1, (int) ($server->fallos_para_alertar ?? 1))."\n"
            .'Error: '.trim((string) ($probeResult['error'] ?? 'Sin respuesta.'))."\n"
            .'Detectado: '.$time;
    }

    public function targetLabel(object $server): string
    {
        $type = strtolower(trim((string) ($server->tipo ?? 'tcp')));
        $host = trim((string) ($server->host ?? ''));
        $port = (int) ($server->puerto ?? 0);
        $path = trim((string) ($server->ruta ?? ''));

        if ($type === 'icmp') {
            return $host.' (ICMP)';
        }

        if ($type === 'tcp') {
            return $host.':'.$port.' (TCP)';
        }

        $defaultPort = $type === 'https' ? 443 : 80;

        return $type.'://'.$host
            .($port > 0 && $port !== $defaultPort ? ':'.$port : '')
            .($path !== '' ? '/'.ltrim($path, '/') : '/');
    }
}
