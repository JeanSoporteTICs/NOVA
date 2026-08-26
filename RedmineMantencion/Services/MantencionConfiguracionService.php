<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Support\Reports\AutomaticReportSchedule;

class MantencionConfiguracionService
{
    public function loadConfig()
    {
        $repo = config_mantencion_repository();
        $data = $repo !== null ? $repo->loadAll() : [];
        if (! is_array($data)) {
            $data = [];
        }
        $data['platform_token'] = '';
        if (! array_key_exists('categories_url', $data)) {
            $data['categories_url'] = '';
        }
        if (! array_key_exists('unidades_url', $data)) {
            $data['unidades_url'] = '';
        }
        if (! array_key_exists('cf_solicitante', $data) || $data['cf_solicitante'] === null || $data['cf_solicitante'] === '') {
            $data['cf_solicitante'] = 3;
        }
        if (! array_key_exists('cf_unidad', $data) || $data['cf_unidad'] === null || $data['cf_unidad'] === '') {
            $data['cf_unidad'] = 5;
        }
        if (! array_key_exists('cf_unidad_solicitante', $data)) {
            $data['cf_unidad_solicitante'] = 11;
        }
        if (! array_key_exists('cf_hora_extra', $data)) {
            $data['cf_hora_extra'] = 12;
        }
        if (! array_key_exists('hora_extra_tiempo_estimado', $data)) {
            $data['hora_extra_tiempo_estimado'] = '1';
        }
        if (! array_key_exists('source_mode', $data)) {
            $data['source_mode'] = 'core';
        }
        if (! array_key_exists('core_enabled', $data)) {
            $data['core_enabled'] = true;
        }
        if (! array_key_exists('core_admin_url', $data)) {
            $data['core_admin_url'] = 'https://www.hbvaldivia.cl/core/solicitudes/administrador';
        }
        if (! array_key_exists('core_historico_url', $data)) {
            $data['core_historico_url'] = 'https://www.hbvaldivia.cl/core/solicitudes/administrador/obtener_solicitudes_historicas';
        }
        if (! array_key_exists('core_sync_minutes', $data)) {
            $data['core_sync_minutes'] = 2;
        }
        if (! array_key_exists('core_last_sync', $data)) {
            $data['core_last_sync'] = '';
        }
        if (! array_key_exists('core_last_error', $data)) {
            $data['core_last_error'] = '';
        }
        if (! array_key_exists('informes_nuevos_habilitado', $data)) {
            $data['informes_nuevos_habilitado'] = true;
        }
        if (! array_key_exists('informes_nuevos_dias', $data)) {
            $data['informes_nuevos_dias'] = 2;
        }
        $schedule = AutomaticReportSchedule::settings($data);
        $data['informes_nuevos_dias_desde'] = $schedule['days_from'];
        $data['informes_nuevos_dias_hasta'] = $schedule['days_to'];
        $data['informes_nuevos_periodo'] = $schedule['period'];
        $data['informes_nuevos_dia'] = $schedule['day'];
        $data['informes_nuevos_hora'] = $schedule['time'];
        foreach (['trackers', 'prioridades', 'estados'] as $k) {
            if (! isset($data[$k]) || ! is_array($data[$k])) {
                $data[$k] = [];
            }
        }
        if (! isset($data['session_timeout'])) {
            $data['session_timeout'] = 300;
        }

        return $data;
    }

    public function saveConfig($cfg)
    {
        $repo = config_mantencion_repository();
        if ($repo !== null) {
            $repo->saveAll($cfg);
        }
        // No filesystem write: configuraciones_modulo is now the single source of truth (S30)
    }

    public function handle()
    {
        $cfg = $this->loadConfig();
        $flash = session()->pull('mantencion_config_flash');
        $action = $_POST['action'] ?? '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '') {
            if (function_exists('csrf_validate')) {
                csrf_validate();
            }
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }
            $optionType = [
                'trackers' => 'tracker',
                'prioridades' => 'prioridad',
                'estados' => 'estado',
            ][(string) ($_POST['opt_type'] ?? '')] ?? null;
            $optionAction = (string) ($_POST['opt_action'] ?? '');
            if ($optionType !== null && in_array($optionAction, ['create', 'update', 'delete', 'set_default'], true)) {
                $repo = config_mantencion_repository();
                $id = trim((string) ($_POST['opt_id'] ?? ''));
                $originalId = trim((string) ($_POST['opt_id_original'] ?? $id));
                $name = trim((string) ($_POST['opt_nombre'] ?? ''));
                $default = isset($_POST['opt_default']);
                if ($repo !== null) {
                    match ($optionAction) {
                        'create' => $repo->createOption($optionType, $id, $name, $default),
                        'update' => $repo->updateOption($optionType, $originalId, $id, $name, $default),
                        'delete' => $repo->deleteOption($optionType, $originalId),
                        'set_default' => $repo->setDefaultOption($optionType, $id),
                    };
                }
                session()->put('mantencion_config_flash', 'Configuración guardada');

                return redirect($_SERVER['REQUEST_URI'] ?? '/redmine-mantencion/app/configuracion');
            }

