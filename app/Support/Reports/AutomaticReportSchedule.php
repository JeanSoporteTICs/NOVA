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
     * @return array{day:string,time:string}
     */
    public static function settings(array $config): array
    {
        $day = trim((string) ($config['informes_nuevos_dia'] ?? '1'));
        if (preg_match('/^[1-7]$/', $day) !== 1) {
            $day = '1';
        }

        $time = trim((string) ($config['informes_nuevos_hora'] ?? '09:00'));
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            $time = '09:00';
        }

        return ['day' => $day, 'time' => $time];
    }

    /** @param array<string, mixed> $config */
    public static function isDue(array $config, DateTimeInterface $now): bool
    {
        $settings = self::settings($config);

        return $settings['day'] === $now->format('N')
            && $now->format('H:i') >= $settings['time'];
    }

    /** @return array{start:CarbonImmutable,end:CarbonImmutable,label:string} */
    public static function previousWeek(DateTimeInterface $now): array
    {
        $weekEnd = CarbonImmutable::instance($now)
            ->setTimezone(self::TIMEZONE)
            ->startOfWeek(CarbonInterface::MONDAY);
        $weekStart = $weekEnd->subWeek();

        return [
            'start' => $weekStart->utc(),
            'end' => $weekEnd->utc(),
            'label' => $weekStart->format('d/m/Y').' al '.$weekEnd->subDay()->format('d/m/Y'),
        ];
    }

    /** @return array{start:CarbonImmutable,end:CarbonImmutable,label:string} */
    public static function lastSevenDays(DateTimeInterface $now): array
    {
        $end = CarbonImmutable::instance($now)->setTimezone(self::TIMEZONE);
        $start = $end->subDays(7);

        return [
            'start' => $start->utc(),
            'end' => $end->utc(),
            'label' => $start->format('d/m/Y H:i').' al '.$end->format('d/m/Y H:i'),
        ];
    }
}
