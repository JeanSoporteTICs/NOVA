<?php

namespace Tests\Unit;

use RedmineTic\Support\RedmineUrlSupport;
use Tests\TestCase;

/**
 * ETAPA B / Lote B6.3 — direct unit coverage of the pure Redmine
 * URL-building utilities extracted verbatim from RedmineDataRepository into
 * RedmineUrlSupport. No HTTP call is made here.
 */
class RedmineUrlSupportTest extends TestCase
{
    public function test_redmine_base_url_strips_project_path_and_trailing_slash(): void
    {
        $this->assertSame(
            'https://redmine.test',
            RedmineUrlSupport::redmineBaseUrl('https://redmine.test/projects/soporte/issues')
        );
    }

    public function test_redmine_base_url_preserves_port_and_prefix_before_projects(): void
    {
        $this->assertSame(
            'https://redmine.test:8443/tic',
            RedmineUrlSupport::redmineBaseUrl('https://redmine.test:8443/tic/projects/soporte')
        );
    }

    public function test_redmine_base_url_preserves_prefix_before_issues_endpoint(): void
    {
        $this->assertSame(
            'https://coresalud.cl/gp',
            RedmineUrlSupport::redmineBaseUrl('https://coresalud.cl/gp/issues.json')
        );
    }

    public function test_redmine_base_url_returns_empty_for_invalid_url(): void
    {
        $this->assertSame('', RedmineUrlSupport::redmineBaseUrl(''));
        $this->assertSame('', RedmineUrlSupport::redmineBaseUrl('not-a-url'));
    }

    public function test_redmine_categories_url_appends_issue_categories_json(): void
    {
        $this->assertSame(
            'https://redmine.test/issue_categories.json',
            RedmineUrlSupport::redmineCategoriesUrl('https://redmine.test/projects/soporte')
        );
        $this->assertSame('', RedmineUrlSupport::redmineCategoriesUrl(''));
    }

    public function test_redmine_custom_field_url_appends_field_id(): void
    {
        $this->assertSame(
            'https://redmine.test/custom_fields/11.json',
            RedmineUrlSupport::redmineCustomFieldUrl('https://redmine.test/projects/soporte', '11')
        );
    }

    public function test_redmine_issues_url_appends_issues_json(): void
    {
        $this->assertSame(
            'https://redmine.test/issues.json',
            RedmineUrlSupport::redmineIssuesUrl('https://redmine.test/projects/soporte')
        );
    }

    public function test_redmine_issue_url_preserves_installation_prefix(): void
    {
        $this->assertSame(
            'https://coresalud.cl/gp/issues/127765',
            RedmineUrlSupport::redmineIssueUrl('https://coresalud.cl/gp/issues.json', '127765')
        );
        $this->assertSame('', RedmineUrlSupport::redmineIssueUrl('https://coresalud.cl/gp/issues.json', ''));
    }
}
