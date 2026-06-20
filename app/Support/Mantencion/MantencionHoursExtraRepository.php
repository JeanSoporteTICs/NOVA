<?php

namespace App\Support\Mantencion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MantencionHoursExtraRepository
{
    private const MODULE_KEY = 'redmine-mantencion';

    private ?int $moduleId = null;
    private bool $moduleIdResolved = false;

    public function __construct(private readonly MantencionReportRepository $reports)
    {
    }

    public function tableReady(): bool
    {
        try {
            return $this->reports->tableReady()
                && Schema::hasTable('modulos_nova')
                && Schema::hasTable('redmine_mantencion_horas_extra_grupos')
                && Schema::hasTable('redmine_mantencion_horas_extra_reportes');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function groups(): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            $rows = DB::table('redmine_mantencion_horas_extra_grupos as g')
                ->join('redmine_mantencion_horas_extra_reportes as hr', 'hr.grupo_id', '=', 'g.id')
                ->join('redmine_mantencion_reportes as r', 'r.id', '=', 'hr.reporte_id')
                ->leftJoin('categorias as c', 'c.id', '=', 'r.categoria_id')
                ->where('g.modulo_id', $moduleId)
                ->where('r.modulo_id', $moduleId)
                ->where('r.hora_extra', 1)
                ->orderByDesc('g.fecha')
                ->orderByDesc('r.fecha_reporte')
                ->orderByDesc('r.id')
                ->get([
                    'g.id as grupo_id',
                    'g.fecha as grupo_fecha',
                    'g.hora_inicio as grupo_hora_inicio',
                    'g.hora_fin as grupo_hora_fin',
                    'r.*',
                    'c.nombre as categoria_nombre',
                ]);

            $groups = [];
            foreach ($rows as $row) {
                $key = (string) $row->grupo_id;
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'fecha' => $this->formatDate($row->grupo_fecha ?? null),
                        'hora_inicio' => $this->formatTime($row->grupo_hora_inicio ?? null),
                        'hora_fin' => $this->formatTime($row->grupo_hora_fin ?? null),
                        'reports' => [],
                    ];
                }
                $message = $this->reports->rowToMessage($row);
                $message['hora_extra'] = '1';
                $message['_fuente'] = 'horas_extra';
                $groups[$key]['reports'][] = $message;
            }

