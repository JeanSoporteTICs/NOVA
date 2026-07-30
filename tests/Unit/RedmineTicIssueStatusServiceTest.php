<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedmineTic\Services\RedmineIssueStatusService;

class RedmineTicIssueStatusServiceTest extends TestCase
{
    private RedmineIssueStatusService $service;

    protected function setUp(): void
    {
        $this->service = new RedmineIssueStatusService;
    }

    public function test_normalizes_configured_status_options(): void
    {
        $this->assertSame([
            ['id' => 1, 'name' => 'Nueva'],
            ['id' => 2, 'name' => 'En curso'],
            ['id' => 5, 'name' => 'Cerrada'],
        ], $this->service->options([
            ['id' => '1', 'nombre' => 'Nueva'],
            ['id' => 2, 'name' => 'En curso'],
            ['id' => '5', 'nombre' => 'Cerrada'],
            ['id' => '', 'nombre' => 'Inválida'],
        ]));
    }

    public function test_finds_a_configured_status_name(): void
    {
        $options = $this->service->options([
            ['id' => 1, 'nombre' => 'Nueva'],
            ['id' => 5, 'nombre' => 'Cerrada'],
        ]);

        $this->assertSame('Cerrada', $this->service->statusName($options, 5));
        $this->assertNull($this->service->statusName($options, 99));
    }

    public function test_detects_the_current_status_by_id_or_name(): void
    {
        $this->assertTrue($this->service->isCurrentStatus(['id' => 2, 'name' => 'En curso'], 2, 'Otro'));
        $this->assertTrue($this->service->isCurrentStatus(['id' => 0, 'name' => 'En curso'], 2, 'En curso'));
        $this->assertFalse($this->service->isCurrentStatus(['id' => 1, 'name' => 'Nueva'], 2, 'En curso'));
    }

    public function test_rejects_invalid_updates_before_calling_redmine(): void
    {
        $invalidStatus = $this->service->update('https://redmine.test/issues/10', 0, 'token');
        $missingToken = $this->service->update('https://redmine.test/issues/10', 2, '');

        $this->assertFalse($invalidStatus['ok']);
        $this->assertSame(0, $invalidStatus['http_code']);
        $this->assertFalse($missingToken['ok']);
        $this->assertStringContainsString('API Key', $missingToken['error']);
    }
}
