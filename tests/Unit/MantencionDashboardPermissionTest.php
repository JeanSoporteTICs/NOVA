<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class MantencionDashboardPermissionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/RedmineMantencion/controllers/dashboard.php';
    }

    public function test_report_edit_permission_protects_all_editing_workflows(): void
    {
        foreach (['update', 'process_selected', 'archive_selected', 'reset_errors'] as $action) {
            $this->assertSame('reportes_editar', dashboard_required_permission_for_action($action));
        }
    }

    public function test_report_delete_permission_protects_individual_and_bulk_deletion(): void
    {
        $this->assertSame('reportes_eliminar', dashboard_required_permission_for_action('delete'));
        $this->assertSame('reportes_eliminar', dashboard_required_permission_for_action('delete_selected'));
    }

    public function test_core_import_and_hours_extra_keep_independent_permissions(): void
    {
        $this->assertSame('reportes_importar_core', dashboard_required_permission_for_action('import_core_history'));
        $this->assertSame('horas_extra_editar', dashboard_required_permission_for_action('toggle_hora_extra'));
    }

    public function test_only_the_global_nova_root_can_select_another_core_assignee(): void
    {
        $this->assertTrue(dashboard_can_select_core_assignee(['role' => 'root']));
        $this->assertFalse(dashboard_can_select_core_assignee(['role' => 'admin']));
        $this->assertFalse(dashboard_can_select_core_assignee(['role' => 'usuario']));
        $this->assertFalse(dashboard_can_select_core_assignee(['rol' => 'root']));
    }
}
