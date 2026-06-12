<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Support\RedmineMantencion\RedmineMantencionStorageRepository;
use RedmineTic\Support\Redmine\RedmineDataRepository;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('redmine:archive-processed', function (RedmineDataRepository $redmine) {
    $archived = $redmine->archiveExpiredProcessedReports();
    $this->info($archived . ' reporte(s) procesado(s) archivado(s) por retencion.');
})->purpose('Archive processed Redmine reports after configured retention hours');

Artisan::command('redmine:tic-import-json', function (RedmineDataRepository $redmine) {
    $summary = $redmine->forProject('redmine_tic')->importJsonDataToDatabase();

    $this->info('Migracion Redmine TIC JSON -> MariaDB completada.');
    foreach ($summary as $key => $count) {
        $this->line(str_replace('_', ' ', $key) . ': ' . $count);
    }
})->purpose('Import Redmine TIC JSON data into NOVA database tables');

Artisan::command('redmine:mantencion-import-json', function (RedmineMantencionStorageRepository $storage) {
    $summary = $storage->importDataDirectory(base_path('redmine-mantencion/data'));

    $this->info('Migracion Redmine Mantencion JSON -> MariaDB completada.');
    foreach ($summary as $key => $count) {
        $this->line(str_replace('_', ' ', $key) . ': ' . $count);
    }
})->purpose('Import Redmine Mantencion JSON/text data into NOVA database tables');

