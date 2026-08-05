<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CoreCategoryMappingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/RedmineMantencion/controllers/dashboard.php';
    }

    public function test_creation_request_keeps_the_matching_catalog_category(): void
    {
        $catalog = ['Credencial CORE', 'Creacion De Usuario', 'Modificar Perfil CORE'];

        $this->assertSame(
            'Creacion De Usuario',
            dashboard_core_resolve_category('Creación de Usuario', $catalog)
        );
    }

    public function test_creation_request_without_de_uses_the_creation_category(): void
    {
        $catalog = ['Credencial CORE', 'Creacion De Usuario', 'Modificar Perfil CORE'];

        $this->assertSame(
            'Creacion De Usuario',
            dashboard_core_resolve_category('Creacion Usuario', $catalog)
        );
    }

    public function test_modify_user_alias_still_uses_the_profile_category(): void
    {
        $catalog = ['Credencial CORE', 'Creacion De Usuario', 'Modificar Perfil CORE'];

        $this->assertSame(
            'Modificar Perfil CORE',
            dashboard_core_resolve_category('Modificar Usuario', $catalog)
        );
    }
}