            $cfg['platform_url'] = trim($_POST['platform_url'] ?? $cfg['platform_url'] ?? '');
            unset($cfg['platform_token']);
            $cfg['source_mode'] = 'core';
            $cfg['core_enabled'] = true;
            $cfg['core_admin_url'] = trim($_POST['core_admin_url'] ?? ($cfg['core_admin_url'] ?? ''));
            $cfg['core_historico_url'] = trim($_POST['core_historico_url'] ?? ($cfg['core_historico_url'] ?? ($cfg['core_admin_url'] ?? '')));
            $cfg['core_sync_minutes'] = max(1, (int) ($_POST['core_sync_minutes'] ?? ($cfg['core_sync_minutes'] ?? 2)));
            unset($cfg['core_login_user'], $cfg['core_login_pass']);
            $cfg['categories_url'] = trim($_POST['categories_url'] ?? ($cfg['categories_url'] ?? ''));
            $cfg['unidades_url'] = trim($_POST['unidades_url'] ?? ($cfg['unidades_url'] ?? ''));
            $cfg['project_id'] = is_numeric($_POST['project_id'] ?? '') ? (int) $_POST['project_id'] : ($_POST['project_id'] ?? $cfg['project_id'] ?? '');
            $cfg['project_name'] = trim($_POST['project_name'] ?? ($cfg['project_name'] ?? ''));
            $cfg['tracker_id'] = is_numeric($_POST['tracker_id'] ?? '') ? (int) $_POST['tracker_id'] : ($_POST['tracker_id'] ?? $cfg['tracker_id'] ?? null);
            $cfg['priority_id'] = is_numeric($_POST['priority_id'] ?? '') ? (int) $_POST['priority_id'] : ($_POST['priority_id'] ?? $cfg['priority_id'] ?? null);
            $cfg['cf_solicitante'] = ($_POST['cf_solicitante'] ?? '') === '' ? null : $_POST['cf_solicitante'];
            $cfg['cf_unidad'] = ($_POST['cf_unidad'] ?? '') === '' ? null : $_POST['cf_unidad'];
            $cfg['cf_unidad_solicitante'] = ($_POST['cf_unidad_solicitante'] ?? '') === '' ? null : $_POST['cf_unidad_solicitante'];
            $cfg['cf_hora_extra'] = ($_POST['cf_hora_extra'] ?? '') === '' ? null : $_POST['cf_hora_extra'];
            $cfg['hora_extra_tiempo_estimado'] = trim((string) ($_POST['hora_extra_tiempo_estimado'] ?? ($cfg['hora_extra_tiempo_estimado'] ?? '1')));
            $cfg['status_id'] = is_numeric($_POST['status_id'] ?? '') ? (int) $_POST['status_id'] : ($cfg['status_id'] ?? 1);
            $cfg['retencion_horas'] = max(1, (int) ($_POST['retencion_horas'] ?? ($cfg['retencion_horas'] ?? 24)));
            if (array_key_exists('informes_nuevos_habilitado', $_POST)) {
                $cfg['informes_nuevos_habilitado'] = (string) $_POST['informes_nuevos_habilitado'] === '1';
            }
            if (array_key_exists('informes_nuevos_dias', $_POST)) {
                $cfg['informes_nuevos_dias'] = max(1, min(30, (int) $_POST['informes_nuevos_dias']));
            }
            if (array_key_exists('informes_nuevos_dias_desde', $_POST)
                || array_key_exists('informes_nuevos_dias_hasta', $_POST)
                || array_key_exists('informes_nuevos_periodo', $_POST)
                || array_key_exists('informes_nuevos_dia', $_POST)
                || array_key_exists('informes_nuevos_hora', $_POST)) {
                $schedule = AutomaticReportSchedule::settings(array_merge($cfg, [
                    'informes_nuevos_dias_desde' => $_POST['informes_nuevos_dias_desde'] ?? 2,
                    'informes_nuevos_dias_hasta' => $_POST['informes_nuevos_dias_hasta'] ?? 365,
                    'informes_nuevos_periodo' => $_POST['informes_nuevos_periodo'] ?? 'previous_week',
                    'informes_nuevos_dia' => $_POST['informes_nuevos_dia'] ?? '1',
                    'informes_nuevos_hora' => $_POST['informes_nuevos_hora'] ?? '09:00',
                ]));
                $cfg['informes_nuevos_dias'] = $schedule['days_from'];
                $cfg['informes_nuevos_dias_desde'] = $schedule['days_from'];
                $cfg['informes_nuevos_dias_hasta'] = $schedule['days_to'];
                $cfg['informes_nuevos_periodo'] = $schedule['period'];
                $cfg['informes_nuevos_dia'] = $schedule['day'];
                $cfg['informes_nuevos_hora'] = $schedule['time'];
            }
            $cfg['session_timeout'] = max(60, (int) ($_POST['session_timeout'] ?? ($cfg['session_timeout'] ?? 300)));
            $this->saveConfig($cfg);
            session()->put('mantencion_config_flash', 'Configuración guardada');

            return redirect($_SERVER['REQUEST_URI'] ?? '/redmine-mantencion/app/configuracion');
        }
        $opts = [
            'trackers' => $cfg['trackers'] ?? [],
            'prioridades' => $cfg['prioridades'] ?? [],
            'estados' => $cfg['estados'] ?? [],
        ];

        return [$cfg, $flash, $opts];
    }
}