Artisan::command('nova:consolidate-users', function () {
    $normalizeStatus = static function (string $status): string {
        return in_array(strtolower(trim($status)), ['baneado', 'bloqueado', 'inactivo'], true) ? 'baneado' : 'activo';
    };
    $normalizeRole = static function (string $role): string {
        return in_array(strtolower(trim($role)), ['admin', 'administrador', 'gestor', 'root'], true) ? 'admin' : 'usuario';
    };
    $splitPerson = static function (string $name, string $lastName = ''): array {
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? '';
        $lastName = preg_replace('/\s+/', ' ', trim($lastName)) ?? '';
        if ($lastName !== '') {
            return [mb_substr($name !== '' ? $name : 'Redmine', 0, 120), mb_substr($lastName, 0, 160)];
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) <= 1) {
            return [mb_substr($name !== '' ? $name : 'Redmine', 0, 120), 'Usuario'];
        }
        $lastLen = count($parts) >= 4 ? 2 : 1;
        return [
            mb_substr(implode(' ', array_slice($parts, 0, -$lastLen)), 0, 120),
            mb_substr(implode(' ', array_slice($parts, -$lastLen)), 0, 160),
        ];
    };
    $uniqueUsername = static function (string $username, ?int $currentId = null): string {
        $username = trim($username) !== '' ? trim($username) : (string) \Illuminate\Support\Str::uuid();
        $candidate = $username;
        $suffix = 2;
        while (true) {
            $query = DB::table('usuarios_nova')->where('usuario', $candidate);
            if ($currentId !== null) {
                $query->where('id', '<>', $currentId);
            }
            if (!$query->exists()) {
                return $candidate;
            }
            $candidate = $username . '-' . $suffix;
            $suffix++;
        }
    };
    $upsertNova = function (array $source, string $origin) use ($normalizeStatus, $normalizeRole, $splitPerson, $uniqueUsername): ?int {
        $redmineId = trim((string) ($source['redmine_id'] ?? $source['id'] ?? ''));
        $rut = trim((string) ($source['rut'] ?? ''));
        $rawUsername = trim((string) ($source['rut_sin_dv'] ?? $source['username'] ?? $source['usuario'] ?? ''));
        $username = $rawUsername !== '' ? $rawUsername : $redmineId;
        [$name, $lastName] = $splitPerson((string) ($source['nombre'] ?? $source['name'] ?? ''), (string) ($source['apellido'] ?? ''));
        if ($username === '' || $name === '') {
            return null;
        }

        $existing = null;
        if ($redmineId !== '') {
            $existing = DB::table('usuarios_nova')->where('redmine_id', $redmineId)->first();
        }
        if (!$existing && $rut !== '') {
            $existing = DB::table('usuarios_nova')->where('rut', $rut)->first();
        }
        if (!$existing && $username !== '') {
            $existing = DB::table('usuarios_nova')->where('usuario', $username)->first();
        }
        if ($existing && trim((string) ($source['nombre'] ?? $source['name'] ?? '')) === '') {
            $name = trim((string) ($existing->nombre ?? $name));
        }
        if ($existing && trim((string) ($source['apellido'] ?? '')) === '') {
            $lastName = trim((string) ($existing->apellido ?? $lastName));
        }
        if ($existing && $rawUsername === '') {
            $username = trim((string) ($existing->usuario ?? $username));
        }

        $values = [
            'usuario' => $uniqueUsername($username, $existing?->id),
            'rut' => $rut !== '' ? $rut : null,
            'redmine_id' => $redmineId !== '' ? $redmineId : null,
            'nombre' => $name,
            'apellido' => $lastName,
            'rol' => $normalizeRole((string) ($source['rol'] ?? $source['role'] ?? 'usuario')),
            'estado' => $normalizeStatus((string) ($source['estado_usuario'] ?? $source['estado'] ?? $source['status'] ?? 'activo')),
            'usuario_core' => trim((string) ($source['core_user'] ?? $source['usuario_core'] ?? '')) ?: null,
            'actualizado_at' => now(),
        ];

        try {
            if ($existing) {
                DB::table('usuarios_nova')->where('id', $existing->id)->update($values);
                return (int) $existing->id;
            }

            $values['uuid'] = (string) \Illuminate\Support\Str::uuid();
            $values['password'] = (string) ($source['password'] ?? '') ?: \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(40));
            $values['creado_at'] = now();

            return (int) DB::table('usuarios_nova')->insertGetId($values);
        } catch (\Throwable $e) {
            $this->warn('No se pudo consolidar usuario ' . ($redmineId ?: $username) . ' desde ' . $origin . ': ' . $e->getMessage());
            return null;
        }
    };
    $saveIntegration = static function (int $userId, string $type, string $secret = '', string $external = '', string $chatId = ''): void {
        if ($userId <= 0 || ($secret === '' && $external === '' && $chatId === '')) {
            return;
        }
        $values = [
            'usuario_externo' => $external !== '' ? $external : null,
            'chat_id' => $chatId !== '' ? $chatId : null,
            'actualizado_at' => now(),
        ];
        if ($secret !== '') {
            try {
                $values['valor_secreto'] = encrypt($secret);
            } catch (\Throwable) {
                $values['valor_secreto'] = $secret;
            }
        }
        DB::table('integraciones_usuario')->updateOrInsert(
            ['usuario_id' => $userId, 'tipo' => $type],
            $values
        );
    };
    $grantAccess = static function (int $userId, string $moduleKey): void {
        if ($userId <= 0) {
            return;
        }
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', $moduleKey)->value('id');
        if ($moduleId === null) {
            return;
        }
        DB::table('permisos_usuario_modulo')->updateOrInsert(
            ['usuario_id' => $userId, 'modulo_id' => (int) $moduleId],
            ['permitido' => 1, 'actualizado_at' => now()]
        );
    };

    if (!\Illuminate\Support\Facades\Schema::hasTable('usuarios_nova')) {
        $this->error('No existe usuarios_nova.');
        return 1;
    }

    $tic = 0;
    if (\Illuminate\Support\Facades\Schema::hasTable('redmine_tic_usuarios')) {
        foreach (DB::table('redmine_tic_usuarios')->get() as $row) {
            $payload = [
                'id' => $row->redmine_id ?? '',
                'rut_sin_dv' => $row->rut_sin_dv ?? '',
                'rut' => $row->rut ?? '',
                'nombre' => $row->nombre ?? '',
                'apellido' => $row->apellido ?? '',
                'rol' => $row->rol ?? 'usuario',
                'estado_usuario' => $row->estado_usuario ?? 'activo',
            ];
            $userId = $upsertNova($payload, 'redmine_tic');
            if ($userId !== null) {
                $saveIntegration($userId, 'redmine_tic', trim((string) ($row->api_token ?? '')), (string) ($row->redmine_id ?? ''));
                $saveIntegration($userId, 'telegram', '', '', trim((string) ($row->telegram_chat_id ?? '')));
                $grantAccess($userId, 'redmine_tic');
                $tic++;
            }
        }
        DB::table('redmine_tic_usuarios')->update([
            'rut_sin_dv' => null,
            'rut' => null,
            'nombre' => null,
            'apellido' => null,
            'api_token' => null,
        ]);
    }

    $mantencion = 0;
    $storageRow = \Illuminate\Support\Facades\Schema::hasTable('redmine_mantencion_storage')
        ? DB::table('redmine_mantencion_storage')->where('path', 'usuarios.json')->first()
        : null;
    $mantencionUsers = $storageRow ? json_decode((string) $storageRow->payload_json, true) : [];
    if (is_array($mantencionUsers)) {
        foreach ($mantencionUsers as $user) {
            if (!is_array($user)) {
                continue;
            }
            $userId = $upsertNova($user, 'redmine_mantencion');
            if ($userId !== null) {
                $redmineId = trim((string) ($user['id'] ?? $user['redmine_id'] ?? ''));
                $saveIntegration($userId, 'redmine_mantencion', trim((string) ($user['api'] ?? '')), $redmineId);
                $grantAccess($userId, 'redmine-mantencion');
                $mantencion++;
            }
        }
    }

    $this->info('Usuarios TIC consolidados: ' . $tic);
    $this->info('Usuarios Mantencion consolidados: ' . $mantencion);
    $this->info('Identidad central: usuarios_nova. Secretos/API: integraciones_usuario.');

    return 0;
})->purpose('Consolidate NOVA, Redmine TIC and Redmine Mantencion users into central tables');

