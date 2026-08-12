<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureNovaAuthenticated;
use App\Modulos\RedmineMantencion\Services\MantencionNextcloudService;
use Tests\TestCase;

final class MantencionNextcloudUserManagementTest extends TestCase
{
    public function test_management_view_is_available_and_explains_the_credentials_gate(): void
    {
        $this->withoutMiddleware(EnsureNovaAuthenticated::class)
            ->withSession([
                'nova_user' => [
                    'id' => 'nextcloud-management-test',
                    'name' => 'Gestión',
                    'apellido' => 'Nextcloud',
                    'role' => 'root',
                    'legacy' => [
                        'id' => 'nextcloud-management-test',
                        'nombre' => 'Gestión Nextcloud',
                        'rol' => 'root',
                    ],
                ],
            ]);

        $this->get('/redmine-mantencion/app/integraciones-nextcloud-usuarios/administrar')
            ->assertOk()
            ->assertSee('Contraseñas de usuarios Nextcloud')
            ->assertSee('Conecta una cuenta administradora')
            ->assertSee('Configurar credenciales')
            ->assertSee('Crear usuarios')
            ->assertSee('Administrar usuarios');
    }

    public function test_management_directory_reuses_cached_groups_without_requesting_the_remote_catalog(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/RedmineMantencion/Controllers/NextcloudGestionUsuariosController.php');
        $service = file_get_contents($root.'/RedmineMantencion/Services/MantencionNextcloudService.php');

        self::assertIsString($controller);
        self::assertIsString($service);
        self::assertStringContainsString('nextcloud_cached_groups()', $controller);
        self::assertStringNotContainsString('nextcloud_directory_groups()', $controller);
        self::assertStringNotContainsString('function nextcloud_directory_groups', $service);
    }

    public function test_group_users_are_loaded_only_for_the_requested_group(): void
    {
        $service = app(MantencionNextcloudService::class);
        $requests = [];
        $requester = static function (array $cfg, string $method, string $path, array $payload) use (&$requests): array {
            $requests[] = compact('method', 'path', 'payload');

            return ['ok' => true, 'statuscode' => 100, 'data' => ['users' => [
                ['id' => 'bob', 'displayname' => 'Bob Constructor', 'email' => 'bob@example.test', 'quota' => ['quota' => -3]],
                ['id' => 'alice', 'displayname' => 'Alice Ejemplo', 'email' => 'alice@example.test', 'quota' => ['quota' => 5368709120]],
                ['id' => 'alice', 'displayname' => 'Alice Ejemplo'],
            ]]];
        };

        $result = $service->nextcloud_group_users('Mesa de Ayuda', [
            'url' => 'https://cloud.example.test',
            'admin_user' => 'admin',
            'admin_pass' => 'app-password',
        ], $requester);

        self::assertTrue($result['ok']);
        self::assertCount(1, $requests);
        self::assertSame('/groups/Mesa%20de%20Ayuda/users/details', $requests[0]['path']);
        self::assertSame(['alice', 'bob'], array_column($result['users'], 'id'));
        self::assertSame(['Alice Ejemplo', 'Bob Constructor'], array_column($result['users'], 'display_name'));
        self::assertSame(['alice@example.test', 'bob@example.test'], array_column($result['users'], 'email'));
        self::assertSame(['5 GB', 'Ilimitada'], array_column($result['users'], 'quota_label'));
    }

    public function test_group_users_fall_back_to_the_legacy_id_endpoint(): void
    {
        $service = app(MantencionNextcloudService::class);
        $paths = [];
        $requester = static function (array $cfg, string $method, string $path) use (&$paths): array {
            $paths[] = $path;
            if (str_ends_with($path, '/users/details')) {
                return ['ok' => false, 'http' => 404, 'statuscode' => 404, 'message' => 'Not found'];
            }

            return ['ok' => true, 'statuscode' => 100, 'data' => ['users' => ['alice']]];
        };

        $result = $service->nextcloud_group_users('Mesa de Ayuda', [
            'url' => 'https://cloud.example.test',
            'admin_user' => 'admin',
            'admin_pass' => 'app-password',
        ], $requester);

        self::assertTrue($result['ok']);
        self::assertSame([
            '/groups/Mesa%20de%20Ayuda/users/details',
            '/groups/Mesa%20de%20Ayuda',
        ], $paths);
        self::assertSame(['alice'], array_column($result['users'], 'id'));
    }

