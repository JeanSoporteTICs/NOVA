<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NovaNavigationIconsTest extends TestCase
{
    public function test_catalog_has_one_canonical_icon_per_shared_destination(): void
    {
        $icons = require dirname(__DIR__, 2).'/config/navigation-icons.php';

        self::assertSame('bi-sliders', $icons['configuracion']);
        self::assertSame('bi-people', $icons['usuarios']);
        self::assertSame('bi-activity', $icons['actividad']);
        self::assertSame('bi-bar-chart-line', $icons['estadisticas']);
        self::assertSame('bi-clock-history', $icons['horas_extra']);
        self::assertSame($icons['historico'], $icons['historial']);

        foreach ($icons as $name => $icon) {
            self::assertMatchesRegularExpression('/^bi-[a-z0-9-]+$/', $icon, $name);
        }
    }

    public function test_every_primary_sidebar_reads_icons_from_the_shared_catalog(): void
    {
        $root = dirname(__DIR__, 2);
        $views = [
            'RedmineMantencion/views/partials/navbar.php',
            'RedmineTic/views/native.blade.php',
            'Emach/views/partials/navbar.php',
            'telegram/views/partials/navbar.php',
            'Nova/views/nova/telegram/navigation.blade.php',
            'Nova/views/nova/integrations/user-config.blade.php',
            'Nova/views/nova/admin/index.blade.php',
            'resources/views/monitor-servidores/index.blade.php',
        ];

        foreach ($views as $relativePath) {
            $view = file_get_contents($root.'/'.$relativePath);
            self::assertIsString($view, $relativePath);
            self::assertStringContainsString("config('navigation-icons.", $view, $relativePath);
        }
    }

    public function test_configuration_uses_the_same_icon_in_every_sidebar_that_exposes_it(): void
    {
        $root = dirname(__DIR__, 2);
        $views = [
            'RedmineMantencion/views/partials/navbar.php',
            'RedmineTic/views/native.blade.php',
            'Emach/views/partials/navbar.php',
            'Nova/views/nova/integrations/user-config.blade.php',
            'Nova/views/nova/admin/index.blade.php',
        ];

        foreach ($views as $relativePath) {
            $view = file_get_contents($root.'/'.$relativePath);
            self::assertIsString($view, $relativePath);
            self::assertStringContainsString("config('navigation-icons.configuracion')", $view, $relativePath);
        }
    }

    public function test_both_tic_sidebars_use_the_same_semantic_icon_keys(): void
    {
        $root = dirname(__DIR__, 2);
        $views = [
            file_get_contents($root.'/RedmineTic/views/native.blade.php'),
            file_get_contents($root.'/Nova/views/nova/integrations/user-config.blade.php'),
        ];
        $sharedKeys = [
            'reportes', 'reporte_manual', 'horas_extra', 'historico',
            'usuarios', 'configuracion', 'estadisticas', 'actividad',
        ];

        foreach ($views as $view) {
            self::assertIsString($view);
            foreach ($sharedKeys as $key) {
                self::assertStringContainsString("config('navigation-icons.{$key}')", $view, $key);
            }
        }
    }
}
