<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use RedmineTic\Repositories\RedmineDataRepository;
use Tests\TestCase;

/**
 * ETAPA B / Lote B6.2 — confirms RedmineDataRepository::previewUsersFromRedmine()/
 * syncUsersFromRedmine() still behave identically through the facade after
 * delegating HTTP transport to RedmineMembershipSyncService. Only exercises
 * paths that never make a real outbound HTTP call — same discipline as
 * every other facade delegation test in this program (no valid API token or
 * platform_url is ever configured here).
 */
class RedmineFacadeMembershipSyncDelegationTest extends TestCase
{
    use DatabaseTransactions;

    private function facade(): RedmineDataRepository
    {
        return (new RedmineDataRepository())->forProject('redmine_tic');
    }

    public function test_preview_users_without_token_returns_the_configuration_error(): void
    {
        $result = $this->facade()->previewUsersFromRedmine('no-such-user-b62');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['items']);
        $this->assertSame('API Key Redmine personal no configurada.', $result['error']);
    }

    public function test_sync_users_without_token_returns_the_configuration_error(): void
    {
        $result = $this->facade()->syncUsersFromRedmine('no-such-user-b62');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame('API Key Redmine personal no configurada.', $result['error']);
    }

    public function test_sync_users_with_empty_selection_array_is_rejected_before_any_http_attempt(): void
    {
        // With no token configured, fetchRedmineMemberships() itself fails
        // first — but the contract for an explicitly-empty $selectedIds is
        // "select at least one user", not "not ok"; confirm the token check
        // still short-circuits earlier, so this message is never reached
        // without a valid token (matching original ordering: memberships are
        // fetched before the selection is validated).
        $result = $this->facade()->syncUsersFromRedmine('no-such-user-b62', []);

        $this->assertFalse($result['ok']);
        $this->assertSame('API Key Redmine personal no configurada.', $result['error']);
    }
}
