<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/core_credentials.php';
require_once __DIR__ . '/maintenance.php';

function dashboard_set_flash(string $message): void {
    session()->put('mantencion_flash', $message);
}

function dashboard_json_response(array $payload, int $statusCode = 200): void {
    // The legacy module runs inside Laravel's output buffer. A PHP warning or
    // incidental whitespace emitted by an included legacy file would otherwise
    // be prepended to this payload and make response.json()/JSON.parse() fail.
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        $json = '{"ok":false,"message":"No se pudo codificar la respuesta del servidor."}';
    }
    echo $json;
    exit;
}

function dashboard_security_actor(): string {
    $user = mantencion_current_user();
    $name = trim((string)($user['nombre'] ?? ''));
    $id = trim((string)($user['id'] ?? ''));
    if ($name === '' && $id === '') {
        return 'usuario desconocido';
    }
    return trim($name . ($id !== '' ? ' (ID ' . $id . ')' : ''));
}

function dashboard_log_action(string $tag, string $details): void {
    if (!function_exists('log_security_event')) {
        return;
    }
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $suffix = $ip !== '' ? ' | IP ' . $ip : '';
    log_security_event($tag, dashboard_security_actor() . ' | ' . $details . $suffix);
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
        'tiempo_estimado' => normalize_hour_extra_value($message['hora_extra'] ?? '') === '1' ? 1 : null,
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
    $sessionUser = mantencion_current_user() ?? [];
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

function normalize_hour_extra_value($value): string {
    $val = strtolower(trim((string)$value));
    $truthy = ['1','si','sí','s','true','yes'];
    return in_array($val, $truthy, true) ? '1' : '0';
}

function message_has_hora_extra(array $message): bool {
    return normalize_hour_extra_value($message['hora_extra'] ?? '') === '1';
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
        $currentSessionUser = mantencion_current_user() ?? [];
        $nombre = trim((string)($currentSessionUser['nombre'] ?? ''));
        $apellido = trim((string)($currentSessionUser['apellido'] ?? ''));
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
