<?php

namespace RedmineTic\Services;

use App\Modulos\Telegram\Services\TelegramService;
use App\Support\Reports\AutomaticReportSchedule;
use Illuminate\Support\Facades\Cache;
use RedmineTic\Repositories\RedmineDataRepository;

final class StaleNewReportNotifier
{
    private const PROJECT_KEY = 'redmine_tic';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly RedmineDataRepository $redmine,
    ) {}

    /** @return array{recipients:int,sent:int,empty:int,skipped:int,failed:int,unsynced:int,reason:string} */
    public function runIfDue(): array
    {
        $config = $this->redmine->forProject(self::PROJECT_KEY)->configuration();
        if (! AutomaticReportSchedule::isDue($config, now(AutomaticReportSchedule::TIMEZONE))) {
            return $this->result('not_scheduled_now');
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

        $dayKey = now(AutomaticReportSchedule::TIMEZONE)->format('Y-m-d');
        $completedKey = 'nova.redmine_tic.informes.nueva.completed.'.$dayKey;
        if (! $force && Cache::has($completedKey)) {
            return $this->result('already_completed');
        }

        $schedule = AutomaticReportSchedule::settings($config);
        $window = AutomaticReportSchedule::reportWindow($config, now(AutomaticReportSchedule::TIMEZONE));
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
            if (! $force && ! Cache::add($processingKey, true, now(AutomaticReportSchedule::TIMEZONE)->addMinutes(10))) {
                $result['skipped']++;

                continue;
            }
            try {
                $result['unsynced'] += $redmine->unsyncedIssueCountForAssignee($assigneeId, $window['start'], $window['end']);
                $issues = $redmine->staleNewIssuesForAssignee($assigneeId, $window['start'], $window['end']);
                if ($issues['error'] !== '') {
                    $result['failed']++;

                    continue;
                }

                $newIds = $issues['ids'];
                if ($newIds === []) {
                    Cache::put($deliveryKey, true, now(AutomaticReportSchedule::TIMEZONE)->addHours(26));
                    $result['empty']++;

                    continue;
                }

                $name = trim((string) (($user['nombre'] ?? '').' '.($user['apellido'] ?? '')));
                try {
                    $sent = $this->telegram->sendToChat($chatId, $this->notificationMessage($name, $newIds, $window['label']));
                } catch (\Throwable) {
                    $sent = false;
                }

                if (! $sent) {
                    $result['failed']++;

                    continue;
                }

                Cache::put($deliveryKey, true, now(AutomaticReportSchedule::TIMEZONE)->addHours(26));
                $result['sent']++;
                try {
                    $redmine->recordActivity('informe_nuevos_telegram_ok', [
                        'user_id' => (string) ($user['id'] ?? $assigneeId),
                        'asignado_a' => (string) $assigneeId,
                        'cantidad' => count($newIds),
                        'periodo' => $schedule['period'],
                        'periodo_desde' => $window['start']->toIso8601String(),
                        'periodo_hasta' => $window['end']->toIso8601String(),
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
            Cache::put($completedKey, true, now(AutomaticReportSchedule::TIMEZONE)->addHours(26));
        }

        return $result;
    }

    /** @param array<int,string> $ids */
    public function notificationMessage(string $name, array $ids, string $periodLabel): string
    {
        $count = count($ids);
        $greeting = trim($name) !== '' ? 'Hola '.trim($name).'.' : 'Hola.';
        $reportWord = $count === 1 ? 'reporte' : 'reportes';
        $openWord = $count === 1 ? 'abierto' : 'abiertos';

        return "📋 [NOVA] INFORME TIC\n"
            .$greeting."\n"
            ."Tienes {$count} {$reportWord} {$openWord}.\n"
            ."Estado: Nueva\n"
            ."Período informado: {$periodLabel}\n"
            .'Tickets: '.$this->ticketSummary($ids)."\n"
            .'Revisa tus tickets asignados en Redmine.';
    }

    /** @param array<int,string> $ids */
    private function ticketSummary(array $ids): string
    {
        $visible = array_slice($ids, 0, 20);
        $summary = implode(', ', array_map(static fn (string $id): string => '#'.$id, $visible));
        $remaining = count($ids) - count($visible);

        return $summary.($remaining > 0 ? ' y '.$remaining.' más' : '');
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
