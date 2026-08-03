<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/core_credentials.php';
require_once __DIR__ . '/maintenance.php';

function dashboard_set_flash(string $message): void {
    auth_start_session();
    $_SESSION['flash'] = $message;
}

function dashboard_consume_flash(): ?string {
    auth_start_session();
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function dashboard_redirect_back(): void {
    $location = $_SERVER['REQUEST_URI'] ?? '/redmine-mantencion';
    header('Location: ' . $location);
    exit;
}

function dashboard_is_ajax_request(): bool {
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || (string)($_POST['ajax'] ?? '') === '1';
}

function dashboard_required_permission_for_action(string $action): ?string {
    return match ($action) {
        'update', 'process_selected', 'archive_selected', 'reset_errors' => 'reportes_editar',
        'delete', 'delete_selected' => 'reportes_eliminar',
        'import_core_history' => 'reportes_importar_core',
        'toggle_hora_extra' => 'horas_extra_editar',
        default => null,
    };
}

function dashboard_status_counts(array $messages): array {
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

function dashboard_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function dashboard_security_actor(): string {
    // Deliberately NOT calling auth_start_session() here: $_SESSION['user'] is already
    // populated by LegacyProjectController::syncNovaUserToLegacySession() before this
    // module's PHP is dispatched, and that value stays readable in memory for the rest
    // of the request even after the session file is closed early (see the comment on
    // session_write_close() in that method) to avoid AJAX requests serializing on the
    // session file lock. Calling auth_start_session() here would silently reopen (and
    // re-lock) the session just to read a value we already have, defeating that fix for
    // every action that logs via dashboard_log_action() — including toggle_hora_extra.
    $name = trim((string)($_SESSION['user']['nombre'] ?? ''));
    $id = trim((string)($_SESSION['user']['id'] ?? ''));
    if ($name === '' && $id === '') {
        return 'usuario desconocido';
    }
    return trim($name . ($id !== '' ? ' (ID ' . $id . ')' : ''));
}

function dashboard_security_ids_count(array $ids): int {
    return count(array_values(array_filter(array_map('trim', $ids), static fn(string $id): bool => $id !== '')));
}

function dashboard_log_action(string $tag, string $details): void {
    if (!function_exists('log_security_event')) {
        return;
    }
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $suffix = $ip !== '' ? ' | IP ' . $ip : '';
    log_security_event($tag, dashboard_security_actor() . ' | ' . $details . $suffix);
}

function dashboard_core_compact_keys(): array {
    return [
        'id',
        'fuente',
        'fuente_id',
        'id_core',
        'core_solicitud_id',
        'estado',
        'redmine_id',
        'procesado_ts',
        'hora_extra',
        'tiempo_estimado',
        'fecha_inicio',
        'fecha_fin',
        'asignado_a',
        'solicitante',
        'core_fecha_creacion',
        'core_tipo_solicitud',
        'core_establecimiento',
        'core_departamento',
        'core_estado',
        'core_usuario_asignado',
        'core_email',
        'core_telefono',
        'core_celular',
        'core_detalle_tipo_solicitud',
        'core_detalle_run',
        'core_detalle_nombre',
        'core_detalle_motivo',
        'core_detalle_establecimientos',
        'core_detalle_otros_permisos',
        'core_detalle_fecha_nacimiento',
        'core_detalle_email',
        'core_detalle_departamento',
        'core_detalle_cargo',
        'core_detalle_rol',
        'core_detalle_estado',
        'core_detalle_items',
    ];
}

function dashboard_core_build_subject(array $message): string {
    $establecimiento = trim((string)($message['core_establecimiento'] ?? ''));
    $departamento = trim((string)($message['core_departamento'] ?? ''));
    if (strtoupper($departamento) === 'N/A' || $departamento === $establecimiento) {
        $departamento = '';
    }
    $parts = array_values(array_filter([
        trim((string)($message['core_tipo_solicitud'] ?? '')),
        $establecimiento,
        $departamento,
    ], fn($value) => $value !== '' && strtoupper($value) !== 'N/A'));
    return implode(' / ', $parts);
}

function dashboard_core_build_description(array $message): string {
    $parts = array_filter([
        ($message['core_tipo_solicitud'] ?? '') !== '' ? 'Tipo de solicitud: ' . $message['core_tipo_solicitud'] : '',
        ($message['core_detalle_tipo_solicitud'] ?? '') !== '' ? 'Detalle tipo solicitud: ' . $message['core_detalle_tipo_solicitud'] : '',
        ($message['core_detalle_run'] ?? '') !== '' ? 'RUN: ' . $message['core_detalle_run'] : '',
        ($message['core_detalle_nombre'] ?? '') !== '' ? 'Nombre: ' . $message['core_detalle_nombre'] : '',
        ($message['core_detalle_motivo'] ?? '') !== '' ? 'Motivo: ' . $message['core_detalle_motivo'] : '',
        ($message['core_detalle_establecimientos'] ?? '') !== '' ? 'Establecimientos: ' . $message['core_detalle_establecimientos'] : '',
        ($message['core_detalle_otros_permisos'] ?? '') !== '' ? 'Otros permisos: ' . $message['core_detalle_otros_permisos'] : '',
        ($message['core_establecimiento'] ?? '') !== '' ? 'Establecimiento: ' . $message['core_establecimiento'] : '',
        dashboard_resolve_department_value($message) !== '' ? 'Departamento: ' . dashboard_resolve_department_value($message) : '',
        ($message['core_telefono'] ?? '') !== '' ? 'Teléfono: ' . $message['core_telefono'] : '',
        ($message['core_celular'] ?? '') !== '' ? 'Celular: ' . $message['core_celular'] : '',
        ($message['core_estado'] ?? '') !== '' ? 'Estado CORE: ' . $message['core_estado'] : '',
        ($message['core_usuario_asignado'] ?? '') !== '' ? 'Usuario asignado CORE: ' . $message['core_usuario_asignado'] : '',
    ]);
    return implode("\n", $parts);
}

function dashboard_resolve_department_value(array $message): string {
    $departamento = trim((string)($message['core_departamento'] ?? $message['departamento'] ?? $message['unidad'] ?? ''));
    $establecimiento = trim((string)($message['core_establecimiento'] ?? $message['establecimiento'] ?? $message['unidad_solicitante'] ?? ''));
    if ($departamento === '' || strtoupper($departamento) === 'N/A') {
        return $establecimiento;
    }
    return $departamento;
}

function dashboard_expand_manual_message(array $message): array {
    $unidad = trim((string)($message['departamento'] ?? $message['unidad'] ?? ''));
    $unidadSolicitante = trim((string)($message['establecimiento'] ?? $message['unidad_solicitante'] ?? ''));
    if ($unidadSolicitante === '') {
        $unidadSolicitante = $unidad;
    }
    $categoria = trim((string)($message['categoria'] ?? ''));
    $tipo = trim((string)($message['tipo'] ?? $message['core_tipo_solicitud'] ?? ''));
    $asignadoId = trim((string)($message['asignado_a'] ?? ''));
    $asignadoNombre = trim((string)($message['asignado_nombre'] ?? ''));
    if ($asignadoNombre === '' && $asignadoId !== '') {
        $asignadoNombre = dashboard_find_user_name($asignadoId);
    }
    $fecha = trim((string)($message['fecha'] ?? ''));
    $hora = trim((string)($message['hora'] ?? ''));
    $message['unidad'] = $unidad;
    $message['unidad_solicitante'] = $unidadSolicitante;
    $message['establecimiento'] = $unidadSolicitante;
    $message['departamento'] = $unidad;
    $message['categoria'] = $categoria;
    $message['tipo'] = $tipo !== '' ? $tipo : 'Soporte';
    $message['asignado_nombre'] = $asignadoNombre;
    $message['core_tipo_solicitud'] = $categoria !== '' ? $categoria : $message['tipo'];
    $message['core_establecimiento'] = $unidadSolicitante !== '' ? $unidadSolicitante : $unidad;
    $message['core_departamento'] = $unidad !== '' ? $unidad : $message['core_establecimiento'];
    $message['core_usuario_asignado'] = $asignadoNombre;
    $message['core_estado'] = trim((string)($message['core_estado'] ?? '')) !== '' ? trim((string)$message['core_estado']) : 'Manual';
    if (trim((string)($message['core_fecha_creacion'] ?? '')) === '') {
        $message['core_fecha_creacion'] = trim($fecha . ' ' . $hora);
    }
    return $message;
}

function dashboard_normalize_email(?string $value): string {
    $email = trim((string)$value);
    if ($email === '') {
        return '';
    }
    return strtolower($email);
}

function dashboard_fix_text_encoding(?string $value): string {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $replacementMap = [
        'Ãƒâ€šÃ‚Â¿' => '¿',
        'Ãƒâ€šÃ‚Â¡' => '¡',
        'Ãƒâ€šÃ‚Âº' => 'º',
        'Ãƒâ€šÃ‚Âª' => 'ª',
        'Ãƒâ€šÃ‚Â°' => '°',
        'Ãƒâ€šÃ‚Â' => '',
        'ÃƒÂ¡' => 'á',
        'ÃƒÂ©' => 'é',
        'ÃƒÂ­' => 'í',
        'ÃƒÂ³' => 'ó',
        'ÃƒÂº' => 'ú',
        'ÃƒÂ' => 'Á',
        'Ãƒâ€°' => 'É',
        'ÃƒÂ' => 'Í',
        'Ãƒâ€œ' => 'Ó',
        'ÃƒÅ¡' => 'Ú',
        'ÃƒÂ±' => 'ñ',
        'Ãƒâ€˜' => 'Ñ',
        'ÃƒÂ¼' => 'ü',
        'ÃƒÅ“' => 'Ü',
        'Ã‚Â¿' => '¿',
        'Ã‚Â¡' => '¡',
        'Ã‚Âº' => 'º',
        'Ã‚Âª' => 'ª',
        'Ã‚Â°' => '°',
        'Ã‚Â' => '',
        'Ã¡' => 'á',
        'Ã©' => 'é',
        'Ã­' => 'í',
        'Ã³' => 'ó',
        'Ãº' => 'ú',
        'Ã' => 'Á',
        'Ã‰' => 'É',
        'Ã' => 'Í',
        'Ã“' => 'Ó',
        'Ãš' => 'Ú',
        'Ã±' => 'ñ',
        'Ã‘' => 'Ñ',
        'Ã¼' => 'ü',
        'Ãœ' => 'Ü',
        'â€œ' => '"',
        'â€' => '"',
        'â€˜' => "'",
        'â€™' => "'",
        'â€“' => '-',
        'â€”' => '-',
        'â€¦' => '...',
        'Â ' => ' ',
    ];

    for ($i = 0; $i < 3; $i++) {
        $updated = strtr($text, $replacementMap);
        if ($updated === $text) {
            break;
        }
        $text = $updated;
    }

    $scoreText = static function (string $candidate): int {
        $score = 0;
        if (preg_match_all('/(?:Ã.|Â.|â.|ð|Ð|�)/u', $candidate, $matches) !== false) {
            $score += count($matches[0] ?? []) * 10;
        } else {
            $score += 1000;
        }
        if (preg_match_all('/(?:Ãƒ|Ã‚|Â|â€|â€œ|â€|â€¦|ðŸ|Ã±|Ã¡|Ã©|Ã­|Ã³|Ãº)/u', $candidate, $matches) !== false) {
            $score += count($matches[0] ?? []) * 20;
        }
        if (preg_match('/[A-Za-z][ÃÂâðÐ]/u', $candidate)) {
            $score += 25;
        }
        return $score;
    };

    $best = $text;
    $bestScore = $scoreText($best);

    for ($pass = 0; $pass < 3; $pass++) {
        $candidates = [$best];
        foreach (['Windows-1252', 'ISO-8859-1'] as $encoding) {
            $decoded = @iconv($encoding, 'UTF-8//IGNORE', $best);
            if (is_string($decoded) && trim($decoded) !== '') {
                $candidates[] = trim($decoded);
            }
        }

        $passBest = $best;
        $passBestScore = $bestScore;
        foreach ($candidates as $candidate) {
            $score = $scoreText($candidate);
            if ($score < $passBestScore) {
                $passBestScore = $score;
                $passBest = $candidate;
            }
        }

        if ($passBest === $best || $passBestScore >= $bestScore) {
            break;
        }

        $best = $passBest;
        $bestScore = $passBestScore;
    }

    return $best;
}

function dashboard_repair_structure_encoding(mixed $value): mixed {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = dashboard_repair_structure_encoding($item);
        }
        return $value;
    }
    if (is_string($value)) {
        return dashboard_fix_text_encoding($value);
    }
    return $value;
}

function dashboard_core_normalize_detail_row(array $item, array $message = []): array {
    $tipo = trim((string)($item['detalle_tipo_solicitud'] ?? $item['tipo solicitud'] ?? $item['tipo_solicitud'] ?? $item['tipo de solicitud'] ?? ''));
    $run = trim((string)($item['detalle_run'] ?? $item['run'] ?? $item['rut'] ?? $item['run usuario'] ?? $item['run_usuario'] ?? ''));
    $nombre = trim((string)($item['detalle_nombre'] ?? $item['nombre'] ?? $item['nombre completo'] ?? $item['nombre_completo'] ?? ''));
    if ($nombre === '') {
        $nombrePartes = array_filter([
            trim((string)($item['nombres_ins'] ?? '')),
            trim((string)($item['apepat_ins'] ?? '')),
            trim((string)($item['apemat_ins'] ?? '')),
        ], fn($value) => $value !== '');
        if (!empty($nombrePartes)) {
            $nombre = implode(' ', $nombrePartes);
        }
    }
    $motivo = trim((string)($item['detalle_motivo'] ?? $item['motivo'] ?? $item['motivo solicitud'] ?? $item['motivo_solicitud'] ?? ''));
    $establecimientos = trim((string)($item['detalle_establecimientos'] ?? $item['establecimientos'] ?? $item['establecimiento'] ?? ''));
    $otrosPermisos = trim((string)($item['detalle_otros_permisos'] ?? $item['otros permisos'] ?? $item['otros_permisos'] ?? $item['permisos'] ?? ''));
    $fechaNacimiento = trim((string)($item['detalle_fecha_nacimiento'] ?? $item['fecha de nacimiento'] ?? $item['fecha_nacimiento'] ?? $item['fec_nacimiento'] ?? $item['fecha_nac'] ?? ''));
    $email = dashboard_normalize_email($item['detalle_email'] ?? $item['email'] ?? $item['correo'] ?? '');
    $departamento = trim((string)($item['detalle_departamento'] ?? $item['departamento'] ?? $item['depto'] ?? ''));
    if (($departamento === '' || strtoupper($departamento) === 'N/A') && $establecimientos !== '') {
        $departamento = $establecimientos;
    }
    $cargo = trim((string)($item['detalle_cargo'] ?? $item['cargo'] ?? $item['id_cargo'] ?? ''));
    $rol = trim((string)($item['detalle_rol'] ?? $item['rol'] ?? ''));
    $estado = trim((string)($item['detalle_estado'] ?? $item['estado'] ?? ''));
    if ($tipo === '') {
        $tipo = trim((string)($message['core_tipo_solicitud'] ?? $message['mensaje'] ?? ''));
    }
    if ($nombre === '') {
        $nombre = trim((string)($message['core_detalle_nombre'] ?? $message['solicitante'] ?? ''));
    }
    return [
        'detalle_tipo_solicitud' => dashboard_fix_text_encoding($tipo),
        'detalle_run' => $run,
        'detalle_nombre' => dashboard_fix_text_encoding($nombre),
        'detalle_motivo' => dashboard_fix_text_encoding($motivo),
        'detalle_establecimientos' => dashboard_fix_text_encoding($establecimientos),
        'detalle_otros_permisos' => dashboard_fix_text_encoding($otrosPermisos),
        'detalle_fecha_nacimiento' => $fechaNacimiento,
        'detalle_email' => $email,
        'detalle_departamento' => dashboard_fix_text_encoding($departamento),
        'detalle_cargo' => dashboard_fix_text_encoding($cargo),
        'detalle_rol' => dashboard_fix_text_encoding($rol),
        'detalle_estado' => dashboard_fix_text_encoding($estado),
    ];
}

