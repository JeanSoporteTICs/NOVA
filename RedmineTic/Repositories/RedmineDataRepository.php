<?php

namespace RedmineTic\Repositories;

use App\Modulos\Nova\Models\NovaUser;
use App\Modulos\Nova\Services\RedmineIdentityService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RedmineTic\Services\RedmineIssueSenderService;
use RedmineTic\Services\RedmineIssueStatusService;
use RedmineTic\Services\RedmineMembershipSyncService;
use RedmineTic\Support\ArraySupport;
use RedmineTic\Support\CatalogMatchSupport;
use RedmineTic\Support\DateSupport;
use RedmineTic\Support\RedmineUrlSupport;
use RedmineTic\Support\TextSupport;

final class RedmineDataRepository
{
    /** Permission keys that carry a string scope value instead of a boolean. */
    private const PERMISSION_SCOPE_KEYS = ['mensajes', 'historico_scope', 'horas_extra'];
    /** Roles required as the minimum permission templates for the TIC module. */
    private const BASE_ROLES = ['administrador', 'usuario'];

    private string $projectKey = 'redmine_tic';
    private ?array $assignedUserNames = null;
    private ?array $activeReportsCache = null;
    private ?array $archivedReportsCache = null;
    private ?bool $reportsTableAvailableCache = null;

    private ?RedmineActivityRepository    $activityRepoInst    = null;
    private ?RedmineConfigRepository      $configRepoInst      = null;
    private ?RedmineCatalogRepository     $catalogRepoInst     = null;
    private ?RedmineHoursExtraRepository  $hoursExtraRepoInst  = null;
    private ?RedminePermissionRepository  $permissionRepoInst  = null;
    private ?RedmineReportRepository      $reportRepoInst      = null;
    private ?RedmineUserRepository        $userRepoInst        = null;
    private ?RedmineIssueSenderService    $issueSenderInst     = null;
    private ?RedmineIssueStatusService    $issueStatusInst     = null;
    private ?RedmineStatisticsRepository  $statisticsRepoInst  = null;
    private ?RedmineMembershipSyncService $membershipSyncInst  = null;
    private ?RedmineIdentityService       $redmineIdentityInst = null;

