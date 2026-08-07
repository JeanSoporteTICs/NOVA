<?php

namespace App\Modulos\RedmineMantencion\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;

class MantencionPendientesService
{
    public function consumeFlash(): ?string
    {
        $message = session()->pull('mantencion_manual_pending_flash');

        return is_string($message) && trim($message) !== '' ? $message : null;
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function users(): array
    {
        $users = [];
        foreach (dashboard_active_mantencion_users() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            $nombre = trim((string) ($row['nombre_completo'] ?? ''));
            if ($id === '' || $nombre === '') {
                continue;
            }
            $users[] = ['id' => $id, 'nombre' => $nombre];
        }
        usort($users, fn ($a, $b) => strcasecmp((string) $a['nombre'], (string) $b['nombre']));

        return $users;
    }

    /**
     * @param array<int,array<string,string>> $users
     */
    public function findUserName(string $userId, array $users): string
    {
        foreach ($users as $user) {
            if ((string) ($user['id'] ?? '') === $userId) {
                return trim((string) ($user['nombre'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @return array<string,mixed>
     */
    public function loadConfig(): array
    {
        $cfg = load_platform_config();
        $cfg['project_id'] = $cfg['project_id'] ?? 48;
        $cfg['project_name'] = trim((string) ($cfg['project_name'] ?? 'Backlog Mantención TI'));
        $cfg['tracker_id'] = $cfg['tracker_id'] ?? 3;
        $cfg['priority_id'] = $cfg['priority_id'] ?? 2;
        $cfg['status_id'] = $cfg['status_id'] ?? 1;
        $cfg['trackers'] = is_array($cfg['trackers'] ?? null) ? $cfg['trackers'] : [];
        $cfg['prioridades'] = is_array($cfg['prioridades'] ?? null) ? $cfg['prioridades'] : [];
        $cfg['estados'] = is_array($cfg['estados'] ?? null) ? $cfg['estados'] : [];

        return $cfg;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    public function optionName(array $items, $id): string
    {
        foreach ($items as $item) {
            if ((string) ($item['id'] ?? '') === (string) $id) {
                return trim((string) ($item['nombre'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    public function normalizeOptionId($value, array $items, $fallback = ''): string
    {
        $candidate = trim((string) $value);
        if ($candidate !== '') {
            foreach ($items as $item) {
                if ((string) ($item['id'] ?? '') === $candidate) {
                    return $candidate;
                }
            }
        }
        $fallback = trim((string) $fallback);
        if ($fallback !== '') {
            foreach ($items as $item) {
                if ((string) ($item['id'] ?? '') === $fallback) {
                    return $fallback;
                }
            }
        }
        foreach ($items as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    public function normalizeUserId($value, array $users): string
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return '';
        }
        foreach ($users as $user) {
            if ((string) ($user['id'] ?? '') === $candidate) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $categories
     */
    public function normalizeCategoryName($value, array $categories): string
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return '';
        }
        foreach ($categories as $category) {
            $name = trim((string) ($category['nombre'] ?? ''));
            if ($name !== '' && strcasecmp($name, $candidate) === 0) {
                return $name;
            }
        }

        return '';
    }

    public function parseDisplayDate($value): ?DateTimeImmutable
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate)) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $candidate);
            if (!($dt instanceof DateTimeImmutable)) {
                return null;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
                return null;
            }
            if ($dt->format('Y-m-d') !== $candidate) {
                return null;
            }

            return $dt;
        }
        if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $candidate)) {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('d-m-Y', $candidate);
        if (!($dt instanceof DateTimeImmutable)) {
            return null;
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            return null;
        }
        if ($dt->format('d-m-Y') !== $candidate) {
            return null;
        }

        return $dt;
    }

    public function normalizeDate($value): string
    {
        $dt = $this->parseDisplayDate($value);

        return $dt ? $dt->format('d-m-Y') : '';
    }

    public function dateForInput($value): string
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return '';
        }
        $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'];
        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $candidate);
            if ($dt instanceof DateTimeImmutable) {
                $errors = DateTimeImmutable::getLastErrors();
                if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                    return $dt->format('Y-m-d');
                }
            }
        }
        $ts = strtotime($candidate);
        if ($ts === false || $ts <= 0) {
            return '';
        }

        return (new DateTimeImmutable())->setTimestamp($ts)->format('Y-m-d');
    }

    public function normalizeHours($value): string
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return '';
        }
        $normalized = str_replace(',', '.', $candidate);
        if (!is_numeric($normalized)) {
            return '';
        }
        $hours = (float) $normalized;
        if ($hours < 0) {
            return '';
        }

        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }

    public function normalizeEmail($value): string
    {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            return '';
        }

        return filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function categoryOptions(): array
    {
        $repo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;

        return $repo !== null ? $repo->categorias() : [];
    }

    /**
     * @param array<string,mixed> $cfg
     * @param array<int,array<string,mixed>> $users
     * @return array<string,mixed>
     */
    public function defaultForm(array $cfg, array $users): array
    {
        $currentUser = dashboard_current_user();
        $currentUserId = (string) ($currentUser['id'] ?? auth_get_user_id() ?? '');
        $currentUserName = $currentUserId !== '' ? $this->findUserName($currentUserId, $users) : dashboard_current_user_full_name();
        $today = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d');

        return [
            'project_id' => (string) ($cfg['project_id'] ?? 48),
            'tracker_id' => (string) ($cfg['tracker_id'] ?? 3),
            'asunto' => '',
            'descripcion' => '',
            'status_id' => (string) ($cfg['status_id'] ?? 1),
            'priority_id' => (string) ($cfg['priority_id'] ?? 2),
            'fecha_inicio' => $today,
            'fecha_fin' => $today,
            'tiempo_estimado' => '',
            'asignado_a' => $currentUserId,
            'categoria' => '',
            'solicitante' => '',
            'anexo' => '',
            'unidad' => '',
            'core_email' => '',
            'hora_extra' => '0',
            'core_usuario_asignado' => $currentUserName,
            'core_estado' => 'Manual',
            'core_tipo_solicitud' => '',
            'core_establecimiento' => '',
            'core_departamento' => '',
            'core_telefono' => '',
            'core_celular' => '',
            'core_detalle_tipo_solicitud' => '',
            'core_detalle_run' => '',
            'core_detalle_nombre' => '',
            'core_detalle_motivo' => '',
            'core_detalle_establecimientos' => '',
            'core_detalle_otros_permisos' => '',
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $cfg
     * @param array<int,array<string,mixed>> $users
     * @return array<string,mixed>
     */
    public function buildRecord(array $input, array $cfg, array $users): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
        $trackerName = $this->optionName($cfg['trackers'] ?? [], $input['tracker_id'] ?? '');
        $priorityName = $this->optionName($cfg['prioridades'] ?? [], $input['priority_id'] ?? '');
        $statusName = $this->optionName($cfg['estados'] ?? [], $input['status_id'] ?? '');
        $assignedId = trim((string) ($input['asignado_a'] ?? ''));
        $assignedName = $assignedId !== '' ? $this->findUserName($assignedId, $users) : '';
        $anexo = trim((string) ($input['anexo'] ?? ''));
        $correo = trim((string) ($input['core_email'] ?? ''));
        $unidad = trim((string) ($input['unidad'] ?? ''));
        $categoria = trim((string) ($input['categoria'] ?? ''));
        $solicitante = trim((string) ($input['solicitante'] ?? ''));
        $asunto = trim((string) ($input['asunto'] ?? ''));
        $descripcion = trim((string) ($input['descripcion'] ?? ''));
        $fechaInicio = $this->normalizeDate($input['fecha_inicio'] ?? '');
        $fechaInicioDate = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaInicio, new DateTimeZone('America/Santiago'));
        $fechaCreacion = $fechaInicioDate instanceof DateTimeImmutable ? $fechaInicioDate->format('d-m-Y') : $now->format('d-m-Y');

        return [
            'id' => 'manual-' . uniqid('', true),
            'fuente' => 'manual',
            'fuente_id' => sha1(implode('|', ['manual', $asunto, $solicitante, $unidad, $anexo, $correo, $now->format(DateTimeInterface::ATOM)])),
            'numero' => dashboard_normalize_phone($anexo),
            'mensaje' => $asunto,
            'descripcion' => $descripcion,
            'fecha' => $fechaCreacion,
            'hora' => $now->format('H:i'),
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $this->normalizeDate($input['fecha_fin'] ?? $fechaInicio),
            'tipo' => $trackerName !== '' ? $trackerName : 'Soporte',
            'tipo_id' => trim((string) ($input['tracker_id'] ?? '')),
            'prioridad' => $priorityName !== '' ? $priorityName : 'Normal',
            'priority_id' => trim((string) ($input['priority_id'] ?? '')),
            'status_id' => trim((string) ($input['status_id'] ?? '')),
            'project_id' => trim((string) ($input['project_id'] ?? ($cfg['project_id'] ?? 48))),
            'proyecto' => trim((string) ($cfg['project_name'] ?? '')),
            'project_name' => trim((string) ($cfg['project_name'] ?? '')),
            'estado' => 'pendiente',
            'estado_redmine' => $statusName,
            'hora_extra' => !empty($input['hora_extra']) ? '1' : '0',
            'tiempo_estimado' => trim((string) ($input['tiempo_estimado'] ?? '')),
            'categoria' => $categoria,
            'unidad' => $unidad,
            'unidad_solicitante' => $unidad,
            'solicitante' => $solicitante,
            'asunto' => $asunto,
            'asignado_a' => $assignedId,
            'asignado_nombre' => $assignedName,
            'anexo' => $anexo,
            'redmine_id' => '',
            'procesado_ts' => '',
            'core_fecha_creacion' => $fechaCreacion . ' ' . $now->format('H:i'),
            'core_tipo_solicitud' => $categoria !== '' ? $categoria : ($trackerName !== '' ? $trackerName : 'Soporte'),
            'core_establecimiento' => $unidad,
            'core_departamento' => $unidad,
            'core_estado' => 'Manual',
            'core_usuario_asignado' => $assignedName,
            'core_email' => $correo,
            'core_telefono' => $anexo,
            'core_celular' => '',
            'core_detalle_tipo_solicitud' => '',
            'core_detalle_run' => '',
            'core_detalle_nombre' => '',
            'core_detalle_motivo' => '',
            'core_detalle_establecimientos' => '',
            'core_detalle_otros_permisos' => '',
        ];
    }

    /**
     * Réplica handle_manual_pending() del legacy. Devuelve la tupla habitual
     * [cfg, users, categorias, form, flash, error] o, si el POST se procesó
     * con éxito, un RedirectResponse (en vez del antiguo header()+exit).
     *
     * @return array{0:array,1:array,2:array,3:array,4:?string,5:?string}|RedirectResponse
     */
    public function handleManualPending(): array|RedirectResponse
    {
        $cfg = $this->loadConfig();
        $users = $this->users();
        $categorias = $this->categoryOptions();
        $form = $this->defaultForm($cfg, $users);
        $flash = $this->consumeFlash();
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string) ($_GET['created'] ?? '') === '1') {
            $flash = 'Pendiente manual creado correctamente.';
        }
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (function_exists('csrf_validate')) {
                csrf_validate();
            }
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }
            foreach ($form as $key => $value) {
                if ($key === 'hora_extra') {
                    $form[$key] = trim((string) ($_POST[$key] ?? '0'));
                    continue;
                }
                $form[$key] = trim((string) ($_POST[$key] ?? $value));
            }
            $form['project_id'] = (string) ($cfg['project_id'] ?? 48);
            $form['tracker_id'] = $this->normalizeOptionId($form['tracker_id'] ?? '', $cfg['trackers'] ?? [], $cfg['tracker_id'] ?? 3);
            $form['status_id'] = $this->normalizeOptionId($form['status_id'] ?? '', $cfg['estados'] ?? [], $cfg['status_id'] ?? 1);
            $form['priority_id'] = $this->normalizeOptionId($form['priority_id'] ?? '', $cfg['prioridades'] ?? [], $cfg['priority_id'] ?? 2);
            $currentUser = dashboard_current_user();
            $currentUserId = (string) ($currentUser['id'] ?? auth_get_user_id() ?? '');
            if (!dashboard_can_assign_other_users()) {
                $form['asignado_a'] = $this->normalizeUserId($currentUserId, $users);
            } else {
                $form['asignado_a'] = $this->normalizeUserId($form['asignado_a'] ?? '', $users);
                if ($form['asignado_a'] === '') {
                    $form['asignado_a'] = $this->normalizeUserId($currentUserId, $users);
                }
            }
            $form['categoria'] = $this->normalizeCategoryName($form['categoria'] ?? '', $categorias);
            $form['fecha_inicio'] = $this->normalizeDate($form['fecha_inicio'] ?? '');
            $form['fecha_fin'] = $this->normalizeDate($form['fecha_fin'] ?? '');
            $form['tiempo_estimado'] = $this->normalizeHours($form['tiempo_estimado'] ?? '');
            $form['core_email'] = $this->normalizeEmail($form['core_email'] ?? '');
            $form['hora_extra'] = ($form['hora_extra'] ?? '0') === '1' ? '1' : '0';
            $form['core_usuario_asignado'] = $this->findUserName((string) ($form['asignado_a'] ?? ''), $users);
            $trackerName = $this->optionName($cfg['trackers'] ?? [], $form['tracker_id'] ?? '');
            $form['core_tipo_solicitud'] = trim((string) ($form['categoria'] ?? '')) !== '' ? trim((string) $form['categoria']) : $trackerName;
            $form['core_establecimiento'] = trim((string) ($form['unidad'] ?? ''));
            $form['core_departamento'] = trim((string) ($form['unidad'] ?? ''));
            $form['core_telefono'] = trim((string) ($form['anexo'] ?? ''));

            if ($form['asunto'] === '' || $form['solicitante'] === '') {
                $error = 'Asunto y solicitante son obligatorios.';
            } elseif (trim((string) ($_POST['fecha_inicio'] ?? '')) !== '' && $form['fecha_inicio'] === '') {
                $error = 'La fecha de inicio no es válida.';
            } elseif (trim((string) ($_POST['fecha_fin'] ?? '')) !== '' && $form['fecha_fin'] === '') {
                $error = 'La fecha fin no es válida.';
            } else {
                $messages = load_messages();
                $messages[] = $this->buildRecord($form, $cfg, $users);
                if (!save_messages($messages)) {
                    $error = 'No fue posible guardar el pendiente. Intenta nuevamente o revisa el registro del sistema.';
                } else {
                    manual_pending_flash_set('Pendiente manual creado correctamente.');
                    $manualPendingUrl = function_exists('url')
                        ? url('/redmine-mantencion/app/manual')
                        : legacy_app_url('app/manual');

                    return redirect($manualPendingUrl . '?created=1');
                }
            }
        }

        return [$cfg, $users, $categorias, $form, $flash, $error];
    }
}
