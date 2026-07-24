<?php

namespace Tests\Unit;

use RedmineTic\Support\CatalogMatchSupport;
use Tests\TestCase;

/**
 * ETAPA B / Lote B6.3 — direct unit coverage of the pure fuzzy catalog
 * matching utilities extracted verbatim from RedmineDataRepository into
 * CatalogMatchSupport.
 */
class CatalogMatchSupportTest extends TestCase
{
    public function test_infer_catalog_match_finds_exact_and_substring_matches(): void
    {
        $items = ['Equipos', 'Redes', 'Correo'];

        $this->assertSame('Equipos', CatalogMatchSupport::inferCatalogMatch('equipos', $items));
        $this->assertSame('Correo', CatalogMatchSupport::inferCatalogMatch('problema con el correo', $items));
    }

    public function test_infer_catalog_match_returns_empty_when_nothing_scores_high_enough(): void
    {
        $this->assertSame('', CatalogMatchSupport::inferCatalogMatch('', ['Equipos']));
        $this->assertSame('', CatalogMatchSupport::inferCatalogMatch('xyz completely unrelated', ['Equipos', 'Redes']));
    }

    public function test_infer_catalog_match_uses_hints_to_disambiguate(): void
    {
        // "impresora" hint should push toward a catalog item that mentions it
        $items = ['Impresoras y Escaneres', 'Telefonia'];
        $this->assertSame('Impresoras y Escaneres', CatalogMatchSupport::inferCatalogMatch('la impresora no imprime', $items));
    }

    public function test_catalog_match_tokens_removes_stopwords_and_short_tokens(): void
    {
        $tokens = CatalogMatchSupport::catalogMatchTokens('la impresora de la oficina no funciona');
        $this->assertNotContains('la', $tokens);
        $this->assertNotContains('de', $tokens);
        $this->assertNotContains('no', $tokens);
        $this->assertContains('impresora', $tokens);
    }

    public function test_catalog_token_stem_removes_known_suffixes(): void
    {
        // 'impresora' doesn't end in any of the known suffixes (ciones/cion/oras/ores/icos/icas/ados/adas/es/s), so it is returned unchanged.
        $this->assertSame('impresora', CatalogMatchSupport::catalogTokenStem('impresora'));
        // 'ores' is checked (and matches) before the shorter 'es' suffix.
        $this->assertSame('computad', CatalogMatchSupport::catalogTokenStem('computadores'));
        $this->assertSame('pc', CatalogMatchSupport::catalogTokenStem('pc'));
    }

    public function test_catalog_match_hints_detects_known_keyword_groups(): void
    {
        $hints = CatalogMatchSupport::catalogMatchHints('mi impresora esta lenta');
        $this->assertContains('impresora', $hints);
        $this->assertContains('falla', $hints);
    }

    public function test_catalog_match_score_rewards_exact_token_matches_and_hints(): void
    {
        $score = CatalogMatchSupport::catalogMatchScore(['impresor'], ['impresora'], ['impresor'], 'impresora oficina');
        $this->assertGreaterThanOrEqual(18 + 22, $score);
    }

    public function test_catalog_match_score_is_zero_when_either_token_set_is_empty(): void
    {
        $this->assertSame(0, CatalogMatchSupport::catalogMatchScore([], ['x'], ['y'], 'z'));
        $this->assertSame(0, CatalogMatchSupport::catalogMatchScore(['x'], [], [], 'z'));
    }
}
