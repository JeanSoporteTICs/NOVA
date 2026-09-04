<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedmineTic\Repositories\RedmineDataRepository;
use RedmineTic\Repositories\RedmineReportRepository;

final class RedmineTicHoursExtraEstimatedTimeTest extends TestCase
{
    public function test_normalization_returns_one_hour_when_enabled_and_no_value_when_disabled(): void
    {
        $repository = new RedmineDataRepository;
        $reflection = new \ReflectionClass($repository);
        $reportRepository = $reflection->getProperty('reportRepoInst');
        $reportRepository->setValue($repository, new RedmineReportRepository('redmine_tic', 'Redmine TIC'));
        $normalize = $reflection->getMethod('normalizeHoursExtraFields');

        self::assertSame(
            ['hora_extra' => 'SI', 'tiempo_estimado' => '3.5'],
            $normalize->invoke($repository, ['hora_extra' => 'SI', 'tiempo_estimado' => '3.5'])
        );
        self::assertSame(
            ['hora_extra' => 'SI', 'tiempo_estimado' => '1'],
            $normalize->invoke($repository, ['hora_extra' => 'SI', 'tiempo_estimado' => ''])
        );
        self::assertSame(
            ['hora_extra' => 'NO', 'tiempo_estimado' => ''],
            $normalize->invoke($repository, ['hora_extra' => 'NO', 'tiempo_estimado' => '3.5'])
        );
        self::assertSame(
            ['hora_extra' => 'NO', 'tiempo_estimado' => ''],
            $normalize->invoke($repository, [])
        );
    }

    public function test_backend_normalizes_estimated_time_for_manual_quick_and_dashboard_updates(): void
    {
        $repository = file_get_contents(dirname(__DIR__, 2).'/RedmineTic/Repositories/RedmineDataRepository.php');

        self::assertIsString($repository);
        self::assertStringContainsString('$payload = $this->normalizeHoursExtraFields($payload);', $repository);
        self::assertStringContainsString("\$estimatedTime !== '' ? \$estimatedTime : '1'", $repository);
        self::assertMatchesRegularExpression(
            '/public function updateReport.*?array_key_exists\(\'hora_extra\'.*?normalizeHoursExtraFields/s',
            $repository
        );
        self::assertMatchesRegularExpression(
            '/public function createSimulatedReport.*?normalizeHoursExtraFields/s',
            $repository
        );
    }

    public function test_all_tic_forms_show_the_same_automatic_value(): void
    {
        $root = dirname(__DIR__, 2);
        $manual = file_get_contents($root.'/RedmineTic/views/native-sections/webhook.blade.php');
        $quick = file_get_contents($root.'/RedmineTic/views/native-sections/quick-report.blade.php');
        $quickScript = file_get_contents($root.'/public/assets/redmine-tic-quick-report.js');
        $dashboard = file_get_contents($root.'/RedmineTic/views/native-sections/dashboard.blade.php');

        self::assertIsString($manual);
        self::assertIsString($quick);
        self::assertIsString($quickScript);
        self::assertIsString($dashboard);
        self::assertStringContainsString("estimatedTime.value = '1';", $manual);
        self::assertStringContainsString(".on('change.ticManualHoursExtra', () => syncEstimatedTime(true))", $manual);
        self::assertStringContainsString("estimatedTime.value = '1';", $quickScript);
        self::assertStringContainsString("form.elements.tiempo_estimado.value = '1';", $dashboard);
        self::assertStringContainsString(".on('change.ticDashboardHoursExtra', sync)", $dashboard);
        self::assertStringContainsString("event.detail.active ? '1' : ''", $dashboard);
        self::assertStringNotContainsString('id="manual-tiempo-estimado" type="text" name="tiempo_estimado" placeholder="Ej: 1.5" readonly', $manual);
        self::assertStringNotContainsString('id="quick-tiempo" name="tiempo_estimado" maxlength="40" value="{{ $field(\'tiempo_estimado\') }}" placeholder="Ej: 1.5" readonly', $quick);
        self::assertStringNotContainsString('name="tiempo_estimado" placeholder="Ej: 1.5" readonly', $dashboard);
    }
}
