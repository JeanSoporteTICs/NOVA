<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use App\Modulos\RedmineMantencion\Services\MantencionRedmineSyncService;
use PHPUnit\Framework\TestCase;

class MantencionRedmineAvailabilityTest extends TestCase
{
    private function service(array $response): MantencionRedmineSyncService
    {
        return new class(new MantencionCoreImportService(), $response) extends MantencionRedmineSyncService {
            public array $requests = [];

            public function __construct(MantencionCoreImportService $core, private array $response)
            {
                parent::__construct($core);
            }

            protected function request_redmine(string $url, string $token, ?array $issue, int $timeout): array
            {
                $this->requests[] = compact('url', 'issue', 'timeout');
                return $this->response;
            }
        };
    }

    public function test_unavailable_api_stops_batch_before_any_report_changes_or_posts(): void
    {
        $service = $this->service(['http_code' => 0, 'body' => '', 'error' => 'Connection timed out']);
        $messages = [['id' => 'report-1', 'estado' => 'pendiente'], ['id' => 'report-2', 'estado' => 'pendiente']];
        $original = $messages;
        $result = $service->send_selected_messages($messages, ['report-1', 'report-2'], ['platform_url' => 'https://redmine.example/subpath'], 'test-token');
        self::assertSame(0, $result['attempts']);
        self::assertSame($original, $messages);
        self::assertCount(1, $service->requests);
        self::assertNull($service->requests[0]['issue']);
        self::assertSame(5, $service->requests[0]['timeout']);
        self::assertStringContainsString('No se enviaron reportes', $result['errors'][0]);
    }

    public function test_html_error_page_with_http_200_is_not_available_redmine_api(): void
    {
        $service = $this->service(['http_code' => 200, 'body' => '<html>Login</html>', 'error' => '']);
        self::assertFalse($service->check_redmine_availability(['platform_url' => 'https://redmine.example'], 'test-token')['ok']);
    }

    public function test_valid_empty_issue_list_is_available(): void
    {
        $service = $this->service(['http_code' => 200, 'body' => '{"issues":[]}', 'error' => '']);
        self::assertTrue($service->check_redmine_availability(['platform_url' => 'https://redmine.example'], 'test-token')['ok']);
    }

    public function test_auth_failure_explains_personal_credentials(): void
    {
        $service = $this->service(['http_code' => 401, 'body' => '', 'error' => '']);
        $result = $service->check_redmine_availability(['platform_url' => 'https://redmine.example'], 'test-token');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('API Key personal', $result['error']);
    }

    public function test_actual_post_has_bounded_timeout_and_is_not_retried(): void
    {
        $service = $this->service(['http_code' => 0, 'body' => '', 'error' => 'Connection timed out']);
        $service->send_redmine_issue(['subject' => 'Prueba'], ['platform_url' => 'https://redmine.example'], 'test-token');
        self::assertCount(1, $service->requests);
        self::assertSame(20, $service->requests[0]['timeout']);
        self::assertSame(['subject' => 'Prueba'], $service->requests[0]['issue']);
    }
}
