<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionEstadisticasService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class MantencionStatisticsFilterTest extends TestCase
{
    public function test_initial_view_defaults_to_current_chile_date(): void
    {
        $service = new MantencionEstadisticasService;
        $now = new DateTimeImmutable('2026-08-27 23:30:00', new DateTimeZone('America/Santiago'));

        $filters = $service->resolveFilters([], [], $now);

        self::assertSame('2026-08-27', $filters['desde']);
        self::assertSame('2026-08-27', $filters['hasta']);
    }

    public function test_explicit_empty_dates_still_allow_clearing_the_range(): void
    {
        $service = new MantencionEstadisticasService;
        $now = new DateTimeImmutable('2026-08-27 23:30:00', new DateTimeZone('America/Santiago'));

        $filters = $service->resolveFilters(['desde' => '', 'hasta' => ''], [], $now);

        self::assertSame('', $filters['desde']);
        self::assertSame('', $filters['hasta']);
    }
}
