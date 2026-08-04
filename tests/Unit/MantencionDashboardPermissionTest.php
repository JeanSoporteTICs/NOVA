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

    public function test_core_reports_in_review_cannot_be_sent_to_redmine(): void
    {
        foreach (['En Revisión', 'en revision', '  EN REVISIÓN  '] as $status) {
            $message = [
                'fuente' => 'core',
                'id_core' => 'CORE-100',
                'estado' => 'pendiente',
                'core_estado' => $status,
            ];

            $this->assertTrue(dashboard_core_is_in_review($message));
            $this->assertSame(
                'La solicitud permanece En Revisión en CORE.',
                dashboard_redmine_send_block_reason($message)
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

            $this->assertFalse(dashboard_core_is_in_review($message));
            $this->assertNull(dashboard_redmine_send_block_reason($message));
        }
    }

    public function test_manual_reports_are_not_blocked_by_the_core_rule(): void
    {
        $this->assertNull(dashboard_redmine_send_block_reason([
            'fuente' => 'manual',
            'estado' => 'pendiente',
            'core_estado' => 'En Revisión',
        ]));
    }
}
