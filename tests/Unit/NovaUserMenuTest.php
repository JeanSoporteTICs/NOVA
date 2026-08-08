<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NovaUserMenuTest extends TestCase
{
    public function test_global_script_builds_the_user_menu_without_replacing_logout_security(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.js');

        self::assertIsString($script);
        self::assertStringContainsString('const NovaUserMenu = (() => {', $script);
        self::assertStringContainsString("form.querySelector('button[type=\"submit\"], input[type=\"submit\"]')", $script);
        self::assertStringContainsString('panel.appendChild(form);', $script);
        self::assertStringContainsString('Cambiar contrase\\u00f1a', $script);
        self::assertStringContainsString('Pr\\u00f3ximamente', $script);
        self::assertStringContainsString('Cerrar sesi\\u00f3n', $script);
        self::assertStringContainsString("document.addEventListener('partial:loaded', () => init())", $script);
    }

    public function test_user_menu_has_compact_desktop_and_mobile_styles(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.css');

        self::assertIsString($css);
        self::assertStringContainsString('.nova-user-menu-trigger {', $css);
        self::assertStringContainsString('.nova-user-menu-panel {', $css);
        self::assertStringContainsString('.nova-user-menu-action.is-danger {', $css);
        self::assertMatchesRegularExpression(
            '/\.nova-user-menu-trigger\s*\{[^}]*background:\s*rgba\(255, 255, 255, \.1\);[^}]*color:\s*#fff;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 575\.98px\)[\s\S]*?\.nova-user-menu-name\s*\{[^}]*display:\s*none;/s',
            $css
        );
    }

    public function test_navigation_entrypoints_cache_bust_the_shared_ui_assets(): void
    {
        $root = dirname(__DIR__, 2);
        $views = [
            'Nova/views/nova/home.blade.php',
            'Nova/views/nova/admin/index.blade.php',
            'Nova/views/nova/modules/index.blade.php',
            'Nova/views/nova/horas-extra/index.blade.php',
            'Nova/views/nova/telegram/index.blade.php',
            'Nova/views/nova/integrations/user-config.blade.php',
            'Nova/views/nova/module-log.blade.php',
            'RedmineTic/views/native.blade.php',
            'RedmineMantencion/views/partials/bootstrap-head.php',
            'Emach/views/partials/bootstrap-head.php',
            'telegram/index.php',
            'resources/views/monitor-servidores/index.blade.php',
            'resources/views/procedimientos/index.blade.php',
        ];

        foreach ($views as $relativePath) {
            $view = file_get_contents($root.'/'.$relativePath);
            self::assertIsString($view, $relativePath);
            self::assertStringContainsString('nova-ui.js', $view, $relativePath);
            self::assertMatchesRegularExpression('/novaUiJsVersion|nova-ui\.js[^\r\n]*\?v=/', $view, $relativePath);
        }
    }

    public function test_primary_navigation_views_keep_post_logout_forms_for_the_global_menu(): void
    {
        $root = dirname(__DIR__, 2);
        $views = [
            'Nova/views/nova/home.blade.php',
            'Nova/views/nova/admin/index.blade.php',
            'Nova/views/nova/modules/index.blade.php',
            'Nova/views/nova/horas-extra/index.blade.php',
            'Nova/views/nova/telegram/navigation.blade.php',
            'Nova/views/nova/integrations/user-config.blade.php',
            'RedmineTic/views/native.blade.php',
            'RedmineMantencion/views/partials/navbar.php',
            'Emach/views/partials/navbar.php',
            'telegram/views/partials/navbar.php',
            'resources/views/monitor-servidores/index.blade.php',
            'resources/views/procedimientos/index.blade.php',
        ];

        foreach ($views as $relativePath) {
            $view = file_get_contents($root.'/'.$relativePath);
            self::assertIsString($view, $relativePath);
            self::assertStringContainsString('method="POST"', $view, $relativePath);
            self::assertStringContainsString('bi-person-circle', $view, $relativePath);
            self::assertStringContainsString('bi-box-arrow-right', $view, $relativePath);
        }
    }

    public function test_all_navigation_headers_render_the_central_full_name(): void
    {
        $root = dirname(__DIR__, 2);
        $partial = file_get_contents($root.'/Nova/views/nova/partials/current-user-name.blade.php');

        self::assertIsString($partial);
        self::assertStringContainsString('NovaUserService::class)->fullName', $partial);
        self::assertStringContainsString("session('nova_user.username')", $partial);

        $bladeViews = [
            'Nova/views/nova/home.blade.php',
            'Nova/views/nova/admin/index.blade.php',
            'Nova/views/nova/modules/index.blade.php',
            'Nova/views/nova/horas-extra/index.blade.php',
            'Nova/views/nova/telegram/navigation.blade.php',
            'Nova/views/nova/integrations/user-config.blade.php',
            'RedmineTic/views/native.blade.php',
            'resources/views/monitor-servidores/index.blade.php',
            'resources/views/procedimientos/index.blade.php',
        ];

        foreach ($bladeViews as $relativePath) {
            $view = file_get_contents($root.'/'.$relativePath);
            self::assertIsString($view, $relativePath);
            self::assertStringContainsString("@include('nova.partials.current-user-name')", $view, $relativePath);
            self::assertStringNotContainsString("session('nova_user.name')", $view, $relativePath);
        }

        foreach (['Emach/views/partials/navbar.php', 'telegram/views/partials/navbar.php'] as $relativePath) {
            $view = file_get_contents($root.'/'.$relativePath);
            self::assertIsString($view, $relativePath);
            self::assertStringContainsString("\$currentUser['apellido']", $view, $relativePath);
            self::assertStringContainsString('$navDisplayName', $view, $relativePath);
        }
    }
}
