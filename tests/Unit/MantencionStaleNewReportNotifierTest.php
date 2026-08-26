<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionStaleNewReportNotifier;
use Tests\TestCase;

final class MantencionStaleNewReportNotifierTest extends TestCase
{
    public function test_database_query_filters_new_issues_by_assignee_and_previous_week(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/Services/MantencionStaleNewReportNotifier.php');

        self::assertIsString($service);
        self::assertStringContainsString('function staleNewIssueIdsForAssignee(', $service);
        self::assertStringContainsString("DB::table('redmine_mantencion_reportes')", $service);
        self::assertStringContainsString("->where('id_redmine_asignado', (int) \$assigneeId)", $service);
        self::assertStringContainsString("->where('estado_redmine', 'Nueva')", $service);
        self::assertStringContainsString("->where('creado_at', '>=', \$start)", $service);
        self::assertStringContainsString("->where('creado_at', '<', \$end)", $service);
    }

    public function test_notification_identifies_mantencion_and_reports_the_count(): void
    {
        $message = app(MantencionStaleNewReportNotifier::class)->notificationMessage('Ana Pérez', ['201', '202', '203', '204'], '17/08/2026 al 23/08/2026');

        self::assertStringContainsString('INFORME MANTENCIÓN', $message);
        self::assertStringContainsString('Hola Ana Pérez.', $message);
        self::assertStringContainsString('Tienes 4 reportes abiertos.', $message);
        self::assertStringContainsString('Estado: Nueva', $message);
        self::assertStringContainsString('Período informado: 17/08/2026 al 23/08/2026', $message);
        self::assertStringContainsString('Tickets: #201, #202, #203, #204', $message);
    }

    public function test_configuration_permissions_and_automation_include_mantencion(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/redmine-mantencion/configuracion.blade.php');
        $controller = file_get_contents($root.'/RedmineMantencion/Controllers/ConfiguracionController.php');
        $service = file_get_contents($root.'/RedmineMantencion/Services/MantencionStaleNewReportNotifier.php');
        $permissions = file_get_contents($root.'/RedmineMantencion/views/Configuracion/_permissions_panels.php');
        $listener = file_get_contents($root.'/telegram/bin/listen.php');
        $schedule = file_get_contents($root.'/app/Console/Kernel.php');

        self::assertStringContainsString("'informes' => ['label' => 'Informes'", $view);
        self::assertStringContainsString('name="informes_nuevos_habilitado"', $view);
        self::assertStringContainsString('name="informes_nuevos_dia"', $view);
        self::assertStringContainsString('name="informes_nuevos_hora"', $view);
        self::assertStringNotContainsString('name="informes_nuevos_dias"', $view);
        self::assertStringContainsString('value="send_reports_now"', $view);
        self::assertStringContainsString('últimos 7 días', $view);
        self::assertStringContainsString("\$action === 'send_reports_now'", $controller);
        self::assertStringContainsString('$this->reportsNotifier->runManual()', $controller);
        self::assertStringContainsString('AutomaticReportSchedule::lastSevenDays(', $service);
        self::assertStringContainsString("'cfg_informes' => 'Informes automáticos'", $permissions);
        self::assertStringContainsString('telegram_run_mantencion_daily_reports', $listener);
        self::assertStringContainsString("redmine:mantencion-notify-stale-new')->everyFiveMinutes()", $schedule);
    }
}
