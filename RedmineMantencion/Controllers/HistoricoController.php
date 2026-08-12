<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionHistoricoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class HistoricoController extends Controller
{
    public function __construct(private readonly MantencionHistoricoService $historico) {}

    /**
     * Histórico. Migrado desde RedmineMantencion/views/Historico/historico.php
     * (lógica de datos, líneas 1-445 del archivo original). El HTML se
     * conserva en resources/views/redmine-mantencion/historico.blade.php.
     */
    public function index(): View|JsonResponse|RedirectResponse
    {
        require_once base_path('RedmineMantencion/controllers/dashboard.php');
        require_once base_path('RedmineMantencion/controllers/storage.php');
        require_once base_path('RedmineMantencion/controllers/maintenance.php');

        if (! auth_can('historico')) {
            return redirect(legacy_app_url());
        }

        $h = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $maintenanceMode = maintenance_mode_enabled();
        $csrf = legacy_csrf_token();
        $mantencionBaseUrl = function_exists('legacy_app_url')
            ? rtrim(legacy_app_url(), '/')
            : (function_exists('url') ? rtrim(url('/redmine-mantencion'), '/') : '/redmine-mantencion');

        // --- Eliminar si se solicito ---
        $alert = '';
        $ok = false;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'], $_POST['id'], $_POST['fuente']) && $_POST['action'] === 'delete') {
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }
            if (function_exists('csrf_validate')) {
                csrf_validate();
            }
            if ($maintenanceMode || ! auth_can('historico_eliminar')) {
                abort(403, 'No tienes permiso para eliminar registros del histórico.');
            }
            $id = trim($_POST['id']);
            $src = $_POST['fuente'];
            $deleteCanAct = true;
            $deleteScope = 'asignados';
            $deleteUserId = (string) auth_get_user_id();
            $deleteUserNames = array_values(array_filter([
                trim((string) (mantencion_current_user()['nombre'] ?? '')),
                trim((string) (auth_find_user_by_id($deleteUserId)['nombre'] ?? '')),
            ], fn ($value) => $value !== ''));
            $sourceRows = $src === 'reportes'
                ? $this->historico->loadReportes()
                : ($src === 'horas_extra' ? $this->historico->loadHorasExtras() : []);
            $target = null;
            foreach ($sourceRows as $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
                    $target = $row;
                    break;
                }
            }
            $canDeleteTarget = $deleteCanAct
                && is_array($target)
                && ($deleteScope === 'todos' || $this->historico->recordMatchesCurrentUser($target, $deleteUserId, $deleteUserNames));

            if ($canDeleteTarget) {
                if ($src === 'reportes') {
                    $ok = $this->historico->deleteReporte($id);
                } elseif ($src === 'horas_extra') {
                    $ok = $this->historico->deleteHorasExtra($id);
                }
            }
            $alert = $ok ? 'Reporte eliminado.' : 'No se pudo eliminar el registro.';
        }

        $f_desde = $this->historico->normDate($_GET['desde'] ?? '');
        $f_hasta = $this->historico->normDate($_GET['hasta'] ?? '');
        $f_usuario = trim($_GET['usuario'] ?? '');
        $f_categoria = strtolower(trim($_GET['categoria'] ?? ''));
        $f_fuente = $_GET['fuente'] ?? '';
        $f_estado_redmine = trim((string) ($_GET['estado_redmine'] ?? ''));
        $f_busqueda = trim($_GET['buscar'] ?? '');
        $f_descripcion = trim($_GET['descripcion'] ?? '');
        $perPageOptions = [25, 50, 100];
        $perPage = (int) ($_GET['per_page'] ?? 25);
        if (! in_array($perPage, $perPageOptions, true)) {
            $perPage = 25;
        }
        $currentPage = max(1, (int) ($_GET['page'] ?? 1));
        $scopePermitido = 'asignados';
        $scopeBloqueado = ($scopePermitido === 'asignados');
        $canChangeHistoryStatus = ! $maintenanceMode && auth_can('historico_estado');
        $canDeleteHistory = ! $maintenanceMode && auth_can('historico_eliminar');
        $showActions = $canChangeHistoryStatus || $canDeleteHistory;
        $tableColspan = 9 + ($canChangeHistoryStatus ? 1 : 0) + ($showActions ? 1 : 0);
        $f_scope = $_GET['mensajes_scope'] ?? ($scopePermitido === 'todos' ? 'todos' : 'asignados');
        if (! in_array($f_scope, ['todos', 'asignados'], true)) {
            $f_scope = 'asignados';
        }
        if ($scopePermitido === 'asignados') {
            $f_scope = 'asignados';
        }
        $userId = (string) auth_get_user_id();
        $userNames = array_values(array_filter([
            trim((string) (mantencion_current_user()['nombre'] ?? '')),
            trim((string) (auth_find_user_by_id($userId)['nombre'] ?? '')),
        ], fn ($value) => $value !== ''));

        $cfg = load_platform_config();
        $redminePlatformUrl = (string) ($cfg['platform_url'] ?? '');
        $redmineToken = load_user_api_token($userId);
        $redmineStatusOptions = $this->historico->redmineStatusOptions();

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_redmine_status') {
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }
            if (function_exists('csrf_validate')) {
                csrf_validate();
            }

            $updated = 0;
            $statusId = (int) ($_POST['status_id'] ?? 0);
            $statusName = $this->historico->redmineStatusName($statusId);
            $requestedIds = is_array($_POST['redmine_ids'] ?? null)
                ? $_POST['redmine_ids']
                : explode(',', (string) ($_POST['redmine_ids'] ?? ''));
            $requestedIds = array_slice(array_values(array_unique(array_filter(array_map(
                static function ($id): string {
                    $id = trim((string) $id);

                    return preg_match('/^\d+$/', $id) ? $id : '';
                },
                $requestedIds
            )))), 0, 100);

            if (! $canChangeHistoryStatus) {
                http_response_code(403);
                $alert = 'No tienes permiso para cambiar estados desde el histórico.';
            } elseif ($statusName === null) {
                $alert = 'Selecciona un estado Redmine válido.';
            } elseif ($requestedIds === []) {
                $alert = 'Selecciona al menos un reporte abierto.';
            } elseif (trim($redmineToken) === '') {
                $alert = 'Configura tu API Key personal de Redmine en Mis integraciones antes de cambiar estados.';
            } else {
                $reportRepo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
                $allowedTickets = [];
                foreach ($reportRepo?->archivedMessages() ?? [] as $archivedRow) {
                    if (! is_array($archivedRow) || ! $this->historico->recordMatchesCurrentUser($archivedRow, $userId, $userNames)) {
                        continue;
                    }
                    $ticketId = trim((string) ($archivedRow['redmine_id'] ?? ''));
                    if (preg_match('/^\d+$/', $ticketId)) {
                        $allowedTickets[$ticketId] = true;
                    }
                }

                $errors = [];
                foreach ($requestedIds as $ticketId) {
                    if (! isset($allowedTickets[$ticketId])) {
                        $errors[] = '#'.$ticketId.': no pertenece a tu histórico disponible.';

                        continue;
                    }

                    $currentStatus = $this->historico->fetchRedmineStatus($redminePlatformUrl, $ticketId, $redmineToken);
                    if (! ($currentStatus['available'] ?? false)) {
                        $errors[] = '#'.$ticketId.': no se pudo confirmar el estado actual.';

                        continue;
                    }
                    if ($currentStatus['closed'] ?? false) {
                        $errors[] = '#'.$ticketId.': ya está cerrado en Redmine.';

                        continue;
                    }

                    $result = $this->historico->updateRedmineStatus($redminePlatformUrl, $ticketId, $statusId, $redmineToken);
                    if (! ($result['ok'] ?? false)) {
                        $errors[] = '#'.$ticketId.': '.trim((string) ($result['error'] ?? 'no se pudo actualizar.'));

                        continue;
                    }

                    $reportRepo?->updateRedmineStatus($ticketId, $statusId, $statusName);
                    $updated++;
                }

                $ok = $updated > 0;
                $alert = $updated.' reporte(s) actualizado(s) a “'.$statusName.'” en Redmine.';
                if ($errors !== []) {
                    $alert .= ' No actualizados: '.implode(' ', array_slice($errors, 0, 5));
                    if (count($errors) > 5) {
                        $alert .= ' y '.(count($errors) - 5).' más.';
                    }
                }

                dashboard_log_action(
                    'REDMINE_STATUS_UPDATE',
                    'Estado "'.$statusName.'" solicitado para '.count($requestedIds)
                        .' ticket(s); actualizados='.$updated.'; fallidos='.count($errors)
                );
            }
        }

        if (($_GET['ajax'] ?? '') === 'redmine_statuses') {
            $ids = array_values(array_unique(array_filter(array_map(
                static function ($id): string {
                    $id = trim((string) $id);

                    return preg_match('/^\d+$/', $id) ? $id : '';
                },
                explode(',', (string) ($_GET['ids'] ?? ''))
            ))));
            $ids = array_slice($ids, 0, 100);
            $statuses = [];
            foreach ($ids as $id) {
                if ($id === '') {
                    continue;
                }
                $statuses[$id] = $this->historico->fetchRedmineStatus($redminePlatformUrl, $id, $redmineToken);
            }

            return response()->json(['ok' => true, 'statuses' => $statuses]);
        }

        $items = [];
        $items = array_merge($items, $this->historico->loadReportes());
        $items = array_merge($items, $this->historico->loadHorasExtras());

        $redmineStatusesSel = [];
        foreach ($redmineStatusOptions as $statusLabel) {
            $statusLabel = trim((string) $statusLabel);
            if ($statusLabel !== '') {
                $redmineStatusesSel[$statusLabel] = $statusLabel;
            }
        }

        $filtered = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! in_array(strtolower(trim((string) ($row['estado'] ?? ''))), ['procesado', 'archivado'], true)) {
                continue;
            }
            $fecha = $this->historico->normDate($row['fecha'] ?? ($row['fecha_inicio'] ?? ''));
            if ($fecha === '') {
                continue;
            }
            if ($f_desde && $fecha < $f_desde) {
                continue;
            }
            if ($f_hasta && $fecha > $f_hasta) {
                continue;
            }
            if ($f_fuente && ($row['_fuente'] ?? '') !== $f_fuente) {
                continue;
            }
            if ($f_estado_redmine !== '') {
                $rowRedmineStatus = trim((string) ($row['estado_redmine'] ?? $row['redmine_estado'] ?? $row['status_name'] ?? ''));
                if ($rowRedmineStatus === '') {
                    $rowStatusId = (int) ($row['status_id'] ?? $row['estado_id'] ?? 0);
                    $rowRedmineStatus = trim((string) ($redmineStatusOptions[$rowStatusId] ?? ''));
                }
                if (dashboard_normalize_text($rowRedmineStatus) !== dashboard_normalize_text($f_estado_redmine)) {
                    continue;
                }
            }
            if ($f_usuario !== '' && (string) ($row['asignado_a'] ?? '') !== (string) $f_usuario) {
                continue;
            }
            if ($f_scope === 'asignados' && ! $this->historico->recordMatchesCurrentUser($row, $userId, $userNames)) {
                continue;
            }
            $cat = strtolower($row['categoria'] ?? '');
            if ($f_categoria !== '' && $cat !== $f_categoria) {
                continue;
            }
            if (! $this->historico->matchesSearch($row, $f_busqueda)) {
                continue;
            }
            if ($f_descripcion !== '') {
                $descriptionNeedle = dashboard_normalize_text($f_descripcion);
                $descriptionText = dashboard_normalize_text((string) ($row['descripcion'] ?? ''));
                if ($descriptionNeedle !== '' && ! str_contains($descriptionText, $descriptionNeedle)) {
                    continue;
                }
            }
            $row['_fecha_norm'] = $fecha;
            $filtered[] = $row;
        }

        if ($f_fuente === '') {
            $filtered = $this->historico->dedupeRows($filtered);
        }

        usort($filtered, function ($a, $b) {
            return strcmp($b['_fecha_norm'] ?? '', $a['_fecha_norm'] ?? '');
        });

        $totalFiltered = count($filtered);
        $totalPages = max(1, (int) ceil($totalFiltered / $perPage));
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }
        $pageOffset = ($currentPage - 1) * $perPage;
        $pagedRows = array_slice($filtered, $pageOffset, $perPage);
        $visibleRows = count($pagedRows);
        $historicoFilterChips = [];
        if ($f_desde !== '') {
            $historicoFilterChips[] = ['icon' => 'bi-calendar-event', 'label' => 'Desde '.$this->historico->formatDate($f_desde), 'remove' => 'desde'];
        }
        if ($f_hasta !== '') {
            $historicoFilterChips[] = ['icon' => 'bi-calendar-check', 'label' => 'Hasta '.$this->historico->formatDate($f_hasta), 'remove' => 'hasta'];
        }
        if ($f_fuente !== '') {
            $historicoFilterChips[] = ['icon' => 'bi-inboxes', 'label' => 'Fuente '.$f_fuente, 'remove' => 'fuente'];
        }
        if ($f_estado_redmine !== '') {
            $historicoFilterChips[] = ['icon' => 'bi-kanban', 'label' => 'Estado Redmine '.$f_estado_redmine, 'remove' => 'estado_redmine'];
        }
        if ($f_busqueda !== '') {
            $historicoFilterChips[] = ['icon' => 'bi-search', 'label' => 'Busqueda '.$f_busqueda, 'remove' => 'buscar'];
        }
        if ($f_descripcion !== '') {
            $historicoFilterChips[] = ['icon' => 'bi-card-text', 'label' => 'Descripción '.$f_descripcion, 'remove' => 'descripcion'];
        }
        if ($f_categoria !== '') {
            $historicoFilterChips[] = ['icon' => 'bi-tags', 'label' => 'Categoria '.$f_categoria, 'remove' => 'categoria'];
        }
        if (! $scopeBloqueado && $f_usuario !== '') {
            $historicoFilterChips[] = ['icon' => 'bi-person', 'label' => 'Asignado '.$f_usuario, 'remove' => 'usuario'];
        }
        $historicoBaseUrl = route('redmine.mantencion.section', ['section' => 'historico']);
        $historicoStateQuery = array_filter([
            'desde' => $f_desde,
            'hasta' => $f_hasta,
            'fuente' => $f_fuente,
            'estado_redmine' => $f_estado_redmine,
            'descripcion' => $f_descripcion,
            'buscar' => $f_busqueda,
            'categoria' => $f_categoria,
            'usuario' => $scopeBloqueado ? '' : $f_usuario,
            'mensajes_scope' => $f_scope,
            'per_page' => $perPage,
            'page' => $currentPage,
        ], static fn ($value): bool => $value !== '');
        $historicoActionUrl = $historicoBaseUrl.'?'.http_build_query($historicoStateQuery);
        $historicoChipUrl = static function (string $key) use ($perPage, $historicoBaseUrl): string {
            $query = $_GET;
            unset($query[$key]);
            $query['page'] = 1;
            $query['per_page'] = $perPage;

            return $historicoBaseUrl.($query ? '?'.http_build_query($query) : '');
        };
        $historicoPageUrl = static function (int $page) use ($perPage, $historicoBaseUrl): string {
            $query = $_GET;
            $query['page'] = max(1, $page);
            $query['per_page'] = $perPage;

            return $historicoBaseUrl.'?'.http_build_query($query);
        };

        $usuariosSel = [];
        $catsSel = [];
        foreach ($items as $r) {
            if (! is_array($r)) {
                continue;
            }
            $usuariosSel[(string) ($r['asignado_a'] ?? '')] = $r['asignado_nombre'] ?? ($r['asignado_a'] ?? '');
            $catsSel[strtolower($r['categoria'] ?? '')] = $r['categoria'] ?? '';
        }
        ksort($usuariosSel);
        ksort($catsSel);

        $historicoService = $this->historico;

        return view('redmine-mantencion.historico', get_defined_vars());
    }
}
