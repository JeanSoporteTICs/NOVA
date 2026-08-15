<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use App\Modulos\RedmineMantencion\Services\MantencionDashboardService;
use App\Modulos\RedmineMantencion\Services\MantencionRedmineSyncService;
use App\Modulos\RedmineMantencion\Services\MantencionRetentionService;
use PHPUnit\Framework\TestCase;

class MantencionDashboardPermissionTest extends TestCase
{
    private static MantencionCoreImportService $coreImport;
    private static MantencionRedmineSyncService $redmineSync;
    private static MantencionDashboardService $dashboardService;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/RedmineMantencion/controllers/dashboard.php';
        self::$coreImport = new MantencionCoreImportService();
        self::$redmineSync = new MantencionRedmineSyncService(self::$coreImport);
        self::$dashboardService = new MantencionDashboardService(
            self::$coreImport,
            self::$redmineSync,
            new MantencionRetentionService(),
        );
    }

    public function test_report_edit_permission_protects_all_editing_workflows(): void
    {
        foreach (['update', 'process_selected', 'archive_selected', 'reset_errors'] as $action) {
            $this->assertSame('reportes_editar', self::$dashboardService->dashboard_required_permission_for_action($action));
        }
    }

    public function test_report_delete_permission_protects_individual_and_bulk_deletion(): void
    {
        $this->assertSame('reportes_eliminar', self::$dashboardService->dashboard_required_permission_for_action('delete'));
        $this->assertSame('reportes_eliminar', self::$dashboardService->dashboard_required_permission_for_action('delete_selected'));
    }

    public function test_core_import_and_hours_extra_keep_independent_permissions(): void
    {
        $this->assertSame('reportes_importar_core', self::$dashboardService->dashboard_required_permission_for_action('import_core_history'));
        $this->assertSame('horas_extra_editar', self::$dashboardService->dashboard_required_permission_for_action('toggle_hora_extra'));
    }

    public function test_only_the_global_nova_root_can_select_another_core_assignee(): void
    {
        $this->assertTrue(self::$coreImport->dashboard_can_select_core_assignee(['role' => 'root']));
        $this->assertFalse(self::$coreImport->dashboard_can_select_core_assignee(['role' => 'admin']));
        $this->assertFalse(self::$coreImport->dashboard_can_select_core_assignee(['role' => 'usuario']));
        $this->assertFalse(self::$coreImport->dashboard_can_select_core_assignee(['rol' => 'root']));
    }

    public function test_core_reports_in_review_cannot_be_sent_to_redmine(): void
    {
        foreach (['En Revisión', 'en revision', '  EN REVISIÓN  '] as $status) {
            $message = [
                'fuente' => 'core',
                'id_core' => 'CORE-100',
                'estado' => 'pendiente',
                'core_estado' => $status,
            ];

            $this->assertTrue(self::$coreImport->dashboard_core_is_in_review($message));
            $this->assertSame(
                'La solicitud permanece En Revisión en CORE.',
                self::$redmineSync->dashboard_redmine_send_block_reason($message)
            );
        }
    }

    public function test_core_reports_outside_review_can_be_sent_to_redmine(): void
    {
        foreach (['Gestionada', 'Rechazada', 'Aprobada', 'Cerrada'] as $status) {
            $message = [
                'fuente' => 'core',
                'id_core' => 'CORE-200',
                'estado' => 'pendiente',
                'core_estado' => $status,
            ];

            $this->assertFalse(self::$coreImport->dashboard_core_is_in_review($message));
            $this->assertNull(self::$redmineSync->dashboard_redmine_send_block_reason($message));
        }
    }

    public function test_manual_reports_are_not_blocked_by_the_core_rule(): void
    {
        $this->assertNull(self::$redmineSync->dashboard_redmine_send_block_reason([
            'fuente' => 'manual',
            'estado' => 'pendiente',
            'core_estado' => 'En Revisión',
        ]));
    }

    public function test_core_empty_import_message_is_simple(): void
    {
        $this->assertSame(
            'No hay reportes nuevos ni reportes por actualizar.',
            self::$dashboardService->dashboard_core_empty_import_message(),
        );
    }
}
