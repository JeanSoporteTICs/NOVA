<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NovaHeroDensityTest extends TestCase
{
    public function test_global_hero_uses_the_compact_size_tokens(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.css');

        self::assertIsString($css);
        self::assertStringContainsString('--nova-hero-min-height: 76px;', $css);
        self::assertStringContainsString('--nova-hero-icon-size: 40px;', $css);
        self::assertStringContainsString('--nova-hero-title-size: clamp(1.08rem, 1.7vw, 1.3rem);', $css);
        self::assertStringContainsString('--nova-hero-subtitle-size: 0.78rem;', $css);
        self::assertMatchesRegularExpression(
            '/\.hero-content\s*\{[^}]*display:\s*grid\s*!important;[^}]*min-height:\s*var\(--nova-hero-min-height\)\s*!important;[^}]*padding:\s*var\(--nova-hero-padding-block\) var\(--nova-hero-padding-inline\)\s*!important;/s',
            $css
        );
    }

    public function test_every_page_hero_variant_is_covered_by_the_global_layer(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.css');

        self::assertIsString($css);
        foreach (['.nova-summary', '.rm-hero', '.telegram-hero', '.emach-hero', '.card.card-hero', '.nova-system-hero', '.monitor-hero', '.module-log-hero'] as $selector) {
            self::assertStringContainsString($selector, $css);
        }
    }

    public function test_mobile_keeps_the_icon_and_copy_on_the_same_row(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/public/assets/nova-ui.css');

        self::assertIsString($css);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 767\.98px\)[\s\S]*?\.hero-content\s*\{[^}]*grid-template-columns:\s*auto minmax\(0, 1fr\)\s*!important;/s',
            $css
        );
        self::assertStringContainsString('--nova-hero-icon-size: 36px;', $css);
    }
}
