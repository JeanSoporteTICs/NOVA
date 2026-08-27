<?php

namespace Tests\Unit;

use App\Support\Http\ApplicationPath;
use Illuminate\Http\RedirectResponse;
use PHPUnit\Framework\TestCase;

final class MantencionConfigurationRedirectTest extends TestCase
{
    public function test_configuration_urls_preserve_a_single_subdirectory_prefix(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Controllers/ConfiguracionController.php'
        );

        self::assertIsString($controller);
        self::assertStringNotContainsString("\$_SERVER['SCRIPT_NAME']", $controller);
        self::assertStringContainsString('ApplicationPath::make(', $controller);
        self::assertStringContainsString('redirect()->away($this->configurationUrl(', $controller);
        self::assertStringNotContainsString('return redirect($this->configurationUrl(', $controller);
        self::assertGreaterThanOrEqual(
            4,
            substr_count($controller, '$this->configurationUrl(')
        );
        self::assertStringContainsString("'panel' => 'usuarios'", $controller);
        self::assertStringContainsString("'user_id' => \$selectedUser", $controller);
    }

    public function test_application_path_does_not_duplicate_the_public_prefix(): void
    {
        self::assertSame(
            '/NOVA/public/redmine-mantencion/app/configuracion?panel=informes',
            ApplicationPath::make('/NOVA/public', '/redmine-mantencion/app/configuracion', ['panel' => 'informes'])
        );
        self::assertSame(
            '/redmine-mantencion/app/configuracion?panel=informes',
            ApplicationPath::make('', '/redmine-mantencion/app/configuracion', ['panel' => 'informes'])
        );
        self::assertStringNotContainsString('/NOVA/public/NOVA/public/', ApplicationPath::make(
            '/NOVA/public/',
            '/redmine-mantencion/app/configuracion'
        ));
    }

    public function test_direct_redirect_keeps_the_generated_location_unchanged(): void
    {
        $target = ApplicationPath::make(
            '/NOVA/public',
            '/redmine-mantencion/app/configuracion',
            ['panel' => 'informes']
        );
        $response = new RedirectResponse($target, 303);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/NOVA/public/redmine-mantencion/app/configuracion?panel=informes', $response->headers->get('Location'));
    }
}