function dashboard_core_is_creation_request(array $message): bool {
    $tipo = trim((string)($message['core_tipo_solicitud'] ?? $message['mensaje'] ?? ''));
    $normalized = dashboard_normalize_text($tipo);
    return $normalized === 'creacion de usuario'
        || $normalized === 'creacion usuario'
        || (str_contains($normalized, 'creaci') && str_contains($normalized, 'usuario'))
        || (str_contains($normalized, 'creacion') && str_contains($normalized, 'usuario'));
}

function dashboard_core_is_add_establishment_request(array $message): bool {
    $tipo = trim((string)($message['core_tipo_solicitud'] ?? $message['mensaje'] ?? ''));
    return dashboard_normalize_text($tipo) === 'agregar establecimiento';
}

function dashboard_core_detail_table_schema(array $message): array {
    if (($message['fuente'] ?? '') === 'manual') {
        return [
            ['label' => 'Tipo solicitud', 'key' => 'detalle_tipo_solicitud'],
            ['label' => 'Solicitante', 'key' => 'detalle_solicitante'],
            ['label' => 'Categoría', 'key' => 'detalle_categoria'],
            ['label' => 'Unidad', 'key' => 'detalle_unidad'],
            ['label' => 'Descripción', 'key' => 'detalle_descripcion'],
        ];
    }
    if (dashboard_core_is_creation_request($message)) {
        return [
            ['label' => 'Tipo solicitud', 'key' => 'detalle_tipo_solicitud'],
            ['label' => 'RUN', 'key' => 'detalle_run'],
            ['label' => 'Nombre', 'key' => 'detalle_nombre'],
            ['label' => 'Fecha de nacimiento', 'key' => 'detalle_fecha_nacimiento'],
            ['label' => 'Email', 'key' => 'detalle_email'],
            ['label' => 'Departamento', 'key' => 'detalle_departamento'],
            ['label' => 'Cargo', 'key' => 'detalle_cargo'],
            ['label' => 'Rol', 'key' => 'detalle_rol'],
        ];
    }
    if (dashboard_core_is_add_establishment_request($message)) {
        return [
            ['label' => 'Tipo solicitud', 'key' => 'detalle_tipo_solicitud'],
            ['label' => 'RUN', 'key' => 'detalle_run'],
            ['label' => 'Nombre', 'key' => 'detalle_nombre'],
            ['label' => 'Motivo', 'key' => 'detalle_motivo'],
            ['label' => 'Establecimientos', 'key' => 'detalle_establecimientos'],
            ['label' => 'Otros permisos', 'key' => 'detalle_otros_permisos'],
        ];
    }
    return [
        ['label' => 'Tipo solicitud', 'key' => 'detalle_tipo_solicitud'],
        ['label' => 'RUN', 'key' => 'detalle_run'],
        ['label' => 'Nombre', 'key' => 'detalle_nombre'],
        ['label' => 'Motivo', 'key' => 'detalle_motivo'],
        ['label' => 'Otros permisos', 'key' => 'detalle_otros_permisos'],
    ];
}

function dashboard_resolve_unit_value(array $message): string {
    $unidad = dashboard_resolve_department_value($message);
    $establecimiento = trim((string)($message['core_establecimiento'] ?? $message['unidad_solicitante'] ?? ''));
    if ($unidad === '' || strtoupper($unidad) === 'N/A') {
        return $establecimiento !== '' ? $establecimiento : 'N/A';
    }
    return $unidad;
}

function dashboard_manual_detail_row(array $message): array {
    return [
        'detalle_tipo_solicitud' => trim((string)($message['tipo'] ?? $message['core_tipo_solicitud'] ?? $message['mensaje'] ?? '')),
        'detalle_solicitante' => trim((string)($message['solicitante'] ?? '')),
        'detalle_categoria' => trim((string)($message['categoria'] ?? '')),
        'detalle_unidad' => trim((string)($message['unidad'] ?? $message['unidad_solicitante'] ?? '')),
        'detalle_descripcion' => trim((string)($message['descripcion'] ?? '')),
    ];
}

function dashboard_filter_detail_rows(array $rows): array {
    return array_values(array_filter($rows, function (array $row): bool {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }));
}

function dashboard_sanitize_redmine_text($value): string {
    $text = dashboard_fix_text_encoding((string)$value);
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/[\x{200D}\x{FE0F}\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $text) ?? $text;
    return trim($text);
}

function dashboard_render_textile_table(array $schema, array $rows): string {
    if (empty($schema) || empty($rows)) {
        return '';
    }
    $header = '|_. ' . implode('|_. ', array_map(fn($column) => $column['label'], $schema)) . '|';
    $lines = [$header];
    foreach ($rows as $row) {
        $values = array_map(
            fn($value) => str_replace(["\r", "\n", '|'], [' ', ' ', '/'], dashboard_sanitize_redmine_text($value)),
            array_map(fn($column) => $row[$column['key']] ?? '', $schema)
        );
        $lines[] = '|' . implode('|', $values) . '|';
    }
    return implode("\n", $lines);
}

function dashboard_detail_preview_rows(array $message): array {
    if (($message['fuente'] ?? '') === 'manual') {
        return dashboard_filter_detail_rows([dashboard_manual_detail_row($message)]);
    }
    $rows = [];
    foreach ((array)($message['core_detalle_items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $rows[] = dashboard_core_normalize_detail_row($item, $message);
    }
    if (empty($rows)) {
        $rows[] = dashboard_core_normalize_detail_row([
            'detalle_tipo_solicitud' => trim((string)($message['core_tipo_solicitud'] ?? $message['mensaje'] ?? '')),
            'detalle_run' => trim((string)($message['core_detalle_run'] ?? '')),
            'detalle_nombre' => trim((string)($message['core_detalle_nombre'] ?? ($message['solicitante'] ?? ''))),
            'detalle_motivo' => trim((string)($message['core_detalle_motivo'] ?? '')),
            'detalle_otros_permisos' => trim((string)($message['core_detalle_otros_permisos'] ?? '')),
            'detalle_fecha_nacimiento' => trim((string)($message['core_detalle_fecha_nacimiento'] ?? '')),
            'detalle_email' => dashboard_normalize_email($message['core_detalle_email'] ?? ''),
            'detalle_departamento' => trim((string)($message['core_detalle_departamento'] ?? '')),
            'detalle_cargo' => trim((string)($message['core_detalle_cargo'] ?? '')),
            'detalle_rol' => trim((string)($message['core_detalle_rol'] ?? '')),
            'detalle_estado' => trim((string)($message['core_detalle_estado'] ?? '')),
        ], $message);
    }
    return dashboard_filter_detail_rows($rows);
}

function dashboard_build_redmine_core_description(array $message): string {
    return dashboard_render_textile_table(
        dashboard_core_detail_table_schema($message),
        dashboard_detail_preview_rows($message)
    );
}

function dashboard_build_redmine_manual_description(array $message): string {
    return dashboard_sanitize_redmine_text($message['descripcion'] ?? '');
}

function dashboard_expand_message(array $message): array {
    $message = dashboard_repair_structure_encoding($message);
    if (($message['fuente'] ?? '') === 'manual') {
        return dashboard_expand_manual_message($message);
    }
    if (($message['fuente'] ?? '') !== 'core') {
        return $message;
    }
    [$fecha, $hora] = dashboard_core_parse_datetime((string)($message['core_fecha_creacion'] ?? ''));
    $numero = dashboard_normalize_phone((string)(($message['core_celular'] ?? '') !== '' ? ($message['core_celular'] ?? '') : ($message['core_telefono'] ?? '')));
    $subject = dashboard_core_build_subject($message);
    $message['numero'] = $numero;
    $message['mensaje'] = trim((string)($message['core_tipo_solicitud'] ?? ''));
    $message['descripcion'] = dashboard_core_build_description($message);
    $message['fecha'] = $fecha;
    $message['hora'] = $hora;
    $message['fecha_inicio'] = trim((string)($message['fecha_inicio'] ?? '')) !== '' ? $message['fecha_inicio'] : $fecha;
    $message['fecha_fin'] = trim((string)($message['fecha_fin'] ?? '')) !== '' ? $message['fecha_fin'] : $fecha;
    $message['tipo'] = 'Soporte';
    $message['prioridad'] = 'NORMAL';
    if (trim((string)($message['categoria'] ?? '')) !== '') {
        $message['categoria'] = $message['categoria'];
    } else {
        $message['categoria'] = dashboard_core_resolve_category(
            trim((string)($message['core_tipo_solicitud'] ?? '')),
            dashboard_catalog_names('categorias')
        );
    }
    $message['unidad'] = dashboard_resolve_unit_value($message);
    $message['unidad_solicitante'] = trim((string)($message['core_establecimiento'] ?? ''));
    $message['asunto'] = $subject;
    $message['asignado_nombre'] = trim((string)($message['core_usuario_asignado'] ?? ''));
    $message['hora_extra'] = trim((string)($message['hora_extra'] ?? '')) !== '' ? $message['hora_extra'] : '0';
    $message['tiempo_estimado'] = trim((string)($message['tiempo_estimado'] ?? ''));
    $message['core_detalle_items'] = array_values(array_filter(array_map(
        fn($item) => is_array($item) ? dashboard_core_normalize_detail_row($item, $message) : null,
        (array)($message['core_detalle_items'] ?? [])
    )));
    $message['core_detalle_fecha_nacimiento'] = trim((string)($message['core_detalle_fecha_nacimiento'] ?? ''));
    $message['core_detalle_email'] = dashboard_normalize_email($message['core_detalle_email'] ?? '');
    $message['core_detalle_departamento'] = trim((string)($message['core_detalle_departamento'] ?? ''));
    $message['core_detalle_cargo'] = trim((string)($message['core_detalle_cargo'] ?? ''));
    $message['core_detalle_rol'] = trim((string)($message['core_detalle_rol'] ?? ''));
    $message['core_detalle_estado'] = trim((string)($message['core_detalle_estado'] ?? ''));
    $message['redmine_id'] = trim((string)($message['redmine_id'] ?? ''));
    $message['procesado_ts'] = trim((string)($message['procesado_ts'] ?? ''));
    return $message;
}

function dashboard_compact_message(array $message): array {
    if (($message['fuente'] ?? '') !== 'core') {
        return $message;
    }
    $compact = [];
    foreach (dashboard_core_compact_keys() as $key) {
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

function load_messages(): array {
    $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
    if ($repo !== null && $repo->tableReady()) {
        return $repo->activeMessages();
    }
    return [];
}

function save_messages(array $messages): bool {
    $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
    if ($repo !== null && $repo->tableReady()) {
        return $repo->syncMessages($messages, load_platform_config());
    }

    return false;
}

function dashboard_update_message_hora_extra(array $message): bool {
    if (!class_exists(\Illuminate\Support\Facades\DB::class)) {
        return false;
    }

    $reportId = null;
    $fuenteId = trim((string)($message['fuente_id'] ?? ''));
    $fuente = trim((string)($message['fuente'] ?? ''));

    try {
        if ($fuenteId !== '') {
            $query = \Illuminate\Support\Facades\DB::table('redmine_mantencion_reportes')
                ->where('fuente_id', $fuenteId);
            if ($fuente !== '') {
                $query->where('fuente', $fuente);
            }
            $reportId = $query->value('id');
        } else {
            $id = trim((string)($message['id'] ?? ''));
            if ($id !== '' && ctype_digit($id)) {
                $reportId = (int)$id;
            }
        }
    } catch (Throwable) {
        return false;
    }

    if ($reportId === null) {
        return false;
    }

    $values = [
        'hora_extra' => normalize_hour_extra_value($message['hora_extra'] ?? '') === '1' ? 1 : 0,
        'actualizado_at' => function_exists('now') ? now() : date('Y-m-d H:i:s'),
    ];

    try {
        return \Illuminate\Support\Facades\DB::table('redmine_mantencion_reportes')
            ->where('id', (int)$reportId)
            ->update($values) > 0;
    } catch (Throwable) {
        return false;
    }
}

function load_platform_config(): array {
    $repo = config_mantencion_repository();
    if ($repo !== null) {
        $data = $repo->loadAll();
        if (is_array($data)) {
            $data['platform_token'] = '';
            return $data;
        }
    }
    return [];
}

function save_platform_config(array $cfg): void {
    $repo = config_mantencion_repository();
    if ($repo !== null) {
        $repo->saveAll($cfg);
    }
}

function dashboard_normalize_text(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/[ÃÂâ]/u', $value)) {
        $candidates = [$value];
        foreach (['ISO-8859-1', 'Windows-1252'] as $encoding) {
            $decoded = @iconv($encoding, 'UTF-8//IGNORE', $value);
            if (is_string($decoded) && trim($decoded) !== '') {
                $candidates[] = $decoded;
            }
        }
        $bestValue = $value;
        $bestScore = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $score = preg_match_all('/[ÃÂâ]/u', $candidate, $matches);
            if ($score === false) {
                $score = PHP_INT_MAX - 1;
            }
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestValue = $candidate;
            }
        }
        $value = $bestValue;
    }
    $value = strtr($value, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
        'Ä' => 'A', 'Ë' => 'E', 'Ï' => 'I', 'Ö' => 'O', 'Ü' => 'U',
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'Â' => 'A', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U',
        'ñ' => 'n', 'Ñ' => 'N',
    ]);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim($value ?? '');
}

/** @return array{key:string,label:string,icon:string,badge:string}|null */
function dashboard_core_status_indicator(array $message): ?array {
    $source = dashboard_normalize_text((string)($message['fuente'] ?? ''));
    $hasCoreIdentity = $source === 'core'
        || trim((string)($message['core_solicitud_id'] ?? $message['id_core'] ?? '')) !== '';
    if (!$hasCoreIdentity) {
        return null;
    }

    $coreStatus = dashboard_normalize_text((string)($message['core_estado'] ?? $message['core_detalle_estado'] ?? ''));
    return match ($coreStatus) {
        'en revision' => ['key' => 'review', 'label' => 'En Revisión', 'icon' => 'bi-hourglass-split', 'badge' => 'warning'],
        'gestionada' => ['key' => 'managed', 'label' => 'Gestionada', 'icon' => 'bi-check-circle-fill', 'badge' => 'success'],
        'rechazada' => ['key' => 'rejected', 'label' => 'Rechazada', 'icon' => 'bi-x-circle-fill', 'badge' => 'danger'],
        default => null,
    };
}

function dashboard_core_is_in_review(array $message): bool {
    return (dashboard_core_status_indicator($message)['key'] ?? '') === 'review';
}

function dashboard_normalize_phone(string $value): string {
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) === 9 && $digits[0] === '9') {
        return '+56' . $digits;
    }
    if (str_starts_with($digits, '56')) {
        return '+' . $digits;
    }
    return '+' . $digits;
}

function dashboard_catalog_names(string $file): array {
    $base  = strtolower(basename($file));
    $repo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
    if ($repo !== null) {
        return match ($base) {
            'categorias', 'categorias.json' => $repo->categoriaNames(),
            'unidades', 'unidades.json' => $repo->unidadNames(),
            default => [],
        };
    }

    return [];
}

