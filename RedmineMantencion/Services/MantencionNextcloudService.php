<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\Nova\Services\NovaUserService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Throwable;
use ZipArchive;

class MantencionNextcloudService
{
    public function __construct(private readonly NovaUserService $novaUsers)
    {
    }

    public function handle_nextcloud(): array|RedirectResponse {
        $flash = $this->nextcloud_consume_flash();
        $lastImport = $this->nextcloud_consume_last_import();
        $preview = $this->nextcloud_consume_preview();
        $request = request();
        if ($request->isMethod('post')) {
            if (function_exists('csrf_validate')) csrf_validate();
            if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
            $action = (string)$request->input('action', '');
            if ($action === 'save_nextcloud_config') {
                $this->nextcloud_save_config($request->all());
                $this->nextcloud_set_flash('Configuración de Nextcloud guardada');
                return $this->nextcloud_redirect_back('nextcloud');
            }
            if ($action === 'fetch_nextcloud_groups') {
                $res = $this->nextcloud_fetch_groups('');
                if (isset($res['error'])) return [$res['error'], $this->nextcloud_config(), $this->nextcloud_cached_groups()];
                $this->nextcloud_save_cached_groups($res['groups'] ?? []);
                $this->nextcloud_set_flash('Grupos consultados: ' . count($res['groups'] ?? []));
                return $this->nextcloud_redirect_back('nextcloud');
            }
            if ($action === 'clear_nextcloud_groups') {
                $this->nextcloud_save_cached_groups([]);
                $this->nextcloud_set_flash('Grupos guardados eliminados.');
                return $this->nextcloud_redirect_back('nextcloud');
            }
            if ($action === 'import_nextcloud_users') {
                $requesterResult = $this->nextcloud_requester_from_input($request->all());
                if (isset($requesterResult['error'])) {
                    return [$requesterResult['error'], $this->nextcloud_config(), $this->nextcloud_cached_groups(), $lastImport, $preview];
                }
                $uploadedFile = $request->file('nextcloud_file');
                $file = $uploadedFile !== null ? [
                    'error' => $uploadedFile->getError(),
                    'name' => $uploadedFile->getClientOriginalName(),
                    'tmp_name' => $uploadedFile->getPathname(),
                ] : [];
                $res = $this->nextcloud_prepare_users($file, $request->all());
                if (isset($res['error'])) return [$res['error'], $this->nextcloud_config(), $this->nextcloud_cached_groups(), $lastImport, $preview];
                $res['requester'] = $requesterResult['requester'];
                $this->nextcloud_set_preview($res);
                return $this->nextcloud_redirect_back();
            }
            if ($action === 'confirm_nextcloud_import') {
                $selectedRows = $this->nextcloud_selected_rows(
                    (array)$request->input('users', []),
                    (array)$request->input('selected_users', [])
                );
                $users = $this->nextcloud_users_from_post($selectedRows);
                if (!$users) return ['Selecciona al menos un usuario para crear.', $this->nextcloud_config(), $this->nextcloud_cached_groups(), $lastImport, $preview];
                $requesterResult = $this->nextcloud_requester_from_input((array)($preview['requester'] ?? []));
                if (isset($requesterResult['error'])) {
                    return [$requesterResult['error'], $this->nextcloud_config(), $this->nextcloud_cached_groups(), $lastImport, $preview];
                }
                $runtimeUser = trim((string)$request->input('nextcloud_runtime_user', ''));
                $runtimePass = trim((string)$request->input('nextcloud_runtime_pass', ''));
                if ($runtimeUser === '' || $runtimePass === '') {
                    $savedCredentials = nextcloud_credentials_for_user(function_exists('auth_get_user_id') ? (string)auth_get_user_id() : '');
                    if ($runtimeUser === '') {
                        $runtimeUser = trim((string)($savedCredentials['user'] ?? ''));
                    }
                    if ($runtimePass === '') {
                        $runtimePass = trim((string)($savedCredentials['pass'] ?? ''));
                    }
                }
                $res = $this->nextcloud_import_prepared_users($users, [
                    'user' => $runtimeUser,
                    'pass' => $runtimePass,
                ], $requesterResult['requester']);
                if (isset($res['error'])) return [$res['error'], $this->nextcloud_config(), $this->nextcloud_cached_groups(), $lastImport, $preview];
                $msg = 'Importación Nextcloud completada. Creados: ' . (int)($res['created'] ?? 0) . ' | existentes: ' . (int)($res['exists'] ?? 0);
                $failed = $res['failed'] ?? [];
                if (is_array($failed) && $failed) {
                    $msg .= ' | errores: ' . count($failed) . ' (' . implode(' / ', array_slice($failed, 0, 3)) . ')';
                }
                $this->nextcloud_set_last_import($res);
                $this->nextcloud_set_flash($msg);
                $this->nextcloud_clear_preview();
                return $this->nextcloud_redirect_back();
            }
        }
        return [$flash, $this->nextcloud_config(), $this->nextcloud_cached_groups(), $lastImport, $preview];
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // Personal Nextcloud file-browser helpers (per-user credentials)
    // ──────────────────────────────────────────────────────────────────────────────

    /**
     * Thin wrappers delegating to NextcloudWebdavClient — see the note above
     * nextcloud_webdav_request(). Same signatures, same return shapes.
     */

    public function nextcloud_best_group_match(string $raw, array $candidates): string {
        $bestGroup = '';
        $bestScore = 0;
        foreach ($candidates as $group) {
            $group = (string)$group;
            $score = $this->nextcloud_group_match_score($raw, $group);
            if ($score > $bestScore || ($score === $bestScore && $score > 0 && strlen($group) < strlen($bestGroup))) {
                $bestScore = $score;
                $bestGroup = $group;
            }
        }
        return $bestScore >= 68 ? $bestGroup : '';
    }

    public function nextcloud_cached_groups(): array {
        $cfg = nextcloud_config_load();
        $groups = $cfg['nextcloud_cached_groups'] ?? [];
        return is_array($groups) ? array_values(array_filter(array_map('strval', $groups))) : [];
    }

    public function nextcloud_column_value(array $row, array $names): string {
        foreach ($names as $name) {
            $key = $this->nextcloud_header_key($name);
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim((string)$row[$key]);
            }
        }
        return '';
    }

