<?php

namespace App\Modulos\RedmineMantencion\Services;

class MantencionCoreImportService
{
    public function dashboard_apply_import_assignment(array $message, array $filters): array {
        $targetUser = null;
        if (!$this->dashboard_can_select_core_assignee()) {
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

    public function dashboard_archived_source_ids(string $baseDir): array {
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

    public function dashboard_can_select_core_assignee(?array $novaUser = null): bool {
        if ($novaUser === null) {
            $novaUser = [];
            if (function_exists('session')) {
                try {
                    $candidate = session('nova_user');
                    if (is_array($candidate)) {
                        $novaUser = $candidate;
                    }
                } catch (\Throwable) {
                }
            }
            if ($novaUser === [] && function_exists('request')) {
                try {
                    $candidate = request()->session()->get('nova_user');
                    if (is_array($candidate)) {
                        $novaUser = $candidate;
                    }
                } catch (\Throwable) {
                }
            }
        }

        return strtolower(trim((string)($novaUser['role'] ?? 'usuario'))) === 'root';
    }

    public function dashboard_core_base_admin_url(string $url): string {
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

    public function dashboard_core_build_message(array $row, array $catalogs, array $users): array {
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

    public function dashboard_core_candidate_urls(string $sourceUrl): array {
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

    public function dashboard_core_collect_recursive_strings(mixed $value): array {
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
            foreach ($this->dashboard_core_collect_recursive_strings($child) as $item) {
                $strings[] = $item;
            }
        }
        return $strings;
    }

    public function dashboard_core_compact_keys(): array {
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

    public function dashboard_core_credentials_for_current_user(): array {
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

        return core_credentials_for_user($this->dashboard_core_current_credential_user_key());
    }

    public function dashboard_core_save_credentials_for_current_user(string $coreUser, string $corePass): bool {
        $coreUser = trim($coreUser);
        $corePass = trim($corePass);
        if ($coreUser === '' || $corePass === '') {
            return false;
        }

        if (function_exists('session') && function_exists('app')) {
            try {
                $novaUser = session('nova_user');
                if (is_array($novaUser) && !empty($novaUser)) {
                    return app(\App\Modulos\Nova\Repositories\UserIntegrationRepository::class)
                        ->saveCredentialForSession($novaUser, 'core', $coreUser, $corePass);
                }
            } catch (\Throwable) {
                // Compatibilidad para ejecuciones legacy sin sesión Laravel.
            }
        }

        return core_credentials_save_for_user(
            $this->dashboard_core_current_credential_user_key(),
            $coreUser,
            $corePass
        );
    }

    public function dashboard_core_clear_credentials_for_current_user(): bool {
        if (function_exists('session') && function_exists('app')) {
            try {
                $novaUser = session('nova_user');
                if (is_array($novaUser) && !empty($novaUser)) {
                    return app(\App\Modulos\Nova\Repositories\UserIntegrationRepository::class)
                        ->deleteCredentialForSession($novaUser, 'core');
                }
            } catch (\Throwable) {
                // Compatibilidad para ejecuciones legacy sin sesión Laravel.
            }
        }

        return core_credentials_clear_for_user($this->dashboard_core_current_credential_user_key());
    }

    public function dashboard_core_curl(string $url, array $options = []): array {
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

    public function dashboard_core_current_credential_user_key(array $currentUser = []): string {
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

    public function dashboard_core_date_matches_filters(array $row, array $filters = [], string $dateKey = 'fecha de creacion'): bool {
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

    public function dashboard_core_detail_defaults(): array {
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

    public function dashboard_core_detail_slug(string $tipoSolicitud): string {
        $tipo = dashboard_normalize_text($tipoSolicitud);
        if ($tipo === '') {
            return '';
        }
        $tokens = array_values(array_filter(explode(' ', $tipo), fn($token) => $token !== 'de'));
        return implode('_', $tokens);
    }

    public function dashboard_core_detail_url_candidates(string $baseUrl, array $row, ?string $solicitudIdOverride = null): array {
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
        $slugWithoutDe = $this->dashboard_core_detail_slug($tipoSolicitud);
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

    public function dashboard_core_enrich_rows_with_detail(array $rows, string $baseUrl, string $cookieJar, array $requestHeaders): array {
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
                $detailUrls = $this->dashboard_core_detail_url_candidates($baseUrl, $row, $currentId);
                foreach ($detailUrls as $detailUrl) {
                    $requests++;
                    $detailResponse = $this->dashboard_core_curl($detailUrl, [
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
                    $detailFields = $this->dashboard_core_extract_detail_from_body($detailBody);
                    $row = $this->dashboard_core_merge_detail_fields($row, $detailFields);
                    foreach ($this->dashboard_core_extract_related_request_ids_from_body($detailBody) as $relatedId) {
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

    public function dashboard_core_extract_candidate_request_ids(string $html): array {
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

    public function dashboard_core_extract_detail_fields(array $item): array {
        $details = [
            'detalle_tipo_solicitud' => $this->dashboard_core_pick_first_recursive($item, ['detalle_tipo_solicitud', 'tipo_solicitud_detalle', 'detalle_tipo', 'detalle_tipo_sol']),
            'detalle_run' => $this->dashboard_core_pick_first_recursive($item, ['run', 'rut', 'detalle_run', 'detalle_rut', 'run_usuario']),
            'detalle_nombre' => $this->dashboard_core_pick_first_recursive($item, ['nombre', 'detalle_nombre', 'nombre_usuario', 'usuario_nombre', 'nombre_completo']),
            'detalle_motivo' => $this->dashboard_core_pick_first_recursive($item, ['motivo', 'detalle_motivo', 'motivo_solicitud']),
            'detalle_establecimientos' => $this->dashboard_core_pick_first_recursive($item, ['establecimientos', 'detalle_establecimientos', 'detalle_estab']),
            'detalle_otros_permisos' => $this->dashboard_core_pick_first_recursive($item, ['otros_permisos', 'detalle_otros_permisos', 'permisos_adicionales']),
            'detalle_fecha_nacimiento' => $this->dashboard_core_pick_first_recursive($item, ['fecha_nacimiento', 'fec_nacimiento', 'fecha_nac', 'detalle_fecha_nacimiento']),
            'detalle_email' => dashboard_normalize_email($this->dashboard_core_pick_first_recursive($item, ['email', 'correo', 'detalle_email'])),
            'detalle_departamento' => $this->dashboard_core_pick_first_recursive($item, ['departamento', 'depto', 'detalle_departamento']),
            'detalle_cargo' => $this->dashboard_core_pick_first_recursive($item, ['cargo', 'detalle_cargo', 'id_cargo']),
            'detalle_rol' => $this->dashboard_core_pick_first_recursive($item, ['rol', 'detalle_rol']),
            'detalle_estado' => $this->dashboard_core_pick_first_recursive($item, ['estado', 'detalle_estado']),
        ];
        if ($details['detalle_nombre'] === '') {
            $nombrePartes = array_filter([
                $this->dashboard_core_pick_first_recursive($item, ['nombres_ins']),
                $this->dashboard_core_pick_first_recursive($item, ['apepat_ins']),
                $this->dashboard_core_pick_first_recursive($item, ['apemat_ins']),
            ], fn($value) => trim((string)$value) !== '');
            if (!empty($nombrePartes)) {
                $details['detalle_nombre'] = implode(' ', $nombrePartes);
            }
        }
        $blob = $this->dashboard_core_pick_first_recursive($item, ['detalle', 'detalle_solicitud', 'descripcion', 'observacion', 'observaciones']);
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

    public function dashboard_core_extract_detail_from_body(string $body): array {
        $details = $this->dashboard_core_detail_defaults();
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
                $details = $this->dashboard_core_merge_detail_fields($details, $normalizedItems[0]);
            }
            $details = $this->dashboard_core_merge_detail_fields($details, $this->dashboard_core_extract_detail_fields($json));
            foreach ($this->dashboard_core_collect_recursive_strings($json) as $candidateHtml) {
                $detailItems = $this->dashboard_core_extract_detail_table_rows($candidateHtml);
                if (!empty($detailItems)) {
                    $details['detalle_items'] = $detailItems;
                    $details = $this->dashboard_core_merge_detail_fields($details, $detailItems[0]);
                    break;
                }
            }
        }
        if (empty($details['detalle_items'])) {
            $detailItems = $this->dashboard_core_extract_detail_table_rows($body);
        } else {
            $detailItems = [];
        }
        if (!empty($detailItems)) {
            $details['detalle_items'] = $detailItems;
            $details = $this->dashboard_core_merge_detail_fields($details, $detailItems[0]);
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

    public function dashboard_core_extract_detail_table_rows(string $html): array {
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

    public function dashboard_core_extract_json_rows(string $body): array {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return [];
        }
        $items = $this->dashboard_core_json_items($payload);
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
                    'id' => $this->dashboard_core_pick_first_recursive($item, ['id']),
                    'id_solicitud_core' => $this->dashboard_core_pick_first_recursive($item, ['id']),
                    'solicitante' => $this->dashboard_core_pick_first_recursive($item, ['solicitante']),
                    'fecha de creacion' => $this->dashboard_core_pick_first_recursive($item, ['fec_creacion', 'fecha_creacion']),
                    'tipo de solicitud' => $this->dashboard_core_pick_first_recursive($item, ['tipo_sol', 'tipo_solicitud']),
                    'establecimiento' => $this->dashboard_core_pick_first_recursive($item, ['estab', 'establecimiento']),
                    'departamento' => $this->dashboard_core_pick_first_recursive($item, ['departamento']),
                    'telefono' => $this->dashboard_core_pick_first_recursive($item, ['fono', 'telefono']),
                    'celular' => $this->dashboard_core_pick_first_recursive($item, ['celular']),
                    'email' => $this->dashboard_core_pick_first_recursive($item, ['correo', 'email']),
                    'estado' => $this->dashboard_core_pick_first_recursive($item, ['estado']),
                    'usuario asignado' => $this->dashboard_core_pick_first_recursive($item, ['usuario_asignado', 'asignado']),
                ];
            }
            $row = array_merge($row, $this->dashboard_core_extract_detail_fields($item));
            if ($row['solicitante'] === '' && $row['tipo de solicitud'] === '' && $row['establecimiento'] === '') {
                continue;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function dashboard_core_extract_related_request_ids_from_body(string $body): array {
        $segments = [];
        if (preg_match_all('/(?:peticiones relacionadas|subtareas)(.{0,6000})/isu', $body, $matches)) {
            $segments = array_merge($segments, $matches[0] ?? []);
        }
        if (empty($segments)) {
            $segments[] = $body;
        }
        $ids = [];
        foreach ($segments as $segment) {
            foreach ($this->dashboard_core_extract_candidate_request_ids((string)$segment) as $id) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique(array_filter($ids, fn($id) => $id !== '')));
    }

    public function dashboard_core_extract_rows(string $html): array {
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
                $candidateRequestIds = $this->dashboard_core_extract_candidate_request_ids($rowHtml);
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

    public function dashboard_core_filter_payload(array $filters = []): array {
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

    public function dashboard_core_has_runtime_credentials(array $credentials): bool {
        return trim((string)($credentials['user'] ?? '')) !== ''
            && trim((string)($credentials['pass'] ?? '')) !== '';
    }

    public function dashboard_core_has_saved_credentials(): bool {
        return $this->dashboard_core_has_runtime_credentials($this->dashboard_core_credentials_for_current_user());
    }

    public function dashboard_core_import_trace_sample(array $row, array $filters, string $reason): array {
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

    public function dashboard_core_is_configured(array $cfg): bool {
        return !empty($cfg['core_enabled'])
            && trim((string)($cfg['core_admin_url'] ?? '')) !== '';
    }

    public function dashboard_core_is_in_review(array $message): bool {
        return ($this->dashboard_core_status_indicator($message)['key'] ?? '') === 'review';
    }

    public function dashboard_core_json_items(array $payload): array {
        if (dashboard_array_is_list($payload)) {
            return $payload;
        }

        foreach (['data', 'rows', 'items', 'solicitudes', 'result', 'results', 'records', 'aaData'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->dashboard_core_json_items($payload[$key]);
            }
        }

        return [$payload];
    }

    public function dashboard_core_log_import_trace(array $counters, ?array $sample): void {
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

    public function dashboard_core_merge_detail_fields(array $base, array $extra): array {
        foreach ($this->dashboard_core_detail_defaults() as $key => $default) {
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

    public function dashboard_core_parse_login_form(string $html, string $baseUrl): array {
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

    public function dashboard_core_parse_totp_form(string $html, string $baseUrl): array {
        $result = [
            'action' => $baseUrl,
            'field' => '',
            'has_totp_form' => false,
            'fields' => [],
        ];
        if ($html === '' || !preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER)) {
            return $result;
        }

        $totpNames = [
            'totp', 'otp', 'mfa', '2fa', 'authenticator', 'verification_code',
            'verificationcode', 'code', 'codigo', 'token',
        ];
        foreach ($forms as $match) {
            $attrs = (string)($match[1] ?? '');
            $inner = (string)($match[2] ?? '');
            if (str_contains($inner, 'name="login_string"') || str_contains($inner, "name='login_string'")) {
                continue;
            }
            if (!preg_match_all('/<input\b([^>]*)>/is', $inner, $inputs, PREG_SET_ORDER)) {
                continue;
            }

            $fields = [];
            $totpField = '';
            foreach ($inputs as $input) {
                $inputAttrs = (string)($input[1] ?? '');
                if (!preg_match('/name\s*=\s*(["\'])(.*?)\1/i', $inputAttrs, $nameMatch)) {
                    continue;
                }
                $name = trim(html_entity_decode((string)$nameMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($name === '') {
                    continue;
                }
                $value = '';
                if (preg_match('/value\s*=\s*(["\'])(.*?)\1/i', $inputAttrs, $valueMatch)) {
                    $value = html_entity_decode((string)$valueMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $fields[$name] = $value;

                $normalizedName = strtolower(str_replace(['-', '[', ']', '.'], ['_', '', '', '_'], $name));
                $inputType = '';
                if (preg_match('/type\s*=\s*(["\'])(.*?)\1/i', $inputAttrs, $typeMatch)) {
                    $inputType = strtolower(trim((string)$typeMatch[2]));
                }
                $isTotpName = in_array($normalizedName, $totpNames, true)
                    || preg_match('/(^|_)(totp|otp|mfa|2fa)(_|$)/', $normalizedName) === 1;
                if ($normalizedName === 'token' && $inputType === 'hidden') {
                    $isTotpName = false;
                }
                if ($totpField === '' && $isTotpName) {
                    $totpField = $name;
                }
            }
            if ($totpField === '') {
                continue;
            }

            $result['has_totp_form'] = true;
            $result['field'] = $totpField;
            $result['fields'] = $fields;
            if (preg_match('/action\s*=\s*(["\'])(.*?)\1/i', $attrs, $actionMatch)) {
                $action = trim(html_entity_decode((string)$actionMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($action !== '') {
                    if (preg_match('~^https?://~i', $action)) {
                        $result['action'] = $action;
                    } else {
                        $parts = parse_url($baseUrl);
                        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
                        if (isset($parts['port'])) {
                            $origin .= ':' . $parts['port'];
                        }
                        $result['action'] = str_starts_with($action, '/')
                            ? $origin . $action
                            : rtrim(dirname($baseUrl), '/') . '/' . ltrim($action, '/');
                    }
                }
            }
            break;
        }

        return $result;
    }

    /**
     * Opens a CORE session and distinguishes invalid primary credentials from
     * a valid login that is waiting for the user's optional TOTP challenge.
     * The caller owns and must remove cookie_jar when authentication succeeds.
     */
    public function dashboard_core_begin_authentication(string $loginUrl, array $credentials): array {
        $credentials = $this->dashboard_core_runtime_credentials($credentials);
        $base = [
            'authenticated' => false,
            'credentials_validated' => false,
            'requires_totp' => false,
            'cookie_jar' => '',
            'login_response' => [],
            'error' => '',
        ];
        if (!$this->dashboard_core_has_runtime_credentials($credentials)) {
            return array_merge($base, ['error' => 'Debes ingresar credenciales de CORE para esta consulta.']);
        }

        $cookieJar = tempnam(sys_get_temp_dir(), 'core_sync_');
        if ($cookieJar === false) {
            return array_merge($base, ['error' => 'No se pudo crear un archivo temporal para la sesión CORE.']);
        }
        $fail = static function (array $values) use ($base, $cookieJar): array {
            @unlink($cookieJar);
            return array_merge($base, $values);
        };

        $loginPage = $this->dashboard_core_curl($loginUrl, [
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
        ]);
        if (($loginPage['error'] ?? '') !== '') {
            return $fail(['error' => 'No se pudo abrir CORE: ' . $loginPage['error']]);
        }
        $formBaseUrl = trim((string)($loginPage['effective_url'] ?? '')) !== ''
            ? (string)$loginPage['effective_url']
            : $loginUrl;
        $form = $this->dashboard_core_parse_login_form((string)($loginPage['body'] ?? ''), $formBaseUrl);
        if (empty($form['has_login_form'])) {
            return $fail(['error' => 'No se encontró el formulario de acceso de CORE.']);
        }

        $payloadFields = is_array($form['fields'] ?? null) ? $form['fields'] : [];
        $payloadFields['csrf_token'] = (string)($form['csrf_token'] ?? '');
        $payloadFields['login_string'] = $credentials['user'];
        $payloadFields['login_pass'] = $credentials['pass'];
        if (!array_key_exists('submit', $payloadFields) || trim((string)$payloadFields['submit']) === '') {
            $payloadFields['submit'] = 'Ingresar';
        }
        $login = $this->dashboard_core_curl((string)$form['action'], [
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payloadFields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        if (($login['error'] ?? '') !== '') {
            return $fail(['error' => 'No se pudo autenticar en CORE: ' . $login['error']]);
        }

        $responseBase = trim((string)($login['effective_url'] ?? '')) !== ''
            ? (string)$login['effective_url']
            : (string)$form['action'];
        $totpForm = $this->dashboard_core_parse_totp_form((string)($login['body'] ?? ''), $responseBase);
        $totpAttempted = false;
        if (!empty($totpForm['has_totp_form'])) {
            if ($credentials['totp'] === '') {
                return $fail([
                    'credentials_validated' => true,
                    'requires_totp' => true,
                ]);
            }
            if (!preg_match('/^\d{6,8}$/', $credentials['totp'])) {
                return $fail([
                    'credentials_validated' => true,
                    'requires_totp' => true,
                    'error' => 'El código TOTP debe contener entre 6 y 8 dígitos.',
                ]);
            }

            $totpFields = is_array($totpForm['fields'] ?? null) ? $totpForm['fields'] : [];
            $totpFields[(string)$totpForm['field']] = $credentials['totp'];
            $totpAttempted = true;
            $login = $this->dashboard_core_curl((string)$totpForm['action'], [
                CURLOPT_COOKIEJAR => $cookieJar,
                CURLOPT_COOKIEFILE => $cookieJar,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($totpFields),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            if (($login['error'] ?? '') !== '') {
                return $fail([
                    'credentials_validated' => true,
                    'requires_totp' => true,
                    'error' => 'No se pudo verificar el código TOTP en CORE: ' . $login['error'],
                ]);
            }
            $afterTotpBase = trim((string)($login['effective_url'] ?? '')) !== ''
                ? (string)$login['effective_url']
                : (string)$totpForm['action'];
            if (!empty($this->dashboard_core_parse_totp_form((string)($login['body'] ?? ''), $afterTotpBase)['has_totp_form'])) {
                return $fail([
                    'credentials_validated' => true,
                    'requires_totp' => true,
                    'error' => 'CORE rechazó el código TOTP. Verifica el código e inténtalo nuevamente.',
                ]);
            }
        }

        $loginBase = trim((string)($login['effective_url'] ?? '')) !== ''
            ? (string)$login['effective_url']
            : (string)$form['action'];
        if ($this->dashboard_core_response_requires_auth($login)
            || !empty($this->dashboard_core_parse_login_form((string)($login['body'] ?? ''), $loginBase)['has_login_form'])) {
            if ($totpAttempted) {
                return $fail([
                    'credentials_validated' => true,
                    'requires_totp' => true,
                    'error' => 'CORE rechazó el código TOTP. Verifica el código e inténtalo nuevamente.',
                ]);
            }
            return $fail(['error' => 'CORE rechazó las credenciales ingresadas. Verifica usuario y contraseña.']);
        }

        return array_merge($base, [
            'authenticated' => true,
            'credentials_validated' => true,
            'cookie_jar' => $cookieJar,
            'login_response' => $login,
        ]);
    }

    public function dashboard_validate_core_credentials(string $loginUrl, array $credentials): array {
        $result = $this->dashboard_core_begin_authentication($loginUrl, $credentials);
        $cookieJar = trim((string)($result['cookie_jar'] ?? ''));
        if ($cookieJar !== '') {
            @unlink($cookieJar);
        }
        unset($result['cookie_jar'], $result['login_response']);

        return $result;
    }

    public function dashboard_core_pick_first_recursive(array $item, array $keys): string {
        $direct = $this->dashboard_core_pick_first_value($item, $keys);
        if ($direct !== '') {
            return $direct;
        }
        foreach ($item as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = $this->dashboard_core_pick_first_recursive($value, $keys);
            if ($found !== '') {
                return $found;
            }
        }
        return '';
    }

    public function dashboard_core_pick_first_value(array $item, array $keys): string {
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

    public function dashboard_core_response_requires_auth(array $response): bool {
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

    public function dashboard_core_row_matches_filters(array $message, array $filters = []): bool {
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

    public function dashboard_core_rows_from_response_body(string $body): array {
        $rows = $this->dashboard_core_extract_json_rows($body);
        if (empty($rows)) {
            $rows = $this->dashboard_core_extract_rows($body);
        }
        return $rows;
    }

    public function dashboard_core_runtime_credentials(array $input = []): array {
        return [
            'user' => trim((string)($input['user'] ?? '')),
            'pass' => trim((string)($input['pass'] ?? '')),
            'totp' => trim((string)($input['totp'] ?? '')),
        ];
    }

    public function dashboard_core_source_row_matches_filters(array $row, array $filters = []): bool {
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

    public function dashboard_core_status_indicator(array $message): ?array {
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

    public function dashboard_core_trace_assigned_summary(array $assignedCounts, int $limit = 5): string {
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

    public function dashboard_core_url_with_filters(string $url, array $filters = []): string {
        $payload = $this->dashboard_core_filter_payload($filters);
        if (empty($payload)) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($payload);
    }

    public function dashboard_default_core_assigned_name(): string {
        return dashboard_current_user_full_name();
    }

    public function dashboard_should_auto_sync_core(array $cfg): bool {
        return false;
    }

    public function dashboard_sync_core(array &$messages, bool $force = false, array $credentials = []): array {
        $cfg = load_platform_config();
        $adminUrl = (string)($cfg['core_admin_url'] ?? '');
        return $this->dashboard_sync_core_source($messages, $adminUrl, [], $force, $adminUrl, $credentials);
    }

    public function dashboard_sync_core_history(array &$messages, array $filters = [], bool $force = true, array $credentials = []): array {
        $cfg = load_platform_config();
        $adminUrl = (string)($cfg['core_admin_url'] ?? '');
        $sourceUrl = (string)($cfg['core_historico_url'] ?? 'https://www.hbvaldivia.cl/core/solicitudes/administrador/obtener_solicitudes_historicas');
        if (str_contains($sourceUrl, 'obtener_solicitudes_asignadas')) {
            $sourceUrl = str_replace('obtener_solicitudes_asignadas', 'obtener_solicitudes_historicas', $sourceUrl);
        } elseif (str_ends_with(rtrim($sourceUrl, '/'), '/obtener_solicitudes')) {
            $sourceUrl = str_replace('/obtener_solicitudes', '/obtener_solicitudes_historicas', $sourceUrl);
        }
        return $this->dashboard_sync_core_source($messages, $sourceUrl, $filters, $force, $adminUrl, $credentials);
    }

    public function dashboard_sync_core_source(array &$messages, string $sourceUrl, array $filters = [], bool $force = false, ?string $loginUrl = null, array $credentials = []): array {
        $cfg = load_platform_config();
        if (!$force && !$this->dashboard_should_auto_sync_core($cfg)) {
            return ['skipped' => true, 'imported' => 0, 'updated' => 0, 'error' => '', 'authenticated' => false];
        }
        if (!$this->dashboard_core_is_configured($cfg)) {
            return ['skipped' => true, 'imported' => 0, 'updated' => 0, 'error' => 'Configura URL, usuario y contraseña de CORE para sincronizar.', 'authenticated' => false];
        }
        $credentials = $this->dashboard_core_runtime_credentials($credentials);
        if (!$this->dashboard_core_has_runtime_credentials($credentials)) {
            return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'Debes ingresar credenciales de CORE para esta consulta.', 'authenticated' => false];
        }
        $sourceUrl = trim($sourceUrl);
        $loginUrl = trim((string)($loginUrl ?? ''));
        if ($sourceUrl === '') {
            return ['skipped' => false, 'imported' => 0, 'updated' => 0, 'error' => 'Falta configurar la URL de origen de CORE.', 'authenticated' => false];
        }
        if ($loginUrl === '') {
            $loginUrl = $sourceUrl;
        }
        $auth = $this->dashboard_core_begin_authentication($loginUrl, $credentials);
        if (empty($auth['authenticated'])) {
            return [
                'skipped' => false,
                'imported' => 0,
                'updated' => 0,
                'error' => (string)($auth['error'] ?? ''),
                'authenticated' => false,
                'credentials_validated' => !empty($auth['credentials_validated']),
                'requires_totp' => !empty($auth['requires_totp']),
            ];
        }
        $cookieJar = (string)$auth['cookie_jar'];
        $login = is_array($auth['login_response'] ?? null) ? $auth['login_response'] : [];
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
        foreach ($this->dashboard_core_candidate_urls($sourceUrl) as $candidateUrl) {
            $page = $this->dashboard_core_curl($this->dashboard_core_url_with_filters($candidateUrl, $filters), [
                CURLOPT_COOKIEJAR => $cookieJar,
                CURLOPT_COOKIEFILE => $cookieJar,
                CURLOPT_HTTPHEADER => $requestHeaders,
            ]);
            if ($page['error'] !== '') {
                continue;
            }
            if ($this->dashboard_core_response_requires_auth($page)) {
                continue;
            }
            $rows = $this->dashboard_core_rows_from_response_body($page['body']);
            if (!empty($rows)) {
                $sourceUrl = $candidateUrl;
                break;
            }

            $filterPayload = $this->dashboard_core_filter_payload($filters);
            if (empty($filterPayload)) {
                continue;
            }

            $page = $this->dashboard_core_curl($candidateUrl, [
                CURLOPT_COOKIEJAR => $cookieJar,
                CURLOPT_COOKIEFILE => $cookieJar,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($filterPayload),
                CURLOPT_HTTPHEADER => array_merge($requestHeaders, ['Content-Type: application/x-www-form-urlencoded']),
            ]);
            if ($page['error'] !== '') {
                continue;
            }
            if ($this->dashboard_core_response_requires_auth($page)) {
                continue;
            }
            $rows = $this->dashboard_core_rows_from_response_body($page['body']);
            if (empty($rows)) {
                $page = $this->dashboard_core_curl($candidateUrl, [
                    CURLOPT_COOKIEJAR => $cookieJar,
                    CURLOPT_COOKIEFILE => $cookieJar,
                    CURLOPT_HTTPHEADER => $requestHeaders,
                ]);
                if ($page['error'] !== '') {
                    continue;
                }
                if ($this->dashboard_core_response_requires_auth($page)) {
                    continue;
                }
                $rows = $this->dashboard_core_rows_from_response_body($page['body']);
            }
            if (!empty($rows)) {
                $sourceUrl = $candidateUrl;
                break;
            }
        }
        if (!empty($rows)) {
            $rowsForDetail = [];
            foreach ($rows as $detailIndex => $detailRow) {
                if (is_array($detailRow) && $this->dashboard_core_source_row_matches_filters($detailRow, $filters)) {
                    $rowsForDetail[$detailIndex] = $detailRow;
                }
            }
            if (!empty($rowsForDetail)) {
                $detailBaseUrl = $this->dashboard_core_base_admin_url((string)($loginUrl !== '' ? $loginUrl : $sourceUrl));
                $detailRows = $this->dashboard_core_enrich_rows_with_detail($rowsForDetail, $detailBaseUrl, $cookieJar, $requestHeaders);
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
        if ($this->dashboard_core_response_requires_auth($page)) {
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
            if (!is_array($traceRow) || !$this->dashboard_core_date_matches_filters($traceRow, $filters)) {
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
                $traceSample = $this->dashboard_core_import_trace_sample($traceRow, $filters, 'user_mismatch');
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
        $messagesToPersist = [];
        foreach ($rows as $row) {
            $message = $this->dashboard_core_build_message($row, $catalogs, $users);
            if (!$this->dashboard_core_row_matches_filters($message, $filters)) {
                if ($traceSample === null) {
                    $traceSample = $this->dashboard_core_import_trace_sample($message, $filters, 'message_filter_mismatch');
                }
                continue;
            }
            $message = $this->dashboard_apply_import_assignment($message, $filters);
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
                $messagesToPersist[] = $merge['message'];
                $updated++;
                $traceCounters['updated']++;
                continue;
            }
            // Record exists in DB but was archived out of the active dashboard view
            // (e.g. by retention). Skip without duplicating; it is not physically deleted.
            if (isset($dbFuenteIds[$sourceId])) {
                $traceCounters['skipped_existing_db']++;
                if ($traceSample === null) {
                    $traceSample = $this->dashboard_core_import_trace_sample($message, $filters, 'existing_db');
                }
                continue;
            }
            $coreId = $coreSync->coreId($message);
            if ($coreId !== '' && isset($dbCoreIds[$coreId])) {
                $traceCounters['skipped_existing_db']++;
                if ($traceSample === null) {
                    $traceSample = $this->dashboard_core_import_trace_sample($message, $filters, 'existing_core_id_db');
                }
                continue;
            }
            // Not in active view and not in DB — import as new.
            // Archive blobs are intentionally not checked here: if the record was deleted
            // from redmine_mantencion_reportes it must be importable again.
            $messages[] = $message;
            $messagesToPersist[] = $message;
            $existingIndexes = $coreSync->indexes($messages);
            $imported++;
            $traceCounters['imported']++;
        }
        $cfg['core_last_sync'] = (new \DateTimeImmutable())->format(\DateTime::ATOM);
        $cfg['core_last_error'] = '';
        save_platform_config($cfg);
        if ($imported > 0 || $updated > 0) {
            // Persistir solo filas nuevas o pendientes realmente modificadas.
            // Guardar toda la cola tambien tocaba actualizado_at de reportes
            // procesados y reiniciaba indebidamente su reloj de retencion.
            save_messages($messagesToPersist);
        }
        $this->dashboard_core_log_import_trace($traceCounters, $traceSample);
        return [
            'skipped' => false,
            'imported' => $imported,
            'updated' => $updated,
            'error' => '',
            'authenticated' => $coreAuthenticated,
            'trace' => $traceCounters,
            'trace_sample' => $traceSample,
            'trace_assigned_summary' => $this->dashboard_core_trace_assigned_summary($traceAssignedCounts),
        ];
    }
}
