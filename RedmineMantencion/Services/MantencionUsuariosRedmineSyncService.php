<?php

namespace App\Modulos\RedmineMantencion\Services;

class MantencionUsuariosRedmineSyncService
{
    private readonly MantencionUsuariosCentralService $central;
    private readonly MantencionUsuariosStorageService $storage;

    public function __construct(MantencionUsuariosCentralService $central, MantencionUsuariosStorageService $storage)
    {
        $this->central = $central;
        $this->storage = $storage;
    }

    public function usuarios_members_url_from_config(): string {
        $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
        $cfg = $repo !== null ? $repo->loadAll() : [];
        if (is_array($cfg)) {
            $custom = trim((string)($cfg['users_members_url'] ?? ''));
            if ($custom !== '') {
                return $custom;
            }
            $platformUrl = trim((string)($cfg['platform_url'] ?? ''));
            if ($platformUrl !== '' && preg_match('#/issues\.json$#', $platformUrl)) {
                return preg_replace('#/issues\.json$#', '/settings/members', $platformUrl);
            }
        }
        return 'https://coresalud.cl/gp/projects/backlog-mantencion-ti/settings/members';
    }

    public function usuarios_members_api_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#/settings/members/?$#', $url)) {
            return preg_replace('#/settings/members/?$#', '/memberships.json', $url);
        }
        if (preg_match('#/issues\.json$#', $url)) {
            return preg_replace('#/issues\.json$#', '/memberships.json', $url);
        }
        return $url;
    }

    public function usuarios_url_with_query(string $url, array $params): string {
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        foreach ($params as $key => $value) {
            $query[$key] = $value;
        }

        $rebuilt = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= (string)($parts['path'] ?? '');
        if ($query !== []) {
            $rebuilt .= '?' . http_build_query($query);
        }
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }

    public function usuarios_redmine_user_api_url(string $membersUrl, string $userId): string {
        $parts = parse_url($membersUrl);
        if (!$parts || empty($parts['scheme']) || empty($parts['host']) || $userId === '') {
            return '';
        }

        $path = (string)($parts['path'] ?? '');
        $prefix = preg_replace('#/projects/.*$#', '', $path);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port . rtrim((string)$prefix, '/') . '/users/' . rawurlencode($userId) . '.json';
    }

    public function usuarios_fetch_redmine_user_detail(string $userId, string $apiKey, string $membersUrl): array {
        static $cache = [];

        if ($userId === '' || $apiKey === '') {
            return [];
        }

        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }

        $url = $this->usuarios_redmine_user_api_url($membersUrl, $userId);
        if ($url === '') {
            return $cache[$userId] = [];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Redmine-API-Key: ' . $apiKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            return $cache[$userId] = [];
        }

        $json = json_decode((string)$resp, true);
        $detail = is_array($json['user'] ?? null) ? $json['user'] : [];

        $detailNombre = trim((string)($detail['firstname'] ?? $detail['first_name'] ?? ''));
        $detailApellido = trim((string)($detail['lastname'] ?? $detail['last_name'] ?? ''));

        if ($detailNombre === '' || $detailApellido === '') {
            $htmlDetail = $this->usuarios_fetch_redmine_user_edit_detail($userId, $apiKey, $membersUrl);
            foreach (['firstname', 'lastname'] as $key) {
                if (trim((string)($detail[$key] ?? '')) === '' && trim((string)($htmlDetail[$key] ?? '')) !== '') {
                    $detail[$key] = $htmlDetail[$key];
                }
            }
        }

        return $cache[$userId] = $detail;
    }

    public function usuarios_fetch_redmine_user_edit_detail(string $userId, string $apiKey, string $membersUrl): array {
        $apiUrl = $this->usuarios_redmine_user_api_url($membersUrl, $userId);
        if ($apiUrl === '') {
            return [];
        }

        $url = preg_replace('#/users/([^/]+)\.json$#', '/users/$1/edit', $apiUrl);
        if (!is_string($url) || $url === $apiUrl) {
            return [];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Redmine-API-Key: ' . $apiKey,
                'Accept: text/html',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $html = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || $code >= 400) {
            return [];
        }

        return [
            'firstname' => $this->usuarios_html_input_value((string)$html, 'user[firstname]'),
            'lastname' => $this->usuarios_html_input_value((string)$html, 'user[lastname]'),
        ];
    }

    public function usuarios_html_input_value(string $html, string $name): string {
        if ($html === '' || $name === '') {
            return '';
        }

        if (!preg_match_all('/<input\b[^>]*>/i', $html, $matches)) {
            return '';
        }

        foreach ($matches[0] as $tag) {
            if ($this->usuarios_html_attr_value($tag, 'name') !== $name) {
                continue;
            }

            return html_entity_decode($this->usuarios_html_attr_value($tag, 'value'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return '';
    }

    public function usuarios_html_attr_value(string $tag, string $attribute): string {
        $quoted = '/\b' . preg_quote($attribute, '/') . '\s*=\s*([\'"])(.*?)\1/i';
        if (preg_match($quoted, $tag, $match)) {
            return (string)$match[2];
        }

        $plain = '/\b' . preg_quote($attribute, '/') . '\s*=\s*([^\s>]+)/i';
        if (preg_match($plain, $tag, $match)) {
            return trim((string)$match[1], "\"'");
        }

        return '';
    }

    public function usuarios_redmine_person_name(array $user, string $apiKey = '', string $membersUrl = ''): array {
        $id = trim((string)($user['id'] ?? ''));
        $nombre = trim((string)($user['firstname'] ?? $user['first_name'] ?? ''));
        $apellido = trim((string)($user['lastname'] ?? $user['last_name'] ?? ''));

        if ($nombre !== '' && $apellido !== '') {
            return [$nombre, $apellido];
        }

        if ($id !== '' && $apiKey !== '' && $membersUrl !== '') {
            $detail = $this->usuarios_fetch_redmine_user_detail($id, $apiKey, $membersUrl);
            $detailNombre = trim((string)($detail['firstname'] ?? $detail['first_name'] ?? ''));
            $detailApellido = trim((string)($detail['lastname'] ?? $detail['last_name'] ?? ''));

            if ($detailNombre !== '') {
                $nombre = $detailNombre;
            }
            if ($detailApellido !== '') {
                $apellido = $detailApellido;
            }
        }

        if ($nombre !== '' && $apellido !== '') {
            return [$nombre, $apellido];
        }

        $fullName = trim((string)($user['name'] ?? ''));
        [$splitNombre, $splitApellido] = usuarios_split_name($fullName);

        return [
            $nombre !== '' ? $nombre : $splitNombre,
            $apellido !== '' ? $apellido : $splitApellido,
        ];
    }

    public function usuarios_redmine_person_identity(array $user, string $apiKey = '', string $membersUrl = ''): array {
        $id = trim((string)($user['id'] ?? ''));
        $detail = $user;
        $login = trim((string)($user['login'] ?? ''));
        $nombre = trim((string)($user['firstname'] ?? $user['first_name'] ?? ''));
        $apellido = trim((string)($user['lastname'] ?? $user['last_name'] ?? ''));

        if ($id !== '' && $apiKey !== '' && $membersUrl !== '' && ($login === '' || $nombre === '' || $apellido === '')) {
            $remoteDetail = $this->usuarios_fetch_redmine_user_detail($id, $apiKey, $membersUrl);
            if ($remoteDetail !== []) {
                $detail = array_merge($user, $remoteDetail);
            }
        }

        [$nombre, $apellido] = $this->usuarios_redmine_person_name($detail, $apiKey, $membersUrl);

        return [
            'id' => $id,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'login' => trim((string)($detail['login'] ?? $login)),
            'mail' => trim((string)($detail['mail'] ?? $detail['email'] ?? '')),
        ];
    }

    public function usuarios_remote_connection(): array {
        $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
        $cfg = $repo !== null ? $repo->loadAll() : [];
        $apiKey = $this->central->usuarios_user_api_token();
        if ($apiKey === '') {
            return ['error' => 'Falta token API para importar usuarios. Agrega tu API personal en Cuentas conectadas.'];
        }
        $url = $this->usuarios_members_api_url($this->usuarios_members_url_from_config());
        if ($url === '') {
            return ['error' => 'Falta URL de miembros para importar usuarios.'];
        }

        return ['apiKey' => $apiKey, 'url' => $url];
    }

    public function usuarios_fetch_remote_memberships(): array {
        $connection = $this->usuarios_remote_connection();
        if (isset($connection['error'])) {
            return $connection;
        }

        $apiKey = (string)$connection['apiKey'];
        $url = (string)$connection['url'];
        $memberships = [];
        $offset = 0;
        $limit = 100;
        do {
            $pageUrl = $this->usuarios_url_with_query($url, ['limit' => $limit, 'offset' => $offset]);
            $ch = curl_init($pageUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'X-Redmine-API-Key: ' . $apiKey,
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 20,
            ]);
            $resp = curl_exec($ch);
            if ($resp === false) {
                $err = curl_error($ch);
                curl_close($ch);
                return ['error' => 'No se pudo conectar para importar usuarios: ' . $err];
            }
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 400) {
                return ['error' => 'HTTP ' . $code . ' al consultar members.'];
            }
            $json = json_decode($resp, true);
            $page = is_array($json['memberships'] ?? null) ? $json['memberships'] : [];
            $memberships = array_merge($memberships, $page);
            $total = (int)($json['total_count'] ?? count($memberships));
            $offset += $limit;
        } while ($offset < $total);

        if (empty($memberships)) {
            return ['error' => 'La respuesta no contiene memberships validos.'];
        }

        return ['ok' => true, 'memberships' => $memberships, 'apiKey' => $apiKey, 'url' => $url];
    }

    public function usuarios_remote_import_preview(array $rows): array {
        $remote = $this->usuarios_fetch_remote_memberships();
        if (isset($remote['error'])) {
            return $remote;
        }

        $currentAccess = [];
        foreach ($rows as $row) {
            if (is_array($row) && trim((string)($row['id'] ?? '')) !== '') {
                $currentAccess[trim((string)$row['id'])] = true;
            }
        }

        $items = [];
        $identityService = app(\App\Modulos\Nova\Services\RedmineIdentityService::class);
        foreach (($remote['memberships'] ?? []) as $membership) {
            if (!is_array($membership)) {
                continue;
            }
            $user = $membership['user'] ?? null;
            if (!is_array($user)) {
                continue;
            }
            $id = trim((string)($user['id'] ?? ''));
            $identity = $this->usuarios_redmine_person_identity($user, (string)$remote['apiKey'], (string)$remote['url']);
            $nombre = $identity['nombre'];
            $apellido = $identity['apellido'];
            $login = $identity['login'];
            if ($id === '' || ($nombre === '' && $apellido === '')) {
                continue;
            }
            $central = $this->central->usuarios_central_access_status_by_redmine_id($id);
            $localMatch = $identityService->projectUserIndexByLogin($rows, $login);
            $centralMatch = $identityService->centralUserByLogin($login);
            $previousId = $localMatch !== null
                ? trim((string)($rows[$localMatch]['redmine_id'] ?? $rows[$localMatch]['id'] ?? ''))
                : trim((string)($centralMatch['redmine_id'] ?? ''));
            $changedId = $previousId !== '' && $previousId !== $id;
            $items[] = [
                'id' => $id,
                'nombre' => $nombre !== '' ? $nombre : trim((string)($user['name'] ?? 'Redmine')),
                'apellido' => $apellido,
                'login' => $login,
                'previous_id' => $changedId ? $previousId : '',
                'status' => $changedId
                    ? 'changed'
                    : (isset($currentAccess[$id]) || $central['has_access'] ? 'current' : ($central['exists'] ? 'revoked' : 'new')),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp(trim($a['nombre'] . ' ' . $a['apellido']), trim($b['nombre'] . ' ' . $b['apellido'])));

        return ['ok' => true, 'items' => $items];
    }

    public function usuarios_sync_remote(array &$rows, ?array $selectedIds = null): array {
        $remote = $this->usuarios_fetch_remote_memberships();
        if (isset($remote['error'])) {
            return $remote;
        }
        $memberships = $remote['memberships'] ?? [];
        $apiKey = (string)($remote['apiKey'] ?? '');
        $url = (string)($remote['url'] ?? '');
        $selected = null;
        if (is_array($selectedIds)) {
            $selected = [];
            foreach ($selectedIds as $id) {
                $id = trim((string)$id);
                if ($id !== '') {
                    $selected[$id] = true;
                }
            }
            if ($selected === []) {
                return ['error' => 'Selecciona al menos un usuario para importar.'];
            }
        }

        $indexed = [];
        foreach ($rows as $idx => $row) {
            if (is_array($row) && isset($row['id'])) {
                $indexed[(string)$row['id']] = $idx;
            }
        }
        $created = 0;
        $updated = 0;
        $identityService = app(\App\Modulos\Nova\Services\RedmineIdentityService::class);
        foreach ($memberships as $membership) {
            if (!is_array($membership)) {
                continue;
            }
            $user = $membership['user'] ?? null;
            if (!is_array($user)) {
                continue;
            }
            $id = trim((string)($user['id'] ?? ''));
            if ($selected !== null && !isset($selected[$id])) {
                continue;
            }
            $identity = $this->usuarios_redmine_person_identity($user, $apiKey, $url);
            $nombre = $identity['nombre'];
            $apellido = $identity['apellido'];
            $login = $identity['login'];
            if ($id === '' || ($nombre === '' && $apellido === '')) {
                continue;
            }
            $idx = $indexed[$id] ?? $identityService->projectUserIndexByLogin($rows, $login);
            if ($idx !== null) {
                $previousId = trim((string)($rows[$idx]['redmine_id'] ?? $rows[$idx]['id'] ?? ''));
                $rows[$idx]['id'] = $id;
                $rows[$idx]['redmine_id'] = $id;
                $currentName = trim((string)($rows[$idx]['nombre'] ?? ''));
                $currentLastName = trim((string)($rows[$idx]['apellido'] ?? ''));
                if ($currentName !== $nombre || $currentLastName !== $apellido) {
                    $rows[$idx]['nombre'] = $nombre;
                    $rows[$idx]['apellido'] = $apellido;
                }
                if ($previousId !== '' && $previousId !== $id) {
                    unset($indexed[$previousId]);
                }
                $indexed[$id] = $idx;
                $updated++;
                $rows[$idx]['_preserve_existing_status'] = true;
                $this->central->usuarios_central_upsert($rows[$idx]);
                unset($rows[$idx]['_preserve_existing_status']);
                continue;
            }
            $centralMatch = $identityService->centralUserByLogin($login);
            $newRow = [
                'id' => $id,
                'redmine_id' => $id,
                'rut_sin_dv' => $centralMatch['usuario'] ?? $identityService->accessUsernameFromLogin($login),
                'nombre' => $nombre !== '' ? $nombre : trim((string)($user['name'] ?? 'Redmine')),
                'apellido' => $apellido,
                'rut' => $centralMatch['rut'] ?? $identityService->rutFromLogin($login),
                'numero_celular' => '',
                'estamento' => '',
                'api' => '',
                'core_user' => '',
                'core_pass_enc' => '',
                'nextcloud_user' => '',
                'nextcloud_pass_enc' => '',
                'rol' => 'usuario',
                'estado' => 'baneado',
                'password' => '',
                'permisos' => [],
                '_preserve_existing_status' => true,
            ];
            if ($centralMatch !== null) {
                $newRow['_nova_user_id'] = $centralMatch['uuid'];
            }
            $rows[] = $newRow;
            $this->central->usuarios_central_upsert($newRow);
            $indexed[$id] = count($rows) - 1;
            if ($centralMatch !== null) {
                $updated++;
            } else {
                $created++;
            }
        }
        $this->storage->save_usuarios('', $rows);
        return ['ok' => true, 'created' => $created, 'updated' => $updated];
    }
}
