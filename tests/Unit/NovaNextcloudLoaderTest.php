<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NovaNextcloudLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_shared_loader_contains_the_sequential_logo_parts(): void
    {
        $partial = file_get_contents($this->root.'/resources/views/partials/nextcloud-loader.php');

        self::assertIsString($partial);
        self::assertStringContainsString('nova-nextcloud-loader-circle is-center', $partial);
        self::assertStringContainsString('nova-nextcloud-loader-circle is-left', $partial);
        self::assertStringContainsString('nova-nextcloud-loader-circle is-right', $partial);
        self::assertStringContainsString('nova-nextcloud-loader-word', $partial);
        self::assertStringContainsString('<span>N</span><span>e</span><span>x</span><span>t</span><span>c</span><span>l</span><span>o</span><span>u</span><span>d</span>', $partial);
    }

    public function test_global_styles_define_draw_pop_piano_and_reduced_motion_states(): void
    {
        $css = file_get_contents($this->root.'/public/assets/nova-ui.css');

        self::assertIsString($css);
        self::assertStringContainsString('@keyframes nova-nextcloud-center', $css);
        self::assertStringContainsString('@keyframes nova-nextcloud-side', $css);
        self::assertStringContainsString('@keyframes nova-nextcloud-letter', $css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        self::assertMatchesRegularExpression(
            '/\.nova-nextcloud-loader\s*\{[^}]*background:\s*transparent;[^}]*color:\s*#0082c9;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.nova-nextcloud-loader-word\s*\{[^}]*color:\s*#0082c9\s*!important;/s',
            $css
        );
    }

    public function test_every_nextcloud_operation_surface_uses_the_shared_loader(): void
    {
        foreach ([
            'RedmineMantencion/views/partials/navbar.php',
            'RedmineMantencion/views/Procedimientos/_nc_browser.php',
            'resources/views/redmine-mantencion/integraciones-nextcloud-usuarios.blade.php',
            'Nova/views/nova/integrations/user-config.blade.php',
        ] as $relativePath) {
            $view = file_get_contents($this->root.'/'.$relativePath);
            self::assertIsString($view);
            self::assertStringContainsString("resources/views/partials/nextcloud-loader.php", $view, $relativePath);
            self::assertStringNotContainsString('Nextcloud.gif', $view, $relativePath);
        }
    }

    public function test_generic_integration_loader_identifies_all_nextcloud_actions(): void
    {
        $navbar = file_get_contents($this->root.'/RedmineMantencion/views/partials/navbar.php');

        self::assertIsString($navbar);
        self::assertStringContainsString("provider: 'nextcloud'", $navbar);
        self::assertMatchesRegularExpression(
            "/if \(lower\.indexOf\('nextcloud'\) !== -1\)[\s\S]+if \(!\/\(sync\|sincron/",
            $navbar
        );
    }
}
