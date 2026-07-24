<?php

namespace RedmineTic\Support;

/**
 * ETAPA B / Lote B6.3 — pure date/time utilities extracted verbatim from
 * RedmineDataRepository's private helper cluster. No DB, cache, session or
 * network access — every method here is a deterministic transformation of
 * its inputs (aside from now()/current-timezone reads used as an explicit
 * "today" default, matching the original behavior exactly).
 */
final class DateSupport
{
    public static function parseDate(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception) {
            return '';
        }
    }

    public static function parseTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $value)) {
            $parts = explode(':', $value);
            $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
            $minute = max(0, min(59, (int) ($parts[1] ?? 0)));
            $second = max(0, min(59, (int) ($parts[2] ?? 0)));

            return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        }

        try {
            return (new \DateTimeImmutable($value))->format('H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    public static function databaseDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::parseDate($value);
    }

    public static function databaseTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $time = self::parseTime($value);

        return $time !== null ? substr($time, 0, 5) : '';
    }

    public static function parseFlexibleDate(string $date): ?\DateTimeImmutable
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $date, new \DateTimeZone('America/Santiago'));
            if ($parsed) {
                return $parsed;
            }
        }

        return null;
    }

    public static function normalizeDateKey(string $date): string
    {
        $parsed = self::parseFlexibleDate($date);

        return $parsed ? $parsed->format('Y-m-d') : '';
    }

    public static function timestampFromValue(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    public static function minutesDiff(string $start, string $end): ?int
    {
        if (trim($start) === '' || trim($end) === '') {
            return null;
        }
        $startTime = \DateTimeImmutable::createFromFormat('H:i', substr($start, 0, 5)) ?: \DateTimeImmutable::createFromFormat('H:i:s', $start);
        $endTime = \DateTimeImmutable::createFromFormat('H:i', substr($end, 0, 5)) ?: \DateTimeImmutable::createFromFormat('H:i:s', $end);
        if (!$startTime || !$endTime || $endTime <= $startTime) {
            return null;
        }

        return (int) round(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60);
    }

    public static function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        return str_pad((string) floor($minutes / 60), 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    public static function minutesFromClock(string $value): ?int
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

    public static function clockFromMinutes(int $minutes): string
    {
        $minutes = max(0, min(1439, $minutes));

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @return array<int,string>
     */
    public static function monthOptions(): array
    {
        return [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];
    }

    public static function selectedMonth(mixed $value, bool $hasExplicitFilters = false): string
    {
        if ($value === null) {
            return $hasExplicitFilters ? '' : now('America/Santiago')->format('n');
        }
        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value >= 1 && (int) $value <= 12 ? (string) (int) $value : '';
    }

    public static function selectedYear(mixed $value, bool $hasExplicitFilters = false): string
    {
        if ($value === null) {
            return $hasExplicitFilters ? '' : now('America/Santiago')->format('Y');
        }
        $value = trim((string) $value);

        return preg_match('/^\d{4}$/', $value) ? $value : '';
    }

    public static function webhookTimestamp(string $date, string $time): int
    {
        $date = $date !== '' ? $date : now('America/Santiago')->format('Y-m-d');
        $time = $time !== '' ? $time : now('America/Santiago')->format('H:i');
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . substr($time, 0, 5), new \DateTimeZone('America/Santiago'));

        return $parsed ? $parsed->getTimestamp() : now('America/Santiago')->timestamp;
    }

    public static function formatUntil(string $until): string
    {
        if ($until === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $until, new \DateTimeZone('America/Santiago'));

        return $date ? $date->format('d-m-Y H:i') : $until;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:?\DateTimeImmutable,1:?\DateTimeImmutable}
     */
    public static function statisticsDateRange(array $filters): array
    {
        $from = self::parseFlexibleDate((string) ($filters['desde'] ?? ''));
        $to = self::parseFlexibleDate((string) ($filters['hasta'] ?? ''));
        if (!$from && !$to) {
            $today = now('America/Santiago');
            $from = new \DateTimeImmutable($today->copy()->startOfMonth()->format('Y-m-d'), new \DateTimeZone('America/Santiago'));
            $to = new \DateTimeImmutable($today->copy()->endOfMonth()->format('Y-m-d'), new \DateTimeZone('America/Santiago'));
        }

        if ($from && $to && $from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     * @return array<int,array<string,mixed>>
     */
    public static function filterReportsByDateRange(array $reports, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        if (!$from && !$to) {
            return $reports;
        }

        return array_values(array_filter($reports, static function (array $report) use ($from, $to): bool {
            $date = self::parseFlexibleDate((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? $report['start_date'] ?? $report['due_date'] ?? $report['created_on'] ?? ''));
            if (!$date) {
                return false;
            }
            if ($from && $date < $from) {
                return false;
            }
            if ($to && $date > $to) {
                return false;
            }

            return true;
        }));
    }
}
