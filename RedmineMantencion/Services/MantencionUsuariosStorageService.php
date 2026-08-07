<?php

namespace App\Modulos\RedmineMantencion\Services;

class MantencionUsuariosStorageService
{
    private readonly MantencionUsuariosCentralService $central;

    public function __construct(MantencionUsuariosCentralService $central)
    {
        $this->central = $central;
    }

    public function rut_base($rut) {
        $clean = preg_replace('/[^0-9kK]/', '', $rut ?? '');
        if ($clean === '') return '';
        $clean = strtoupper($clean);
        return strlen($clean) > 1 ? substr($clean, 0, -1) : $clean;
    }

    public function ensure_usr_file($path) {
        // DB-only runtime: usuarios_nova/permisos_usuario_modulo are the source of truth.
    }

    public function usuarios_strip_trailing_phrase(string $value, string $phrase): string {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        $phrase = preg_replace('/\s+/', ' ', trim($phrase)) ?? '';
        if ($value === '' || $phrase === '') {
            return $value;
        }
        $phraseTokens = explode(' ', usuarios_text_key($phrase));
        if ($phraseTokens === ['']) {
            return $value;
        }
        do {
            $tokens = preg_split('/\s+/', $value) ?: [];
            $tail = array_slice($tokens, -count($phraseTokens));
            $tailKey = usuarios_text_key(implode(' ', $tail));
            $phraseKey = implode(' ', $phraseTokens);
            if ($tailKey !== $phraseKey || count($tokens) <= count($phraseTokens)) {
                break;
            }
            $value = implode(' ', array_slice($tokens, 0, -count($phraseTokens)));
        } while (true);

        return trim($value);
    }

    public function usuarios_normalize_person_fields(array &$item): void {
        $nombre = preg_replace('/\s+/', ' ', trim((string)($item['nombre'] ?? ''))) ?? '';
        $apellido = preg_replace('/\s+/', ' ', trim((string)($item['apellido'] ?? ''))) ?? '';
        $nombre = strtr($nombre, [
            'ÃƒÆ’Ã‚Â¡' => 'á', 'ÃƒÆ’Ã‚Â©' => 'é', 'ÃƒÆ’Ã‚Â­' => 'í', 'ÃƒÆ’Ã‚Â³' => 'ó', 'ÃƒÆ’Ã‚Âº' => 'ú',
            'ÃƒÆ’Ã‚Â±' => 'ñ', 'ÃƒÆ’Ã‚Â‘' => 'Ñ',
            'Ã¡' => 'á', 'Ã©' => 'é', 'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú', 'Ã±' => 'ñ',
        ]);
        $apellido = strtr($apellido, [
            'ÃƒÆ’Ã‚Â¡' => 'á', 'ÃƒÆ’Ã‚Â©' => 'é', 'ÃƒÆ’Ã‚Â­' => 'í', 'ÃƒÆ’Ã‚Â³' => 'ó', 'ÃƒÆ’Ã‚Âº' => 'ú',
            'ÃƒÆ’Ã‚Â±' => 'ñ', 'ÃƒÆ’Ã‚Â‘' => 'Ñ',
            'Ã¡' => 'á', 'Ã©' => 'é', 'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú', 'Ã±' => 'ñ',
        ]);
        if ($apellido !== '') {
            [$lastPrefix, $lastSuffix] = usuarios_detect_repeated_suffix($apellido);
            if ($lastSuffix !== '' && strlen($lastSuffix) < strlen($apellido)) {
                $apellido = $lastSuffix;
            }
            $nombre = $this->usuarios_strip_trailing_phrase($nombre, $apellido);
            [$detectedName, $detectedLastName] = usuarios_detect_repeated_suffix($nombre);
            if ($detectedLastName !== '' && strlen($detectedName) < strlen($nombre)) {
                $nombre = $detectedName;
            }
            $tokens = preg_split('/\s+/', $nombre) ?: [];
            while (count($tokens) > 1 && preg_match('/Ã|Â/u', (string)end($tokens)) === 1) {
                array_pop($tokens);
            }
            $nombre = trim(implode(' ', $tokens));
        } else {
            [$detectedName, $detectedLastName] = usuarios_detect_repeated_suffix($nombre);
            $nombre = $detectedName;
            $apellido = $detectedLastName;
        }
        $item['nombre'] = $nombre;
        $item['apellido'] = $apellido;
    }

    public function ensure_user_fields(array &$item) {
        $defaults = [
            'id' => uniqid('', true),
            'rut_sin_dv' => '',
            'nombre' => '',
            'apellido' => '',
            'rut' => '',
            'numero_celular' => '',
            'estamento' => '',
            'api' => '',
            'core_user' => '',
            'core_pass_enc' => '',
            'nextcloud_user' => '',
            'nextcloud_pass_enc' => '',
            'rol' => 'usuario',
            'estado' => 'activo',
            'password' => '',
        ];
        foreach ($defaults as $key => $value) {
            if (!isset($item[$key])) {
                $item[$key] = $value;
            }
        }
        $this->usuarios_normalize_person_fields($item);
        $item['numero_celular'] = '';
        $item['rut_sin_dv'] = '';
        $item['rut'] = '';
        $item['estamento'] = '';
    }