function load_name_map(string $file, string $nameKey = 'nombre'): array {
    $base  = strtolower(basename($file));
    $repo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
    if ($repo !== null) {
        return match ($base) {
            'categorias', 'categorias.json' => $repo->categoriaNameMap(),
            'unidades', 'unidades.json' => $repo->unidadNameMap(),
            default => [],
        };
    }

    return [];
}

function parse_issue_date(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $value);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d');
        }
    }
    $timestamp = strtotime($value);
    if ($timestamp !== false && $timestamp > 0) {
        return (new DateTimeImmutable())->setTimestamp($timestamp)->format('Y-m-d');
    }
    return null;
}

function dashboard_infer_catalog_match(string $value, array $catalog, string $fallback = ''): string {
    $needle = dashboard_normalize_text($value);
    if ($needle === '') {
        return $fallback;
    }
    foreach ($catalog as $candidate) {
        $normalized = dashboard_normalize_text((string)$candidate);
        if ($normalized !== '' && ($normalized === $needle || str_contains($needle, $normalized) || str_contains($normalized, $needle))) {
            return (string)$candidate;
        }
    }
    return $fallback;
}

function dashboard_catalog_similarity_score(string $needle, string $candidate): float {
    if ($needle === '' || $candidate === '') {
        return 0.0;
    }
    if ($needle === $candidate) {
        return 1.0;
    }
    if (str_contains($candidate, $needle) || str_contains($needle, $candidate)) {
        return 0.9;
    }
    similar_text($needle, $candidate, $similarPercent);
    $similarity = ((float)$similarPercent) / 100.0;
    $distance = levenshtein($needle, $candidate);
    $maxLength = max(strlen($needle), strlen($candidate), 1);
    $distanceScore = 1.0 - min(1.0, $distance / $maxLength);
    $needleTokens = array_values(array_filter(explode(' ', $needle)));
    $candidateTokens = array_values(array_filter(explode(' ', $candidate)));
    $intersection = count(array_intersect($needleTokens, $candidateTokens));
    $union = count(array_unique(array_merge($needleTokens, $candidateTokens)));
    $tokenScore = $union > 0 ? ($intersection / $union) : 0.0;
    return max($similarity, $distanceScore, $tokenScore);
}

function dashboard_core_category_aliases(): array {
    return [
        'modificar usuario' => 'modificar perfil core',
        'creacion usuario' => 'creacion de usuario',
    ];
}

function dashboard_core_resolve_category(string $tipoSolicitud, array $catalog): string {
    $tipoSolicitud = trim($tipoSolicitud);
    if ($tipoSolicitud === '') {
        return 'Modificar Perfil CORE';
    }
    $normalizedType = dashboard_normalize_text($tipoSolicitud);
    $aliases = dashboard_core_category_aliases();
    if (isset($aliases[$normalizedType])) {
        $normalizedType = $aliases[$normalizedType];
    }
    $bestCandidate = '';
    $bestScore = 0.0;
    foreach ($catalog as $candidate) {
        $normalizedCandidate = dashboard_normalize_text((string)$candidate);
        if ($normalizedCandidate === '') {
            continue;
        }
        if ($normalizedCandidate === $normalizedType) {
            return (string)$candidate;
        }
        $score = dashboard_catalog_similarity_score($normalizedType, $normalizedCandidate);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestCandidate = (string)$candidate;
        }
    }
    if ($bestCandidate !== '' && $bestScore >= 0.45) {
        return $bestCandidate;
    }
    return 'Modificar Perfil CORE';
}

function dashboard_load_user_maps(): array {
    $result = ['phone' => [], 'name' => []];
    $users = function_exists('auth_central_users_for_mantencion') ? auth_central_users_for_mantencion() : [];
    if (!is_array($users)) {
        return $result;
    }
    foreach ($users as $user) {
        if (!is_array($user) || !dashboard_user_is_active($user)) {
            continue;
        }
        $id = trim((string)($user['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $phone = dashboard_normalize_phone((string)($user['numero_celular'] ?? ''));
        if ($phone !== '') {
            $result['phone'][$phone] = $user;
        }
        $fullName = trim((string)($user['nombre'] ?? ''));
        $fullNameKey = dashboard_normalize_text($fullName);
        if ($fullNameKey !== '') {
            $result['name'][$fullNameKey] = $user;
        }
    }
    return $result;
}

function dashboard_user_is_active(array $user): bool {
    $state = strtolower(trim((string)($user['estado'] ?? $user['estado_usuario'] ?? $user['status'] ?? 'activo')));
    return $state === '' || $state === 'activo' || $state === 'active';
}

function dashboard_active_mantencion_users(): array {
    $users = function_exists('auth_central_users_for_mantencion') ? auth_central_users_for_mantencion() : [];
    if (!is_array($users)) {
        return [];
    }

    $active = [];
    foreach ($users as $user) {
        if (!is_array($user) || !dashboard_user_is_active($user)) {
            continue;
        }
        $id = trim((string)($user['id'] ?? ''));
        $nombre = trim((string)($user['nombre'] ?? ''));
        $apellido = trim((string)($user['apellido'] ?? ''));
        $displayName = trim($nombre . ($apellido !== '' ? ' ' . $apellido : ''));
        if ($id === '' || $displayName === '') {
            continue;
        }
        $user['nombre_completo'] = $displayName;
        $active[] = $user;
    }

    usort($active, fn($a, $b) => strcasecmp((string)($a['nombre_completo'] ?? ''), (string)($b['nombre_completo'] ?? '')));
    return $active;
}

function dashboard_find_user_name(string $userId): string {
    $userId = trim($userId);
    if ($userId === '') {
        return '';
    }
    foreach (dashboard_active_mantencion_users() as $user) {
        if ((string)($user['id'] ?? '') === $userId) {
            return trim((string)($user['nombre_completo'] ?? ''));
        }
    }
    return '';
}

function dashboard_central_user_from_needles(array $needles): ?array {
    if (!class_exists(\Illuminate\Support\Facades\DB::class) || !class_exists(\Illuminate\Support\Facades\Schema::class)) {
        return null;
    }
    try {
        if (!\Illuminate\Support\Facades\Schema::hasTable('usuarios_nova')) {
            return null;
        }
        $values = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $needles)))));
        if (empty($values)) {
            return null;
        }
        $row = \Illuminate\Support\Facades\DB::table('usuarios_nova')
            ->where(function ($query) use ($values): void {
                foreach ($values as $value) {
                    $query->orWhere('uuid', $value)
                        ->orWhere('usuario', $value)
                        ->orWhere('rut', $value)
                        ->orWhere('usuario_core', $value);
                    if (ctype_digit($value)) {
                        $query->orWhere('id', (int)$value)
                            ->orWhere('redmine_id', (int)$value);
                    }
                }
            })
            ->first(['id', 'uuid', 'usuario', 'rut', 'redmine_id', 'nombre', 'apellido', 'rol', 'estado', 'usuario_core']);
        if (!$row) {
            return null;
        }
        $nombre = trim((string)($row->nombre ?? ''));
        $apellido = trim((string)($row->apellido ?? ''));
        return [
            'id' => trim((string)($row->redmine_id ?? '')) ?: trim((string)($row->usuario ?? $row->uuid ?? '')),
            '_nova_user_id' => trim((string)($row->uuid ?? $row->id ?? '')),
            'rut_sin_dv' => trim((string)($row->usuario ?? '')),
            'nombre' => $nombre,
            'apellido' => $apellido,
            'nombre_completo' => trim($nombre . ($apellido !== '' ? ' ' . $apellido : '')),
            'rut' => trim((string)($row->rut ?? '')),
            'core_user' => trim((string)($row->usuario_core ?? '')),
            'rol' => trim((string)($row->rol ?? 'usuario')),
            'estado' => trim((string)($row->estado ?? 'activo')),
        ];
    } catch (Throwable) {
        return null;
    }
}

function dashboard_current_user(): array {
    $userId = function_exists('auth_get_user_id') ? (string)auth_get_user_id() : '';
    $user = $userId !== '' && function_exists('auth_find_user_by_id') ? auth_find_user_by_id($userId) : null;
    // Not calling auth_start_session(): $_SESSION['user'] stays readable in memory
    // after the early session_write_close() in handle_request() — see the full
    // rationale on dashboard_security_actor() above. Reopening here would re-lock
    // the session file on nearly every AJAX/POST response (this function runs on
    // 'update' and on the AJAX response path for almost every action).
    $sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
    $novaUser = [];
    if (function_exists('session')) {
        $candidateNovaUser = session('nova_user');
        if (is_array($candidateNovaUser)) {
            $novaUser = $candidateNovaUser;
        }
    }
    if (empty($novaUser) && function_exists('request')) {
        try {
            $candidateNovaUser = request()->session()->get('nova_user');
            if (is_array($candidateNovaUser)) {
                $novaUser = $candidateNovaUser;
            }
        } catch (Throwable) {
        }
    }

    $pickFirst = static function (...$values): string {
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    };

    if (!empty($novaUser)) {
        $novaLegacy = is_array($novaUser['legacy'] ?? null) ? $novaUser['legacy'] : [];
        $sessionUser = array_merge($novaLegacy, $sessionUser, [
            '_nova_user_id' => $pickFirst($sessionUser['_nova_user_id'] ?? '', $novaUser['id'] ?? ''),
            'id' => $pickFirst($sessionUser['id'] ?? '', $novaUser['redmine_id'] ?? '', $novaUser['username'] ?? '', $novaUser['id'] ?? ''),
            'nombre' => $pickFirst($sessionUser['nombre'] ?? '', $novaUser['name'] ?? ''),
            'apellido' => $pickFirst($sessionUser['apellido'] ?? '', $novaUser['apellido'] ?? ''),
            'rut' => $pickFirst($sessionUser['rut'] ?? '', $novaUser['rut'] ?? ''),
            'rut_sin_dv' => $pickFirst($sessionUser['rut_sin_dv'] ?? '', $novaUser['rut_sin_dv'] ?? '', $novaUser['username'] ?? ''),
            'core_user' => $pickFirst($sessionUser['core_user'] ?? '', $novaUser['core_user'] ?? ''),
            'rol' => $pickFirst($sessionUser['rol'] ?? '', $novaUser['role'] ?? '', 'usuario'),
            'estado' => $pickFirst($sessionUser['estado'] ?? '', $novaUser['status'] ?? '', 'activo'),
        ]);
    }

    $identityNeedles = [
        $userId,
        $sessionUser['_nova_user_id'] ?? '',
        $sessionUser['id'] ?? '',
        $sessionUser['rut'] ?? '',
        $sessionUser['rut_sin_dv'] ?? '',
        $sessionUser['core_user'] ?? '',
        $novaUser['id'] ?? '',
        $novaUser['username'] ?? '',
        $novaUser['redmine_id'] ?? '',
        $novaUser['rut'] ?? '',
        $novaUser['rut_sin_dv'] ?? '',
        $novaUser['core_user'] ?? '',
    ];

    if (!is_array($user)) {
        $needles = array_filter(array_map('dashboard_normalize_text', $identityNeedles));
        $candidates = function_exists('auth_central_users_for_mantencion') ? auth_central_users_for_mantencion() : [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidateNeedles = array_filter(array_map('dashboard_normalize_text', [
                $candidate['id'] ?? '',
                $candidate['_nova_user_id'] ?? '',
                $candidate['rut'] ?? '',
                $candidate['rut_sin_dv'] ?? '',
                $candidate['core_user'] ?? '',
            ]));
            if (array_intersect($needles, $candidateNeedles) !== []) {
                $user = $candidate;
                break;
            }
        }
    }

    if (!is_array($user) || trim((string)($user['nombre'] ?? $user['name'] ?? $user['nombre_completo'] ?? '')) === '') {
        $centralUser = dashboard_central_user_from_needles($identityNeedles);
        if (is_array($centralUser)) {
            $user = is_array($user) ? array_merge($user, $centralUser) : $centralUser;
        }
    }

    $user = is_array($user) ? array_merge($sessionUser, $user) : $sessionUser;
    $nombre = trim((string)($user['nombre'] ?? $user['name'] ?? ''));
    $apellido = trim((string)($user['apellido'] ?? ''));
    $fullName = trim((string)($user['nombre_completo'] ?? ''));
    if ($fullName === '') {
        $fullName = trim($nombre . ($apellido !== '' ? ' ' . $apellido : ''));
    }
    $user['nombre'] = $nombre;
    $user['apellido'] = $apellido;
    $user['nombre_completo'] = $fullName;
    return $user;
}

function dashboard_current_user_full_name(): string {
    return trim((string)(dashboard_current_user()['nombre_completo'] ?? ''));
}

function dashboard_can_assign_other_users(): bool {
    if (function_exists('auth_user_has_all_permissions') && auth_user_has_all_permissions()) {
        return true;
    }
    $scope = function_exists('auth_get_permission_value') ? auth_get_permission_value('mensajes') : null;
    return strtolower(trim((string)$scope)) === 'todos';
}

function dashboard_can_select_core_assignee(?array $novaUser = null): bool {
    if ($novaUser === null) {
        $novaUser = [];
        if (function_exists('session')) {
            try {
                $candidate = session('nova_user');
                if (is_array($candidate)) {
                    $novaUser = $candidate;
                }
            } catch (Throwable) {
            }
        }
        if ($novaUser === [] && function_exists('request')) {
            try {
                $candidate = request()->session()->get('nova_user');
                if (is_array($candidate)) {
                    $novaUser = $candidate;
                }
            } catch (Throwable) {
            }
        }
    }

    return strtolower(trim((string)($novaUser['role'] ?? 'usuario'))) === 'root';
}

function dashboard_enforced_assigned_name(string $submitted = ''): string {
    $current = dashboard_current_user_full_name();
    if (!dashboard_can_select_core_assignee()) {
        return $current;
    }
    $submitted = trim($submitted);
    if ($submitted !== '' && dashboard_find_active_user_by_name($submitted) !== null) {
        return $submitted;
    }

    return $current;
}

function dashboard_find_active_user_by_name(string $name): ?array {
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    foreach (dashboard_active_mantencion_users() as $user) {
        if (dashboard_name_tokens_match($name, (string)($user['nombre_completo'] ?? ''))) {
            return $user;
        }
    }
    return null;
}

function dashboard_apply_import_assignment(array $message, array $filters): array {
    $targetUser = null;
    if (!dashboard_can_select_core_assignee()) {
        $targetUser = dashboard_current_user();
    } else {
        $assigned = trim((string)($filters['assigned'] ?? ''));
        if ($assigned !== '') {
            $targetUser = dashboard_find_active_user_by_name($assigned);
        }
    }

    if (is_array($targetUser) && !empty($targetUser)) {
        $targetId = trim((string)($targetUser['id'] ?? ''));
        $targetName = trim((string)($targetUser['nombre_completo'] ?? trim((string)($targetUser['nombre'] ?? $targetUser['name'] ?? '') . ' ' . (string)($targetUser['apellido'] ?? ''))));
        if ($targetId !== '') {
            $message['asignado_a'] = $targetId;
        }
        if ($targetName !== '') {
            $message['asignado_nombre'] = $targetName;
        }
    }

    return $message;
}

function dashboard_core_is_configured(array $cfg): bool {
    return !empty($cfg['core_enabled'])
        && trim((string)($cfg['core_admin_url'] ?? '')) !== '';
}

function dashboard_should_auto_sync_core(array $cfg): bool {
    return false;
}

function dashboard_core_runtime_credentials(array $input = []): array {
    return [
        'user' => trim((string)($input['user'] ?? '')),
        'pass' => trim((string)($input['pass'] ?? '')),
    ];
}

