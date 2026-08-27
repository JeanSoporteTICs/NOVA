<?php

namespace App\Repositories\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AutomaticReportRecipientRepository
{
    private const TABLE = 'destinatarios_informes_modulo';

    /**
     * @return array{configured:bool,users:array<int,array<string,mixed>>,totals:array{users:int,recipients:int,managers:int,missing_telegram:int}}
     */
    public function panelData(string $moduleKey): array
    {
        $moduleId = $this->moduleId($moduleKey);
        $users = $this->eligibleUsers($moduleId);
        $settings = $this->settingsByUser($moduleId);
        $configured = $settings !== [];

        foreach ($users as &$user) {
            $userId = (int) $user['id'];
            $hasTelegram = trim((string) ($user['telegram_chat_id'] ?? '')) !== '';
            $hasRedmineIdentity = trim((string) ($user['redmine_id'] ?? '')) !== '';
            $setting = $settings[$userId] ?? null;

            $user['has_telegram'] = $hasTelegram;
            $user['receives_report'] = $configured
                ? ! empty($setting['recibe_informe'])
                : ($hasTelegram && $hasRedmineIdentity);
            $user['is_manager'] = $configured && ! empty($setting['es_jefatura']);
        }
        unset($user);

        return [
            'configured' => $configured,
            'users' => $users,
            'totals' => [
                'users' => count($users),
                'recipients' => count(array_filter($users, static fn (array $user): bool => ! empty($user['receives_report']))),
                'managers' => count(array_filter($users, static fn (array $user): bool => ! empty($user['is_manager']))),
                'missing_telegram' => count(array_filter($users, static fn (array $user): bool => empty($user['has_telegram']))),
            ],
        ];
    }

    /**
     * Null preserves the historical behavior: all eligible module users receive the report
     * until an administrator saves an explicit selection.
     *
     * @return array<int,int>|null
     */
    public function recipientUserIds(string $moduleKey): ?array
    {
        $moduleId = $this->moduleId($moduleKey);
        if ($moduleId === null || ! $this->tableAvailable()) {
            return null;
        }

        if (! DB::table(self::TABLE)->where('modulo_id', $moduleId)->exists()) {
            return null;
        }

        return DB::table(self::TABLE)
            ->where('modulo_id', $moduleId)
            ->where('recibe_informe', 1)
            ->pluck('usuario_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<int,array{id:int,name:string,chat_id:string}> */
    public function managers(string $moduleKey): array
    {
        $moduleId = $this->moduleId($moduleKey);
        if ($moduleId === null || ! $this->tableAvailable()) {
            return [];
        }

        return DB::table(self::TABLE.' as settings')
            ->join('usuarios_nova as users', 'users.id', '=', 'settings.usuario_id')
            ->where('settings.modulo_id', $moduleId)
            ->where('settings.es_jefatura', 1)
            ->whereIn('users.estado', ['activo', 'active'])
            ->whereNotNull('users.telegram_id_chat')
            ->where('users.telegram_id_chat', '<>', '')
            ->orderBy('users.nombre')
            ->orderBy('users.apellido')
            ->get(['users.id', 'users.nombre', 'users.apellido', 'users.telegram_id_chat'])
            ->map(static fn (object $user): array => [
                'id' => (int) $user->id,
                'name' => trim((string) $user->nombre.' '.(string) $user->apellido),
                'chat_id' => trim((string) $user->telegram_id_chat),
            ])
            ->all();
    }

    /**
     * @param  array<int|string,mixed>  $recipientIds
     * @param  array<int|string,mixed>  $managerIds
     */
    public function sync(string $moduleKey, array $recipientIds, array $managerIds): int
    {
        $moduleId = $this->moduleId($moduleKey);
        if ($moduleId === null || ! $this->tableAvailable()) {
            return 0;
        }

        $eligibleIds = array_column($this->eligibleUsers($moduleId), 'id');
        $eligible = array_fill_keys(array_map('intval', $eligibleIds), true);
        $recipients = $this->validatedSelection($recipientIds, $eligible);
        $managers = $this->validatedSelection($managerIds, $eligible);
        $now = now();

        DB::transaction(function () use ($moduleId, $eligible, $recipients, $managers, $now): void {
            DB::table(self::TABLE)->where('modulo_id', $moduleId)->delete();
            if ($eligible === []) {
                return;
            }

            $rows = [];
            foreach (array_keys($eligible) as $userId) {
                $rows[] = [
                    'modulo_id' => $moduleId,
                    'usuario_id' => (int) $userId,
                    'recibe_informe' => isset($recipients[(int) $userId]),
                    'es_jefatura' => isset($managers[(int) $userId]),
                    'creado_at' => $now,
                    'actualizado_at' => $now,
                ];
            }
            DB::table(self::TABLE)->insert($rows);
        });

        return count($recipients);
    }

    private function moduleId(string $moduleKey): ?int
    {
        if (! Schema::hasTable('modulos_nova')) {
            return null;
        }

        $id = DB::table('modulos_nova')->where('clave_modulo', $moduleKey)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array<int,array<string,mixed>> */
    private function eligibleUsers(?int $moduleId): array
    {
        if ($moduleId === null || ! Schema::hasTable('usuarios_nova') || ! Schema::hasTable('permisos_usuario_modulo')) {
            return [];
        }

        $adminRoles = array_map(
            static fn ($role): string => strtolower(trim((string) $role)),
            (array) config('nova.module_admin_roles', ['admin', 'root'])
        );

        return DB::table('usuarios_nova as users')
            ->leftJoin('permisos_usuario_modulo as access', function ($join) use ($moduleId): void {
                $join->on('access.usuario_id', '=', 'users.id')
                    ->where('access.modulo_id', '=', $moduleId);
            })
            ->whereIn('users.estado', ['activo', 'active'])
            ->where(function ($query) use ($adminRoles): void {
                $query->where('access.permitido', 1)->orWhereIn(DB::raw('LOWER(users.rol)'), $adminRoles);
            })
            ->select([
                'users.id',
                'users.usuario',
                'users.redmine_id',
                'users.nombre',
                'users.apellido',
                'users.rol',
                'users.telegram_id_chat as telegram_chat_id',
            ])
            ->distinct()
            ->orderBy('users.nombre')
            ->orderBy('users.apellido')
            ->get()
            ->map(static fn (object $user): array => (array) $user)
            ->all();
    }

    /** @return array<int,array{recibe_informe:bool,es_jefatura:bool}> */
    private function settingsByUser(?int $moduleId): array
    {
        if ($moduleId === null || ! $this->tableAvailable()) {
            return [];
        }

        return DB::table(self::TABLE)
            ->where('modulo_id', $moduleId)
            ->get(['usuario_id', 'recibe_informe', 'es_jefatura'])
            ->mapWithKeys(static fn (object $row): array => [(int) $row->usuario_id => [
                'recibe_informe' => (bool) $row->recibe_informe,
                'es_jefatura' => (bool) $row->es_jefatura,
            ]])
            ->all();
    }

    /**
     * @param  array<int|string,mixed>  $selection
     * @param  array<int,bool>  $eligible
     * @return array<int,bool>
     */
    private function validatedSelection(array $selection, array $eligible): array
    {
        $validated = [];
        foreach ($selection as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && isset($eligible[(int) $id])) {
                $validated[(int) $id] = true;
            }
        }

        return $validated;
    }

    private function tableAvailable(): bool
    {
        return Schema::hasTable(self::TABLE);
    }
}
