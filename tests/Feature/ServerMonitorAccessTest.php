<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ServerMonitorAccessTest extends TestCase
{
    public function test_guest_cannot_open_server_monitor_dashboard(): void
    {
        $this->get('/monitoreo-servidores')->assertRedirect('/login');
    }

    public function test_guest_cannot_manage_servers(): void
    {
        $this->get('/monitoreo-servidores/servidores')->assertRedirect('/login');
    }

    public function test_guest_cannot_check_all_servers(): void
    {
        $this->post('/monitoreo-servidores/servidores/comprobar-todos')->assertRedirect('/login');
    }

    public function test_guest_cannot_manage_alert_recipients(): void
    {
        $this->get('/monitoreo-servidores/destinatarios')->assertRedirect('/login');
    }

    public function test_monitor_routes_are_registered(): void
    {
        $this->assertSame(
            '/monitoreo-servidores',
            parse_url(route('monitor.dashboard'), PHP_URL_PATH)
        );
        $this->assertSame(
            '/monitoreo-servidores/destinatarios',
            parse_url(route('monitor.recipients'), PHP_URL_PATH)
        );
        $this->assertSame(
            '/monitoreo-servidores/servidores/comprobar-todos',
            parse_url(route('monitor.servers.check-all'), PHP_URL_PATH)
        );
    }
}
