<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\Nova\Support\SecretValue;
use App\Modulos\RedmineMantencion\Repositories\MantencionAdministrationRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MantencionNextcloudProvisioningService
{
    public function __construct(
        private readonly MantencionConfigRepository $config,
        private readonly MantencionAdministrationRepository $admin,
    ) {}

    /** @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>,groups:array<int,string>} */
    public function preview(UploadedFile $file, string $defaultGroup = ''): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            return ['ok' => false, 'error' => 'El archivo debe ser CSV o XLSX.', 'rows' => [], 'groups' => []];
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'El archivo supera el máximo de 5 MB.', 'rows' => [], 'groups' => []];
        }
        $parsed = $extension === 'csv' ? $this->csv($file->getRealPath()) : $this->xlsx($file->getRealPath());
        if ($parsed === []) {
            return ['ok' => false, 'error' => 'El archivo no contiene usuarios válidos.', 'rows' => [], 'groups' => []];
        }

        $credentials = $this->credentials();
        if ($credentials === null) {
            return ['ok' => false, 'error' => 'Configura URL, usuario y contraseña administrativa de Nextcloud.', 'rows' => [], 'groups' => []];
        }
        $groupsResult = $this->listGroups();
        $groups = $groupsResult['ok'] ? $groupsResult['groups'] : [];
        $groupsByKey = [];
        foreach ($groups as $group) {
            $groupsByKey[$this->normalizedKey($group)] = $group;
        }

        $seenUserIds = [];
        $rows = [];
        foreach ($parsed as $raw) {
            $rawUserId = trim((string) ($raw['userid'] ?? $raw['usuario'] ?? $raw['rut'] ?? $raw['email'] ?? ''));
            $userId = $this->userId($rawUserId);
            $displayName = trim((string) ($raw['display_name'] ?? $raw['nombre'] ?? ''));
            $email = trim((string) ($raw['email'] ?? $raw['correo'] ?? ''));
            $groupRaw = trim((string) ($raw['grupo'] ?? $raw['group'] ?? $defaultGroup));
            $password = trim((string) ($raw['password'] ?? $raw['clave'] ?? '')) ?: Str::password(16);
            $matchedGroup = $groupRaw !== '' ? ($groupsByKey[$this->normalizedKey($groupRaw)] ?? '') : '';

            $duplicate = $userId !== '' && isset($seenUserIds[$userId]);
            if ($userId !== '') {
                $seenUserIds[$userId] = true;
            }

            $rows[] = [
                'userid' => $userId,
                'raw_userid' => $rawUserId,
                'userid_normalized' => $userId !== '' && $userId !== $rawUserId,
                'display_name' => $displayName,
                'email' => $email,
                'email_valid' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
                'group' => $matchedGroup,
                'quota' => '',
                'password' => $password,
                'duplicate' => $duplicate,
            ];
        }

        return ['ok' => true, 'error' => '', 'rows' => $rows, 'groups' => $groups];
    }

    /** @param array<int,array<string,mixed>> $rows @return array{ok:bool,total:int,created:int,existing:int,failed:int,error:string} */
    public function confirmImport(array $rows): array
    {
        if ($rows === []) {
            return $this->failure('No hay usuarios para crear.');
        }
        $credentials = $this->credentials();
        if ($credentials === null) {
            return $this->failure('Configura URL, usuario y contraseña administrativa de Nextcloud.');
        }
        [$url, $adminUser, $adminPass] = $credentials;

        $moduleId = $this->admin->moduleId();
        $batchId = DB::table('redmine_mantencion_nextcloud_historial_lotes')->insertGetId([
            'modulo_id' => $moduleId, 'legacy_id' => 'native-'.now()->format('YmdHis').'-'.Str::lower(Str::random(5)), 'created_at_cl' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $created = $existing = $failed = 0;
        foreach ($rows as $row) {
            $userId = $this->userId((string) ($row['userid'] ?? ''));
            $displayName = trim((string) ($row['display_name'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));
            $group = trim((string) ($row['group'] ?? ''));
            $quota = trim((string) ($row['quota'] ?? ''));
            $password = trim((string) ($row['password'] ?? '')) ?: Str::password(16);
            if ($userId === '' || $displayName === '') {
                $failed++;
                $this->history($batchId, 'failed', $userId, $displayName, $email, $group, 'Usuario o nombre incompleto.');

                continue;
            }
            $exists = $this->ocs($url, $adminUser, $adminPass, 'GET', '/users/'.rawurlencode($userId));
            if ($exists['ok']) {
                $existing++;
                $this->history($batchId, 'existing', $userId, $displayName, $email, $group, 'El usuario ya existía.');

                continue;
            }
            $create = $this->ocs($url, $adminUser, $adminPass, 'POST', '/users', ['userid' => $userId, 'password' => $password, 'displayName' => $displayName, 'email' => $email]);
            if (! $create['ok']) {
                $failed++;
                $this->history($batchId, 'failed', $userId, $displayName, $email, $group, $create['error']);

                continue;
            }
            if ($group !== '') {
                $this->ocs($url, $adminUser, $adminPass, 'POST', '/users/'.rawurlencode($userId).'/groups', ['groupid' => $group]);
            }
            if ($quota !== '') {
                $this->ocs($url, $adminUser, $adminPass, 'PUT', '/users/'.rawurlencode($userId), ['key' => 'quota', 'value' => $quota]);
            }
            $created++;
            $this->history($batchId, 'created', $userId, $displayName, $email, $group, 'Usuario creado.');
        }

        return ['ok' => $failed === 0, 'total' => count($rows), 'created' => $created, 'existing' => $existing, 'failed' => $failed, 'error' => $failed ? 'Algunos usuarios no pudieron crearse.' : ''];
    }

    /** @return array{ok:bool,groups:array<int,string>,error:string} */
    public function listGroups(): array
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return ['ok' => false, 'groups' => [], 'error' => 'Configura URL, usuario y contraseña administrativa de Nextcloud.'];
        }
        [$url, $adminUser, $adminPass] = $credentials;
        $result = $this->ocs($url, $adminUser, $adminPass, 'GET', '/groups');
        if (! $result['ok']) {
            return ['ok' => false, 'groups' => [], 'error' => $result['error']];
        }

        $groups = is_array($result['data']['groups'] ?? null) ? $result['data']['groups'] : [];
        sort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return ['ok' => true, 'groups' => array_values($groups), 'error' => ''];
    }

    /** @return array{0:string,1:string,2:string}|null */
    private function credentials(): ?array
    {
        $cfg = $this->config->loadAll() ?? [];
        $url = rtrim(trim((string) ($cfg['nextcloud_url'] ?? '')), '/');
        $adminUser = trim((string) ($cfg['nextcloud_admin_user'] ?? ''));
        $adminPass = SecretValue::decryptSecret((string) ($cfg['nextcloud_admin_pass_enc'] ?? '')) ?? '';
        if ($url === '' || $adminUser === '' || $adminPass === '') {
            return null;
        }

        return [$url, $adminUser, $adminPass];
    }

    private function normalizedKey(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($value), 'UTF-8'));

        return preg_replace('/[^a-z0-9]+/', '', is_string($ascii) ? $ascii : $value) ?? '';
    }

    /** @return array<int,array<string,string>> */
    private function csv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $header = fgetcsv($handle, 0, ',');
        if (! is_array($header)) {
            fclose($handle);

            return [];
        }
        $header = array_map(fn ($value) => $this->key((string) $value), $header);
        $rows = [];
        while (($values = fgetcsv($handle, 0, ',')) !== false) {
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = trim((string) ($values[$i] ?? ''));
            }
            if (array_filter($row, fn ($value) => $value !== '')) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return array<int,array<string,string>> */
    private function xlsx(string $path): array
    {
        if (! class_exists(\ZipArchive::class)) {
            return [];
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }
        $shared = [];
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($xml)) {
            try {
                $sx = simplexml_load_string($xml);
                foreach ($sx->si ?? [] as $si) {
                    $shared[] = trim((string) $si->t);
                }
            } catch (\Throwable) {
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (! is_string($sheet)) {
            return [];
        }
        try {
            $sx = simplexml_load_string($sheet);
        } catch (\Throwable) {
            return [];
        }
        $matrix = [];
        foreach ($sx->sheetData->row ?? [] as $row) {
            $values = [];
            foreach ($row->c ?? [] as $cell) {
                $ref = (string) $cell['r'];
                preg_match('/^[A-Z]+/', $ref, $m);
                $index = $this->columnIndex($m[0] ?? 'A');
                $value = (string) $cell->v;
                if ((string) $cell['t'] === 's') {
                    $value = $shared[(int) $value] ?? '';
                }
                $values[$index] = trim($value);
            }
            if ($values) {
                $matrix[] = $values;
            }
        }
        if (! $matrix) {
            return [];
        }
        $headers = array_map(fn ($value) => $this->key((string) $value), array_shift($matrix));
        $rows = [];
        foreach ($matrix as $values) {
            $row = [];
            foreach ($headers as $i => $key) {
                $row[$key] = trim((string) ($values[$i] ?? ''));
            }
            if (array_filter($row, fn ($value) => $value !== '')) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array{ok:bool,error:string,data:mixed} */
    private function ocs(string $url, string $user, string $pass, string $method, string $path, array $payload = []): array
    {
        $ch = curl_init($url.'/ocs/v1.php/cloud'.'/'.ltrim($path, '/').'?format=json');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_USERPWD => $user.':'.$pass, CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Accept: application/json'], CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20]);
        if ($payload) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        }
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string) curl_error($ch));
        curl_close($ch);
        $data = json_decode((string) $body, true);
        $status = (int) ($data['ocs']['meta']['statuscode'] ?? 0);
        $ok = $body !== false && $error === '' && $http >= 200 && $http < 300 && in_array($status, [100, 200], true);

        return [
            'ok' => $ok,
            'error' => $error !== '' ? $error : trim((string) ($data['ocs']['meta']['message'] ?? ('HTTP '.$http))),
            'data' => $data['ocs']['data'] ?? null,
        ];
    }

    private function history(int $batchId, string $status, string $userId, string $name, string $email, string $group, string $message): void
    {
        DB::table('redmine_mantencion_nextcloud_historial_usuarios')->insert([
            'lote_id' => $batchId,
            'tipo' => 'usuario',
            'userid' => $userId,
            'display_name' => $name,
            'email' => $email ?: null,
            'grupo' => $group ?: null,
            'status' => $status,
            'message' => $message ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userId(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($value), 'UTF-8'));

        return trim(preg_replace('/[^a-z0-9._-]+/', '.', is_string($ascii) ? $ascii : $value) ?? '', '.');
    }

    private function key(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($value), 'UTF-8'));

        return trim(preg_replace('/[^a-z0-9]+/', '_', is_string($ascii) ? $ascii : $value) ?? '', '_');
    }

    private function columnIndex(string $letters): int
    {
        $value = 0;
        foreach (str_split($letters) as $letter) {
            $value = $value * 26 + (ord($letter) - 64);
        }

        return max(0, $value - 1);
    }

    /** @return array{ok:false,total:int,created:int,existing:int,failed:int,error:string} */
    private function failure(string $error): array
    {
        return ['ok' => false, 'total' => 0, 'created' => 0, 'existing' => 0, 'failed' => 0, 'error' => $error];
    }
}
