<?php

namespace RedmineTic\Support\Redmine;

use App\Models\NovaUser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class RedmineDataRepository
{
    private string $projectKey = 'redmine_tic';

    public function forProject(string $projectKey): self
    {
        if (array_key_exists($projectKey, config('modules', []))) {
            $this->projectKey = $projectKey;
        }

        return $this;
    }

    public function projectKey(): string
    {
        return $this->projectKey;
    }

    public function projectName(): string
    {
        return (string) data_get(config('modules.' . $this->projectKey, []), 'name', 'Redmine');
    }

    public function basePath(): string
    {
        return (string) data_get(config('modules.' . $this->projectKey, []), 'path', base_path($this->projectKey));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function activeReports(): array
    {
        $fileReports = $this->readList($this->dataPath('mensaje.json'));
        $databaseReports = $this->activeReportsFromDatabase();
        if ($databaseReports === []) {
            if ($fileReports !== []) {
                $this->saveActiveReportsToDatabase($fileReports);
            }

            return $fileReports;
        }

        $known = array_fill_keys(array_map(static fn (array $report): string => (string) ($report['id'] ?? ''), $databaseReports), true);
        foreach ($fileReports as $report) {
            $id = (string) ($report['id'] ?? '');
            if ($id !== '' && !isset($known[$id])) {
                $databaseReports[] = $report;
            }
        }

        return $databaseReports;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function saveActiveReports(array $reports): void
    {
        $this->saveActiveReportsToDatabase(array_values($reports));
        $this->writeJson($this->dataPath('mensaje.json'), array_values($reports));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function archivedReports(): array
    {
        $reports = [];
        $root = $this->dataPath('reportes');

        if (!is_dir($root)) {
            return [];
        }

        foreach (File::allFiles($root) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            foreach ($this->readList($file->getPathname()) as $report) {
                $reports[] = $report;
            }
        }

        return $reports;
    }

    /**
     * @return array<string,mixed>
     */
    public function configuration(): array
    {
        $config = json_decode((string) @file_get_contents($this->dataPath('configuracion.json')), true);

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string,mixed> $config
     */
    public function saveConfiguration(array $config): void
    {
        $this->writeJson($this->dataPath('configuracion.json'), $config);
    }

    public function maintenanceModeEnabled(): bool
    {
        return !empty($this->configuration()['maintenance_mode']);
    }

    /**
     * @return array<string,mixed>
     */
    public function dashboardSummary(): array
    {
        $active = $this->activeReports();
        $archived = $this->archivedReports();
        $config = $this->configuration();

        return [
            'active_total' => count($active),
            'pending' => $this->countByState($active, ['pendiente']),
            'processed' => $this->countByState($active, ['procesado', 'procesada']),
            'errors' => $this->countByState($active, ['error', 'fallido', 'fallida']),
            'archived_total' => count($archived),
            'project_name' => (string) ($config['project_name'] ?? 'Redmine'),
            'maintenance' => [
                'enabled' => !empty($config['maintenance_mode']),
                'until' => trim((string) ($config['maintenance_until'] ?? '')),
                'until_text' => $this->formatUntil(trim((string) ($config['maintenance_until'] ?? ''))),
            ],
            'recent' => array_slice(array_reverse($active), 0, 10),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     * @return array<string,int>
     */
    private function dashboardSummaryForReports(array $reports): array
    {
        return [
            'active_total' => count($reports),
            'pending' => $this->countByState($reports, ['pendiente']),
            'processed' => $this->countByState($reports, ['procesado', 'procesada']),
            'errors' => $this->countByState($reports, ['error', 'fallido', 'fallida']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function dashboardData(string $filter = 'todos', array $user = []): array
    {
        $this->archiveExpiredProcessedReports();

        $filter = $this->normalizeDashboardFilter($filter);
        $allReports = $this->activeReports();
        $reports = $this->filterReportsByUserScope($allReports, $user, 'mensajes');
        $visibleReports = $this->filterReportsByDashboardStatus($reports, $filter);
        $allSummary = $this->dashboardSummary();
        $visibleSummary = $this->dashboardSummaryForReports($reports);

        return [
            'summary' => array_merge($allSummary, $visibleSummary, [
                'filter' => $filter,
                'visible_total' => count($visibleReports),
                'scope_total' => count($reports),
                'hidden_by_scope' => max(0, count($allReports) - count($reports)),
                'total_pending' => $allSummary['pending'] ?? 0,
                'total_processed' => $allSummary['processed'] ?? 0,
                'total_errors' => $allSummary['errors'] ?? 0,
            ]),
            'reports' => $visibleReports,
            'dashboardFilter' => $filter,
        ];
    }

    /**
     * @param array<string,mixed> $user
     */
    public function canAccessActiveReport(string $id, array $user): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }

        foreach ($this->filterReportsByUserScope($this->activeReports(), $user, 'mensajes') as $report) {
            if ((string) ($report['id'] ?? '') === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $ids
     * @param array<string,mixed> $user
     * @return array<int,string>
     */
    public function filterAccessibleActiveReportIds(array $ids, array $user): array
    {
        if ($ids === []) {
            return [];
        }

        $allowed = [];
        foreach ($this->filterReportsByUserScope($this->activeReports(), $user, 'mensajes') as $report) {
            $reportId = (string) ($report['id'] ?? '');
            if ($reportId !== '') {
                $allowed[$reportId] = true;
            }
        }

        return array_values(array_filter($ids, static fn (string $id): bool => isset($allowed[$id])));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function users(): array
    {
        $users = $this->projectUsersFromNova();
        $legacyUsers = $this->hydrateUsersWithNovaTelegram($this->readList($this->dataPath('usuarios.json')));
        if ($legacyUsers !== []) {
            $knownIds = array_fill_keys(array_map(static fn (array $user): string => (string) ($user['id'] ?? ''), $users), true);
            $missingLegacyUsers = array_values(array_filter(
                $legacyUsers,
                static fn (array $user): bool => (string) ($user['id'] ?? '') !== '' && !isset($knownIds[(string) ($user['id'] ?? '')])
            ));
            if ($missingLegacyUsers !== []) {
                $this->saveProjectUsers(array_merge($users, $missingLegacyUsers));
                return $this->projectUsersFromNova();
            }
        }

        return $users;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function activeUsersWithPhone(): array
    {
        return array_values(array_filter($this->users(), static function (array $user): bool {
            $state = strtolower(trim((string) ($user['estado_usuario'] ?? $user['estado'] ?? 'activo')));
            $chatId = trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', '')));

            return $state === 'activo' && $chatId !== '';
        }));
    }

    /**
     * @return array{ok:bool,error:string,users:array<int,array<string,mixed>>}
     */
    public function saveUser(array $payload): array
    {
        $users = $this->users();
        $id = trim((string) ($payload['id'] ?? ''));
        $isExplicitCreate = filter_var($payload['_creating'] ?? false, FILTER_VALIDATE_BOOL);
        if ($id === '') {
            $id = trim((string) ($payload['rut_sin_dv'] ?? '')) ?: (string) Str::uuid();
        }
        if ($isExplicitCreate) {
            foreach ($users as $user) {
                if ((string) ($user['id'] ?? '') === $id) {
                    return [
                        'ok' => false,
                        'error' => 'El ID ya esta asociado a otro usuario.',
                        'users' => $users,
                    ];
                }
            }
        }
        $telegramChatId = trim((string) ($payload['telegram_chat_id'] ?? ''));
        if ($telegramChatId !== '') {
            foreach ($users as $user) {
                if ((string) ($user['id'] ?? '') === $id) {
                    continue;
                }
                $existingChatId = trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', '')));
                if ($existingChatId !== '' && $existingChatId === $telegramChatId) {
                    return [
                        'ok' => false,
                        'error' => 'El Chat ID Telegram ya esta asociado a otro usuario.',
                        'users' => $users,
                    ];
                }
            }
        }

        $row = [
            'id' => $id,
            'rut' => trim((string) ($payload['rut'] ?? '')),
            'rut_sin_dv' => trim((string) ($payload['rut_sin_dv'] ?? $id)),
            'nombre' => trim((string) ($payload['nombre'] ?? '')),
            'apellido' => trim((string) ($payload['apellido'] ?? '')),
            'numero_celular' => '',
            'telegram_chat_id' => $telegramChatId,
            'rol' => trim((string) ($payload['rol'] ?? 'usuario')) ?: 'usuario',
            'api' => trim((string) ($payload['api'] ?? '')),
            'estado_usuario' => (($payload['estado_usuario'] ?? 'activo') === 'baneado') ? 'baneado' : 'activo',
        ];

        $updated = false;
        foreach ($users as $index => $user) {
            if ((string) ($user['id'] ?? '') !== $id) {
                continue;
            }
            $users[$index] = array_merge($user, $row);
            $updated = true;
            break;
        }

        if (!$updated) {
            $users[] = $row;
        }

        $this->saveProjectUsers($users);

        return ['ok' => true, 'error' => '', 'users' => $users];
    }

    public function deleteUser(string $id): int
    {
        $users = $this->users();
        $changed = 0;

        foreach ($users as $index => $user) {
            if ((string) ($user['id'] ?? '') !== $id) {
                continue;
            }

            $users[$index]['estado_usuario'] = 'baneado';
            $changed = 1;
            break;
        }

        if ($changed === 1) {
            $this->saveProjectUsers($users);
        }

        return $changed;
    }

    /**
     * @param array<string,mixed> $permissions
     */
    public function saveUserPermissions(string $id, string $role, array $permissions): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }

        $users = $this->users();
        foreach ($users as $index => $user) {
            if ((string) ($user['id'] ?? '') !== $id) {
                continue;
            }

            if (trim($role) !== '') {
                $users[$index]['rol'] = trim($role);
            }
            $users[$index]['permisos'] = $permissions;
            $this->saveProjectUsers($users);

            return true;
        }

        return false;
    }

    /**
     * @return array{ok:bool,created:int,updated:int,error:string}
     */
    public function syncUsersFromRedmine(?string $userId = null): array
    {
        $config = $this->configuration();
        $token = $this->userApiToken($userId) ?: trim((string) ($config['platform_token'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'created' => 0, 'updated' => 0, 'error' => 'Token Redmine no configurado.'];
        }

        $projectId = trim((string) ($config['project_id'] ?? ''));
        if ($projectId === '') {
            return ['ok' => false, 'created' => 0, 'updated' => 0, 'error' => 'ID de proyecto no configurado.'];
        }

        $baseUrl = $this->redmineBaseUrl((string) ($config['platform_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'created' => 0, 'updated' => 0, 'error' => 'URL Redmine no configurada.'];
        }

        $memberships = [];
        $offset = 0;
        $limit = 100;
        do {
            $url = $baseUrl . '/projects/' . rawurlencode($projectId) . '/memberships.json?limit=' . $limit . '&offset=' . $offset;
            $response = $this->getRedmineJson($url, $token);
            if ($response['error'] !== '') {
                return ['ok' => false, 'created' => 0, 'updated' => 0, 'error' => $response['error']];
            }
            if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
                return ['ok' => false, 'created' => 0, 'updated' => 0, 'error' => 'HTTP ' . $response['http_code'] . ' - ' . $response['body']];
            }

            $data = json_decode($response['body'], true);
            $page = is_array($data['memberships'] ?? null) ? $data['memberships'] : [];
            $memberships = array_merge($memberships, $page);
            $total = (int) ($data['total_count'] ?? count($memberships));
            $offset += $limit;
        } while ($offset < $total);

        $users = $this->users();
        $byId = [];
        foreach ($users as $index => $user) {
            $id = (string) ($user['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $index;
            }
        }

        $created = 0;
        $updated = 0;
        foreach ($memberships as $membership) {
            if (!is_array($membership) || !is_array($membership['user'] ?? null)) {
                continue;
            }
            $redmineUser = $membership['user'];
            $id = trim((string) ($redmineUser['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            [$firstName, $lastName] = $this->splitRedmineName((string) ($redmineUser['name'] ?? ''));
            $row = [
                'id' => $id,
                'nombre' => $firstName,
                'apellido' => $lastName,
                'rol' => 'usuario',
                'estado_usuario' => 'activo',
                'redmine_membership_id' => $membership['id'] ?? null,
                'redmine_roles' => array_values(array_filter(array_map(static fn ($role): string => (string) ($role['name'] ?? ''), (array) ($membership['roles'] ?? [])))),
            ];

            if (isset($byId[$id])) {
                $index = $byId[$id];
                $users[$index] = array_merge($row, $users[$index], [
                    'id' => $id,
                    'nombre' => $users[$index]['nombre'] ?? $firstName,
                    'apellido' => $users[$index]['apellido'] ?? $lastName,
                    'redmine_membership_id' => $membership['id'] ?? ($users[$index]['redmine_membership_id'] ?? null),
                    'redmine_roles' => $row['redmine_roles'],
                ]);
                $updated++;
                continue;
            }

            $users[] = $row;
            $byId[$id] = count($users) - 1;
            $created++;
        }

        $this->saveProjectUsers($users);

        return ['ok' => true, 'created' => $created, 'updated' => $updated, 'error' => ''];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function roles(): array
    {
        $roles = json_decode((string) @file_get_contents($this->dataPath('roles.json')), true);

        return is_array($roles) ? $roles : [];
    }

    /**
     * @param array<string,mixed> $permissions
     */
    public function saveRolePermissions(string $role, array $permissions): bool
    {
        $role = trim($role);
        if ($role === '') {
            return false;
        }

        $roles = $this->roles();
        $roles[$role] = $permissions;
        $this->writeJson($this->dataPath('roles.json'), $roles);

        return true;
    }

    public function deleteRole(string $role): array
    {
        $role = trim($role);
        if ($role === '') {
            return ['ok' => false, 'error' => 'Rol no valido.'];
        }

        if (in_array($role, ['root', 'administrador', 'gestor', 'usuario'], true)) {
            return ['ok' => false, 'error' => 'No se puede eliminar un rol base.'];
        }

        foreach ($this->users() as $user) {
            if ((string) ($user['rol'] ?? '') === $role) {
                return ['ok' => false, 'error' => 'No se puede eliminar: hay usuarios con este rol asignado.'];
            }
        }

        $roles = $this->roles();
        if (!array_key_exists($role, $roles)) {
            return ['ok' => false, 'error' => 'Rol no encontrado.'];
        }

        unset($roles[$role]);
        $this->writeJson($this->dataPath('roles.json'), $roles);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function categories(): array
    {
        return $this->readList($this->dataPath('categorias.json'));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function saveCategory(array $payload): array
    {
        return $this->upsertNamedRow('categorias.json', $payload);
    }

    public function deleteCategory(string $id): int
    {
        return $this->deleteFromList($this->dataPath('categorias.json'), $id);
    }

    /**
     * @return array{ok:bool,count:int,changed:bool,error:string}
     */
    public function syncCategoriesFromRedmine(?string $userId = null): array
    {
        $config = $this->configuration();
        $token = $this->userApiToken($userId) ?: trim((string) ($config['platform_token'] ?? ''));
        $url = trim((string) ($config['categories_url'] ?? '')) ?: $this->redmineCategoriesUrl((string) ($config['platform_url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'Falta URL de categorias Redmine.'];
        }
        if ($token === '') {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'Token Redmine no configurado.'];
        }

        $response = $this->getRedmineJson($url, $token);
        if ($response['error'] !== '') {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => $response['error']];
        }
        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'HTTP ' . $response['http_code'] . ' al consultar categorias.'];
        }

        $data = json_decode($response['body'], true);
        $items = is_array($data['issue_categories'] ?? null) ? $data['issue_categories'] : [];
        if ($items === []) {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'La respuesta de Redmine no contiene issue_categories.'];
        }

        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }
            $rows[] = ['id' => $id, 'nombre' => $name];
        }

        $changed = $this->catalogRowsChanged($this->categories(), $rows);
        if ($changed) {
            $this->writeJson($this->dataPath('categorias.json'), $rows);
        }

        return ['ok' => true, 'count' => count($rows), 'changed' => $changed, 'error' => ''];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function units(): array
    {
        return $this->readList($this->dataPath('unidades.json'));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function saveUnit(array $payload): array
    {
        return $this->upsertNamedRow('unidades.json', $payload);
    }

    public function deleteUnit(string $id): int
    {
        return $this->deleteFromList($this->dataPath('unidades.json'), $id);
    }

    /**
     * @return array{ok:bool,count:int,changed:bool,error:string}
     */
    public function syncUnitsFromRedmine(?string $userId = null): array
    {
        $config = $this->configuration();
        $token = $this->userApiToken($userId) ?: trim((string) ($config['platform_token'] ?? ''));
        $url = trim((string) ($config['unidades_url'] ?? '')) ?: $this->redmineCustomFieldUrl((string) ($config['platform_url'] ?? ''), '11');
        if ($url === '') {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'Falta URL de unidades Redmine.'];
        }
        if ($token === '') {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'Token Redmine no configurado.'];
        }

        $response = $this->getRedmineJson($url, $token);
        if ($response['error'] !== '') {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => $response['error']];
        }
        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'HTTP ' . $response['http_code'] . ' al consultar unidades.'];
        }

        $data = json_decode($response['body'], true);
        $values = [];
        if (is_array($data['custom_field']['possible_values'] ?? null)) {
            $values = $data['custom_field']['possible_values'];
        } elseif (is_array($data['custom_fields'] ?? null)) {
            foreach ($data['custom_fields'] as $field) {
                if (is_array($field) && (string) ($field['id'] ?? '') === '11' && is_array($field['possible_values'] ?? null)) {
                    $values = $field['possible_values'];
                    break;
                }
            }
        }
        if ($values === []) {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'La respuesta de Redmine no contiene possible_values.'];
        }

        $rows = [];
        foreach ($values as $value) {
            $name = is_array($value) ? trim((string) ($value['value'] ?? '')) : trim((string) $value);
            if ($name === '') {
                continue;
            }
            $rows[] = ['id' => $name, 'nombre' => $name];
        }

        $changed = $this->catalogRowsChanged($this->units(), $rows);
        if ($changed) {
            $this->writeJson($this->dataPath('unidades.json'), $rows);
        }

        return ['ok' => true, 'count' => count($rows), 'changed' => $changed, 'error' => ''];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function hoursExtra(): array
    {
        return $this->readJsonTree('horasExtras');
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $user
     * @return array{rows:array<int,array<string,mixed>>,hoursMeta:array<string,mixed>}
     */
    public function hoursExtraData(array $filters = [], array $user = []): array
    {
        $groups = $this->deduplicateHoursGroups($this->hoursExtra());
        $userId = (string) ($user['id'] ?? '');
        if ($userId !== '' && $this->scopeForUser($user, 'horas_extra') !== 'todos') {
            $groups = array_values(array_filter(array_map(static function (array $group) use ($userId): ?array {
                $reports = array_values(array_filter((array) ($group['reports'] ?? []), static fn (array $report): bool => (string) ($report['asignado_a'] ?? '') === $userId));
                if ($reports === []) {
                    return null;
                }
                $group['reports'] = $reports;

                return $group;
            }, $groups)));
        }

        $availableYears = [now('America/Santiago')->format('Y') => true];
        foreach ($groups as $group) {
            $date = $this->parseFlexibleDate((string) ($group['fecha'] ?? ''));
            if ($date) {
                $availableYears[$date->format('Y')] = true;
            }
        }
        $availableYears = array_keys($availableYears);
        sort($availableYears);

        $hasExplicitFilters = array_key_exists('filters', $filters) || array_key_exists('mes', $filters) || array_key_exists('anio', $filters);
        $selectedMonth = $this->selectedMonth($filters['mes'] ?? null, $hasExplicitFilters);
        $selectedYear = $this->selectedYear($filters['anio'] ?? null, $hasExplicitFilters);
        $visible = array_values(array_filter($groups, function (array $group) use ($selectedMonth, $selectedYear): bool {
            $date = $this->parseFlexibleDate((string) ($group['fecha'] ?? ''));
            if (!$date) {
                return true;
            }

            return ($selectedMonth === '' || (int) $selectedMonth === (int) $date->format('n'))
                && ($selectedYear === '' || (string) $selectedYear === $date->format('Y'));
        }));
        usort($visible, function (array $a, array $b): int {
            return ((string) ($this->normalizeDateKey((string) ($b['fecha'] ?? '')))) <=> ((string) ($this->normalizeDateKey((string) ($a['fecha'] ?? ''))));
        });

        $totalMinutes = array_reduce($visible, fn (int $carry, array $group): int => $carry + ($this->minutesDiff((string) ($group['hora_inicio'] ?? ''), (string) ($group['hora_fin'] ?? '')) ?? 0), 0);

        return [
            'rows' => $visible,
            'hoursMeta' => [
                'months' => $this->monthOptions(),
                'years' => $availableYears,
                'selectedMonth' => $selectedMonth,
                'selectedYear' => $selectedYear,
                'visibleCount' => count($visible),
                'totalCount' => count($groups),
                'totalHours' => $this->formatMinutes($totalMinutes),
            ],
        ];
    }

    public function saveHoursGroup(string $sourceFile, array $payload): void
    {
        $path = $this->dataPath('horasExtras/' . ltrim(str_replace('\\', '/', $sourceFile), '/'));
        $this->assertInsideDataRoot($path);
        $groups = $this->readList($path);
        $date = trim((string) ($payload['fecha'] ?? ''));
        foreach ($groups as $index => $group) {
            if ((string) ($group['fecha'] ?? '') !== $date) {
                continue;
            }
            $groups[$index]['hora_inicio'] = trim((string) ($payload['hora_inicio'] ?? ($group['hora_inicio'] ?? '')));
            $groups[$index]['hora_fin'] = trim((string) ($payload['hora_fin'] ?? ($group['hora_fin'] ?? '')));
            $this->writeJson($path, $groups);
            return;
        }
    }

    public function deleteHoursGroup(string $sourceFile, string $date): int
    {
        $path = $this->dataPath('horasExtras/' . ltrim(str_replace('\\', '/', $sourceFile), '/'));
        $this->assertInsideDataRoot($path);
        $groups = $this->readList($path);
        $next = array_values(array_filter($groups, static fn (array $group): bool => (string) ($group['fecha'] ?? '') !== $date));
        $deleted = count($groups) - count($next);
        if ($deleted > 0) {
            $this->writeJson($path, $next);
        }

        return $deleted;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function history(array $user = []): array
    {
        $rows = [];
        foreach ($this->archivedReports() as $index => $report) {
            $key = $this->historyRowKey($report, 'archived-' . $index);
            $report['_history_type'] = 'Archivado';
            $report['_history_can_delete'] = true;
            $report['_history_sort_date'] = $this->normalizeDateKey((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? ''));
            $rows[$key] = $report;
        }

        foreach ($this->deduplicateHoursGroups($this->hoursExtra()) as $group) {
            foreach ((array) ($group['reports'] ?? []) as $index => $report) {
                if (!is_array($report)) {
                    continue;
                }
                $key = $this->historyRowKey($report, 'hours-' . ($group['fecha'] ?? '') . '-' . $index);
                if (isset($rows[$key])) {
                    $rows[$key]['_history_type'] = 'Hora extra';
                    $rows[$key]['_history_is_hours_extra'] = true;
                    continue;
                }
                $report['_history_type'] = 'Hora extra';
                $report['_history_is_hours_extra'] = true;
                $report['_history_can_delete'] = false;
                $report['_history_sort_date'] = $this->normalizeDateKey((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? $group['fecha'] ?? ''));
                $rows[$key] = $report;
            }
        }

        $rows = array_values($rows);
        usort($rows, static function (array $a, array $b): int {
            $dateCompare = ((string) ($b['_history_sort_date'] ?? '')) <=> ((string) ($a['_history_sort_date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return ((string) ($b['created_at'] ?? $b['procesado_ts'] ?? '')) <=> ((string) ($a['created_at'] ?? $a['procesado_ts'] ?? ''));
        });

        $rows = $this->filterReportsByUserScope($rows, $user, 'historico_scope');

        return $rows;
    }

    public function redmineIssueUrl(string $redmineId): string
    {
        $redmineId = preg_replace('/\D+/', '', trim($redmineId)) ?? '';
        if ($redmineId === '') {
            return '';
        }

        $baseUrl = $this->redmineBaseUrl((string) ($this->configuration()['platform_url'] ?? ''));

        return $baseUrl === '' ? '' : $baseUrl . '/issues/' . rawurlencode($redmineId);
    }

    /**
     * @param array<int,string> $redmineIds
     * @return array<string,array{name:string,closed:bool,available:bool,message:string}>
     */
    public function issueStatuses(array $redmineIds, ?string $userId = null): array
    {
        $config = $this->configuration();
        $token = $this->userApiToken($userId) ?: trim((string) ($config['platform_token'] ?? ''));
        $statuses = [];

        foreach ($redmineIds as $redmineId) {
            $id = preg_replace('/\D+/', '', trim((string) $redmineId)) ?? '';
            if ($id === '') {
                continue;
            }

            $issueUrl = $this->redmineIssueUrl($id);
            if ($issueUrl === '') {
                $statuses[$id] = [
                    'name' => '',
                    'closed' => false,
                    'available' => false,
                    'message' => 'URL Redmine no configurada',
                ];
                continue;
            }

            if ($token === '') {
                $statuses[$id] = [
                    'name' => '',
                    'closed' => false,
                    'available' => false,
                    'message' => 'Token API no configurado',
                ];
                continue;
            }

            $response = $this->getRedmineJson($issueUrl . '.json', $token);
            if ($response['error'] !== '' || $response['http_code'] < 200 || $response['http_code'] >= 300) {
                $statuses[$id] = [
                    'name' => '',
                    'closed' => false,
                    'available' => false,
                    'message' => $response['error'] !== '' ? $response['error'] : 'HTTP ' . $response['http_code'],
                ];
                continue;
            }

            $payload = json_decode($response['body'], true);
            $statusName = trim((string) data_get($payload, 'issue.status.name', ''));
            if ($statusName === '') {
                $statuses[$id] = [
                    'name' => '',
                    'closed' => false,
                    'available' => false,
                    'message' => 'Estado no informado por Redmine',
                ];
                continue;
            }

            $statuses[$id] = [
                'name' => $statusName,
                'closed' => $this->isClosedIssueStatus($statusName),
                'available' => true,
                'message' => '',
            ];
        }

        return $statuses;
    }

    public function deleteArchivedReport(string $id): int
    {
        $root = $this->dataPath('reportes');
        if (!is_dir($root)) {
            return 0;
        }

        $deleted = 0;
        foreach (File::allFiles($root) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }
            $rows = $this->readList($file->getPathname());
            $next = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['id'] ?? '') !== $id));
            if (count($rows) === count($next)) {
                continue;
            }
            $deleted += count($rows) - count($next);
            $this->writeJson($file->getPathname(), $next);
        }

        return $deleted;
    }

    /**
     * @return array<int,string>
     */
    public function activity(): array
    {
        $path = $this->dataPath('security.log');
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_slice(array_reverse(is_array($lines) ? $lines : []), 0, 200);
    }

    public function clearActivity(): void
    {
        $this->writeText($this->dataPath('security.log'), '');
    }

    /**
     * @param array<string,mixed> $context
     */
    public function recordActivity(string $event, array $context = []): void
    {
        $this->appendActivityLog($event, $context);
    }

    /**
     * @return array<string,mixed>
     */
    public function statistics(array $filters = []): array
    {
        $reports = array_merge($this->activeReports(), $this->archivedReports());
        [$from, $to] = $this->statisticsDateRange($filters);
        $reports = $this->filterReportsByDateRange($reports, $from, $to);
        $byDate = $this->countsByDate($reports);
        $byMonth = $this->countsByMonth($reports);

        return [
            'total' => count($reports),
            'by_status' => $this->countsByField($reports, 'estado'),
            'by_category' => $this->countsByField($reports, 'categoria'),
            'by_unit' => $this->countsByField($reports, 'unidad_solicitante'),
            'by_assignee' => $this->countsByField($reports, 'asignado_nombre'),
            'by_date' => $byDate,
            'by_month' => $byMonth,
            'max_daily' => $byDate ? max($byDate) : 0,
            'max_monthly' => $byMonth ? max($byMonth) : 0,
            'filters' => [
                'desde' => $from?->format('d-m-Y') ?? '',
                'hasta' => $to?->format('d-m-Y') ?? '',
            ],
            'updated_at' => now('America/Santiago')->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function redmineApiStatistics(array $filters = [], array $user = []): array
    {
        $config = $this->configuration();
        $statusOptions = $this->redmineStatusOptions($config);
        $statusSelection = $this->normalizeRedmineStatusSelection((string) ($filters['status_scope'] ?? 'all'), $statusOptions);
        $trackerOptions = $this->redmineConfigOptions($config, 'trackers');
        $priorityOptions = $this->redmineConfigOptions($config, 'prioridades');
        $trackerSelection = $this->normalizeRedmineOptionSelection((string) ($filters['tracker_scope'] ?? 'all'), $trackerOptions);
        $prioritySelection = $this->normalizeRedmineOptionSelection((string) ($filters['priority_scope'] ?? 'all'), $priorityOptions);
        $categorySelection = $this->normalizeRedmineCategorySelection((array) ($filters['category_scope'] ?? []));
        $categoryFilterActive = filter_var($filters['category_filter'] ?? false, FILTER_VALIDATE_BOOL);
        [$from, $to] = $this->statisticsDateRange($filters);
        $fetchRequested = filter_var($filters['fetch'] ?? false, FILTER_VALIDATE_BOOL);
        $maintenanceActive = $this->maintenanceModeEnabled();
        $shouldFetch = $fetchRequested && !$maintenanceActive;
        $empty = $this->emptyStatistics([
            'desde' => $from?->format('d-m-Y') ?? '',
            'hasta' => $to?->format('d-m-Y') ?? '',
            'status_scope' => $statusSelection,
            'tracker_scope' => $trackerSelection,
            'priority_scope' => $prioritySelection,
            'category_scope' => $categorySelection,
            'category_filter' => $categoryFilterActive ? '1' : '',
        ]);
        $empty['source'] = 'redmine-api';
        $empty['fetched'] = false;
        $empty['error'] = '';
        $empty['status_options'] = $statusOptions;
        $empty['tracker_options'] = $trackerOptions;
        $empty['priority_options'] = $priorityOptions;

        if ($fetchRequested && $maintenanceActive) {
            $this->appendActivityLog('redmine_api_sincronizacion_bloqueada', [
                'reason' => 'modo_mantencion',
                'desde' => $from?->format('d-m-Y') ?? '',
                'hasta' => $to?->format('d-m-Y') ?? '',
                'status_scope' => $statusSelection,
                'tracker_scope' => $trackerSelection,
                'priority_scope' => $prioritySelection,
            ]);
        }

        if (!$shouldFetch) {
            $cached = $this->redmineApiStatisticsCache();
            if ($cached !== []) {
                $rawRows = (array) ($cached['raw_rows'] ?? []);
                if ($rawRows !== []) {
                    $cachedFilters = (array) ($cached['filters'] ?? []);
                    $cachedFilters['category_scope'] = $categorySelection;
                    $cachedFilters['category_filter'] = $categoryFilterActive ? '1' : '';
                    $cached = $this->buildRedmineApiStatisticsFromRows($rawRows, $config, $cachedFilters, true);
                }
                $cached = $this->normalizeRedmineApiStatistics($cached);
                $cached['source'] = 'redmine-api';
                $cached['fetched'] = true;
                $cached['cached'] = true;
                $cached['error'] = '';
                $cached['status_options'] = $statusOptions;
                $cached['tracker_options'] = $trackerOptions;
                $cached['priority_options'] = $priorityOptions;

                return $cached;
            }

            return $empty;
        }

        $token = $this->userApiToken((string) ($user['id'] ?? '')) ?: trim((string) ($config['platform_token'] ?? ''));
        $projectId = trim((string) ($config['project_id'] ?? ''));
        $issuesUrl = $this->redmineIssuesUrl((string) ($config['platform_url'] ?? ''));
        $dateField = in_array((string) ($config['date_field'] ?? ''), ['start_date', 'due_date', 'created_on'], true)
            ? (string) $config['date_field']
            : 'start_date';

        if ($token === '' || $projectId === '' || $issuesUrl === '') {
            $empty['error'] = 'Falta configurar URL, proyecto o token API de Redmine.';
            $this->appendActivityLog('redmine_api_sincronizacion_error', [
                'error' => $empty['error'],
                'desde' => $from?->format('d-m-Y') ?? '',
                'hasta' => $to?->format('d-m-Y') ?? '',
            ]);
            return $empty;
        }
        if (!$from || !$to) {
            $empty['error'] = 'Selecciona un rango de fechas para consultar Redmine.';
            $this->appendActivityLog('redmine_api_sincronizacion_error', [
                'error' => $empty['error'],
            ]);
            return $empty;
        }

        $query = [
            'project_id' => $projectId,
            'status_id' => $this->redmineStatusQueryValue($statusSelection),
            $dateField => '><' . $from->format('Y-m-d') . '|' . $to->format('Y-m-d'),
        ];
        if ($trackerSelection !== 'all') {
            $query['tracker_id'] = $trackerSelection;
        }
        if ($prioritySelection !== 'all') {
            $query['priority_id'] = $prioritySelection;
        }

        $issues = $this->fetchRedmineIssues($issuesUrl, $token, $query);
        if (isset($issues['error'])) {
            $empty['error'] = $issues['error'];
            $this->appendActivityLog('redmine_api_sincronizacion_error', [
                'error' => $issues['error'],
                'desde' => $from->format('d-m-Y'),
                'hasta' => $to->format('d-m-Y'),
                'status_scope' => $statusSelection,
                'tracker_scope' => $trackerSelection,
                'priority_scope' => $prioritySelection,
            ]);
            return $empty;
        }

        $rows = $issues['rows'];
        $result = $this->buildRedmineApiStatisticsFromRows($rows, $config, [
            'desde' => $from->format('d-m-Y'),
            'hasta' => $to->format('d-m-Y'),
            'status_scope' => $statusSelection,
            'tracker_scope' => $trackerSelection,
            'priority_scope' => $prioritySelection,
            'category_scope' => [],
            'category_filter' => '',
        ]);
        $result['status_options'] = $statusOptions;
        $result['tracker_options'] = $trackerOptions;
        $result['priority_options'] = $priorityOptions;

        $this->saveRedmineApiStatisticsCache($result);
        $this->appendActivityLog('redmine_api_sincronizacion_ok', [
            'total' => count($rows),
            'desde' => $from->format('d-m-Y'),
            'hasta' => $to->format('d-m-Y'),
            'status_scope' => $statusSelection,
            'tracker_scope' => $trackerSelection,
            'priority_scope' => $prioritySelection,
        ]);

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function nativeSectionData(string $section, string $dashboardFilter = 'todos', array $filters = [], array $user = []): array
    {
        return match ($section) {
            'dashboard' => array_merge($this->dashboardData($dashboardFilter, $user), ['users' => $this->users(), 'categories' => $this->categories(), 'units' => $this->units()]),
            'webhook' => ['config' => $this->configuration(), 'users' => $this->users(), 'categories' => $this->categories(), 'units' => $this->units()],
            'horas-extra' => $this->hoursExtraData($filters, $user),
            'historico' => ['rows' => $this->history($user), 'config' => $this->configuration()],
            'usuarios' => ['users' => $this->users(), 'roles' => $this->roles()],
            'configuracion' => ['config' => $this->configuration(), 'roles' => $this->roles(), 'users' => $this->users(), 'categories' => $this->categories(), 'units' => $this->units(), 'webhookUrl' => $this->webhookUrl()],
            'estadisticas' => ['stats' => $this->statistics($filters)],
            'estadisticas-api' => ['stats' => $this->redmineApiStatistics($filters, $user)],
            'actividad' => ['lines' => $this->activity()],
            default => [],
        };
    }

    /**
     * @return array<string,array{label:string,paths:array<int,string>}>
     */
    public function maintenanceSections(): array
    {
        return [
            'archivados' => [
                'label' => 'Archivados',
                'paths' => ['reportes'],
            ],
            'pendientes' => [
                'label' => 'Pendientes activos',
                'paths' => ['mensaje.json'],
            ],
            'horas_extras' => [
                'label' => 'Horas extra',
                'paths' => ['horasExtras'],
            ],
            'configuraciones' => [
                'label' => 'Configuraciones',
                'paths' => [
                    'configuracion.json',
                    'roles.json',
                    'categorias.json',
                    'usuarios.json',
                    'unidades.json',
                ],
            ],
        ];
    }

    /**
     * @param string[] $selected
     * @return array<string,mixed>
     */
    public function exportMaintenanceBundle(array $selected): array
    {
        $available = $this->maintenanceSections();
        $bundle = [
            'type' => 'redmine-native-maintenance',
            'version' => 1,
            'created_at' => now('America/Santiago')->toIso8601String(),
            'sections' => [],
        ];

        foreach ($selected as $sectionKey) {
            if (!isset($available[$sectionKey])) {
                continue;
            }

            $bundle['sections'][$sectionKey] = [
                'label' => $available[$sectionKey]['label'],
                'files' => [],
            ];

            foreach ($available[$sectionKey]['paths'] as $path) {
                foreach ($this->maintenanceReadPath($path) as $relative => $file) {
                    $bundle['sections'][$sectionKey]['files'][$relative] = $file;
                }
            }
        }

        return $bundle;
    }

    /**
     * @param array<string,mixed> $bundle
     * @param string[] $selected
     */
    public function importMaintenanceBundle(array $bundle, array $selected): int
    {
        if (!in_array(($bundle['type'] ?? ''), ['redmine-native-maintenance', 'redmine-maintenance', 'redmine-mantencion-maintenance'], true)) {
            throw new \RuntimeException('El archivo no corresponde a un respaldo de mantencion valido.');
        }
        if (!isset($bundle['sections']) || !is_array($bundle['sections'])) {
            throw new \RuntimeException('El respaldo no contiene secciones importables.');
        }

        $allowed = $this->maintenanceAllowedPaths($selected);
        $written = 0;

        foreach ($selected as $sectionKey) {
            $section = $bundle['sections'][$sectionKey] ?? [];
            $files = is_array($section) && isset($section['files']) && is_array($section['files'])
                ? $section['files']
                : (is_array($section) ? $section : []);

            foreach ($files as $relative => $file) {
                $relative = $this->normalizeMaintenanceRelativePath((string) $relative);
                if (!$this->maintenancePathAllowed($relative, $allowed)) {
                    continue;
                }
                if (!is_array($file)) {
                    continue;
                }

                $this->maintenanceWriteImportedFile($relative, $file);
                $written++;
            }
        }

        return $written;
    }

    public function dataPath(string $path): string
    {
        return $this->basePath() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readList(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data)
            ? array_values(array_filter($data, static fn ($item): bool => is_array($item)))
            : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonMap(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    public function updateReport(array $payload): bool
    {
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return false;
        }

        $reports = $this->activeReports();
        foreach ($reports as $index => $report) {
            if ((string) ($report['id'] ?? '') !== $id) {
                continue;
            }
            $reports[$index] = array_merge($report, Arr::only($payload, [
                'tipo',
                'asunto',
                'prioridad',
                'categoria',
                'asignado_a',
                'solicitante',
                'unidad',
                'unidad_solicitante',
                'hora_extra',
                'fecha_inicio',
                'fecha_fin',
                'tiempo_estimado',
                'fecha',
                'hora',
                'numero',
                'mensaje',
                'descripcion',
            ]));
            $this->saveActiveReports($reports);
            $this->syncHoursExtraForReport($reports[$index]);
            return true;
        }

        return false;
    }

    public function deleteReport(string $id): int
    {
        $reports = $this->activeReports();
        $next = array_values(array_filter($reports, static fn (array $report): bool => (string) ($report['id'] ?? '') !== $id));
        $deleted = count($reports) - count($next);
        if ($deleted > 0) {
            $this->saveActiveReports($next);
            $this->removeHoursExtraRecord($id);
        }

        return $deleted;
    }

    /**
     * @param string[] $ids
     */
    public function deleteReports(array $ids): int
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        $reports = $this->activeReports();
        $next = array_values(array_filter($reports, static fn (array $report): bool => !in_array((string) ($report['id'] ?? ''), $ids, true)));
        $deleted = count($reports) - count($next);
        if ($deleted > 0) {
            $this->saveActiveReports($next);
            foreach ($ids as $id) {
                $this->removeHoursExtraRecord($id);
            }
        }

        return $deleted;
    }

    /**
     * @param string[] $ids
     */
    public function archiveReports(array $ids): int
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        $reports = $this->activeReports();
        $next = [];
        $archived = 0;

        foreach ($reports as $report) {
            if (!in_array((string) ($report['id'] ?? ''), $ids, true)) {
                $next[] = $report;
                continue;
            }
            $this->archiveReport($report);
            $archived++;
        }

        if ($archived > 0) {
            $this->saveActiveReports($next);
        }

        return $archived;
    }

    public function archiveExpiredProcessedReports(): int
    {
        $retentionHours = max(1, (int) ($this->configuration()['retencion_horas'] ?? 24));
        $limit = now('America/Santiago')->subHours($retentionHours)->getTimestamp();
        $reports = $this->activeReports();
        $next = [];
        $archived = 0;

        foreach ($reports as $report) {
            $state = strtolower(trim((string) ($report['estado'] ?? '')));
            if (!in_array($state, ['procesado', 'procesada'], true)) {
                $next[] = $report;
                continue;
            }

            $processedAt = $this->timestampFromValue($report['procesado_ts'] ?? null);
            if ($processedAt === null || $processedAt > $limit) {
                $next[] = $report;
                continue;
            }

            $report['_archivado_por'] = 'retencion';
            $report['_retencion_horas'] = $retentionHours;
            $this->archiveReport($report);
            $archived++;
        }

        if ($archived > 0) {
            $this->saveActiveReports($next);
        }

        return $archived;
    }

    public function toggleHoursExtra(string $id, bool $enabled): bool
    {
        $reports = $this->activeReports();
        foreach ($reports as $index => $report) {
            if ((string) ($report['id'] ?? '') !== $id) {
                continue;
            }
            $reports[$index]['hora_extra'] = $enabled ? 'SI' : 'NO';
            $reports[$index]['tiempo_estimado'] = $enabled ? '1' : '';
            $this->saveActiveReports($reports);
            if ($enabled) {
                $this->syncHoursExtraForReport($reports[$index]);
            } else {
                $this->removeHoursExtraRecord($id);
            }
            return true;
        }

        return false;
    }

    /**
     * @param string[] $ids
     */
    public function resetErrors(array $ids): int
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        $reports = $this->activeReports();
        $updated = 0;
        foreach ($reports as $index => $report) {
            if (!in_array((string) ($report['id'] ?? ''), $ids, true)) {
                continue;
            }
            if (strtolower((string) ($report['estado'] ?? '')) !== 'error') {
                continue;
            }
            $reports[$index]['estado'] = 'pendiente';
            unset($reports[$index]['redmine_id']);
            $reports[$index]['procesado_ts'] = '';
            $updated++;
        }
        if ($updated > 0) {
            $this->saveActiveReports($reports);
        }

        return $updated;
    }

    /**
     * @param string[] $ids
     * @return array{attempts:int,success:int,errors:array<int,string>,redmine_ids:array<int,string>}
     */
    public function sendReportsToRedmine(array $ids, ?string $userId = null): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        $reports = $this->activeReports();
        $config = $this->configuration();
        $token = $this->userApiToken($userId) ?: trim((string) ($config['platform_token'] ?? ''));
        $attempts = 0;
        $success = 0;
        $errors = [];
        $redmineIds = [];

        foreach ($reports as $index => $report) {
            if (!in_array((string) ($report['id'] ?? ''), $ids, true)) {
                continue;
            }
            $attempts++;
            $payload = ['issue' => $this->buildIssuePayload($report, $config)];
            $result = $this->postRedmineIssue($config, $payload, $token);
            $this->appendSendLog([
                'ts' => now('America/Santiago')->toAtomString(),
                'message_id' => $report['id'] ?? '',
                'http_code' => $result['http_code'],
                'error' => $result['error'],
                'body' => $result['body'],
                'payload' => $payload,
            ]);

            if ($result['http_code'] === 201) {
                $decoded = json_decode($result['body'], true);
                $reports[$index]['estado'] = 'procesado';
                $reports[$index]['redmine_id'] = $decoded['issue']['id'] ?? $reports[$index]['redmine_id'] ?? '';
                $reports[$index]['procesado_ts'] = now('America/Santiago')->toAtomString();
                $success++;
                if (!empty($reports[$index]['redmine_id'])) {
                    $redmineIds[] = (string) $reports[$index]['redmine_id'];
                }
                $this->appendActivityLog('envio_redmine_ok', [
                    'message_id' => $report['id'] ?? '',
                    'user_id' => $userId ?? '',
                    'redmine_id' => $reports[$index]['redmine_id'] ?? '',
                    'http_code' => $result['http_code'],
                    'asunto' => $report['asunto'] ?? '',
                    'categoria' => $report['categoria'] ?? '',
                    'unidad' => $report['unidad'] ?? '',
                    'unidad_solicitante' => $report['unidad_solicitante'] ?? '',
                ]);
                $this->syncHoursExtraForReport($reports[$index]);
                continue;
            }

            $reports[$index]['estado'] = 'error';
            $reports[$index]['procesado_ts'] = now('America/Santiago')->toAtomString();
            $errors[] = 'No se pudo enviar ' . ($report['id'] ?? 'sin-id') . ': ' . ($result['error'] ?: $result['body']);
            $this->appendActivityLog('envio_redmine_error', [
                'message_id' => $report['id'] ?? '',
                'user_id' => $userId ?? '',
                'http_code' => $result['http_code'],
                'error' => $result['error'] ?: $result['body'],
                'asunto' => $report['asunto'] ?? '',
                'categoria' => $report['categoria'] ?? '',
                'unidad' => $report['unidad'] ?? '',
            ]);
        }

        if ($attempts > 0) {
            $this->saveActiveReports($reports);
            $this->appendActivityLog('envio_redmine_resumen', [
                'user_id' => $userId ?? '',
                'attempts' => $attempts,
                'success' => $success,
                'errors' => count($errors),
                'redmine_ids' => $redmineIds,
            ]);
        }

        return [
            'attempts' => $attempts,
            'success' => $success,
            'errors' => $errors,
            'redmine_ids' => $redmineIds,
        ];
    }

    public function createSimulatedReport(array $payload): array
    {
        $reports = $this->activeReports();
        $now = now('America/Santiago');
        $report = [
            'id' => (string) Str::uuid(),
            'tipo' => trim((string) ($payload['tipo'] ?? 'webhook')),
            'estado' => 'pendiente',
            'asunto' => trim((string) ($payload['asunto'] ?? 'Solicitud simulada')),
            'descripcion' => trim((string) ($payload['descripcion'] ?? '')),
            'mensaje' => trim((string) ($payload['mensaje'] ?? '')),
            'solicitante' => trim((string) ($payload['solicitante'] ?? '')),
            'unidad' => trim((string) ($payload['unidad'] ?? '')),
            'unidad_solicitante' => trim((string) ($payload['unidad_solicitante'] ?? $payload['unidad'] ?? '')),
            'categoria' => trim((string) ($payload['categoria'] ?? '')),
            'prioridad' => trim((string) ($payload['prioridad'] ?? 'NORMAL')),
            'numero' => trim((string) ($payload['numero'] ?? '')),
            'fecha' => trim((string) ($payload['fecha'] ?? $now->format('Y-m-d'))),
            'hora' => trim((string) ($payload['hora'] ?? $now->format('H:i'))),
            'fecha_inicio' => trim((string) ($payload['fecha_inicio'] ?? $now->format('Y-m-d'))),
            'fecha_fin' => trim((string) ($payload['fecha_fin'] ?? '')),
            'asignado_a' => trim((string) ($payload['asignado_a'] ?? '')),
            'asignado_nombre' => trim((string) ($payload['asignado_nombre'] ?? '')),
            'hora_extra' => (($payload['hora_extra'] ?? '') === 'SI' || ($payload['hora_extra'] ?? '') === '1') ? 'SI' : 'NO',
            'tiempo_estimado' => trim((string) ($payload['tiempo_estimado'] ?? '')),
            'origen' => trim((string) ($payload['origen'] ?? 'manual')),
            'created_at' => $now->toAtomString(),
        ];
        $reports[] = $report;
        $this->saveActiveReports($reports);
        $this->appendActivityLog('recepcion_datos', [
            'message_id' => $report['id'],
            'tipo' => $report['tipo'],
            'numero' => $report['numero'],
            'asunto' => $report['asunto'],
            'categoria' => $report['categoria'],
            'unidad_solicitante' => $report['unidad_solicitante'],
        ]);

        return $report;
    }

    /**
     * @param array<string,mixed> $telegramUser
     * @return array{ok:bool,error:string,report:array<string,mixed>,maintenance:bool}
     */
    public function createTelegramReport(string $text, array $telegramUser = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => 'Escribe el reporte despues del comando. Ej: /tic impresora no imprime, SOME HBV, Juan Perez', 'report' => [], 'maintenance' => false];
        }

        if ($this->maintenanceModeEnabled()) {
            $maintenance = $this->dashboardSummary()['maintenance'];
            $until = trim((string) ($maintenance['until_text'] ?? ''));
            return [
                'ok' => false,
                'error' => 'Redmine TIC esta en mantencion. Termino: ' . ($until !== '' ? $until : 'sin fecha definida') . '.',
                'report' => [],
                'maintenance' => true,
            ];
        }

        $now = now('America/Santiago');
        $parts = array_map('trim', explode(',', $text));
        $problem = $parts[0] ?? $text;
        $unitText = $parts[1] ?? '';
        $requesterText = $parts[2] ?? '';
        $categories = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['nombre'] ?? '')),
            $this->categories()
        )));
        $units = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['nombre'] ?? '')),
            $this->units()
        )));

        $category = $this->inferCatalogMatch($problem, $categories)
            ?: $this->inferCatalogMatch($problem . ' ' . $unitText, $categories)
            ?: 'Equipos';
        $requestUnit = $this->inferCatalogMatch($unitText, $units) ?: 'HBV';
        $unit = $unitText !== '' ? $unitText : $requestUnit;
        $requester = $requesterText !== '' ? $requesterText : $this->telegramUserDisplayName($telegramUser);
        $chatId = trim((string) data_get($telegramUser, 'telegram_settings.chat_id', ''));
        $assignee = $this->telegramProjectAssignee($telegramUser, $chatId);

        $report = [
            'id' => (string) Str::uuid(),
            'numero' => $chatId !== '' ? 'telegram:' . $chatId : 'telegram',
            'mensaje' => $text,
            'fecha' => $now->format('d-m-Y'),
            'hora' => $now->format('H:i:s'),
            'fecha_inicio' => $now->format('d-m-Y'),
            'fecha_fin' => $now->format('d-m-Y'),
            'tipo' => 'Soporte',
            'prioridad' => 'NORMAL',
            'estado' => 'pendiente',
            'hora_extra' => 'NO',
            'tiempo_estimado' => '',
            'categoria' => $category,
            'unidad' => $unit,
            'unidad_solicitante' => $requestUnit,
            'solicitante' => $requester,
            'asunto' => $problem !== '' && $unit !== '' ? $problem . ' / ' . $unit : $problem,
            'asignado_a' => $assignee['id'],
            'asignado_nombre' => $assignee['name'],
            'origen' => 'telegram',
            'created_at' => $now->toAtomString(),
        ];

        $reports = $this->activeReports();
        $reports[] = $report;
        $this->saveActiveReports($reports);
        $this->appendActivityLog('recepcion_telegram', [
            'message_id' => $report['id'],
            'chat_id' => $chatId,
            'asunto' => $report['asunto'],
            'categoria' => $report['categoria'],
            'unidad_solicitante' => $report['unidad_solicitante'],
        ]);

        return ['ok' => true, 'error' => '', 'report' => $report, 'maintenance' => false];
    }

    public function webhookUrl(): string
    {
        $configUrl = trim((string) ($this->configuration()['webhook_url'] ?? ''));

        return $configUrl !== ''
            ? $configUrl
            : (trim((string) env('WEBHOOK_URL', 'http://localhost:8000/webhook')) ?: 'http://localhost:8000/webhook');
    }

    /**
     * @return array{ok:bool,http_code:int,body:string,error:string,url:string}
     */
    public function testWebhookConnection(string $url): array
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'URL de webhook no valida.', 'url' => $url];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'La extension cURL de PHP no esta disponible.', 'url' => $url];
        }

        $payload = [
            'message' => [
                'from' => '+56000000000',
                'text' => 'test conexion webhook NOVA',
                'timestamp' => time(),
            ],
            'from' => '+56000000000',
            'test' => true,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $body === false ? (string) curl_error($ch) : '';
        curl_close($ch);

        $ok = $body !== false && $httpCode >= 200 && $httpCode < 400;

        return [
            'ok' => $ok,
            'http_code' => $httpCode,
            'body' => $body === false ? '' : Str::limit((string) $body, 500),
            'error' => $error,
            'url' => $url,
        ];
    }

    /**
     * @return array{ok:bool,http_code:int,body:string,error:string,payload:array<string,mixed>,url:string}
     */
    public function sendWebhookMessage(array $input): array
    {
        $url = trim((string) ($input['webhook_url'] ?? $this->webhookUrl())) ?: $this->webhookUrl();
        $numero = trim((string) ($input['numero'] ?? ''));
        $mensaje = trim((string) ($input['mensaje'] ?? ''));

        if ($numero === '' || $mensaje === '') {
            $this->appendActivityLog('webhook_envio_error', [
                'url' => $url,
                'error' => 'El numero y el mensaje son obligatorios.',
            ]);
            return [
                'ok' => false,
                'http_code' => 0,
                'body' => '',
                'error' => 'El numero y el mensaje son obligatorios.',
                'payload' => [],
                'url' => $url,
            ];
        }

        $timestamp = $this->webhookTimestamp(
            trim((string) ($input['fecha'] ?? now('America/Santiago')->format('Y-m-d'))),
            trim((string) ($input['hora'] ?? now('America/Santiago')->format('H:i')))
        );
        $payload = [
            'message' => [
                'from' => $numero,
                'text' => $mensaje,
                'timestamp' => $timestamp,
            ],
            'from' => $numero,
        ];

        if (!function_exists('curl_init')) {
            $this->appendActivityLog('webhook_envio_error', [
                'url' => $url,
                'numero' => $numero,
                'error' => 'La extension cURL de PHP no esta disponible.',
            ]);
            return [
                'ok' => false,
                'http_code' => 0,
                'body' => '',
                'error' => 'La extension cURL de PHP no esta disponible.',
                'payload' => $payload,
                'url' => $url,
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $body === false ? (string) curl_error($ch) : '';
        curl_close($ch);
        $ok = $body !== false && $httpCode >= 200 && $httpCode < 300;
        $this->appendActivityLog($ok ? 'webhook_envio_ok' : 'webhook_envio_error', [
            'url' => $url,
            'numero' => $numero,
            'http_code' => $httpCode,
            'error' => $error,
            'body' => $body === false ? '' : Str::limit((string) $body, 500),
        ]);

        return [
            'ok' => $ok,
            'http_code' => $httpCode,
            'body' => $body === false ? '' : (string) $body,
            'error' => $error,
            'payload' => $payload,
            'url' => $url,
        ];
    }

    public function syncHoursExtraForReport(array $report): void
    {
        $id = (string) ($report['id'] ?? '');
        if ($id === '') {
            return;
        }
        $this->removeHoursExtraRecord($id);
        if (!in_array(strtolower((string) ($report['hora_extra'] ?? '')), ['si', 'sí', '1', 'true'], true)) {
            return;
        }

        $date = trim((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? now('America/Santiago')->format('Y-m-d')));
        $dt = date_create($date) ?: now('America/Santiago');
        $year = $dt->format('Y');
        $month = $this->monthName((int) $dt->format('n'));
        $path = $this->dataPath("horasExtras/$year/$month.json");
        $groups = $this->readList($path);
        $targetDate = $dt->format('Y-m-d');
        foreach ($groups as $index => $group) {
            if ((string) ($group['fecha'] ?? '') !== $targetDate) {
                continue;
            }
            $group['reports'] = array_values(array_filter($group['reports'] ?? [], static fn ($row): bool => !is_array($row) || (string) ($row['id'] ?? '') !== $id));
            $group['reports'][] = $report;
            $groups[$index] = $group;
            $this->writeJson($path, $groups);
            return;
        }

        $groups[] = [
            'fecha' => $targetDate,
            'hora_inicio' => trim((string) ($report['hora_inicio'] ?? $report['hora'] ?? '')),
            'hora_fin' => trim((string) ($report['hora_fin'] ?? $report['hora'] ?? '')),
            'reports' => [$report],
        ];
        $this->writeJson($path, $groups);
    }

    public function removeHoursExtraRecord(string $id): void
    {
        $root = $this->dataPath('horasExtras');
        if (!is_dir($root)) {
            return;
        }
        foreach (File::allFiles($root) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }
            $groups = $this->readList($file->getPathname());
            $changed = false;
            $next = [];
            foreach ($groups as $group) {
                $reports = $group['reports'] ?? [];
                if (!is_array($reports)) {
                    $next[] = $group;
                    continue;
                }
                $filtered = array_values(array_filter($reports, static fn ($row): bool => !is_array($row) || (string) ($row['id'] ?? '') !== $id));
                if (count($filtered) !== count($reports)) {
                    $changed = true;
                }
                if (!$filtered) {
                    continue;
                }
                $group['reports'] = $filtered;
                $next[] = $group;
            }
            if ($changed) {
                $this->writeJson($file->getPathname(), $next);
            }
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readJsonTree(string $directory): array
    {
        $rows = [];
        $root = $this->dataPath($directory);

        if (!is_dir($root)) {
            return [];
        }

        foreach (File::allFiles($root) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            foreach ($this->readList($file->getPathname()) as $row) {
                $row['_source_file'] = str_replace('\\', '/', $file->getRelativePathname());
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function upsertNamedRow(string $file, array $payload): array
    {
        $path = $this->dataPath($file);
        $rows = $this->readList($path);
        $id = trim((string) ($payload['id'] ?? '')) ?: (string) Str::uuid();
        $row = [
            'id' => $id,
            'nombre' => trim((string) ($payload['nombre'] ?? $payload['name'] ?? '')),
        ];
        $updated = false;
        foreach ($rows as $index => $existing) {
            if ((string) ($existing['id'] ?? '') !== $id) {
                continue;
            }
            $rows[$index] = array_merge($existing, $row);
            $updated = true;
            break;
        }
        if (!$updated) {
            $rows[] = $row;
        }
        $this->writeJson($path, $rows);

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $current
     * @param array<int,array<string,string>> $incoming
     */
    private function catalogRowsChanged(array $current, array $incoming): bool
    {
        $normalize = static function (array $rows): array {
            return array_values(array_map(static fn (array $row): array => [
                'id' => (string) ($row['id'] ?? ''),
                'nombre' => (string) ($row['nombre'] ?? $row['name'] ?? ''),
            ], $rows));
        };

        return $normalize($current) !== $normalize($incoming);
    }

    private function deleteFromList(string $path, string $id): int
    {
        $rows = $this->readList($path);
        $next = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['id'] ?? '') !== $id));
        $deleted = count($rows) - count($next);
        if ($deleted > 0) {
            $this->writeJson($path, $next);
        }

        return $deleted;
    }

    /**
     * @param mixed $data
     */
    private function writeJson(string $path, $data): void
    {
        $this->assertInsideDataRoot($path);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function writeText(string $path, string $contents): void
    {
        $this->assertInsideDataRoot($path);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents, LOCK_EX);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function activeReportsFromDatabase(): array
    {
        if (!$this->reportsTableAvailable()) {
            return [];
        }

        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            return DB::table('reportes_redmine')
                ->where('modulo_id', $moduleId)
                ->whereNull('archivado_at')
                ->orderBy('creado_at')
                ->get()
                ->map(fn ($row): array => $this->databaseReportToArray($row))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     */
    private function saveActiveReportsToDatabase(array $reports): void
    {
        if (!$this->reportsTableAvailable()) {
            return;
        }

        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return;
        }

        $activeIds = [];
        foreach ($reports as $report) {
            if (!is_array($report)) {
                continue;
            }

            $localId = trim((string) ($report['id'] ?? ''));
            if ($localId === '') {
                continue;
            }
            $activeIds[] = $localId;

            try {
                DB::table('reportes_redmine')->updateOrInsert(
                    ['modulo_id' => $moduleId, 'local_id' => $localId],
                    $this->databaseReportPayload($moduleId, $report, false)
                );
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            $query = DB::table('reportes_redmine')
                ->where('modulo_id', $moduleId)
                ->whereNull('archivado_at');

            if ($activeIds !== []) {
                $query->whereNotIn('local_id', $activeIds);
            }

            $query->delete();
        } catch (\Throwable) {
        }
    }

    private function saveArchivedReportToDatabase(array $report): void
    {
        if (!$this->reportsTableAvailable()) {
            return;
        }

        $moduleId = $this->databaseModuleId();
        $localId = trim((string) ($report['id'] ?? ''));
        if ($moduleId === null || $localId === '') {
            return;
        }

        try {
            DB::table('reportes_redmine')->updateOrInsert(
                ['modulo_id' => $moduleId, 'local_id' => $localId],
                $this->databaseReportPayload($moduleId, $report, true)
            );
        } catch (\Throwable) {
        }
    }

    /**
     * @param object $row
     * @return array<string,mixed>
     */
    private function databaseReportToArray(object $row): array
    {
        $extra = json_decode((string) ($row->datos_extra ?? ''), true);
        $extra = is_array($extra) ? $extra : [];

        return array_merge($extra, [
            'id' => (string) ($row->local_id ?? $extra['id'] ?? ''),
            'redmine_id' => (string) ($row->redmine_id ?? ''),
            'estado' => (string) ($row->estado ?? ''),
            'estado_redmine' => (string) ($row->estado_redmine ?? ''),
            'tipo' => (string) ($row->tipo ?? ''),
            'prioridad' => (string) ($row->prioridad ?? ''),
            'categoria' => (string) ($row->categoria ?? ''),
            'unidad' => (string) ($row->unidad ?? ''),
            'unidad_solicitante' => (string) ($row->unidad_solicitante ?? ''),
            'solicitante' => (string) ($row->solicitante ?? ''),
            'asunto' => (string) ($row->asunto ?? ''),
            'descripcion' => (string) ($row->descripcion ?? ''),
            'fecha' => $this->databaseDate($row->fecha ?? null),
            'hora' => $this->databaseTime($row->hora ?? null),
            'asignado_a' => (string) ($row->asignado_a ?? ''),
            'asignado_nombre' => (string) ($row->asignado_nombre ?? ''),
            'hora_extra' => !empty($row->hora_extra) ? 'SI' : 'NO',
            'tiempo_estimado' => $row->tiempo_estimado !== null ? (string) $row->tiempo_estimado : '',
            'origen' => (string) ($row->origen ?? ''),
            'procesado_ts' => $row->procesado_at !== null ? (string) $row->procesado_at : (string) ($extra['procesado_ts'] ?? ''),
            'created_at' => $row->creado_at !== null ? (string) $row->creado_at : (string) ($extra['created_at'] ?? ''),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function databaseReportPayload(int $moduleId, array $report, bool $archived): array
    {
        $extra = $report;
        foreach ([
            'id', 'redmine_id', 'estado', 'estado_redmine', 'tipo', 'prioridad', 'categoria', 'unidad',
            'unidad_solicitante', 'solicitante', 'asunto', 'descripcion', 'fecha', 'hora', 'asignado_a',
            'asignado_nombre', 'hora_extra', 'tiempo_estimado', 'origen',
        ] as $key) {
            unset($extra[$key]);
        }

        return [
            'modulo_id' => $moduleId,
            'local_id' => trim((string) ($report['id'] ?? '')),
            'redmine_id' => trim((string) ($report['redmine_id'] ?? '')) ?: null,
            'estado' => trim((string) ($report['estado'] ?? 'pendiente')) ?: null,
            'estado_redmine' => trim((string) ($report['estado_redmine'] ?? '')) ?: null,
            'tipo' => trim((string) ($report['tipo'] ?? '')) ?: null,
            'prioridad' => trim((string) ($report['prioridad'] ?? '')) ?: null,
            'categoria' => trim((string) ($report['categoria'] ?? '')) ?: null,
            'unidad' => trim((string) ($report['unidad'] ?? '')) ?: null,
            'unidad_solicitante' => trim((string) ($report['unidad_solicitante'] ?? '')) ?: null,
            'solicitante' => trim((string) ($report['solicitante'] ?? '')) ?: null,
            'asunto' => trim((string) ($report['asunto'] ?? '')) ?: null,
            'descripcion' => trim((string) ($report['descripcion'] ?? '')) ?: null,
            'fecha' => $this->parseDate($report['fecha'] ?? $report['fecha_inicio'] ?? '') ?: null,
            'hora' => $this->parseTime($report['hora'] ?? ''),
            'asignado_a' => trim((string) ($report['asignado_a'] ?? '')) ?: null,
            'asignado_nombre' => trim((string) ($report['asignado_nombre'] ?? '')) ?: null,
            'hora_extra' => $this->isHoursExtraReport($report) ? 1 : 0,
            'tiempo_estimado' => $this->decimalHours($report['tiempo_estimado'] ?? null),
            'origen' => trim((string) ($report['origen'] ?? '')) ?: null,
            'procesado_at' => $this->parseDateTime($report['procesado_ts'] ?? ''),
            'archivado_por' => $archived ? trim((string) ($report['_archivado_por'] ?? '')) ?: null : null,
            'archivado_at' => $archived ? now() : null,
            'datos_extra' => json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'actualizado_at' => now(),
        ];
    }

    private function databaseModuleId(): ?int
    {
        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', $this->projectKey)->value('id');
            if ($id !== null) {
                return (int) $id;
            }

            DB::table('modulos_nova')->insert([
                'clave_modulo' => $this->projectKey,
                'nombre' => $this->projectName(),
                'descripcion' => '',
                'icono' => '',
                'tipo' => 'native',
                'ruta' => $this->projectKey,
                'entrada' => 'laravel:redmine.native.dashboard',
                'activo' => 1,
                'orden' => 100,
                'creado_at' => now(),
                'actualizado_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function reportsTableAvailable(): bool
    {
        try {
            return Schema::hasTable('modulos_nova') && Schema::hasTable('reportes_redmine');
        } catch (\Throwable) {
            return false;
        }
    }

    private function archiveReport(array $report): void
    {
        $this->saveArchivedReportToDatabase($report);

        if ($this->isHoursExtraReport($report)) {
            $this->syncHoursExtraForReport($report);
            return;
        }

        $date = trim((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? now('America/Santiago')->format('Y-m-d')));
        $dt = date_create($date) ?: now('America/Santiago');
        $year = $dt->format('Y');
        $month = $this->monthName((int) $dt->format('n'));
        $path = $this->dataPath("reportes/$year/$month.json");
        $rows = $this->readList($path);
        $id = (string) ($report['id'] ?? '');
        if ($id !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (string) ($row['id'] ?? '') !== $id));
        }
        $report['_archivado_en'] = now('America/Santiago')->toAtomString();
        $rows[] = $report;
        $this->writeJson($path, $rows);
    }

    private function isHoursExtraReport(array $report): bool
    {
        return in_array(strtolower((string) ($report['hora_extra'] ?? '')), ['si', 'sí', 'sÃ­', '1', 'true'], true);
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function buildIssuePayload(array $report, array $config): array
    {
        $issue = [
            'project_id' => (int) ($config['project_id'] ?? 0),
            'subject' => trim((string) ($report['asunto'] ?? $report['descripcion'] ?? $report['mensaje'] ?? '')),
            'description' => trim((string) ($report['descripcion'] ?? '')),
            'tracker_id' => (int) ($config['tracker_id'] ?? 0),
            'priority_id' => (int) ($config['priority_id'] ?? 0),
            'status_id' => (int) ($config['status_id'] ?? 0),
        ];

        $categoryId = $this->redmineCategoryId((string) ($report['categoria'] ?? ''));
        if ($categoryId > 0) {
            $issue['category_id'] = $categoryId;
        }

        $start = $this->parseDate($report['fecha_inicio'] ?? $report['fecha'] ?? '');
        $due = $this->parseDate($report['fecha_fin'] ?? $report['fecha'] ?? $report['fecha_inicio'] ?? '');
        if ($start !== '') {
            $issue['start_date'] = $start;
        }
        if ($due !== '') {
            $issue['due_date'] = $due;
        }
        if (is_numeric($report['tiempo_estimado'] ?? null)) {
            $issue['estimated_hours'] = (float) $report['tiempo_estimado'];
        }
        if (!empty($report['asignado_a'])) {
            $issue['assigned_to_id'] = $report['asignado_a'];
        }

        $customFields = [];
        foreach ([
            'cf_solicitante' => $report['solicitante'] ?? '',
            'cf_unidad' => $report['unidad'] ?? '',
            'cf_unidad_solicitante' => $report['unidad_solicitante'] ?? $report['unidad'] ?? '',
            'cf_hora_extra' => in_array(strtolower((string) ($report['hora_extra'] ?? '')), ['si', 'sí', '1', 'true'], true) ? '1' : '0',
        ] as $configKey => $value) {
            if (empty($config[$configKey]) || trim((string) $value) === '') {
                continue;
            }
            $customFields[] = ['id' => $config[$configKey], 'value' => $value];
        }
        if ($customFields) {
            $issue['custom_fields'] = $customFields;
        }

        return array_filter($issue, static fn ($value): bool => $value !== '' && $value !== 0 && $value !== null);
    }

    private function redmineCategoryId(string $category): int
    {
        $category = trim($category);
        if ($category === '') {
            return 0;
        }
        if (ctype_digit($category)) {
            return (int) $category;
        }

        $wanted = Str::lower(Str::ascii($category));
        foreach ($this->categories() as $row) {
            $name = trim((string) ($row['nombre'] ?? $row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (Str::lower(Str::ascii($name)) === $wanted) {
                return (int) ($row['id'] ?? 0);
            }
        }

        $matchedName = $this->inferCatalogMatch($category, array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['nombre'] ?? $row['name'] ?? '')),
            $this->categories()
        ))));
        if ($matchedName !== '') {
            $matchedWanted = Str::lower(Str::ascii($matchedName));
            foreach ($this->categories() as $row) {
                $name = trim((string) ($row['nombre'] ?? $row['name'] ?? ''));
                if ($name !== '' && Str::lower(Str::ascii($name)) === $matchedWanted) {
                    return (int) ($row['id'] ?? 0);
                }
            }
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $payload
     * @return array{http_code:int,body:string,error:string}
     */
    private function postRedmineIssue(array $config, array $payload, string $token): array
    {
        $url = trim((string) ($config['platform_url'] ?? ''));
        if ($url === '') {
            return ['http_code' => 0, 'body' => '', 'error' => 'URL no configurada'];
        }
        if (!function_exists('curl_init')) {
            return ['http_code' => 0, 'body' => '', 'error' => 'Extension cURL no disponible'];
        }

        $ch = curl_init($url);
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($token !== '') {
            $headers[] = 'X-Redmine-API-Key: ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        return ['http_code' => $httpCode, 'body' => (string) $body, 'error' => $error];
    }

    /**
     * @return array{http_code:int,body:string,error:string}
     */
    private function getRedmineJson(string $url, string $token): array
    {
        if (!function_exists('curl_init')) {
            return ['http_code' => 0, 'body' => '', 'error' => 'Extension cURL no disponible'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Redmine-API-Key: ' . $token],
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        return ['http_code' => $httpCode, 'body' => (string) $body, 'error' => $error];
    }

    private function redmineBaseUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        $prefix = '';
        $projectsPos = strpos($path, '/projects/');
        if ($projectsPos !== false) {
            $prefix = substr($path, 0, $projectsPos);
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return rtrim($parts['scheme'] . '://' . $parts['host'] . $port . $prefix, '/');
    }

    private function redmineCategoriesUrl(string $url): string
    {
        $baseUrl = $this->redmineBaseUrl($url);

        return $baseUrl === '' ? '' : $baseUrl . '/issue_categories.json';
    }

    private function redmineCustomFieldUrl(string $url, string $fieldId): string
    {
        $baseUrl = $this->redmineBaseUrl($url);

        return $baseUrl === '' ? '' : $baseUrl . '/custom_fields/' . rawurlencode($fieldId) . '.json';
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitRedmineName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return ['', ''];
        }
        $parts = explode(' ', $name, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function normalizePhoneForCompare(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '569') && strlen($digits) === 11) {
            return $digits;
        }
        if (str_starts_with($digits, '9') && strlen($digits) === 9) {
            return '56' . $digits;
        }

        return $digits;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function projectUsersFromNova(): array
    {
        $rows = $this->novaUsers();
        $users = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $project = is_array(data_get($row, 'projects.' . $this->projectKey)) ? data_get($row, 'projects.' . $this->projectKey) : [];
            if ($project === []) {
                continue;
            }

            $redmineId = trim((string) ($project['id'] ?? $row['redmine_id'] ?? ''));
            if ($redmineId === '') {
                continue;
            }

            $users[] = [
                'id' => $redmineId,
                'rut_sin_dv' => trim((string) ($row['rut_sin_dv'] ?? $row['username'] ?? '')),
                'nombre' => trim((string) ($row['name'] ?? '')),
                'apellido' => trim((string) ($row['apellido'] ?? '')),
                'rut' => trim((string) ($row['rut'] ?? '')),
                'numero_celular' => '',
                'telegram_chat_id' => trim((string) data_get($row, 'telegram_settings.chat_id', '')),
                'telegram_source' => trim((string) data_get($row, 'telegram_settings.chat_id', '')) !== '' ? 'nova' : '',
                'api' => trim((string) ($project['api'] ?? $row['api'] ?? '')),
                'rol' => trim((string) ($project['rol'] ?? $row['role'] ?? 'usuario')) ?: 'usuario',
                'password' => (string) ($row['password'] ?? ''),
                'permisos' => is_array($project['permisos'] ?? null) ? $project['permisos'] : [],
                'estado_usuario' => trim((string) ($project['estado_usuario'] ?? $row['status'] ?? 'activo')) ?: 'activo',
                'redmine_membership_id' => $project['redmine_membership_id'] ?? null,
                'redmine_roles' => is_array($project['redmine_roles'] ?? null) ? $project['redmine_roles'] : [],
                '_nova_user_id' => (string) ($row['id'] ?? ''),
            ];
        }

        return $this->hydrateUsersWithNovaTelegram($users);
    }

    /**
     * @param array<int,array<string,mixed>> $projectUsers
     */
    private function saveProjectUsers(array $projectUsers): void
    {
        $novaUsers = $this->novaUsers();

        foreach ($projectUsers as $projectUser) {
            if (!is_array($projectUser)) {
                continue;
            }

            $redmineId = trim((string) ($projectUser['id'] ?? ''));
            if ($redmineId === '') {
                continue;
            }

            $index = $this->findNovaUserIndexForProjectUser($novaUsers, $projectUser);
            $current = $index !== null ? $novaUsers[$index] : [];
            $name = trim((string) ($projectUser['nombre'] ?? $current['name'] ?? ''));
            $lastName = trim((string) ($projectUser['apellido'] ?? $current['apellido'] ?? ''));
            if ($lastName === '' && str_contains($name, ' ')) {
                [$first, $rest] = explode(' ', $name, 2);
                $name = $first;
                $lastName = $rest;
            }

            $row = array_merge($current, [
                'id' => (string) ($current['id'] ?? (string) Str::uuid()),
                'redmine_id' => $redmineId,
                'source' => (string) ($current['source'] ?? $this->projectKey),
                'username' => trim((string) ($current['username'] ?? $projectUser['rut_sin_dv'] ?? $projectUser['rut'] ?? $redmineId)),
                'name' => $name,
                'apellido' => $lastName,
                'rut' => trim((string) ($projectUser['rut'] ?? $current['rut'] ?? '')),
                'rut_sin_dv' => trim((string) ($projectUser['rut_sin_dv'] ?? $current['rut_sin_dv'] ?? '')),
                'role' => $this->normalizeNovaRoleForProject((string) ($current['role'] ?? $projectUser['rol'] ?? 'usuario')),
                'status' => $this->normalizeProjectStatus((string) ($projectUser['estado_usuario'] ?? $current['status'] ?? 'activo')),
                'password' => (string) ($current['password'] ?? $projectUser['password'] ?? ''),
                'api' => trim((string) ($current['api'] ?? $projectUser['api'] ?? '')),
            ]);

            $chatId = trim((string) ($projectUser['telegram_chat_id'] ?? data_get($current, 'telegram_settings.chat_id', '')));
            if ($chatId !== '') {
                $row['telegram_settings'] = array_merge(is_array($row['telegram_settings'] ?? null) ? $row['telegram_settings'] : [], [
                    'chat_id' => $chatId,
                    'updated_at' => (string) data_get($row, 'telegram_settings.updated_at', now('America/Santiago')->toAtomString()),
                ]);
            }

            $projects = is_array($row['projects'] ?? null) ? $row['projects'] : [];
            $projects[$this->projectKey] = [
                'id' => $redmineId,
                'rol' => trim((string) ($projectUser['rol'] ?? 'usuario')) ?: 'usuario',
                'estado_usuario' => $this->normalizeProjectStatus((string) ($projectUser['estado_usuario'] ?? 'activo')),
                'api' => trim((string) ($projectUser['api'] ?? '')),
                'permisos' => is_array($projectUser['permisos'] ?? null) ? $projectUser['permisos'] : [],
                'redmine_membership_id' => $projectUser['redmine_membership_id'] ?? null,
                'redmine_roles' => is_array($projectUser['redmine_roles'] ?? null) ? $projectUser['redmine_roles'] : [],
            ];
            $row['projects'] = $projects;

            if ($index === null) {
                $novaUsers[] = $row;
            } else {
                $novaUsers[$index] = $row;
            }
        }

        $this->writeNovaUsers($novaUsers);
    }

    private function findNovaUserIndexForProjectUser(array $novaUsers, array $projectUser): ?int
    {
        $redmineId = $this->normalizeUnifiedIdentity((string) ($projectUser['id'] ?? ''));
        $needles = array_filter(array_map([$this, 'normalizeUnifiedIdentity'], [
            $projectUser['rut'] ?? '',
            $projectUser['rut_sin_dv'] ?? '',
        ]));

        foreach ($novaUsers as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $project = is_array(data_get($row, 'projects.' . $this->projectKey)) ? data_get($row, 'projects.' . $this->projectKey) : [];
            $candidateRedmineId = $this->normalizeUnifiedIdentity((string) ($project['id'] ?? $row['redmine_id'] ?? ''));
            if ($redmineId !== '' && $candidateRedmineId === $redmineId) {
                return $index;
            }

            $candidates = array_filter(array_map([$this, 'normalizeUnifiedIdentity'], [
                $row['rut'] ?? '',
                $row['rut_sin_dv'] ?? '',
                $row['username'] ?? '',
            ]));
            if ($needles !== [] && array_intersect($needles, $candidates) !== []) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function novaUsers(): array
    {
        $fileUsers = $this->novaUsersFromFile();
        if (!$this->novaUsersTableAvailable()) {
            return $fileUsers;
        }

        $databaseUsers = $this->novaUsersFromDatabase($fileUsers);

        return $databaseUsers !== [] ? $databaseUsers : $fileUsers;
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function writeNovaUsers(array $users): void
    {
        if ($this->novaUsersTableAvailable()) {
            $this->writeNovaUsersToDatabase($users);
        }

        File::ensureDirectoryExists(dirname($this->novaUsersPath()));
        file_put_contents($this->novaUsersPath(), json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($this->novaUsersPath(), 0666);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function novaUsersFromFile(): array
    {
        $rows = json_decode((string) @file_get_contents($this->novaUsersPath()), true);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param array<int,array<string,mixed>> $fileUsers
     * @return array<int,array<string,mixed>>
     */
    private function novaUsersFromDatabase(array $fileUsers): array
    {
        $fileByIdentity = [];
        foreach ($fileUsers as $fileUser) {
            foreach ([
                $fileUser['redmine_id'] ?? '',
                data_get($fileUser, 'projects.' . $this->projectKey . '.id', ''),
                $fileUser['rut'] ?? '',
                $fileUser['rut_sin_dv'] ?? '',
                $fileUser['username'] ?? '',
            ] as $identity) {
                $identity = $this->normalizeUnifiedIdentity((string) $identity);
                if ($identity !== '' && !isset($fileByIdentity[$identity])) {
                    $fileByIdentity[$identity] = $fileUser;
                }
            }
        }

        try {
            return NovaUser::query()
                ->orderBy('nombre')
                ->orderBy('apellido')
                ->get()
                ->map(function (NovaUser $user) use ($fileByIdentity): array {
                    $redmineId = trim((string) $user->redmine_id);
                    $rut = trim((string) $user->rut);
                    $username = trim((string) $user->usuario);
                    $current = [];

                    foreach ([$redmineId, $rut, $username] as $identity) {
                        $identity = $this->normalizeUnifiedIdentity($identity);
                        if ($identity !== '' && isset($fileByIdentity[$identity])) {
                            $current = $fileByIdentity[$identity];
                            break;
                        }
                    }

                    $project = is_array(data_get($current, 'projects.' . $this->projectKey))
                        ? data_get($current, 'projects.' . $this->projectKey)
                        : [];
                    $projectRedmineId = $redmineId !== '' ? $redmineId : trim((string) ($project['id'] ?? ''));

                    return array_merge($current, [
                        'id' => (string) ($current['id'] ?? $user->uuid),
                        'redmine_id' => $projectRedmineId,
                        'username' => $username,
                        'name' => trim((string) $user->nombre),
                        'apellido' => trim((string) $user->apellido),
                        'rut' => $rut,
                        'rut_sin_dv' => trim((string) ($current['rut_sin_dv'] ?? $username)),
                        'core_user' => trim((string) ($user->usuario_core ?? '')),
                        'role' => $this->normalizeNovaRoleForProject((string) $user->rol),
                        'status' => $this->normalizeProjectStatus((string) $user->estado),
                        'password' => (string) ($user->password ?? $current['password'] ?? ''),
                        'projects' => array_merge(is_array($current['projects'] ?? null) ? $current['projects'] : [], [
                            $this->projectKey => array_merge($project, [
                                'id' => $projectRedmineId,
                                'rol' => trim((string) ($project['rol'] ?? $user->rol ?? 'usuario')) ?: 'usuario',
                                'estado_usuario' => $this->normalizeProjectStatus((string) ($user->estado ?? 'activo')),
                                'api' => trim((string) ($project['api'] ?? $current['api'] ?? '')),
                                'permisos' => is_array($project['permisos'] ?? null) ? $project['permisos'] : [],
                                'redmine_membership_id' => $project['redmine_membership_id'] ?? null,
                                'redmine_roles' => is_array($project['redmine_roles'] ?? null) ? $project['redmine_roles'] : [],
                            ]),
                        ]),
                    ]);
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function writeNovaUsersToDatabase(array $users): void
    {
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $project = is_array(data_get($user, 'projects.' . $this->projectKey)) ? data_get($user, 'projects.' . $this->projectKey) : [];
            $redmineId = trim((string) ($project['id'] ?? $user['redmine_id'] ?? ''));
            $username = trim((string) ($user['username'] ?? $user['rut_sin_dv'] ?? $user['rut'] ?? $redmineId));
            $name = trim((string) ($user['name'] ?? $user['nombre'] ?? ''));
            $lastName = trim((string) ($user['apellido'] ?? ''));

            if ($username === '' || $name === '') {
                continue;
            }

            if ($lastName === '' && str_contains($name, ' ')) {
                [$first, $rest] = explode(' ', $name, 2);
                $name = $first;
                $lastName = $rest;
            }

            try {
                NovaUser::query()->updateOrCreate(
                    ['usuario' => $username],
                    [
                        'uuid' => (string) ($user['id'] ?? Str::uuid()),
                        'rut' => trim((string) ($user['rut'] ?? '')) ?: null,
                        'redmine_id' => $redmineId !== '' ? $redmineId : null,
                        'nombre' => $name,
                        'apellido' => $lastName,
                        'email' => trim((string) ($user['email'] ?? '')) ?: null,
                        'rol' => $this->normalizeNovaRoleForProject((string) ($user['role'] ?? $user['rol'] ?? 'usuario')),
                        'estado' => $this->normalizeProjectStatus((string) ($user['status'] ?? $user['estado_usuario'] ?? 'activo')),
                        'password' => (string) ($user['password'] ?? ''),
                        'usuario_core' => trim((string) ($user['core_user'] ?? '')) ?: null,
                    ]
                );
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function novaUsersTableAvailable(): bool
    {
        try {
            return Schema::hasTable('usuarios_nova');
        } catch (\Throwable) {
            return false;
        }
    }

    private function novaUsersPath(): string
    {
        return function_exists('storage_path')
            ? storage_path('app/nova/users.json')
            : dirname($this->basePath()) . '/storage/app/nova/users.json';
    }

    private function normalizeUnifiedIdentity(string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9a-z]/i', '', $value));
    }

    private function normalizeProjectStatus(string $status): string
    {
        return in_array(strtolower(trim($status)), ['baneado', 'bloqueado', 'inactivo'], true) ? 'baneado' : 'activo';
    }

    private function normalizeNovaRoleForProject(string $role): string
    {
        return in_array(strtolower(trim($role)), ['admin', 'administrador', 'gestor', 'root'], true) ? 'admin' : 'usuario';
    }

    /**
     * @param array<int,array<string,mixed>> $users
     * @return array<int,array<string,mixed>>
     */
    private function hydrateUsersWithNovaTelegram(array $users): array
    {
        $novaByRedmineId = $this->novaTelegramByRedmineId();
        if ($novaByRedmineId === []) {
            return $users;
        }

        foreach ($users as &$user) {
            $chatId = trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', '')));
            if ($chatId !== '') {
                continue;
            }

            $redmineId = trim((string) ($user['id'] ?? ''));
            if ($redmineId === '' || !isset($novaByRedmineId[$redmineId])) {
                continue;
            }

            $user['telegram_chat_id'] = $novaByRedmineId[$redmineId];
            $user['telegram_source'] = 'nova';
        }
        unset($user);

        return $users;
    }

    /**
     * @return array<string,string>
     */
    private function novaTelegramByRedmineId(): array
    {
        $path = function_exists('storage_path')
            ? storage_path('app/nova/users.json')
            : $this->basePath() . '/../storage/app/nova/users.json';
        $rows = json_decode((string) @file_get_contents($path), true);
        if (!is_array($rows)) {
            return [];
        }

        $mapped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $redmineId = trim((string) ($row['redmine_id'] ?? ''));
            $chatId = trim((string) data_get($row, 'telegram_settings.chat_id', ''));
            if ($redmineId !== '' && $chatId !== '') {
                $mapped[$redmineId] = $chatId;
            }
        }

        return $mapped;
    }

    /**
     * @param string[] $items
     */
    private function inferCatalogMatch(string $text, array $items): string
    {
        $target = $this->normalizeTelegramReportText($text);
        if ($target === '') {
            return '';
        }

        $targetTokens = $this->catalogMatchTokens($target);
        $hints = $this->catalogMatchHints($target);
        $bestItem = '';
        $bestScore = 0;

        foreach ($items as $item) {
            $item = trim($item);
            $normalized = $this->normalizeTelegramReportText($item);
            if ($normalized === '') {
                continue;
            }
            if ($normalized === $target || preg_match('/\b' . preg_quote($normalized, '/') . '\b/u', $target)) {
                return $item;
            }
            if (strlen($normalized) >= 4 && (str_contains($target, $normalized) || str_contains($normalized, $target))) {
                return $item;
            }

            $score = $this->catalogMatchScore($targetTokens, $hints, $this->catalogMatchTokens($normalized), $normalized);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestItem = $item;
            }
        }

        return $bestScore >= 22 ? $bestItem : '';
    }

    private function normalizeTelegramReportText(string $text): string
    {
        $text = Str::ascii(Str::lower($text));
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * @return array<int,string>
     */
    private function catalogMatchTokens(string $text): array
    {
        $stopWords = array_fill_keys(['de', 'del', 'la', 'las', 'los', 'el', 'en', 'para', 'por', 'con', 'sin', 'un', 'una', 'y', 'o', 'no', 'se', 'a'], true);
        $tokens = [];
        foreach (explode(' ', $this->normalizeTelegramReportText($text)) as $token) {
            if (strlen($token) < 3 || isset($stopWords[$token])) {
                continue;
            }
            $tokens[] = $this->catalogTokenStem($token);
        }

        return array_values(array_unique($tokens));
    }

    private function catalogTokenStem(string $token): string
    {
        foreach (['ciones', 'cion', 'oras', 'ores', 'icos', 'icas', 'ados', 'adas', 'es', 's'] as $suffix) {
            if (strlen($token) > strlen($suffix) + 3 && str_ends_with($token, $suffix)) {
                return substr($token, 0, -strlen($suffix));
            }
        }

        return $token;
    }

    /**
     * @return array<int,string>
     */
    private function catalogMatchHints(string $target): array
    {
        $rules = [
            'impresora' => ['impresora', 'impresion', 'printer', 'toner'],
            'problem' => ['problema', 'problemas', 'no funciona', 'no imprime'],
            'falla' => ['falla', 'fallando', 'lento', 'lenta', 'malo', 'mala'],
            'anexo' => ['anexo', 'telefono', 'telefonico'],
            'telefono' => ['telefono', 'telefonico', 'celular', 'fono'],
            'correo' => ['correo', 'email', 'mail', 'outlook'],
            'cuenta' => ['cuenta', 'usuario', 'clave', 'password', 'contrasena'],
            'pc' => ['pc', 'equipo', 'computador', 'notebook'],
            'red' => ['red', 'internet', 'wifi', 'conexion', 'vlan', 'vpn'],
            'camara' => ['camara', 'webcam'],
            'recuper' => ['recuperacion', 'recuperar', 'recupero'],
        ];

        $hints = [];
        foreach ($rules as $hint => $needles) {
            foreach ($needles as $needle) {
                if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $target)) {
                    $hints[] = $hint;
                    break;
                }
            }
        }

        return array_values(array_unique($hints));
    }

    /**
     * @param array<int,string> $targetTokens
     * @param array<int,string> $hints
     * @param array<int,string> $itemTokens
     */
    private function catalogMatchScore(array $targetTokens, array $hints, array $itemTokens, string $normalizedItem): int
    {
        if ($targetTokens === [] || $itemTokens === []) {
            return 0;
        }

        $score = 0;
        foreach ($targetTokens as $targetToken) {
            foreach ($itemTokens as $itemToken) {
                if ($targetToken === $itemToken) {
                    $score += 18;
                    continue;
                }
                if (strlen($targetToken) >= 4 && strlen($itemToken) >= 4 && (str_starts_with($targetToken, $itemToken) || str_starts_with($itemToken, $targetToken))) {
                    $score += 12;
                }
            }
        }

        foreach ($hints as $hint) {
            if (str_contains($normalizedItem, $hint)) {
                $score += 22;
            }
        }

        if (count($itemTokens) > 4) {
            $score -= min(12, count($itemTokens) - 4);
        }

        return $score;
    }

    /**
     * @param array<string,mixed> $telegramUser
     */
    private function telegramUserDisplayName(array $telegramUser): string
    {
        $first = trim((string) ($telegramUser['name'] ?? ''));
        $last = trim((string) ($telegramUser['apellido'] ?? ''));
        $name = $this->joinPersonName($first, $last);
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($telegramUser['username'] ?? ''));
    }

    /**
     * @param array<string,mixed> $telegramUser
     * @return array{id:string,name:string}
     */
    private function telegramProjectAssignee(array $telegramUser, string $chatId): array
    {
        $project = is_array(data_get($telegramUser, 'projects.' . $this->projectKey))
            ? data_get($telegramUser, 'projects.' . $this->projectKey)
            : [];
        $projectId = trim((string) ($project['id'] ?? ''));
        if ($projectId !== '') {
            return [
                'id' => $projectId,
                'name' => $this->telegramUserDisplayName($telegramUser),
            ];
        }

        $legacyId = trim((string) data_get($telegramUser, 'redmine_tic_user.id', ''));
        if ($legacyId !== '') {
            return [
                'id' => $legacyId,
                'name' => $this->telegramUserDisplayName($telegramUser),
            ];
        }

        if ($chatId !== '') {
            foreach ($this->users() as $user) {
                $candidateChatId = trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', '')));
                if ($candidateChatId !== $chatId) {
                    continue;
                }

                return [
                    'id' => trim((string) ($user['id'] ?? '')),
                    'name' => $this->joinPersonName((string) ($user['nombre'] ?? ''), (string) ($user['apellido'] ?? '')),
                ];
            }
        }

        return ['id' => '', 'name' => ''];
    }

    private function joinPersonName(string $first, string $last): string
    {
        $first = trim($first);
        $last = trim($last);
        if ($first === '') {
            return $last;
        }
        if ($last === '') {
            return $first;
        }

        $firstNormalized = $this->normalizeTelegramReportText($first);
        $lastNormalized = $this->normalizeTelegramReportText($last);
        if ($lastNormalized !== '' && str_contains($firstNormalized, $lastNormalized)) {
            return $first;
        }

        return trim($first . ' ' . $last);
    }

    private function userApiToken(?string $userId): string
    {
        if (!$userId) {
            return '';
        }
        foreach ($this->users() as $user) {
            if ((string) ($user['id'] ?? '') === (string) $userId) {
                return trim((string) ($user['api'] ?? ''));
            }
        }

        return '';
    }

    private function redmineIssuesUrl(string $url): string
    {
        $baseUrl = $this->redmineBaseUrl($url);

        return $baseUrl === '' ? '' : $baseUrl . '/issues.json';
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,array{value:string,label:string}>
     */
    private function redmineStatusOptions(array $config): array
    {
        $options = [
            ['value' => 'open', 'label' => 'Abiertos'],
            ['value' => 'closed', 'label' => 'Cerrados'],
            ['value' => 'all', 'label' => 'Todos'],
        ];

        foreach ((array) ($config['estados'] ?? []) as $status) {
            if (!is_array($status)) {
                continue;
            }
            $id = trim((string) ($status['id'] ?? ''));
            $label = trim((string) ($status['nombre'] ?? $status['name'] ?? ''));
            if ($id === '' || $label === '') {
                continue;
            }
            $options[] = ['value' => $id, 'label' => $label];
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,array{value:string,label:string}>
     */
    private function redmineConfigOptions(array $config, string $key): array
    {
        $options = [['value' => 'all', 'label' => 'Todos']];
        foreach ((array) ($config[$key] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            $label = trim((string) ($item['nombre'] ?? $item['name'] ?? ''));
            if ($id === '' || $label === '') {
                continue;
            }
            $options[] = ['value' => $id, 'label' => $label];
        }

        return $options;
    }

    /**
     * @param array<int,array{value:string,label:string}> $statusOptions
     */
    private function normalizeRedmineStatusSelection(string $value, array $statusOptions): string
    {
        $value = trim($value);
        $allowed = array_column($statusOptions, 'value');

        return in_array($value, $allowed, true) ? $value : 'open';
    }

    /**
     * @param array<int,array{value:string,label:string}> $options
     */
    private function normalizeRedmineOptionSelection(string $value, array $options): string
    {
        $value = trim($value);
        $allowed = array_column($options, 'value');

        return in_array($value, $allowed, true) ? $value : 'all';
    }

    private function redmineStatusQueryValue(string $statusSelection): string
    {
        return match ($statusSelection) {
            'all' => '*',
            'closed' => 'c',
            'open' => 'o',
            default => $statusSelection,
        };
    }

    private function isClosedIssueStatus(string $statusName): bool
    {
        $statusKey = strtolower(trim($statusName));
        foreach (['cerrad', 'closed', 'resuelt', 'resolved', 'finaliz', 'complet', 'terminad'] as $needle) {
            if (str_contains($statusKey, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,string> $params
     * @return array{rows:array<int,array<string,mixed>>}|array{error:string}
     */
    private function fetchRedmineIssues(string $issuesUrl, string $token, array $params): array
    {
        $rows = [];
        $limit = 100;
        $offset = 0;
        $total = null;

        do {
            $query = array_merge($params, [
                'limit' => (string) $limit,
                'offset' => (string) $offset,
            ]);
            $url = $issuesUrl . '?' . http_build_query($query);
            $response = $this->getRedmineJson($url, $token);
            if ($response['error'] !== '') {
                return ['error' => $response['error']];
            }
            if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
                return ['error' => 'HTTP ' . $response['http_code'] . ' - ' . $response['body']];
            }

            $payload = json_decode($response['body'], true);
            if (!is_array($payload)) {
                return ['error' => 'Respuesta Redmine invalida.'];
            }

            foreach ((array) ($payload['issues'] ?? []) as $issue) {
                if (is_array($issue)) {
                    $rows[] = $issue;
                }
            }
            $total = (int) ($payload['total_count'] ?? count($rows));
            $offset += $limit;
        } while ($offset < $total);

        return ['rows' => $rows];
    }

    /**
     * @param array<string,string> $filters
     * @return array<string,mixed>
     */
    private function emptyStatistics(array $filters): array
    {
        return [
            'total' => 0,
            'by_status' => [],
            'by_category' => [],
            'category_options' => [],
            'by_unit' => [],
            'by_assignee' => [],
            'by_priority' => [],
            'by_tracker' => [],
            'by_date' => [],
            'by_month' => [],
            'max_daily' => 0,
            'max_monthly' => 0,
            'filters' => $filters,
            'updated_at' => now('America/Santiago')->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function redmineApiStatisticsCache(): array
    {
        $cache = $this->readJsonMap($this->dataPath('estadisticas_api_cache.json'));
        if ((int) ($cache['schema_version'] ?? 0) < 3) {
            return [];
        }

        return is_array($cache['stats'] ?? null) ? $cache['stats'] : [];
    }

    /**
     * @param array<string,mixed> $stats
     */
    private function saveRedmineApiStatisticsCache(array $stats): void
    {
        $this->writeJson($this->dataPath('estadisticas_api_cache.json'), [
            'schema_version' => 3,
            'saved_at' => now('America/Santiago')->format('Y-m-d H:i'),
            'stats' => $this->normalizeRedmineApiStatistics($stats),
        ]);
    }

    /**
     * @param array<string,mixed> $stats
     * @return array<string,mixed>
     */
    private function normalizeRedmineApiStatistics(array $stats): array
    {
        $stats['by_unit'] = $this->normalizeRedmineUnitCounts((array) ($stats['by_unit'] ?? []));

        return $stats;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $config
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function buildRedmineApiStatisticsFromRows(array $rows, array $config, array $filters, bool $cached = false): array
    {
        $categorySelection = $this->normalizeRedmineCategorySelection((array) ($filters['category_scope'] ?? []));
        $categoryFilterActive = filter_var($filters['category_filter'] ?? false, FILTER_VALIDATE_BOOL);
        $filteredRows = $categoryFilterActive ? $this->filterRedmineRowsByCategories($rows, $categorySelection) : array_values($rows);
        $byDate = $this->countsByDate($filteredRows);
        $byMonth = $this->countsByMonth($filteredRows);

        $filters['category_scope'] = $categorySelection;
        $filters['category_filter'] = $categoryFilterActive ? '1' : '';

        return [
            'source' => 'redmine-api',
            'fetched' => true,
            'cached' => $cached,
            'error' => '',
            'total' => count($filteredRows),
            'by_status' => $this->countsByRelation($filteredRows, 'status'),
            'by_category' => $this->countsByRelation($filteredRows, 'category'),
            'category_options' => $this->countsByRelation($rows, 'category'),
            'by_unit' => $this->countsByRedmineUnitField($filteredRows, (string) ($config['cf_unidad_solicitante'] ?? $config['cf_unidad'] ?? '')),
            'by_assignee' => $this->countsByRelation($filteredRows, 'assigned_to'),
            'by_priority' => $this->countsByRelation($filteredRows, 'priority'),
            'by_tracker' => $this->countsByRelation($filteredRows, 'tracker'),
            'by_date' => $byDate,
            'by_month' => $byMonth,
            'max_daily' => $byDate ? max($byDate) : 0,
            'max_monthly' => $byMonth ? max($byMonth) : 0,
            'filters' => $filters,
            'raw_rows' => array_values($rows),
            'updated_at' => now('America/Santiago')->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param array<int,mixed> $selection
     * @return array<int,string>
     */
    private function normalizeRedmineCategorySelection(array $selection): array
    {
        $normalized = [];
        foreach ($selection as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $categorySelection
     * @return array<int,array<string,mixed>>
     */
    private function filterRedmineRowsByCategories(array $rows, array $categorySelection): array
    {
        if ($categorySelection === []) {
            return [];
        }

        $selected = array_fill_keys(array_map(static fn (string $name): string => Str::lower(Str::ascii($name)), $categorySelection), true);

        return array_values(array_filter($rows, function (array $row) use ($selected): bool {
            $category = $this->redmineRelationName($row, 'category');
            $key = Str::lower(Str::ascii($category));

            return isset($selected[$key]);
        }));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function redmineRelationName(array $row, string $field): string
    {
        $value = Arr::get($row, $field . '.name', Arr::get($row, $field . '.value', ''));
        $value = trim((string) $value);

        return $value !== '' ? $value : 'Sin dato';
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function appendSendLog(array $entry): void
    {
        $path = $this->dataPath('envio_errores.log');
        $this->assertInsideDataRoot($path);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function appendActivityLog(string $event, array $context = []): void
    {
        $path = $this->dataPath('security.log');
        $this->assertInsideDataRoot($path);
        File::ensureDirectoryExists(dirname($path));
        $entry = [
            'ts' => now('America/Santiago')->format('Y-m-d H:i:s'),
            'event' => $event,
            'context' => $context,
        ];
        try {
            @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
        }
    }

    private function parseDate($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception) {
            return '';
        }
    }

    private function parseTime($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $value)) {
            $parts = explode(':', $value);
            $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
            $minute = max(0, min(59, (int) ($parts[1] ?? 0)));
            $second = max(0, min(59, (int) ($parts[2] ?? 0)));

            return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        }

        try {
            return (new \DateTimeImmutable($value))->format('H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function parseDateTime($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function decimalHours($value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $value, $matches)) {
            return round((float) $matches[1] + ((float) $matches[2] / 60), 2);
        }

        return null;
    }

    private function databaseDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->parseDate($value);
    }

    private function databaseTime($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $time = $this->parseTime($value);

        return $time !== null ? substr($time, 0, 5) : '';
    }

    private function assertInsideDataRoot(string $path): void
    {
        $root = realpath($this->dataPath('')) ?: $this->dataPath('');
        $targetDir = realpath(dirname($path)) ?: dirname($path);
        if (!str_starts_with(strtolower($targetDir), strtolower(rtrim($root, DIRECTORY_SEPARATOR)))) {
            throw new \RuntimeException('Ruta Redmine fuera del directorio de datos NOVA.');
        }
    }

    private function monthName(int $month): string
    {
        return [
            1 => 'enero',
            2 => 'febrero',
            3 => 'marzo',
            4 => 'abril',
            5 => 'mayo',
            6 => 'junio',
            7 => 'julio',
            8 => 'agosto',
            9 => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre',
        ][$month] ?? 'enero';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByField(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = trim((string) Arr::get($row, $field, ''));
            $value = $value !== '' ? $value : 'Sin dato';
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByRelation(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = Arr::get($row, $field . '.name', Arr::get($row, $field . '.value', ''));
            $value = trim((string) $value);
            $value = $value !== '' ? $value : 'Sin dato';
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByRedmineCustomField(array $rows, string $fieldId): array
    {
        if ($fieldId === '') {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['custom_fields'] ?? []) as $field) {
                if (!is_array($field) || (string) ($field['id'] ?? '') !== $fieldId) {
                    continue;
                }
                $value = $field['value'] ?? '';
                if (is_array($value)) {
                    $value = implode(', ', array_filter(array_map('strval', $value)));
                }
                $value = trim((string) $value);
                $value = $value !== '' ? $value : 'Sin dato';
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByRedmineUnitField(array $rows, string $fieldId): array
    {
        return $this->normalizeRedmineUnitCounts($this->countsByRedmineCustomField($rows, $fieldId));
    }

    /**
     * @param array<string,int|numeric-string> $counts
     * @return array<string,int>
     */
    private function normalizeRedmineUnitCounts(array $counts): array
    {
        $normalized = [];
        $labels = [];

        foreach ($counts as $name => $count) {
            $label = $this->normalizeRedmineUnitLabel((string) $name);
            if ($label === '' || (int) $count <= 0) {
                continue;
            }

            $key = Str::lower(Str::ascii($label));
            $normalized[$key] = ($normalized[$key] ?? 0) + (int) $count;
            if (!isset($labels[$key]) || $this->labelScore($label) > $this->labelScore($labels[$key])) {
                $labels[$key] = $label;
            }
        }

        $out = [];
        foreach ($normalized as $key => $count) {
            $out[$labels[$key] ?? $key] = $count;
        }
        arsort($out);

        return $out;
    }

    private function normalizeRedmineUnitLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        if ($label === '') {
            return '';
        }

        $plain = Str::lower(Str::ascii($label));
        if (in_array($plain, ['sin dato', 'sin datos', 'n/a', 'na', 'null', '-'], true)) {
            return '';
        }

        return $this->titleLabel($label);
    }

    private function titleLabel(string $label): string
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);

        return function_exists('mb_convert_case')
            ? mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8')
            : ucwords($lower);
    }

    private function labelScore(string $label): int
    {
        return preg_match('/[^\x00-\x7F]/', $label) ? 2 : 1;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByDate(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $date = $this->normalizeDateKey((string) ($row['fecha_inicio'] ?? $row['fecha'] ?? $row['start_date'] ?? $row['due_date'] ?? $row['created_on'] ?? ''));
            if ($date === '') {
                continue;
            }
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByMonth(array $rows): array
    {
        $counts = [];
        foreach ($this->countsByDate($rows) as $date => $total) {
            $month = substr($date, 0, 7);
            if ($month === '') {
                continue;
            }
            $counts[$month] = ($counts[$month] ?? 0) + $total;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:?\DateTimeImmutable,1:?\DateTimeImmutable}
     */
    private function statisticsDateRange(array $filters): array
    {
        $from = $this->parseFlexibleDate((string) ($filters['desde'] ?? ''));
        $to = $this->parseFlexibleDate((string) ($filters['hasta'] ?? ''));
        if (!$from && !$to) {
            $today = now('America/Santiago');
            $from = new \DateTimeImmutable($today->copy()->startOfMonth()->format('Y-m-d'), new \DateTimeZone('America/Santiago'));
            $to = new \DateTimeImmutable($today->copy()->endOfMonth()->format('Y-m-d'), new \DateTimeZone('America/Santiago'));
        }

        if ($from && $to && $from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     * @return array<int,array<string,mixed>>
     */
    private function filterReportsByDateRange(array $reports, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        if (!$from && !$to) {
            return $reports;
        }

        return array_values(array_filter($reports, function (array $report) use ($from, $to): bool {
            $date = $this->parseFlexibleDate((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? $report['start_date'] ?? $report['due_date'] ?? $report['created_on'] ?? ''));
            if (!$date) {
                return false;
            }
            if ($from && $date < $from) {
                return false;
            }
            if ($to && $date > $to) {
                return false;
            }

            return true;
        }));
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     * @param string[] $states
     */
    private function countByState(array $reports, array $states): int
    {
        return count(array_filter($reports, static function (array $report) use ($states): bool {
            $state = strtolower(trim((string) Arr::get($report, 'estado', '')));

            return in_array($state, $states, true);
        }));
    }

    /**
     * @param array<int,array<string,mixed>> $groups
     * @return array<int,array<string,mixed>>
     */
    private function deduplicateHoursGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            $reports = (array) ($group['reports'] ?? []);
            if ($reports === []) {
                continue;
            }
            $groupDate = $this->normalizeDateKey((string) ($group['fecha'] ?? ''));
            foreach ($reports as $report) {
                if (!is_array($report)) {
                    continue;
                }
                $date = $this->normalizeDateKey((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? $groupDate));
                if ($date === '') {
                    continue;
                }
                if (!isset($out[$date])) {
                    $out[$date] = [
                        'fecha' => $date,
                        'hora_inicio' => (string) ($group['hora_inicio'] ?? ''),
                        'hora_fin' => (string) ($group['hora_fin'] ?? ''),
                        '_source_file' => (string) ($group['_source_file'] ?? ''),
                        'reports' => [],
                        '_order' => [],
                    ];
                }
                if ($groupDate === $date) {
                    $out[$date]['hora_inicio'] = (string) ($group['hora_inicio'] ?? $out[$date]['hora_inicio']);
                    $out[$date]['hora_fin'] = (string) ($group['hora_fin'] ?? $out[$date]['hora_fin']);
                    $out[$date]['_source_file'] = (string) ($group['_source_file'] ?? $out[$date]['_source_file']);
                }
                $key = (string) ($report['id'] ?? (($report['numero'] ?? '') . '|' . ($report['hora'] ?? '') . '|' . ($report['asunto'] ?? '')));
                if ($key === '') {
                    continue;
                }
                if (!isset($out[$date]['reports'][$key])) {
                    $out[$date]['reports'][$key] = $report;
                    $out[$date]['_order'][] = $key;
                    continue;
                }
                $out[$date]['reports'][$key] = array_merge($out[$date]['reports'][$key], array_filter($report, static fn ($value): bool => $value !== null && $value !== ''));
            }
        }

        foreach ($out as &$group) {
            $group['reports'] = array_values(array_intersect_key($group['reports'], array_flip($group['_order'])));
            unset($group['_order']);
        }
        unset($group);

        return array_values($out);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function historyRowKey(array $row, string $fallback): string
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '') {
            return 'id:' . $id;
        }

        $redmineId = trim((string) ($row['redmine_id'] ?? ''));
        if ($redmineId !== '') {
            return 'redmine:' . $redmineId;
        }

        return 'fallback:' . $fallback;
    }

    private function normalizeDateKey(string $date): string
    {
        $parsed = $this->parseFlexibleDate($date);

        return $parsed ? $parsed->format('Y-m-d') : '';
    }

    private function parseFlexibleDate(string $date): ?\DateTimeImmutable
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $date, new \DateTimeZone('America/Santiago'));
            if ($parsed) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function monthOptions(): array
    {
        return [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];
    }

    private function selectedMonth($value, bool $hasExplicitFilters = false): string
    {
        if ($value === null) {
            return $hasExplicitFilters ? '' : now('America/Santiago')->format('n');
        }
        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value >= 1 && (int) $value <= 12 ? (string) (int) $value : '';
    }

    private function selectedYear($value, bool $hasExplicitFilters = false): string
    {
        if ($value === null) {
            return $hasExplicitFilters ? '' : now('America/Santiago')->format('Y');
        }
        $value = trim((string) $value);

        return preg_match('/^\d{4}$/', $value) ? $value : '';
    }

    private function minutesDiff(string $start, string $end): ?int
    {
        if (trim($start) === '' || trim($end) === '') {
            return null;
        }
        $startTime = \DateTimeImmutable::createFromFormat('H:i', substr($start, 0, 5)) ?: \DateTimeImmutable::createFromFormat('H:i:s', $start);
        $endTime = \DateTimeImmutable::createFromFormat('H:i', substr($end, 0, 5)) ?: \DateTimeImmutable::createFromFormat('H:i:s', $end);
        if (!$startTime || !$endTime || $endTime <= $startTime) {
            return null;
        }

        return (int) round(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60);
    }

    private function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        return str_pad((string) floor($minutes / 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    private function timestampFromValue($value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     * @return array<int,array<string,mixed>>
     */
    private function filterReportsByDashboardStatus(array $reports, string $filter): array
    {
        $states = match ($filter) {
            'pendientes' => ['pendiente'],
            'procesados' => ['procesado', 'procesada'],
            'errores' => ['error', 'fallido', 'fallida'],
            default => [],
        };

        if ($states === []) {
            return array_values($reports);
        }

        return array_values(array_filter($reports, static function (array $report) use ($states): bool {
            $state = strtolower(trim((string) Arr::get($report, 'estado', '')));

            return in_array($state, $states, true);
        }));
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     * @param array<string,mixed> $user
     * @return array<int,array<string,mixed>>
     */
    private function filterReportsByUserScope(array $reports, array $user, string $scopeKey): array
    {
        if ($user === []) {
            return array_values($reports);
        }

        if ($this->scopeForUser($user, $scopeKey) === 'todos') {
            return array_values($reports);
        }

        $userId = trim((string) ($user['id'] ?? data_get($user, 'legacy.id', '')));
        if ($userId === '') {
            return [];
        }

        $candidateNames = array_values(array_unique(array_filter([
            trim((string) (($user['name'] ?? '') . ' ' . ($user['apellido'] ?? ''))),
            trim((string) data_get($user, 'legacy.nombre', '')),
            trim((string) ((data_get($user, 'legacy.nombre', '') ?: '') . ' ' . (data_get($user, 'legacy.apellido', '') ?: ''))),
        ])));

        return array_values(array_filter($reports, function (array $report) use ($userId, $candidateNames, $scopeKey): bool {
            if ((string) ($report['asignado_a'] ?? '') === $userId) {
                return true;
            }

            $assignedName = trim((string) ($report['core_usuario_asignado'] ?? $report['asignado_nombre'] ?? ''));
            if ($scopeKey === 'mensajes' && trim((string) ($report['asignado_a'] ?? '')) === '' && $assignedName === '') {
                return true;
            }
            if ($assignedName === '') {
                return false;
            }

            foreach ($candidateNames as $candidateName) {
                if ($this->nameTokensMatch($candidateName, $assignedName)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param array<string,mixed> $user
     */
    private function scopeForUser(array $user, string $scopeKey): string
    {
        $role = trim((string) ($user['role'] ?? data_get($user, 'legacy.rol', 'usuario')));
        $permissions = is_array(data_get($user, 'legacy.permisos')) ? data_get($user, 'legacy.permisos') : [];
        if (!empty($permissions['all'])) {
            return 'todos';
        }
        if (array_key_exists($scopeKey, $permissions)) {
            return strtolower(trim((string) $permissions[$scopeKey])) === 'todos' ? 'todos' : 'asignados';
        }
        if ($scopeKey === 'historico_scope' && array_key_exists('historico', $permissions)) {
            return strtolower(trim((string) $permissions['historico'])) === 'todos' ? 'todos' : 'asignados';
        }

        $roles = $this->roles();
        $roleConfig = is_array($roles[$role] ?? null) ? $roles[$role] : [];
        if (!empty($roleConfig['all'])) {
            return 'todos';
        }

        $value = $roleConfig[$scopeKey] ?? null;
        if ($scopeKey === 'historico_scope' && $value === null) {
            $value = $roleConfig['historico'] ?? null;
        }

        return strtolower(trim((string) $value)) === 'todos' ? 'todos' : 'asignados';
    }

    private function nameTokensMatch(string $left, string $right): bool
    {
        $leftTokens = $this->nameTokens($left);
        $rightTokens = $this->nameTokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $matches = 0;
        foreach ($leftTokens as $token) {
            if (in_array($token, $rightTokens, true)) {
                $matches++;
            }
        }

        return $matches >= min(2, count($leftTokens), count($rightTokens));
    }

    /**
     * @return array<int,string>
     */
    private function nameTokens(string $value): array
    {
        $normalized = strtolower(Str::ascii($value));
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';
        $tokens = array_filter(explode(' ', $normalized), static fn (string $token): bool => strlen($token) >= 3);

        return array_values(array_unique($tokens));
    }

    private function normalizeDashboardFilter(string $filter): string
    {
        $filter = strtolower(trim($filter));

        return in_array($filter, ['todos', 'pendientes', 'procesados', 'errores'], true) ? $filter : 'todos';
    }

    private function webhookTimestamp(string $date, string $time): int
    {
        $date = $date !== '' ? $date : now('America/Santiago')->format('Y-m-d');
        $time = $time !== '' ? $time : now('America/Santiago')->format('H:i');
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . substr($time, 0, 5), new \DateTimeZone('America/Santiago'));

        return $parsed ? $parsed->getTimestamp() : now('America/Santiago')->timestamp;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function maintenanceReadPath(string $relative): array
    {
        $relative = $this->normalizeMaintenanceRelativePath($relative);
        if ($relative === '') {
            return [];
        }

        $absolute = $this->dataPath($relative);
        $this->assertInsideDataRoot($absolute);
        if (is_file($absolute)) {
            return [$relative => $this->maintenanceEncodeFile($absolute)];
        }
        if (!is_dir($absolute)) {
            return [];
        }

        $files = [];
        foreach (File::allFiles($absolute) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            $root = str_replace('\\', '/', rtrim($this->dataPath(''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
            $fileRelative = substr($path, strlen($root));
            $files[$fileRelative] = $this->maintenanceEncodeFile($file->getPathname());
        }
        ksort($files);

        return $files;
    }

    /**
     * @return array<string,mixed>
     */
    private function maintenanceEncodeFile(string $absolute): array
    {
        $content = (string) file_get_contents($absolute);
        if (strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) === 'json') {
            $decoded = json_decode($content, true);

            return [
                '_encoding' => 'json',
                'content' => is_array($decoded) ? $decoded : [],
            ];
        }

        return [
            '_encoding' => 'base64',
            'content' => base64_encode($content),
        ];
    }

    /**
     * @param string[] $selected
     * @return string[]
     */
    private function maintenanceAllowedPaths(array $selected): array
    {
        $available = $this->maintenanceSections();
        $allowed = [];
        foreach ($selected as $sectionKey) {
            foreach ($available[$sectionKey]['paths'] ?? [] as $path) {
                $allowed[] = trim(str_replace('\\', '/', $path), '/');
            }
        }

        return $allowed;
    }

    /**
     * @param string[] $allowed
     */
    private function maintenancePathAllowed(string $relative, array $allowed): bool
    {
        if ($relative === '' || str_contains($relative, '..')) {
            return false;
        }

        foreach ($allowed as $base) {
            if ($relative === $base || str_starts_with($relative, rtrim($base, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizeMaintenanceRelativePath(string $relative): string
    {
        return ltrim(str_replace('\\', '/', trim($relative)), '/');
    }

    /**
     * @param array<string,mixed> $file
     */
    private function maintenanceWriteImportedFile(string $relative, array $file): void
    {
        $target = $this->dataPath($relative);
        $this->assertInsideDataRoot($target);
        $encoding = (string) ($file['_encoding'] ?? 'json');

        if ($encoding === 'base64') {
            $decoded = base64_decode((string) ($file['content'] ?? ''), true);
            if ($decoded === false) {
                throw new \RuntimeException('Contenido base64 invalido en ' . $relative . '.');
            }
            $this->writeText($target, $decoded);

            return;
        }

        $incoming = is_array($file['content'] ?? null) ? $file['content'] : [];
        if ($this->maintenanceShouldMergeJson($relative)) {
            $existing = is_file($target) ? json_decode((string) file_get_contents($target), true) : [];
            $incoming = str_starts_with($relative, 'horasExtras/')
                ? $this->maintenanceMergeHoursGroups(is_array($existing) ? $existing : [], $incoming)
                : $this->maintenanceMergeListById(is_array($existing) ? $existing : [], $incoming);
        }

        $this->writeJson($target, $incoming);
    }

    private function maintenanceShouldMergeJson(string $relative): bool
    {
        return strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'json'
            && (str_starts_with($relative, 'reportes/') || str_starts_with($relative, 'horasExtras/'));
    }

    /**
     * @param array<int,mixed> $existing
     * @param array<int,mixed> $incoming
     * @return array<int,mixed>
     */
    private function maintenanceMergeListById(array $existing, array $incoming): array
    {
        $merged = [];
        $positions = [];
        foreach ($existing as $item) {
            $key = is_array($item) ? trim((string) ($item['id'] ?? '')) : '';
            $key = $key !== '' ? $key : 'hash:' . md5(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $positions[$key] = count($merged);
            $merged[] = $item;
        }
        foreach ($incoming as $item) {
            $key = is_array($item) ? trim((string) ($item['id'] ?? '')) : '';
            $key = $key !== '' ? $key : 'hash:' . md5(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (array_key_exists($key, $positions) && is_array($merged[$positions[$key]]) && is_array($item)) {
                $merged[$positions[$key]] = array_replace_recursive($merged[$positions[$key]], $item);
                continue;
            }
            $positions[$key] = count($merged);
            $merged[] = $item;
        }

        return array_values($merged);
    }

    /**
     * @param array<int,mixed> $existing
     * @param array<int,mixed> $incoming
     * @return array<int,mixed>
     */
    private function maintenanceMergeHoursGroups(array $existing, array $incoming): array
    {
        $merged = [];
        $positions = [];
        foreach ($existing as $group) {
            $key = is_array($group) ? $this->maintenanceHoursGroupKey($group) : 'hash:' . md5(json_encode($group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $positions[$key] = count($merged);
            $merged[] = $group;
        }
        foreach ($incoming as $group) {
            $key = is_array($group) ? $this->maintenanceHoursGroupKey($group) : 'hash:' . md5(json_encode($group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (array_key_exists($key, $positions) && is_array($merged[$positions[$key]]) && is_array($group)) {
                $current = $merged[$positions[$key]];
                $combined = array_replace_recursive($current, $group);
                if (isset($current['reports']) || isset($group['reports'])) {
                    $combined['reports'] = $this->maintenanceMergeListById(
                        is_array($current['reports'] ?? null) ? $current['reports'] : [],
                        is_array($group['reports'] ?? null) ? $group['reports'] : []
                    );
                }
                $merged[$positions[$key]] = $combined;
                continue;
            }
            $positions[$key] = count($merged);
            $merged[] = $group;
        }

        return array_values($merged);
    }

    /**
     * @param array<string,mixed> $group
     */
    private function maintenanceHoursGroupKey(array $group): string
    {
        foreach (['fecha', 'fecha_inicio', 'start_date', 'date', 'created_on'] as $key) {
            $value = trim((string) ($group[$key] ?? ''));
            if ($value !== '') {
                return $key . ':' . $value;
            }
        }

        return 'hash:' . md5(json_encode($group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function formatUntil(string $until): string
    {
        if ($until === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $until, new \DateTimeZone('America/Santiago'));

        return $date ? $date->format('d-m-Y H:i') : $until;
    }
}
