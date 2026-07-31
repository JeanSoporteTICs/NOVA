<?php

namespace Tests\Unit;

use App\Modulos\MonitorServidores\Controllers\ServerMonitorController;
use App\Modulos\MonitorServidores\Services\ServerProbeService;
use Illuminate\Validation\ValidationException;
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
