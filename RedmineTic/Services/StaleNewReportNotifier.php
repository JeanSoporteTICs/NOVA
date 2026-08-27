<?php

namespace RedmineTic\Services;

use App\Modulos\Telegram\Services\TelegramService;
use App\Repositories\Reports\AutomaticReportRecipientRepository;
use App\Support\Reports\AutomaticReportSchedule;
use App\Support\Reports\ManagementReportFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RedmineTic\Repositories\RedmineDataRepository;

final class StaleNewReportNotifier
{
    private const PROJECT_KEY = 'redmine_tic';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly RedmineDataRepository $redmine,
        private readonly AutomaticReportRecipientRepository $recipients,
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
        return $this->execute(
            $force,
            AutomaticReportSchedule::previousWeek(now(AutomaticReportSchedule::TIMEZONE)),
            true
        );
    }

    /** @return array<string,int|string> */
    public function runManual(): array
    {
        return $this->execute(
            true,
            AutomaticReportSchedule::lastSevenDays(now(AutomaticReportSchedule::TIMEZONE)),
            false
        );
    }

    /**
     * @param  array{start:CarbonImmutable,end:CarbonImmutable,label:string}  $window
     * @return array<string,int|string>
     */
    private function execute(bool $force, array $window, bool $recordAutomaticDelivery): array
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

        $result = $this->result('completed');
        $selectedRecipientIds = $this->recipients->recipientUserIds(self::PROJECT_KEY);
        $managementRecipients = $this->recipients->managers(self::PROJECT_KEY);
        $openByUser = [];
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

            $novaUserId = (int) ($user['_nova_user_db_id'] ?? 0);
            $receivesIndividual = $selectedRecipientIds === null || in_array($novaUserId, $selectedRecipientIds, true);
            if (! $receivesIndividual && $managementRecipients === []) {
                continue;
            }
            if ($receivesIndividual) {
                $result['recipients']++;
            }
            $deliveryKey = 'nova.redmine_tic.informes.nueva.sent.'.$dayKey.'.'.$assigneeId;
            $skipIndividual = $receivesIndividual && $recordAutomaticDelivery && ! $force && Cache::has($deliveryKey);
            if ($skipIndividual) {
                $result['skipped']++;
            }

            $processingKey = $deliveryKey.'.processing';
            $processingLocked = false;
            if ($receivesIndividual && ! $skipIndividual && $recordAutomaticDelivery && ! $force) {
                $processingLocked = Cache::add($processingKey, true, now(AutomaticReportSchedule::TIMEZONE)->addMinutes(10));
            }
            if ($receivesIndividual && ! $skipIndividual && $recordAutomaticDelivery && ! $force && ! $processingLocked) {
                $result['skipped']++;
                $skipIndividual = true;
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
                    if ($receivesIndividual && ! $skipIndividual && $recordAutomaticDelivery) {
                        Cache::put($deliveryKey, true, now(AutomaticReportSchedule::TIMEZONE)->addHours(26));
                    }
                    if ($receivesIndividual && ! $skipIndividual) {
                        $result['empty']++;
                    }

                    continue;
                }

                $name = trim((string) (($user['nombre'] ?? '').' '.($user['apellido'] ?? '')));
                $openByUser[] = ['name' => $name !== '' ? $name : 'Responsable '.$assigneeId, 'ids' => $newIds];
                if (! $receivesIndividual || $skipIndividual) {
                    continue;
                }
                $chatId = trim((string) ($user['telegram_chat_id'] ?? ''));
                if ($chatId === '') {
                    $result['skipped']++;

                    continue;
                }
                try {
                    $sent = $this->telegram->sendToChat($chatId, $this->notificationMessage($name, $newIds, $window['label']));
                } catch (\Throwable) {
                    $sent = false;
                }

                if (! $sent) {
                    $result['failed']++;

                    continue;
                }

                if ($recordAutomaticDelivery) {
                    Cache::put($deliveryKey, true, now(AutomaticReportSchedule::TIMEZONE)->addHours(26));
                }
                $result['sent']++;
                try {
                    $redmine->recordActivity('informe_nuevos_telegram_ok', [
                        'user_id' => (string) ($user['id'] ?? $assigneeId),
                        'asignado_a' => (string) $assigneeId,
                        'cantidad' => count($newIds),
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
                if ($processingLocked) {
                    Cache::forget($processingKey);
                }
            }
        }

        $this->sendManagementReports($managementRecipients, $openByUser, $window['label'], $dayKey, $force, $recordAutomaticDelivery, $result);

        if ($recordAutomaticDelivery && $result['failed'] === 0) {
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
            ."Semana informada: {$periodLabel}\n"
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

    /**
     * @param  array<int,array{id:int,name:string,chat_id:string}>  $managers
     * @param  array<int,array{name:string,ids:array<int,string>}>  $openByUser
     * @param  array<string,int|string>  $result
     */
    private function sendManagementReports(array $managers, array $openByUser, string $periodLabel, string $dayKey, bool $force, bool $recordAutomaticDelivery, array &$result): void
    {
        if ($openByUser === []) {
            return;
        }

        foreach ($managers as $manager) {
            $result['managers']++;
            $deliveryKey = 'nova.redmine_tic.informes.jefatura.sent.'.$dayKey.'.'.$manager['id'];
            if ($recordAutomaticDelivery && ! $force && Cache::has($deliveryKey)) {
                $result['skipped']++;

                continue;
            }

            try {
                $sent = $this->telegram->sendToChat(
                    $manager['chat_id'],
                    ManagementReportFormatter::message('TIC', '📊', $manager['name'], $openByUser, $periodLabel)
                );
            } catch (\Throwable) {
                $sent = false;
            }

            if (! $sent) {
                $result['manager_failed']++;
                $result['failed']++;

                continue;
            }

            if ($recordAutomaticDelivery) {
                Cache::put($deliveryKey, true, now(AutomaticReportSchedule::TIMEZONE)->addHours(26));
            }
            $result['manager_sent']++;
        }
    }

    /** @return array<string,int|string> */
    private function result(string $reason): array
    {
        return [
            'recipients' => 0,
            'sent' => 0,
            'empty' => 0,
            'skipped' => 0,
            'failed' => 0,
            'unsynced' => 0,
            'managers' => 0,
            'manager_sent' => 0,
            'manager_failed' => 0,
            'reason' => $reason,
        ];
    }
}
