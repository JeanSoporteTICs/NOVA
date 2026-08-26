<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RedmineTic\Repositories\RedmineCatalogRepository;
use RedmineTic\Repositories\RedmineConfigRepository;
use RedmineTic\Repositories\RedmineDataRepository;
use Tests\TestCase;

/**
 * Covers the ETAPA B / Lote B1 delegation of RedmineDataRepository's
 * configuration()/saveConfiguration()/maintenanceModeEnabled() onto the
 * already-existing RedmineConfigRepository, plus the already-delegated
 * category/unit CRUD (RedmineCatalogRepository) — asserting the facade's
 * public contract is unchanged before/after. Runs against the real
 * configuraciones_modulo/modulo_opciones/catalogos_modulo tables for the
 * 'redmine_tic' module inside a rolled-back transaction.
 */
class RedmineTicConfigCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private function facade(): RedmineDataRepository
    {
        return (new RedmineDataRepository)->forProject('redmine_tic');
    }

    private function configRepo(): RedmineConfigRepository
    {
        return new RedmineConfigRepository('redmine_tic', 'Backlog Soporte TI');
    }

    private function catalogRepo(): RedmineCatalogRepository
    {
        return new RedmineCatalogRepository('redmine_tic', 'Backlog Soporte TI');
    }

    public function test_facade_configuration_matches_config_repository_directly(): void
    {
        $viaFacade = $this->facade()->configuration();
        $viaRepo = $this->configRepo()->configuration();

        $this->assertSame($viaRepo, $viaFacade);
    }

    public function test_configuration_contains_all_default_keys(): void
    {
        $config = $this->facade()->configuration();

        foreach ([
            'platform_url', 'categories_url', 'unidades_url', 'webhook_url', 'project_id',
            'project_name', 'tracker_id', 'priority_id', 'status_id', 'cf_solicitante',
            'cf_unidad', 'cf_unidad_solicitante', 'cf_hora_extra', 'retencion_horas',
            'informes_nuevos_habilitado', 'informes_nuevos_dias',
            'maintenance_mode', 'maintenance_until', 'trackers', 'prioridades', 'estados',
        ] as $key) {
            $this->assertArrayHasKey($key, $config);
        }
    }

    public function test_save_and_load_configuration_round_trips(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['platform_url' => 'https://example.test/redmine', 'retencion_horas' => 48]);

        $config = $facade->configuration();
        $this->assertSame('https://example.test/redmine', $config['platform_url']);
        $this->assertSame(48, $config['retencion_horas']);
    }

    public function test_maintenance_mode_enabled_reflects_configuration(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['maintenance_mode' => true, 'maintenance_until' => '2026-01-01 00:00:00']);

        $this->assertTrue($facade->maintenanceModeEnabled());
    }

    public function test_create_update_delete_and_set_default_option_via_config_repository(): void
    {
        // RedmineDashboardController::configurationAction() calls these
        // directly on RedmineConfigRepository (documented bypass in the
        // map) — this test exercises that exact real caller shape and
        // confirms the facade's configuration() reflects the same rows.
        $repo = $this->configRepo();

        $this->assertTrue($repo->createOption('tracker', 'test-tracker-1', 'Tracker de prueba'));
        $trackers = $this->facade()->configuration()['trackers'];
        $this->assertTrue(collect($trackers)->contains(fn ($t) => $t['nombre'] === 'Tracker de prueba'));

        $this->assertTrue($repo->updateOption('tracker', 'test-tracker-1', 'Tracker editado', true));
        $trackers = $this->facade()->configuration()['trackers'];
        $edited = collect($trackers)->firstWhere('nombre', 'Tracker editado');
        $this->assertNotNull($edited);
        $this->assertTrue($edited['default']);

        $this->assertTrue($repo->setDefaultOption('tracker', 'test-tracker-1'));
        $this->assertSame('test-tracker-1', (string) $this->facade()->configuration()['tracker_id']);

        $this->assertTrue($repo->deleteOption('tracker', 'test-tracker-1'));
        $trackers = $this->facade()->configuration()['trackers'];
        $this->assertFalse(collect($trackers)->contains(fn ($t) => $t['nombre'] === 'Tracker editado'));
    }

    public function test_save_configuration_bulk_replaces_options_deactivating_missing(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['prioridades' => [
            ['id' => 'p1', 'nombre' => 'Baja'],
            ['id' => 'p2', 'nombre' => 'Alta'],
        ]]);
        $names = collect($facade->configuration()['prioridades'])->pluck('nombre')->all();
        $this->assertEqualsCanonicalizing(['Baja', 'Alta'], $names);

        // Bulk re-save with a different set: old rows must be deactivated
        // (gone from the read), not accumulated — this is the "sync masivo"
        // semantics saveConfiguration()/saveOptionsToDatabase() must keep.
        $facade->saveConfiguration(['prioridades' => [
            ['id' => 'p3', 'nombre' => 'Media'],
        ]]);
        $names = collect($facade->configuration()['prioridades'])->pluck('nombre')->all();
        $this->assertSame(['Media'], $names);
    }

    public function test_categories_crud_via_facade(): void
    {
        $facade = $this->facade();
        $before = count($facade->categories());

        $facade->saveCategory(['nombre' => 'Categoria de prueba B1']);
        $after = $facade->categories();
        $this->assertCount($before + 1, $after);
        $created = collect($after)->firstWhere('nombre', 'Categoria de prueba B1');
        $this->assertNotNull($created);

        $deleted = $facade->deleteCategory($created['id']);
        $this->assertSame(1, $deleted);
        $this->assertCount($before, $facade->categories());
    }

    public function test_units_crud_via_facade(): void
    {
        $facade = $this->facade();
        $before = count($facade->units());

        $facade->saveUnit(['nombre' => 'Unidad de prueba B1']);
        $after = $facade->units();
        $this->assertCount($before + 1, $after);
        $created = collect($after)->firstWhere('nombre', 'Unidad de prueba B1');
        $this->assertNotNull($created);

        $deleted = $facade->deleteUnit($created['id']);
        $this->assertSame(1, $deleted);
        $this->assertCount($before, $facade->units());
    }

    public function test_redmine_possible_values_preserve_external_value_and_label(): void
    {
        $rows = $this->catalogRepo()->rowsFromRedminePossibleValues([
            ['value' => 'UNI_CORE_01', 'label' => 'Unidad Clínica Norte'],
            'ARCHIVO',
            ['value' => '', 'label' => 'Inválida'],
        ]);

        $this->assertSame([
            ['id' => 'UNI_CORE_01', 'nombre' => 'Unidad Clínica Norte'],
            ['id' => 'ARCHIVO', 'nombre' => 'ARCHIVO'],
        ], $rows);
    }

    public function test_active_external_value_matches_label_but_returns_exact_redmine_key(): void
    {
        $repo = $this->catalogRepo();
        $repo->saveCatalogRowsToDatabase('unidad', [[
            'id' => 'UNI_CORE_Exacta',
            'nombre' => 'Unidad Clínica Ñuble',
        ]], false);
        $catalogId = $repo->idForValue('unidad', 'UNI_CORE_Exacta');

        $this->assertSame('UNI_CORE_Exacta', $repo->activeExternalValue('unidad', 'unidad clinica nuble'));
        $this->assertSame('UNI_CORE_Exacta', $repo->activeExternalValue('unidad', 'uni_core_exacta'));
        $this->assertSame('UNI_CORE_Exacta', $repo->activeExternalValueById('unidad', $catalogId));

        $repo->deleteUnit('UNI_CORE_Exacta');
        $this->assertNull($repo->activeExternalValue('unidad', 'Unidad Clínica Ñuble'));
        $this->assertNull($repo->activeExternalValueById('unidad', $catalogId));
    }

    public function test_report_lookup_does_not_create_unknown_catalog_values(): void
    {
        $repo = $this->catalogRepo();
        $before = count($repo->units());

        $this->assertNull($repo->idForValue('unidad', 'Unidad inexistente desde reporte'));
        $this->assertCount($before, $repo->units());
    }

    public function test_saving_configuration_twice_does_not_duplicate_rows(): void
    {
        $facade = $this->facade();
        $facade->saveConfiguration(['platform_url' => 'https://example.test/one']);
        $facade->saveConfiguration(['platform_url' => 'https://example.test/two']);

        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        $rows = DB::table('configuraciones_modulo')
            ->where('modulo_id', $moduleId)
            ->where('clave', 'platform_url')
            ->count();

        $this->assertSame(1, $rows);
        $this->assertSame('https://example.test/two', $facade->configuration()['platform_url']);
    }
}