Artisan::command('redmine:mantencion-repair-user-names', function () {
    $fixMojibake = static function (string $value): string {
        $value = strtr($value, [
            'ÃƒÆ’Ã‚Â¡' => 'á', 'ÃƒÆ’Ã‚Â©' => 'é', 'ÃƒÆ’Ã‚Â­' => 'í', 'ÃƒÆ’Ã‚Â³' => 'ó', 'ÃƒÆ’Ã‚Âº' => 'ú',
            'ÃƒÆ’Ã‚ÂÁ' => 'Á', 'ÃƒÆ’Ã‚Â‰' => 'É', 'ÃƒÆ’Ã‚Â�' => 'Í', 'ÃƒÆ’Ã‚Â“' => 'Ó', 'ÃƒÆ’Ã‚Âš' => 'Ú',
            'ÃƒÆ’Ã‚Â±' => 'ñ', 'ÃƒÆ’Ã‚Â‘' => 'Ñ',
            'Ã¡' => 'á', 'Ã©' => 'é', 'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú', 'Ã±' => 'ñ',
            'Ã�' => 'Í', 'Ã“' => 'Ó', 'Ãš' => 'Ú', 'Ã‘' => 'Ñ',
        ]);
        if (preg_match('/Ã|Â/u', $value) !== 1) {
            return $value;
        }
        $fixed = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        return is_string($fixed) && trim($fixed) !== '' ? $fixed : $value;
    };
    $cleanSpaces = static fn (string $value): string => preg_replace('/\s+/', ' ', trim($fixMojibake($value))) ?? '';
    $textKey = static function (string $value) use ($cleanSpaces): string {
        $value = $cleanSpaces($value);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim((string) $value);
    };
    $stripPhrase = static function (string $name, string $lastName) use ($cleanSpaces, $textKey): string {
        $name = $cleanSpaces($name);
        $lastKey = $textKey($lastName);
        $lastLen = count(array_filter(explode(' ', $lastKey), static fn ($value): bool => $value !== ''));
        if ($name === '' || $lastKey === '' || $lastLen < 1) {
            return $name;
        }
        while (true) {
            $tokens = preg_split('/\s+/', $name) ?: [];
            if (count($tokens) <= $lastLen || $textKey(implode(' ', array_slice($tokens, -$lastLen))) !== $lastKey) {
                break;
            }
            $name = implode(' ', array_slice($tokens, 0, -$lastLen));
        }
        return $cleanSpaces($name);
    };
    $detectRepeatedSuffix = static function (string $fullName) use ($cleanSpaces, $textKey): array {
        $fullName = $cleanSpaces($fullName);
        $tokens = preg_split('/\s+/', $fullName) ?: [];
        $count = count($tokens);
        if ($count < 3) {
            return [$fullName, ''];
        }
        for ($len = min(4, intdiv($count, 2)); $len >= 1; $len--) {
            $suffix = array_slice($tokens, -$len);
            $previous = array_slice($tokens, -($len * 2), $len);
            if ($textKey(implode(' ', $suffix)) !== $textKey(implode(' ', $previous))) {
                continue;
            }
            $nameTokens = $tokens;
            while (count($nameTokens) > $len && $textKey(implode(' ', array_slice($nameTokens, -$len))) === $textKey(implode(' ', $suffix))) {
                $nameTokens = array_slice($nameTokens, 0, -$len);
            }
            return [$cleanSpaces(implode(' ', $nameTokens)), $cleanSpaces(implode(' ', $suffix))];
        }
        return [$fullName, ''];
    };
    $splitFullName = static function (string $fullName) use ($cleanSpaces, $detectRepeatedSuffix): array {
        $fullName = $cleanSpaces($fullName);
        [$name, $lastName] = $detectRepeatedSuffix($fullName);
        if ($lastName !== '') {
            return [$name, $lastName];
        }
        $tokens = preg_split('/\s+/', $fullName) ?: [];
        if (count($tokens) < 2) {
            return [$fullName, ''];
        }
        $lastLen = count($tokens) >= 3 ? 2 : 1;
        return [
            $cleanSpaces(implode(' ', array_slice($tokens, 0, -$lastLen))),
            $cleanSpaces(implode(' ', array_slice($tokens, -$lastLen))),
        ];
    };
    $dropMojibakeTail = static function (string $value) use ($cleanSpaces): string {
        $tokens = preg_split('/\s+/', $cleanSpaces($value)) ?: [];
        while (count($tokens) > 1 && preg_match('/Ã|Â/u', (string) end($tokens)) === 1) {
            array_pop($tokens);
        }
        return $cleanSpaces(implode(' ', $tokens));
    };
    $repairPerson = static function (string $name, string $lastName = '') use ($cleanSpaces, $stripPhrase, $splitFullName, $dropMojibakeTail, $detectRepeatedSuffix): array {
        $name = $cleanSpaces($name);
        $lastName = $cleanSpaces($lastName);
        if ($lastName !== '') {
            [$lastPrefix, $lastSuffix] = $detectRepeatedSuffix($lastName);
            if ($lastSuffix !== '' && strlen($lastSuffix) < strlen($lastName)) {
                $lastName = $lastSuffix;
            }
            $cleanName = $stripPhrase($name, $lastName);
            [$detectedName, $detectedLastName] = $splitFullName($cleanName);
            if ($detectedLastName !== '' && strlen($detectedName) < strlen($cleanName)) {
                $cleanName = $detectedName;
            }
            $cleanName = $dropMojibakeTail($cleanName);
            if ($cleanName === '') {
                [$cleanName] = $splitFullName($name);
            }
            return [mb_substr($cleanName !== '' ? $cleanName : $name, 0, 120), mb_substr($lastName, 0, 160)];
        }
        [$cleanName, $cleanLastName] = $splitFullName($name);
        return [mb_substr($cleanName, 0, 120), mb_substr($cleanLastName, 0, 160)];
    };

    $historicalByRedmineId = [];
    $historicalJson = shell_exec('git -C ' . escapeshellarg(base_path()) . ' show HEAD:redmine-mantencion/data/usuarios.json 2>/dev/null');
    $historicalUsers = is_string($historicalJson) ? json_decode($historicalJson, true) : [];
    if (is_array($historicalUsers)) {
        foreach ($historicalUsers as $historicalUser) {
            if (!is_array($historicalUser)) {
                continue;
            }
            $id = trim((string) ($historicalUser['id'] ?? ''));
            $fullName = trim((string) ($historicalUser['nombre'] ?? ''));
            if ($id !== '' && $fullName !== '') {
                $historicalByRedmineId[$id] = $fullName;
            }
        }
    }

    $lastNameByRedmineId = [];
    $personByRedmineId = [];
    $novaUpdated = 0;
    foreach (DB::table('usuarios_nova')->get(['id', 'redmine_id', 'nombre', 'apellido']) as $row) {
        $redmineId = trim((string) $row->redmine_id);
        if ($redmineId !== '' && isset($historicalByRedmineId[$redmineId])) {
            [$name, $lastName] = $splitFullName($historicalByRedmineId[$redmineId]);
        } else {
            [$name, $lastName] = $repairPerson((string) $row->nombre, (string) $row->apellido);
        }
        if ((string) $row->nombre !== $name || (string) $row->apellido !== $lastName) {
            DB::table('usuarios_nova')->where('id', $row->id)->update([
                'nombre' => $name,
                'apellido' => $lastName,
            ]);
            $novaUpdated++;
        }
        if ($redmineId !== '' && $lastName !== '') {
            $lastNameByRedmineId[$redmineId] = $lastName;
        }
        if ($redmineId !== '' && $name !== '') {
            $personByRedmineId[$redmineId] = [$name, $lastName];
        }
    }

    $mantUpdated = 0;
    $row = DB::table('redmine_mantencion_storage')->where('path', 'usuarios.json')->first();
    if ($row) {
        $users = json_decode((string) $row->payload_json, true);
        if (is_array($users)) {
            foreach ($users as &$user) {
                if (!is_array($user)) {
                    continue;
                }
                $id = trim((string) ($user['id'] ?? ''));
                if ($id !== '' && isset($historicalByRedmineId[$id])) {
                    [$name, $lastName] = $splitFullName($historicalByRedmineId[$id]);
                } else {
                    $knownLastName = $lastNameByRedmineId[$id] ?? (string) ($user['apellido'] ?? '');
                    [$name, $lastName] = $repairPerson((string) ($user['nombre'] ?? ''), $knownLastName);
                }
                if (($user['nombre'] ?? '') !== $name || ($user['apellido'] ?? '') !== $lastName) {
                    $user['nombre'] = $name;
                    $user['apellido'] = $lastName;
                    $mantUpdated++;
                }
            }
            unset($user);
            $payload = json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            DB::table('redmine_mantencion_storage')->where('path', 'usuarios.json')->update([
                'payload_json' => $payload,
                'bytes' => strlen((string) $payload),
                'checksum' => hash('sha256', (string) $payload),
                'updated_at' => now(),
            ]);
            file_put_contents(base_path('redmine-mantencion/data/usuarios.json'), json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
        }
    }

    $novaFileUpdated = 0;
    $novaFile = storage_path('app/nova/users.json');
    if (is_file($novaFile)) {
        $users = json_decode((string) file_get_contents($novaFile), true);
        if (is_array($users)) {
            foreach ($users as &$user) {
                if (!is_array($user)) {
                    continue;
                }
                $redmineId = trim((string) ($user['redmine_id'] ?? data_get($user, 'projects.redmine-mantencion.id') ?? data_get($user, 'projects.redmine_tic.id') ?? ''));
                if ($redmineId !== '' && isset($personByRedmineId[$redmineId])) {
                    [$name, $lastName] = $personByRedmineId[$redmineId];
                } else {
                    [$name, $lastName] = $repairPerson((string) ($user['name'] ?? $user['nombre'] ?? ''), (string) ($user['apellido'] ?? ''));
                }
                if (($user['name'] ?? '') !== $name || ($user['apellido'] ?? '') !== $lastName) {
                    $user['name'] = $name;
                    $user['apellido'] = $lastName;
                    $novaFileUpdated++;
                }
            }
            unset($user);
            file_put_contents($novaFile, json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
        }
    }

    $this->info('usuarios_nova reparados: ' . $novaUpdated);
    $this->info('usuarios Mantencion reparados: ' . $mantUpdated);
    $this->info('storage/app/nova/users.json reparados: ' . $novaFileUpdated);
})->purpose('Repair duplicated first/last names after Redmine Mantencion migration');
