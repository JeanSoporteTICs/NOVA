<?php

namespace App\Modulos\RedmineMantencion\Services;

class MantencionUsuariosCentralService
{
    public function usuarios_user_api_token(): string {
        if (!function_exists('auth_get_user_id')) {
            return '';
        }
        $userId = auth_get_user_id();
        if ($userId === '') {
            return '';
        }
        if (function_exists('auth_central_redmine_api_token')) {
            $central = auth_central_redmine_api_token($userId);
            if ($central !== '') {
                return $central;
            }
        }
        return '';
    }

    public function usuarios_central_module_id(string $moduleKey = 'redmine-mantencion'): ?int {
        if (!class_exists(\Illuminate\Support\Facades\DB::class)) {
            return null;
        }
        try {
            $id = \Illuminate\Support\Facades\DB::table('modulos_nova')->where('clave_modulo', $moduleKey)->value('id');
            return $id !== null ? (int)$id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function usuarios_central_decrypt_secret(string $secret): string {
        if ($secret === '') {
            return '';
        }
        try {
            return (string)decrypt($secret);
        } catch (\Throwable) {
            return $secret;
        }
    }

    public function usuarios_central_user_api(string $redmineId, string $type = 'redmine'): string {
        $redmineId = trim($redmineId);
        if ($redmineId === '' || !class_exists(\Illuminate\Support\Facades\DB::class)) {
            return '';
        }
        try {
            return app(\App\Modulos\Nova\Repositories\UserIntegrationRepository::class)
                ->redmineTokenForRedmineId($redmineId);
        } catch (\Throwable) {
            return '';
        }
    }

    public function usuarios_central_integration_external(int $userId, string $type): string {
        if ($userId <= 0 || !class_exists(\Illuminate\Support\Facades\DB::class)) {
            return '';
        }
        try {
            return trim((string)\Illuminate\Support\Facades\DB::table('integraciones_usuario')
                ->where('usuario_id', $userId)
                ->where('tipo', $type)
                ->value('usuario_externo'));
        } catch (\Throwable) {
            return '';
        }
    }

    public function usuarios_central_integration_has_secret(int $userId, string $type): bool {
        if ($userId <= 0 || !class_exists(\Illuminate\Support\Facades\DB::class)) {
            return false;
        }
        try {
            $secret = \Illuminate\Support\Facades\DB::table('integraciones_usuario')
                ->where('usuario_id', $userId)
                ->where('tipo', $type)
                ->value('valor_secreto');

            return trim((string)$secret) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Low-level writer: persists $secretValue verbatim (no encryption applied
     * here). Callers are responsible for handing it an already-safe value
     * (either freshly encrypted, or an untouched already-encrypted ciphertext).
     */

    public function usuarios_central_write_integration(int $userId, string $type, ?string $secretValue, string $externalUser = ''): void {
        if ($userId <= 0 || !class_exists(\Illuminate\Support\Facades\DB::class)) {
            return;
        }
        if (($secretValue === null || $secretValue === '') && $externalUser === '') {
            return;
        }
        $values = [
            'usuario_externo' => $externalUser !== '' ? $externalUser : null,
            'actualizado_at' => now(),
        ];
        if ($secretValue !== null && $secretValue !== '') {
            $values['valor_secreto'] = $secretValue;
        }
        try {
            \Illuminate\Support\Facades\DB::table('integraciones_usuario')->updateOrInsert(
                ['usuario_id' => $userId, 'tipo' => $type],
                $values
            );
        } catch (\Throwable) {
        }
    }

    public function usuarios_central_save_integration(int $userId, string $type, string $secret = '', string $externalUser = ''): void {
        $encrypted = null;
        if ($secret !== '') {
            try {
                $encrypted = \App\Modulos\Nova\Support\SecretValue::encryptSecret($secret);
            } catch (\Throwable) {
                // Never persist plaintext nor touch the previous credential on a real encryption failure.
                return;
            }
        }
        $this->usuarios_central_write_integration($userId, $type, $encrypted, $externalUser);
    }

    /**
     * Round-trips whatever is currently stored in valor_secreto (used by the
     * admin "usuarios" form, which has no editable password field and only
     * resaves the value it just read). Detects the actual format via
     * SecretValue instead of assuming enc:v1 — an already Laravel-encrypted
     * value is passed through unchanged (no double-encrypt), a decodable
     * legacy value gets re-encrypted, and an invalid value is left untouched.
     */

    public function usuarios_central_save_integration_encrypted(int $userId, string $type, string $storedSecret = '', string $externalUser = ''): void {
        if ($storedSecret === '') {
            $this->usuarios_central_write_integration($userId, $type, null, $externalUser);
            return;
        }

        $inspection = \App\Modulos\Nova\Support\SecretValue::inspect($storedSecret);
        if ($inspection['status'] === 'invalid') {
            $this->usuarios_central_write_integration($userId, $type, null, $externalUser);
            return;
        }

        if (!$inspection['needs_rewrite']) {
            $this->usuarios_central_write_integration($userId, $type, $storedSecret, $externalUser);
            return;
        }

        $plaintext = \App\Modulos\Nova\Support\SecretValue::decryptSecret($storedSecret);
        $this->usuarios_central_save_integration($userId, $type, (string) $plaintext, $externalUser);
    }

    public function usuarios_central_grant_access(int $userId, string $moduleKey = 'redmine-mantencion', ?string $moduleRole = null): void {
        $moduleId = $this->usuarios_central_module_id($moduleKey);
        if ($userId <= 0 || $moduleId === null || !class_exists(\Illuminate\Support\Facades\DB::class)) {
            return;
        }
        try {
            $values = ['permitido' => 1, 'actualizado_at' => now()];
            if ($moduleRole !== null
                && \Illuminate\Support\Facades\Schema::hasColumn('permisos_usuario_modulo', 'rol_modulo')) {
                $values['rol_modulo'] = usuarios_normalize_module_role($moduleRole);
            }
            \Illuminate\Support\Facades\DB::table('permisos_usuario_modulo')->updateOrInsert(
                ['usuario_id' => $userId, 'modulo_id' => $moduleId],
                $values
            );
        } catch (\Throwable) {
        }
    }

    public function usuarios_central_revoke_access(int $userId, string $moduleKey = 'redmine-mantencion'): bool {
        $moduleId = $this->usuarios_central_module_id($moduleKey);
        if ($userId <= 0 || $moduleId === null || !class_exists(\Illuminate\Support\Facades\DB::class)) {
            return false;
        }
        try {
            return \Illuminate\Support\Facades\DB::table('permisos_usuario_modulo')
                ->where('usuario_id', $userId)
                ->where('modulo_id', $moduleId)
                ->delete() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function usuarios_central_id_for_project_user(array $user): ?int {
        if (!class_exists(\Illuminate\Support\Facades\DB::class)) {
            return null;
        }
        $redmineId = trim((string)($user['redmine_id'] ?? ''));
        if ($redmineId === '' && ctype_digit(trim((string)($user['id'] ?? '')))) {
            $redmineId = trim((string)$user['id']);
        }
        $uuid = trim((string)($user['_nova_user_id'] ?? ''));
        if ($uuid === '' && $redmineId === '' && !ctype_digit(trim((string)($user['id'] ?? '')))) {
            $uuid = trim((string)($user['id'] ?? ''));
        }
        $username = trim((string)($user['rut_sin_dv'] ?? $user['username'] ?? ''));

        try {
            $id = null;
            if ($redmineId !== '') {
                $id = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('redmine_id', $redmineId)->value('id');
            }
            if (!$id && $uuid !== '') {
                $id = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('uuid', $uuid)->value('id');
            }
            if (!$id && $username !== '') {
                $id = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('usuario', $username)->value('id');
            }
            return $id ? (int)$id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function usuarios_central_upsert(array $user, string $moduleKey = 'redmine-mantencion'): ?int {
        if (!class_exists(\Illuminate\Support\Facades\DB::class)) {
            return null;
        }
        $redmineId = trim((string)($user['redmine_id'] ?? ''));
        if ($redmineId === '' && ctype_digit(trim((string)($user['id'] ?? '')))) {
            $redmineId = trim((string)$user['id']);
        }
        $uuid = trim((string)($user['_nova_user_id'] ?? ''));
        if ($uuid === '' && $redmineId === '' && !ctype_digit(trim((string)($user['id'] ?? '')))) {
            $uuid = trim((string)($user['id'] ?? ''));
        }
        if ($redmineId === '' && $uuid === '') {
            return null;
        }
        $name = trim((string)($user['nombre'] ?? $user['name'] ?? ''));
        $lastName = trim((string)($user['apellido'] ?? ''));
        if ($lastName === '' && str_contains($name, ' ')) {
            [$name, $lastName] = usuarios_split_name($name);
        }
        $name = $name !== '' ? $name : 'Redmine';
        $lastName = $lastName !== '' ? $lastName : 'Usuario';
        $identityService = app(\App\Modulos\Nova\Services\RedmineIdentityService::class);
        $rawUsername = trim((string)($user['rut_sin_dv'] ?? $user['username'] ?? ''));
        $username = $rawUsername !== '' ? $rawUsername : $redmineId;
        $rut = $identityService->rutFromLogin((string)($user['rut'] ?? ''));
        $incomingStatus = array_key_exists('estado', $user) || array_key_exists('estado_usuario', $user)
            ? (string)($user['estado'] ?? $user['estado_usuario'] ?? '')
            : '';
        $status = $incomingStatus !== ''
            ? usuarios_normalize_status($incomingStatus)
            : '';
        $moduleRole = usuarios_normalize_module_role((string)($user['rol'] ?? 'usuario'));
        try {
            $row = null;
            if ($redmineId !== '') {
                $row = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('redmine_id', $redmineId)->first();
            }
            if (!$row && $uuid !== '') {
                $row = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('uuid', $uuid)->first();
            }
            if (!$row && $rut !== '') {
                $match = $identityService->centralUserByLogin($rut);
                $row = $match !== null
                    ? \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('id', $match['id'])->first()
                    : null;
            }
            if (!$row && $rawUsername !== '') {
                $match = $identityService->centralUserByLogin($rawUsername);
                $row = $match !== null
                    ? \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('id', $match['id'])->first()
                    : null;
            }
            if ($row && !empty($user['_preserve_existing_status'])) {
                $status = '';
            }
            if ($row && $rawUsername === '') {
                $username = trim((string)$row->usuario) ?: $username;
            }
            if ($row && $rut === '') {
                $rut = trim((string)$row->rut);
            }
            if (!$row && $status === '') {
                $status = 'activo';
            }

            $values = [
                'usuario'        => $username,
                'rut'            => $rut !== '' ? $rut : null,
                'nombre'         => $name,
                'apellido'       => $lastName,
                'actualizado_at' => now(),
            ];
            if ($redmineId !== '') {
                $values['redmine_id'] = $redmineId;
            }
            if ($status !== '') {
                $values['estado'] = $status;
            }

            if ($row) {
                \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('id', $row->id)->update($values);
                $userId = (int)$row->id;
            } else {
                $values['uuid']      = (string)\Illuminate\Support\Str::uuid();
                // El rol de Mantención es local al módulo y no debe elevar el rol
                // global del usuario recién importado.
                $values['rol']       = 'usuario';
                $values['password']  = \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(40));
                $values['creado_at'] = now();
                $userId = (int)\Illuminate\Support\Facades\DB::table('usuarios_nova')->insertGetId($values);
            }
            if ($redmineId !== '') {
                $identityService->syncRedmineIdAndIntegrations($userId, $redmineId);
            }
            $this->usuarios_central_save_integration($userId, 'redmine', trim((string)($user['api'] ?? '')), $redmineId);
            $this->usuarios_central_save_integration_encrypted($userId, 'core', trim((string)($user['core_pass_enc'] ?? '')), trim((string)($user['core_user'] ?? '')));
            $this->usuarios_central_save_integration_encrypted($userId, 'nextcloud', trim((string)($user['nextcloud_pass_enc'] ?? '')), trim((string)($user['nextcloud_user'] ?? '')));
            $this->usuarios_central_grant_access($userId, $moduleKey, $moduleRole);
            return $userId;
        } catch (\Throwable) {
            return null;
        }
    }

    public function usuarios_merge_central_access(array $rows, string $moduleKey = 'redmine-mantencion'): array {
        $moduleId = $this->usuarios_central_module_id($moduleKey);
        if ($moduleId === null || !class_exists(\Illuminate\Support\Facades\DB::class)) {
            return $rows;
        }
        $indexed = [];
        foreach ($rows as $idx => $row) {
            if (is_array($row) && trim((string)($row['id'] ?? '')) !== '') {
                $indexed[trim((string)$row['id'])] = $idx;
            }
        }
        try {
            $selectColumns = ['usuarios_nova.*'];
            if (\Illuminate\Support\Facades\Schema::hasColumn('permisos_usuario_modulo', 'rol_modulo')) {
                $selectColumns[] = 'permisos_usuario_modulo.rol_modulo';
            }
            $central = \Illuminate\Support\Facades\DB::table('usuarios_nova')
                ->join('permisos_usuario_modulo', 'permisos_usuario_modulo.usuario_id', '=', 'usuarios_nova.id')
                ->where('permisos_usuario_modulo.modulo_id', $moduleId)
                ->where('permisos_usuario_modulo.permitido', 1)
                ->select($selectColumns)
                ->get();
        } catch (\Throwable) {
            return $rows;
        }

        // Prefetch: one query for integraciones_usuario covering every user in $central,
        // instead of the 4-5 per-user queries (usuarios_central_user_api/_integration_external/
        // _integration_has_secret) this loop used to run — see Fase 4 lote 1.
        $centralIds = [];
        foreach ($central as $user) {
            $id = (int)($user->id ?? 0);
            if ($id > 0) {
                $centralIds[] = $id;
            }
        }
        $integrationsByUser = [];
        if ($centralIds !== []) {
            try {
                $integrationRows = \Illuminate\Support\Facades\DB::table('integraciones_usuario')
                    ->whereIn('usuario_id', $centralIds)
                    ->whereIn('tipo', ['redmine', 'redmine_mantencion', 'redmine_tic', 'core', 'nextcloud'])
                    ->get(['usuario_id', 'tipo', 'usuario_externo', 'valor_secreto']);
                foreach ($integrationRows as $integrationRow) {
                    $integrationsByUser[(int)$integrationRow->usuario_id][(string)$integrationRow->tipo] = [
                        'usuario_externo' => (string)($integrationRow->usuario_externo ?? ''),
                        'valor_secreto' => (string)($integrationRow->valor_secreto ?? ''),
                    ];
                }
            } catch (\Throwable) {
                $integrationsByUser = [];
            }
        }

        foreach ($central as $user) {
            $redmineId = trim((string)($user->redmine_id ?? ''));
            $rowId = $redmineId !== '' ? $redmineId : trim((string)($user->uuid ?? $user->usuario ?? ''));
            if ($rowId === '') {
                continue;
            }
            $userIntegrations = $integrationsByUser[(int)($user->id ?? 0)] ?? [];
            // $this->usuarios_central_user_api() returned '' immediately when $redmineId === '';
            // preserved here so central-only users keep the exact same 'api' value.
            $apiSecret = '';
            if ($redmineId !== '') {
                foreach (['redmine', 'redmine_mantencion', 'redmine_tic'] as $redmineType) {
                    $candidate = trim((string)($userIntegrations[$redmineType]['valor_secreto'] ?? ''));
                    if ($candidate !== '') {
                        $apiSecret = $candidate;
                        break;
                    }
                }
            }
            $coreExternal = trim((string)($userIntegrations['core']['usuario_externo'] ?? ''));
            $coreSecret = trim((string)($userIntegrations['core']['valor_secreto'] ?? ''));
            $nextcloudExternal = trim((string)($userIntegrations['nextcloud']['usuario_externo'] ?? ''));
            $nextcloudSecret = trim((string)($userIntegrations['nextcloud']['valor_secreto'] ?? ''));
            $row = [
                'id' => $rowId,
                'redmine_id' => $redmineId,
                'rut_sin_dv' => trim((string)($user->usuario ?? '')),
                'nombre' => trim((string)($user->nombre ?? '')),
                'apellido' => trim((string)($user->apellido ?? '')),
                'rut' => trim((string)($user->rut ?? '')),
                'numero_celular' => '',
                'estamento' => '',
                'api' => $this->usuarios_central_decrypt_secret($apiSecret),
                'core_user' => trim((string)($user->usuario_core ?? '')) ?: $coreExternal,
                'core_pass_enc' => '',
                'has_core_credentials' => $coreSecret !== '',
                'nextcloud_user' => $nextcloudExternal,
                'nextcloud_pass_enc' => '',
                'has_nextcloud_credentials' => $nextcloudSecret !== '',
                'rol' => strtolower(trim((string)($user->rol ?? 'usuario'))) === 'root'
                    ? 'root'
                    : usuarios_normalize_module_role(
                        (string)($user->rol_modulo ?? (
                            in_array(strtolower(trim((string)($user->rol ?? 'usuario'))), ['admin', 'administrador'], true)
                                ? 'administrador'
                                : 'usuario'
                        ))
                    ),
                'estado' => trim((string)($user->estado ?? 'activo')),
                'password' => (string)($user->password ?? ''),
                'permisos' => [],
                '_central_only' => $redmineId === '',
            ];
            if (isset($indexed[$rowId])) {
                $rows[$indexed[$rowId]] = array_merge($rows[$indexed[$rowId]], $row);
            } else {
                $rows[] = $row;
                $indexed[$rowId] = count($rows) - 1;
            }
        }
        return $rows;
    }

    public function usuarios_central_access_status_by_redmine_id(string $redmineId, string $moduleKey = 'redmine-mantencion'): array {
        if ($redmineId === '' || !class_exists(\Illuminate\Support\Facades\DB::class)) {
            return ['exists' => false, 'has_access' => false];
        }
        $moduleId = $this->usuarios_central_module_id($moduleKey);
        try {
            $user = \Illuminate\Support\Facades\DB::table('usuarios_nova')
                ->where('redmine_id', $redmineId)
                ->first(['id']);
            if (!$user) {
                return ['exists' => false, 'has_access' => false];
            }
            $hasAccess = false;
            if ($moduleId !== null) {
                $hasAccess = \Illuminate\Support\Facades\DB::table('permisos_usuario_modulo')
                    ->where('usuario_id', (int)$user->id)
                    ->where('modulo_id', $moduleId)
                    ->where('permitido', 1)
                    ->exists();
            }

            return ['exists' => true, 'has_access' => $hasAccess];
        } catch (\Throwable) {
            return ['exists' => false, 'has_access' => false];
        }
    }
}
