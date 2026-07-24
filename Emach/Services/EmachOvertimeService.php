<?php

namespace App\Modulos\Emach\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pure calculation/business logic for EMACH overtime suggestions — schedule
 * lookup, clock-mark matching, minute arithmetic. Ported from 7 private
 * methods that lived in Nova\Controllers\UserIntegrationController
 * (Fase 9 lote 1 of the 2026-07 standardization program — see
 * .claude/knowledge/external-clients-architecture.md). The controller keeps
 * orchestrating request/response only; transport to EMACH itself stays in
 * EmachClientService/EmachScraperClient, untouched by this lote.
 *
 * No request/session/UI here — scheduleForUser() keeps the same
 * DB::table('emach_horarios_usuario') query the controller already had;
 * everything else is pure computation over data passed in by the caller.
 */
final class EmachOvertimeService
{
    /**
     * @return array<int,array{activo:bool,salida:string}>
     */
    public function scheduleForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            if (!Schema::hasTable('emach_horarios_usuario')) {
                return [];
            }

            $schedule = [];
            $rows = DB::table('emach_horarios_usuario')->where('usuario_id', $userId)->get();
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
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int,array<int|string,mixed>> $rows
     */
    public function exitForDate(array $rows, string $dateKey): ?int
    {
        $minutes = $this->minutesForDate($rows, $dateKey, 'SALIDA');

        return $minutes === [] ? null : max($minutes);
    }

    /**
     * Primera marcacion de entrada del dia (usada cuando el dia no tiene
     * jornada activa configurada: todo lo marcado se considera hora extra).
     *
     * @param array<int,array<int|string,mixed>> $rows
     */
    public function entryForDate(array $rows, string $dateKey): ?int
    {
        $minutes = $this->minutesForDate($rows, $dateKey, 'ENTRADA');

        return $minutes === [] ? null : min($minutes);
    }

    public function minutesFromClock(string $value): ?int
    {
        $value = trim($value);
        if (!preg_match('/^(\d{1,2}):([0-5]\d)(?::[0-5]\d)?$/', $value, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        if ($hour < 0 || $hour > 23) {
            return null;
        }

        return ($hour * 60) + (int) $matches[2];
    }

    public function clockFromMinutes(int $minutes): string
    {
        $minutes = max(0, min(1439, $minutes));

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public function formatMinutes(int $minutes): string
    {
        return str_pad((string) floor($minutes / 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<int,array<int|string,mixed>> $rows
     * @return array<int,int>
     */
    private function minutesForDate(array $rows, string $dateKey, string $tipo): array
    {
        $minutes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = strtoupper(trim((string) ($row[5] ?? data_get($row, 'tipo', ''))));
            if ($type !== $tipo) {
                continue;
            }

            $date = $this->parseDate((string) ($row[3] ?? data_get($row, 'fecha', '')));
            $minute = $this->minutesFromClock((string) ($row[4] ?? data_get($row, 'marcas', data_get($row, 'marca', ''))));
            if (!$date || $date->format('Y-m-d') !== $dateKey || $minute === null) {
                continue;
            }

            $minutes[] = $minute;
        }

        return $minutes;
    }

    /**
     * Private, self-contained copy of the same date parsing
     * UserIntegrationController::parseDate() does — kept here so this
     * service doesn't depend back on the controller. Not one of the 7
     * candidate functions for this lote (it's a generic date parser, not
     * EMACH-specific), so the controller's own copy was left untouched.
     */
    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('America/Santiago'));
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        return null;
    }
}