            return array_values($groups);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->groups() as $group) {
            foreach (($group['reports'] ?? []) as $report) {
                if (is_array($report)) {
                    $report['fecha'] = $report['fecha'] ?? ($group['fecha'] ?? '');
                    $report['_fuente'] = 'horas_extra';
                    $messages[] = $report;
                }
            }
        }

        return $messages;
    }

    /** @param array<string,mixed> $message */
    public function syncMessage(array $message): void
    {
        if (! $this->tableReady() || ! $this->messageHasHoursExtra($message)) {
            return;
        }

        $moduleId = $this->resolveModuleId();
        $reportId = $this->reportIdForMessage($message);
        if ($moduleId === null || $reportId === null) {
            return;
        }

        $fecha = $this->dateFromMessage($message);
        if ($fecha === null) {
            return;
        }

        $horaInicio = $this->timeFromMessage($message, ['hora_inicio', 'hora']);
        $horaFin = $this->timeFromMessage($message, ['hora_fin', 'hora']);

        try {
            $grupoId = DB::table('redmine_mantencion_horas_extra_grupos')
                ->where('modulo_id', $moduleId)
                ->where('fecha', $fecha)
                ->value('id');

            if ($grupoId === null) {
                $grupoId = DB::table('redmine_mantencion_horas_extra_grupos')->insertGetId([
                    'modulo_id' => $moduleId,
                    'fecha' => $fecha,
                    'hora_inicio' => $horaInicio,
                    'hora_fin' => $horaFin,
                    'creado_at' => now(),
                    'actualizado_at' => now(),
                ]);
            } else {
                $values = ['actualizado_at' => now()];
                if ($horaInicio !== null) {
                    $values['hora_inicio'] = $horaInicio;
                }
                if ($horaFin !== null) {
                    $values['hora_fin'] = $horaFin;
                }
                DB::table('redmine_mantencion_horas_extra_grupos')->where('id', $grupoId)->update($values);
            }

            DB::table('redmine_mantencion_horas_extra_reportes')->updateOrInsert(
                ['grupo_id' => (int) $grupoId, 'reporte_id' => $reportId],
                ['actualizado_at' => now()],
            );
        } catch (\Throwable) {
        }
    }

    public function detachMessageId(string $messageId): bool
    {
        $messageId = trim($messageId);
        if (! $this->tableReady() || $messageId === '') {
            return false;
        }

        $reportId = $this->reportIdForMessage(['id' => $messageId, 'fuente_id' => $messageId]);
        if ($reportId === null) {
            return false;
        }

        try {
            $deleted = DB::table('redmine_mantencion_horas_extra_reportes')
                ->where('reporte_id', $reportId)
                ->delete();

            DB::table('redmine_mantencion_reportes')
                ->where('id', $reportId)
                ->update(['hora_extra' => 0, 'actualizado_at' => now()]);

            $this->deleteEmptyGroups();

            return $deleted > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function updateGroupHours(string $fecha, string $horaInicio, string $horaFin): bool
    {
        if (! $this->tableReady()) {
            return false;
        }

        $moduleId = $this->resolveModuleId();
        $fecha = $this->normalizeDate($fecha) ?? '';
        if ($moduleId === null || $fecha === '') {
            return false;
        }

        $values = ['actualizado_at' => now()];
        $inicio = $this->normalizeTime($horaInicio);
        $fin = $this->normalizeTime($horaFin);
        if ($inicio !== null) {
            $values['hora_inicio'] = $inicio;
        }
        if ($fin !== null) {
            $values['hora_fin'] = $fin;
        }
        if (count($values) === 1) {
            return false;
        }

        try {
            return DB::table('redmine_mantencion_horas_extra_grupos')
                ->where('modulo_id', $moduleId)
                ->where('fecha', $fecha)
                ->update($values) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function reportIdForMessage(array $message): ?int
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return null;
        }

        $fuente = trim((string) ($message['fuente'] ?? ''));
        $fuenteId = trim((string) ($message['fuente_id'] ?? $message['id'] ?? ''));
        if ($fuenteId === '') {
            return null;
        }

        try {
            $query = DB::table('redmine_mantencion_reportes')
                ->where('modulo_id', $moduleId)
                ->where('fuente_id', $fuenteId);
            if ($fuente !== '') {
                $query->where('fuente', $fuente);
            }

            $id = $query->value('id');
            return $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateFromMessage(array $message): ?string
    {
        foreach (['fecha_inicio', 'fecha', 'core_fecha_creacion'] as $key) {
            $date = $this->normalizeDate((string) ($message[$key] ?? ''));
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    /** @param array<int,string> $keys */
    private function timeFromMessage(array $message, array $keys): ?string
    {
        foreach ($keys as $key) {
            $time = $this->normalizeTime((string) ($message[$key] ?? ''));
            if ($time !== null) {
                return $time;
            }
        }

        return null;
    }

    private function messageHasHoursExtra(array $message): bool
    {
        return in_array(strtolower(trim((string) ($message['hora_extra'] ?? ''))), ['1', 'si', 'sí', 's', 'true', 'yes'], true);
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, substr($value, 0, strlen($format)))->toDateString();
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['H:i:s', 'H:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, substr($value, 0, strlen($format)))->format('H:i:s');
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function formatDate(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return trim((string) $value);
        }
    }

    private function formatTime(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        try {
            return Carbon::parse((string) $value)->format('H:i');
        } catch (\Throwable) {
            return trim((string) $value);
        }
    }

    private function deleteEmptyGroups(): void
    {
        DB::table('redmine_mantencion_horas_extra_grupos')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('redmine_mantencion_horas_extra_reportes as hr')
                    ->whereColumn('hr.grupo_id', 'redmine_mantencion_horas_extra_grupos.id');
            })
            ->delete();
    }

    private function resolveModuleId(): ?int
    {
        if ($this->moduleIdResolved) {
            return $this->moduleId;
        }

        $this->moduleIdResolved = true;

        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', self::MODULE_KEY)->value('id');
            $this->moduleId = $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            $this->moduleId = null;
        }

        return $this->moduleId;
    }
}
