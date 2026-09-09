<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionHoursExtraFields;
use App\Modulos\RedmineMantencion\Services\MantencionPendientesService;
use PHPUnit\Framework\TestCase;

class MantencionHoursExtraFieldsTest extends TestCase
{
    public function test_enabled_empty_estimate_defaults_to_one_hour(): void
    {
        $result = MantencionHoursExtraFields::normalize(['hora_extra' => '1', 'tiempo_estimado' => '']);
        self::assertSame('1', $result['tiempo_estimado']);
    }

    public function test_user_estimate_is_preserved(): void
    {
        $result = MantencionHoursExtraFields::normalize(['hora_extra' => '1', 'tiempo_estimado' => '2.5']);
        self::assertSame('2.5', $result['tiempo_estimado']);
    }

    public function test_disabling_hours_extra_clears_estimate(): void
    {
        $result = MantencionHoursExtraFields::normalize(['hora_extra' => '0', 'tiempo_estimado' => '2.5']);
        self::assertSame('', $result['tiempo_estimado']);
    }

    public function test_manual_record_applies_rule_before_persistence(): void
    {
        require_once dirname(__DIR__, 2).'/RedmineMantencion/controllers/dashboard.php';
        $service = new MantencionPendientesService;
        foreach ([['1', '', '1'], ['1', '2.5', '2.5'], ['0', '2.5', '']] as [$enabled, $hours, $expected]) {
            $record = $service->buildRecord(['hora_extra' => $enabled, 'tiempo_estimado' => $hours], [], []);
            self::assertSame($expected, $record['tiempo_estimado']);
            self::assertSame($enabled, $record['hora_extra']);
        }
    }
}
