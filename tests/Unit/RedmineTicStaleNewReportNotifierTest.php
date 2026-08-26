<?php

namespace Tests\Unit;

use RedmineTic\Services\StaleNewReportNotifier;
use Tests\TestCase;

final class RedmineTicStaleNewReportNotifierTest extends TestCase
{
    public function test_remote_query_filters_new_issues_by_assignee_and_age(): void
    {
        $repository = file_get_contents(dirname(__DIR__, 2).'/RedmineTic/Repositories/RedmineDataRepository.php');

        $this->assertIsString($repository);
        $this->assertStringContainsString('function staleNewIssuesForAssignee(', $repository);
        $this->assertStringContainsString("'assigned_to_id' => \$assigneeId", $repository);
        $this->assertStringContainsString("'status_id' => (string) \$newStatusId", $repository);
        $this->assertStringContainsString("'created_on' => '<='.\$cutoff->format('Y-m-d')", $repository);
    }

    public function test_notification_reports_the_count_and_threshold(): void
    {
        $message = app(StaleNewReportNotifier::class)->notificationMessage('Ana Pérez', 3, 2);

        $this->assertStringContainsString('Hola Ana Pérez.', $message);
        $this->assertStringContainsString('Tienes 3 reportes sin finalizar.', $message);
        $this->assertStringContainsString('Estado Redmine: Nueva', $message);
        $this->assertStringContainsString('más de 2 días', $message);
    }

    public function test_configuration_and_telegram_listener_expose_the_daily_report(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/RedmineTic/views/native-sections/config.blade.php');
        $listener = file_get_contents($root.'/telegram/bin/listen.php');
        $schedule = file_get_contents($root.'/app/Console/Kernel.php');

        $this->assertStringContainsString("'informes' => ['label' => 'Informes'", $view);
        $this->assertStringContainsString('name="informes_nuevos_habilitado"', $view);
        $this->assertStringContainsString('name="informes_nuevos_dias"', $view);
        $this->assertStringContainsString('telegram_run_tic_daily_reports', $listener);
        $this->assertStringContainsString("redmine:notify-stale-new')->dailyAt('09:00')", $schedule);
    }
}
