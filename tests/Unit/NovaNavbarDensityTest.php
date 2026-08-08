<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NovaNavbarDensityTest extends TestCase
{
    public function test_global_navbar_uses_the_compact_height_token(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.css');

        self::assertIsString($css);
        self::assertStringContainsString('--nova-navbar-height: 60px;', $css);
        self::assertStringContainsString('--navbar-height: var(--nova-navbar-height);', $css);
        self::assertStringContainsString('min-height: var(--nova-navbar-height) !important;', $css);
        self::assertStringContainsString('min-height: calc(100vh - var(--nova-navbar-height));', $css);
    }

    public function test_all_topbar_variants_share_the_compact_contract(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.css');

        self::assertIsString($css);
        foreach (['.nova-topbar', '.rm-navbar', '.sb-navbar', '.telegram-topbar', '.telegram-navbar', '.emach-navbar'] as $selector) {
            self::assertStringContainsString($selector, $css);
        }
        self::assertMatchesRegularExpression(
            '/\.nova-brand-mark,[^{]+\.sb-brand-mark\s*\{[^}]*width:\s*36px\s*!important;[^}]*height:\s*36px\s*!important;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.nova-topbar \.btn,[^{]+\.emach-navbar \.btn\s*\{[^}]*min-height:\s*34px;/s',
            $css
        );
    }

    public function test_mobile_keeps_larger_touch_targets(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.css');

        self::assertIsString($css);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 767\.98px\)[\s\S]*?\.nova-topbar \.btn,[^{]+\.emach-navbar \.btn\s*\{[^}]*min-height:\s*38px;/s',
            $css
        );
    }
}
