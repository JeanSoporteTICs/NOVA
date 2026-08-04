<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\RedmineMantencion\Repositories\MantencionAdministrationRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MantencionRedmineUserSyncService
{
    public function __construct(
        private readonly MantencionConfigRepository $config,
        private readonly MantencionAdministrationRepository $admin,
    ) {}

    /** @return array{ok:bool,created:int,updated:int,error:string} */
    public function sync(string $token): array
    {
        if (trim($token) === '') {
            return $this->error('Configura tu API Key personal de Redmine antes de sincronizar.');
        }
        $config = $this->config->loadAll() ?? [];
        $url = trim((string) ($config['users_members_url'] ?? ''));
        if ($url === '') {
            $platform = trim((string) ($config['platform_url'] ?? ''));
            $base = preg_replace('#/issues(?:\.json)?(?:\?.*)?$#i', '', $platform) ?? '';
            $projectId = trim((string) ($config['project_id'] ?? ''));
            $url = $base !== '' && $projectId !== '' ? rtrim($base, '/').'/projects/'.rawurlencode($projectId).'/memberships.json' : '';
        }
        if ($url === '') {
            return $this->error('Falta configurar la URL de miembros de Redmine.');
        }

        $memberships = [];
        for ($offset = 0; ; $offset += 100) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $response = $this->get($url.$separator.http_build_query(['limit' => 100, 'offset' => $offset]), $token);
            if (! $response['ok']) {
                return $this->error($response['error']);
            }
            $page = (array) ($response['data']['memberships'] ?? []);
            $memberships = array_merge($memberships, $page);
            if (count($memberships) >= (int) ($response['data']['total_count'] ?? count($memberships)) || $page === []) {
                break;
            }
        }

        $moduleId = $this->admin->moduleId();
        if ($moduleId === null) {
            return $this->error('El módulo Mantención no está registrado.');
        }
        $created = $updated = 0;
        foreach ($memberships as $membership) {
            $remote = is_array($membership['user'] ?? null) ? $membership['user'] : [];
            $redmineId = trim((string) ($remote['id'] ?? ''));
            if ($redmineId === '') {
                continue;
            }
            $detailUrl = preg_replace('#/projects/[^/]+/memberships\.json.*$#', '/users/'.$redmineId.'.json', $url);
            $detail = is_string($detailUrl) ? $this->get($detailUrl, $token) : ['ok' => false];
            $user = is_array($detail['data']['user'] ?? null) ? $detail['data']['user'] : $remote;
            [$first, $last] = $this->names($user);
            if ($first === '' || $last === '') {
                continue;
            }
            $login = trim((string) ($user['login'] ?? '')) ?: 'redmine-'.$redmineId;
            $existing = DB::table('usuarios_nova')->where('redmine_id', $redmineId)->first();
            if ($existing === null) {
                $existing = DB::table('usuarios_nova')->where('usuario', $login)->first();
            }
            $values = [
                'redmine_id' => $redmineId,
                'usuario' => $login,
                'nombre' => $first,
                'apellido' => $last,
                'email' => trim((string) ($user['mail'] ?? $user['email'] ?? '')) ?: null,
                'actualizado_at' => now(),
            ];
            if ($existing === null) {
                $values += ['uuid' => (string) Str::uuid(), 'rol' => 'usuario', 'estado' => 'activo', 'password' => password_hash(Str::random(48), PASSWORD_DEFAULT), 'creado_at' => now()];
                $userId = (int) DB::table('usuarios_nova')->insertGetId($values);
                $created++;
            } else {
                $userId = (int) $existing->id;
                DB::table('usuarios_nova')->where('id', $userId)->update($values);
                $updated++;
            }
            DB::table('permisos_usuario_modulo')->updateOrInsert(
                ['usuario_id' => $userId, 'modulo_id' => $moduleId],
                ['permitido' => 1, 'rol_modulo' => 'usuario', 'actualizado_at' => now()],
            );
        }

        return ['ok' => true, 'created' => $created, 'updated' => $updated, 'error' => ''];
    }

    /** @return array{ok:bool,data?:array<string,mixed>,error?:string} */
    private function get(string $url, string $token): array
    {
        if (! function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL no está disponible.'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Redmine-API-Key: '.trim($token)], CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string) curl_error($ch));
        curl_close($ch);
        $data = json_decode((string) $body, true);
        if ($body !== false && $error === '' && $http >= 200 && $http < 300 && is_array($data)) {
            return ['ok' => true, 'data' => $data];
        }

        return ['ok' => false, 'error' => $error !== '' ? $error : 'Redmine respondió HTTP '.$http.'.'];
    }

    /** @param array<string,mixed> $user @return array{string,string} */
    private function names(array $user): array
    {
        $first = trim((string) ($user['firstname'] ?? ''));
        $last = trim((string) ($user['lastname'] ?? ''));
        if ($first !== '' && $last !== '') {
            return [$first, $last];
        }
        $parts = preg_split('/\s+/', trim((string) ($user['name'] ?? '')), 2) ?: [];

        return [trim((string) ($parts[0] ?? '')), trim((string) ($parts[1] ?? ''))];
    }

    /** @return array{ok:false,created:int,updated:int,error:string} */
    private function error(string $message): array
    {
        return ['ok' => false, 'created' => 0, 'updated' => 0, 'error' => $message];
    }
}
