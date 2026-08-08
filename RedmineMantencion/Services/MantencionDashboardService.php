<?php

namespace App\Modulos\RedmineMantencion\Services;

class MantencionDashboardService
{
    private readonly MantencionCoreImportService $coreImport;
    private readonly MantencionRedmineSyncService $redmineSync;
    private readonly MantencionRetentionService $retention;

    public function __construct(MantencionCoreImportService $coreImport, MantencionRedmineSyncService $redmineSync, MantencionRetentionService $retention)
    {
        $this->coreImport = $coreImport;
        $this->redmineSync = $redmineSync;
        $this->retention = $retention;
    }

    public function dashboard_compact_message(array $message): array {
        if (($message['fuente'] ?? '') !== 'core') {
            return $message;
        }
        $compact = [];
        foreach ($this->coreImport->dashboard_core_compact_keys() as $key) {
            if (array_key_exists($key, $message)) {
                $compact[$key] = $message[$key];
            }
        }
        $compact['fuente'] = 'core';
        $compact['estado'] = trim((string)($compact['estado'] ?? '')) !== '' ? $compact['estado'] : 'pendiente';
        $compact['hora_extra'] = trim((string)($compact['hora_extra'] ?? '')) !== '' ? $compact['hora_extra'] : '0';
        $compact['tiempo_estimado'] = trim((string)($compact['tiempo_estimado'] ?? ''));
        $compact['redmine_id'] = trim((string)($compact['redmine_id'] ?? ''));
        $compact['procesado_ts'] = trim((string)($compact['procesado_ts'] ?? ''));
        return $compact;
    }

    public function dashboard_consume_flash(): ?string {
        return session()->pull('mantencion_flash');
    }

    public function dashboard_enforced_assigned_name(string $submitted = ''): string {
        $current = dashboard_current_user_full_name();
        if (!$this->coreImport->dashboard_can_select_core_assignee()) {
            return $current;
        }
        $submitted = trim($submitted);
        if ($submitted !== '' && dashboard_find_active_user_by_name($submitted) !== null) {
            return $submitted;
        }

        return $current;
    }

    public function dashboard_hora_extra_default_time(string $default = '1'): string {
        $cfg = load_platform_config();
        $value = trim((string)($cfg['hora_extra_tiempo_estimado'] ?? $default));
        return $value !== '' ? $value : $default;
    }

    public function dashboard_is_ajax_request(): bool {
        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (string)($_POST['ajax'] ?? '') === '1';
    }

    public function dashboard_messages_scope(): string {
        return 'asignados';
    }

    public function dashboard_normalize_stored_date($value): string {
        $parsed = parse_issue_date((string)$value);
        if ($parsed === null) {
            return trim((string)$value);
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $parsed);
        return $dt instanceof \DateTimeImmutable ? $dt->format('d-m-Y') : trim((string)$value);
    }

    public function dashboard_redirect_back(): \Illuminate\Http\RedirectResponse {
        return redirect(request()->fullUrl());
    }

    public function dashboard_required_permission_for_action(string $action): ?string {
        return match ($action) {
            'update', 'process_selected', 'archive_selected', 'reset_errors' => 'reportes_editar',
            'delete', 'delete_selected' => 'reportes_eliminar',
            'import_core_history' => 'reportes_importar_core',
            'toggle_hora_extra' => 'horas_extra_editar',
            default => null,
        };
    }

    public function dashboard_security_ids_count(array $ids): int {
        return count(array_values(array_filter(array_map('trim', $ids), static fn(string $id): bool => $id !== '')));
    }

