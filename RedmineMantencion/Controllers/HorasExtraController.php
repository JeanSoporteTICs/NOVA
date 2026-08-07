<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionHorasExtraService;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\View;

class HorasExtraController extends Controller
{
    public function __construct(private readonly MantencionHorasExtraService $horasExtra)
    {
    }

    /**
     * Horas Extra. Migrado desde RedmineMantencion/views/HorasExtra/horas_extra.php
     * (lógica de datos, líneas 1-465 del archivo original). El HTML se
     * conserva en resources/views/redmine-mantencion/horas-extra.blade.php.
     */
    public function index(): View
    {
        require_once base_path('RedmineMantencion/controllers/dashboard.php');
        require_once base_path('RedmineMantencion/controllers/maintenance.php');

        if (!auth_can('horas_extra')) {
            abort(403, 'No tienes permiso para ver Horas extra.');
        }

        $maintenanceMode = maintenance_mode_enabled();
        $canEditHours = auth_can('horas_extra_editar');

        $activeNav = 'horas';
        $h = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $csrf = legacy_csrf_token();
        $hoursActionUrl = function_exists('url') ? url('/redmine-mantencion/app/horas-extra') : legacy_app_url('app/horas-extra');
        setlocale(LC_TIME, 'es_CL.UTF-8', 'es_ES.UTF-8', 'es_ES', 'Spanish');

        $today = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
        $mesActual = $today->format('n');
        $selMes = array_key_exists('mes', $_GET) ? trim($_GET['mes']) : $mesActual;
        $anioActual = $today->format('Y');
        $selAnio = array_key_exists('anio', $_GET) ? trim($_GET['anio']) : $anioActual;
        $flash = null;
        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
            7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

        $grupos = $this->horasExtra->loadAll();
        $grupos = $this->horasExtra->deduplicateGroupsBySharedDate($grupos);
        $uid = auth_get_user_id();
        $grupos = $this->horasExtra->filterGroupsForUser($grupos, (string) $uid);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_extra') {
            if (!$canEditHours) {
                abort(403, 'No tienes permiso para editar Horas extra.');
            }
            if (function_exists('csrf_validate')) {
                csrf_validate();
            }
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }
            $fecha = trim($_POST['fecha'] ?? '');
            $horaIni = trim($_POST['hora_ini'] ?? '');
            $horaFin = trim($_POST['hora_fin'] ?? '');
            if ($this->horasExtra->updateHoursByDate($fecha, $horaIni, $horaFin)) {
                $flash = 'Horas actualizadas';
            } else {
                $flash = 'No se encontraron registros para esa fecha';
            }
            $grupos = $this->horasExtra->filterGroupsForUser($this->horasExtra->deduplicateGroupsBySharedDate($this->horasExtra->loadAll()), (string) $uid);
            if ($fecha !== '' && $selMes === '' && $selAnio === '') {
                $dtTmp = DateTime::createFromFormat('Y-m-d', $fecha) ?: DateTime::createFromFormat('d-m-Y', $fecha);
                if ($dtTmp instanceof DateTime) {
                    $selMes = $dtTmp->format('n');
                    $selAnio = $dtTmp->format('Y');
                }
            }
        }

        $aniosDisponibles = [];
        foreach ($grupos as $g) {
            $fechaBase = $g['fecha'] ?? '';
            if ($fechaBase) {
                $dt = DateTime::createFromFormat('Y-m-d', $fechaBase) ?: DateTime::createFromFormat('d-m-Y', $fechaBase);
                if ($dt instanceof DateTime) {
                    $aniosDisponibles[$dt->format('Y')] = true;
                }
            }
        }
        $aniosDisponibles = array_keys($aniosDisponibles);
        $aniosDisponibles[] = $anioActual;
        if ($selAnio !== '') {
            $aniosDisponibles[] = $selAnio;
        }
        $aniosDisponibles = array_values(array_unique(array_map('strval', $aniosDisponibles)));
        $aniosDisponibles ? sort($aniosDisponibles, SORT_NUMERIC) : [];

        $grupos = array_values(array_filter($grupos, function ($g) use ($selMes, $selAnio) {
            $fechaBase = $g['fecha'] ?? '';
            if ($fechaBase) {
                $dt = DateTime::createFromFormat('Y-m-d', $fechaBase) ?: DateTime::createFromFormat('d-m-Y', $fechaBase);
                if ($dt instanceof DateTime) {
                    $mesNum = (int) $dt->format('n');
                    $anioNum = $dt->format('Y');
                    if ($selMes !== '' && (int) $selMes !== $mesNum) {
                        return false;
                    }
                    if ($selAnio !== '' && $selAnio !== $anioNum) {
                        return false;
                    }
                }
            }

            return true;
        }));

        usort($grupos, function ($a, $b) {
            $fa = $this->horasExtra->normalizeDateKey($a['fecha'] ?? '');
            $fb = $this->horasExtra->normalizeDateKey($b['fecha'] ?? '');
            if ($fa === $fb) {
                return 0;
            }
            if ($fa === '') {
                return 1;
            }
            if ($fb === '') {
                return -1;
            }

            return $fa <=> $fb; // mostrar primero las fechas más antiguas
        });

        $emachSuggestions = $this->horasExtra->emachOvertimeSuggestions($grupos);

        $horasExtraService = $this->horasExtra;

        return view('redmine-mantencion.horas-extra', get_defined_vars());
    }
}
