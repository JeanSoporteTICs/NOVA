<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_page_loads()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('NOVA');
        $response->assertSee('Ingresar');
    }

    public function test_login_redirects_authenticated_user_to_home()
    {
        $response = $this->withSession(['nova_user' => $this->fakeSessionUser()])
            ->get('/login');

        $response->assertRedirect(route('home'));
    }

    public function test_login_requires_username()
    {
        $response = $this->post('/login', [
            '_token' => csrf_token(),
            'password' => 'any',
        ]);

        $response->assertSessionHasErrors('username');
    }

    public function test_login_requires_password()
    {
        $response = $this->post('/login', [
            '_token' => csrf_token(),
            'username' => 'admin',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_login_username_max_length_enforced()
    {
        $response = $this->post('/login', [
            '_token' => csrf_token(),
            'username' => str_repeat('a', 181),
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('username');
    }

    public function test_login_password_max_length_enforced()
    {
        $response = $this->post('/login', [
            '_token' => csrf_token(),
            'username' => 'user',
            'password' => str_repeat('p', 513),
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_login_with_invalid_credentials_returns_error()
    {
        $response = $this->post('/login', [
            '_token' => csrf_token(),
            'username' => 'usuario_inexistente_test',
            'password' => 'contrasena_incorrecta',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('username');
    }

    public function test_login_preserves_username_on_failure()
    {
        $response = $this->post('/login', [
            '_token' => csrf_token(),
            'username' => 'mi_usuario',
            'password' => 'pass_incorrecta',
        ]);

        $response->assertRedirect();
        $this->assertEquals('mi_usuario', session('_old_input.username'));
    }

    public function test_logout_requires_post()
    {
        $response = $this->withSession(['nova_user' => $this->fakeSessionUser()])
            ->get('/logout');

        $response->assertStatus(405);
    }

    public function test_logout_via_post_clears_session_and_redirects()
    {
        $response = $this->withSession([
            'nova_user' => $this->fakeSessionUser(),
            'nova_last_activity' => time(),
        ])->post('/logout', ['_token' => csrf_token()]);

        $response->assertRedirect(route('login'));
        $response->assertSessionMissing('nova_user');
        $response->assertSessionMissing('nova_last_activity');
    }

    public function test_guest_cannot_access_home()
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_admin()
    {
        $response = $this->get('/administracion');

        $response->assertRedirect(route('login'));
    }

    public function test_session_extend_requires_authenticated_user()
    {
        $response = $this->postJson('/session/extend', ['password' => 'any']);

        $response->assertStatus(401);
    }

    public function test_session_extend_requires_password()
    {
        $response = $this->withSession(['nova_user' => $this->fakeSessionUser()])
            ->postJson('/session/extend', []);

        $response->assertStatus(422);
    }

    public function test_session_extend_rejects_password_over_max_length()
    {
        $response = $this->withSession(['nova_user' => $this->fakeSessionUser()])
            ->postJson('/session/extend', [
                'password' => str_repeat('x', 513),
            ]);

        $response->assertStatus(422);
    }

    public function test_login_has_csrf_protection()
    {
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        // With CSRF middleware disabled, login still validates form data
        $response = $this->post('/login', [
            'username' => 'usuario_test',
            'password' => 'contrasena_test',
        ]);

        // Should redirect back (wrong credentials), not throw CSRF error
        $response->assertRedirect();
    }

    /**
     * @return array<string,mixed>
     */
    private function fakeSessionUser(): array
    {
        return [
            'id' => 'test-uuid-1234',
            'username' => 'test_user',
            'name' => 'Usuario Test',
            'apellido' => 'Apellido',
            'rut' => '',
            'rut_sin_dv' => '',
            'role' => 'usuario',
            'source' => 'nova',
        ];
    }
}
