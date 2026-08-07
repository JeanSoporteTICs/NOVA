<?php

namespace App\Modulos\RedmineMantencion\Services;

class MantencionRedmineSyncService
{
    private readonly MantencionCoreImportService $coreImport;

    public function __construct(MantencionCoreImportService $coreImport)
    {
        $this->coreImport = $coreImport;
    }

    public function append_redmine_log(array $entry): void {
        try {
            \Illuminate\Support\Facades\DB::table('mantencion_log')->insert([
                'canal' => 'redmine', 'tipo' => (string)($entry['event'] ?? $entry['status'] ?? 'envio'),
                'mensaje_id' => trim((string)($entry['message_id'] ?? '')) ?: null,
                'detalle' => (string)($entry['error'] ?? $entry['message'] ?? ''),
                'contexto' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'registrado_at' => now(),
            ]);
        } catch (\Throwable) {}
    }

    public function build_redmine_issue_payload(array $message, array $cfg, array $catMap, array $unitMap): array {
        $isManual = ($message['fuente'] ?? '') === 'manual' || str_starts_with((string)($message['id'] ?? ''), 'manual-');
        if ($isManual) {
            $description = $this->dashboard_build_redmine_manual_description($message);
            $issue = [
                'project_id' => (int)($message['project_id'] ?? ($cfg['project_id'] ?? 48)),
                'subject' => trim((string)($message['asunto'] ?? $message['mensaje'] ?? '')),
                'description' => $description,
                'tracker_id' => (int)($message['tipo_id'] ?? ($cfg['tracker_id'] ?? 3)),
                'priority_id' => (int)($message['priority_id'] ?? ($cfg['priority_id'] ?? 2)),
                'status_id' => (int)($message['status_id'] ?? ($cfg['status_id'] ?? 1)),
                'is_private' => false,
            ];
            $startDate = parse_issue_date($message['fecha_inicio'] ?? $message['fecha'] ?? '');
            $dueDate = parse_issue_date($message['fecha_fin'] ?? $message['fecha'] ?? $message['fecha_inicio'] ?? '');
            if ($startDate) {
                $issue['start_date'] = $startDate;
            }
            if ($dueDate) {
                $issue['due_date'] = $dueDate;
            }
            $est = trim((string)($message['tiempo_estimado'] ?? ''));
            if ($est !== '' && is_numeric($est)) {
                $issue['estimated_hours'] = (float)$est;
            }
            $asignado = trim((string)($message['asignado_a'] ?? ''));
            if ($asignado !== '') {
                $issue['assigned_to_id'] = $asignado;
            }
            $categoria = strtoupper(trim((string)($message['categoria'] ?? '')));
            if ($categoria !== '' && isset($catMap[$categoria])) {
                $issue['category_id'] = (int)$catMap[$categoria];
            }
            $customFields = [];
            foreach (['cf_solicitante','cf_unidad','cf_unidad_solicitante'] as $cfKey) {
                $cfId = $cfg[$cfKey] ?? null;
                if (($cfId === null || $cfId === '') && $cfKey === 'cf_solicitante') $cfId = 3;
                if (($cfId === null || $cfId === '') && $cfKey === 'cf_unidad') $cfId = 5;
                if (!$cfId) {
                    continue;
                }
                $value = '';
                switch ($cfKey) {
                    case 'cf_solicitante':
                        $value = trim((string)($message['solicitante'] ?? ''));
                        break;
                    case 'cf_unidad':
                        $value = dashboard_resolve_department_value($message);
                        break;
                    case 'cf_unidad_solicitante':
                        $value = strtoupper(trim((string)($message['unidad_solicitante'] ?? $message['unidad'] ?? '')));
                        break;
                }
                if ($value === '' && $cfKey === 'cf_unidad_solicitante' && $unitMap) {
                    $derived = strtoupper(trim((string)($message['unidad_solicitante'] ?? $message['unidad'] ?? '')));
                    if (isset($unitMap[$derived])) {
                        $value = $unitMap[$derived];
                    }
                }
                if ($value !== '') {
                    $customFields[] = ['id' => $cfId, 'value' => $value];
                }
            }
            if (trim((string)($message['anexo'] ?? '')) !== '') {
                $customFields[] = ['id' => 4, 'value' => trim((string)$message['anexo'])];
            }
            $normalizedEmail = dashboard_normalize_email($message['core_email'] ?? '');
            if ($normalizedEmail !== '') {
                $customFields[] = ['id' => 8, 'value' => $normalizedEmail];
            }
            $cfHoraExtra = $cfg['cf_hora_extra'] ?? null;
            if ($cfHoraExtra === null || $cfHoraExtra === '') {
                $cfHoraExtra = 12;
            }
            if ($cfHoraExtra) {
                $customFields[] = ['id' => $cfHoraExtra, 'value' => normalize_hour_extra_value($message['hora_extra'] ?? '')];
            }
            if (!empty($customFields)) {
                $issue['custom_fields'] = $customFields;
            }
            return $issue;
        }

        $coreTipo = trim((string)($message['core_tipo_solicitud'] ?? $message['mensaje'] ?? ''));
        $coreEstablecimiento = trim((string)($message['core_establecimiento'] ?? $message['unidad_solicitante'] ?? ''));
        $coreDepartamento = trim((string)($message['core_departamento'] ?? $message['unidad'] ?? ''));
        if (strtoupper($coreDepartamento) === 'N/A' || $coreDepartamento === $coreEstablecimiento) {
            $coreDepartamento = '';
        }
        $coreEmail = dashboard_normalize_email($message['core_email'] ?? '');
        $subjectParts = array_values(array_filter(
            [$coreTipo, $coreEstablecimiento, $coreDepartamento],
            fn($v) => ($value = trim((string)$v)) !== '' && strtoupper($value) !== 'N/A'
        ));
        $subject = implode(' / ', $subjectParts);
        $description = $this->dashboard_build_redmine_core_description($message);
        $issue = [
            'project_id' => (int)($cfg['project_id'] ?? 48),
            'subject' => $subject !== '' ? $subject : trim((string)($message['asunto'] ?? $message['mensaje'] ?? '')),
            'description' => $description,
            'tracker_id' => (int)($cfg['tracker_id'] ?? 1),
            'priority_id' => (int)($cfg['priority_id'] ?? 2),
            'status_id' => (int)($cfg['status_id'] ?? 1),
        ];
        $startDate = parse_issue_date($message['fecha_inicio'] ?? $message['fecha'] ?? '');
        $dueDate = parse_issue_date($message['fecha_fin'] ?? $message['fecha'] ?? $message['fecha_inicio'] ?? '');
        if ($startDate) {
            $issue['start_date'] = $startDate;
        }
        if ($dueDate) {
            $issue['due_date'] = $dueDate;
        }
        $est = trim((string)($message['tiempo_estimado'] ?? ''));
        if ($est !== '' && is_numeric($est)) {
            $issue['estimated_hours'] = (float)$est;
        }
        $asignado = trim((string)(auth_get_user_id() ?: ($message['asignado_a'] ?? '')));
        if ($asignado !== '') {
            $issue['assigned_to_id'] = $asignado;
        }
        $categoria = strtoupper(trim($message['categoria'] ?? ''));
        if ($categoria !== '' && isset($catMap[$categoria])) {
            $issue['category_id'] = (int)$catMap[$categoria];
        }
        $customFields = [];
        foreach (['cf_solicitante','cf_unidad','cf_unidad_solicitante'] as $cfKey) {
            $cfId = $cfg[$cfKey] ?? null;
            if (($cfId === null || $cfId === '') && $cfKey === 'cf_solicitante') $cfId = 3;
            if (($cfId === null || $cfId === '') && $cfKey === 'cf_unidad') $cfId = 5;
            if (!$cfId) continue;
            $value = '';
            switch ($cfKey) {
                case 'cf_solicitante':
                    $value = $message['solicitante'] ?? '';
                    break;
                case 'cf_unidad':
                    $value = dashboard_resolve_department_value($message);
                    break;
                case 'cf_unidad_solicitante':
                    $value = strtoupper(trim($message['unidad_solicitante'] ?? $message['unidad'] ?? ''));
                    break;
            }
            if ($value === '' && $cfKey === 'cf_unidad_solicitante' && $unitMap) {
                $derived = strtoupper(trim($message['unidad_solicitante'] ?? $message['unidad'] ?? ''));
                if (isset($unitMap[$derived])) {
                    $value = $unitMap[$derived];
                }
            }
            if ($value === '') continue;
            $customFields[] = ['id' => $cfId, 'value' => $value];
        }
        if ($coreEmail !== '') {
            $customFields[] = ['id' => 8, 'value' => $coreEmail];
        }
        $cfHoraExtra = $cfg['cf_hora_extra'] ?? null;
        if ($cfHoraExtra === null || $cfHoraExtra === '') {
            $cfHoraExtra = 12;
        }
        if ($cfHoraExtra) {
            $customFields[] = ['id' => $cfHoraExtra, 'value' => normalize_hour_extra_value($message['hora_extra'] ?? '')];
        }
        if (!empty($customFields)) {
            $issue['custom_fields'] = $customFields;
        }
        return $issue;
    }