function dashboard_core_credentials_for_current_user(): array {
    // La ficha NOVA y Cuentas conectadas usan este repositorio como fuente
    // canónica. Leer por la misma vía evita que el bridge legacy confunda el
    // ID del perfil Redmine con el ID/UUID central del usuario autenticado.
    if (function_exists('session') && function_exists('app')) {
        try {
            $novaUser = session('nova_user');
            if (is_array($novaUser) && !empty($novaUser)) {
                $stored = app(\App\Modulos\Nova\Repositories\UserIntegrationRepository::class)
                    ->credentialForSession($novaUser, 'core');
                if (!empty($stored['stored'])) {
                    return [
                        'user' => trim((string)($stored['user'] ?? '')),
                        'pass' => trim((string)($stored['secret'] ?? '')),
                    ];
                }
            }
        } catch (\Throwable) {
            // Conserva compatibilidad con ejecuciones legacy fuera de Laravel.
        }
    }

    return core_credentials_for_user(dashboard_core_current_credential_user_key());
}

function dashboard_core_has_saved_credentials(): bool {
    return dashboard_core_has_runtime_credentials(dashboard_core_credentials_for_current_user());
}

function dashboard_core_current_credential_user_key(array $currentUser = []): string {
    if (empty($currentUser)) {
        $currentUser = dashboard_current_user();
    }

    $novaId = trim((string)($currentUser['_nova_user_id'] ?? ''));
    if ($novaId !== '') {
        return ctype_digit($novaId) ? 'nova:' . $novaId : 'uuid:' . $novaId;
    }

    $uuid = trim((string)($currentUser['uuid'] ?? ''));
    if ($uuid !== '') {
        return 'uuid:' . $uuid;
    }

    foreach ([$currentUser['redmine_id'] ?? '', $currentUser['id'] ?? ''] as $redmineId) {
        $redmineId = trim((string)$redmineId);
        if ($redmineId !== '') {
            return 'redmine:' . $redmineId;
        }
    }

    $authenticatedId = function_exists('auth_get_user_id') ? trim((string)auth_get_user_id()) : '';
    if ($authenticatedId !== '') {
        return 'redmine:' . $authenticatedId;
    }

    return '';
}

function dashboard_core_has_runtime_credentials(array $credentials): bool {
    return trim((string)($credentials['user'] ?? '')) !== ''
        && trim((string)($credentials['pass'] ?? '')) !== '';
}

function dashboard_core_curl(string $url, array $options = []): array {
    $ch = curl_init($url);
    $default = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'HBV Redmine Sync/1.0',
    ];
    curl_setopt_array($ch, $options + $default);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [
        'body' => $body === false ? '' : (string)$body,
        'error' => $error,
        'http_code' => $httpCode,
        'effective_url' => $effectiveUrl,
    ];
}

function dashboard_core_parse_login_form(string $html, string $baseUrl): array {
    $form = [
        'action' => $baseUrl,
        'csrf_token' => '',
        'has_login_form' => false,
        'fields' => [],
    ];
    if ($html === '') {
        return $form;
    }
    if (preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER)) {
        foreach ($forms as $match) {
            $attrs = $match[1] ?? '';
            $inner = $match[2] ?? '';
            if (!str_contains($inner, 'name="login_string"') || !str_contains($inner, 'name="login_pass"')) {
                continue;
            }
            $form['has_login_form'] = true;
            $action = '';
            if (preg_match('/action\s*=\s*"([^"]+)"/i', $attrs, $actionMatch)) {
                $action = trim($actionMatch[1]);
            }
            if ($action !== '') {
                if (preg_match('~^https?://~i', $action)) {
                    $form['action'] = $action;
                } else {
                    $parts = parse_url($baseUrl);
                    $scheme = $parts['scheme'] ?? 'https';
                    $host = $parts['host'] ?? '';
                    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                    $prefix = $scheme . '://' . $host . $port;
                    $form['action'] = str_starts_with($action, '/') ? $prefix . $action : rtrim(dirname($baseUrl), '/') . '/' . ltrim($action, '/');
                }
            }
            if (preg_match_all('/<input\b([^>]*)>/is', $inner, $inputMatches, PREG_SET_ORDER)) {
                foreach ($inputMatches as $inputMatch) {
                    $inputAttrs = $inputMatch[1] ?? '';
                    if (!preg_match('/name\s*=\s*"([^"]+)"/i', $inputAttrs, $nameMatch)) {
                        continue;
                    }
                    $fieldName = trim($nameMatch[1]);
                    if ($fieldName === '') {
                        continue;
                    }
                    $fieldValue = '';
                    if (preg_match('/value\s*=\s*"([^"]*)"/i', $inputAttrs, $valueMatch)) {
                        $fieldValue = $valueMatch[1];
                    }
                    $form['fields'][$fieldName] = $fieldValue;
                }
            }
            if (isset($form['fields']['csrf_token'])) {
                $form['csrf_token'] = (string)$form['fields']['csrf_token'];
            }
            break;
        }
    }
    return $form;
}

function dashboard_core_response_requires_auth(array $response): bool {
    $body = (string)($response['body'] ?? '');
    if ((int)($response['http_code'] ?? 0) === 401) {
        return true;
    }
    $normalized = dashboard_normalize_text($body);
    if (str_contains($normalized, 'no autorizado')
        || str_contains($normalized, 'iniciar sesion en core')
        || str_contains($normalized, 'usuario rut sin digito verificador o email')) {
        return true;
    }
    $payload = json_decode($body, true);
    if (is_array($payload)) {
        $error = dashboard_normalize_text((string)($payload['error'] ?? $payload['message'] ?? ''));
        return str_contains($error, 'no autorizado') || str_contains($error, 'unauthorized');
    }
    return false;
}

