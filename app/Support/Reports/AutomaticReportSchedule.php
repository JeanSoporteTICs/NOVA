<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

final class AutomaticReportSchedule
{
    public const TIMEZONE = 'America/Santiago';

    /**
     * @param  array<string, mixed>  $config
     * @return array{days_from:int,days_to:int,day:string,time:string,period:string}
     */
    public static function settings(array $config): array
    {
        $legacyDays = self::boundedDays($config['informes_nuevos_dias'] ?? 2);
        $daysFrom = self::boundedDays($config['informes_nuevos_dias_desde'] ?? $legacyDays);
        $daysTo = self::boundedDays($config['informes_nuevos_dias_hasta'] ?? 365);

        if ($daysFrom > $daysTo) {
            [$daysFrom, $daysTo] = [$daysTo, $daysFrom];
        }

        $day = strtolower(trim((string) ($config['informes_nuevos_dia'] ?? '1')));
        if ($day !== 'daily' && preg_match('/^[1-7]$/', $day) !== 1) {
            $day = '1';
        }

        $time = trim((string) ($config['informes_nuevos_hora'] ?? '09:00'));
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            $time = '09:00';
        }

        $period = strtolower(trim((string) ($config['informes_nuevos_periodo'] ?? 'previous_week')));
        if (! in_array($period, ['previous_week', 'age_range'], true)) {
            $period = 'previous_week';
        }

        return [
            'days_from' => $daysFrom,
            'days_to' => $daysTo,
            'day' => $day,
            'time' => $time,
            'period' => $period,
        ];
    }

    /** @param array<string, mixed> $config */
    public static function isDue(array $config, DateTimeInterface $now): bool
    {
        $settings = self::settings($config);
        if ($settings['day'] !== 'daily' && $settings['day'] !== $now->format('N')) {
            return false;
        }

        return $now->format('H:i') >= $settings['time'];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{start:CarbonImmutable,end:CarbonImmutable,label:string}
     */
    public static function reportWindow(array $config, DateTimeInterface $now): array
    {
        $settings = self::settings($config);
        $localNow = CarbonImmutable::instance($now)->setTimezone(self::TIMEZONE);

        if ($settings['period'] === 'previous_week') {
            $end = $localNow->startOfWeek(CarbonInterface::MONDAY);
            $start = $end->subWeek();

            return [
                'start' => $start->utc(),
                'end' => $end->utc(),
                'label' => $start->format('d/m/Y').' al '.$end->subDay()->format('d/m/Y'),
            ];
        }

        return [
            'start' => $localNow->subDays($settings['days_to'] + 1)->utc(),
            'end' => $localNow->subDays($settings['days_from'])->utc(),
            'label' => $settings['days_from'].' a '.$settings['days_to'].' días de antigüedad',
        ];
    }

    private static function boundedDays(mixed $value): int
    {
        return max(1, min(365, (int) $value));
    }
}