    public function dashboard_build_redmine_core_description(array $message): string {
        return $this->dashboard_render_textile_table(
            dashboard_core_detail_table_schema($message),
            dashboard_detail_preview_rows($message)
        );
    }

    public function dashboard_build_redmine_manual_description(array $message): string {
        return $this->dashboard_sanitize_redmine_text($message['descripcion'] ?? '');
    }

    public function dashboard_redmine_send_block_reason(array $message): ?string {
        if ($this->coreImport->dashboard_core_is_in_review($message)) {
            return 'La solicitud permanece En Revisión en CORE.';
        }

        return null;
    }

    public function dashboard_render_textile_table(array $schema, array $rows): string {
        if (empty($schema) || empty($rows)) {
            return '';
        }
        $header = '|_. ' . implode('|_. ', array_map(fn($column) => $column['label'], $schema)) . '|';
        $lines = [$header];
        foreach ($rows as $row) {
            $values = array_map(
                fn($value) => str_replace(["\r", "\n", '|'], [' ', ' ', '/'], $this->dashboard_sanitize_redmine_text($value)),
                array_map(fn($column) => $row[$column['key']] ?? '', $schema)
            );
            $lines[] = '|' . implode('|', $values) . '|';
        }
        return implode("\n", $lines);
    }

    public function dashboard_sanitize_redmine_text($value): string {
        $text = dashboard_fix_text_encoding((string)$value);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/[\x{200D}\x{FE0F}\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $text) ?? $text;
        return trim($text);
    }

