<?php

namespace Tests\Unit;

use App\Support\Reports\AutomaticReportSchedule;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AutomaticReportScheduleTest extends TestCase
{
    public function test_defaults_to_monday_at_nine(): void
    {
        self::assertSame(['day' => '1', 'time' => '09:00'], AutomaticReportSchedule::settings([]));
    }

    public function test_schedule_becomes_due_only_on_selected_day_and_time(): void
    {
        $config = ['informes_nuevos_dia' => '3', 'informes_nuevos_hora' => '14:30'];
        $timezone = new DateTimeZone(AutomaticReportSchedule::TIMEZONE);

        self::assertFalse(AutomaticReportSchedule::isDue($config, new DateTimeImmutable('2026-08-26 14:29', $timezone)));
        self::assertTrue(AutomaticReportSchedule::isDue($config, new DateTimeImmutable('2026-08-26 14:30', $timezone)));
        self::assertFalse(AutomaticReportSchedule::isDue($config, new DateTimeImmutable('2026-08-27 14:30', $timezone)));
    }

    public function test_previous_week_is_always_monday_through_sunday(): void
    {
        $timezone = new DateTimeZone(AutomaticReportSchedule::TIMEZONE);
        $window = AutomaticReportSchedule::previousWeek(new DateTimeImmutable('2026-08-26 12:00', $timezone));

        self::assertSame('17/08/2026 al 23/08/2026', $window['label']);
        self::assertSame('2026-08-17', $window['start']->setTimezone($timezone)->format('Y-m-d'));
        self::assertSame('2026-08-24', $window['end']->setTimezone($timezone)->format('Y-m-d'));
    }

    public function test_last_seven_days_preserves_the_current_time(): void
    {
        $timezone = new DateTimeZone(AutomaticReportSchedule::TIMEZONE);
        $window = AutomaticReportSchedule::lastSevenDays(new DateTimeImmutable('2026-08-26 14:30:45', $timezone));

        self::assertSame('19/08/2026 14:30 al 26/08/2026 14:30', $window['label']);
        self::assertSame('2026-08-19 14:30:45', $window['start']->setTimezone($timezone)->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-26 14:30:45', $window['end']->setTimezone($timezone)->format('Y-m-d H:i:s'));
    }
}