    public function dashboard_status_counts(array $messages): array {
        $counts = [
            'pendiente' => 0,
            'procesado' => 0,
            'error' => 0,
        ];
        foreach ($messages as $message) {
            $status = strtolower((string)($message['estado'] ?? ''));
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        return $counts;
    }

    public function message_is_procesado(array $message): bool {
        return strtolower(trim((string) ($message['estado'] ?? ''))) === 'procesado';
    }

    public function dashboard_filter_ids_by_scope(array $messages, array $ids): array {
        $allowed = dashboard_accessible_message_ids($messages);
        return array_values(array_filter(array_map('trim', $ids), static fn(string $id): bool => $id !== '' && isset($allowed[$id])));
    }

    public function handle_request(): array|\Illuminate\Http\RedirectResponse {
        $messages = load_messages();
        $userId = auth_get_user_id();
        $userToken = load_user_api_token($userId);
        if (!maintenance_mode_enabled() && $this->retention->apply_retention_archive($messages)) {
            save_messages($messages);
        }
        $flash = $this->dashboard_consume_flash();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_validate();

            // Nota histórica: este módulo solía liberar aquí el lock del archivo
            // de sesión PHP nativa (NOVALEGACY) para no serializar acciones AJAX
            // concurrentes del mismo usuario. Ya no aplica: Mantención no abre
            // sesión PHP nativa — corre enteramente sobre la sesión de Laravel,
            // que no retiene ese lock por el resto del request.
            $action = $_POST['action'] ?? '';
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }
            $flashMsg = null;
            $ajaxAction = $this->dashboard_is_ajax_request();
            $ajaxPayload = [
                'ok' => true,
                'action' => $action,
                'message' => '',
                'ids' => [],
            ];
            $requiredPermission = $this->dashboard_required_permission_for_action((string)$action);
            if ($requiredPermission !== null && !auth_can($requiredPermission)) {
                $permissionLabels = [
                    'reportes_editar' => 'editar reportes',
                    'reportes_eliminar' => 'eliminar reportes',
                    'reportes_importar_core' => 'importar reportes desde CORE',
                    'horas_extra_editar' => 'editar Horas extra',
                ];
                $flashMsg = 'No tienes permiso para ' . ($permissionLabels[$requiredPermission] ?? 'ejecutar esta acción') . '.';
                $ajaxPayload['ok'] = false;
                if ($ajaxAction || $action === 'toggle_hora_extra') {
                    dashboard_json_response(array_merge($ajaxPayload, ['message' => $flashMsg]), 403);
                }
                http_response_code(403);
                exit($flashMsg);
            }
            switch ($action) {
                case 'update':
                    $id = $_POST['id'] ?? '';
                    if ($id === '') {
                        $flashMsg = 'Falta el identificador del mensaje.';
                        break;
                    }
                    if (!dashboard_can_access_message($messages, (string)$id)) {
                        $flashMsg = 'No tienes acceso a este mensaje.';
                        $ajaxPayload['ok'] = false;
                        break;
                    }
                    $updated = false;
                    $fields = [
                        'asunto','categoria',
                        'asignado_a','solicitante','unidad','unidad_solicitante','establecimiento','departamento',
                        'hora_extra','fecha_inicio','fecha_fin','tiempo_estimado',
                        'fecha','hora','numero','descripcion','core_email'
                    ];
                    if (!auth_can('horas_extra_editar')) {
                        $fields = array_values(array_diff($fields, ['hora_extra', 'tiempo_estimado']));
                    }
                    $updatedMessage = null;
                    foreach ($messages as &$message) {
                        if (($message['id'] ?? '') !== $id) {
                            continue;
                        }
                        foreach ($fields as $field) {
                            if (isset($_POST[$field])) {
                                $value = $_POST[$field];
                                if (in_array($field, ['fecha_inicio', 'fecha_fin', 'fecha'], true)) {
                                    $value = $this->dashboard_normalize_stored_date($value);
                                }
                                $message[$field] = $value;
                            }
                        }
                        if (!dashboard_can_assign_other_users()) {
                            $currentUser = dashboard_current_user();
                            $currentUserId = trim((string)($currentUser['id'] ?? auth_get_user_id() ?? ''));
                            $currentUserName = dashboard_current_user_full_name();
                            if ($currentUserId !== '') {
                                $message['asignado_a'] = $currentUserId;
                            }
                            if ($currentUserName !== '') {
                                $message['asignado_nombre'] = $currentUserName;
                            }
                        } else {
                            $postedAssignee = trim((string)($message['asignado_a'] ?? ''));
                            if ($postedAssignee !== '' && dashboard_find_user_name($postedAssignee) === '') {
                                $message['asignado_a'] = '';
                            }
                        }
                        $establecimiento = trim((string)($_POST['establecimiento'] ?? $message['core_establecimiento'] ?? $message['unidad_solicitante'] ?? ''));
                        $departamento = trim((string)($_POST['departamento'] ?? $message['core_departamento'] ?? $message['unidad'] ?? ''));
                        $message['establecimiento'] = $establecimiento;
                        $message['departamento'] = $departamento;
                        $message['core_establecimiento'] = $establecimiento;
                        $message['core_departamento'] = $departamento !== '' ? $departamento : $establecimiento;
                        $message['unidad_solicitante'] = $establecimiento;
                        $message['unidad'] = $departamento !== '' ? $departamento : $establecimiento;
                        if (($message['fuente'] ?? '') === 'manual') {
                            $message = dashboard_expand_manual_message($message);
                        }
                        $updated = true;
                        $updatedMessage = $message;
                        break;
                    }
                    unset($message);
                    if ($updated) {
                        // Punctual update: persist only the one edited record via
                        // save_messages()/syncMessages()'s existing per-message upsert,
                        // instead of resyncing every message in the account for a single
                        // edit. Same function, same guards (tableReady()/try-catch inside
                        // the repository) — just scoped to what actually changed. Mirrors
                        // the pattern already used by 'toggle_hora_extra' and 'delete' in
                        // this same file (see dashboard_update_message_hora_extra()).
                        save_messages([$updatedMessage]);
                        if (is_array($updatedMessage)) {
                            append_hours_extra_record($updatedMessage);
                        }
                        $flashMsg = 'Mensaje actualizado.';
                        dashboard_log_action('REPORT_UPDATE', 'Edito reporte ID ' . $id);
                    } else {
                        $flashMsg = 'No se encontró el mensaje.';
                    }
                    break;
                case 'toggle_hora_extra':
                    $id = trim((string)($_POST['id'] ?? ''));
                    if ($id === '') {
                        $flashMsg = 'Identificador no valido.';
                        break;
                    }
                    if (!dashboard_can_access_message($messages, $id)) {
                        $flashMsg = 'No tienes acceso a este mensaje.';
                        $ajaxPayload['ok'] = false;
                        break;
                    }
                    $updated = false;
                    $isEnabled = false;
                    $updatedMessage = null;
                    foreach ($messages as &$message) {
                        if (($message['id'] ?? '') !== $id) {
                            continue;
                        }
                        $current = normalize_hour_extra_value($message['hora_extra'] ?? '');
                        $isEnabled = $current !== '1';
                        $message['hora_extra'] = $isEnabled ? '1' : '0';
                        if ($isEnabled) {
                            $message['tiempo_estimado'] = $this->dashboard_hora_extra_default_time('1');
                        } else {
                            $message['tiempo_estimado'] = '';
                        }
                        $updatedMessage = $message;
                        $updated = true;
                        break;
                    }
                    unset($message);
                    if ($updated) {
                        if ($isEnabled && is_array($updatedMessage)) {
                            if (!dashboard_update_message_hora_extra($updatedMessage)) {
                                $flashMsg = 'No se pudo actualizar la hora extra.';
                                $ajaxPayload['ok'] = false;
                                break;
                            }
                            append_hours_extra_record($updatedMessage);
                        } else {
                            remove_hours_extra_record_by_id($id);
                        }
                        $flashMsg = $isEnabled ? 'Hora extra activada.' : 'Hora extra desactivada.';
                        dashboard_log_action('HORA_EXTRA', ($isEnabled ? 'Activo' : 'Desactivo') . ' hora extra en reporte ID ' . $id);
                        $ajaxPayload['ids'] = [$id];
                        $ajaxPayload['row'] = [
                            'id' => $id,
                            'hora_extra' => $isEnabled ? '1' : '0',
                            'tiempo_estimado' => $isEnabled ? $this->dashboard_hora_extra_default_time('1') : '',
                            'title' => $isEnabled ? 'Hora extra: Sí. Cambiar a No' : 'Hora extra: No. Cambiar a Sí',
                            'icon' => $isEnabled ? 'bi-clock-fill' : 'bi-clock',
                            'buttonClass' => $isEnabled ? 'btn-hora-extra--on' : 'btn-hora-extra--off',
                        ];
                    } else {
                        $flashMsg = 'No se encontro el mensaje.';
                        $ajaxPayload['ok'] = false;
                    }
                    break;
                case 'delete':
                    $id = $_POST['id'] ?? '';
                    if ($id === '') {
                        $flashMsg = 'Identificador no válido.';
                        $ajaxPayload['ok'] = false;
                        break;
                    }
                    if (!dashboard_can_access_message($messages, (string)$id)) {
                        $flashMsg = 'No tienes acceso a este mensaje.';
                        $ajaxPayload['ok'] = false;
                        break;
                    }
                    $deletedFuenteIds = [];
                    $deletedPkIds = [];
                    foreach ($messages as $m) {
                        if (is_array($m) && ($m['id'] ?? '') === $id) {
                            $fid = trim((string)($m['fuente_id'] ?? ''));
                            if ($fid !== '') {
                                $deletedFuenteIds[] = $fid;
                            } elseif (ctype_digit((string)($m['id'] ?? ''))) {
                                $deletedPkIds[] = (int)$m['id'];
                            }
                            break;
                        }
                    }
                    if (!empty($deletedFuenteIds) || !empty($deletedPkIds)) {
                        $dbRepo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
                        if ($dbRepo !== null && !empty($deletedFuenteIds)) {
                            $dbRepo->deleteByFuenteIds($deletedFuenteIds);
                        }
                        if (!empty($deletedPkIds)) {
                            try {
                                \Illuminate\Support\Facades\DB::table('redmine_mantencion_reportes')
                                    ->whereIn('id', $deletedPkIds)
                                    ->delete();
                            } catch (\Throwable) {
                            }
                        }
                        $flashMsg = 'Mensaje eliminado.';
                        dashboard_log_action('REPORT_DELETE', 'Elimino reporte ID ' . $id);
                        $ajaxPayload['ids'] = [$id];
                    } else {
                        $flashMsg = 'No se encontró el mensaje para eliminar.';
                        $ajaxPayload['ok'] = false;
                    }
                    break;
                case 'process_selected':
                    // se resuelve después del switch para incluir resultados del envío.
                    break;
                case 'import_core_history':
                    if (function_exists('maintenance_mode_block_if_enabled')) {
                        maintenance_mode_block_if_enabled();
                    }
                    $desde = trim((string)($_POST['core_desde'] ?? ''));
                    $hasta = trim((string)($_POST['core_hasta'] ?? ''));
                    $canSelectCoreAssignee = $this->coreImport->dashboard_can_select_core_assignee();
                    $assigned = $this->dashboard_enforced_assigned_name((string)($_POST['core_assigned_name'] ?? ''));
                    if (!$canSelectCoreAssignee && $assigned === '') {
                        $flashMsg = 'No se pudo identificar al usuario conectado para filtrar las solicitudes de CORE.';
                        $ajaxPayload['ok'] = false;
                        break;
                    }
                    $coreUser = trim((string)($_POST['core_runtime_user'] ?? ''));
                    $corePass = trim((string)($_POST['core_runtime_pass'] ?? ''));
                    $rememberCore = !empty($_POST['core_remember_credentials']);
                    if ($coreUser === '' || $corePass === '') {
                        $savedCoreCredentials = $this->coreImport->dashboard_core_credentials_for_current_user();
                        if ($coreUser === '') {
                            $coreUser = trim((string)($savedCoreCredentials['user'] ?? ''));
                        }
                        if ($corePass === '') {
                            $corePass = trim((string)($savedCoreCredentials['pass'] ?? ''));
                        }
                    }
                    $currentUserData = dashboard_current_user();
                    $coreCredentialUserKey = $this->coreImport->dashboard_core_current_credential_user_key($currentUserData);
                    $result = $this->coreImport->dashboard_sync_core_history($messages, [
                        'desde' => $desde,
                        'hasta' => $hasta,
                        'assigned' => $assigned,
                        '_current_user' => !$canSelectCoreAssignee && is_array($currentUserData) ? $currentUserData : [],
                    ], true, [
                        'user' => $coreUser,
                        'pass' => $corePass,
                    ]);
                    $coreCredentialsSaved = null;
                    if ($rememberCore && $coreUser !== '' && $corePass !== '' && (empty($result['error']) || !empty($result['authenticated']))) {
                        $coreCredentialsSaved = core_credentials_save_for_user($coreCredentialUserKey, $coreUser, $corePass);
                        if (!$coreCredentialsSaved) {
                            dashboard_log_action('CORE_CREDENTIALS_SAVE_FAIL', 'No se pudieron guardar las credenciales CORE del usuario conectado.');
                        }
                    }
                    if (!empty($result['error'])) {
                        $flashMsg = $result['error'];
                        if (str_contains(dashboard_normalize_text($flashMsg), 'core rechazo las credenciales')) {
                            core_credentials_clear_for_user($coreCredentialUserKey);
                            session()->put('mantencion_dashboard_open_core_credentials_modal', true);
                            session()->put('mantencion_dashboard_core_runtime_user', $coreUser);
                        }
                        if ($coreCredentialsSaved === false) {
                            $flashMsg .= ' Además, no se pudieron guardar las credenciales en tu cuenta NOVA.';
                        }
                        dashboard_log_action('CORE_IMPORT_FAIL', 'Error al obtener datos CORE desde ' . $desde . ' hasta ' . $hasta . ': ' . $result['error']);
                    } else {
                        $flashMsg = 'Importación CORE completada. Nuevos: ' . (int)($result['imported'] ?? 0) . ' | actualizados: ' . (int)($result['updated'] ?? 0);
                        if ($coreCredentialsSaved === true) {
                            $flashMsg .= ' | Credenciales guardadas en tu cuenta.';
                        } elseif ($coreCredentialsSaved === false) {
                            $flashMsg .= ' | No se pudieron guardar las credenciales.';
                        }
                        if ((int)($result['imported'] ?? 0) === 0 && (int)($result['updated'] ?? 0) === 0 && is_array($result['trace'] ?? null)) {
                            $trace = $result['trace'];
                            $flashMsg .= ' | Diagnostico: rows_raw=' . (int)($trace['rows_raw'] ?? 0)
                                . ', rows_after_date_filter=' . (int)($trace['rows_after_date_filter'] ?? 0)
                                . ', rows_after_user_match=' . (int)($trace['rows_after_user_match'] ?? 0)
                                . ', skipped_user_mismatch=' . (int)($trace['skipped_user_mismatch'] ?? 0)
                                . ', skipped_existing_db=' . (int)($trace['skipped_existing_db'] ?? 0);
                            if (is_array($result['trace_sample'] ?? null)) {
                                $sample = $result['trace_sample'];
                                $flashMsg .= ' | sample_core_id=' . (string)($sample['core_id'] ?? '')
                                    . ', sample_assigned="' . (string)($sample['core_assigned'] ?? '')
                                    . '", filter_assigned="' . (string)($sample['filter_assigned'] ?? '')
                                    . '", sample_match=' . (string)($sample['match_result'] ?? 'none')
                                    . ', nova_logged="' . (string)($sample['nova_logged_user'] ?? '')
                                    . '", nova_core_user="' . (string)($sample['nova_core_user'] ?? '')
                                    . '", nova_user_id="' . (string)($sample['nova_user_id'] ?? '')
                                    . ', sample_reason=' . (string)($sample['skip_reason'] ?? '');
                            }
                            if (trim((string)($result['trace_assigned_summary'] ?? '')) !== '') {
                                $flashMsg .= ' | assigned_summary=' . (string)$result['trace_assigned_summary'];
                            }
                        }
                        dashboard_log_action(
                            'CORE_IMPORT',
                            'Obtuvo datos CORE desde ' . $desde . ' hasta ' . $hasta
                            . ' | asignado "' . $assigned . '"'
                            . ' | nuevos ' . (int)($result['imported'] ?? 0)
                            . ' | actualizados ' . (int)($result['updated'] ?? 0)
                        );
                    }
                    break;
                case 'archive_selected':
                    $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
                    $ids = $this->dashboard_filter_ids_by_scope($messages, $ids);
                    $archived = $this->retention->archive_selected_messages($messages, $ids);
                    if ($archived > 0) {
                        $flashMsg = $archived . ' tickets archivados.';
                        dashboard_log_action('REPORT_ARCHIVE', 'Archivo ' . $archived . ' reporte(s)');
                        $ajaxPayload['ids'] = array_values(array_filter(array_map('trim', $ids)));
                    } else {
                        $flashMsg = 'No había mensajes seleccionados para archivar.';
                        $ajaxPayload['ok'] = false;
                    }
                    break;
                case 'delete_selected':
                    $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
                    $ids = $this->dashboard_filter_ids_by_scope($messages, $ids);
                    if (empty($ids)) {
                        $flashMsg = 'No había mensajes seleccionados para eliminar.';
                        $ajaxPayload['ok'] = false;
                        break;
                    }
                    $deletedFuenteIds = [];
                    foreach ($messages as $m) {
                        if (is_array($m) && in_array(($m['id'] ?? ''), $ids, true)) {
                            $fid = trim((string)($m['fuente_id'] ?? $m['id'] ?? ''));
                            if ($fid !== '') {
                                $deletedFuenteIds[] = $fid;
                            }
                        }
                    }
                    $before = count($messages);
                    $messages = array_values(array_filter($messages, fn($m) => !in_array(($m['id'] ?? ''), $ids, true)));
                    $deleted = $before - count($messages);
                    if ($deleted > 0) {
                        save_messages($messages);
                        $dbRepo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
                        if ($dbRepo !== null && !empty($deletedFuenteIds)) {
                            $dbRepo->deleteByFuenteIds($deletedFuenteIds);
                        }
                        $flashMsg = $deleted . ' mensaje(s) eliminados.';
                        dashboard_log_action('REPORT_DELETE_BULK', 'Elimino ' . $deleted . ' reporte(s)');
                        $ajaxPayload['ids'] = $ids;
                    } else {
                        $flashMsg = 'No se encontraron mensajes seleccionados para eliminar.';
                        $ajaxPayload['ok'] = false;
                    }
                    break;
                case 'reset_errors':
                    $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
                    $ids = $this->dashboard_filter_ids_by_scope($messages, $ids);
                    $updated = 0;
                    foreach ($messages as &$message) {
                        if (!in_array(($message['id'] ?? ''), $ids, true)) {
                            continue;
                        }
                        if (strtolower($message['estado'] ?? '') !== 'error') {
                            continue;
                        }
                        $message['estado'] = 'pendiente';
                        unset($message['redmine_id']);
                        $message['procesado_ts'] = '';
                        $updated++;
                    }
                    unset($message);
                    if ($updated > 0) {
                        $this->redmineSync->remove_redmine_logs_for_messages($ids);
                        save_messages($messages);
                        $flashMsg = $updated . ' error(es) marcados como pendientes.';
                        dashboard_log_action('REPORT_RESET_ERRORS', 'Marco ' . $updated . ' error(es) como pendientes');
                        $ajaxPayload['ids'] = array_values($ids);
                        $ajaxPayload['status'] = 'pendiente';
                    } else {
                        $flashMsg = 'No se encontraron errores seleccionados.';
                        $ajaxPayload['ok'] = false;
                    }
                    break;
                default:
                    $flashMsg = 'Acción desconocida.';
                    break;
            }
            if ($action === 'process_selected') {
                $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
                $ids = $this->dashboard_filter_ids_by_scope($messages, $ids);
                $result = $this->redmineSync->send_selected_messages($messages, $ids, load_platform_config(), $userToken);
                $flashParts = [];
                if ($result['success'] > 0) {
                    $flashParts[] = $result['success'] . ' ticket(s) enviados.';
                }
                if ($result['attempts'] > $result['success']) {
                    $flashParts[] = 'Hubo fallas con ' . ($result['attempts'] - $result['success']) . ' ticket(s).';
                }
                if (empty($flashParts)) {
                    $flashParts[] = 'No se enviaron tickets.';
                }
                if (!empty($result['errors'])) {
                    $flashParts[] = implode(' ', $result['errors']);
                }
                if (!empty($result['redmine_ids'])) {
                    $flashParts[] = 'Redmine ID(s): ' . implode(', ', $result['redmine_ids']);
                }
                $flashMsg = implode(' ', $flashParts);
                dashboard_log_action(
                    'REDMINE_SEND',
                    'Envio datos a Redmine | seleccionados ' . $this->dashboard_security_ids_count($ids)
                    . ' | intentos ' . (int)($result['attempts'] ?? 0)
                    . ' | exitos ' . (int)($result['success'] ?? 0)
                    . ' | fallas ' . max(0, (int)($result['attempts'] ?? 0) - (int)($result['success'] ?? 0))
                );
            }
            // The hour-extra toggle is an AJAX-only UI action. Always return its JSON
            // contract even when a proxy/legacy bridge does not preserve the AJAX
            // indicators; otherwise the generic legacy redirect below returns the full
            // dashboard HTML and the optimistic toggle rolls itself back.
            $mustReturnJson = $ajaxAction || $action === 'toggle_hora_extra';
            if ($mustReturnJson && $action !== 'process_selected' && $action !== 'import_core_history') {
                $scopedMessages = dashboard_filter_messages_by_scope($messages);
                $ajaxPayload['message'] = $flashMsg ?? '';
                $ajaxPayload['counts'] = $this->dashboard_status_counts($scopedMessages);
                dashboard_json_response($ajaxPayload, !empty($ajaxPayload['ok']) ? 200 : 400);
            }
            dashboard_set_flash($flashMsg ?? '');
            return $this->dashboard_redirect_back();
        }
        $rawLog = security_load_events();
        $securityLog = array_filter($rawLog, fn($entry) => (($entry['tag'] ?? '') !== 'CSRF_ALERT'));
        if (empty($securityLog)) {
            $securityLog = array_filter($rawLog, fn($entry) => in_array(($entry['tag'] ?? ''), ['LOGIN_SUCCESS', 'LOG', 'AUTH_SUCCESS']));
        }
        $messages = dashboard_filter_messages_by_scope($messages);
        return [$messages, $flash, $securityLog];
    }
}
