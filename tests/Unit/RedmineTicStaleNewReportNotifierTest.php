<?php

namespace Tests\Unit;

use RedmineTic\Services\StaleNewReportNotifier;
use Tests\TestCase;

final class RedmineTicStaleNewReportNotifierTest extends TestCase
{
    public function test_database_query_filters_new_issues_by_assignee_and_age(): void
    {
        $root = dirname(__DIR__, 2);
        $repository = file_get_contents($root.'/RedmineTic/Repositories/RedmineReportRepository.php');
        $notifier = file_get_contents($root.'/RedmineTic/Services/StaleNewReportNotifier.php');

        $this->assertIsString($repository);
        $this->assertStringContainsString('function staleNewIssueIdsForAssignee(', $repository);
        $this->assertStringContainsString("->where('asignado_a', (int) \$assigneeId)", $repository);
        $this->assertStringContainsString("->where('estado_redmine', 'Nueva')", $repository);
        $this->assertStringContainsString("->where('creado_at', '<', \$cutoff)", $repository);
        $this->assertStringNotContainsString("\$user['api']", $notifier);
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
        $historyView = file_get_contents($root.'/RedmineTic/views/native-sections/history.blade.php');
        $controller = file_get_contents($root.'/RedmineTic/Controllers/RedmineDashboardController.php');
        $repository = file_get_contents($root.'/RedmineTic/Repositories/RedmineDataRepository.php');
        $listener = file_get_contents($root.'/telegram/bin/listen.php');
        $schedule = file_get_contents($root.'/app/Console/Kernel.php');

        $this->assertStringContainsString("'informes' => ['label' => 'Informes'", $view);
        $this->assertStringContainsString('name="informes_nuevos_habilitado"', $view);
        $this->assertStringContainsString('name="informes_nuevos_dias"', $view);
        $this->assertStringContainsString('value="send_reports_now"', $view);
        $this->assertStringContainsString("input('config_action') === 'send_reports_now'", $controller);
        $this->assertStringContainsString('app(StaleNewReportNotifier::class)->run(true)', $controller);
        $this->assertStringContainsString('value="sync_redmine_statuses"', $historyView);
        $this->assertStringContainsString('$redmine->persistIssueStatuses($statuses)', $controller);
        $this->assertStringContainsString("\$report['estado_redmine'] = trim((string) data_get(\$decoded, 'issue.status.name', ''))", $repository);
        $this->assertStringContainsString('telegram_run_tic_daily_reports', $listener);
        $this->assertStringContainsString("redmine:notify-stale-new')->dailyAt('09:00')", $schedule);
    }
}
