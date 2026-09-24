<?php

namespace Tests\Unit;

use App\Services\Redmine\HistorySyncService;
use PHPUnit\Framework\TestCase;

class RedmineHistoryDeadlineTest extends TestCase
{
    private function service(array $responses): HistorySyncService
    {
        return new class($responses) extends HistorySyncService
        {
            public array $requests = [];

            private float $time = 0;

            public function __construct(private array $responses) {}

            protected function clock(): float
            {
                return $this->time;
            }

            protected function get(string $url, string $token, float $timeout): array
            {
                $this->requests[] = compact('url', 'timeout');
                $next = array_shift($this->responses);
                if ($next === null) {
                    throw new \LogicException('Unexpected external request');
                }
                $this->time += min((float) ($next['delay'] ?? 0), $timeout);

                return $next + ['transport' => false];
            }
        };
    }

    private function normalize(array $status): array
    {
        return ['id' => $status['id'], 'name' => $status['name'], 'closed' => false];
    }

    public function test_api_unavailable_or_html_stops_before_any_ticket_query(): void
    {
        foreach ([['code' => 503, 'data' => null], ['code' => 200, 'data' => '<html>Login</html>'], ['code' => 401, 'data' => []]] as $response) {
            $service = $this->service([$response]);
            $result = $service->statuses('https://example.test/issues.json', 'synthetic', ['1', '2'], $this->normalize(...));
            self::assertFalse($result['ok']);
            self::assertSame(['1', '2'], $result['pending_ids']);
            self::assertSame([], $result['statuses']);
            self::assertCount(1, $service->requests);
            self::assertSame(5.0, $service->requests[0]['timeout']);
        }
    }

    public function test_404_does_not_prevent_later_ticket_but_transport_failure_stops_rest(): void
    {
        $service = $this->service([
            ['code' => 200, 'data' => ['issues' => []]], ['code' => 404, 'data' => []],
            ['code' => 200, 'data' => ['issue' => ['status' => ['id' => 2, 'name' => 'En curso']]]],
            ['code' => 0, 'data' => null, 'transport' => true],
        ]);
        $result = $service->statuses('https://example.test/issues.json', 'synthetic', ['1', '2', '3', '4'], $this->normalize(...));
        self::assertFalse($result['complete']);
        self::assertFalse($result['statuses'][1]['available']);
        self::assertSame('En curso', $result['statuses'][2]['name']);
        self::assertSame(['3', '4'], $result['pending_ids']);
        self::assertCount(4, $service->requests);
    }

    public function test_total_deadline_includes_preflight_and_caps_final_call(): void
    {
        $responses = [['code' => 200, 'data' => ['issues' => []], 'delay' => 5]];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = ['code' => 200, 'data' => ['issue' => ['status' => ['id' => 2, 'name' => 'En curso']]], 'delay' => 5];
        }
        $service = $this->service($responses);
        $result = $service->statuses('https://example.test/issues.json', 'synthetic', range(1, 20), $this->normalize(...));
        self::assertCount(5, $result['statuses']);
        self::assertSame('6', $result['pending_ids'][0]);
        self::assertSame(4.0, $service->requests[5]['timeout']);
        self::assertFalse($result['complete']);
    }

    public function test_paginated_sync_preserves_progress_and_resumes_next_offset(): void
    {
        $service = $this->service([
            ['code' => 200, 'data' => ['issues' => []], 'delay' => 5],
            ['code' => 200, 'data' => ['issues' => [['id' => 12, 'status' => ['name' => 'Nueva']]], 'total_count' => 500], 'delay' => 20],
            ['code' => 0, 'data' => null, 'transport' => true, 'delay' => 20],
        ]);
        $result = $service->allStatuses('https://example.test/issues.json', 'synthetic', '6', 100);
        self::assertSame([12 => 'Nueva'], $result['statuses']);
        self::assertSame(101, $result['next_offset']);
        self::assertFalse($result['complete']);
        self::assertSame(4.0, $service->requests[2]['timeout']);
        self::assertStringContainsString('offset=100', $service->requests[1]['url']);
        $next = $this->service([
            ['code' => 200, 'data' => ['issues' => []]],
            ['code' => 200, 'data' => ['issues' => [['id' => 13, 'status' => ['name' => 'Cerrada']]], 'total_count' => 102]],
        ]);
        $resumed = $next->allStatuses('https://example.test/issues.json', 'synthetic', '6', $result['next_offset']);
        self::assertTrue($resumed['complete']);
        self::assertSame(0, $resumed['next_offset']);
        self::assertStringContainsString('offset=101', $next->requests[1]['url']);
    }
}
