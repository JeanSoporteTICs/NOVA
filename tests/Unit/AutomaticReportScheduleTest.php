<?php

namespace Tests\Unit;

use App\Support\Reports\AutomaticReportSchedule;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AutomaticReportScheduleTest extends TestCase
{
    public function test_normalizes_range_day_and_time(): void
    {
        $settings = AutomaticReportSchedule::settings([
            'informes_nuevos_dias_desde' => 20,
            'informes_nuevos_dias_hasta' => 5,
            'informes_nuevos_dia' => '3',
            'informes_nuevos_hora' => '14:30',
        ]);

        self::assertSame(5, $settings['days_from']);
        self::assertSame(20, $settings['days_to']);
        self::assertSame('3', $settings['day']);
        self::assertSame('14:30', $settings['time']);
        self::assertSame('previous_week', $settings['period']);
    }

    public function test_weekly_schedule_becomes_due_at_configured_time(): void
    {
        $config = [
            'informes_nuevos_dia' => '3',
            'informes_nuevos_hora' => '14:30',
        ];
        $timezone = new DateTimeZone(AutomaticReportSchedule::TIMEZONE);

        self::assertFalse(AutomaticReportSchedule::isDue($config, new DateTimeImmutable('2026-08-26 14:29', $timezone)));
        self::assertTrue(AutomaticReportSchedule::isDue($config, new DateTimeImmutable('2026-08-26 14:30', $timezone)));
        self::assertFalse(AutomaticReportSchedule::isDue($config, new DateTimeImmutable('2026-08-27 14:30', $timezone)));
    }

    public function test_previous_week_window_uses_monday_through_sunday(): void
    {
        $timezone = new DateTimeZone(AutomaticReportSchedule::TIMEZONE);
        $window = AutomaticReportSchedule::reportWindow([], new DateTimeImmutable('2026-08-26 12:00', $timezone));

        self::assertSame('17/08/2026 al 23/08/2026', $window['label']);
        self::assertSame('2026-08-17', $window['start']->setTimezone($timezone)->format('Y-m-d'));
        self::assertSame('2026-08-24', $window['end']->setTimezone($timezone)->format('Y-m-d'));
    }

    public function test_legacy_configuration_defaults_to_monday_at_nine(): void
    {
        $settings = AutomaticReportSchedule::settings(['informes_nuevos_dias' => 2]);

        self::assertSame(2, $settings['days_from']);
        self::assertSame(365, $settings['days_to']);
        self::assertSame('1', $settings['day']);
        self::assertSame('09:00', $settings['time']);
        self::assertSame('previous_week', $settings['period']);
    }
}
