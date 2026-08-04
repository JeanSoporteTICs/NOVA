<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Repositories\MantencionCatalogRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;
use App\Modulos\RedmineMantencion\Services\CorePendingReportSyncService;
use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use PHPUnit\Framework\TestCase;

final class CoreCategoryMappingTest extends TestCase
{
    private function service(): MantencionCoreImportService
    {
        $catalogs = new MantencionCatalogRepository;

        return new MantencionCoreImportService(
            new MantencionConfigRepository,
            $catalogs,
            new MantencionReportRepository($catalogs),
            new CorePendingReportSyncService,
        );
    }

    public function test_native_core_import_preserves_category_aliases(): void
    {
        $catalog = ['Credencial CORE', 'Creacion De Usuario', 'Modificar Perfil CORE'];
        self::assertSame('Creacion De Usuario', $this->service()->resolveCategoryFromCatalog('Creación de Usuario', $catalog));
        self::assertSame('Creacion De Usuario', $this->service()->resolveCategoryFromCatalog('Creacion Usuario', $catalog));
        self::assertSame('Modificar Perfil CORE', $this->service()->resolveCategoryFromCatalog('Modificar Usuario', $catalog));
    }
}
