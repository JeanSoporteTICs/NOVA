<?php

namespace RedmineTic\Services;

use App\Modulos\Telegram\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use RedmineTic\Repositories\RedmineDataRepository;

final class StaleNewReportNotifier
{
    private const PROJECT_KEY = 'redmine_tic';

    private const START_HOUR = 9;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly RedmineDataRepository $redmine,
    ) {}

    /** @return array{recipients:int,sent:int,empty:int,skipped:int,failed:int,unsynced:int,reason:string} */
    public function runIfDue(): array
    {
        $now = now('America/Santiago');
        if ((int) $now->format('G') < self::START_HOUR) {
            return $this->result('before_start_time');
        }

        return $this->run(false);
    }

    /** @return array{recipients:int,sent:int,empty:int,skipped:int,failed:int,unsynced:int,reason:string} */
    public function run(bool $force = false): array
    {
        $redmine = $this->redmine->forProject(self::PROJECT_KEY);
        $config = $redmine->configuration();
        $enabled = filter_var($config['informes_nuevos_habilitado'] ?? true, FILTER_VALIDATE_BOOL);
        if (! $enabled && ! $force) {
            return $this->result('disabled');
        }

        $dayKey = now('America/Santiago')->format('Y-m-d');
        $completedKey = 'nova.redmine_tic.informes.nueva.completed.'.$dayKey;
        if (! $force && Cache::has($completedKey)) {
            return $this->result('already_completed');
        }

        $days = max(1, min(30, (int) ($config['informes_nuevos_dias'] ?? 2)));
        $result = $this->result('completed');
        foreach ($redmine->users() as $user) {
            if (! $this->activeUser($user)) {
                $result['skipped']++;

                continue;
            }

            $assigneeId = trim((string) ($user['redmine_id'] ?? ''));
            if (! preg_match('/^[1-9]\d*$/', $assigneeId)) {
                $result['skipped']++;

                continue;
            }
            $result['recipients']++;
            $deliveryKey = 'nova.redmine_tic.informes.nueva.sent.'.$dayKey.'.'.$assigneeId;
            if (! $force && Cache::has($deliveryKey)) {
                $result['skipped']++;

                continue;
            }

            $chatId = trim((string) ($user['telegram_chat_id'] ?? ''));
            if ($chatId === '') {
                $result['skipped']++;

                continue;
            }

            $processingKey = $deliveryKey.'.processing';
            if (! $force && ! Cache::add($processingKey, true, now('America/Santiago')->addMinutes(10))) {
                $result['skipped']++;

                continue;
            }
            try {
                $result['unsynced'] += $redmine->unsyncedIssueCountForAssignee($assigneeId, $days);
                $issues = $redmine->staleNewIssuesForAssignee($assigneeId, $days);
                if ($issues['error'] !== '') {
                    $result['failed']++;

                    continue;
                }

                $newIds = $issues['ids'];
                if ($newIds === []) {
                    Cache::put($deliveryKey, true, now('America/Santiago')->addHours(26));
                    $result['empty']++;

                    continue;
                }

                $name = trim((string) (($user['nombre'] ?? '').' '.($user['apellido'] ?? '')));
                try {
                    $sent = $this->telegram->sendToChat($chatId, $this->notificationMessage($name, count($newIds), $days));
                } catch (\Throwable) {
                    $sent = false;
                }

                if (! $sent) {
                    $result['failed']++;

                    continue;
                }

                Cache::put($deliveryKey, true, now('America/Santiago')->addHours(26));
                $result['sent']++;
                try {
                    $redmine->recordActivity('informe_nuevos_telegram_ok', [
                        'user_id' => (string) ($user['id'] ?? $assigneeId),
                        'asignado_a' => (string) $assigneeId,
                        'cantidad' => count($newIds),
                        'dias' => $days,
                        'redmine_ids' => $newIds,
                    ]);
                } catch (\Throwable) {
                    // El recordatorio ya fue entregado; la bitácora no debe provocar un reenvío.
                }
            } catch (\Throwable) {
                $result['failed']++;
            } finally {
                if (! $force) {
                    Cache::forget($processingKey);
                }
            }
        }

        if ($result['failed'] === 0) {
            Cache::put($completedKey, true, now('America/Santiago')->addHours(26));
        }

        return $result;
    }

    public function notificationMessage(string $name, int $count, int $days): string
    {
        $greeting = trim($name) !== '' ? 'Hola '.trim($name).'.' : 'Hola.';
        $reportWord = $count === 1 ? 'reporte' : 'reportes';

        return "📋 [NOVA] INFORME TIC\n"
            .$greeting."\n"
            ."Tienes {$count} {$reportWord} sin finalizar.\n"
            ."Estado Redmine: Nueva\n"
            ."Antigüedad: más de {$days} días.\n"
            .'Revisa tus tickets asignados en Redmine.';
    }

    /** @param array<string,mixed> $user */
    private function activeUser(array $user): bool
    {
        return strtolower(trim((string) ($user['estado_usuario'] ?? 'activo'))) === 'activo'
            && strtolower(trim((string) ($user['estado_nova'] ?? 'activo'))) === 'activo';
    }

    /** @return array{recipients:int,sent:int,empty:int,skipped:int,failed:int,unsynced:int,reason:string} */
    private function result(string $reason): array
    {
        return [
            'recipients' => 0,
            'sent' => 0,
            'empty' => 0,
            'skipped' => 0,
            'failed' => 0,
            'unsynced' => 0,
            'reason' => $reason,
        ];
    }
}