    public function nextcloud_config(): array {
        $cfg = nextcloud_config_load();
        $userId = function_exists('auth_get_user_id') ? (string)auth_get_user_id() : '';
        $globalUser = trim((string)($cfg['nextcloud_admin_user'] ?? ''));
        $globalPassRaw = trim((string)($cfg['nextcloud_admin_pass_enc'] ?? ''));
        $globalPass = $globalPassRaw !== '' ? (\App\Modulos\Nova\Support\SecretValue::decryptSecret($globalPassRaw) ?? '') : '';
        if ($userId !== '' && $globalUser !== '' && $globalPass !== '' && !nextcloud_credentials_has_saved($userId)) {
            // Only clear the legacy global field once the per-user row is confirmed saved —
            // previously this cleared unconditionally, which could silently drop the
            // password if the per-user save failed.
            if (nextcloud_credentials_save_for_user($userId, $globalUser, $globalPass)) {
                $cfg['nextcloud_admin_user'] = '';
                $cfg['nextcloud_admin_pass_enc'] = '';
                $this->nextcloud_config_save($cfg);
            }
        }
        $savedUserCredentials = nextcloud_credentials_for_user($userId);
        $adminUser = trim((string)($savedUserCredentials['user'] ?? ''));
        $adminPass = trim((string)($savedUserCredentials['pass'] ?? ''));
        return [
            'url' => trim((string)($cfg['nextcloud_url'] ?? 'https://www.coresalud.cl/nextcloud')),
            'admin_user' => $adminUser,
            'admin_pass' => $adminPass,
            'default_group' => trim((string)($cfg['nextcloud_default_group'] ?? '')),
            'default_quota' => trim((string)($cfg['nextcloud_default_quota'] ?? '')),
            'default_language' => trim((string)($cfg['nextcloud_default_language'] ?? 'es')),
            'has_password' => nextcloud_credentials_has_saved($userId),
            'has_global_password' => false,
        ];
    }

    public function nextcloud_config_save(array $cfg): bool {
        $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
        if ($repo !== null) {
            $repo->saveAll($cfg);
            return true;
        }
        return false;
    }

    public function nextcloud_consume_flash(): ?string {
        return session()->pull('mantencion_nextcloud_flash');
    }

    public function nextcloud_consume_last_import(): array {
        $result = session()->pull('mantencion_nextcloud_last_import', []);
        return is_array($result) ? $result : [];
    }

    public function nextcloud_consume_preview(): array {
        // La previsualización debe sobrevivir al GET para poder revalidar la
        // selección en el POST de confirmación y volver a mostrarse si falla.
        $preview = session()->get('mantencion_nextcloud_preview', []);
        return is_array($preview) ? $preview : [];
    }

    public function nextcloud_clear_preview(): void {
        session()->forget('mantencion_nextcloud_preview');
    }