    public function test_password_suggestion_reuses_the_new_user_generation_rule(): void
    {
        $service = app(MantencionNextcloudService::class);

        $result = $service->nextcloud_password_suggestion([
            'userid' => '19006667-6',
            'display_name' => 'Jean Cortés Lorca',
        ]);

        self::assertTrue($result['ok']);
        self::assertSame(
            $service->nextcloud_generate_password('Jean Cortés Lorca', '19006667-6'),
            $result['password']
        );
    }

    public function test_password_action_ignores_every_profile_field_from_the_request(): void
    {
        $service = app(MantencionNextcloudService::class);
        $requests = [];
        $requester = static function (array $cfg, string $method, string $path, array $payload) use (&$requests): array {
            $requests[] = compact('method', 'path', 'payload');

            return ['ok' => true, 'statuscode' => 100, 'message' => 'OK'];
        };

        $result = $service->nextcloud_change_user_password([
            'userid' => 'jdoe',
            'displayname' => 'Nombre manipulado',
            'email' => 'manipulado@example.test',
            'quota' => 'none',
            'fields' => ['displayname', 'email', 'quota'],
            'password' => 'NuevaClaveSegura!2026',
            'password_confirmation' => 'NuevaClaveSegura!2026',
        ], [
            'url' => 'https://cloud.example.test',
            'admin_user' => 'admin',
            'admin_pass' => 'app-password',
        ], $requester);

        self::assertTrue($result['ok']);
        self::assertCount(1, $requests);
        self::assertSame('/users/jdoe', $requests[0]['path']);
        self::assertSame([
            'key' => 'password',
            'value' => 'NuevaClaveSegura!2026',
        ], $requests[0]['payload']);
        self::assertStringNotContainsString('NuevaClaveSegura', $result['message']);
    }

    public function test_password_confirmation_is_validated_before_calling_nextcloud(): void
    {
        $service = app(MantencionNextcloudService::class);
        $calls = 0;
        $requester = static function () use (&$calls): array {
            $calls++;

            return ['ok' => true];
        };

        $result = $service->nextcloud_change_user_password([
            'userid' => 'jdoe',
            'password' => 'ClaveSegura!2026',
            'password_confirmation' => 'OtraClave!2026',
        ], [
            'url' => 'https://cloud.example.test',
            'admin_user' => 'admin',
            'admin_pass' => 'app-password',
        ], $requester);

        self::assertFalse($result['ok']);
        self::assertSame(0, $calls);
        self::assertStringContainsString('no coincide', $result['message']);
    }

