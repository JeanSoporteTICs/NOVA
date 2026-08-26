<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use App\Modulos\Telegram\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class MantencionStaleNewReportNotifier
{
    private const MODULE_KEY = 'redmine-mantencion';

    private const START_HOUR = 9;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly MantencionConfigRepository $config,
    ) {}

    /** @return array{recipients:int,sent:int,empty:int,skipped:int,failed:int,unsynced:int,reason:string} */
    public function runIfDue(): array
    {
        if ((int) now('America/Santiago')->format('G') < self::START_HOUR) {
            return $this->result('before_start_time');
        }

        return $this->run(false);
    }

    /** @return array{recipients:int,sent:int,empty:int,skipped:int,failed:int,unsynced:int,reason:string} */
    public function run(bool $force = false): array
    {
        $config = $this->config->loadAll() ?? [];
        $enabled = filter_var($config['informes_nuevos_habilitado'] ?? true, FILTER_VALIDATE_BOOL);
        if (! $enabled && ! $force) {
            return $this->result('disabled');
        }

        $dayKey = now('America/Santiago')->format('Y-m-d');
        $completedKey = 'nova.redmine_mantencion.informes.nueva.completed.'.$dayKey;
        if (! $force && Cache::has($completedKey)) {
            return $this->result('already_completed');
        }

        $result = $this->result('completed');
        try {
            $users = $this->activeUsers();
        } catch (\Throwable) {
            $result['failed'] = 1;
            $result['reason'] = 'users_unavailable';

            return $result;
        }

        $days = max(1, min(30, (int) ($config['informes_nuevos_dias'] ?? 2)));

        foreach ($users as $user) {
            $assigneeId = trim((string) ($user->redmine_id ?? ''));
            if (! preg_match('/^[1-9]\d*$/', $assigneeId)) {
                $result['skipped']++;

                continue;
            }

            $result['recipients']++;
            $deliveryKey = 'nova.redmine_mantencion.informes.nueva.sent.'.$dayKey.'.'.$assigneeId;
            if (! $force && Cache::has($deliveryKey)) {
                $result['skipped']++;

                continue;
            }

            $chatId = trim((string) ($user->telegram_id_chat ?? ''));
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
                $result['unsynced'] += $this->unsyncedIssueCountForAssignee($assigneeId, $days);
                $ids = $this->staleNewIssueIdsForAssignee($assigneeId, $days);
                if ($ids === []) {
                    Cache::put($deliveryKey, true, now('America/Santiago')->addHours(26));
                    $result['empty']++;

                    continue;
                }

                $name = trim((string) (($user->nombre ?? '').' '.($user->apellido ?? '')));
                if (! $this->telegram->sendToChat($chatId, $this->notificationMessage($name, count($ids), $days))) {
                    $result['failed']++;

                    continue;
                }

                Cache::put($deliveryKey, true, now('America/Santiago')->addHours(26));
                $result['sent']++;
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

        return "🔧 [NOVA] INFORME MANTENCIÓN\n"
            .$greeting."\n"
            ."Tienes {$count} {$reportWord} sin finalizar.\n"
            ."Estado Redmine: Nueva\n"
            ."Antigüedad: más de {$days} días.\n"
            .'Revisa tus tickets asignados en Redmine.';
    }

    /** @return array<int,object> */
    private function activeUsers(): array
    {
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', self::MODULE_KEY)->value('id');
        if ($moduleId === null) {
            throw new \RuntimeException('Módulo Mantención no registrado.');
        }

        $adminRoles = array_map('strval', config('nova.module_admin_roles', ['admin', 'root']));

        return DB::table('usuarios_nova as users')
            ->leftJoin('permisos_usuario_modulo as access', function ($join) use ($moduleId): void {
                $join->on('access.usuario_id', '=', 'users.id')
                    ->where('access.modulo_id', '=', (int) $moduleId);
            })
            ->whereIn('users.estado', ['activo', 'active'])
            ->where(function ($query) use ($adminRoles): void {
                $query->where('access.permitido', 1)->orWhereIn('users.rol', $adminRoles);
            })
            ->whereNotNull('users.telegram_id_chat')
            ->where('users.telegram_id_chat', '<>', '')
            ->whereNotNull('users.redmine_id')
            ->where('users.redmine_id', '<>', '')
            ->distinct()
            ->get(['users.id', 'users.redmine_id', 'users.nombre', 'users.apellido', 'users.telegram_id_chat'])
            ->all();
    }

    /** @return array<int,string> */
    private function staleNewIssueIdsForAssignee(string $assigneeId, int $days): array
    {
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', self::MODULE_KEY)->value('id');
        if ($moduleId === null || ! preg_match('/^[1-9]\d*$/', $assigneeId)) {
            return [];
        }

        return DB::table('redmine_mantencion_reportes')
            ->where('modulo_id', (int) $moduleId)
            ->where('id_redmine_asignado', (int) $assigneeId)
            ->where('estado_redmine', 'Nueva')
            ->whereNotNull('numero_ticket_redmine')
            ->where('creado_at', '<', now('UTC')->subDays(max(1, min(30, $days))))
            ->orderBy('numero_ticket_redmine')
            ->pluck('numero_ticket_redmine')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function unsyncedIssueCountForAssignee(string $assigneeId, int $days): int
    {
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', self::MODULE_KEY)->value('id');
        if ($moduleId === null || ! preg_match('/^[1-9]\d*$/', $assigneeId)) {
            return 0;
        }

        return DB::table('redmine_mantencion_reportes')
            ->where('modulo_id', (int) $moduleId)
            ->where('id_redmine_asignado', (int) $assigneeId)
            ->whereNotNull('numero_ticket_redmine')
            ->where(function ($query): void {
                $query->whereNull('estado_redmine')->orWhere('estado_redmine', '');
            })
            ->where('creado_at', '<', now('UTC')->subDays(max(1, min(30, $days))))
            ->count();
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