    public function usuarios_sort_for_project(array $rows): array {
        usort($rows, static function (array $a, array $b): int {
            $stateA = strtolower(trim((string)($a['estado'] ?? $a['estado_usuario'] ?? 'activo'))) === 'baneado' ? 1 : 0;
            $stateB = strtolower(trim((string)($b['estado'] ?? $b['estado_usuario'] ?? 'activo'))) === 'baneado' ? 1 : 0;
            if ($stateA !== $stateB) {
                return $stateA <=> $stateB;
            }

            $nameA = trim((string)($a['nombre'] ?? '') . ' ' . (string)($a['apellido'] ?? ''));
            $nameB = trim((string)($b['nombre'] ?? '') . ' ' . (string)($b['apellido'] ?? ''));
            return strcasecmp($nameA, $nameB);
        });

        return array_values($rows);
    }

    public function load_usuarios($path) {
        $data = function_exists('auth_central_users_for_mantencion') ? auth_central_users_for_mantencion(false) : [];
        if (!is_array($data)) $data = [];
        foreach ($data as &$item) {
            $this->ensure_user_fields($item);
        }
        unset($item);
        return $this->usuarios_sort_for_project($data);
    }

    public function save_usuarios($path, $data) {
        if (!is_array($data)) {
            return;
        }
        foreach (array_values($data) as $row) {
            if (is_array($row)) {
                $this->central->usuarios_central_upsert($row);
            }
        }
    }

    public function usuarios_norm_identity(string $value): string {
        return strtolower((string)preg_replace('/[^0-9a-z]/i', '', $value));
    }

    public function usuarios_migrate_global_nextcloud_credentials(array &$rows): bool {
        $userId = function_exists('auth_get_user_id') ? (string)auth_get_user_id() : '';
        if ($userId === '') {
            return false;
        }
        $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
        $cfg = $repo !== null ? $repo->loadAll() : [];
        if (!is_array($cfg)) {
            return false;
        }
        $globalUser = trim((string)($cfg['nextcloud_admin_user'] ?? ''));
        $globalPassEnc = trim((string)($cfg['nextcloud_admin_pass_enc'] ?? ''));
        if ($globalUser === '' || $globalPassEnc === '') {
            return false;
        }
        $changed = false;
        foreach ($rows as &$row) {
            if (!is_array($row) || (string)($row['id'] ?? '') !== $userId) {
                continue;
            }
            if (trim((string)($row['nextcloud_user'] ?? '')) === '' && trim((string)($row['nextcloud_pass_enc'] ?? '')) === '') {
                $row['nextcloud_user'] = $globalUser;
                $row['nextcloud_pass_enc'] = $globalPassEnc;
                unset($row['nextcloud_pass']);
                $changed = true;
            }
            break;
        }
        unset($row);
        $cfg['nextcloud_admin_user'] = '';
        $cfg['nextcloud_admin_pass_enc'] = '';
        if ($repo !== null) {
            $repo->saveAll($cfg);
        }
        return $changed;
    }

    public function find_user_index(array $rows, string $id): ?int {
        foreach ($rows as $idx => $row) {
            if ((string)($row['id'] ?? '') === (string)$id) return $idx;
        }
        return null;
    }

    public function has_duplicate_id(array $rows, string $id): bool {
        foreach ($rows as $row) {
            if ((string)($row['id'] ?? '') === (string)$id) return true;
        }
        return false;
    }

    public function has_duplicate_rut(array $rows, string $rutBase, string $excludeId = ''): bool {
        if ($rutBase === '') return false;
        foreach ($rows as $row) {
            if ($excludeId !== '' && (string)($row['id'] ?? '') === (string)$excludeId) {
                continue;
            }
            $rutExist = preg_replace('/[^0-9kK]/', '', $row['rut'] ?? '');
            if ($this->rut_base($rutExist) === $rutBase) {
                return true;
            }
        }
        return false;
    }

    public function sanitize_input(string $value): string {
        return trim(filter_var($value, FILTER_UNSAFE_RAW) ?? '');
    }

    public function format_rut_value(string $rut): string {
        $clean = preg_replace('/[^0-9kK]/', '', $rut ?? '');
        if ($clean === '') return '';
        $clean = strtoupper($clean);
        if (strlen($clean) < 2) return $clean;
        $body = substr($clean, 0, -1);
        $dv = substr($clean, -1);
        $body = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $body);
        return $body . '-' . $dv;
    }

    public function usuarios_consume_flash(): ?string {
        return session()->pull('mantencion_usuarios_flash');
    }

    public function usuarios_redirect_back(): void {
        $location = $_SERVER['REQUEST_URI'] ?? '/redmine-mantencion/views/Usuarios/usuarios.php';
        header('Location: ' . $location);
        exit;
    }
}