    public function test_management_view_groups_lazily_and_exposes_only_the_password_drawer(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/resources/views/redmine-mantencion/integraciones-nextcloud-gestion-usuarios.blade.php');
        $css = file_get_contents($root.'/RedmineMantencion/assets/css/nextcloud-gestion-usuarios.css');

        self::assertIsString($view);
        self::assertIsString($css);
        self::assertSame(1, substr_count($view, 'id="nextcloudUserPasswordDrawer"'));
        self::assertStringContainsString('class="offcanvas offcanvas-end nextcloud-password-drawer"', $view);
        self::assertStringNotContainsString('class="modal fade', $view);
        self::assertStringContainsString('id="nextcloud-generate-password"', $view);
        self::assertStringContainsString('class="nextcloud-password-feedback"', $view);
        self::assertStringContainsString('password?.addEventListener(\'input\', clearPasswordFeedback)', $view);
        self::assertStringNotContainsString('Contraseña generada con la regla de creación de usuarios.', $view);
        self::assertStringNotContainsString('invalid-feedback d-block', $view);
        self::assertStringContainsString('data-password-suggestion-url', $view);
        self::assertStringContainsString('user?.display_name', $view);
        self::assertStringContainsString("['Nombre y apellidos', 'Usuario', 'Correo', 'Cuota asignada', 'Contraseña']", $view);
        self::assertStringContainsString("account.className = 'nextcloud-group-user-account'", $view);
        self::assertStringContainsString("identity.className = 'nextcloud-group-user-name'", $view);
        self::assertStringContainsString("emailCell.className = 'nextcloud-group-user-email'", $view);
        self::assertStringContainsString("quotaCell.className = 'nextcloud-group-user-quota'", $view);
        self::assertStringContainsString('data-nextcloud-user-search', $view);
        self::assertStringContainsString('data-user-table-toolbar', $view);
        self::assertStringContainsString('id="nextcloud-user-search-<?=', $view);
        self::assertStringContainsString('Nombre, apellido o RUT', $view);
        self::assertStringContainsString('row.dataset.userSearch', $view);
        self::assertStringContainsString("applyUserFilter(card, event.currentTarget.value)", $view);
        self::assertStringContainsString("document.createElement('span')", $view);
        self::assertStringNotContainsString('mailto:', $view);
        self::assertStringContainsString('nextcloud-password-action', $view);
        self::assertStringContainsString('btn-action btn-action-password btn-action-sm nextcloud-password-action', $view);
        self::assertStringContainsString('button.title = `Cambiar contraseña de ${displayName}`', $view);
        self::assertStringNotContainsString('<span>Cambiar</span>', $view);
        self::assertStringContainsString('name="password_confirmation"', $view);
        self::assertStringContainsString('Todos los grupos', $view);
        self::assertStringContainsString('data-nextcloud-group-card', $view);
        self::assertStringContainsString('data-nextcloud-group-toggle', $view);
        self::assertStringContainsString('Ver usuarios', $view);
        self::assertStringContainsString("url.searchParams.set('group', group)", $view);
        self::assertStringContainsString('data-group-users-url', $view);
        self::assertStringContainsString('$groupsConfigUrl', $view);
        self::assertStringNotContainsString('data-nextcloud-refresh', $view);
        self::assertStringNotContainsString('name="displayname"', $view);
        self::assertStringNotContainsString('name="email"', $view);
        self::assertStringNotContainsString('name="quota"', $view);
        self::assertStringNotContainsString('data-nextcloud-user-row', $view);
        self::assertStringNotContainsString('<style>', $view);
        self::assertStringContainsString('@media (max-width: 767.98px)', $css);
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('--bs-offcanvas-width', $css);
        self::assertStringContainsString('--bs-offcanvas-width: min(520px, 100vw)', $css);
        self::assertStringContainsString('.nextcloud-password-feedback[hidden]', $css);
        self::assertStringContainsString('.nextcloud-password-generator', $css);
        self::assertStringContainsString('.nextcloud-group-users-head', $css);
        self::assertStringContainsString('.nextcloud-group-user-table-toolbar', $css);
        self::assertStringContainsString('grid-template-columns: minmax(220px, 1.15fr) minmax(125px, .65fr) minmax(210px, 1fr) minmax(120px, .55fr) 86px', $css);
        self::assertStringContainsString('grid-template-columns: repeat(6, minmax(0, 1fr))', $css);
        self::assertStringContainsString('.nextcloud-group-card.is-open', $css);
        self::assertStringContainsString('grid-column: 1 / -1', $css);
        self::assertStringContainsString('@media (max-width: 1199.98px)', $css);
    }

    public function test_password_suggestion_route_is_registered(): void
    {
        self::assertSame(
            url('/redmine-mantencion/app/integraciones-nextcloud-usuarios/administrar/generar-password'),
            route('redmine.mantencion.nextcloud-users.password-suggestion')
        );
    }

    public function test_every_user_directory_navigation_uses_the_shared_nextcloud_loader(): void
    {
        $navbar = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/views/partials/navbar.php');

        self::assertIsString($navbar);
        self::assertStringContainsString("includes('/integraciones-nextcloud-usuarios')", $navbar);
        self::assertStringContainsString("rawHref === '#'", $navbar);
        self::assertStringContainsString("link.hasAttribute('data-bs-toggle')", $navbar);
        self::assertStringContainsString("title: isManagement ? 'Cargando grupos guardados'", $navbar);
        self::assertStringContainsString("provider: 'nextcloud'", $navbar);
        self::assertStringContainsString('window.appUi?.setIntegrationLoading?.(false);', $navbar);
    }
}