    public function load_redmine_logs_by_message(): array {
        $grouped = [];
        foreach ($this->parse_redmine_log_entries() as $entry) {
            $mid = trim((string)($entry['message_id'] ?? ''));
            if ($mid === '') {
                continue;
            }
            $decoded = is_array($entry['decoded'] ?? null) ? $entry['decoded'] : [];
            $grouped[$mid] = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        return $grouped;
    }

    public function parse_redmine_log_entries(): array {
        $entries = [];
        try { foreach (\Illuminate\Support\Facades\DB::table('mantencion_log')->where('canal','redmine')->orderBy('id')->get() as $row) {
            $decoded = json_decode((string)($row->contexto ?? '{}'), true); if (!is_array($decoded)) $decoded = [];
            $entries[] = ['message_id'=>(string)($row->mensaje_id ?? ''),'raw'=>json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),'decoded'=>$decoded];
        }} catch (\Throwable) {}
        return $entries;
    }

    public function redmine_api_issues_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('~\/issues(?:\.json)?(?:\?.*)?$~i', $url) === 1) {
            $parts = parse_url($url);
            $path = (string)($parts['path'] ?? '');
            if ($path !== '' && !str_ends_with(strtolower($path), '.json')) {
                $path .= '.json';
            }
            $rebuilt = '';
            if (isset($parts['scheme'])) {
                $rebuilt .= $parts['scheme'] . '://';
            }
            if (isset($parts['user'])) {
                $rebuilt .= $parts['user'];
                if (isset($parts['pass'])) {
                    $rebuilt .= ':' . $parts['pass'];
                }
                $rebuilt .= '@';
            }
            $rebuilt .= (string)($parts['host'] ?? '');
            if (isset($parts['port'])) {
                $rebuilt .= ':' . $parts['port'];
            }
            $rebuilt .= $path;
            if (!empty($parts['query'])) {
                $rebuilt .= '?' . $parts['query'];
            }
            return $rebuilt;
        }
        return rtrim($url, '/') . '/issues.json';
    }

    public function redmine_log_path(): string {
        return __DIR__ . '/../data/envio_errores.log';
    }

    public function remove_redmine_logs_for_messages(array $ids): void {
        $ids = array_values(array_filter(array_map('trim', $ids)));
        if (empty($ids)) return;
        try { \Illuminate\Support\Facades\DB::table('mantencion_log')->where('canal','redmine')->whereIn('mensaje_id',$ids)->delete(); } catch (\Throwable) {}
    }

    public function send_redmine_issue(array $issue, array $cfg, string $userToken = ''): array {
        $url = $this->redmine_api_issues_url((string)($cfg['platform_url'] ?? ''));
        if ($url === '') {
            return ['http_code' => 0, 'body' => '', 'error' => 'URL no configurada'];
        }
        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $token = trim($userToken);
        if ($token !== '') {
            $headers[] = 'X-Redmine-API-Key: ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode(['issue' => $issue], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        return ['http_code' => $httpCode, 'body' => $body ?? '', 'error' => $curlErr];
    }

    public function send_selected_messages(array &$messages, array $ids, array $cfg, string $userToken): array {
        $catMap = load_name_map('categorias');
        $unitMap = load_name_map('unidades');
        $attempts = 0;
        $success = 0;
        $created = [];
        $errors = [];
        $blocked = 0;
        $blockedIds = [];
        $ids = array_filter(array_map('trim', $ids));
        if (empty($ids)) {
            return ['success' => 0, 'errors' => ['No hay mensajes seleccionados'], 'attempts' => 0, 'blocked' => 0];
        }
        if (trim($userToken) === '') {
            return [
                'success' => 0,
                'errors' => ['Debes configurar tu API Key personal de Redmine en Cuentas conectadas antes de enviar reportes.'],
                'attempts' => 0,
                'blocked' => 0,
                'redmine_ids' => [],
            ];
        }
        foreach ($messages as &$message) {
            if (!in_array(($message['id'] ?? ''), $ids, true)) {
                continue;
            }
            $blockReason = $this->dashboard_redmine_send_block_reason($message);
            if ($blockReason !== null) {
                $message['estado'] = 'pendiente';
                $blocked++;
                $blockedIds[] = (string)($message['id'] ?? 'sin-id');
                continue;
            }
            $attempts++;
            $issue = $this->build_redmine_issue_payload($message, $cfg, $catMap, $unitMap);
            $result = $this->send_redmine_issue($issue, $cfg, $userToken);
            $entry = [
                'ts' => (new \DateTimeImmutable())->format(\DateTime::ATOM),
                'http_code' => $result['http_code'],
                'error' => $result['error'] ?? '',
                'body' => $result['body'],
                'payload' => ['issue' => $issue],
                'message_id' => $message['id'] ?? '',
            ];
            $this->append_redmine_log($entry);
            if ($result['http_code'] === 201) {
                $success++;
                $decoded = json_decode($result['body'] ?? '', true);
                $message['estado'] = 'procesado';
                $message['redmine_id'] = $decoded['issue']['id'] ?? $message['redmine_id'] ?? '';
                $message['procesado_ts'] = (new \DateTimeImmutable())->format(\DateTime::ATOM);
                if ($message['redmine_id']) {
                    $created[] = (string)$message['redmine_id'];
                }
                log_security_event(
                    'REDMINE_SEND',
                    sprintf(
                        'Ticket enviado a Redmine. Mensaje=%s Ticket=%s Usuario=%s',
                        (string)($message['id'] ?? 'sin-id'),
                        (string)($message['redmine_id'] ?? 'sin-id'),
                        (string)(mantencion_current_user()['nombre'] ?? 'usuario')
                    )
                );
            } else {
                $message['estado'] = 'error';
                $message['procesado_ts'] = (new \DateTimeImmutable())->format(\DateTime::ATOM);
                $errors[] = sprintf('No se pudo enviar %s: %s', $message['id'] ?? 'sin-id', $result['error'] ?: $result['body']);
                log_security_event(
                    'REDMINE_SEND_FAIL',
                    sprintf(
                        'Fallo envio a Redmine. Mensaje=%s HTTP=%s Error=%s Usuario=%s',
                        (string)($message['id'] ?? 'sin-id'),
                        (string)($result['http_code'] ?? ''),
                        substr((string)($result['error'] ?: $result['body']), 0, 180),
                        (string)(mantencion_current_user()['nombre'] ?? 'usuario')
                    )
                );
            }
            append_hours_extra_record($message);
        }
        unset($message);
        if ($blocked > 0) {
            $errors[] = $blocked . ' reporte(s) permanecen pendientes por estar En Revisión en CORE: ' . implode(', ', $blockedIds) . '.';
        }
        save_messages($messages);
        return [
            'success' => $success,
            'errors' => array_values(array_filter($errors)),
            'attempts' => $attempts,
            'blocked' => $blocked,
            'redmine_ids' => $created,
        ];
    }
}
