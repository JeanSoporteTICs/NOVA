<?php

namespace Tests\Unit;

use RedmineTic\Services\RedmineMembershipSyncService;
use Tests\TestCase;

/**
 * ETAPA B / Lote B6.2 — direct unit coverage of the Redmine membership
 * transport now living in RedmineMembershipSyncService, extracted verbatim
 * from RedmineDataRepository::fetchRedmineMemberships()'s pagination loop
 * and ::redmineUserName()'s name-resolution fallback chain.
 *
 * No real HTTP/DB is used — $httpGetJson is always a fake callback. The
 * HTML-scrape fallback inside resolveUserName() (private getRedmineHtml(),
 * only reached when the JSON detail lookup leaves a name empty) is
 * deliberately not exercised here: it always issues a real cURL call with
 * no injection seam, same safety discipline as every other untested-for-
 * safety HTTP branch in this program (e.g. RedmineIssueSenderService's
 * success path in B5.4). Tests that would reach it instead use an empty
 * $baseUrl/$token to short-circuit straight to the splitRedmineName()
 * fallback, which covers the same "final fallback" outcome without any
 * network access.
 */
class RedmineMembershipSyncServiceTest extends TestCase
{
    private function service(): RedmineMembershipSyncService
    {
        return new RedmineMembershipSyncService();
    }

    private function jsonResponse(int $httpCode, array $body): array
    {
        return ['http_code' => $httpCode, 'body' => json_encode($body), 'error' => ''];
    }

    // ---- fetchMemberships() ----

    public function test_fetch_memberships_returns_single_page_result(): void
    {
        $calls = [];
        $httpGetJson = function (string $url, string $token) use (&$calls): array {
            $calls[] = $url;
            return $this->jsonResponse(200, [
                'memberships' => [['id' => 1, 'user' => ['id' => '10', 'name' => 'Juan Perez']]],
                'total_count' => 1,
            ]);
        };

        $result = $this->service()->fetchMemberships('https://redmine.test', 'tok', '5', $httpGetJson);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['memberships']);
        $this->assertCount(1, $calls);
        $this->assertStringContainsString('/projects/5/memberships.json', $calls[0]);
    }

    public function test_fetch_memberships_paginates_until_total_count_reached(): void
    {
        $callCount = 0;
        $httpGetJson = function (string $url, string $token) use (&$callCount): array {
            $callCount++;
            $offset = $callCount === 1 ? 0 : 100;
            return $this->jsonResponse(200, [
                'memberships' => array_fill(0, 100, ['id' => $offset, 'user' => ['id' => (string) $offset, 'name' => 'User']]),
                'total_count' => 150,
            ]);
        };

        $result = $this->service()->fetchMemberships('https://redmine.test', 'tok', '5', $httpGetJson);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $callCount);
        $this->assertCount(200, $result['memberships']);
    }

    public function test_fetch_memberships_returns_error_on_non_2xx_http_code(): void
    {
        $httpGetJson = fn (string $url, string $token): array => ['http_code' => 403, 'body' => 'Forbidden', 'error' => ''];

        $result = $this->service()->fetchMemberships('https://redmine.test', 'tok', '5', $httpGetJson);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['memberships']);
        $this->assertStringContainsString('403', $result['error']);
    }

    public function test_fetch_memberships_returns_error_on_transport_failure(): void
    {
        $httpGetJson = fn (string $url, string $token): array => ['http_code' => 0, 'body' => '', 'error' => 'Connection refused'];

        $result = $this->service()->fetchMemberships('https://redmine.test', 'tok', '5', $httpGetJson);

        $this->assertFalse($result['ok']);
        $this->assertSame('Connection refused', $result['error']);
    }

    // ---- resolveUserName() ----

    public function test_resolve_user_name_uses_firstname_lastname_when_present_without_any_http_call(): void
    {
        $called = false;
        $httpGetJson = function () use (&$called): array {
            $called = true;
            return ['http_code' => 200, 'body' => '{}', 'error' => ''];
        };

        [$first, $last] = $this->service()->resolveUserName(
            ['id' => '10', 'firstname' => 'Juan', 'lastname' => 'Perez'],
            'https://redmine.test',
            'tok',
            $httpGetJson
        );

        $this->assertSame('Juan', $first);
        $this->assertSame('Perez', $last);
        $this->assertFalse($called);
    }

    public function test_resolve_user_name_falls_back_to_json_detail_lookup(): void
    {
        $httpGetJson = fn (string $url, string $token): array => $this->jsonResponse(200, [
            'user' => ['firstname' => 'Ana', 'lastname' => 'Soto'],
        ]);

        [$first, $last] = $this->service()->resolveUserName(
            ['id' => '11', 'name' => 'Ana Soto'],
            'https://redmine.test',
            'tok',
            $httpGetJson
        );

        $this->assertSame('Ana', $first);
        $this->assertSame('Soto', $last);
    }

    public function test_resolve_user_name_falls_back_to_splitting_the_display_name_when_no_transport_is_available(): void
    {
        $called = false;
        $httpGetJson = function () use (&$called): array {
            $called = true;
            return ['http_code' => 200, 'body' => '{}', 'error' => ''];
        };

        // Empty base URL short-circuits the whole JSON/HTML lookup block,
        // landing directly on the splitRedmineName() fallback — without any
        // network access, including the untestable HTML-scrape branch.
        [$first, $last] = $this->service()->resolveUserName(
            ['id' => '12', 'name' => 'Carlos Mora Diaz'],
            '',
            '',
            $httpGetJson
        );

        $this->assertSame('Carlos', $first);
        $this->assertSame('Mora Diaz', $last);
        $this->assertFalse($called);
    }

    public function test_resolve_user_name_returns_empty_strings_when_nothing_is_resolvable(): void
    {
        $httpGetJson = fn (): array => ['http_code' => 200, 'body' => '{}', 'error' => ''];

        [$first, $last] = $this->service()->resolveUserName(['id' => '13'], '', '', $httpGetJson);

        $this->assertSame('', $first);
        $this->assertSame('', $last);
    }

    public function test_resolve_user_identity_fetches_login_even_when_membership_already_has_names(): void
    {
        $calls = 0;
        $httpGetJson = function (string $url, string $token) use (&$calls): array {
            $calls++;

            return $this->jsonResponse(200, [
                'user' => [
                    'id' => 44,
                    'login' => '12345678-5',
                    'firstname' => 'Maria',
                    'lastname' => 'Rojas',
                    'mail' => 'maria@example.test',
                ],
            ]);
        };

        $identity = $this->service()->resolveUserIdentity(
            ['id' => '44', 'firstname' => 'Maria', 'lastname' => 'Rojas'],
            'https://redmine.test',
            'tok',
            $httpGetJson
        );

        $this->assertSame(1, $calls);
        $this->assertSame('12345678-5', $identity['login']);
        $this->assertSame('Maria', $identity['firstname']);
        $this->assertSame('Rojas', $identity['lastname']);
        $this->assertSame('maria@example.test', $identity['mail']);
    }
}
