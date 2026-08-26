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
        $this->assertStringContainsString("->where('creado_at', '>=', \$start)", $repository);
        $this->assertStringContainsString("->where('creado_at', '<', \$end)", $repository);
        $this->assertStringNotContainsString("\$user['api']", $notifier);
    }

    public function test_notification_reports_the_count_and_threshold(): void
    {
        $message = app(StaleNewReportNotifier::class)->notificationMessage('Ana Pérez', ['101', '102', '103'], '17/08/2026 al 23/08/2026');

        $this->assertStringContainsString('Hola Ana Pérez.', $message);
        $this->assertStringContainsString('Tienes 3 reportes abiertos.', $message);
        $this->assertStringContainsString('Estado: Nueva', $message);
        $this->assertStringContainsString('Período informado: 17/08/2026 al 23/08/2026', $message);
        $this->assertStringContainsString('Tickets: #101, #102, #103', $message);
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
        $commands = file_get_contents($root.'/routes/console.php');

        $this->assertStringContainsString("'informes' => ['label' => 'Informes'", $view);
        $this->assertStringContainsString('name="informes_nuevos_habilitado"', $view);
        $this->assertStringContainsString('name="informes_nuevos_dias_desde"', $view);
        $this->assertStringContainsString('name="informes_nuevos_dias_hasta"', $view);
        $this->assertStringContainsString('name="informes_nuevos_periodo"', $view);
        $this->assertStringContainsString('name="informes_nuevos_dia"', $view);
        $this->assertStringContainsString('name="informes_nuevos_hora"', $view);
        $this->assertStringContainsString('value="send_reports_now"', $view);
        $this->assertStringContainsString("input('config_action') === 'send_reports_now'", $controller);
        $this->assertStringContainsString('app(StaleNewReportNotifier::class)->run(true)', $controller);
        $this->assertStringContainsString('value="sync_redmine_statuses"', $historyView);
        $this->assertStringContainsString('$redmine->persistIssueStatuses($statuses)', $controller);
        $this->assertStringContainsString("\$report['estado_redmine'] = trim((string) data_get(\$decoded, 'issue.status.name', ''))", $repository);
        $this->assertStringContainsString('telegram_run_tic_daily_reports', $listener);
        $this->assertStringContainsString("redmine:notify-stale-new')->everyFiveMinutes()", $schedule);
        $this->assertStringContainsString('$force ? $notifier->run(true) : $notifier->runIfDue()', $commands);
    }
}
