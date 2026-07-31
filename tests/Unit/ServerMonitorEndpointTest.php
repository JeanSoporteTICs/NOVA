<?php

namespace Tests\Unit;

use App\Modulos\MonitorServidores\Controllers\ServerMonitorController;
use App\Modulos\MonitorServidores\Services\ServerMonitorService;
use App\Modulos\MonitorServidores\Services\ServerProbeService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class ServerMonitorEndpointTest extends TestCase
{
    public function test_https_destination_accepts_complete_url(): void
    {
        $endpoint = $this->normalize('https', 'https://www.hbvaldivia.cl/estado?simple=1', null);

        $this->assertSame('www.hbvaldivia.cl', $endpoint['host']);
        $this->assertSame(443, $endpoint['port']);
        $this->assertSame('/estado?simple=1', $endpoint['path']);
    }

    public function test_selected_http_method_adds_omitted_protocol(): void
    {
        $endpoint = $this->normalize('http', '10.63.123.249/salud', null);

        $this->assertSame('10.63.123.249', $endpoint['host']);
        $this->assertSame(80, $endpoint['port']);
        $this->assertSame('/salud', $endpoint['path']);
    }

    public function test_tcp_destination_keeps_separate_port(): void
    {
        $endpoint = $this->normalize('tcp', '10.63.123.249', 3306);

        $this->assertSame(['host' => '10.63.123.249', 'port' => 3306, 'path' => null], $endpoint);
    }

    public function test_icmp_destination_does_not_store_a_port(): void
    {
        $endpoint = $this->normalize('icmp', '10.63.123.249', null);

        $this->assertSame(['host' => '10.63.123.249', 'port' => null, 'path' => null], $endpoint);
    }

    public function test_icmp_rejects_shell_characters_without_running_a_process(): void
    {
        $result = (new ServerProbeService)->probe((object) [
            'tipo' => 'icmp',
            'host' => '127.0.0.1;whoami',
            'timeout_segundos' => 1,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame('El host ICMP no es válido.', $result['error']);
    }

    public function test_url_protocol_must_match_selected_method(): void
    {
        $this->expectException(ValidationException::class);

        $this->normalize('https', 'http://www.hbvaldivia.cl/', null);
    }

    public function test_numeric_ipv4_typo_is_rejected_before_probe(): void
    {
        $this->expectException(ValidationException::class);

        $this->normalize('icmp', '10.6.61.1444', null);
    }

    public function test_valid_ipv6_is_accepted_for_icmp(): void
    {
        $endpoint = $this->normalize('icmp', '2001:db8::1', null);

        $this->assertSame('2001:db8::1', $endpoint['host']);
    }

    public function test_maintenance_window_is_active_only_between_its_limits(): void
    {
        $service = (new ReflectionClass(ServerMonitorService::class))->newInstanceWithoutConstructor();
        $server = (object) [
            'mantenimiento_desde' => '2026-07-31 10:00:00',
            'mantenimiento_hasta' => '2026-07-31 12:00:00',
        ];

        $this->assertFalse($service->isMaintenanceActive($server, Carbon::parse('2026-07-31 09:59:59')));
        $this->assertTrue($service->isMaintenanceActive($server, Carbon::parse('2026-07-31 11:00:00')));
        $this->assertFalse($service->isMaintenanceActive($server, Carbon::parse('2026-07-31 12:00:01')));
    }

    public function test_monitor_relative_times_are_rendered_in_spanish(): void
    {
        Carbon::setTestNow('2026-07-31 14:00:00');
        $method = new ReflectionMethod(ServerMonitorController::class, 'relativeTime');
        $method->setAccessible(true);
        $controller = new ServerMonitorController;

        try {
            $this->assertSame('Ahora mismo', $method->invoke($controller, '2026-07-31 13:59:58'));
            $this->assertSame('hace 2 minutos', $method->invoke($controller, '2026-07-31 13:58:00'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{host:string,port:?int,path:?string}
     */
    private function normalize(string $type, string $destination, ?int $port): array
    {
        $method = new ReflectionMethod(ServerMonitorController::class, 'normalizeEndpoint');
        $method->setAccessible(true);

        return $method->invoke(new ServerMonitorController, $type, $destination, $port);
    }
}
