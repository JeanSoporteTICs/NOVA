<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Modulos\Nova\Services\DatabaseSqlBackupService;
use RedmineTic\Repositories\RedmineDataRepository;
use RedmineTic\Services\LegacyTicBackupImportService;

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

Artisan::command('nova:import-legacy-tic-backup
    {path : Carpeta extraida del respaldo legacy TIC}
    {--assignees=117,122 : IDs Redmine separados por coma}
    {--apply : Ejecutar la importacion; sin esta opcion solo analiza}
    {--expect-reports= : Cantidad exacta de reportes esperada}
    {--expect-hour-links= : Cantidad exacta de asociaciones de horas extra esperada}
    {--confirm= : Debe ser IMPORTAR-LEGACY-TIC al ejecutar}', function (
        LegacyTicBackupImportService $importer,
        DatabaseSqlBackupService $backups
    ) {
    $path = (string) $this->argument('path');
    if (!str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) !== 1) {
        $path = base_path($path);
    }
    $assignees = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('assignees')))));
    $summary = $importer->analyze($path, $assignees);
    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if (!$this->option('apply')) {
        $this->info('Simulacion completada. No se modifico la base de datos.');
        return 0;
    }
    if ((string) $this->option('confirm') !== 'IMPORTAR-LEGACY-TIC') {
        $this->error('Falta --confirm=IMPORTAR-LEGACY-TIC.');
        return 1;
    }
    $expectedReports = filter_var($this->option('expect-reports'), FILTER_VALIDATE_INT);
    $expectedHourLinks = filter_var($this->option('expect-hour-links'), FILTER_VALIDATE_INT);
    if ($expectedReports === false || $expectedHourLinks === false) {
        $this->error('Para ejecutar debes indicar --expect-reports y --expect-hour-links.');
        return 1;
    }
    if ((int) $summary['selected_reports'] !== $expectedReports || (int) $summary['selected_hour_links'] !== $expectedHourLinks) {
        $this->error('Los conteos del respaldo no coinciden con los valores esperados. No se importo nada.');
        return 1;
    }

    $backupPath = $backups->create('before-legacy-tic-import');
    $this->info('Respaldo SQL creado: ' . $backupPath);
    $result = $importer->import($path, $assignees);
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $this->info('Importacion legacy TIC completada.');

    return 0;
})->purpose('Analyze or import selected reports from a legacy Redmine TIC JSON backup');


Artisan::command('nova:consolidate-users', function () {
    $normalizeStatus = static function (string $status): string {
        return in_array(strtolower(trim($status)), ['baneado', 'bloqueado', 'inactivo'], true) ? 'baneado' : 'activo';
    };
    $normalizeRole = static function (string $role): string {
        return match (strtolower(trim($role))) {
            'root' => 'root',
            'admin', 'administrador' => 'admin',
            default => 'usuario',
        };
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
    if (\Illuminate\Support\Facades\Schema::hasTable('redmine_tic_perfiles_usuario')) {
        foreach (DB::table('redmine_tic_perfiles_usuario')
            ->join('usuarios_nova', 'usuarios_nova.id', '=', 'redmine_tic_perfiles_usuario.usuario_id')
            ->select([
                'usuarios_nova.id',
                'usuarios_nova.redmine_id',
                'redmine_tic_perfiles_usuario.rol',
                'redmine_tic_perfiles_usuario.estado_usuario',
            ])
            ->get() as $row) {
            $userId = (int) ($row->id ?? 0);
            if ($userId > 0) {
                $saveIntegration($userId, 'redmine_tic', '', trim((string) ($row->redmine_id ?? '')));
                $grantAccess($userId, 'redmine_tic');
                $tic++;
            }
        }
    }

    $mantencion = 0;

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

    $this->info('usuarios_nova reparados: ' . $novaUpdated);
    $this->info('usuarios Mantencion reparados: ' . $mantUpdated);
})->purpose('Repair duplicated first/last names after Redmine Mantencion migration');
