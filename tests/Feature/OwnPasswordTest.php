<?php

namespace Tests\Feature;

use Tests\TestCase;

class OwnPasswordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\Schema::create('usuarios_nova', function ($table) {
            $table->id();
            foreach (['uuid', 'usuario', 'nombre', 'apellido', 'rut', 'redmine_id', 'usuario_core', 'rol', 'estado', 'password', 'actualizado_at'] as $column) {
                $table->string($column)->nullable();
            }
        });
        foreach (['own-uuid', 'another-user'] as $id) {
            \Illuminate\Support\Facades\DB::table('usuarios_nova')->insert([
                'uuid' => $id, 'usuario' => $id, 'nombre' => 'Persona', 'apellido' => 'Prueba',
                'rol' => 'usuario', 'estado' => 'activo', 'password' => password_hash('Actual123!', PASSWORD_DEFAULT),
            ]);
        }
    }

    private function signedIn(): void
    {
        $this->withSession(['nova_user' => ['id' => 'own-uuid', 'username' => 'own-uuid', 'name' => 'Persona', 'role' => 'usuario'], 'nova_last_activity' => time()]);
    }

    private function assertPassword(string $id, string $password): void
    {
        $hash = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('uuid', $id)->value('password');
        $this->assertTrue(password_verify($password, $hash));
    }

    private function payload(): array
    {
        return ['current_password' => 'Actual123!', 'password' => 'Nueva123!', 'password_confirmation' => 'Nueva123!', 'id' => 'another-user'];
    }

    public function test_guests_cannot_read_or_submit_password_form(): void
    {
        $this->get('/mi-cuenta/password')->assertRedirect(route('login'));
        $this->post('/mi-cuenta/password', $this->payload())->assertRedirect(route('login'));
    }

    public function test_regular_user_can_load_partial_for_modal(): void
    {
        $this->signedIn();
        $this->get('/mi-cuenta/password', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertViewIs('nova.partials.password-form')
            ->assertSee('Contraseña actual')
            ->assertDontSee('<html', false)
            ->assertDontSee('Volver a NOVA');
    }

    public function test_direct_navigation_redirects_to_home(): void
    {
        $this->signedIn();
        $this->get('/mi-cuenta/password')->assertRedirect(route('home'));
    }

    public function test_changes_only_session_owner_even_if_another_id_is_submitted(): void
    {
        $this->signedIn();
        $this->post('/mi-cuenta/password', $this->payload())->assertRedirect(route('account.password'))->assertSessionHas('status');
        $this->assertPassword('own-uuid', 'Nueva123!');
        $this->assertPassword('another-user', 'Actual123!');
    }

    public function test_wrong_current_password_is_rejected_without_saving(): void
    {
        $this->signedIn();
        $this->post('/mi-cuenta/password', array_replace($this->payload(), ['current_password' => 'incorrecta']))->assertSessionHasErrors('current_password');
    }

    public function test_confirmation_mismatch_does_not_flash_passwords(): void
    {
        $this->signedIn();
        $this->post('/mi-cuenta/password', array_replace($this->payload(), ['password_confirmation' => 'distinta']))->assertSessionHasErrors('password');
        $this->assertArrayNotHasKey('current_password', session('_old_input', []));
        $this->assertArrayNotHasKey('password', session('_old_input', []));
    }

    public function test_short_password_is_rejected(): void
    {
        $this->signedIn();
        $this->post('/mi-cuenta/password', array_replace($this->payload(), ['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->assertPassword('own-uuid', 'Actual123!');
    }
    public function test_failed_database_write_does_not_report_success(): void
    {
        $this->signedIn();
        \Illuminate\Support\Facades\Schema::table('usuarios_nova', fn ($table) => $table->dropColumn('actualizado_at'));
        $this->post('/mi-cuenta/password', $this->payload())->assertSessionHasErrors('password')->assertSessionMissing('status');
        $this->assertPassword('own-uuid', 'Actual123!');
    }
    public function test_modal_receives_json_success_and_updated_csrf_token(): void
    {
        $this->signedIn();
        $this->postJson('/mi-cuenta/password', $this->payload())
            ->assertOk()->assertJsonStructure(['message', 'csrf_token']);
        $this->assertPassword('own-uuid', 'Nueva123!');
    }

    public function test_modal_receives_current_password_error_as_json(): void
    {
        $this->signedIn();
        $this->postJson('/mi-cuenta/password', array_replace($this->payload(), ['current_password' => 'incorrecta']))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertPassword('own-uuid', 'Actual123!');
    }
}
