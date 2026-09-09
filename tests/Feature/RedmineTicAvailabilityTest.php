<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use RedmineTic\Services\RedmineIssueSenderService;
use Tests\TestCase;

class RedmineTicAvailabilityTest extends TestCase
{
    public function test_probe_has_short_timeout_and_rejects_html_login_page(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) {
            $this->assertSame(5, $options['timeout']);
            $this->assertSame(3, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('GET', $request->method());
            $this->assertTrue($request->hasHeader('X-Redmine-API-Key', 'fake-token'));
            return Http::response('<html>Login</html>', 200);
        });
        $result = (new RedmineIssueSenderService)->checkAvailability(['platform_url' => 'https://redmine.example/sub/issues.json'], 'fake-token');
        $this->assertFalse($result['ok']);
    }

    public function test_probe_accepts_empty_issue_list(): void
    {
        Http::fake(['*' => Http::response(['issues' => []])]);
        $this->assertTrue((new RedmineIssueSenderService)->checkAvailability(['platform_url' => 'https://redmine.example/issues.json'], 'token')['ok']);
    }

    public function test_post_connection_failure_is_bounded_and_not_retried(): void
    {
        $calls = 0;
        Http::fake(function ($request, $options) use (&$calls) {
            $calls++;
            $this->assertSame(20, $options['timeout']);
            $this->assertSame(3, $options['connect_timeout']);
            throw new \Illuminate\Http\Client\ConnectionException('Simulated timeout');
        });
        $service = new RedmineIssueSenderService;
        $result = $service->send(['asunto' => 'Test'], ['platform_url' => 'https://redmine.example/issues.json'], 'token', fn () => 0);
        $this->assertSame(1, $calls);
        $this->assertSame(0, $result['http_code']);
        $this->assertTrue($service->shouldStopBatch($result));
    }

    public function test_auth_and_server_errors_stop_batch_but_validation_errors_do_not(): void
    {
        $service = new RedmineIssueSenderService;
        foreach ([0, 401, 403, 429, 500, 503] as $code) {
            $this->assertTrue($service->shouldStopBatch(['http_code' => $code, 'error' => '']));
        }
        $this->assertFalse($service->shouldStopBatch(['http_code' => 422, 'error' => '']));
    }
}
