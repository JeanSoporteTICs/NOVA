<?php

use App\Modulos\Nova\Repositories\UserIntegrationRepository;

// Frozen projectUsers composition before the P06 member-scoped profile reads.
return function (bool $withCredentials = true): array {
    if (! $this->novaUsersTableAvailable()) {
        return [];
    }

    $profiles = $this->redmineTicProfilesByUserId();
    $central = [];
    foreach ($this->novaUsersWithProjectAccess($withCredentials) as $nova) {
        $central[(int) $nova->id] = $nova;
    }

    $allRelationalPerms = $this->permRepo()->allPermissionsFromRelational();
    $credentials = $withCredentials ? app(UserIntegrationRepository::class)->redmineCredentialsForUserIds(array_keys($central)) : [];

    $users = [];
    foreach ($central as $nova) {
        $profile = $profiles[(int) $nova->id] ?? null;
        $redmineId = trim((string) ($nova->redmine_id ?? ''));
        $projectId = $redmineId !== '' ? $redmineId : trim((string) ($nova->uuid ?? $nova->usuario ?? ''));
        if ($projectId === '') {
            continue;
        }
        $telegramChatId = trim((string) ($nova->telegram_id_chat ?? ''));

        $perfilId = (int) ($profile->id ?? 0);
        $permissions = ($allRelationalPerms !== null && $perfilId > 0 && isset($allRelationalPerms[$perfilId]))
            ? $allRelationalPerms[$perfilId]
            : $this->jsonArray($profile->permisos ?? null);

        $users[] = [
            'id' => $projectId,
            '_nova_user_db_id' => (int) ($nova->id ?? 0),
            'redmine_id' => $redmineId,
            'rut_sin_dv' => trim((string) ($nova->usuario ?? '')),
            'nombre' => trim((string) ($nova->nombre ?? '')),
            'apellido' => trim((string) ($nova->apellido ?? '')),
            'rut' => trim((string) ($nova->rut ?? '')),
            'email' => trim((string) ($nova->email ?? '')),
            'numero_celular' => '',
            'telegram_chat_id' => $telegramChatId,
            'telegram_source' => $telegramChatId !== '' ? 'nova' : '',
            'api' => $credentials[(int) $nova->id]['secret'] ?? '',
            'rol' => trim((string) ($profile->rol ?? $nova->rol ?? 'usuario')) ?: 'usuario',
            'rol_nova' => strtolower(trim((string) ($nova->rol ?? 'usuario'))),
            'estado_nova' => strtolower(trim((string) ($nova->estado ?? 'activo'))) ?: 'activo',
            'password' => (string) ($nova->password ?? ''),
            'permisos' => $permissions,
            'estado_usuario' => trim((string) ($profile->estado_usuario ?? $nova->estado ?? 'activo')) ?: 'activo',
            'redmine_membership_id' => $profile->redmine_membership_id ?? null,
            '_nova_user_id' => (string) ($nova->uuid ?? ''),
            '_central_only' => $redmineId === '',
            'ultimo_login_at' => (string) ($nova->ultimo_login_at ?? ''),
            'creado_at' => (string) ($nova->creado_at ?? ''),
        ];
    }

    usort($users, static function (array $a, array $b): int {
        $stateA = strtolower(trim((string) ($a['estado_usuario'] ?? $a['estado'] ?? 'activo'))) === 'baneado' ? 1 : 0;
        $stateB = strtolower(trim((string) ($b['estado_usuario'] ?? $b['estado'] ?? 'activo'))) === 'baneado' ? 1 : 0;
        if ($stateA !== $stateB) {
            return $stateA <=> $stateB;
        }

        return strcasecmp(
            trim((string) ($a['nombre'] ?? '').' '.(string) ($a['apellido'] ?? '')),
            trim((string) ($b['nombre'] ?? '').' '.(string) ($b['apellido'] ?? ''))
        );
    });

    return array_values($users);
};
