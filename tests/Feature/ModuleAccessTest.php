<?php

namespace Tests\Feature;

use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    public function test_unauthenticated_user_redirected_from_redmine_tic()
    {
        $response = $this->get('/redmine_tic/app');

        $response->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_redirected_from_mantencion()
    {
        $response = $this->get('/redmine-mantencion');

        $response->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_redirected_from_telegram()
    {
        $response = $this->get('/telegram');

        $response->assertRedirect(route('login'));
    }

    public function test_unauthenticated_user_redirected_from_administracion()
    {
        $response = $this->get('/administracion');

        $response->assertRedirect(route('login'));
    }

    public function test_health_endpoint_redmine_tic_requires_auth()
    {
        $response = $this->get('/redmine_tic/health.php');

        $response->assertRedirect(route('login'));
    }

    public function test_health_endpoint_redmine_tic_returns_json()
    {
        $response = $this->withoutMiddleware(\App\Http\Middleware\EnsureNovaAuthenticated::class)
            ->get('/redmine_tic/health.php');

        $response->assertStatus(200);
        $response->assertJson(['ok' => true, 'module' => 'redmine_tic']);
    }

    public function test_health_endpoint_mantencion_requires_auth()
    {
        $response = $this->get('/redmine-mantencion/health.php');

        $response->assertRedirect(route('login'));
    }

    public function test_login_route_is_named()
    {
        $this->assertNotNull(route('login'));
    }

    public function test_home_route_requires_nova_auth()
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('login'));
    }

    public function test_logout_route_accessible_via_post()
    {
        $response = $this->withSession(['nova_user' => $this->fakeSessionUser()])
            ->post(route('logout'), ['_token' => csrf_token()]);

        $response->assertRedirect(route('login'));
    }

    public function test_session_extend_post_only()
    {
        // GET to session/extend should fail or redirect
        $response = $this->get('/session/extend');

        $response->assertStatus(405);
    }

    /**
     * @return array<string,mixed>
     */
    private function fakeSessionUser(): array
    {
        return [
            'id' => 'test-uuid-5678',
            'username' => 'test_modulos',
            'name' => 'Test Modulos',
            'apellido' => 'Usuario',
            'rut' => '',
            'rut_sin_dv' => '',
            'role' => 'usuario',
            'source' => 'nova',
        ];
    }
}
