<?php

namespace Tests\Unit;

use RedmineTic\Support\TextSupport;
use Tests\TestCase;

/**
 * ETAPA B / Lote B6.3 — direct unit coverage of the pure string/name
 * utilities extracted verbatim from RedmineDataRepository into TextSupport.
 */
class TextSupportTest extends TestCase
{
    public function test_normalize_telegram_report_text_strips_accents_and_punctuation(): void
    {
        $this->assertSame('impresora no imprime', TextSupport::normalizeTelegramReportText('¡Impresora, no imprime!'));
    }

    public function test_join_person_name_combines_first_and_last(): void
    {
        $this->assertSame('Juan Perez', TextSupport::joinPersonName('Juan', 'Perez'));
        $this->assertSame('Juan', TextSupport::joinPersonName('Juan', ''));
        $this->assertSame('Perez', TextSupport::joinPersonName('', 'Perez'));
    }

    public function test_join_person_name_avoids_duplicating_last_name_contained_in_first(): void
    {
        $this->assertSame('Juan Perez', TextSupport::joinPersonName('Juan Perez', 'Perez'));
    }

    public function test_telegram_user_display_name_falls_back_to_username(): void
    {
        $this->assertSame('Juan Perez', TextSupport::telegramUserDisplayName(['name' => 'Juan', 'apellido' => 'Perez']));
        $this->assertSame('jperez', TextSupport::telegramUserDisplayName(['username' => 'jperez']));
    }

    public function test_truncate_log_value_adds_ellipsis_past_the_limit(): void
    {
        $this->assertSame('abc', TextSupport::truncateLogValue('abc', 10));
        $this->assertSame('abcde...', TextSupport::truncateLogValue('abcdefghij', 5));
    }

    public function test_is_closed_issue_status_matches_known_keywords(): void
    {
        $this->assertTrue(TextSupport::isClosedIssueStatus('Cerrada'));
        $this->assertTrue(TextSupport::isClosedIssueStatus('Resolved'));
        $this->assertTrue(TextSupport::isClosedIssueStatus('Rechazada'));
        $this->assertFalse(TextSupport::isClosedIssueStatus('Nueva'));
    }

    public function test_name_tokens_filters_short_words_and_normalizes(): void
    {
        $this->assertSame(['juan', 'perez'], TextSupport::nameTokens('Juan Pérez'));
        $this->assertSame([], TextSupport::nameTokens('a b'));
    }

    public function test_name_tokens_match_requires_at_least_two_shared_tokens_when_available(): void
    {
        $this->assertTrue(TextSupport::nameTokensMatch('Juan Carlos Perez', 'Perez Juan Carlos'));
        $this->assertFalse(TextSupport::nameTokensMatch('Juan Perez', 'Ana Soto'));
        $this->assertFalse(TextSupport::nameTokensMatch('', 'Juan Perez'));
    }
}
