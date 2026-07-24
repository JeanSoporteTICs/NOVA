<?php

namespace Tests\Unit;

use RedmineTic\Support\DateSupport;
use Tests\TestCase;

/**
 * ETAPA B / Lote B6.3 — direct unit coverage of the pure date/time utilities
 * extracted verbatim from RedmineDataRepository into DateSupport. No DB,
 * cache, session or network access.
 */
class DateSupportTest extends TestCase
{
    public function test_parse_date_formats_valid_input_and_returns_empty_on_failure(): void
    {
        $this->assertSame('2026-06-01', DateSupport::parseDate('2026-06-01 10:00:00'));
        $this->assertSame('', DateSupport::parseDate(''));
        $this->assertSame('', DateSupport::parseDate('not-a-date'));
    }

    public function test_parse_time_normalizes_hh_mm_and_full_formats(): void
    {
        $this->assertSame('09:05:00', DateSupport::parseTime('9:5'));
        $this->assertSame('23:59:59', DateSupport::parseTime('23:59:59'));
        $this->assertNull(DateSupport::parseTime(''));
        $this->assertNull(DateSupport::parseTime('not-a-time'));
    }

    public function test_database_date_and_time_return_empty_for_null_or_empty(): void
    {
        $this->assertSame('', DateSupport::databaseDate(null));
        $this->assertSame('', DateSupport::databaseDate(''));
        $this->assertSame('2026-06-01', DateSupport::databaseDate('2026-06-01'));

        $this->assertSame('', DateSupport::databaseTime(null));
        $this->assertSame('10:30', DateSupport::databaseTime('10:30:00'));
    }

    public function test_parse_flexible_date_accepts_multiple_formats(): void
    {
        $this->assertSame('2026-06-01', DateSupport::parseFlexibleDate('2026-06-01')?->format('Y-m-d'));
        $this->assertSame('2026-06-01', DateSupport::parseFlexibleDate('01-06-2026')?->format('Y-m-d'));
        $this->assertSame('2026-06-01', DateSupport::parseFlexibleDate('01/06/2026')?->format('Y-m-d'));
        $this->assertSame('2026-06-01', DateSupport::parseFlexibleDate('2026/06/01')?->format('Y-m-d'));
        $this->assertNull(DateSupport::parseFlexibleDate(''));
        $this->assertNull(DateSupport::parseFlexibleDate('garbage'));
    }

    public function test_normalize_date_key_returns_iso_date_or_empty(): void
    {
        $this->assertSame('2026-06-01', DateSupport::normalizeDateKey('01-06-2026'));
        $this->assertSame('', DateSupport::normalizeDateKey('garbage'));
    }

    public function test_timestamp_from_value_handles_numeric_string_and_date_string(): void
    {
        $this->assertSame(1234, DateSupport::timestampFromValue(1234));
        $this->assertSame(1234, DateSupport::timestampFromValue('1234'));
        $this->assertNull(DateSupport::timestampFromValue(''));
        $this->assertNull(DateSupport::timestampFromValue('garbage'));
        $this->assertIsInt(DateSupport::timestampFromValue('2026-06-01 10:00:00'));
    }

    public function test_minutes_diff_computes_positive_difference_only(): void
    {
        $this->assertSame(90, DateSupport::minutesDiff('09:00', '10:30'));
        $this->assertNull(DateSupport::minutesDiff('10:30', '09:00'));
        $this->assertNull(DateSupport::minutesDiff('', '10:00'));
    }

    public function test_format_minutes_pads_hours_and_minutes(): void
    {
        $this->assertSame('01:05', DateSupport::formatMinutes(65));
        $this->assertSame('', DateSupport::formatMinutes(null));
    }

    public function test_minutes_from_clock_and_clock_from_minutes_round_trip(): void
    {
        $this->assertSame(605, DateSupport::minutesFromClock('10:05'));
        $this->assertNull(DateSupport::minutesFromClock('25:00'));
        $this->assertNull(DateSupport::minutesFromClock('garbage'));
        $this->assertSame('10:05', DateSupport::clockFromMinutes(605));
        $this->assertSame('23:59', DateSupport::clockFromMinutes(9999));
    }

    public function test_month_options_returns_twelve_spanish_month_names(): void
    {
        $options = DateSupport::monthOptions();
        $this->assertCount(12, $options);
        $this->assertSame('Enero', $options[1]);
        $this->assertSame('Diciembre', $options[12]);
    }

    public function test_selected_month_validates_range_and_defaults(): void
    {
        $this->assertSame('6', DateSupport::selectedMonth('6'));
        $this->assertSame('', DateSupport::selectedMonth('13'));
        $this->assertSame('', DateSupport::selectedMonth('0'));
        $this->assertSame('', DateSupport::selectedMonth(null, true));
    }

    public function test_selected_year_validates_four_digits(): void
    {
        $this->assertSame('2026', DateSupport::selectedYear('2026'));
        $this->assertSame('', DateSupport::selectedYear('26'));
        $this->assertSame('', DateSupport::selectedYear(null, true));
    }

    public function test_webhook_timestamp_parses_given_date_and_time(): void
    {
        $timestamp = DateSupport::webhookTimestamp('2026-06-01', '10:00');
        $this->assertSame('2026-06-01 10:00', (new \DateTimeImmutable("@$timestamp"))->setTimezone(new \DateTimeZone('America/Santiago'))->format('Y-m-d H:i'));
    }

    public function test_format_until_formats_iso_datetime_or_returns_original(): void
    {
        $this->assertSame('01-06-2026 10:00', DateSupport::formatUntil('2026-06-01T10:00'));
        $this->assertSame('', DateSupport::formatUntil(''));
        $this->assertSame('garbage', DateSupport::formatUntil('garbage'));
    }

    public function test_statistics_date_range_defaults_to_current_month_and_swaps_reversed_range(): void
    {
        [$from, $to] = DateSupport::statisticsDateRange(['desde' => '30-06-2026', 'hasta' => '01-06-2026']);
        $this->assertSame('2026-06-01', $from->format('Y-m-d'));
        $this->assertSame('2026-06-30', $to->format('Y-m-d'));

        [$defaultFrom, $defaultTo] = DateSupport::statisticsDateRange([]);
        $this->assertNotNull($defaultFrom);
        $this->assertNotNull($defaultTo);
    }

    public function test_filter_reports_by_date_range_excludes_out_of_range_and_undated_reports(): void
    {
        $reports = [
            ['fecha_inicio' => '2026-06-05'],
            ['fecha_inicio' => '2026-07-05'],
            ['fecha_inicio' => ''],
        ];
        $from = new \DateTimeImmutable('2026-06-01');
        $to = new \DateTimeImmutable('2026-06-30');

        $filtered = DateSupport::filterReportsByDateRange($reports, $from, $to);

        $this->assertCount(1, $filtered);
        $this->assertSame('2026-06-05', $filtered[0]['fecha_inicio']);
    }

    public function test_filter_reports_by_date_range_returns_all_when_no_bounds_given(): void
    {
        $reports = [['fecha_inicio' => '2026-06-05'], ['fecha_inicio' => '']];
        $this->assertSame($reports, DateSupport::filterReportsByDateRange($reports, null, null));
    }
}
