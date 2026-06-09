<?php

namespace Tests\Unit;

use App\Support\Nova\NovaAuditRepository;
use Tests\TestCase;

class NovaAuditRepositoryTest extends TestCase
{
    public function test_records_recent_audit_event(): void
    {
        $audit = app(NovaAuditRepository::class);
        $audit->record('test_event', 'Evento de prueba', ['ok' => true]);

        $items = $audit->recent(1);

        $this->assertSame('test_event', $items[0]['event'] ?? null);
        $this->assertSame('Evento de prueba', $items[0]['message'] ?? null);
    }
}
