<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NovaSidebarCompactTest extends TestCase
{
    public function test_global_script_initializes_every_primary_sidebar(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.js');

        self::assertIsString($script);
        self::assertStringContainsString("root.querySelectorAll('.nova-sidebar:not([data-nova-sidebar-compact-ready])')", $script);
        self::assertStringContainsString("footer.className = 'nova-sidebar-footer'", $script);
        self::assertStringContainsString("button.className = 'nova-sidebar-collapse-toggle'", $script);
        self::assertStringContainsString("sidebar.classList.toggle('is-compact', compact)", $script);
        self::assertStringContainsString("window.localStorage.setItem(storageKey(sidebar)", $script);
        self::assertStringContainsString("document.addEventListener('partial:loaded', () => init())", $script);
        self::assertStringContainsString('function syncViewportOffset(sidebar)', $script);
        self::assertStringContainsString('function syncNestedGroups(sidebar, compact)', $script);
        self::assertStringContainsString("sidebar.dataset.novaSidebarTemporaryExpanded = 'true'", $script);
        self::assertStringContainsString('event.stopPropagation()', $script);
        self::assertStringContainsString("window.addEventListener('scroll', requestViewportSync, { passive: true })", $script);
        self::assertStringContainsString("document.documentElement.classList.remove('nova-sidebar-precompact')", $script);
    }

    public function test_tic_preloads_the_compact_state_before_the_first_paint(): void
    {
        $root = dirname(__DIR__, 2);
        $preload = file_get_contents($root.'/public/assets/nova-sidebar-preload.js');
        $view = file_get_contents($root.'/RedmineTic/views/native.blade.php');
        $css = file_get_contents($root.'/public/assets/nova-ui.css');

        self::assertIsString($preload);
        self::assertStringContainsString('window.localStorage.getItem(`${STORAGE_PREFIX}${moduleKey}`)', $preload);
        self::assertStringContainsString("document.documentElement.classList.add('nova-sidebar-precompact')", $preload);

        self::assertIsString($view);
        self::assertStringContainsString('nova-sidebar-preload.js', $view);
        self::assertStringContainsString('data-nova-sidebar-key="redmine_tic"', $view);
        self::assertLessThan(
            strpos($view, 'nova-ui.css'),
            strpos($view, 'nova-sidebar-preload.js'),
            'The compact-state preload must run before the sidebar stylesheet is rendered.'
        );

        self::assertIsString($css);
        self::assertStringContainsString('html.nova-sidebar-precompact .nova-sidebar', $css);
        self::assertStringContainsString('html.nova-sidebar-precompact .nova-sidebar .nova-sidebar-link > span', $css);
    }

    public function test_every_sidebar_layout_preloads_its_persisted_state_before_css(): void
    {
        $root = dirname(__DIR__, 2);
        $preloadHosts = [
            'RedmineMantencion/views/partials/bootstrap-head.php' => 'redmine-mantencion',
            'RedmineTic/views/native.blade.php' => 'redmine_tic',
            'Emach/views/partials/bootstrap-head.php' => 'emach',
            'telegram/views/partials/bootstrap-head.php' => 'telegram',
            'Nova/views/nova/telegram/index.blade.php' => 'telegram',
            'Nova/views/nova/integrations/user-config.blade.php' => '{{ $moduleKey }}',
            'Nova/views/nova/admin/index.blade.php' => 'administracion',
            'resources/views/monitor-servidores/index.blade.php' => 'monitoreo-servidores',
        ];

        foreach ($preloadHosts as $relativePath => $stateKey) {
            $view = file_get_contents($root.'/'.$relativePath);
            self::assertIsString($view, $relativePath);
            self::assertStringContainsString('nova-sidebar-preload.js', $view, $relativePath);
            self::assertStringContainsString('data-nova-sidebar-key="'.$stateKey.'"', $view, $relativePath);
            self::assertStringContainsString('nova-ui.css', $view, $relativePath);
            self::assertLessThan(
                strpos($view, 'nova-ui.css'),
                strpos($view, 'nova-sidebar-preload.js'),
                $relativePath.' must preload the compact state before rendering the sidebar CSS.'
            );
        }
    }

    public function test_compact_sidebar_reduces_width_and_hides_labels_only_on_desktop(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.css');

        self::assertIsString($css);
        self::assertStringContainsString('.nova-sidebar.is-compact,', $css);
        self::assertMatchesRegularExpression('/html\.nova-sidebar-precompact \.nova-sidebar\s*\{[^}]*--nova-sidebar-width:\s*72px;/s', $css);
        self::assertStringContainsString('.nova-sidebar.is-compact .nova-sidebar-link > span', $css);
        self::assertStringContainsString('.nova-sidebar.is-compact .nova-sidebar-sub', $css);
        self::assertMatchesRegularExpression('/html\.nova-sidebar-precompact \.nova-sidebar \.nova-sidebar-sub\s*\{[^}]*display:\s*none !important;/s', $css);
        self::assertMatchesRegularExpression('/\.nova-sidebar-footer\s*\{[^}]*display:\s*none;/s', $css);
    }

    public function test_sidebar_footer_stays_visible_while_only_the_navigation_scrolls(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.css');

        self::assertIsString($css);
        self::assertStringContainsString('top: var(--nova-sidebar-viewport-offset, var(--nova-navbar-height));', $css);
        self::assertStringContainsString('height: calc(100dvh - var(--nova-sidebar-viewport-offset, var(--nova-navbar-height)));', $css);
        self::assertMatchesRegularExpression(
            '/\.nova-sidebar-body\s*\{[^}]*min-height:\s*0;[^}]*overflow-y:\s*auto;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.nova-sidebar-footer\s*\{[^}]*flex:\s*0 0 auto;/s',
            $css
        );
    }

    public function test_every_primary_sidebar_uses_the_shared_component_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $sidebarViews = [
            'RedmineMantencion/views/partials/navbar.php',
            'RedmineTic/views/native.blade.php',
            'Emach/views/partials/navbar.php',
            'telegram/views/partials/navbar.php',
            'Nova/views/nova/telegram/navigation.blade.php',
            'Nova/views/nova/integrations/user-config.blade.php',
            'Nova/views/nova/admin/index.blade.php',
            'resources/views/monitor-servidores/index.blade.php',
        ];

        foreach ($sidebarViews as $relativePath) {
            $view = file_get_contents($root.'/'.$relativePath);
            self::assertIsString($view, $relativePath);
            self::assertStringContainsString('class="nova-sidebar ', $view, $relativePath);
            self::assertStringContainsString('class="nova-sidebar-body"', $view, $relativePath);
        }
    }

    public function test_every_primary_sidebar_renders_the_compact_control_server_side(): void
    {
        $root = dirname(__DIR__, 2);
        $views = [
            'RedmineMantencion/views/partials/navbar.php' => 'nova-sidebar-collapse-toggle',
            'RedmineTic/views/native.blade.php' => "@include('nova.partials.sidebar-compact-control'",
            'Emach/views/partials/navbar.php' => 'nova-sidebar-collapse-toggle',
            'telegram/views/partials/navbar.php' => 'nova-sidebar-collapse-toggle',
            'Nova/views/nova/telegram/navigation.blade.php' => "@include('nova.partials.sidebar-compact-control'",
            'Nova/views/nova/integrations/user-config.blade.php' => "@include('nova.partials.sidebar-compact-control'",
            'Nova/views/nova/admin/index.blade.php' => "@include('nova.partials.sidebar-compact-control'",
            'resources/views/monitor-servidores/index.blade.php' => "@include('nova.partials.sidebar-compact-control'",
        ];

        foreach ($views as $relativePath => $controlMarkup) {
            $view = file_get_contents($root.'/'.$relativePath);
            self::assertIsString($view, $relativePath);
            self::assertStringContainsString($controlMarkup, $view, $relativePath);
        }

        $partial = file_get_contents($root.'/Nova/views/nova/partials/sidebar-compact-control.blade.php');
        self::assertIsString($partial);
        self::assertStringContainsString('nova-sidebar-collapse-toggle', $partial);
        self::assertStringContainsString('Contraer menú', $partial);
    }

    public function test_every_sidebar_layout_loads_the_global_ui_script(): void
    {
        $root = dirname(__DIR__, 2);
        $scriptHosts = [
            'RedmineMantencion/views/partials/bootstrap-head.php',
            'RedmineTic/views/native.blade.php',
            'Emach/views/partials/bootstrap-head.php',
            'telegram/index.php',
            'Nova/views/nova/telegram/index.blade.php',
            'Nova/views/nova/integrations/user-config.blade.php',
            'Nova/views/nova/admin/index.blade.php',
            'resources/views/monitor-servidores/index.blade.php',
        ];

        foreach ($scriptHosts as $relativePath) {
            $view = file_get_contents($root.'/'.$relativePath);
            self::assertIsString($view, $relativePath);
            self::assertStringContainsString('nova-ui.js', $view, $relativePath);
        }
    }

    public function test_mantencion_no_longer_duplicates_the_global_sidebar_logic(): void
    {
        $navbar = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/views/partials/navbar.php'
        );

        self::assertIsString($navbar);
        self::assertStringNotContainsString('redmine-mantencion-sidebar-compact', $navbar);
        self::assertStringNotContainsString('id="nova-sidebar-collapse-toggle"', $navbar);
    }

    public function test_mantencion_partial_navigation_syncs_the_active_group_hierarchy(): void
    {
        $navbar = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/views/partials/navbar.php'
        );

        self::assertIsString($navbar);
        self::assertStringContainsString('const groupToggles =', $navbar);
        self::assertStringContainsString('controlled.contains(activeLink)', $navbar);
        self::assertStringContainsString("toggle.classList.toggle('active', containsActive)", $navbar);
        self::assertStringContainsString("collapse.classList.toggle('show', shouldExpand)", $navbar);
    }
}