    public function forProject(string $projectKey): self
    {
        if (array_key_exists($projectKey, config('modules', [])) && $projectKey !== $this->projectKey) {
            $this->projectKey = $projectKey;
            $this->activeReportsCache = null;
            $this->archivedReportsCache = null;
            $this->assignedUserNames = null;
            $this->activityRepoInst    = null;
            $this->configRepoInst      = null;
            $this->catalogRepoInst     = null;
            $this->hoursExtraRepoInst  = null;
            $this->permissionRepoInst  = null;
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

    // ---- focused-class factory methods ----

    private function activityRepo(): RedmineActivityRepository
    {
        return $this->activityRepoInst ??= new RedmineActivityRepository($this->projectKey, $this->projectName());
    }

    private function configRepo(): RedmineConfigRepository
    {
        return $this->configRepoInst ??= new RedmineConfigRepository($this->projectKey, $this->projectName());
    }

    private function catalogRepo(): RedmineCatalogRepository
    {
        return $this->catalogRepoInst ??= new RedmineCatalogRepository($this->projectKey, $this->projectName());
    }

    private function hoursExtraRepo(): RedmineHoursExtraRepository
    {
        return $this->hoursExtraRepoInst ??= new RedmineHoursExtraRepository();
    }

    private function permissionRepo(): RedminePermissionRepository
    {
        return $this->permissionRepoInst ??= new RedminePermissionRepository($this->projectKey, $this->projectName());
    }

    private function reportRepo(): RedmineReportRepository
    {
        return $this->reportRepoInst ??= new RedmineReportRepository($this->projectKey, $this->projectName());
    }

    private function userRepo(): RedmineUserRepository
    {
        return $this->userRepoInst ??= new RedmineUserRepository($this->projectKey, $this->projectName());
    }

    private function issueSender(): RedmineIssueSenderService
    {
        return $this->issueSenderInst ??= new RedmineIssueSenderService();
    }

    private function issueStatus(): RedmineIssueStatusService
    {
        return $this->issueStatusInst ??= new RedmineIssueStatusService();
    }

    private function statisticsRepo(): RedmineStatisticsRepository
    {
        return $this->statisticsRepoInst ??= new RedmineStatisticsRepository($this->projectKey, $this->projectName());
    }

    private function membershipSync(): RedmineMembershipSyncService
    {
        return $this->membershipSyncInst ??= new RedmineMembershipSyncService();
    }

    private function redmineIdentity(): RedmineIdentityService
    {
        return $this->redmineIdentityInst ??= app(RedmineIdentityService::class);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function activeReports(): array
    {
        if ($this->activeReportsCache === null) {
            $this->activeReportsCache = $this->activeReportsFromDatabase();
        }

        return $this->activeReportsCache;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function archivedReports(): array
    {
        if ($this->archivedReportsCache === null) {
            $this->archivedReportsCache = $this->archivedReportsFromDatabase();
        }

        return $this->archivedReportsCache;
    }


    /**
     * @return array<string,mixed>
     */
    public function configuration(): array
    {
        return $this->configRepo()->configuration();
    }

    /**
     * @param array<string,mixed> $config
     */
    public function saveConfiguration(array $config): void
    {
        $this->configRepo()->saveConfiguration($config);
    }

    public function maintenanceModeEnabled(): bool
    {
        return $this->configRepo()->maintenanceModeEnabled();
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
            'pending' => ArraySupport::countByState($active, ['pendiente']),
            'processed' => ArraySupport::countByState($active, ['procesado', 'procesada']),
            'errors' => ArraySupport::countByState($active, ['error', 'fallido', 'fallida']),
            'archived_total' => count($archived),
            'project_name' => (string) ($config['project_name'] ?? 'Redmine'),
            'maintenance' => [
                'enabled' => !empty($config['maintenance_mode']),
                'until' => trim((string) ($config['maintenance_until'] ?? '')),
                'until_text' => DateSupport::formatUntil(trim((string) ($config['maintenance_until'] ?? ''))),
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
            'pending' => ArraySupport::countByState($reports, ['pendiente']),
            'processed' => ArraySupport::countByState($reports, ['procesado', 'procesada']),
            'errors' => ArraySupport::countByState($reports, ['error', 'fallido', 'fallida']),
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
        $allSummary = $this->dashboardSummaryForReports($reports);
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
            'errorLogsByReport' => $this->errorLogsByReport(),
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
        return $this->userRepo()->projectUsers();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function activeUsersWithPhone(): array
    {
        return $this->userRepo()->activeUsersWithPhone();
    }

    /**
     * @return array{ok:bool,error:string,users:array<int,array<string,mixed>>}
     */
    public function saveUser(array $payload): array
    {
        return $this->userRepo()->saveUser($payload, $this->roles());
    }

    public function deleteUser(string $id): int
    {
        $changed = $this->userRepo()->deleteUser($id);
        if ($changed > 0) {
            $this->activeReportsCache = null;
        }

        return $changed;
    }

    /**
     * @return array{ok:bool,nuevo_estado:string}
     */
    public function toggleUserStatus(string $id): array
    {
        $result = $this->userRepo()->toggleUserStatus($id);
        if ($result['ok']) {
            $this->activeReportsCache = null;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $permissions
     */
    public function saveUserPermissions(string $id, string $role, array $permissions): bool
    {
        return $this->userRepo()->saveUserPermissions($id, $role, $permissions);
    }

    /**
     * @return array{ok:bool,items:array<int,array<string,mixed>>,error:string}
     */
    public function previewUsersFromRedmine(?string $userId = null): array
    {
        $remote = $this->fetchRedmineMemberships($userId);
        if (!$remote['ok']) {
            return ['ok' => false, 'items' => [], 'error' => $remote['error']];
        }

        $currentUsers = $this->users();
        $currentAccess = [];
        foreach ($currentUsers as $user) {
            $id = trim((string) ($user['redmine_id'] ?? $user['id'] ?? ''));
            if ($id !== '') {
                $currentAccess[$id] = true;
            }
        }

        $items = [];
        foreach ($remote['memberships'] as $membership) {
            if (!is_array($membership) || !is_array($membership['user'] ?? null)) {
                continue;
            }
            $redmineUser = $membership['user'];
            $id = trim((string) ($redmineUser['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $identity = $this->membershipSync()->resolveUserIdentity(
                $redmineUser,
                $remote['base_url'],
                $remote['token'],
                fn (string $url, string $token): array => $this->getRedmineJson($url, $token)
            );
            $firstName = $identity['firstname'];
            $lastName = $identity['lastname'];
            $login = $identity['login'];
            if ($firstName === '' && $lastName === '') {
                continue;
            }
            $access = $this->userRepo()->accessStatusByRedmineId($id);
            $localMatch = $this->redmineIdentity()->projectUserIndexByLogin($currentUsers, $login);
            $centralMatch = $this->redmineIdentity()->centralUserByLogin($login);
            $previousId = $localMatch !== null
                ? trim((string) ($currentUsers[$localMatch]['redmine_id'] ?? $currentUsers[$localMatch]['id'] ?? ''))
                : trim((string) ($centralMatch['redmine_id'] ?? ''));
            $changedId = $previousId !== '' && $previousId !== $id;
            $items[] = [
                'id' => $id,
                'nombre' => $firstName,
                'apellido' => $lastName,
                'login' => $login,
                'previous_id' => $changedId ? $previousId : '',
                'redmine_membership_id' => $membership['id'] ?? null,
                'status' => $changedId
                    ? 'changed'
                    : (isset($currentAccess[$id]) || $access['has_access'] ? 'current' : ($access['exists'] ? 'revoked' : 'new')),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp(
            trim((string) ($a['nombre'] ?? '') . ' ' . (string) ($a['apellido'] ?? '')),
            trim((string) ($b['nombre'] ?? '') . ' ' . (string) ($b['apellido'] ?? ''))
        ));

        return ['ok' => true, 'items' => $items, 'error' => ''];
    }

    /**
     * @param string[]|null $selectedIds
     * @return array{ok:bool,created:int,updated:int,error:string}
     */
    public function syncUsersFromRedmine(?string $userId = null, ?array $selectedIds = null): array
    {
        $remote = $this->fetchRedmineMemberships($userId);
        if (!$remote['ok']) {
            return ['ok' => false, 'created' => 0, 'updated' => 0, 'error' => $remote['error']];
        }

        $memberships = $remote['memberships'];
        $baseUrl = $remote['base_url'];
        $token = $remote['token'];
        $selected = null;
        if (is_array($selectedIds)) {
            $selected = [];
            foreach ($selectedIds as $id) {
                $id = trim((string) $id);
                if ($id !== '') {
                    $selected[$id] = true;
                }
            }
            if ($selected === []) {
                return ['ok' => false, 'created' => 0, 'updated' => 0, 'error' => 'Selecciona al menos un usuario para importar.'];
            }
        }

        $users = $this->users();
        $byId = [];
        foreach ($users as $index => $user) {
            $id = trim((string) ($user['redmine_id'] ?? $user['id'] ?? ''));
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
            if ($selected !== null && !isset($selected[$id])) {
                continue;
            }

            $identity = $this->membershipSync()->resolveUserIdentity(
                $redmineUser,
                $baseUrl,
                $token,
                fn (string $url, string $t): array => $this->getRedmineJson($url, $t)
            );
            $firstName = $identity['firstname'];
            $lastName = $identity['lastname'];
            $login = $identity['login'];
            $row = [
                'id' => $id,
                'redmine_id' => $id,
                'nombre' => $firstName,
                'apellido' => $lastName,
                'rol' => 'usuario',
                'redmine_membership_id' => $membership['id'] ?? null,
            ];

            $index = $byId[$id] ?? $this->redmineIdentity()->projectUserIndexByLogin($users, $login);
            if ($index !== null) {
                $previousId = trim((string) ($users[$index]['redmine_id'] ?? $users[$index]['id'] ?? ''));
                $users[$index] = array_merge($users[$index], $row, [
                    'id' => $id,
                    'redmine_id' => $id,
                    'nombre' => $firstName !== '' ? $firstName : ($users[$index]['nombre'] ?? ''),
                    'apellido' => $lastName !== '' ? $lastName : ($users[$index]['apellido'] ?? ''),
                    'redmine_membership_id' => $membership['id'] ?? ($users[$index]['redmine_membership_id'] ?? null),
                ]);
                if ($previousId !== '' && $previousId !== $id) {
                    unset($byId[$previousId]);
                }
                $byId[$id] = $index;
                $updated++;
                continue;
            }

            $centralMatch = $this->redmineIdentity()->centralUserByLogin($login);
            if ($centralMatch !== null) {
                $row['_nova_user_id'] = $centralMatch['uuid'];
                $row['rut_sin_dv'] = $centralMatch['usuario'];
                $row['rut'] = $centralMatch['rut'];
                $updated++;
            } else {
                $row['rut_sin_dv'] = $this->redmineIdentity()->accessUsernameFromLogin($login);
                $row['rut'] = $this->redmineIdentity()->rutFromLogin($login);
                $created++;
            }

            $users[] = $row;
            $byId[$id] = count($users) - 1;
        }

        $this->userRepo()->persistUsers($users, true, 'baneado');

        return ['ok' => true, 'created' => $created, 'updated' => $updated, 'error' => ''];
    }

    /**
     * @return array{ok:bool,memberships:array<int,mixed>,base_url:string,token:string,error:string}
     */
    private function fetchRedmineMemberships(?string $userId = null): array
    {
        $config = $this->configuration();
        $token = $this->userApiToken($userId);
        if ($token === '') {
            return ['ok' => false, 'memberships' => [], 'base_url' => '', 'token' => '', 'error' => 'API Key Redmine personal no configurada.'];
        }

        $projectId = trim((string) ($config['project_id'] ?? ''));
        if ($projectId === '') {
            return ['ok' => false, 'memberships' => [], 'base_url' => '', 'token' => '', 'error' => 'ID de proyecto no configurado.'];
        }

        $baseUrl = RedmineUrlSupport::redmineBaseUrl((string) ($config['platform_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'memberships' => [], 'base_url' => '', 'token' => '', 'error' => 'URL Redmine no configurada.'];
        }

        $result = $this->membershipSync()->fetchMemberships($baseUrl, $token, $projectId, fn (string $url, string $t): array => $this->getRedmineJson($url, $t));

        return [
            'ok' => $result['ok'],
            'memberships' => $result['memberships'],
            'base_url' => $baseUrl,
            'token' => $token,
            'error' => $result['error'],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function roles(): array
    {
        return $this->permissionRepo()->roles();
    }

    /** @return string[] */
    public function baseRoles(): array
    {
        return self::BASE_ROLES;
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

        return $this->permissionRepo()->saveRolePermissions($role, $permissions);
    }

    public function deleteRole(string $role): array
    {
        $role = trim($role);
        if ($role === '') {
            return ['ok' => false, 'error' => 'Rol no valido.'];
        }

        if (in_array($role, self::BASE_ROLES, true)) {
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
        $this->saveRolesToDatabase($roles);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function categories(): array
    {
        return $this->catalogRepo()->categories();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function saveCategory(array $payload): array
    {
        return $this->catalogRepo()->saveCategory($payload);
    }

    public function deleteCategory(string $id): int
    {
        return $this->catalogRepo()->deleteCategory($id);
    }

    /**
     * @return array{ok:bool,count:int,changed:bool,error:string}
     */
    public function syncCategoriesFromRedmine(?string $userId = null): array
    {
        $config = $this->configuration();
        $token = $this->userApiToken($userId);
        $url = trim((string) ($config['categories_url'] ?? '')) ?: RedmineUrlSupport::redmineCategoriesUrl((string) ($config['platform_url'] ?? ''));
        if ($url === '') {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'Falta URL de categorias Redmine.'];
        }
        if ($token === '') {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'API Key Redmine personal no configurada.'];
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
            $this->saveCatalogRowsToDatabase('categoria', $rows);
        }

        return ['ok' => true, 'count' => count($rows), 'changed' => $changed, 'error' => ''];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function units(): array
    {
        return $this->catalogRepo()->units();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function saveUnit(array $payload): array
    {
        return $this->catalogRepo()->saveUnit($payload);
    }

    public function deleteUnit(string $id): int
    {
        return $this->catalogRepo()->deleteUnit($id);
    }

    /**
     * @return array{ok:bool,count:int,changed:bool,error:string}
     */
    public function syncUnitsFromRedmine(?string $userId = null): array
    {
        $config = $this->configuration();
        $token = $this->userApiToken($userId);
        $url = trim((string) ($config['unidades_url'] ?? '')) ?: RedmineUrlSupport::redmineCustomFieldUrl((string) ($config['platform_url'] ?? ''), '11');
        if ($url === '') {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'Falta URL de unidades Redmine.'];
        }
        if ($token === '') {
            return ['ok' => false, 'count' => 0, 'changed' => false, 'error' => 'API Key Redmine personal no configurada.'];
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
            $this->saveCatalogRowsToDatabase('unidad', $rows);
        }

        return ['ok' => true, 'count' => count($rows), 'changed' => $changed, 'error' => ''];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function hoursExtra(): array
    {
        return $this->hoursExtraFromDatabase();
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
        if ($userId !== '') {
            $groups = array_values(array_filter(array_map(static function (array $group) use ($userId): ?array {
                $reports = array_values(array_filter((array) ($group['reports'] ?? []), static fn (array $report): bool => (string) ($report['asignado_a'] ?? '') === $userId));
                if ($reports === []) {
                    return null;
                }
                $group['reports'] = $reports;

                return $group;
            }, $groups)));
        } else {
            $groups = [];
        }

        $availableYears = [now('America/Santiago')->format('Y') => true];
        foreach ($groups as $group) {
            $date = DateSupport::parseFlexibleDate((string) ($group['fecha'] ?? ''));
            if ($date) {
                $availableYears[$date->format('Y')] = true;
            }
        }
        $availableYears = array_keys($availableYears);
        sort($availableYears);

        $hasExplicitFilters = array_key_exists('filters', $filters) || array_key_exists('mes', $filters) || array_key_exists('anio', $filters);
        $selectedMonth = DateSupport::selectedMonth($filters['mes'] ?? null, $hasExplicitFilters);
        $selectedYear = DateSupport::selectedYear($filters['anio'] ?? null, $hasExplicitFilters);
        $visible = array_values(array_filter($groups, function (array $group) use ($selectedMonth, $selectedYear): bool {
            $date = DateSupport::parseFlexibleDate((string) ($group['fecha'] ?? ''));
            if (!$date) {
                return true;
            }

            return ($selectedMonth === '' || (int) $selectedMonth === (int) $date->format('n'))
                && ($selectedYear === '' || (string) $selectedYear === $date->format('Y'));
        }));
        usort($visible, function (array $a, array $b): int {
            return ((string) (DateSupport::normalizeDateKey((string) ($b['fecha'] ?? '')))) <=> ((string) (DateSupport::normalizeDateKey((string) ($a['fecha'] ?? ''))));
        });

        $totalMinutes = array_reduce($visible, fn (int $carry, array $group): int => $carry + (DateSupport::minutesDiff((string) ($group['hora_inicio'] ?? ''), (string) ($group['hora_fin'] ?? '')) ?? 0), 0);
        $emachSuggestions = $this->emachOvertimeSuggestionsForGroups($visible, $user);

        return [
            'rows' => $visible,
            'hoursMeta' => [
                'months' => DateSupport::monthOptions(),
                'years' => $availableYears,
                'selectedMonth' => $selectedMonth,
                'selectedYear' => $selectedYear,
                'visibleCount' => count($visible),
                'totalCount' => count($groups),
                'totalHours' => DateSupport::formatMinutes($totalMinutes),
                'emachSuggestions' => $emachSuggestions,
            ],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $groups
     * @param array<string,mixed> $user
     * @return array<string,array<string,mixed>>
     */
    private function emachOvertimeSuggestionsForGroups(array $groups, array $user): array
    {
        $suggestions = [];
        foreach ($groups as $group) {
            $dateKey = DateSupport::normalizeDateKey((string) ($group['fecha'] ?? ''));
            if ($dateKey !== '') {
                $suggestions[$dateKey] = [
                    'ok' => false,
                    'hora_inicio' => '',
                    'hora_fin' => '',
                    'total' => '',
                    'status' => 'Sin datos EMACH para calcular esta fecha.',
                ];
            }
        }

        if ($suggestions === []) {
            return [];
        }

        if (!$this->emachCredentialsConfigured($user)) {
            return array_map(static function (array $suggestion): array {
                $suggestion['status'] = 'Configura tus credenciales EMACH antes de calcular.';

                return $suggestion;
            }, $suggestions);
        }

        $userId = $this->emachCentralUserId($user);
        if ($userId === null) {
            return array_map(static function (array $suggestion): array {
                $suggestion['status'] = 'No pude asociar tu usuario NOVA con EMACH.';

                return $suggestion;
            }, $suggestions);
        }

        $schedule = $this->emachScheduleForUser($userId);
        if ($schedule === []) {
            return array_map(static function (array $suggestion): array {
                $suggestion['status'] = 'Define tu horario semanal en EMACH > Horario.';

                return $suggestion;
            }, $suggestions);
        }

        $marks = $this->emachExitMarksFromSession();
        if ($marks === []) {
            return array_map(static function (array $suggestion): array {
                $suggestion['status'] = 'Consulta tus marcaciones en EMACH antes de calcular.';

                return $suggestion;
            }, $suggestions);
        }

        foreach (array_keys($suggestions) as $dateKey) {
            $date = DateSupport::parseFlexibleDate($dateKey);
            if (!$date) {
                continue;
            }

            $weekday = (int) $date->format('N');
            $configured = $schedule[$weekday] ?? null;
            if (!$configured || empty($configured['activo'])) {
                $suggestions[$dateKey]['status'] = 'Ese día no tiene jornada activa en tu horario EMACH.';
                continue;
            }

            $scheduledExit = DateSupport::minutesFromClock((string) ($configured['salida'] ?? ''));
            if ($scheduledExit === null) {
                $suggestions[$dateKey]['status'] = 'Tu horario EMACH no tiene hora de salida para ese día.';
                continue;
            }

            $actualExit = $marks[$dateKey]['exit'] ?? null;
            if ($actualExit === null) {
                $suggestions[$dateKey]['status'] = 'No encontré una marcación de salida EMACH para esa fecha.';
                continue;
            }

            $extraMinutes = $actualExit - $scheduledExit;
            if ($extraMinutes <= 0) {
                $suggestions[$dateKey]['status'] = 'La salida EMACH no supera tu horario de salida.';
                continue;
            }

            $suggestions[$dateKey] = [
                'ok' => true,
                'hora_inicio' => DateSupport::clockFromMinutes($scheduledExit),
                'hora_fin' => DateSupport::clockFromMinutes($actualExit),
                'total' => DateSupport::formatMinutes($extraMinutes),
                'status' => 'Calculado con horario EMACH y marcación de salida.',
            ];
        }

        return $suggestions;
    }

    private function emachCredentialsConfigured(array $user): bool
    {
        try {
            $credentials = app(\App\Modulos\Nova\Repositories\UserIntegrationRepository::class)->emachForSession($user);

            return !empty($credentials['stored']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function emachCentralUserId(array $user): ?int
    {
        if (!Schema::hasTable('usuarios_nova')) {
            return null;
        }

        $candidates = [
            'uuid' => [$user['_nova_user_id'] ?? '', $user['uuid'] ?? ''],
            'usuario' => [$user['username'] ?? '', $user['usuario'] ?? '', $user['rut_sin_dv'] ?? '', $user['id'] ?? ''],
            'rut' => [$user['rut'] ?? ''],
            'redmine_id' => [$user['redmine_id'] ?? '', $user['id'] ?? ''],
            'usuario_core' => [$user['core_user'] ?? '', $user['usuario_core'] ?? ''],
        ];

        foreach ($candidates as $column => $values) {
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }

                $id = DB::table('usuarios_nova')->where($column, $value)->value('id');
                if ($id !== null) {
                    return (int) $id;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,array{activo:bool,salida:string}>
     */
    private function emachScheduleForUser(int $userId): array
    {
        if ($userId <= 0 || !Schema::hasTable('emach_horarios_usuario')) {
            return [];
        }

        $schedule = [];
        $rows = DB::table('emach_horarios_usuario')
            ->where('usuario_id', $userId)
            ->get();

        foreach ($rows as $row) {
            $day = (int) ($row->dia_semana ?? 0);
            if ($day < 1 || $day > 7) {
                continue;
            }

            $schedule[$day] = [
                'activo' => (bool) ($row->activo ?? false),
                'salida' => substr((string) ($row->hora_salida ?? ''), 0, 5),
            ];
        }

        return $schedule;
    }

    /**
     * @return array<string,array{exit:int}>
     */
    private function emachExitMarksFromSession(): array
    {
        if (!request()->hasSession()) {
            return [];
        }

        $payload = request()->session()->get('emach.last_query', []);
        $rows = is_array($payload) ? (array) data_get($payload, 'planilla.rows', []) : [];
        $marks = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = strtoupper(trim((string) ($row[5] ?? data_get($row, 'tipo', ''))));
            if ($type !== 'SALIDA') {
                continue;
            }

            $dateKey = DateSupport::normalizeDateKey((string) ($row[3] ?? data_get($row, 'fecha', '')));
            $minutes = DateSupport::minutesFromClock((string) ($row[4] ?? data_get($row, 'marcas', data_get($row, 'marca', ''))));
            if ($dateKey === '' || $minutes === null) {
                continue;
            }

            $marks[$dateKey]['exit'] = max((int) ($marks[$dateKey]['exit'] ?? -1), $minutes);
        }

        return $marks;
    }


    public function saveHoursGroup(string $sourceFile, array $payload): bool
    {
        return $this->hoursExtraRepo()->saveGroup($sourceFile, $payload);
    }

    public function deleteHoursGroup(string $sourceFile, string $date): int
    {
        return $this->hoursExtraRepo()->deleteGroup($sourceFile, $date);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function history(array $user = []): array
    {
        $rows = [];
        foreach ($this->archivedReports() as $index => $report) {
            $key = ArraySupport::historyRowKey($report, 'archived-' . $index);
            $report['_history_type'] = 'Archivado';
            $report['_history_can_delete'] = true;
            $report['_history_sort_date'] = DateSupport::normalizeDateKey((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? ''));
            $rows[$key] = $report;
        }

        foreach ($this->deduplicateHoursGroups($this->hoursExtra()) as $group) {
            foreach ((array) ($group['reports'] ?? []) as $index => $report) {
                if (!is_array($report)) {
                    continue;
                }
                $key = ArraySupport::historyRowKey($report, 'hours-' . ($group['fecha'] ?? '') . '-' . $index);
                if (isset($rows[$key])) {
                    $rows[$key]['_history_type'] = 'Hora extra';
                    $rows[$key]['_history_is_hours_extra'] = true;
                    continue;
                }
                $report['_history_type'] = 'Hora extra';
                $report['_history_is_hours_extra'] = true;
                $report['_history_can_delete'] = false;
                $report['_history_sort_date'] = DateSupport::normalizeDateKey((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? $group['fecha'] ?? ''));
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
        return RedmineUrlSupport::redmineIssueUrl(
            (string) ($this->configuration()['platform_url'] ?? ''),
            $redmineId
        );
    }

    /**
     * @param array<int,string> $redmineIds
     * @return array<string,array{id:int,name:string,closed:bool,available:bool,message:string}>
     */
    public function issueStatuses(array $redmineIds, ?string $userId = null): array
    {
        $token = $this->userApiToken($userId);
        $statuses = [];

        foreach ($redmineIds as $redmineId) {
            $id = trim((string) $redmineId);
            if (! preg_match('/^\d+$/', $id)) {
                continue;
            }

            $issueUrl = $this->redmineIssueUrl($id);
            $statuses[$id] = $this->issueStatus()->fetch($issueUrl, $token);
        }

        return $statuses;
    }

    /**
     * @return array<int,array{id:int,name:string}>
     */
    public function issueStatusOptions(): array
    {
        return $this->issueStatus()->options((array) ($this->configuration()['estados'] ?? []));
    }

    /**
     * @param  array<int,string>  $redmineIds
     * @param  array<string,mixed>  $user
     * @return array{updated:int,requested:int,status_name:string,errors:array<int,string>}
     */
    public function updateHistoryIssueStatuses(array $redmineIds, int $statusId, array $user): array
    {
        $options = $this->issueStatusOptions();
        $statusName = $this->issueStatus()->statusName($options, $statusId);
        $requested = array_slice(array_values(array_unique(array_filter(array_map(
            static function ($id): string {
                $id = trim((string) $id);

                return preg_match('/^\d+$/', $id) ? $id : '';
            },
            $redmineIds
        )))), 0, 100);

        if ($statusName === null) {
            return [
                'updated' => 0,
                'requested' => count($requested),
                'status_name' => '',
                'errors' => ['Selecciona un estado Redmine válido.'],
            ];
        }
        if ($requested === []) {
            return [
                'updated' => 0,
                'requested' => 0,
                'status_name' => $statusName,
                'errors' => ['Selecciona al menos un reporte abierto.'],
            ];
        }

        $accessible = [];
        foreach ($this->history($user) as $report) {
            $redmineId = trim((string) ($report['redmine_id'] ?? ''));
            if (preg_match('/^\d+$/', $redmineId)) {
                $accessible[$redmineId] = true;
            }
        }

        $userId = (string) ($user['id'] ?? '');
        $token = $this->userApiToken($userId);
        if ($token === '') {
            return [
                'updated' => 0,
                'requested' => count($requested),
                'status_name' => $statusName,
                'errors' => ['Configura tu API Key personal de Redmine en Mis integraciones.'],
            ];
        }

        $updated = 0;
        $errors = [];
        foreach ($requested as $redmineId) {
            if (! isset($accessible[$redmineId])) {
                $errors[] = '#'.$redmineId.': no pertenece a tu histórico disponible.';

                continue;
            }

            $current = $this->issueStatus()->fetch($this->redmineIssueUrl($redmineId), $token);
            if (! $current['available']) {
                $errors[] = '#'.$redmineId.': no se pudo confirmar el estado actual.';

                continue;
            }
            if ($current['closed']) {
                $errors[] = '#'.$redmineId.': ya está cerrado en Redmine.';

                continue;
            }
            if ($this->issueStatus()->isCurrentStatus($current, $statusId, $statusName)) {
                $errors[] = '#'.$redmineId.': ya tiene el estado '.$statusName.'.';

                continue;
            }

            $result = $this->issueStatus()->update($this->redmineIssueUrl($redmineId), $statusId, $token);
            if (! $result['ok']) {
                $errors[] = '#'.$redmineId.': '.($result['error'] ?: 'no se pudo actualizar.');

                continue;
            }

            $this->reportRepo()->updateArchivedRedmineStatus($redmineId, $statusName);
            $updated++;
        }

        if ($updated > 0) {
            $this->archivedReportsCache = null;
        }
        $this->appendActivityLog('historico_estado_redmine_actualizado', [
            'user_id' => $userId,
            'redmine_ids' => $requested,
            'status_id' => $statusId,
            'status_name' => $statusName,
            'updated' => $updated,
            'failed' => count($errors),
        ]);

        return [
            'updated' => $updated,
            'requested' => count($requested),
            'status_name' => $statusName,
            'errors' => $errors,
        ];
    }

    public function deleteArchivedReport(string $id): int
    {
        return $this->reportRepo()->deleteArchived($id);
    }

    /**
     * @return array<int,string>
     */
    public function activity(): array
    {
        return $this->activityRepo()->activity();
    }

    /** @return array<string,mixed> */
    public function activityData(array $filters = [], string $viewerId = '', bool $canViewAll = false): array
    {
        return $this->activityRepo()->search($filters, $viewerId, $canViewAll);
    }

    public function clearActivityForUser(string $userId): int
    {
        return $this->activityRepo()->clearForUser($userId);
    }

    /**
     * @return array<string,string>
     */
    public function errorLogsByReport(): array
    {
        $logs = [];

        foreach ($this->activity() as $line) {
            $entry = json_decode((string) $line, true);
            if (!is_array($entry)) {
                continue;
            }

            $event = trim((string) ($entry['event'] ?? ''));
            if (!in_array($event, ['envio_redmine_error', 'envio_redmine_http'], true)) {
                continue;
            }

            $context = $entry['context'] ?? [];
            if (!is_array($context)) {
                continue;
            }

            $messageId = trim((string) ($context['message_id'] ?? ''));
            if ($messageId === '') {
                continue;
            }

            $httpCode = (int) ($context['http_code'] ?? 0);
            $error = trim((string) ($context['error'] ?? ''));
            if ($event === 'envio_redmine_http' && $httpCode >= 200 && $httpCode < 400 && $error === '') {
                continue;
            }

            $logs[$messageId][] = $this->formatErrorLogEntry($entry, $context);
        }

        return array_map(
            static fn (array $entries): string => implode(PHP_EOL . PHP_EOL . '---' . PHP_EOL . PHP_EOL, array_slice($entries, 0, 8)),
            $logs
        );
    }

    public function clearActivity(): void
    {
        $this->activityRepo()->clearActivity();
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
        [$from, $to] = DateSupport::statisticsDateRange($filters);
        $reports = DateSupport::filterReportsByDateRange($reports, $from, $to);

        return $this->statisticsRepo()->statistics($reports, $from, $to, fn (string $date): string => DateSupport::normalizeDateKey($date));
    }

    /**
     * @return array<string,mixed>
     */
    public function redmineApiStatistics(array $filters = [], array $user = []): array
    {
        $stats = $this->statisticsRepo();
        $config = $this->configuration();
        $statusOptions = $stats->statusOptions($config);
        $statusSelection = $stats->normalizeStatusSelection((string) ($filters['status_scope'] ?? 'all'), $statusOptions);
        $trackerOptions = $stats->configOptions($config, 'trackers');
        $priorityOptions = $stats->configOptions($config, 'prioridades');
        $trackerSelection = $stats->normalizeOptionSelection((string) ($filters['tracker_scope'] ?? 'all'), $trackerOptions);
        $prioritySelection = $stats->normalizeOptionSelection((string) ($filters['priority_scope'] ?? 'all'), $priorityOptions);
        $categorySelection = $stats->normalizeCategorySelection((array) ($filters['category_scope'] ?? []));
        $categoryFilterActive = filter_var($filters['category_filter'] ?? false, FILTER_VALIDATE_BOOL);
        [$from, $to] = DateSupport::statisticsDateRange($filters);
        $fetchRequested = filter_var($filters['fetch'] ?? false, FILTER_VALIDATE_BOOL);
        $maintenanceActive = $this->maintenanceModeEnabled();
        $shouldFetch = $fetchRequested && !$maintenanceActive;
        $empty = $stats->emptyStatistics([
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
            $cached = $stats->apiStatisticsCache();
            if ($cached !== []) {
                $rawRows = (array) ($cached['raw_rows'] ?? []);
                if ($rawRows !== []) {
                    $cachedFilters = (array) ($cached['filters'] ?? []);
                    $cachedFilters['category_scope'] = $categorySelection;
                    $cachedFilters['category_filter'] = $categoryFilterActive ? '1' : '';
                    $cached = $stats->buildApiStatisticsFromRows($rawRows, $config, $cachedFilters, true, fn (string $date): string => DateSupport::normalizeDateKey($date));
                }
                $cached = $stats->normalizeApiStatistics($cached);
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

        $token = $this->userApiToken((string) ($user['id'] ?? ''));
        $projectId = trim((string) ($config['project_id'] ?? ''));
        $issuesUrl = RedmineUrlSupport::redmineIssuesUrl((string) ($config['platform_url'] ?? ''));
        $dateField = in_array((string) ($config['date_field'] ?? ''), ['start_date', 'due_date', 'created_on'], true)
            ? (string) $config['date_field']
            : 'start_date';

        if ($token === '' || $projectId === '' || $issuesUrl === '') {
            $empty['error'] = 'Falta configurar URL, proyecto o API Key personal de Redmine.';
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
            'status_id' => $stats->statusQueryValue($statusSelection),
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
        $result = $stats->buildApiStatisticsFromRows($rows, $config, [
            'desde' => $from->format('d-m-Y'),
            'hasta' => $to->format('d-m-Y'),
            'status_scope' => $statusSelection,
            'tracker_scope' => $trackerSelection,
            'priority_scope' => $prioritySelection,
            'category_scope' => [],
            'category_filter' => '',
        ], false, fn (string $date): string => DateSupport::normalizeDateKey($date));
        $result['status_options'] = $statusOptions;
        $result['tracker_options'] = $trackerOptions;
        $result['priority_options'] = $priorityOptions;

        $stats->saveApiStatisticsCache($result);
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
            'configuracion' => ['config' => $this->configuration(), 'roles' => $this->roles(), 'baseRoles' => $this->baseRoles(), 'users' => $this->users(), 'categories' => $this->categories(), 'units' => $this->units(), 'webhookUrl' => $this->webhookUrl()],
            'estadisticas' => ['stats' => $this->statistics($filters)],
            'actividad' => ['users' => $this->users()],
            default => [],
        };
    }


    public function updateReport(array $payload): bool
    {
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return false;
        }

        $fields = Arr::only($payload, [
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
            'estado',
            'mensaje',
            'descripcion',
        ]);
        $report = $this->updateActiveReportInDatabase($id, $fields);
        if ($report === null) {
            return false;
        }

        return true;
    }

    public function deleteReport(string $id): int
    {
        $deleted = $this->deleteActiveReportFromDatabase($id);
        if ($deleted > 0) {
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
        if ($ids === []) {
            return 0;
        }

        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return 0;
        }

        $deleted = $this->reportRepo()->deleteActiveByIds($moduleId, $ids);
        if ($deleted > 0) {
            $this->activeReportsCache = null;
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
        if ($ids === []) {
            return 0;
        }

        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return 0;
        }

        $targets = $this->reportRepo()->findActiveByIds($moduleId, $ids, fn (string $assigneeId): string => $this->assignedUserName($assigneeId));
        $archived = 0;
        foreach ($targets as $report) {
            $this->archiveReport($report);
            $archived++;
        }

        if ($archived > 0) {
            $this->activeReportsCache = null;
        }

        return $archived;
    }

    public function archiveExpiredProcessedReports(): int
    {
        $cacheKey = 'nova.redmine.archive_check.' . $this->projectKey;
        if (Cache::has($cacheKey)) {
            return 0;
        }
        Cache::put($cacheKey, 1, 300);

        $retentionHours = max(1, (int) ($this->configuration()['retencion_horas'] ?? 24));
        $limit = now('America/Santiago')->subHours($retentionHours)->getTimestamp();
        $moduleId = $this->databaseModuleId();
        $candidates = $moduleId !== null
            ? $this->reportRepo()->findActiveByStates($moduleId, ['procesado', 'procesada'], fn (string $assigneeId): string => $this->assignedUserName($assigneeId))
            : [];
        $archived = 0;

        foreach ($candidates as $report) {
            $processedAt = DateSupport::timestampFromValue($report['procesado_ts'] ?? null);
            if ($processedAt === null || $processedAt > $limit) {
                continue;
            }

            $report['_retencion_horas'] = $retentionHours;
            $this->archiveReport($report);
            $archived++;
        }

        if ($archived > 0) {
            $this->activeReportsCache = null;
        }

        return $archived;
    }

    public function toggleHoursExtra(string $id, bool $enabled): bool
    {
        $report = $this->activeReportFromDatabaseById($id);
        if ($report === null) {
            return false;
        }

        $report['hora_extra'] = $enabled ? 'SI' : 'NO';
        $report['tiempo_estimado'] = $enabled ? '1' : '';

        if (!$this->updateActiveReportHoursExtraInDatabase($id, $enabled)) {
            return false;
        }

        if (!$enabled) {
            $this->removeHoursExtraRecord($id);
        }

        return true;
    }

    /**
     * Un reporte solo debe pasar a Horas Extra una vez procesado (enviado a
     * Redmine con éxito). Reportes 'pendiente' o 'error' no ingresan a
     * horas_extra_grupo_reportes todavía.
     *
     * @param array<string,mixed> $report
     */
    private function isReportProcesado(array $report): bool
    {
        return strtolower(trim((string) ($report['estado'] ?? ''))) === 'procesado';
    }

    /**
     * @param string[] $ids
     */
    public function resetErrors(array $ids): int
    {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        if ($ids === []) {
            return 0;
        }

        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return 0;
        }

        $targets = $this->reportRepo()->findActiveByIds($moduleId, $ids, fn (string $assigneeId): string => $this->assignedUserName($assigneeId));
        $updated = 0;
        foreach ($targets as $report) {
            if (strtolower((string) ($report['estado'] ?? '')) !== 'error') {
                continue;
            }

            $report['estado'] = 'pendiente';
            unset($report['redmine_id']);
            $report['procesado_ts'] = '';

            $values = Arr::only($this->databaseReportPayload($moduleId, $report, false), ['estado', 'redmine_id', 'procesado_at']);
            $values['actualizado_at'] = now();

            $this->reportRepo()->updateActiveFields($moduleId, (string) ($report['id'] ?? ''), $values);
            $updated++;
        }

        if ($updated > 0) {
            $this->activeReportsCache = null;
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
        $config = $this->configuration();
        $token = $this->userApiToken($userId);
        $attempts = 0;
        $success = 0;
        $errors = [];
        $redmineIds = [];

        $moduleId = $this->databaseModuleId();
        $reports = $moduleId !== null
            ? $this->reportRepo()->findActiveByIds($moduleId, $ids, fn (string $assigneeId): string => $this->assignedUserName($assigneeId))
            : [];

        if ($token === '') {
            $attempts = count($reports);
            $message = 'API Key Redmine personal no configurada o no legible. Reconfigura tu API Key en Mis integraciones.';
            $this->appendActivityLog('envio_redmine_error', [
                'user_id' => $userId ?? '',
                'http_code' => 0,
                'error' => $message,
            ]);

            return ['attempts' => $attempts, 'success' => 0, 'errors' => [$message], 'redmine_ids' => []];
        }

        foreach ($reports as $report) {
            $attempts++;
            $result = $this->issueSender()->send($report, $config, $token, fn (string $category): int => $this->redmineCategoryId($category));
            $payload = $result['payload'];
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
                $report['estado'] = 'procesado';
                $report['redmine_id'] = $decoded['issue']['id'] ?? $report['redmine_id'] ?? '';
                $report['procesado_ts'] = now('America/Santiago')->toAtomString();
                $success++;
                if (!empty($report['redmine_id'])) {
                    $redmineIds[] = (string) $report['redmine_id'];
                }
                $this->appendActivityLog('envio_redmine_ok', [
                    'message_id' => $report['id'] ?? '',
                    'user_id' => $userId ?? '',
                    'redmine_id' => $report['redmine_id'] ?? '',
                    'http_code' => $result['http_code'],
                    'asunto' => $report['asunto'] ?? '',
                    'categoria' => $report['categoria'] ?? '',
                    'unidad' => $report['unidad'] ?? '',
                    'unidad_solicitante' => $report['unidad_solicitante'] ?? '',
                ]);
                $this->persistSentReport($moduleId, $report);
                continue;
            }

            $report['estado'] = 'error';
            $report['procesado_ts'] = now('America/Santiago')->toAtomString();
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
            $this->persistSentReport($moduleId, $report);
        }

        if ($attempts > 0) {
            $this->activeReportsCache = null;
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

    /**
     * Punctual persistence of one report's outcome after sendReportsToRedmine()
     * attempts it — updates only estado/redmine_id/procesado_at for that
     * single row instead of rewriting the whole active set.
     *
     * @param array<string,mixed> $report
     */
    private function persistSentReport(?int $moduleId, array $report): void
    {
        if ($moduleId === null) {
            return;
        }

        $values = Arr::only($this->databaseReportPayload($moduleId, $report, false), ['estado', 'redmine_id', 'procesado_at']);
        $values['actualizado_at'] = now();

        $this->reportRepo()->updateActiveFields($moduleId, (string) ($report['id'] ?? ''), $values);
    }

    public function createSimulatedReport(array $payload): array
    {
        $reports = $this->activeReports();
        $now = now('America/Santiago');
        $report = [
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
            'chat_id_telegram' => trim((string) ($payload['chat_id_telegram'] ?? $payload['numero'] ?? '')),
            'fecha' => trim((string) ($payload['fecha'] ?? $now->format('Y-m-d'))),
            'hora' => trim((string) ($payload['hora'] ?? $now->format('H:i'))),
            'fecha_inicio' => trim((string) ($payload['fecha_inicio'] ?? $now->format('Y-m-d'))),
            'fecha_fin' => trim((string) ($payload['fecha_fin'] ?? '')),
            'asignado_a' => trim((string) ($payload['asignado_a'] ?? '')),
            'hora_extra' => (($payload['hora_extra'] ?? '') === 'SI' || ($payload['hora_extra'] ?? '') === '1') ? 'SI' : 'NO',
            'tiempo_estimado' => trim((string) ($payload['tiempo_estimado'] ?? '')),
            'origen' => trim((string) ($payload['origen'] ?? 'manual')),
            'created_at' => $now->toAtomString(),
        ];
        $report = $this->saveNewReport($report, false);
        $this->appendActivityLog('recepcion_datos', [
            'message_id' => $report['id'] ?? '',
            'tipo' => $report['tipo'],
            'chat_id_telegram' => $report['chat_id_telegram'],
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

        $category = CatalogMatchSupport::inferCatalogMatch($problem, $categories)
            ?: CatalogMatchSupport::inferCatalogMatch($problem . ' ' . $unitText, $categories)
            ?: 'Equipos';
        $requestUnit = CatalogMatchSupport::inferCatalogMatch($unitText, $units) ?: 'HBV';
        $unit = $unitText !== '' ? $unitText : $requestUnit;
        $requester = $requesterText !== '' ? $requesterText : TextSupport::telegramUserDisplayName($telegramUser);
        $chatId = trim((string) data_get($telegramUser, 'telegram_settings.chat_id', ''));
        $assignee = $this->telegramProjectAssignee($telegramUser, $chatId);

        $report = [
            'chat_id_telegram' => $chatId,
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
            'origen' => 'telegram',
            'created_at' => $now->toAtomString(),
        ];

        $report = $this->saveNewReport($report, false);
        $this->appendActivityLog('recepcion_telegram', [
            'message_id' => $report['id'] ?? '',
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

    public function syncHoursExtraForReport(array $report): void
    {
        $this->hoursExtraRepo()->syncForReport($report);
    }

    public function removeHoursExtraRecord(string $id): void
    {
        $this->hoursExtraRepo()->remove($id);
    }


    /**
     * @return array<int,array<string,mixed>>
     */
    private function hoursExtraFromDatabase(): array
    {
        if (!$this->hoursExtraTableAvailable() || !$this->reportsTableAvailable() || !$this->hoursExtraPivotTableAvailable()) {
            return [];
        }

        $grupos = $this->hoursExtraRepo()->groupsForOrigen();
        if ($grupos === []) {
            return [];
        }

        $reports = collect($this->archivedReportsFromDatabase())
            ->keyBy(static fn (array $report): string => (string) ($report['id'] ?? ''));

        return array_map(function (array $grupo) use ($reports): array {
            $reportRows = array_values(array_filter(array_map(
                static fn (int $id): ?array => $reports->get((string) $id),
                $grupo['reporte_ids']
            )));

            return [
                'fecha'       => DateSupport::databaseDate($grupo['fecha']),
                'hora_inicio' => DateSupport::databaseTime($grupo['hora_inicio']),
                'hora_fin'    => DateSupport::databaseTime($grupo['hora_fin']),
                'reports'     => $reportRows,
                '_source_file' => DateSupport::databaseDate($grupo['fecha']),
            ];
        }, $grupos);
    }

    /**
     * @param array<int,array<string,mixed>> $current
     * @param array<int,array<string,string>> $incoming
     */
    private function catalogRowsChanged(array $current, array $incoming): bool
    {
        return $this->catalogRepo()->rowsChanged($current, $incoming);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function saveNewReport(array $report, bool $archived): array
    {
        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return $report;
        }

        $saved = $this->reportRepo()->insertReport($moduleId, $report, $archived);
        if ($saved === null) {
            return $report;
        }

        $this->activeReportsCache = null;
        $this->archivedReportsCache = null;

        return $saved;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function activeReportFromDatabaseById(string $id): ?array
    {
        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return null;
        }

        return $this->reportRepo()->findActiveById($moduleId, $id, fn (string $assigneeId): string => $this->assignedUserName($assigneeId));
    }

    private function updateActiveReportHoursExtraInDatabase(string $id, bool $enabled): bool
    {
        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return false;
        }

        $updated = $this->reportRepo()->updateActiveHoursExtraFlag($moduleId, $id, $enabled);
        if ($updated) {
            $this->activeReportsCache = null;
        }

        return $updated;
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>|null
     */
    private function updateActiveReportInDatabase(string $id, array $fields): ?array
    {
        $report = $this->activeReportFromDatabaseById($id);
        if ($report === null) {
            return null;
        }

        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return null;
        }

        $fields = array_filter(
            $fields,
            fn ($value, string $field): bool => $this->reportFieldValue($field, $value) !== $this->reportFieldValue($field, $report[$field] ?? null),
            ARRAY_FILTER_USE_BOTH
        );
        if ($fields === []) {
            return $report;
        }

        $report = array_merge($report, $fields);
        $columnByField = [
            'tipo' => 'tipo',
            'asunto' => 'asunto',
            'prioridad' => 'prioridad',
            'categoria' => 'categoria_catalogo_id',
            'asignado_a' => 'asignado_a',
            'solicitante' => 'solicitante',
            'unidad' => 'unidad_catalogo_id',
            'unidad_solicitante' => 'unidad_solicitante_catalogo_id',
            'hora_extra' => 'hora_extra',
            'fecha_inicio' => 'fecha_inicio',
            'fecha_fin' => 'fecha_fin',
            'tiempo_estimado' => 'tiempo_estimado',
            'fecha' => 'fecha',
            'hora' => 'hora',
            'estado' => 'estado',
            'mensaje' => 'mensaje',
            'descripcion' => 'descripcion',
        ];
        $columns = array_values(array_intersect_key($columnByField, $fields));
        $values = Arr::only($this->databaseReportPayload($moduleId, $report, false), $columns);
        $values['actualizado_at'] = now();

        if (!$this->reportRepo()->updateActiveFields($moduleId, $id, $values)) {
            return null;
        }

        $this->activeReportsCache = null;

        return $report;
    }

    private function reportFieldValue(string $field, $value): mixed
    {
        return match ($field) {
            'hora_extra' => $this->reportRepo()->isHoursExtraReport(['hora_extra' => $value]),
            'tiempo_estimado' => $this->reportRepo()->decimalHours($value),
            'fecha', 'fecha_inicio', 'fecha_fin' => DateSupport::parseDate($value),
            'hora' => DateSupport::parseTime($value),
            'asignado_a' => $this->reportRepo()->unsignedIntegerOrNull($value),
            default => trim((string) $value),
        };
    }

    private function deleteActiveReportFromDatabase(string $id): int
    {
        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return 0;
        }

        $deleted = $this->reportRepo()->deleteActiveById($moduleId, $id);
        if ($deleted > 0) {
            $this->activeReportsCache = null;
        }

        return $deleted;
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
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where(function ($query): void {
                    $query->whereNull('estado')->orWhere('estado', '<>', 'archivado');
                })
                ->orderBy('creado_at')
                ->get()
                ->map(fn ($row): array => $this->databaseReportToArray($row))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function saveArchivedReportToDatabase(array $report): array
    {
        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return $report;
        }

        return $this->reportRepo()->upsertArchived($moduleId, $report);
    }

    private function assignedUserName(string $assigneeId): string
    {
        $assigneeId = trim($assigneeId);
        if ($assigneeId === '') {
            return '';
        }

        if ($this->assignedUserNames === null) {
            $this->assignedUserNames = [];
            foreach ($this->users() as $user) {
                $name = trim((string) (($user['nombre'] ?? $user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')));
                if ($name === '') {
                    $name = trim((string) ($user['username'] ?? $user['usuario'] ?? ''));
                }
                if ($name === '') {
                    continue;
                }

                foreach ([
                    $user['id'] ?? '',
                    $user['redmine_id'] ?? '',
                    $user['rut'] ?? '',
                    $user['rut_sin_dv'] ?? '',
                ] as $identity) {
                    $identity = trim((string) $identity);
                    if ($identity !== '' && !isset($this->assignedUserNames[$identity])) {
                        $this->assignedUserNames[$identity] = $name;
                    }
                }
            }
        }

        return (string) ($this->assignedUserNames[$assigneeId] ?? '');
    }

    /**
     * @param object $row
     * @return array<string,mixed>
     */
    private function databaseReportToArray(object $row): array
    {
        return $this->reportRepo()->hydrate($row, fn (string $id): string => $this->assignedUserName($id));
    }

    /**
     * @return array<string,mixed>
     */
    private function databaseReportPayload(int $moduleId, array $report, bool $archived): array
    {
        return $this->reportRepo()->payload($moduleId, $report, $archived);
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
                'nombre'       => $this->projectName(),
                'descripcion'  => '',
                'icono'        => '',
                'tipo'         => 'native',
                'ruta'         => $this->projectKey,
                'entrada'      => 'laravel:redmine.native.dashboard',
                'habilitado'   => 1,
                'orden'        => 100,
                'creado_at'    => now(),
                'actualizado_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function reportsTableAvailable(): bool
    {
        if ($this->reportsTableAvailableCache !== null) {
            return $this->reportsTableAvailableCache;
        }
        try {
            return $this->reportsTableAvailableCache = Schema::hasTable('modulos_nova') && Schema::hasTable('redmine_tic_reportes');
        } catch (\Throwable) {
            return $this->reportsTableAvailableCache = false;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function archivedReportsFromDatabase(): array
    {
        if (!$this->reportsTableAvailable()) {
            return [];
        }

        $moduleId = $this->databaseModuleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where('estado', 'archivado')
                ->orderByDesc('actualizado_at')
                ->get()
                ->map(fn ($row): array => $this->databaseReportToArray($row))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function saveCatalogRowsToDatabase(string $type, array $rows, bool $deactivateMissing = true): void
    {
        $this->catalogRepo()->saveCatalogRowsToDatabase($type, $rows, $deactivateMissing);
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * @param array<string,mixed> $config
     * @param array<string,string> $types
     */
    private function saveModuleConfigurationToDatabase(array $config, array $types = []): void
    {
        $this->configRepo()->saveToDatabase($config, $types);
    }

    /** @param array<string,array<string,mixed>> $roles */
    private function saveRolesToDatabase(array $roles): void
    {
        $this->permissionRepo()->saveRolesToRelational($roles);
        $this->saveModuleConfigurationToDatabase(['roles' => $roles], ['roles' => 'json']);
    }

    // -------------------------------------------------------------------------
    // Permission helpers — delegated to RedminePermissionRepository
    // -------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>>|null */
    private function allPermissionsFromRelational(): ?array
    {
        return $this->permissionRepo()->allPermissionsFromRelational();
    }

    /** @param array<string,mixed> $permissions */
    private function savePermissionsToRelational(int $perfilId, array $permissions): void
    {
        $this->permissionRepo()->savePermissionsToRelational($perfilId, $permissions);
    }

    // -------------------------------------------------------------------------

    private function hoursExtraTableAvailable(): bool
    {
        return $this->hoursExtraRepo()->tableAvailable();
    }

    private function hoursExtraPivotTableAvailable(): bool
    {
        return $this->hoursExtraRepo()->pivotTableAvailable();
    }

    private function archiveReport(array $report): void
    {
        $this->archivedReportsCache = null;
        $report['estado'] = 'archivado';
        $report = $this->saveArchivedReportToDatabase($report);

        if ($this->reportRepo()->isHoursExtraReport($report)) {
            $this->syncHoursExtraForReport($report);
        }
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

        $matchedName = CatalogMatchSupport::inferCatalogMatch($category, array_values(array_filter(array_map(
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
                'name' => TextSupport::telegramUserDisplayName($telegramUser),
            ];
        }

        $legacyId = trim((string) data_get($telegramUser, 'redmine_tic_user.id', ''));
        if ($legacyId !== '') {
            return [
                'id' => $legacyId,
                'name' => TextSupport::telegramUserDisplayName($telegramUser),
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
                    'name' => TextSupport::joinPersonName((string) ($user['nombre'] ?? ''), (string) ($user['apellido'] ?? '')),
                ];
            }
        }

        return ['id' => '', 'name' => ''];
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
     * @param array<string,mixed> $entry
     */
    private function appendSendLog(array $entry): void
    {
        $this->appendActivityLog('envio_redmine_http', $entry);
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $context
     */
    private function formatErrorLogEntry(array $entry, array $context): string
    {
        $lines = [
            '[' . trim((string) ($entry['ts'] ?? 'sin fecha')) . '] ' . trim((string) ($entry['event'] ?? 'envio_redmine_error')),
        ];

        if (array_key_exists('http_code', $context)) {
            $lines[] = 'HTTP: ' . (string) $context['http_code'];
        }

        $error = trim((string) ($context['error'] ?? ''));
        if ($error !== '') {
            $lines[] = 'Error: ' . TextSupport::truncateLogValue($error);
        }

        $body = trim((string) ($context['body'] ?? ''));
        if ($body !== '') {
            $lines[] = 'Body: ' . TextSupport::truncateLogValue($body);
        }

        if (isset($context['payload'])) {
            $payload = json_encode($context['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($payload) && $payload !== '') {
                $lines[] = 'Payload: ' . TextSupport::truncateLogValue($payload, 1200);
            }
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function appendActivityLog(string $event, array $context = []): void
    {
        $this->activityRepo()->append($event, $context);
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
            $groupDate = DateSupport::normalizeDateKey((string) ($group['fecha'] ?? ''));
            foreach ($reports as $report) {
                if (!is_array($report)) {
                    continue;
                }
                $date = DateSupport::normalizeDateKey((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? $groupDate));
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
                $key = (string) ($report['id'] ?? (($report['chat_id_telegram'] ?? $report['numero'] ?? '') . '|' . ($report['hora'] ?? '') . '|' . ($report['asunto'] ?? '')));
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
            return [];
        }

        $userId = trim((string) ($user['redmine_id'] ?? $user['id'] ?? data_get($user, 'legacy.id', '')));
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
                if (TextSupport::nameTokensMatch($candidateName, $assignedName)) {
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

    private function normalizeDashboardFilter(string $filter): string
    {
        $filter = strtolower(trim($filter));

        return in_array($filter, ['todos', 'pendientes', 'procesados', 'errores'], true) ? $filter : 'todos';
    }
}
