<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProcedimientosBrowserLayoutTest extends TestCase
{
    public function test_browser_fills_the_available_page_height_without_card_hover_translation(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = file_get_contents($root.'/resources/views/procedimientos/index.blade.php');
        $browser = file_get_contents($root.'/RedmineMantencion/views/Procedimientos/_nc_browser.php');
        $styles = file_get_contents($root.'/RedmineMantencion/assets/css/procedimientos.css');

        $this->assertStringContainsString('procedimientos-page', $layout);
        $this->assertStringContainsString('nc-browser-section card shadow-sm mb-0', $browser);
        $this->assertStringContainsString('body.procedimientos-page > .rm-layout', $styles);
        $this->assertStringContainsString('flex: 1 1 auto;', $styles);
        $this->assertStringContainsString('.nc-browser-section:hover', $styles);
        $this->assertStringContainsString('transform: none !important;', $styles);
    }

    public function test_busy_overlay_is_detached_from_the_hovered_card(): void
    {
        $browser = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/views/Procedimientos/_nc_browser.php'
        );

        $this->assertStringContainsString("document.getElementById('nc-busy-overlay')", $browser);
        $this->assertStringContainsString('document.body.appendChild(busyOverlay)', $browser);
    }
}