    public function nextcloud_created_history_load(): array {
        if (!$this->nextcloud_history_table_ready()) {
            return [];
        }
        try {
            $batches = \Illuminate\Support\Facades\DB::table('redmine_mantencion_nextcloud_historial_lotes')
                ->orderByDesc('created_at_cl')
                ->get();

            $out = [];
            foreach ($batches as $batch) {
                $users = \Illuminate\Support\Facades\DB::table('redmine_mantencion_nextcloud_historial_usuarios')
                    ->where('lote_id', $batch->id)
                    ->orderBy('id')
                    ->get();
                $entry = [
                    'id' => (string)$batch->legacy_id,
                    'created_at' => (new DateTimeImmutable((string)$batch->created_at_cl))->format(DateTimeInterface::ATOM),
                    'solicitante' => (string)($batch->solicitante ?? ''),
                    'solicitante_nombre' => (string)($batch->solicitante_nombre ?? ''),
                    'solicitante_rut' => (string)($batch->solicitante_rut ?? ''),
                    'solicitante_correo' => (string)($batch->solicitante_correo ?? ''),
                    'users' => [],
                    'created_users' => [],
                    'existing_users' => [],
                    'failed_users' => [],
                    'result_users' => [],
                ];
                foreach ($users as $user) {
                    $snapshot = [
                        'userid' => (string)($user->userid ?? ''),
                        'displayName' => (string)($user->display_name ?? ''),
                        'email' => (string)($user->email ?? ''),
                        'group' => (string)($user->grupo ?? ''),
                        'status' => (string)($user->status ?? ''),
                        'message' => (string)($user->message ?? ''),
                    ];
                    $type = (string)$user->tipo;
                    if (isset($entry[$type]) && is_array($entry[$type])) {
                        $entry[$type][] = $snapshot;
                    }
                    if (in_array($type, ['created_users', 'result_users'], true)) {
                        $entry['users'][] = $snapshot;
                    }
                }
                $out[] = $entry;
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    public function nextcloud_created_history_save_batch(array $createdUsers, array $existingUsers = [], array $failedUsers = [], array $resultUsers = [], array $requester = []): ?array {
        if (!$createdUsers && !$existingUsers && !$failedUsers && !$resultUsers) return null;
        $batch = [
            'id' => bin2hex(random_bytes(6)),
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('c'),
            'users' => array_values($createdUsers),
            'created_users' => array_values($createdUsers),
            'existing_users' => array_values($existingUsers),
            'failed_users' => array_values($failedUsers),
            'result_users' => array_values($resultUsers),
            'solicitante' => (string)($requester['solicitante'] ?? ''),
            'solicitante_nombre' => (string)($requester['solicitante_nombre'] ?? ''),
            'solicitante_rut' => (string)($requester['solicitante_rut'] ?? ''),
            'solicitante_correo' => (string)($requester['solicitante_correo'] ?? ''),
        ];
        if (!$this->nextcloud_history_table_ready()) {
            return null;
        }
        try {
            $moduleId = $this->nextcloud_history_module_id();
            \Illuminate\Support\Facades\DB::transaction(static function () use ($batch, $moduleId): void {
                $batchId = \Illuminate\Support\Facades\DB::table('redmine_mantencion_nextcloud_historial_lotes')->insertGetId([
                    'modulo_id' => $moduleId,
                    'legacy_id' => $batch['id'],
                    'solicitante' => $batch['solicitante'],
                    'solicitante_nombre' => $batch['solicitante_nombre'],
                    'solicitante_rut' => $batch['solicitante_rut'],
                    'solicitante_correo' => $batch['solicitante_correo'],
                    'created_at_cl' => date('Y-m-d H:i:s', strtotime((string)$batch['created_at'])),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach (['created_users', 'existing_users', 'failed_users', 'result_users'] as $type) {
                    foreach (($batch[$type] ?? []) as $user) {
                        if (!is_array($user)) {
                            continue;
                        }
                        \Illuminate\Support\Facades\DB::table('redmine_mantencion_nextcloud_historial_usuarios')->insert([
                            'lote_id' => $batchId,
                            'tipo' => $type,
                            'userid' => (string)($user['userid'] ?? ''),
                            'display_name' => (string)($user['displayName'] ?? ''),
                            'email' => (string)($user['email'] ?? ''),
                            'grupo' => (string)($user['group'] ?? ''),
                            'status' => (string)($user['status'] ?? ''),
                            'message' => (string)($user['message'] ?? ''),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
        } catch (Throwable) {
            return null;
        }
        return $batch;
    }

    public function nextcloud_fetch_groups(string $search = ''): array {
        $cfg = $this->nextcloud_config();
        if ($cfg['url'] === '' || $cfg['admin_user'] === '' || $cfg['admin_pass'] === '') {
            return ['error' => 'Configura URL, usuario administrador y contraseña de aplicación de Nextcloud.'];
        }
        $query = '?limit=500';
        if ($search !== '') {
            $query .= '&search=' . rawurlencode($search);
        }
        $res = nextcloud_request($cfg, 'GET', '/groups' . $query, [], 30);
        if (!$res['ok']) {
            return ['error' => (($res['message'] ?? '') ?: 'HTTP ' . ($res['http'] ?? 0))];
        }
        return ['groups' => $this->nextcloud_groups_from_response($res['data'] ?? [])];
    }

    public function nextcloud_generate_password(string $displayName, string $userid): string {
        $parts = preg_split('/\s+/', trim($displayName)) ?: [];
        $name = $parts[0] ?? 'Usuario';
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($converted) && $converted !== '') $name = $converted;
        }
        $name = preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'Usuario';
        $rut = strtoupper(str_replace('.', '', trim($userid)));
        if (str_contains($rut, '-')) {
            [$body, $digit] = array_pad(explode('-', $rut, 2), 2, '');
        } else {
            $body = substr($rut, 0, -1);
            $digit = substr($rut, -1);
        }
        $body = preg_replace('/\D+/', '', $body) ?: '';
        $digit = preg_replace('/[^0-9K]/', '', $digit) ?: '';
        if (strlen($body) < 4 || $digit === '') {
            $rawDigits = preg_replace('/\D+/', '', $userid) ?: '0000';
            $body = str_pad($rawDigits, 4, '0');
            $digit = substr($rawDigits, -1) ?: '0';
        }
        return substr($body, 0, 4) . ucfirst(strtolower($name)) . strtoupper($digit) . '!' . date('y');
    }

    public function nextcloud_group_match_score(string $needleRaw, string $groupRaw): int {
        $needle = $this->nextcloud_match_key($needleRaw);
        $group = $this->nextcloud_match_key($groupRaw);
        if ($needle === '' || $group === '') return 0;
        if ($needle === $group) return 100;

        $needleTokens = $this->nextcloud_match_tokens($needleRaw);
        $groupTokens = $this->nextcloud_match_tokens($groupRaw);
        $needleTokenKey = implode('', $needleTokens);
        $groupTokenKey = implode('', $groupTokens);
        if ($needleTokenKey !== '' && $needleTokenKey === $groupTokenKey) return 98;
        if ($needleTokenKey !== '' && $groupTokenKey !== '' && str_contains($groupTokenKey, $needleTokenKey)) return 90;
        if ($needleTokenKey !== '' && $groupTokenKey !== '' && str_contains($needleTokenKey, $groupTokenKey)) return 86;

        if ($needleTokens && $groupTokens) {
            $intersect = array_intersect($needleTokens, $groupTokens);
            $partialMatches = [];
            foreach ($needleTokens as $needleToken) {
                foreach ($groupTokens as $groupToken) {
                    if (strlen($needleToken) >= 2 && (str_starts_with($groupToken, $needleToken) || str_contains($groupToken, $needleToken))) {
                        $partialMatches[] = $needleToken;
                        break;
                    }
                }
            }
            $needleCoverage = count($intersect) / max(1, count($needleTokens));
            $groupCoverage = count($intersect) / max(1, count($groupTokens));
            $partialCoverage = count(array_unique($partialMatches)) / max(1, count($needleTokens));
            if ($needleCoverage === 1.0 && $groupCoverage === 1.0) return 96;
            if ($needleCoverage === 1.0) return 82;
            if ($partialCoverage === 1.0) return 80;
            if ($partialCoverage >= 0.5 && count($partialMatches) >= 2) return 72;
            if ($groupCoverage === 1.0 && count($intersect) >= 2) return 78;
            if (count($intersect) >= 2) return 68;
        }

        if (str_contains($group, $needle)) return 74;
        if (str_contains($needle, $group)) return 70;

        similar_text($needleTokenKey ?: $needle, $groupTokenKey ?: $group, $percent);
        return $percent >= 84 ? (int)round($percent * 0.65) : 0;
    }

    public function nextcloud_group_suggestions(string $raw, array $candidates, int $limit = 3): array {
        $items = [];
        foreach ($candidates as $group) {
            $group = (string)$group;
            $score = $this->nextcloud_group_match_score($raw, $group);
            if ($score > 0) {
                $items[] = ['group' => $group, 'score' => $score];
            }
        }
        usort($items, static function ($a, $b) {
            return ($b['score'] <=> $a['score']) ?: (strlen($a['group']) <=> strlen($b['group'])) ?: strnatcasecmp($a['group'], $b['group']);
        });
        return array_values(array_map(static fn($item) => $item['group'], array_slice($items, 0, $limit)));
    }

    public function nextcloud_groups_from_response($data): array {
        $groups = [];
        if (is_array($data['groups'] ?? null)) {
            $source = $data['groups'];
        } elseif (is_array($data)) {
            $source = $data;
        } else {
            $source = [];
        }
        foreach ($source as $key => $value) {
            if (is_string($value)) {
                $groups[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $nested) {
                    if (is_string($nested)) $groups[] = $nested;
                }
            } elseif (is_string($key) && $key !== 'groups') {
                $groups[] = $key;
            }
        }
        $groups = array_values(array_unique(array_filter(array_map('trim', $groups))));
        natcasesort($groups);
        return array_values($groups);
    }

    public function nextcloud_header_key(string $value): string {
        $value = strtolower(trim($value));
        $value = str_replace(['á','é','í','ó','ú','ñ','ü',' '], ['a','e','i','o','u','n','u','_'], $value);
        return preg_replace('/[^a-z0-9_]+/', '', $value) ?? '';
    }

    public function nextcloud_history_module_id(): ?int {
        try {
            $id = \Illuminate\Support\Facades\DB::table('modulos_nova')
                ->where('clave_modulo', 'redmine-mantencion')
                ->value('id');
            return $id !== null ? (int)$id : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function nextcloud_history_table_ready(): bool {
        return class_exists(\Illuminate\Support\Facades\Schema::class)
            && \Illuminate\Support\Facades\Schema::hasTable('redmine_mantencion_nextcloud_historial_lotes')
            && \Illuminate\Support\Facades\Schema::hasTable('redmine_mantencion_nextcloud_historial_usuarios');
    }

    public function nextcloud_import_file(array $file, array $options = []): array {
        $prepared = $this->nextcloud_prepare_users($file, $options);
        if (isset($prepared['error'])) return $prepared;
        return $this->nextcloud_import_prepared_users($prepared['users'] ?? []);
    }

    public function nextcloud_import_prepared_users(array $users, array $runtimeCredentials = [], array $requester = []): array {
        $cfg = $this->nextcloud_config();
        $runtimeUser = trim((string)($runtimeCredentials['user'] ?? ''));
        $runtimePass = trim((string)($runtimeCredentials['pass'] ?? ''));
        if ($runtimeUser !== '') {
            $cfg['admin_user'] = $runtimeUser;
        }
        if ($runtimePass !== '') {
            $cfg['admin_pass'] = $runtimePass;
        }
        if ($cfg['url'] === '' || $cfg['admin_user'] === '' || $cfg['admin_pass'] === '') {
            nextcloud_log_action('NEXTCLOUD_USERS_IMPORT_FAIL', 'Intento de crear usuarios Nextcloud sin credenciales completas');
            return ['error' => 'Configura URL, usuario administrador y contraseña de aplicación de Nextcloud.'];
        }
        $created = 0;
        $exists = 0;
        $failed = [];
        $failedUsers = [];
        $existingUsers = [];
        $createdUsers = [];
        $resultUsers = [];
        $seenUsers = [];
        foreach ($users as $user) {
            $user['userid'] = $this->nextcloud_normalize_userid((string)($user['userid'] ?? ''));
            if (($user['userid'] ?? '') === '') {
                $message = 'RUT inválido o vacío';
                $failed[] = 'usuario: ' . $message;
                $failedUser = $this->nextcloud_user_result_snapshot($user, 'failed', $message);
                $failedUsers[] = $failedUser;
                $resultUsers[] = $failedUser;
                continue;
            }
            if (isset($seenUsers[(string)$user['userid']])) {
                $message = 'RUT duplicado en la planilla';
                $failed[] = ($user['userid'] ?? 'usuario') . ': ' . $message;
                $failedUser = $this->nextcloud_user_result_snapshot($user, 'failed', $message);
                $failedUsers[] = $failedUser;
                $resultUsers[] = $failedUser;
                continue;
            }
            $seenUsers[(string)$user['userid']] = true;
            if (filter_var((string)($user['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
                $message = 'correo inválido';
                $failed[] = ($user['userid'] ?? 'usuario') . ': ' . $message;
                $failedUser = $this->nextcloud_user_result_snapshot($user, 'failed', $message);
                $failedUsers[] = $failedUser;
                $resultUsers[] = $failedUser;
                continue;
            }
            if (empty($user['groups'])) {
                $message = 'sin grupo válido';
                $failed[] = ($user['userid'] ?? 'usuario') . ': ' . $message;
                $failedUser = $this->nextcloud_user_result_snapshot($user, 'failed', $message);
                $failedUsers[] = $failedUser;
                $resultUsers[] = $failedUser;
                continue;
            }
            $existsCheck = $this->nextcloud_user_exists($cfg, (string)$user['userid']);
            if (($existsCheck['exists'] ?? false) === true) {
                $exists++;
                $existingUser = $this->nextcloud_user_result_snapshot($user, 'existing', 'No se creó porque ya existe en Nextcloud.');
                $existingUsers[] = $existingUser;
                $resultUsers[] = $existingUser;
                continue;
            }
            if (array_key_exists('error', $existsCheck)) {
                $message = 'no se pudo validar existencia: ' . (string)$existsCheck['error'];
                $failed[] = ($user['userid'] ?? 'usuario') . ': ' . $message;
                $failedUser = $this->nextcloud_user_result_snapshot($user, 'failed', $message);
                $failedUsers[] = $failedUser;
                $resultUsers[] = $failedUser;
                continue;
            }
            $res = nextcloud_request($cfg, 'POST', '/users', [
                'userid' => $user['userid'],
                'password' => $user['password'],
                'displayName' => $user['displayName'],
                'email' => $user['email'],
                'groups' => $user['groups'],
                'quota' => $user['quota'],
                'language' => $user['language'],
            ], 30);
            $verification = !empty($res['timeout'])
                ? $this->nextcloud_user_exists($cfg, (string)$user['userid'], 30)
                : [];
            $outcome = $this->nextcloud_classify_creation_response($res, $verification);
            if ($outcome['status'] === 'created') {
                $created++;
                $createdUser = $this->nextcloud_user_result_snapshot($user, 'created', $outcome['message']);
                $createdUsers[] = $createdUser;
                $resultUsers[] = $createdUser;
            } elseif ($outcome['status'] === 'existing') {
                $exists++;
                $existingUser = $this->nextcloud_user_result_snapshot($user, 'existing', $outcome['message']);
                $existingUsers[] = $existingUser;
                $resultUsers[] = $existingUser;
            } else {
                $message = $outcome['message'];
                $failed[] = $user['userid'] . ': ' . $message;
                $failedUser = $this->nextcloud_user_result_snapshot($user, 'failed', $message);
                $failedUsers[] = $failedUser;
                $resultUsers[] = $failedUser;
            }
        }
        $batch = $this->nextcloud_created_history_save_batch($createdUsers, $existingUsers, $failedUsers, $resultUsers, $requester);
        nextcloud_log_action(
            'NEXTCLOUD_USERS_IMPORT',
            'Creacion/importacion de usuarios Nextcloud | total ' . count($users)
                . ' | creados ' . $created
                . ' | existentes ' . $exists
                . ' | fallidos ' . count($failedUsers)
                . (($batch && !empty($batch['id'])) ? ' | lote ' . (string)$batch['id'] : '')
        );
        return [
            'ok' => true,
            'created' => $created,
            'exists' => $exists,
            'created_users' => $createdUsers,
            'created_batch' => $batch,
            'existing_users' => $existingUsers,
            'failed_users' => $failedUsers,
            'result_users' => $resultUsers,
            'failed' => $failed,
            'total' => count($users),
        ];
    }

    public function nextcloud_match_groups(string $raw, array $candidates, string $defaultGroup = ''): array {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', $raw) ?: [])));
        $matched = [];
        foreach ($parts as $part) {
            $match = $this->nextcloud_best_group_match($part, $candidates);
            if ($match !== '') $matched[] = $match;
        }
        $matched = array_values(array_unique($matched));
        if (!$matched && $defaultGroup !== '') $matched[] = $defaultGroup;
        return $matched;
    }

    public function nextcloud_match_key(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') $value = $converted;
        }
        $value = strtolower($value);
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    public function nextcloud_match_tokens(string $value): array {
        $value = trim($value);
        if ($value === '') return [];
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') $value = $converted;
        }
        $value = strtolower($value);
        $tokens = preg_split('/[^a-z0-9]+/', $value) ?: [];
        $stopwords = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'a', 'al', 'en', 'por', 'para'];
        $tokens = array_values(array_filter($tokens, static function ($token) use ($stopwords) {
            return $token !== '' && !in_array($token, $stopwords, true);
        }));
        return array_values(array_unique($tokens));
    }

    public function nextcloud_normalize_row(array $row, array $defaults): ?array {
        $rawUserid = $this->nextcloud_column_value($row, ['userid', 'usuario', 'nombre_de_usuario', 'user', 'id', 'rut']);
        $userid = $this->nextcloud_normalize_userid($rawUserid);
        $display = $this->nextcloud_column_value($row, ['displayName', 'displayname', 'nombre_a_desplegar', 'nombre', 'nombre_completo', 'name']);
        $email = $this->nextcloud_column_value($row, ['email', 'correo_electronico', 'correo', 'mail']);
        $password = $this->nextcloud_column_value($row, ['password', 'contrasena', 'contraseña', 'clave']);
        $groupsRaw = $this->nextcloud_column_value($row, ['servicio', 'nombre_del_servicio', 'service', 'groups', 'grupos', 'grupo']);
        $language = $this->nextcloud_column_value($row, ['language', 'idioma']);
        if ($userid === '') {
            return null;
        }
        $lowerUser = strtolower($rawUserid);
        if (str_contains($lowerUser, 'rut sin') || str_contains($lowerUser, 'ej:')) {
            return null;
        }
        $groups = [];
        if ($groupsRaw !== '') {
            $groups = array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', $groupsRaw) ?: [])));
        } elseif (($defaults['default_group'] ?? '') !== '') {
            $groups = [$defaults['default_group']];
        }
        return [
            'userid' => $userid,
            'raw_userid' => $rawUserid,
            'userid_normalized' => $userid !== $rawUserid,
            'password' => $password,
            'displayName' => $display !== '' ? $display : $userid,
            'email' => $email,
            'groups' => $groups,
            'group_source' => $groupsRaw,
            'quota' => (string)($defaults['default_quota'] ?? ''),
            'language' => $language !== '' ? $language : (string)($defaults['default_language'] ?? 'es'),
        ];
    }

    public function nextcloud_normalize_userid(string $userid): string {
        $userid = strtoupper(trim($userid));
        if ($userid === '') return '';
        $userid = str_replace(['.', ' '], '', $userid);
        if (str_contains($userid, '-')) {
            [$body] = array_pad(explode('-', $userid, 2), 2, '');
            return preg_replace('/\D+/', '', $body) ?: '';
        }
        if (preg_match('/^\d{7,8}[0-9K]$/', $userid) && strlen($userid) >= 9) {
            return substr($userid, 0, -1);
        }
        return preg_replace('/\D+/', '', $userid) ?: '';
    }

    public function nextcloud_parse_csv(string $path, array $defaults): array {
        $fh = fopen($path, 'rb');
        if (!$fh) return ['error' => 'No se pudo leer el archivo.'];
        $header = null;
        $rows = [];
        while (($line = fgetcsv($fh, 0, ';')) !== false) {
            if (count($line) <= 1) {
                $line = str_getcsv((string)implode('', $line), ',');
            }
            $line = $this->nextcloud_to_utf8($line);
            if ($header === null) {
                $header = array_map([$this, 'nextcloud_header_key'], $line);
                continue;
            }
            if (!$header || count(array_filter($line, fn($v) => trim((string)$v) !== '')) === 0) {
                continue;
            }
            $assoc = [];
            foreach ($header as $idx => $key) {
                if ($key !== '') $assoc[$key] = $line[$idx] ?? '';
            }
            $normalized = $this->nextcloud_normalize_row($assoc, $defaults);
            if ($normalized) $rows[] = $normalized;
        }
        fclose($fh);
        return ['rows' => $rows];
    }

    public function nextcloud_parse_xlsx(string $path, array $defaults): array {
        if (!class_exists('ZipArchive')) {
            return ['error' => 'Para leer XLSX debes habilitar la extensión ZIP de PHP. Mientras tanto exporta el Excel como CSV.'];
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return ['error' => 'No se pudo abrir el archivo XLSX.'];
        }
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        $shared = $sharedXml !== false ? $this->nextcloud_xlsx_shared_strings($sharedXml) : [];
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) return ['error' => 'El XLSX no contiene una hoja principal válida.'];
        preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $sheet, $matches);
        $matrix = [];
        foreach ($matches[1] ?? [] as $rowXml) {
            preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $rowXml, $cells, PREG_SET_ORDER);
            $row = [];
            foreach ($cells as $cell) {
                $attrs = $cell[1] ?? '';
                $body = $cell[2] ?? '';
                preg_match('/\br="([^"]+)"/', $attrs, $refMatch);
                $idx = $this->nextcloud_xlsx_col_index($refMatch[1] ?? 'A');
                preg_match('/<v>(.*?)<\/v>/s', $body, $valueMatch);
                $value = html_entity_decode($valueMatch[1] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (str_contains($attrs, ' t="s"')) {
                    $value = $shared[(int)$value] ?? '';
                } elseif (str_contains($attrs, ' t="inlineStr"')) {
                    preg_match('/<t\b[^>]*>(.*?)<\/t>/s', $body, $inlineMatch);
                    $value = html_entity_decode($inlineMatch[1] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                $row[$idx] = trim((string)$value);
            }
            if ($row) {
                ksort($row);
                $matrix[] = $row;
            }
        }
        if (!$matrix) return ['rows' => []];
        $header = array_map([$this, 'nextcloud_header_key'], array_values($matrix[0]));
        $rows = [];
        foreach (array_slice($matrix, 1) as $line) {
            $assoc = [];
            foreach ($header as $idx => $key) {
                if ($key !== '') $assoc[$key] = $line[$idx] ?? '';
            }
            $normalized = $this->nextcloud_normalize_row($assoc, $defaults);
            if ($normalized) $rows[] = $normalized;
        }
        return ['rows' => $rows];
    }

    public function nextcloud_prepare_users(array $file, array $options = []): array {
        $cfg = $this->nextcloud_config();
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['error' => 'Debes seleccionar un archivo CSV o XLSX.'];
        }
        $name = strtolower((string)($file['name'] ?? ''));
        $tmp = (string)($file['tmp_name'] ?? '');
        $parsed = str_ends_with($name, '.xlsx') ? $this->nextcloud_parse_xlsx($tmp, $cfg) : $this->nextcloud_parse_csv($tmp, $cfg);
        if (isset($parsed['error'])) return ['error' => $parsed['error']];
        $users = $parsed['rows'] ?? [];
        if (!$users) return ['error' => 'El archivo no contiene usuarios válidos.'];
        $cachedGroups = $this->nextcloud_cached_groups();
        $preparedUsers = [];
        $seenUsers = [];
        foreach ($users as $user) {
            $user['password'] = $this->nextcloud_generate_password((string)$user['displayName'], (string)$user['userid']);
            $user['groups'] = $this->nextcloud_match_groups((string)($user['group_source'] ?? ''), $cachedGroups, '');
            $user['group_match_found'] = !empty($user['groups']);
            $user['group_suggestions'] = empty($user['groups']) ? $this->nextcloud_group_suggestions((string)($user['group_source'] ?? ''), $cachedGroups, 3) : [];
            $user['email_valid'] = filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL) !== false;
            $userKey = (string)($user['userid'] ?? '');
            $user['duplicate_in_file'] = $userKey !== '' && isset($seenUsers[$userKey]);
            if ($userKey !== '') {
                $seenUsers[$userKey] = true;
            }
            $preparedUsers[] = $user;
        }
        return ['ok' => true, 'users' => $preparedUsers, 'total' => count($preparedUsers)];
    }

    public function nextcloud_redirect_back(string $panel = ''): RedirectResponse {
        $target = $panel !== ''
            ? request()->fullUrlWithQuery(['panel' => $panel])
            : request()->fullUrl();

        return redirect()->to($target, 303);
    }

    public function nextcloud_sanitize(string $value): string {
        return trim(filter_var($value, FILTER_UNSAFE_RAW) ?? '');
    }

    public function nextcloud_save_cached_groups(array $groups): void {
        $cfg = nextcloud_config_load();
        $cfg['nextcloud_cached_groups'] = array_values($groups);
        $cfg['nextcloud_cached_groups_at'] = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('c');
        $this->nextcloud_config_save($cfg);
    }

    public function nextcloud_save_config(array $post): bool {
        $cfg = nextcloud_config_load();
        $cfg['nextcloud_url'] = rtrim($this->nextcloud_sanitize($post['nextcloud_url'] ?? ''), '/');
        $cfg['nextcloud_default_group'] = $this->nextcloud_sanitize($post['nextcloud_default_group'] ?? '');
        $cfg['nextcloud_default_quota'] = $this->nextcloud_sanitize($post['nextcloud_default_quota'] ?? '');
        $cfg['nextcloud_default_language'] = $this->nextcloud_sanitize($post['nextcloud_default_language'] ?? 'es');
        $cfg['nextcloud_admin_user'] = '';
        $cfg['nextcloud_admin_pass_enc'] = '';
        return $this->nextcloud_config_save($cfg);
    }

    public function nextcloud_set_flash(string $message): void {
        session()->put('mantencion_nextcloud_flash', $message);
    }

    public function nextcloud_set_last_import(array $result): void {
        session()->put('mantencion_nextcloud_last_import', $result);
    }

    public function nextcloud_requester_from_input(array $input): array {
        $limit = static function ($value, int $length): string {
            $clean = trim((string)$value);
            $clean = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $clean);
            return function_exists('mb_substr') ? mb_substr($clean, 0, $length) : substr($clean, 0, $length);
        };
        $requesterName = $limit($input['solicitante_nombre'] ?? '', 200);
        if ($requesterName === '') {
            $requesterName = $limit($input['solicitante'] ?? '', 200);
        }
        $requester = [
            // Se conserva la clave legacy para leer vistas previas e historiales antiguos.
            'solicitante' => '',
            'solicitante_nombre' => $requesterName,
            'solicitante_rut' => $limit($input['solicitante_rut'] ?? '', 20),
            'solicitante_correo' => strtolower($limit($input['solicitante_correo'] ?? '', 190)),
        ];
        if ($requester['solicitante_nombre'] === '') {
            return ['error' => 'Debes ingresar el nombre del solicitante.'];
        }
        if (filter_var($requester['solicitante_correo'], FILTER_VALIDATE_EMAIL) === false) {
            return ['error' => 'Debes ingresar un correo válido para el solicitante.'];
        }
        if ($requester['solicitante_rut'] !== '') {
            if (! $this->novaUsers->isValidRut($requester['solicitante_rut'])) {
                return ['error' => 'El RUT del solicitante no es válido.'];
            }
            $requester['solicitante_rut'] = $this->novaUsers->canonicalRut($requester['solicitante_rut']);
        }

        return ['requester' => $requester];
    }

    public function nextcloud_set_preview(array $preview): void {
        session()->put('mantencion_nextcloud_preview', $preview);
    }

    public function nextcloud_to_utf8(array $row): array {
        return array_map(static function ($value) {
            $value = trim((string)$value);
            if ($value === '') return '';
            if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
                $enc = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
                if ($enc && $enc !== 'UTF-8') {
                    return mb_convert_encoding($value, 'UTF-8', $enc);
                }
            }
            return $value;
        }, $row);
    }

    public function nextcloud_user_exists(array $cfg, string $userid, int $timeoutSeconds = 30): array {
        $userid = trim($userid);
        if ($userid === '') {
            return ['exists' => false];
        }
        $res = nextcloud_request($cfg, 'GET', '/users/' . rawurlencode($userid), [], $timeoutSeconds);
        if (!empty($res['ok'])) {
            return ['exists' => true];
        }
        $http = (int)($res['http'] ?? 0);
        $statusCode = (int)($res['statuscode'] ?? 0);
        $message = strtolower((string)($res['message'] ?? ''));
        if ($http === 404 || $statusCode === 404 || str_contains($message, 'not exist') || str_contains($message, 'not found')) {
            return ['exists' => false];
        }
        return [
            'exists' => null,
            'error' => (($res['message'] ?? '') ?: 'HTTP ' . $http),
            'timeout' => !empty($res['timeout']),
        ];
    }

    public function nextcloud_classify_creation_response(array $response, array $verification = []): array {
        if (!empty($response['ok'])) {
            return ['status' => 'created', 'message' => 'Creado correctamente.'];
        }
        if ((int)($response['statuscode'] ?? 0) === 102) {
            return ['status' => 'existing', 'message' => 'No se creó porque ya existe en Nextcloud.'];
        }
        if (!empty($response['timeout'])) {
            if (($verification['exists'] ?? null) === true) {
                return [
                    'status' => 'created',
                    'message' => 'Creado correctamente. Nextcloud demoró en responder, pero la cuenta fue verificada.',
                ];
            }
            if (($verification['exists'] ?? null) === false) {
                return [
                    'status' => 'failed',
                    'message' => 'Nextcloud no confirmó la creación y el usuario no aparece registrado. Puedes intentar nuevamente.',
                ];
            }
            return [
                'status' => 'failed',
                'message' => 'Nextcloud no confirmó la creación y no fue posible verificar si la cuenta quedó registrada. Revisa el usuario antes de reintentar.',
            ];
        }

        return [
            'status' => 'failed',
            'message' => (string)(($response['message'] ?? '') ?: 'HTTP ' . ($response['http'] ?? 0)),
        ];
    }

    public function nextcloud_user_result_snapshot(array $user, string $status, string $message = ''): array {
        return [
            'userid' => (string)($user['userid'] ?? ''),
            'displayName' => (string)($user['displayName'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'group' => (string)(($user['groups'][0] ?? '')),
            'password' => (string)($user['password'] ?? ''),
            'status' => $status,
            'message' => $message,
        ];
    }

    public function nextcloud_users_from_post(array $rows): array {
        $allowedGroups = $this->nextcloud_cached_groups();
        $users = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $group = trim((string)($row['group'] ?? ''));
            $groups = ($group !== '' && in_array($group, $allowedGroups, true)) ? [$group] : [];
            $users[] = [
                'userid' => trim((string)($row['userid'] ?? '')),
                'password' => trim((string)($row['password'] ?? '')),
                'displayName' => trim((string)($row['displayName'] ?? '')),
                'email' => trim((string)($row['email'] ?? '')),
                'groups' => array_values(array_unique(array_filter($groups))),
                'quota' => trim((string)($row['quota'] ?? '')),
                'language' => trim((string)($row['language'] ?? 'es')),
            ];
        }
        return array_values(array_filter($users, fn($user) => $user['userid'] !== '' && $user['password'] !== ''));
    }

    public function nextcloud_selected_rows(array $rows, array $selectedIndexes): array {
        $selected = [];
        $seen = [];
        foreach ($selectedIndexes as $index) {
            $key = is_int($index) ? $index : trim((string)$index);
            $lookupKey = (string)$key;
            if ($key === '' || isset($seen[$lookupKey]) || !array_key_exists($key, $rows) || !is_array($rows[$key])) {
                continue;
            }
            $seen[$lookupKey] = true;
            $selected[] = $rows[$key];
        }
        return $selected;
    }

    public function nextcloud_xlsx_col_index(string $cellRef): int {
        preg_match('/^[A-Z]+/i', $cellRef, $m);
        $letters = strtoupper($m[0] ?? 'A');
        $idx = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $idx = ($idx * 26) + (ord($letters[$i]) - 64);
        }
        return max(0, $idx - 1);
    }

    public function nextcloud_xlsx_shared_strings(string $xml): array {
        preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $xml, $items);
        $strings = [];
        foreach ($items[1] ?? [] as $item) {
            preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $item, $texts);
            $strings[] = html_entity_decode(implode('', $texts[1] ?? []), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return $strings;
    }
}
