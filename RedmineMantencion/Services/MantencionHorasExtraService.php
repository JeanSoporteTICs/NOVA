<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MantencionHorasExtraService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function loadAll(): array
    {
        $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;

        return $repo !== null ? $repo->groups() : [];
    }

    public function normalizeDateKey($fecha)
    {
        $fecha = trim((string) $fecha);
        if ($fecha === '') {
            return '';
        }
        $fmts = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'];
        foreach ($fmts as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $fecha);
            if ($dt instanceof DateTime) {
                return $dt->format('Y-m-d');
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $groups
     * @return array<int,array<string,mixed>>
     */
    public function deduplicateGroupsBySharedDate(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            if (!is_array($group) || empty($group['reports']) || !is_array($group['reports'])) {
                continue;
            }
            $groupFecha = $this->normalizeDateKey($group['fecha'] ?? '');
            if ($groupFecha === '') {
                continue;
            }
            foreach ($group['reports'] as $report) {
                if (!is_array($report)) {
                    continue;
                }
                $startDate = $groupFecha;
                if ($startDate === '') {
                    continue;
                }
                if (!isset($out[$startDate])) {
                    $out[$startDate] = [
                        'fecha' => $startDate,
                        'hora_inicio' => $group['hora_inicio'] ?? '',
                        'hora_fin' => $group['hora_fin'] ?? '',
                        'reports' => [],
                        '__order' => [],
                    ];
                }
                if (!empty($group['hora_inicio'])) {
                    $out[$startDate]['hora_inicio'] = $group['hora_inicio'];
                }
                if (!empty($group['hora_fin'])) {
                    $out[$startDate]['hora_fin'] = $group['hora_fin'];
                }
                $reportKey = $report['id'] ?? null;
                if ($reportKey === null) {
                    $reportKey = ($report['numero'] ?? '') . '|' . ($report['hora'] ?? '') . '|' . ($report['asunto'] ?? '');
                }
                if ($reportKey === '') {
                    continue;
                }
                if (!isset($out[$startDate]['reports'][$reportKey])) {
                    $out[$startDate]['reports'][$reportKey] = $report;
                    $out[$startDate]['__order'][] = $reportKey;
                    continue;
                }
                foreach ($report as $key => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $out[$startDate]['reports'][$reportKey][$key] = $value;
                }
            }
        }
        foreach ($out as &$entry) {
            $reports = [];
            foreach ($entry['__order'] as $key) {
                if (isset($entry['reports'][$key])) {
                    $reports[] = $entry['reports'][$key];
                }
            }
            $entry['reports'] = $reports;
            unset($entry['__order']);
        }
        unset($entry);

        return array_values($out);
    }

    /**
     * @param array<int,array<string,mixed>> $groups
     * @return array<int,array<string,mixed>>
     */
    public function filterGroupsForUser(array $groups, string $userId): array
    {
        if ($userId === '') {
            return [];
        }

        return array_values(array_filter(array_map(static function ($group) use ($userId) {
            if (!is_array($group)) {
                return null;
            }
            $group['reports'] = array_values(array_filter(
                (array) ($group['reports'] ?? []),
                static fn ($report) => is_array($report) && (string) ($report['asignado_a'] ?? '') === $userId
            ));

            return $group['reports'] !== [] ? $group : null;
        }, $groups), static fn ($group) => $group !== null));
    }

    public function sanitizeTimeValue($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            $hh = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mm = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            if (!isset($m[3]) || $m[3] === '') {
                return "$hh:$mm";
            }
            $ss = str_pad($m[3], 2, '0', STR_PAD_LEFT);

            return "$hh:$mm:$ss";
        }

        return $value;
    }

    public function updateHoursByDate($fecha, $horaIni, $horaFin): bool
    {
        if ($fecha === '') {
            return false;
        }
        $fechaKey = $this->normalizeDateKey($fecha);
        $horaIni = $this->sanitizeTimeValue($horaIni);
        $horaFin = $this->sanitizeTimeValue($horaFin);
        $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;

        return $repo !== null && $repo->updateGroupHours($fechaKey, $horaIni, $horaFin);
    }

    public function formatFecha($fecha)
    {
        $dt = DateTime::createFromFormat('Y-m-d', $fecha) ?: DateTime::createFromFormat('d-m-Y', $fecha);

        return $dt ? $dt->format('d-m-Y') : $fecha;
    }

    public function minutosDiff($ini, $fin)
    {
        if (!$ini || !$fin) {
            return null;
        }
        $d1 = DateTime::createFromFormat('H:i', $ini) ?: DateTime::createFromFormat('H:i:s', $ini);
        $d2 = DateTime::createFromFormat('H:i', $fin) ?: DateTime::createFromFormat('H:i:s', $fin);
        if (!$d1 || !$d2 || $d2 <= $d1) {
            return null;
        }

        return (int) round(($d2->getTimestamp() - $d1->getTimestamp()) / 60);
    }

    public function hhmm($mins)
    {
        if ($mins === null) {
            return '';
        }
        $hh = str_pad((string) floor($mins / 60), 2, '0', STR_PAD_LEFT);
        $mm = str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT);

        return "$hh:$mm";
    }

    public function emachMinutesFromTime($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{1,2}):([0-5]\d)(?::[0-5]\d)?$/', $value, $matches)) {
            return null;
        }
        $hour = (int) $matches[1];
        if ($hour < 0 || $hour > 23) {
            return null;
        }

        return ($hour * 60) + (int) $matches[2];
    }

    public function emachClockFromMinutes($minutes)
    {
        $minutes = max(0, min(1439, (int) $minutes));

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @param array<string,mixed> $sessionUser
     */
    public function emachCentralUserId(array $sessionUser): ?int
    {
        if (!class_exists(DB::class) || !class_exists(Schema::class)) {
            return null;
        }

        try {
            if (!Schema::hasTable('usuarios_nova')) {
                return null;
            }

            $candidates = [
                'uuid' => [$sessionUser['_nova_user_id'] ?? '', $sessionUser['uuid'] ?? ''],
                'usuario' => [$sessionUser['username'] ?? '', $sessionUser['usuario'] ?? '', $sessionUser['rut_sin_dv'] ?? '', $sessionUser['id'] ?? ''],
                'rut' => [$sessionUser['rut'] ?? ''],
                'redmine_id' => [$sessionUser['redmine_id'] ?? '', $sessionUser['id'] ?? ''],
                'usuario_core' => [$sessionUser['core_user'] ?? '', $sessionUser['usuario_core'] ?? ''],
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
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function emachScheduleForUser(?int $userId): array
    {
        if (!$userId || !class_exists(DB::class) || !class_exists(Schema::class)) {
            return [];
        }

        try {
            if (!Schema::hasTable('emach_horarios_usuario')) {
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
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function emachExitMarksFromSession(): array
    {
        if (!function_exists('request') || !request()->hasSession()) {
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
            $dateKey = $this->normalizeDateKey((string) ($row[3] ?? data_get($row, 'fecha', '')));
            $minutes = $this->emachMinutesFromTime((string) ($row[4] ?? data_get($row, 'marcas', data_get($row, 'marca', ''))));
            if ($dateKey === '' || $minutes === null) {
                continue;
            }
            $marks[$dateKey]['exit'] = max((int) ($marks[$dateKey]['exit'] ?? -1), $minutes);
        }

        return $marks;
    }

    /**
     * @param array<string,mixed> $sessionUser
     */
    public function emachCredentialsConfigured(array $sessionUser): bool
    {
        if (!function_exists('app')) {
            return false;
        }

        try {
            $credentials = app(UserIntegrationRepository::class)->emachForSession($sessionUser);

            return !empty($credentials['stored']);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $groups
     * @return array<string,array<string,mixed>>
     */
    public function emachOvertimeSuggestions(array $groups): array
    {
        $suggestions = [];
        foreach ($groups as $group) {
            $dateKey = $this->normalizeDateKey($group['fecha'] ?? '');
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
        if (!$suggestions) {
            return [];
        }

        $sessionUser = function_exists('mantencion_current_user') ? (mantencion_current_user() ?? []) : [];
        if (function_exists('request')) {
            $novaUser = request()->session()->get('nova_user', []);
            if (is_array($novaUser)) {
                $sessionUser = array_merge($novaUser, $sessionUser);
            }
        }

        if (!$this->emachCredentialsConfigured($sessionUser)) {
            return array_map(function ($suggestion) {
                $suggestion['status'] = 'Configura tus credenciales EMACH antes de calcular.';

                return $suggestion;
            }, $suggestions);
        }

        $userId = $this->emachCentralUserId($sessionUser);
        if ($userId === null) {
            return array_map(function ($suggestion) {
                $suggestion['status'] = 'No pude asociar tu usuario NOVA con EMACH.';

                return $suggestion;
            }, $suggestions);
        }

        $schedule = $this->emachScheduleForUser($userId);
        if (!$schedule) {
            return array_map(function ($suggestion) {
                $suggestion['status'] = 'Define tu horario semanal en EMACH > Horario.';

                return $suggestion;
            }, $suggestions);
        }

        $marks = $this->emachExitMarksFromSession();
        if (!$marks) {
            return array_map(function ($suggestion) {
                $suggestion['status'] = 'Consulta tus marcaciones en EMACH antes de calcular.';

                return $suggestion;
            }, $suggestions);
        }

        foreach (array_keys($suggestions) as $dateKey) {
            $date = DateTime::createFromFormat('Y-m-d', $dateKey);
            if (!$date) {
                continue;
            }
            $weekday = (int) $date->format('N');
            $configured = $schedule[$weekday] ?? null;
            if (!$configured || empty($configured['activo'])) {
                $suggestions[$dateKey]['status'] = 'Ese dia no tiene jornada activa en tu horario EMACH.';
                continue;
            }
            $scheduledExit = $this->emachMinutesFromTime($configured['salida'] ?? '');
            if ($scheduledExit === null) {
                $suggestions[$dateKey]['status'] = 'Tu horario EMACH no tiene hora de salida para ese dia.';
                continue;
            }
            $actualExit = $marks[$dateKey]['exit'] ?? null;
            if ($actualExit === null) {
                $suggestions[$dateKey]['status'] = 'No encontre una marcacion de salida EMACH para esa fecha.';
                continue;
            }
            $extraMinutes = $actualExit - $scheduledExit;
            if ($extraMinutes <= 0) {
                $suggestions[$dateKey]['status'] = 'La salida EMACH no supera tu horario de salida.';
                continue;
            }
            $suggestions[$dateKey] = [
                'ok' => true,
                'hora_inicio' => $this->emachClockFromMinutes($scheduledExit),
                'hora_fin' => $this->emachClockFromMinutes($actualExit),
                'total' => $this->hhmm($extraMinutes),
                'status' => 'Calculado con horario EMACH y marcacion de salida.',
            ];
        }

        return $suggestions;
    }
}
