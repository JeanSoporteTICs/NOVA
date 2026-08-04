<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Controllers\MantencionDashboardController;
use App\Modulos\RedmineMantencion\Repositories\MantencionCatalogRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use App\Modulos\RedmineMantencion\Services\MantencionRedmineIssueService;
use PHPUnit\Framework\TestCase;

final class MantencionDashboardPermissionTest extends TestCase
{
    public function test_native_actions_have_explicit_permissions(): void
    {
        foreach (['update', 'process_selected', 'archive_selected', 'reset_errors'] as $action) {
            self::assertSame('reportes_editar', MantencionDashboardController::requiredPermission($action));
        }
        self::assertSame('reportes_eliminar', MantencionDashboardController::requiredPermission('delete'));
        self::assertSame('reportes_eliminar', MantencionDashboardController::requiredPermission('delete_selected'));
        self::assertSame('reportes_importar_core', MantencionDashboardController::requiredPermission('import_core_history'));
        self::assertSame('horas_extra_editar', MantencionDashboardController::requiredPermission('toggle_hora_extra'));
        self::assertSame('', MantencionDashboardController::requiredPermission('unknown'));
    }

    public function test_core_reports_in_review_are_blocked_but_manual_reports_are_not(): void
    {
        $service = new MantencionRedmineIssueService(new MantencionConfigRepository, new MantencionCatalogRepository);
        foreach (['En Revisión', 'en revision', '  EN REVISIÓN  '] as $status) {
            self::assertTrue($service->isCoreInReview(['fuente' => 'core', 'core_estado' => $status]));
        }
        self::assertFalse($service->isCoreInReview(['fuente' => 'core', 'core_estado' => 'Gestionada']));
        self::assertFalse($service->isCoreInReview(['fuente' => 'manual', 'core_estado' => 'En Revisión']));
    }
}