function dashboard_core_extract_rows(string $html): array {
    $requiredHeaders = [
        'solicitante',
        'fecha de creacion',
        'tipo de solicitud',
        'establecimiento',
        'departamento',
        'telefono',
        'celular',
        'email',
        'estado',
        'usuario asignado',
    ];
    $rows = [];
    if ($html === '') {
        return $rows;
    }
    if (!preg_match_all('/<table\b[^>]*>(.*?)<\/table>/is', $html, $tables, PREG_SET_ORDER)) {
        return $rows;
    }
    foreach ($tables as $tableMatch) {
        $tableHtml = $tableMatch[1] ?? '';
        if (!preg_match_all('/<th\b[^>]*>(.*?)<\/th>/is', $tableHtml, $headerMatches)) {
            continue;
        }
        $headers = [];
        foreach (($headerMatches[1] ?? []) as $headerHtml) {
            $headers[] = trim(html_entity_decode(strip_tags($headerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (empty($headers)) {
            continue;
        }
        $normalizedHeaders = array_map('dashboard_normalize_text', $headers);
        $missing = array_diff($requiredHeaders, $normalizedHeaders);
        if (!empty($missing)) {
            continue;
        }
        if (!preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $tableHtml, $rowMatches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($rowMatches as $rowIndex => $trMatch) {
            if ($rowIndex === 0) {
                continue;
            }
            $rowHtml = $trMatch[1] ?? '';
            if (!preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $rowHtml, $cellMatches)) {
                continue;
            }
            $cells = $cellMatches[1] ?? [];
            if (count($cells) < count($headers)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $headerText) {
                $key = dashboard_normalize_text((string)$headerText);
                $row[$key] = trim(html_entity_decode(strip_tags($cells[$index] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $candidateRequestIds = dashboard_core_extract_candidate_request_ids($rowHtml);
            $row['_candidate_request_ids'] = $candidateRequestIds;
            if (!empty($candidateRequestIds)) {
                $row['id_solicitud_core'] = $row['id_solicitud_core'] ?? $candidateRequestIds[0];
                $row['id'] = $row['id'] ?? $candidateRequestIds[0];
            }
            if (($row['solicitante'] ?? '') === '') {
                continue;
            }
            $rows[] = $row;
        }
        if (!empty($rows)) {
            return $rows;
        }
    }
    return $rows;
}

function dashboard_core_extract_detail_table_rows(string $html): array {
    $requiredHeaders = [
        'tipo solicitud',
        'run',
        'nombre',
        'motivo',
        'otros permisos',
    ];
    $rows = [];
    if ($html === '') {
        return $rows;
    }
    if (!preg_match_all('/<table\b[^>]*>(.*?)<\/table>/is', $html, $tables, PREG_SET_ORDER)) {
        return $rows;
    }
    foreach ($tables as $tableMatch) {
        $tableHtml = $tableMatch[1] ?? '';
        $headers = [];
        if (preg_match_all('/<th\b[^>]*>(.*?)<\/th>/is', $tableHtml, $headerMatches)) {
            foreach (($headerMatches[1] ?? []) as $headerHtml) {
                $headers[] = dashboard_normalize_text(trim(html_entity_decode(strip_tags($headerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            }
        }
        if (empty($headers) && preg_match('/<tr\b[^>]*>(.*?)<\/tr>/is', $tableHtml, $firstRowMatch)) {
            if (preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $firstRowMatch[1] ?? '', $headerCellMatches)) {
                foreach (($headerCellMatches[1] ?? []) as $headerHtml) {
                    $headers[] = dashboard_normalize_text(trim(html_entity_decode(strip_tags($headerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                }
            }
        }
        if (empty($headers)) {
            continue;
        }
        if (!empty(array_diff($requiredHeaders, $headers))) {
            continue;
        }
        if (!preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $tableHtml, $rowMatches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($rowMatches as $rowIndex => $trMatch) {
            if ($rowIndex === 0) {
                continue;
            }
            $rowHtml = $trMatch[1] ?? '';
            if (!preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $rowHtml, $cellMatches)) {
                continue;
            }
            $cells = $cellMatches[1] ?? [];
            if (count($cells) < count($headers)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $headerText) {
                $row[$headerText] = trim(html_entity_decode(strip_tags($cells[$index] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $normalized = dashboard_core_normalize_detail_row($row);
            $hasValue = false;
            foreach ($normalized as $value) {
                if (trim((string)$value) !== '' && trim((string)$value) !== '-') {
                    $hasValue = true;
                    break;
                }
            }
            if ($hasValue) {
                $rows[] = $normalized;
            }
        }
        if (!empty($rows)) {
            return $rows;
        }
    }
    return $rows;
}

function dashboard_array_is_list(array $value): bool {
    $index = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $index) {
            return false;
        }
        $index++;
    }
    return true;
}

function dashboard_name_tokens_match(string $expected, string $candidate): bool {
    $expected = dashboard_normalize_text($expected);
    $candidate = dashboard_normalize_text($candidate);
    if ($expected === '' || $candidate === '') {
        return false;
    }
    if ($expected === $candidate || str_contains($candidate, $expected) || str_contains($expected, $candidate)) {
        return true;
    }
    $expectedTokens = array_values(array_filter(explode(' ', $expected)));
    $candidateTokens = array_values(array_filter(explode(' ', $candidate)));
    if (empty($expectedTokens) || empty($candidateTokens)) {
        return false;
    }
    foreach ($expectedTokens as $token) {
        if (!in_array($token, $candidateTokens, true)) {
            return false;
        }
    }
    return true;
}

function dashboard_core_pick_first_value(array $item, array $keys): string {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $item)) {
            continue;
        }
        $value = trim((string)($item[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function dashboard_core_pick_first_recursive(array $item, array $keys): string {
    $direct = dashboard_core_pick_first_value($item, $keys);
    if ($direct !== '') {
        return $direct;
    }
    foreach ($item as $value) {
        if (!is_array($value)) {
            continue;
        }
        $found = dashboard_core_pick_first_recursive($value, $keys);
        if ($found !== '') {
            return $found;
        }
    }
    return '';
}

function dashboard_core_collect_recursive_strings(mixed $value): array {
    $strings = [];
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed !== '') {
            $strings[] = $trimmed;
        }
        return $strings;
    }
    if (!is_array($value)) {
        return $strings;
    }
    foreach ($value as $child) {
        foreach (dashboard_core_collect_recursive_strings($child) as $item) {
            $strings[] = $item;
        }
    }
    return $strings;
}

function dashboard_core_extract_detail_fields(array $item): array {
    $details = [
        'detalle_tipo_solicitud' => dashboard_core_pick_first_recursive($item, ['detalle_tipo_solicitud', 'tipo_solicitud_detalle', 'detalle_tipo', 'detalle_tipo_sol']),
        'detalle_run' => dashboard_core_pick_first_recursive($item, ['run', 'rut', 'detalle_run', 'detalle_rut', 'run_usuario']),
        'detalle_nombre' => dashboard_core_pick_first_recursive($item, ['nombre', 'detalle_nombre', 'nombre_usuario', 'usuario_nombre', 'nombre_completo']),
        'detalle_motivo' => dashboard_core_pick_first_recursive($item, ['motivo', 'detalle_motivo', 'motivo_solicitud']),
        'detalle_establecimientos' => dashboard_core_pick_first_recursive($item, ['establecimientos', 'detalle_establecimientos', 'detalle_estab']),
        'detalle_otros_permisos' => dashboard_core_pick_first_recursive($item, ['otros_permisos', 'detalle_otros_permisos', 'permisos_adicionales']),
        'detalle_fecha_nacimiento' => dashboard_core_pick_first_recursive($item, ['fecha_nacimiento', 'fec_nacimiento', 'fecha_nac', 'detalle_fecha_nacimiento']),
        'detalle_email' => dashboard_normalize_email(dashboard_core_pick_first_recursive($item, ['email', 'correo', 'detalle_email'])),
        'detalle_departamento' => dashboard_core_pick_first_recursive($item, ['departamento', 'depto', 'detalle_departamento']),
        'detalle_cargo' => dashboard_core_pick_first_recursive($item, ['cargo', 'detalle_cargo', 'id_cargo']),
        'detalle_rol' => dashboard_core_pick_first_recursive($item, ['rol', 'detalle_rol']),
        'detalle_estado' => dashboard_core_pick_first_recursive($item, ['estado', 'detalle_estado']),
    ];
    if ($details['detalle_nombre'] === '') {
        $nombrePartes = array_filter([
            dashboard_core_pick_first_recursive($item, ['nombres_ins']),
            dashboard_core_pick_first_recursive($item, ['apepat_ins']),
            dashboard_core_pick_first_recursive($item, ['apemat_ins']),
        ], fn($value) => trim((string)$value) !== '');
        if (!empty($nombrePartes)) {
            $details['detalle_nombre'] = implode(' ', $nombrePartes);
        }
    }
    $blob = dashboard_core_pick_first_recursive($item, ['detalle', 'detalle_solicitud', 'descripcion', 'observacion', 'observaciones']);
    if ($blob !== '') {
        $normalizedBlob = preg_replace("/\r\n?/", "\n", html_entity_decode(strip_tags($blob), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $patterns = [
            'detalle_tipo_solicitud' => ['tipo solicitud', 'tipo de solicitud'],
            'detalle_run' => ['run', 'rut'],
            'detalle_nombre' => ['nombre'],
            'detalle_motivo' => ['motivo'],
            'detalle_establecimientos' => ['establecimientos', 'establecimiento'],
            'detalle_otros_permisos' => ['otros permisos', 'permisos'],
            'detalle_fecha_nacimiento' => ['fecha de nacimiento', 'fecha nacimiento'],
            'detalle_email' => ['email', 'correo'],
            'detalle_departamento' => ['departamento'],
            'detalle_cargo' => ['cargo'],
            'detalle_rol' => ['rol'],
            'detalle_estado' => ['estado'],
        ];
        foreach ($patterns as $field => $labels) {
            if ($details[$field] !== '') {
                continue;
            }
            foreach ($labels as $label) {
                $regex = '/(?:^|\n)\s*' . preg_quote($label, '/') . '\s*:\s*(.+?)(?=\n\s*[A-Za-zÁÉÍÓÚáéíóúÑñ ]+\s*:|$)/isu';
                if (preg_match($regex, $normalizedBlob, $match)) {
                    $details[$field] = trim($match[1]);
                    break;
                }
            }
        }
    }
    return $details;
}

function dashboard_core_detail_defaults(): array {
    return [
        'detalle_tipo_solicitud' => '',
        'detalle_run' => '',
        'detalle_nombre' => '',
        'detalle_motivo' => '',
        'detalle_establecimientos' => '',
        'detalle_otros_permisos' => '',
        'detalle_fecha_nacimiento' => '',
        'detalle_email' => '',
        'detalle_departamento' => '',
        'detalle_cargo' => '',
        'detalle_rol' => '',
        'detalle_estado' => '',
        'detalle_items' => [],
    ];
}

function dashboard_core_merge_detail_fields(array $base, array $extra): array {
    foreach (dashboard_core_detail_defaults() as $key => $default) {
        if ($key === 'detalle_items') {
            $baseItems = [];
            foreach ((array)($base[$key] ?? []) as $item) {
                if (is_array($item)) {
                    $baseItems[] = dashboard_core_normalize_detail_row($item);
                }
            }
            if (empty($baseItems)) {
                foreach ((array)($extra[$key] ?? []) as $item) {
                    if (is_array($item)) {
                        $baseItems[] = dashboard_core_normalize_detail_row($item);
                    }
                }
            }
            $base[$key] = $baseItems;
            continue;
        }
        if (trim((string)($base[$key] ?? '')) === '' && trim((string)($extra[$key] ?? '')) !== '') {
            $base[$key] = trim((string)$extra[$key]);
        }
    }
    return $base;
}

function dashboard_core_detail_slug(string $tipoSolicitud): string {
    $tipo = dashboard_normalize_text($tipoSolicitud);
    if ($tipo === '') {
        return '';
    }
    $tokens = array_values(array_filter(explode(' ', $tipo), fn($token) => $token !== 'de'));
    return implode('_', $tokens);
}

function dashboard_core_extract_candidate_request_ids(string $html): array {
    if ($html === '') {
        return [];
    }
    $ids = [];
    if (preg_match_all('/data-(?:id|solicitud|solicitud-id|solicitud_id)\s*=\s*["\']?(\d{2,})["\']?/i', $html, $matches)) {
        foreach (($matches[1] ?? []) as $id) {
            $ids[] = trim((string)$id);
        }
    }
    if (preg_match_all('#/obtener_detalle_[^/]+/(\d+)#i', $html, $matches)) {
        foreach (($matches[1] ?? []) as $id) {
            $ids[] = trim((string)$id);
        }
    }
    if (preg_match_all('/(?:ver|detalle|editar|modificar|obtener)[^0-9]{0,25}["\']?(\d{2,})["\']?/i', $html, $matches)) {
        foreach (($matches[1] ?? []) as $id) {
            $ids[] = trim((string)$id);
        }
    }
    if (preg_match_all('/\b(?:id_solicitud_core|id_solicitud|solicitud_id|id)\b[^0-9]{0,12}(\d{2,})/i', $html, $matches)) {
        foreach (($matches[1] ?? []) as $id) {
            $ids[] = trim((string)$id);
        }
    }
    if (preg_match('/peticiones relacionadas|subtareas/i', $html) && preg_match_all('/>(\d{2,})</', $html, $matches)) {
        foreach (($matches[1] ?? []) as $id) {
            $ids[] = trim((string)$id);
        }
    }
    return array_values(array_unique(array_filter($ids, fn($id) => $id !== '')));
}

function dashboard_core_extract_related_request_ids_from_body(string $body): array {
    $segments = [];
    if (preg_match_all('/(?:peticiones relacionadas|subtareas)(.{0,6000})/isu', $body, $matches)) {
        $segments = array_merge($segments, $matches[0] ?? []);
    }
    if (empty($segments)) {
        $segments[] = $body;
    }
    $ids = [];
    foreach ($segments as $segment) {
        foreach (dashboard_core_extract_candidate_request_ids((string)$segment) as $id) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique(array_filter($ids, fn($id) => $id !== '')));
}

function dashboard_core_detail_url_candidates(string $baseUrl, array $row, ?string $solicitudIdOverride = null): array {
    $solicitudId = trim((string)($solicitudIdOverride ?? $row['id_solicitud_core'] ?? $row['id'] ?? ''));
    $tipoSolicitud = trim((string)($row['tipo de solicitud'] ?? ''));
    $normalizedType = dashboard_normalize_text($tipoSolicitud);
    if ($solicitudId === '' || $tipoSolicitud === '') {
        return [];
    }
    $baseUrl = rtrim($baseUrl, '/');
    if ($baseUrl === '') {
        return [];
    }
    $slugs = [];
    $slugWithoutDe = dashboard_core_detail_slug($tipoSolicitud);
    $slugFull = str_replace(' ', '_', $normalizedType);
    foreach ([$slugWithoutDe, $slugFull] as $slug) {
        if ($slug !== '') {
            $slugs[] = $slug;
        }
    }
    if (
        $normalizedType === 'creacion de usuario'
        || $normalizedType === 'creacion usuario'
        || (str_contains($normalizedType, 'creaci') && str_contains($normalizedType, 'usuario'))
    ) {
        $slugs[] = 'credencial_core';
        $slugs[] = 'creacion_de_usuario';
        $slugs[] = 'creacion_usuario';
    }
    $slugs = array_values(array_unique(array_filter($slugs)));
    return array_map(
        fn($slug) => $baseUrl . '/obtener_detalle_' . $slug . '/' . rawurlencode($solicitudId),
        $slugs
    );
}

function dashboard_core_extract_detail_from_body(string $body): array {
    $details = dashboard_core_detail_defaults();
    $json = json_decode($body, true);
    if (is_array($json)) {
        $jsonItems = dashboard_array_is_list($json) ? $json : [$json];
        $normalizedItems = [];
        foreach ($jsonItems as $jsonItem) {
            if (!is_array($jsonItem)) {
                continue;
            }
            $normalizedRow = dashboard_core_normalize_detail_row($jsonItem);
            $hasValue = false;
            foreach ($normalizedRow as $value) {
                if (trim((string)$value) !== '' && trim((string)$value) !== '-') {
                    $hasValue = true;
                    break;
                }
            }
            if ($hasValue) {
                $normalizedItems[] = $normalizedRow;
            }
        }
        if (!empty($normalizedItems)) {
            $details['detalle_items'] = $normalizedItems;
            $details = dashboard_core_merge_detail_fields($details, $normalizedItems[0]);
        }
        $details = dashboard_core_merge_detail_fields($details, dashboard_core_extract_detail_fields($json));
        foreach (dashboard_core_collect_recursive_strings($json) as $candidateHtml) {
            $detailItems = dashboard_core_extract_detail_table_rows($candidateHtml);
            if (!empty($detailItems)) {
                $details['detalle_items'] = $detailItems;
                $details = dashboard_core_merge_detail_fields($details, $detailItems[0]);
                break;
            }
        }
    }
    if (empty($details['detalle_items'])) {
        $detailItems = dashboard_core_extract_detail_table_rows($body);
    } else {
        $detailItems = [];
    }
    if (!empty($detailItems)) {
        $details['detalle_items'] = $detailItems;
        $details = dashboard_core_merge_detail_fields($details, $detailItems[0]);
    }
    $normalizedBody = preg_replace("/\r\n?/", "\n", html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($normalizedBody === null) {
        $normalizedBody = '';
    }
    $patterns = [
        'detalle_tipo_solicitud' => ['tipo solicitud', 'tipo de solicitud'],
        'detalle_run' => ['run', 'rut'],
        'detalle_nombre' => ['nombre'],
        'detalle_motivo' => ['motivo'],
        'detalle_establecimientos' => ['establecimientos', 'establecimiento'],
        'detalle_otros_permisos' => ['otros permisos', 'permisos'],
    ];
    foreach ($patterns as $field => $labels) {
        if (trim((string)$details[$field]) !== '') {
            continue;
        }
        foreach ($labels as $label) {
            $regex = '/(?:^|\n)\s*' . preg_quote($label, '/') . '\s*:\s*(.+?)(?=\n\s*[A-Za-zÁÉÍÓÚáéíóúÑñ ]+\s*:|$)/isu';
            if (preg_match($regex, $normalizedBody, $match)) {
                $details[$field] = trim($match[1]);
                break;
            }
        }
    }
    return $details;
}

function dashboard_core_enrich_rows_with_detail(array $rows, string $baseUrl, string $cookieJar, array $requestHeaders): array {
    $startedAt = microtime(true);
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((microtime(true) - $startedAt) >= 45) {
            break;
        }
        $candidateIds = [];
        foreach ((array)($row['_candidate_request_ids'] ?? []) as $candidateId) {
            $candidateIds[] = trim((string)$candidateId);
        }
        $candidateIds[] = trim((string)($row['id_solicitud_core'] ?? $row['id'] ?? ''));
        $candidateIds = array_values(array_unique(array_filter($candidateIds, fn($id) => $id !== '')));
        if (empty($candidateIds)) {
            continue;
        }
        $visitedIds = [];
        $requests = 0;
        while (!empty($candidateIds) && $requests < 12) {
            $currentId = array_shift($candidateIds);
            if ($currentId === '' || isset($visitedIds[$currentId])) {
                continue;
            }
            $visitedIds[$currentId] = true;
            $detailUrls = dashboard_core_detail_url_candidates($baseUrl, $row, $currentId);
            foreach ($detailUrls as $detailUrl) {
                $requests++;
                $detailResponse = dashboard_core_curl($detailUrl, [
                    CURLOPT_COOKIEJAR => $cookieJar,
                    CURLOPT_COOKIEFILE => $cookieJar,
                    CURLOPT_HTTPHEADER => $requestHeaders,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 5,
                ]);
                if (($detailResponse['error'] ?? '') !== '' || (int)($detailResponse['http_code'] ?? 0) >= 400) {
                    continue;
                }
                $detailBody = (string)($detailResponse['body'] ?? '');
                $detailFields = dashboard_core_extract_detail_from_body($detailBody);
                $row = dashboard_core_merge_detail_fields($row, $detailFields);
                foreach (dashboard_core_extract_related_request_ids_from_body($detailBody) as $relatedId) {
                    if (!isset($visitedIds[$relatedId])) {
                        $candidateIds[] = $relatedId;
                    }
                }
                if (!empty($detailFields['detalle_items']) || trim((string)($detailFields['detalle_run'] ?? '')) !== '' || trim((string)($detailFields['detalle_nombre'] ?? '')) !== '') {
                    $rows[$index] = $row;
                    break 2;
                }
            }
        }
        $rows[$index] = $row;
    }
    return $rows;
}

function dashboard_core_source_row_matches_filters(array $row, array $filters = []): bool {
    $desde = trim((string)($filters['desde'] ?? ''));
    $hasta = trim((string)($filters['hasta'] ?? ''));
    $assigned = trim((string)($filters['assigned'] ?? ''));
    $currentUser = is_array($filters['_current_user'] ?? null) ? $filters['_current_user'] : [];
    $fecha = parse_issue_date((string)($row['fecha de creacion'] ?? ''));
    if ($desde !== '' && $fecha !== null && $fecha < $desde) {
        return false;
    }
    if ($hasta !== '' && $fecha !== null && $fecha > $hasta) {
        return false;
    }
    if (!empty($currentUser) || $assigned !== '') {
        $candidate = trim((string)($row['usuario asignado'] ?? ''));
        if ($candidate === '') {
            return false;
        }
        $matchesCurrentUser = !empty($currentUser) && dashboard_user_matches_assigned($candidate, $currentUser);
        $matchesAssignedName = $assigned !== '' && dashboard_name_tokens_match($assigned, $candidate);
        if (!$matchesCurrentUser && !$matchesAssignedName) {
            return false;
        }
    }
    return true;
}

function dashboard_core_extract_json_rows(string $body): array {
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return [];
    }
    $items = dashboard_core_json_items($payload);
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (dashboard_array_is_list($item)) {
            $values = array_map(fn($value) => trim(html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8')), array_values($item));
            $offset = isset($values[0]) && preg_match('/^\d{2,}$/', $values[0]) ? 1 : 0;
            $row = [
                'id' => $offset === 1 ? ($values[0] ?? '') : '',
                'id_solicitud_core' => $offset === 1 ? ($values[0] ?? '') : '',
                'solicitante' => $values[$offset] ?? '',
                'fecha de creacion' => $values[$offset + 1] ?? '',
                'tipo de solicitud' => $values[$offset + 2] ?? '',
                'establecimiento' => $values[$offset + 3] ?? '',
                'departamento' => $values[$offset + 4] ?? '',
                'telefono' => $values[$offset + 5] ?? '',
                'celular' => $values[$offset + 6] ?? '',
                'email' => $values[$offset + 7] ?? '',
                'estado' => $values[$offset + 8] ?? '',
                'usuario asignado' => $values[$offset + 9] ?? '',
            ];
        } else {
            $row = [
                'id' => dashboard_core_pick_first_recursive($item, ['id']),
                'id_solicitud_core' => dashboard_core_pick_first_recursive($item, ['id']),
                'solicitante' => dashboard_core_pick_first_recursive($item, ['solicitante']),
                'fecha de creacion' => dashboard_core_pick_first_recursive($item, ['fec_creacion', 'fecha_creacion']),
                'tipo de solicitud' => dashboard_core_pick_first_recursive($item, ['tipo_sol', 'tipo_solicitud']),
                'establecimiento' => dashboard_core_pick_first_recursive($item, ['estab', 'establecimiento']),
                'departamento' => dashboard_core_pick_first_recursive($item, ['departamento']),
                'telefono' => dashboard_core_pick_first_recursive($item, ['fono', 'telefono']),
                'celular' => dashboard_core_pick_first_recursive($item, ['celular']),
                'email' => dashboard_core_pick_first_recursive($item, ['correo', 'email']),
                'estado' => dashboard_core_pick_first_recursive($item, ['estado']),
                'usuario asignado' => dashboard_core_pick_first_recursive($item, ['usuario_asignado', 'asignado']),
            ];
        }
        $row = array_merge($row, dashboard_core_extract_detail_fields($item));
        if ($row['solicitante'] === '' && $row['tipo de solicitud'] === '' && $row['establecimiento'] === '') {
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

function dashboard_core_parse_datetime(string $value): array {
    $value = trim($value);
    if ($value === '') {
        $now = new DateTimeImmutable();
        return [$now->format('d-m-Y'), $now->format('H:i:s')];
    }
    $formats = ['d-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt instanceof DateTimeImmutable) {
            return [$dt->format('d-m-Y'), $dt->format('H:i:s')];
        }
    }
    $ts = strtotime($value);
    if ($ts !== false) {
        $dt = (new DateTimeImmutable())->setTimestamp($ts);
        return [$dt->format('d-m-Y'), $dt->format('H:i:s')];
    }
    $now = new DateTimeImmutable();
    return [$now->format('d-m-Y'), $now->format('H:i:s')];
}

function dashboard_core_build_message(array $row, array $catalogs, array $users): array {
    [$fecha, $hora] = dashboard_core_parse_datetime((string)($row['fecha de creacion'] ?? ''));
    $solicitante = trim((string)($row['solicitante'] ?? ''));
    $tipoSolicitud = trim((string)($row['tipo de solicitud'] ?? ''));
    $establecimiento = trim((string)($row['establecimiento'] ?? ''));
    $departamento = trim((string)($row['departamento'] ?? ''));
    $sourceDepartamento = $departamento;
    if (($departamento === '' || strtoupper($departamento) === 'N/A') && $establecimiento !== '') {
        $departamento = $establecimiento;
    }
    $telefono = trim((string)($row['telefono'] ?? ''));
    $celular = trim((string)($row['celular'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    $estadoCore = trim((string)($row['estado'] ?? ''));
    $usuarioAsignado = trim((string)($row['usuario asignado'] ?? ''));
    $coreSolicitudId = trim((string)($row['id_solicitud_core'] ?? $row['id'] ?? ''));
    $detalleTipoSolicitud = trim((string)($row['detalle_tipo_solicitud'] ?? ''));
    $detalleRun = trim((string)($row['detalle_run'] ?? ''));
    $detalleNombre = trim((string)($row['detalle_nombre'] ?? ''));
    $detalleMotivo = trim((string)($row['detalle_motivo'] ?? ''));
    $detalleEstablecimientos = trim((string)($row['detalle_establecimientos'] ?? ''));
    $detalleOtrosPermisos = trim((string)($row['detalle_otros_permisos'] ?? ''));
    $detalleFechaNacimiento = trim((string)($row['detalle_fecha_nacimiento'] ?? ''));
    $detalleEmail = dashboard_normalize_email($row['detalle_email'] ?? '');
    $detalleDepartamento = trim((string)($row['detalle_departamento'] ?? ''));
    if (($detalleDepartamento === '' || strtoupper($detalleDepartamento) === 'N/A') && $departamento !== '') {
        $detalleDepartamento = $departamento;
    }
    $detalleCargo = trim((string)($row['detalle_cargo'] ?? ''));
    $detalleRol = trim((string)($row['detalle_rol'] ?? ''));
    $detalleEstado = trim((string)($row['detalle_estado'] ?? ''));
    $detalleItems = [];
    foreach ((array)($row['detalle_items'] ?? []) as $detailItem) {
        if (!is_array($detailItem)) {
            continue;
        }
        $detalleItems[] = dashboard_core_normalize_detail_row($detailItem, [
            'core_tipo_solicitud' => $tipoSolicitud,
            'solicitante' => $solicitante,
        ]);
    }
    $numero = dashboard_normalize_phone($celular !== '' ? $celular : $telefono);
    $descripcion = implode("\n", array_filter([
        'Tipo de solicitud: ' . $tipoSolicitud,
        $detalleTipoSolicitud !== '' ? 'Detalle tipo solicitud: ' . $detalleTipoSolicitud : '',
        $detalleRun !== '' ? 'RUN: ' . $detalleRun : '',
        $detalleNombre !== '' ? 'Nombre: ' . $detalleNombre : '',
        $detalleMotivo !== '' ? 'Motivo: ' . $detalleMotivo : '',
        $detalleEstablecimientos !== '' ? 'Establecimientos: ' . $detalleEstablecimientos : '',
        $detalleOtrosPermisos !== '' ? 'Otros permisos: ' . $detalleOtrosPermisos : '',
        'Establecimiento: ' . $establecimiento,
        'Departamento: ' . $departamento,
        $telefono !== '' ? 'Teléfono: ' . $telefono : '',
        $celular !== '' ? 'Celular: ' . $celular : '',
        $email !== '' ? 'Email: ' . $email : '',
        $estadoCore !== '' ? 'Estado CORE: ' . $estadoCore : '',
        $usuarioAsignado !== '' ? 'Usuario asignado CORE: ' . $usuarioAsignado : '',
    ]));
    $categoria = dashboard_core_resolve_category($tipoSolicitud, $catalogs['categorias'] ?? []);
    $unidad = $departamento !== '' ? $departamento : ($establecimiento !== '' ? $establecimiento : 'HBV');
    $unidadSolicitante = dashboard_infer_catalog_match(trim($departamento . ' ' . $establecimiento), $catalogs['unidades'] ?? [], $establecimiento !== '' ? $establecimiento : 'HBV');
    $fallbackSourceKey = sha1(implode('|', [
        $solicitante,
        $fecha,
        $hora,
        $tipoSolicitud,
        $establecimiento,
        $sourceDepartamento,
        $telefono,
        $celular,
        $email,
    ]));
    $sourceKey = $coreSolicitudId !== ''
        ? 'core-id:' . substr($coreSolicitudId, 0, 152)
        : $fallbackSourceKey;
    $assignedUser = null;
    if ($numero !== '' && isset($users['phone'][$numero])) {
        $assignedUser = $users['phone'][$numero];
    }
    $assignedByNameKey = dashboard_normalize_text($usuarioAsignado);
    if ($assignedUser === null && $assignedByNameKey !== '' && isset($users['name'][$assignedByNameKey])) {
        $assignedUser = $users['name'][$assignedByNameKey];
    }
    return [
        'id' => 'core-' . substr($sourceKey, 0, 20),
        'fuente' => 'core',
        'fuente_id' => $sourceKey,
        'id_core' => $coreSolicitudId,
        'core_solicitud_id' => $coreSolicitudId,
        'numero' => $numero,
        'mensaje' => $tipoSolicitud,
        'descripcion' => $descripcion,
        'fecha' => $fecha,
        'hora' => $hora,
        'fecha_inicio' => $fecha,
        'fecha_fin' => $fecha,
        'tipo' => 'Soporte',
        'prioridad' => 'NORMAL',
        'estado' => 'pendiente',
        'hora_extra' => '0',
        'tiempo_estimado' => '',
        'categoria' => $categoria,
        'unidad' => $unidad,
        'unidad_solicitante' => $unidadSolicitante,
        'solicitante' => $solicitante,
        'asunto' => trim($tipoSolicitud . ' / ' . $unidad),
        'asignado_a' => (string)($assignedUser['id'] ?? ''),
        'asignado_nombre' => $usuarioAsignado !== '' ? $usuarioAsignado : trim((string)($assignedUser['nombre'] ?? '')),
        'core_fecha_creacion' => trim((string)($row['fecha de creacion'] ?? '')),
        'core_tipo_solicitud' => $tipoSolicitud,
        'core_establecimiento' => $establecimiento,
        'core_departamento' => $departamento,
        'core_estado' => $estadoCore,
        'core_usuario_asignado' => $usuarioAsignado,
        'core_email' => dashboard_normalize_email($email),
        'core_telefono' => $telefono,
        'core_celular' => $celular,
        'core_detalle_tipo_solicitud' => $detalleTipoSolicitud,
        'core_detalle_run' => $detalleRun,
        'core_detalle_nombre' => $detalleNombre,
        'core_detalle_motivo' => $detalleMotivo,
        'core_detalle_establecimientos' => $detalleEstablecimientos,
        'core_detalle_otros_permisos' => $detalleOtrosPermisos,
        'core_detalle_fecha_nacimiento' => $detalleFechaNacimiento,
        'core_detalle_email' => $detalleEmail,
        'core_detalle_departamento' => $detalleDepartamento,
        'core_detalle_cargo' => $detalleCargo,
        'core_detalle_rol' => $detalleRol,
        'core_detalle_estado' => $detalleEstado,
        'core_detalle_items' => $detalleItems,
    ];
}

function dashboard_core_row_matches_filters(array $message, array $filters = []): bool {
    $desde = trim((string)($filters['desde'] ?? ''));
    $hasta = trim((string)($filters['hasta'] ?? ''));
    $assigned = trim((string)($filters['assigned'] ?? ''));
    $currentUser = is_array($filters['_current_user'] ?? null) ? $filters['_current_user'] : [];
    $fecha = parse_issue_date((string)($message['core_fecha_creacion'] ?? $message['fecha'] ?? ''));
    if ($desde !== '' && $fecha !== null && $fecha < $desde) {
        return false;
    }
    if ($hasta !== '' && $fecha !== null && $fecha > $hasta) {
        return false;
    }
    if (!empty($currentUser) || $assigned !== '') {
        $candidate = trim((string)($message['core_usuario_asignado'] ?? $message['asignado_nombre'] ?? ''));
        if ($candidate === '') {
            return false;
        }
        $matchesCurrentUser = !empty($currentUser) && dashboard_user_matches_assigned($candidate, $currentUser);
        $matchesAssignedName = $assigned !== '' && dashboard_name_tokens_match($assigned, $candidate);
        if (!$matchesCurrentUser && !$matchesAssignedName) {
            return false;
        }
    }
    return true;
}

function dashboard_core_candidate_urls(string $sourceUrl): array {
    $sourceUrl = trim($sourceUrl);
    if ($sourceUrl === '') {
        return [];
    }
    $candidates = [$sourceUrl];
    $patterns = [
        'obtener_solicitudes_asignadas',
        'obtener_solicitudes_historicas',
        'obtener_solicitudes',
    ];
    foreach ($patterns as $from) {
        foreach ($patterns as $to) {
            if ($from === $to || !str_contains($sourceUrl, $from)) {
                continue;
            }
            $candidates[] = str_replace($from, $to, $sourceUrl);
        }
    }
    return array_values(array_unique(array_filter($candidates)));
}

function dashboard_core_filter_payload(array $filters = []): array {
    $payload = [];
    $desde = trim((string)($filters['desde'] ?? ''));
    $hasta = trim((string)($filters['hasta'] ?? ''));

    if ($desde !== '') {
        $payload['desde'] = $desde;
        $payload['fecha_desde'] = $desde;
        $payload['fecha_inicio'] = $desde;
    }

    if ($hasta !== '') {
        $payload['hasta'] = $hasta;
        $payload['fecha_hasta'] = $hasta;
        $payload['fecha_fin'] = $hasta;
    }

    return $payload;
}

function dashboard_core_url_with_filters(string $url, array $filters = []): string {
    $payload = dashboard_core_filter_payload($filters);
    if (empty($payload)) {
        return $url;
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($payload);
}

function dashboard_core_json_items(array $payload): array {
    if (dashboard_array_is_list($payload)) {
        return $payload;
    }

    foreach (['data', 'rows', 'items', 'solicitudes', 'result', 'results', 'records', 'aaData'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return dashboard_core_json_items($payload[$key]);
        }
    }

    return [$payload];
}

function dashboard_core_rows_from_response_body(string $body): array {
    $rows = dashboard_core_extract_json_rows($body);
    if (empty($rows)) {
        $rows = dashboard_core_extract_rows($body);
    }
    return $rows;
}

function dashboard_core_base_admin_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return rtrim(preg_replace('~/obtener_[^/?#]+$~', '', $url) ?? $url, '/');
    }
    $base = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $base .= ':' . $parts['port'];
    }
    $path = (string)($parts['path'] ?? '');
    $path = preg_replace('~/obtener_[^/?#]+$~', '', $path) ?? $path;
    $path = rtrim($path, '/');
    return $base . $path;
}

function dashboard_archived_source_ids(string $baseDir): array {
    $sourceIds = [];
    $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
    $rows = ($repo !== null && $repo->tableReady()) ? $repo->archivedMessages() : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sourceId = trim((string)($row['fuente_id'] ?? ''));
        if ($sourceId !== '') {
            $sourceIds[$sourceId] = true;
        }
    }
    return $sourceIds;
}

function dashboard_core_date_matches_filters(array $row, array $filters = [], string $dateKey = 'fecha de creacion'): bool {
    $desde = trim((string)($filters['desde'] ?? ''));
    $hasta = trim((string)($filters['hasta'] ?? ''));
    $fecha = parse_issue_date((string)($row[$dateKey] ?? ''));
    if ($desde !== '' && $fecha !== null && $fecha < $desde) {
        return false;
    }
    if ($hasta !== '' && $fecha !== null && $fecha > $hasta) {
        return false;
    }
    return true;
}

function dashboard_core_import_trace_sample(array $row, array $filters, string $reason): array {
    $currentUser = is_array($filters['_current_user'] ?? null) ? $filters['_current_user'] : [];
    $candidate = trim((string)($row['usuario asignado'] ?? $row['core_usuario_asignado'] ?? $row['asignado_nombre'] ?? ''));
    return [
        'core_id' => trim((string)($row['id_solicitud_core'] ?? $row['id_core'] ?? $row['core_solicitud_id'] ?? $row['id'] ?? '')),
        'core_assigned' => $candidate,
        'filter_assigned' => trim((string)($filters['assigned'] ?? '')),
        'nova_logged_user' => trim((string)($currentUser['nombre_completo'] ?? trim((string)($currentUser['nombre'] ?? $currentUser['name'] ?? '') . ' ' . (string)($currentUser['apellido'] ?? '')))),
        'nova_core_user' => trim((string)($currentUser['core_user'] ?? '')),
        'nova_user_id' => trim((string)($currentUser['_nova_user_id'] ?? $currentUser['id'] ?? '')),
        'match_result' => dashboard_user_match_priority($candidate, $currentUser),
        'skip_reason' => $reason,
    ];
}

function dashboard_core_log_import_trace(array $counters, ?array $sample): void {
    $summary = 'CORE import trace'
        . ' | rows_raw ' . (int)($counters['rows_raw'] ?? 0)
        . ' | rows_after_date_filter ' . (int)($counters['rows_after_date_filter'] ?? 0)
        . ' | rows_after_user_match ' . (int)($counters['rows_after_user_match'] ?? 0)
        . ' | skipped_user_mismatch ' . (int)($counters['skipped_user_mismatch'] ?? 0)
        . ' | skipped_existing_json ' . (int)($counters['skipped_existing_json'] ?? 0)
        . ' | skipped_existing_db ' . (int)($counters['skipped_existing_db'] ?? 0)
        . ' | skipped_non_pending ' . (int)($counters['skipped_non_pending'] ?? 0)
        . ' | skipped_unchanged ' . (int)($counters['skipped_unchanged'] ?? 0)
        . ' | imported ' . (int)($counters['imported'] ?? 0)
        . ' | updated ' . (int)($counters['updated'] ?? 0);
    if (is_array($sample)) {
        $summary .= ' | sample ' . json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    dashboard_log_action('CORE_IMPORT_TRACE', $summary);
}

function dashboard_core_trace_assigned_summary(array $assignedCounts, int $limit = 5): string {
    if (empty($assignedCounts)) {
        return '';
    }
    arsort($assignedCounts);
    $items = [];
    foreach (array_slice($assignedCounts, 0, $limit, true) as $name => $count) {
        $items[] = $name . ':' . (int)$count;
    }
    return implode(', ', $items);
}

function dashboard_sync_core_source(array &$messages, string $sourceUrl, array $filters = [], bool $force = false, ?string $loginUrl = null, array $credentials = []): array {
    $cfg = load_platform_config();
    if (!$force && !dashboard_should_auto_sync_core($cfg)) {
        return ['skipped' => true, 'imported' => 0, 'updated' => 0, 'error' => '', 'authenticated' => false];
    }
    if (!dashboard_core_is_configured($cfg)) {
        return ['skipped' => true, 'imported' => 0, 'updated' => 0, 'error' => 'Configura URL, usuario y contraseña de CORE para sincronizar.', 'authenticated' => false];
    }
    $credentials = dashboard_core_runtime_credentials($credentials);
    if (!dashboard_core_has_runtime_credentials($credentials)) {
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'Debes ingresar credenciales de CORE para esta consulta.', 'authenticated' => false];
    }
    $cookieJar = tempnam(sys_get_temp_dir(), 'core_sync_');
    if ($cookieJar === false) {
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se pudo crear un archivo temporal para la sesión CORE.', 'authenticated' => false];
    }
    $sourceUrl = trim($sourceUrl);
    $loginUrl = trim((string)($loginUrl ?? ''));
    if ($sourceUrl === '') {
        @unlink($cookieJar);
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'Falta configurar la URL de origen de CORE.', 'authenticated' => false];
    }
    if ($loginUrl === '') {
        $loginUrl = $sourceUrl;
    }
    $loginPage = dashboard_core_curl($loginUrl, [
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
    ]);
    if ($loginPage['error'] !== '') {
        @unlink($cookieJar);
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se pudo abrir CORE: ' . $loginPage['error'], 'authenticated' => false];
    }
    $formBaseUrl = trim((string)($loginPage['effective_url'] ?? '')) !== ''
        ? (string)$loginPage['effective_url']
        : $loginUrl;
    $form = dashboard_core_parse_login_form($loginPage['body'], $formBaseUrl);
    if (!$form['has_login_form']) {
        @unlink($cookieJar);
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se encontró el formulario de acceso de CORE.', 'authenticated' => false];
    }
    $payloadFields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
    $payloadFields['csrf_token'] = $form['csrf_token'];
    $payloadFields['login_string'] = (string)$credentials['user'];
    $payloadFields['login_pass'] = (string)$credentials['pass'];
    if (!array_key_exists('submit', $payloadFields) || trim((string)$payloadFields['submit']) === '') {
        $payloadFields['submit'] = 'Ingresar';
    }
    $payload = http_build_query($payloadFields);
    $login = dashboard_core_curl($form['action'], [
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    if ($login['error'] !== '') {
        @unlink($cookieJar);
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se pudo autenticar en CORE: ' . $login['error'], 'authenticated' => false];
    }
    if (dashboard_core_response_requires_auth($login) || dashboard_core_parse_login_form($login['body'], (string)($login['effective_url'] ?? $form['action']))['has_login_form']) {
        @unlink($cookieJar);
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'CORE rechazó las credenciales ingresadas. Verifica usuario y contraseña.', 'authenticated' => false];
    }
    $coreAuthenticated = true;
    $rows = [];
    $page = ['body' => '', 'error' => '', 'http_code' => 0, 'effective_url' => ''];
    $requestHeaders = [
        'Accept: application/json, text/plain, */*',
        'X-Requested-With: XMLHttpRequest',
    ];
    if ($loginUrl !== '') {
        $requestHeaders[] = 'Referer: ' . $loginUrl;
    }
    foreach (dashboard_core_candidate_urls($sourceUrl) as $candidateUrl) {
        $page = dashboard_core_curl(dashboard_core_url_with_filters($candidateUrl, $filters), [
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_HTTPHEADER => $requestHeaders,
        ]);
        if ($page['error'] !== '') {
            continue;
        }
        if (dashboard_core_response_requires_auth($page)) {
            continue;
        }
        $rows = dashboard_core_rows_from_response_body($page['body']);
        if (!empty($rows)) {
            $sourceUrl = $candidateUrl;
            break;
        }

        $filterPayload = dashboard_core_filter_payload($filters);
        if (empty($filterPayload)) {
            continue;
        }

        $page = dashboard_core_curl($candidateUrl, [
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($filterPayload),
            CURLOPT_HTTPHEADER => array_merge($requestHeaders, ['Content-Type: application/x-www-form-urlencoded']),
        ]);
        if ($page['error'] !== '') {
            continue;
        }
        if (dashboard_core_response_requires_auth($page)) {
            continue;
        }
        $rows = dashboard_core_rows_from_response_body($page['body']);
        if (empty($rows)) {
            $page = dashboard_core_curl($candidateUrl, [
                CURLOPT_COOKIEJAR => $cookieJar,
                CURLOPT_COOKIEFILE => $cookieJar,
                CURLOPT_HTTPHEADER => $requestHeaders,
            ]);
            if ($page['error'] !== '') {
                continue;
            }
            if (dashboard_core_response_requires_auth($page)) {
                continue;
            }
            $rows = dashboard_core_rows_from_response_body($page['body']);
        }
        if (!empty($rows)) {
            $sourceUrl = $candidateUrl;
            break;
        }
    }
    if (!empty($rows)) {
        $rowsForDetail = [];
        foreach ($rows as $detailIndex => $detailRow) {
            if (is_array($detailRow) && dashboard_core_source_row_matches_filters($detailRow, $filters)) {
                $rowsForDetail[$detailIndex] = $detailRow;
            }
        }
        if (!empty($rowsForDetail)) {
            $detailBaseUrl = dashboard_core_base_admin_url((string)($loginUrl !== '' ? $loginUrl : $sourceUrl));
            $detailRows = dashboard_core_enrich_rows_with_detail($rowsForDetail, $detailBaseUrl, $cookieJar, $requestHeaders);
            foreach ($detailRows as $detailIndex => $detailRow) {
                $rows[$detailIndex] = $detailRow;
            }
        }
    }
    @unlink($cookieJar);
    if ($page['error'] !== '') {
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se pudo cargar la tabla de CORE: ' . $page['error'], 'authenticated' => $coreAuthenticated];
    }
    $pageNorm = dashboard_normalize_text($page['body']);
    if (dashboard_core_response_requires_auth($page)) {
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'CORE rechazó las credenciales configuradas.', 'authenticated' => false];
    }
    if (empty($rows)) {
        return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'No se encontró la tabla de solicitudes en CORE.', 'authenticated' => $coreAuthenticated];
    }
    $traceCounters = [
        'rows_raw' => count($rows),
        'rows_after_date_filter' => 0,
        'rows_after_user_match' => 0,
        'skipped_user_mismatch' => 0,
        'skipped_existing_json' => 0,
        'skipped_existing_db' => 0,
        'skipped_non_pending' => 0,
        'skipped_unchanged' => 0,
        'imported' => 0,
        'updated' => 0,
    ];
    $traceSample = null;
    $traceAssignedCounts = [];
    foreach ($rows as $traceRow) {
        if (!is_array($traceRow) || !dashboard_core_date_matches_filters($traceRow, $filters)) {
            continue;
        }
        $traceCounters['rows_after_date_filter']++;
        $candidate = trim((string)($traceRow['usuario asignado'] ?? ''));
        if ($candidate !== '') {
            $traceAssignedCounts[$candidate] = ($traceAssignedCounts[$candidate] ?? 0) + 1;
        }
        $currentUser = is_array($filters['_current_user'] ?? null) ? $filters['_current_user'] : [];
        $assigned = trim((string)($filters['assigned'] ?? ''));
        $userMatches = true;
        if (!empty($currentUser)) {
            $userMatches = $candidate !== '' && (
                dashboard_user_matches_assigned($candidate, $currentUser)
                || ($assigned !== '' && dashboard_name_tokens_match($assigned, $candidate))
            );
        } elseif ($assigned !== '') {
            $userMatches = $candidate !== '' && dashboard_name_tokens_match($assigned, $candidate);
        }
        if ($userMatches) {
            $traceCounters['rows_after_user_match']++;
            continue;
        }
        $traceCounters['skipped_user_mismatch']++;
        if ($traceSample === null) {
            $traceSample = dashboard_core_import_trace_sample($traceRow, $filters, 'user_mismatch');
        }
    }
    $catalogs = [
        'categorias' => dashboard_catalog_names('categorias'),
        'unidades' => dashboard_catalog_names('unidades'),
    ];
    $users = dashboard_load_user_maps();
    $coreSync = app(\App\Modulos\RedmineMantencion\Services\CorePendingReportSyncService::class);
    $existingIndexes = $coreSync->indexes($messages);
    // DB-based duplicate guard: query redmine_mantencion_reportes for existing fuente_ids.
    // This is the authoritative source for "does this record exist?".
    // Archive rows in redmine_mantencion_reportes are historical only.
    // and must not block re-import of physically-deleted records.
    $dbImportRepo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
    $dbFuenteIds = ($dbImportRepo !== null) ? $dbImportRepo->getExistingFuenteIds('core') : [];
    $dbCoreIds = ($dbImportRepo !== null) ? $dbImportRepo->getExistingCoreIds() : [];
    $imported = 0;
    $updated = 0;
    foreach ($rows as $row) {
        $message = dashboard_core_build_message($row, $catalogs, $users);
        if (!dashboard_core_row_matches_filters($message, $filters)) {
            if ($traceSample === null) {
                $traceSample = dashboard_core_import_trace_sample($message, $filters, 'message_filter_mismatch');
            }
            continue;
        }
        $message = dashboard_apply_import_assignment($message, $filters);
        $sourceId = $message['fuente_id'];
        if ($sourceId === '') {
            continue;
        }
        // Match the stable CORE request ID first and the old fingerprint as
        // fallback. Only pending reports may receive changes from CORE.
        $existingIndex = $coreSync->matchIndex($existingIndexes, $message);
        if ($existingIndex !== null) {
            $merge = $coreSync->mergePending($messages[$existingIndex], $message);
            if (! $merge['eligible']) {
                $traceCounters['skipped_non_pending']++;
                continue;
            }
            if (! $merge['changed']) {
                $traceCounters['skipped_unchanged']++;
                continue;
            }

            $messages[$existingIndex] = $merge['message'];
            $updated++;
            $traceCounters['updated']++;
            continue;
        }
        // Record exists in DB but was archived out of the active dashboard view
        // (e.g. by retention). Skip without duplicating; it is not physically deleted.
        if (isset($dbFuenteIds[$sourceId])) {
            $traceCounters['skipped_existing_db']++;
            if ($traceSample === null) {
                $traceSample = dashboard_core_import_trace_sample($message, $filters, 'existing_db');
            }
            continue;
        }
        $coreId = $coreSync->coreId($message);
        if ($coreId !== '' && isset($dbCoreIds[$coreId])) {
            $traceCounters['skipped_existing_db']++;
            if ($traceSample === null) {
                $traceSample = dashboard_core_import_trace_sample($message, $filters, 'existing_core_id_db');
            }
            continue;
        }
        // Not in active view and not in DB — import as new.
        // Archive blobs are intentionally not checked here: if the record was deleted
        // from redmine_mantencion_reportes it must be importable again.
        $messages[] = $message;
        $existingIndexes = $coreSync->indexes($messages);
        $imported++;
        $traceCounters['imported']++;
    }
    $cfg['core_last_sync'] = (new DateTimeImmutable())->format(DateTime::ATOM);
    $cfg['core_last_error'] = '';
    save_platform_config($cfg);
    if ($imported > 0 || $updated > 0) {
        save_messages($messages);
    }
    dashboard_core_log_import_trace($traceCounters, $traceSample);
    return [
        'skipped' => false,
        'imported' => $imported,
        'updated' => $updated,
        'error' => '',
        'authenticated' => $coreAuthenticated,
        'trace' => $traceCounters,
        'trace_sample' => $traceSample,
        'trace_assigned_summary' => dashboard_core_trace_assigned_summary($traceAssignedCounts),
    ];
}

function dashboard_sync_core(array &$messages, bool $force = false, array $credentials = []): array {
    $cfg = load_platform_config();
    $adminUrl = (string)($cfg['core_admin_url'] ?? '');
    return dashboard_sync_core_source($messages, $adminUrl, [], $force, $adminUrl, $credentials);
}

function dashboard_sync_core_history(array &$messages, array $filters = [], bool $force = true, array $credentials = []): array {
    $cfg = load_platform_config();
    $adminUrl = (string)($cfg['core_admin_url'] ?? '');
    $sourceUrl = (string)($cfg['core_historico_url'] ?? 'https://www.hbvaldivia.cl/core/solicitudes/administrador/obtener_solicitudes_historicas');
    if (str_contains($sourceUrl, 'obtener_solicitudes_asignadas')) {
        $sourceUrl = str_replace('obtener_solicitudes_asignadas', 'obtener_solicitudes_historicas', $sourceUrl);
    } elseif (str_ends_with(rtrim($sourceUrl, '/'), '/obtener_solicitudes')) {
        $sourceUrl = str_replace('/obtener_solicitudes', '/obtener_solicitudes_historicas', $sourceUrl);
    }
    return dashboard_sync_core_source($messages, $sourceUrl, $filters, $force, $adminUrl, $credentials);
}

function get_retencion_horas(int $default = 24): int {
    $cfg = load_platform_config();
    $value = isset($cfg['retencion_horas']) ? (int)$cfg['retencion_horas'] : $default;
    return max(1, $value);
}

function dashboard_hora_extra_default_time(string $default = '1'): string {
    $cfg = load_platform_config();
    $value = trim((string)($cfg['hora_extra_tiempo_estimado'] ?? $default));
    return $value !== '' ? $value : $default;
}

function redmine_log_path(): string {
    return __DIR__ . '/../data/envio_errores.log';
}

function dashboard_normalize_stored_date($value): string {
    $parsed = parse_issue_date((string)$value);
    if ($parsed === null) {
        return trim((string)$value);
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $parsed);
    return $dt instanceof DateTimeImmutable ? $dt->format('d-m-Y') : trim((string)$value);
}

function build_redmine_issue_payload(array $message, array $cfg, array $catMap, array $unitMap): array {
    $isManual = ($message['fuente'] ?? '') === 'manual' || str_starts_with((string)($message['id'] ?? ''), 'manual-');
    if ($isManual) {
        $description = dashboard_build_redmine_manual_description($message);
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
    $description = dashboard_build_redmine_core_description($message);
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

function normalize_hour_extra_value($value): string {
    $val = strtolower(trim((string)$value));
    $truthy = ['1','si','sí','s','true','yes'];
    return in_array($val, $truthy, true) ? '1' : '0';
}

function message_has_hora_extra(array $message): bool {
    return normalize_hour_extra_value($message['hora_extra'] ?? '') === '1';
}

function message_is_procesado(array $message): bool {
    return strtolower(trim((string) ($message['estado'] ?? ''))) === 'procesado';
}

function append_hours_extra_record(array $message): void {
    if (!message_has_hora_extra($message) || strtolower(trim((string) ($message['estado'] ?? ''))) !== 'archivado') {
        return;
    }
    $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;
    if ($repo !== null) {
        $repo->syncMessage($message);
    }
}

function remove_hours_extra_record_by_id(string $messageId): void {
    $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;
    if ($repo !== null) {
        $repo->detachMessageId($messageId);
    }
}

function append_redmine_log(array $entry): void {
    try {
        \Illuminate\Support\Facades\DB::table('mantencion_log')->insert([
            'canal' => 'redmine', 'tipo' => (string)($entry['event'] ?? $entry['status'] ?? 'envio'),
            'mensaje_id' => trim((string)($entry['message_id'] ?? '')) ?: null,
            'detalle' => (string)($entry['error'] ?? $entry['message'] ?? ''),
            'contexto' => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'registrado_at' => now(),
        ]);
    } catch (Throwable) {}
}

function parse_redmine_log_entries(): array {
    $entries = [];
    try { foreach (\Illuminate\Support\Facades\DB::table('mantencion_log')->where('canal','redmine')->orderBy('id')->get() as $row) {
        $decoded = json_decode((string)($row->contexto ?? '{}'), true); if (!is_array($decoded)) $decoded = [];
        $entries[] = ['message_id'=>(string)($row->mensaje_id ?? ''),'raw'=>json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),'decoded'=>$decoded];
    }} catch (Throwable) {}
    return $entries;
}

function load_redmine_logs_by_message(): array {
    $grouped = [];
    foreach (parse_redmine_log_entries() as $entry) {
        $mid = trim((string)($entry['message_id'] ?? ''));
        if ($mid === '') {
            continue;
        }
        $decoded = is_array($entry['decoded'] ?? null) ? $entry['decoded'] : [];
        $grouped[$mid] = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    return $grouped;
}

function remove_redmine_logs_for_messages(array $ids): void {
    $ids = array_values(array_filter(array_map('trim', $ids)));
    if (empty($ids)) return;
    try { \Illuminate\Support\Facades\DB::table('mantencion_log')->where('canal','redmine')->whereIn('mensaje_id',$ids)->delete(); } catch (Throwable) {}
}

function load_user_api_token(?string $userId): string {
    if (!$userId) {
        return '';
    }
    if (function_exists('auth_central_redmine_api_token')) {
        $central = auth_central_redmine_api_token($userId);
        if ($central !== '') {
            return $central;
        }
    }
    return '';
}

function redmine_api_issues_url(string $url): string {
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

function send_redmine_issue(array $issue, array $cfg, string $userToken = ''): array {
    $url = redmine_api_issues_url((string)($cfg['platform_url'] ?? ''));
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

function send_selected_messages(array &$messages, array $ids, array $cfg, string $userToken): array {
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
        if (dashboard_core_is_in_review($message)) {
            $message['estado'] = 'pendiente';
            $blocked++;
            $blockedIds[] = (string)($message['id'] ?? 'sin-id');
            continue;
        }
        $attempts++;
        $issue = build_redmine_issue_payload($message, $cfg, $catMap, $unitMap);
        $result = send_redmine_issue($issue, $cfg, $userToken);
        $entry = [
            'ts' => (new DateTimeImmutable())->format(DateTime::ATOM),
            'http_code' => $result['http_code'],
            'error' => $result['error'] ?? '',
            'body' => $result['body'],
            'payload' => ['issue' => $issue],
            'message_id' => $message['id'] ?? '',
        ];
        append_redmine_log($entry);
        if ($result['http_code'] === 201) {
            $success++;
            $decoded = json_decode($result['body'] ?? '', true);
            $message['estado'] = 'procesado';
            $message['redmine_id'] = $decoded['issue']['id'] ?? $message['redmine_id'] ?? '';
            $message['procesado_ts'] = (new DateTimeImmutable())->format(DateTime::ATOM);
            if ($message['redmine_id']) {
                $created[] = (string)$message['redmine_id'];
            }
            log_security_event(
                'REDMINE_SEND',
                sprintf(
                    'Ticket enviado a Redmine. Mensaje=%s Ticket=%s Usuario=%s',
                    (string)($message['id'] ?? 'sin-id'),
                    (string)($message['redmine_id'] ?? 'sin-id'),
                    (string)($_SESSION['user']['nombre'] ?? 'usuario')
                )
            );
        } else {
            $message['estado'] = 'error';
            $message['procesado_ts'] = (new DateTimeImmutable())->format(DateTime::ATOM);
            $errors[] = sprintf('No se pudo enviar %s: %s', $message['id'] ?? 'sin-id', $result['error'] ?: $result['body']);
            log_security_event(
                'REDMINE_SEND_FAIL',
                sprintf(
                    'Fallo envio a Redmine. Mensaje=%s HTTP=%s Error=%s Usuario=%s',
                    (string)($message['id'] ?? 'sin-id'),
                    (string)($result['http_code'] ?? ''),
                    substr((string)($result['error'] ?: $result['body']), 0, 180),
                    (string)($_SESSION['user']['nombre'] ?? 'usuario')
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

function parse_message_timestamp(array $message): ?DateTimeImmutable {
    $processed = trim((string)($message['procesado_ts'] ?? ''));
    if ($processed !== '') {
        try {
            return new DateTimeImmutable($processed);
        } catch (Exception $e) {
            // fallback to other fields
        }
    }
    $dateParts = trim((string)($message['fecha'] ?? $message['fecha_inicio'] ?? ''));
    $timeParts = trim((string)($message['hora'] ?? $message['hora_inicio'] ?? ''));
    if ($dateParts === '') {
        return null;
    }
    $candidate = trim("$dateParts $timeParts");
    $formats = [
        'd-m-Y H:i:s', 'd-m-Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'Y/m/d H:i:s', 'Y/m/d H:i',
        'Y-m-d', 'd-m-Y', 'Y/m/d', 'd/m/Y'
    ];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $candidate);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }
    $timestamp = strtotime($candidate);
    if ($timestamp !== false && $timestamp > 0) {
        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }
    return null;
}

function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

function archive_message_record(array $message, string $archivedBy = 'retencion'): void {
    $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
    if ($repo !== null && $repo->tableReady()) {
        $message['estado'] = 'archivado';
        $repo->markArchived($message);
        append_hours_extra_record($message);
    }
}

function apply_retention_archive(array &$messages): bool {
    $threshold = (new DateTimeImmutable())->modify('-' . get_retencion_horas() . ' hours');
    $removed = [];
    foreach ($messages as $key => $message) {
        $estado = strtolower($message['estado'] ?? '');
        if ($estado !== 'procesado') {
            continue;
        }
        $ts = parse_message_timestamp($message);
        if ($ts === null || $ts > $threshold) {
            continue;
        }
        $removed[] = $message;
        unset($messages[$key]);
    }
    if (empty($removed)) {
        return false;
    }
    $messages = array_values($messages);
    foreach ($removed as $item) {
        archive_message_record($item);
    }
    return true;
}

function archive_selected_messages(array &$messages, array $ids): int {
    $ids = array_filter(array_map('trim', $ids));
    if (empty($ids)) {
        return 0;
    }
    $archived = 0;
    foreach ($messages as $key => $message) {
        if (!in_array(($message['id'] ?? ''), $ids, true)) {
            continue;
        }
        if (strtolower(trim((string)($message['estado'] ?? ''))) !== 'procesado') {
            continue;
        }
        archive_message_record($message, 'manual');
        unset($messages[$key]);
        $archived++;
    }
    if ($archived > 0) {
        $messages = array_values($messages);
        save_messages($messages);
    }
    return $archived;
}

function dashboard_messages_scope(): string {
    return 'asignados';
}

function dashboard_filter_messages_by_scope(array $messages): array {
    $userId = (string)auth_get_user_id();
    if ($userId === '') {
        return [];
    }
    $currentUser = dashboard_current_user();
    return array_values(array_filter($messages, function ($row) use ($userId, $currentUser) {
        if (!is_array($row)) {
            return false;
        }
        if ((string)($row['asignado_a'] ?? '') === $userId) {
            return true;
        }
        $assignedName = trim((string)($row['core_usuario_asignado'] ?? ($row['asignado_nombre'] ?? '')));
        if ($assignedName === '') {
            return false;
        }
        if (!empty($currentUser)) {
            return dashboard_user_matches_assigned($assignedName, $currentUser);
        }
        // Fallback when user record is not available
        auth_start_session();
        $nombre = trim((string)($_SESSION['user']['nombre'] ?? ''));
        $apellido = trim((string)($_SESSION['user']['apellido'] ?? ''));
        $fullName = trim($nombre . ($apellido !== '' ? ' ' . $apellido : ''));
        return $fullName !== '' && dashboard_name_tokens_match($fullName, $assignedName);
    }));
}

function dashboard_accessible_message_ids(array $messages): array {
    $ids = [];
    foreach (dashboard_filter_messages_by_scope($messages) as $message) {
        $id = trim((string)($message['id'] ?? ''));
        if ($id !== '') {
            $ids[$id] = true;
        }
    }
    return $ids;
}

function dashboard_can_access_message(array $messages, string $id): bool {
    $id = trim($id);
    if ($id === '') {
        return false;
    }
    $ids = dashboard_accessible_message_ids($messages);
    return isset($ids[$id]);
}

function dashboard_filter_ids_by_scope(array $messages, array $ids): array {
    $allowed = dashboard_accessible_message_ids($messages);
    return array_values(array_filter(array_map('trim', $ids), static fn(string $id): bool => $id !== '' && isset($allowed[$id])));
}

function dashboard_default_core_assigned_name(): string {
    return dashboard_current_user_full_name();
}

function dashboard_user_match_priority(string $candidate, array $user): string {
    $candidate = trim($candidate);
    if ($candidate === '' || empty($user)) {
        return 'none';
    }

    // Priority 1: CORE username
    $normalizedCandidate = dashboard_normalize_text($candidate);
    $coreUser = trim((string)($user['core_user'] ?? ''));
    if ($coreUser !== '' && $normalizedCandidate === dashboard_normalize_text($coreUser)) {
        return 'P1';
    }

    // Priority 2: RUT (digits + optional K, both with and without DV)
    $candidateDigits = strtolower(preg_replace('/[^0-9kK]/i', '', $candidate));
    if ($candidateDigits !== '') {
        $userRut = strtolower(preg_replace('/[^0-9kK]/i', '', (string)($user['rut'] ?? '')));
        if ($userRut !== '' && $userRut === $candidateDigits) {
            return 'P2';
        }
        $candidateNoK = preg_replace('/[^0-9]/', '', $candidateDigits);
        $userRutSinDv = preg_replace('/[^0-9]/', '', (string)($user['rut_sin_dv'] ?? ''));
        if ($userRutSinDv !== '' && $candidateNoK !== '' && $userRutSinDv === $candidateNoK) {
            return 'P2';
        }
    }

    // Priority 3: Username / ID
    $userId = trim((string)($user['id'] ?? ''));
    if ($userId !== '' && $normalizedCandidate === dashboard_normalize_text($userId)) {
        return 'P3';
    }

    // Priority 4: Full name — all NOVA tokens must appear in the CORE candidate.
    // Requires ≥ 2 NOVA tokens to avoid single-name false positives.
    // Allows CORE to include extra middle names (e.g. "Jean Carlos Cortes Lorca" matches NOVA "Jean Cortés Lorca").
    $nombre = trim((string)($user['nombre'] ?? $user['name'] ?? $user['nombre_completo'] ?? ''));
    $apellido = trim((string)($user['apellido'] ?? ''));
    $fullName = trim((string)($user['nombre_completo'] ?? ''));
    if ($fullName === '') {
        $fullName = trim($nombre . ($apellido !== '' ? ' ' . $apellido : ''));
    }
    if ($fullName !== '') {
        $normFull = dashboard_normalize_text($fullName);
        if ($normFull !== '' && $normalizedCandidate !== '') {
            $fullTokens = array_values(array_filter(explode(' ', $normFull)));
            $candidateTokens = array_values(array_filter(explode(' ', $normalizedCandidate)));
            if (count($fullTokens) >= 2 && !empty($candidateTokens)
                && empty(array_diff($fullTokens, $candidateTokens))) {
                return 'P4';
            }
        }
    }

    return 'none';
}

function dashboard_user_matches_assigned(string $candidate, array $user): bool {
    return dashboard_user_match_priority($candidate, $user) !== 'none';
}

function handle_request(): array {
    $messages = load_messages();
    $userId = auth_get_user_id();
    $userToken = load_user_api_token($userId);
    if (!maintenance_mode_enabled() && apply_retention_archive($messages)) {
        save_messages($messages);
    }
    $flash = dashboard_consume_flash();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_validate();

        // Release the legacy session file lock here, once, right after the last thing
        // in this request that genuinely needs it (login/timeout checks in
        // auth_require_login(), flash-consume, CSRF token validation above — all done
        // by this point). Everything from here on (the action switch below, including
        // toggle_hora_extra) persists through the DB-backed *_repository() layer, not
        // $_SESSION, so it doesn't need the lock. Without this, concurrent AJAX row
        // actions from the same user serialize on this lock instead of running in
        // parallel — see the session_write_close() call added for this same reason in
        // LegacyProjectController::syncNovaUserToLegacySession(). Any code path below
        // that still needs to WRITE session data (dashboard_set_flash(),
        // the non-AJAX redirect+flash flow, or the maintenance-mode block) already
        // reopens the session itself via auth_start_session() before writing, so this
        // is safe for both the AJAX and non-AJAX flows.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $action = $_POST['action'] ?? '';
        if (function_exists('maintenance_mode_block_if_enabled')) {
            maintenance_mode_block_if_enabled();
        }
        $flashMsg = null;
        $ajaxAction = dashboard_is_ajax_request();
        $ajaxPayload = [
            'ok' => true,
            'action' => $action,
            'message' => '',
            'ids' => [],
        ];
        $requiredPermission = dashboard_required_permission_for_action((string)$action);
        if ($requiredPermission !== null && !auth_can($requiredPermission)) {
            $permissionLabels = [
                'reportes_editar' => 'editar reportes',
                'reportes_eliminar' => 'eliminar reportes',
                'reportes_importar_core' => 'importar reportes desde CORE',
                'horas_extra_editar' => 'editar Horas extra',
            ];
            $flashMsg = 'No tienes permiso para ' . ($permissionLabels[$requiredPermission] ?? 'ejecutar esta acción') . '.';
            $ajaxPayload['ok'] = false;
            if ($ajaxAction) {
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
                                $value = dashboard_normalize_stored_date($value);
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
                        $message['tiempo_estimado'] = dashboard_hora_extra_default_time('1');
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
                        'tiempo_estimado' => $isEnabled ? dashboard_hora_extra_default_time('1') : '',
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
                $canSelectCoreAssignee = dashboard_can_select_core_assignee();
                $assigned = dashboard_enforced_assigned_name((string)($_POST['core_assigned_name'] ?? ''));
                if (!$canSelectCoreAssignee && $assigned === '') {
                    $flashMsg = 'No se pudo identificar al usuario conectado para filtrar las solicitudes de CORE.';
                    $ajaxPayload['ok'] = false;
                    break;
                }
                $coreUser = trim((string)($_POST['core_runtime_user'] ?? ''));
                $corePass = trim((string)($_POST['core_runtime_pass'] ?? ''));
                $rememberCore = !empty($_POST['core_remember_credentials']);
                if ($coreUser === '' || $corePass === '') {
                    $savedCoreCredentials = dashboard_core_credentials_for_current_user();
                    if ($coreUser === '') {
                        $coreUser = trim((string)($savedCoreCredentials['user'] ?? ''));
                    }
                    if ($corePass === '') {
                        $corePass = trim((string)($savedCoreCredentials['pass'] ?? ''));
                    }
                }
                $currentUserData = dashboard_current_user();
                $coreCredentialUserKey = dashboard_core_current_credential_user_key($currentUserData);
                $result = dashboard_sync_core_history($messages, [
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
                        auth_start_session();
                        $_SESSION['dashboard_open_core_credentials_modal'] = true;
                        $_SESSION['dashboard_core_runtime_user'] = $coreUser;
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
                $ids = dashboard_filter_ids_by_scope($messages, $ids);
                $archived = archive_selected_messages($messages, $ids);
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
                $ids = dashboard_filter_ids_by_scope($messages, $ids);
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
                $ids = dashboard_filter_ids_by_scope($messages, $ids);
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
                    remove_redmine_logs_for_messages($ids);
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
            $ids = dashboard_filter_ids_by_scope($messages, $ids);
            $result = send_selected_messages($messages, $ids, load_platform_config(), $userToken);
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
                'Envio datos a Redmine | seleccionados ' . dashboard_security_ids_count($ids)
                . ' | intentos ' . (int)($result['attempts'] ?? 0)
                . ' | exitos ' . (int)($result['success'] ?? 0)
                . ' | fallas ' . max(0, (int)($result['attempts'] ?? 0) - (int)($result['success'] ?? 0))
            );
        }
        if ($ajaxAction && $action !== 'process_selected' && $action !== 'import_core_history') {
            $scopedMessages = dashboard_filter_messages_by_scope($messages);
            $ajaxPayload['message'] = $flashMsg ?? '';
            $ajaxPayload['counts'] = dashboard_status_counts($scopedMessages);
            dashboard_json_response($ajaxPayload, !empty($ajaxPayload['ok']) ? 200 : 400);
        }
        dashboard_set_flash($flashMsg ?? '');
        dashboard_redirect_back();
    }
    $rawLog = security_load_events();
    $securityLog = array_filter($rawLog, fn($entry) => (($entry['tag'] ?? '') !== 'CSRF_ALERT'));
    if (empty($securityLog)) {
        $securityLog = array_filter($rawLog, fn($entry) => in_array(($entry['tag'] ?? ''), ['LOGIN_SUCCESS', 'LOG', 'AUTH_SUCCESS']));
    }
    $messages = dashboard_filter_messages_by_scope($messages);
    return [$messages, $flash, $securityLog];
}
