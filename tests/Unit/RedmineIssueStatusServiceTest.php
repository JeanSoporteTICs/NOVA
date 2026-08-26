<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\RedmineIssueStatusService;
use PHPUnit\Framework\TestCase;

class RedmineIssueStatusServiceTest extends TestCase
{
    private RedmineIssueStatusService $service;

    protected function setUp(): void
    {
        $this->service = new RedmineIssueStatusService;
    }

    public function test_exposes_only_the_supported_redmine_statuses(): void
    {
        $this->assertSame([
            1 => 'Nueva',
            2 => 'En curso',
            5 => 'Cerrada',
            6 => 'Rechazada',
        ], $this->service->statusOptions());
    }

    public function test_builds_issue_api_url_preserving_redmine_prefix(): void
    {
        $this->assertSame(
            'https://coresalud.cl/gp/issues/129852.json',
            $this->service->issueApiUrl(
                'https://coresalud.cl/gp/projects/backlog-mantencion-ti/issues.json',
                '129852'
            )
        );
    }

    public function test_builds_collection_api_url_outside_the_project_html_path(): void
    {
        $this->assertSame(
            'https://coresalud.cl/gp/issues.json',
            $this->service->issuesCollectionApiUrl(
                'https://coresalud.cl/gp/projects/backlog-mantencion-ti/issues.json'
            )
        );
        $this->assertSame(
            'https://coresalud.cl/gp/issues.json',
            $this->service->issuesCollectionApiUrl('https://coresalud.cl/gp/issues.json')
        );
    }

    public function test_rejects_invalid_issue_ids(): void
    {
        $this->assertSame('', $this->service->issueApiUrl('https://coresalud.cl/gp/issues.json', 'abc'));
        $this->assertSame('', $this->service->issueApiUrl('https://coresalud.cl/gp/issues.json', '129x852'));
    }

    public function test_detects_closed_and_rejected_status_names(): void
    {
        $this->assertTrue($this->service->isClosedStatus('Cerrada'));
        $this->assertTrue($this->service->isClosedStatus('Rechazada'));
        $this->assertFalse($this->service->isClosedStatus('En curso'));
    }

    public function test_remote_closed_flag_has_priority_over_name_fallback(): void
    {
        $this->assertTrue($this->service->isClosedStatus('Estado personalizado', true));
        $this->assertFalse($this->service->isClosedStatus('Cerrada', false));
    }

    public function test_rejects_an_unsupported_status_before_calling_redmine(): void
    {
        $result = $this->service->updateStatus('https://coresalud.cl/gp', '129852', 99, 'token');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['http_code']);
    }

    public function test_requires_the_users_personal_api_key_before_calling_redmine(): void
    {
        $result = $this->service->updateStatus('https://coresalud.cl/gp', '129852', 2, '');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('API Key', $result['error']);
    }
}
