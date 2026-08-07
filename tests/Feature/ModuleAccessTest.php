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
     * ETAPA B / Lote B5.5 — the maintenance bundle export/import flow was
     * retired (belonged to the pre-relational JSON persistence era, had no
     * UI/JS/command/cron caller). These routes must be fully gone, not just
     * unreachable from the UI.
     */
    public function test_removed_maintenance_bundle_routes_return_404()
    {
        $this->post('/redmine_tic/app/configuracion/importar')->assertStatus(404);
        $this->post('/redmine_tic/app/configuracion/exportar')->assertStatus(404);
    }

    public function test_removed_maintenance_bundle_route_names_no_longer_registered()
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('redmine.native.config.import'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('redmine.native.config.export'));
    }

    /**
     * Fase 3 de la migración de Redmine Mantención a Laravel nativo: el
     * catch-all `/redmine-mantencion/{path}` (resolvía cualquier ruta bajo
     * views/, controllers/ o la raíz del módulo contra el filesystem) fue
     * eliminado. Solo deben quedar rutas explícitas.
     */
    public function test_mantencion_catch_all_route_no_longer_registered()
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('redmine.mantencion.path'));
    }

    public function test_mantencion_direct_controller_file_access_returns_404()
    {
        // Antes del catch-all removal, esto habría ejecutado
        // controllers/dashboard.php directamente vía el bridge legacy.
        $this->get('/redmine-mantencion/controllers/dashboard.php')->assertStatus(404);
        $this->get('/redmine-mantencion/controllers/auth.php')->assertStatus(404);
    }

    public function test_mantencion_orphan_mini_mvc_entrypoints_return_404()
    {
        // app/ (mini-MVC huérfano, ver skill 07-redmine-mantencion) ya no es
        // alcanzable: index.php y session_touch.php no tenían ningún enlace
        // real apuntándoles.
        $this->get('/redmine-mantencion/index.php')->assertStatus(404);
        $this->get('/redmine-mantencion/session_touch.php')->assertStatus(404);
    }

    public function test_mantencion_login_php_redirects_to_central_login()
    {
        $response = $this->get('/redmine-mantencion/login.php');

        $response->assertRedirect(route('login'));
    }

    public function test_mantencion_logout_php_route_is_registered()
    {
        // El middleware nova.auth intercepta antes de llegar al passthrough
        // (igual que ocurría antes de esta migración con el catch-all), así
        // que sin sesión redirige a login como cualquier otra URL del módulo.
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('redmine.mantencion.logout-legacy'));

        $response = $this->get('/redmine-mantencion/logout.php');

        $response->assertRedirect(route('login'));
    }

    public function test_mantencion_nc_browser_ajax_endpoint_still_registered()
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('redmine.mantencion.nc-browser-ajax'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('redmine.mantencion.nextcloud-config-shim'));

        // Sin sesión NOVA, debe redirigir a login (igual que cualquier otra
        // pantalla del módulo), no ejecutar el endpoint sin autenticar.
        $this->get('/redmine-mantencion/views/Procedimientos/nc_browser_ajax.php')
            ->assertRedirect(route('login'));
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
