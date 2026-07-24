<?php

namespace Tests\Unit;

use RedmineTic\Support\ArraySupport;
use Tests\TestCase;

/**
 * ETAPA B / Lote B6.3 — direct unit coverage of the small, generic array
 * utilities extracted verbatim from RedmineDataRepository into
 * ArraySupport.
 */
class ArraySupportTest extends TestCase
{
    public function test_count_by_state_counts_only_matching_states(): void
    {
        $reports = [
            ['estado' => 'pendiente'],
            ['estado' => 'procesado'],
            ['estado' => 'PROCESADA'],
            ['estado' => 'error'],
        ];

        $this->assertSame(2, ArraySupport::countByState($reports, ['procesado', 'procesada']));
        $this->assertSame(1, ArraySupport::countByState($reports, ['pendiente']));
        $this->assertSame(0, ArraySupport::countByState($reports, ['inexistente']));
    }

    public function test_history_row_key_prefers_id_then_redmine_id_then_fallback(): void
    {
        $this->assertSame('id:42', ArraySupport::historyRowKey(['id' => 42, 'redmine_id' => 7], 'fallback-key'));
        $this->assertSame('redmine:7', ArraySupport::historyRowKey(['redmine_id' => 7], 'fallback-key'));
        $this->assertSame('fallback:fallback-key', ArraySupport::historyRowKey([], 'fallback-key'));
    }
}
