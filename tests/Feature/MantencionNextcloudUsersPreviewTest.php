<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureNovaAuthenticated;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class MantencionNextcloudUsersPreviewTest extends TestCase
{
    public function test_csv_preview_survives_the_post_redirect_get_flow(): void
    {
        $url = '/redmine-mantencion/app/integraciones-nextcloud-usuarios';
        $csv = implode("\n", [
            'rut;nombre;correo;servicio',
            '12.345.678-5;Persona de Prueba;preview.nextcloud@example.test;Grupo de Prueba',
        ]);

        $this->withoutMiddleware(EnsureNovaAuthenticated::class)
            ->withSession([
                'nova_user' => [
                    'id' => 'nextcloud-preview-test',
                    'name' => 'Preview',
                    'apellido' => 'Nextcloud',
                    'role' => 'root',
                    'legacy' => [
                        'id' => 'nextcloud-preview-test',
                        'nombre' => 'Preview Nextcloud',
                        'rol' => 'root',
                    ],
                ],
            ]);

        $response = $this->post($url, [
            'action' => 'import_nextcloud_users',
            'csrf_token' => csrf_token(),
            'solicitante_nombre' => 'Persona Solicitante',
            'solicitante_rut' => '12.345.678-5',
            'solicitante_correo' => 'solicitante@example.test',
            'nextcloud_file' => UploadedFile::fake()->createWithContent('usuarios.csv', $csv),
        ]);

        $response->assertStatus(303);
        $response->assertRedirect(url($url));

        $this->get($url)
            ->assertOk()
            ->assertSee('Previsualización de envío')
            ->assertSee('nextcloud-table-wrap', false)
            ->assertSee('nextcloud-user-id', false)
            ->assertSee('name="selected_users[]"', false)
            ->assertSee('id="nextcloud-bulk-group"', false)
            ->assertSee('id="nextcloud-bulk-quota"', false)
            ->assertSee('Grupo para seleccionados')
            ->assertSee('Cuota para seleccionados')
            ->assertSee('No cambiar grupo')
            ->assertSee('No cambiar cuota')
            ->assertSee('nextcloud-row-group-select', false)
            ->assertSee('select2.min.js', false)
            ->assertDontSee('nextcloud-group-search', false)
            ->assertSee('Persona Solicitante')
            ->assertSee('12345678-5')
            ->assertSee('solicitante@example.test')
            ->assertSee('12345678')
            ->assertSee('Persona de Prueba')
            ->assertSee('preview.nextcloud@example.test')
            ->assertDontSee('Normalizado desde');
    }

    public function test_confirmation_requires_at_least_one_selected_user(): void
    {
        $url = '/redmine-mantencion/app/integraciones-nextcloud-usuarios';
        $previewUser = [
            'userid' => '12345678',
            'password' => 'ClaveTemporal123!',
            'displayName' => 'Persona de Prueba',
            'email' => 'preview.nextcloud@example.test',
            'groups' => ['Grupo de Prueba'],
            'quota' => '',
            'language' => 'es',
            'email_valid' => true,
        ];

        $this->withoutMiddleware(EnsureNovaAuthenticated::class)
            ->withSession([
                'nova_user' => [
                    'id' => 'nextcloud-preview-test',
                    'name' => 'Preview',
                    'apellido' => 'Nextcloud',
                    'role' => 'root',
                    'legacy' => ['id' => 'nextcloud-preview-test', 'nombre' => 'Preview Nextcloud', 'rol' => 'root'],
                ],
                'mantencion_nextcloud_preview' => ['users' => [$previewUser]],
            ]);

        $this->post($url, [
            'action' => 'confirm_nextcloud_import',
            'csrf_token' => csrf_token(),
            'users' => [$previewUser],
        ])
            ->assertOk()
            ->assertSee('Selecciona al menos un usuario para crear.')
            ->assertSee('Previsualización de envío');
    }

    public function test_server_filters_rows_using_the_selected_checkbox_indexes(): void
    {
        $service = app(\App\Modulos\RedmineMantencion\Services\MantencionNextcloudService::class);
        $rows = [
            ['userid' => '11111111', 'password' => 'uno'],
            ['userid' => '22222222', 'password' => 'dos'],
            ['userid' => '33333333', 'password' => 'tres'],
        ];

        $selected = $service->nextcloud_selected_rows($rows, ['0', '2', '2', '99']);

        self::assertSame(['11111111', '33333333'], array_column($selected, 'userid'));
    }

    public function test_requester_is_validated_and_rut_is_stored_canonically(): void
    {
        $service = app(\App\Modulos\RedmineMantencion\Services\MantencionNextcloudService::class);

        $valid = $service->nextcloud_requester_from_input([
            'solicitante_nombre' => 'Persona Solicitante',
            'solicitante_rut' => '12.345.678-5',
            'solicitante_correo' => 'SOLICITANTE@EXAMPLE.TEST',
        ]);
        self::assertSame('12345678-5', $valid['requester']['solicitante_rut'] ?? null);
        self::assertSame('solicitante@example.test', $valid['requester']['solicitante_correo'] ?? null);
        self::assertSame('', $valid['requester']['solicitante'] ?? null);

        $optional = $service->nextcloud_requester_from_input([]);
        self::assertArrayNotHasKey('error', $optional);
        self::assertSame('', $optional['requester']['solicitante_nombre'] ?? null);
        self::assertSame('', $optional['requester']['solicitante_correo'] ?? null);

        $invalid = $service->nextcloud_requester_from_input([
            'solicitante_correo' => 'correo-invalido',
        ]);
        self::assertSame('El correo del solicitante no es válido.', $invalid['error'] ?? null);

        $invalidRut = $service->nextcloud_requester_from_input([
            'solicitante_nombre' => 'Persona Solicitante',
            'solicitante_rut' => '12.345.678-9',
            'solicitante_correo' => 'solicitante@example.test',
        ]);
        self::assertSame('El RUT del solicitante no es válido.', $invalidRut['error'] ?? null);
    }

    public function test_requester_form_autoformats_rut_and_validates_rut_and_email_in_the_browser(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/integraciones-nextcloud-usuarios.blade.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString('id="nextcloud-import-form"', $view);
        self::assertStringContainsString('maxlength="12"', $view);
        self::assertStringContainsString('Nombre del solicitante <span class="text-muted fw-normal">(opcional)</span>', $view);
        self::assertStringContainsString('Correo <span class="text-muted fw-normal">(opcional)</span>', $view);
        self::assertStringContainsString("const valid = !hasValue || isValidRequesterEmail(requesterEmail.value)", $view);
        self::assertDoesNotMatchRegularExpression('/name="solicitante_(?:nombre|correo)"[^>]*\srequired(?:\s|>)/', $view);
        self::assertStringContainsString('Los puntos y el guion se añaden automáticamente.', $view);
        self::assertStringContainsString('function formatRequesterRut(value)', $view);
        self::assertStringContainsString('function isValidRequesterRut(value)', $view);
        self::assertStringContainsString('function isValidRequesterEmail(value)', $view);
        self::assertStringContainsString("requesterRut.setCustomValidity(valid ? ''", $view);
        self::assertStringContainsString("requesterEmail.setCustomValidity(valid ? ''", $view);
        self::assertStringContainsString("requesterEmail.value.trim().toLowerCase()", $view);
    }

    public function test_nextcloud_creation_timeout_is_verified_before_marking_the_user_as_failed(): void
    {
        $service = app(\App\Modulos\RedmineMantencion\Services\MantencionNextcloudService::class);
        $timeout = ['ok' => false, 'statuscode' => 0, 'timeout' => true, 'message' => 'Tiempo agotado'];

        $verified = $service->nextcloud_classify_creation_response($timeout, ['exists' => true]);
        self::assertSame('created', $verified['status']);
        self::assertStringContainsString('cuenta fue verificada', $verified['message']);

        $notCreated = $service->nextcloud_classify_creation_response($timeout, ['exists' => false]);
        self::assertSame('failed', $notCreated['status']);
        self::assertStringContainsString('no aparece registrado', $notCreated['message']);

        $uncertain = $service->nextcloud_classify_creation_response($timeout, ['exists' => null, 'error' => 'Sin respuesta']);
        self::assertSame('failed', $uncertain['status']);
        self::assertStringContainsString('Revisa el usuario antes de reintentar', $uncertain['message']);

        $existing = $service->nextcloud_classify_creation_response(['ok' => false, 'statuscode' => 102]);
        self::assertSame('existing', $existing['status']);
    }

    public function test_nextcloud_ocs_requests_report_timeouts_and_allow_thirty_seconds_by_default(): void
    {
        $client = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/controllers/nextcloud.php'
        );

        self::assertIsString($client);
        self::assertStringContainsString('int $timeoutSeconds = 30', $client);
        self::assertStringContainsString("'timeout' => \$errno === CURLE_OPERATION_TIMEDOUT", $client);
    }

    public function test_webdav_directory_listing_allows_nextcloud_authentication_delay(): void
    {
        $client = file_get_contents(base_path('RedmineMantencion/ExternalClients/NextcloudWebdavClient.php'));

        self::assertIsString($client);
        self::assertStringContainsString('DIRECTORY_CONNECT_TIMEOUT_SECONDS = 8', $client);
        self::assertStringContainsString('DIRECTORY_TIMEOUT_SECONDS = 40', $client);
        self::assertStringContainsString('CURLOPT_TIMEOUT => self::DIRECTORY_TIMEOUT_SECONDS', $client);
        self::assertStringNotContainsString('CURLOPT_TIMEOUT => 5', $client);
    }

    public function test_requester_form_does_not_duplicate_or_prefill_the_logged_user(): void
    {
        $url = '/redmine-mantencion/app/integraciones-nextcloud-usuarios';

        $this->withoutMiddleware(EnsureNovaAuthenticated::class)
            ->withSession([
                'nova_user' => [
                    'id' => 'logged-user',
                    'name' => 'Nombre Autocargado',
                    'apellido' => 'No Debe Aparecer',
                    'rut' => '11111111-1',
                    'email' => 'autocargado@example.test',
                    'role' => 'root',
                    'legacy' => ['id' => 'logged-user', 'rol' => 'root'],
                ],
            ]);

        $response = $this->get($url)->assertOk();

        $response->assertSee('name="solicitante_nombre" value=""', false)
            ->assertSee('name="solicitante_rut" value=""', false)
            ->assertSee('name="solicitante_correo" type="email" value=""', false)
            ->assertDontSee('name="solicitante"', false)
            ->assertDontSee('autocargado@example.test')
            ->assertDontSee('11111111-1');
    }

    public function test_bulk_group_change_targets_checked_rows_and_each_row_has_its_own_select(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/integraciones-nextcloud-usuarios.blade.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString("document.querySelectorAll('.nextcloud-row-check:checked')", $view);
        self::assertStringContainsString("row.querySelector('.nextcloud-row-group-select')", $view);
        self::assertStringContainsString("name=\"users[<?= (int)\$idx ?>][group]\"", $view);
        self::assertStringContainsString("window.jQuery(groupSelect).trigger('change.select2')", $view);
        self::assertStringContainsString("bulkQuota.value !== keepBulkValue", $view);
        self::assertStringContainsString("const hasBulkChange = selectedBulkGroup() !== '' || hasBulkQuotaChange()", $view);
        self::assertStringContainsString("window.jQuery(bulkGroup).on('change.novaBulk', updateApplyState)", $view);
        self::assertStringContainsString("document.getElementById('nextcloud-bulk-selected')", $view);
        self::assertStringContainsString('puedes aplicar solo grupo, solo cuota o ambos', $view);
        self::assertStringContainsString('applyChanges.disabled = !hasBulkChange || !hasSelectedRows()', $view);
        self::assertStringNotContainsString('const missingGroup =', $view);
        self::assertStringContainsString('allowClear: false', $view);
        self::assertStringNotContainsString('allowClear: true', $view);
    }

    public function test_nextcloud_redirect_does_not_terminate_the_laravel_request(): void
    {
        $service = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Services/MantencionNextcloudService.php'
        );

        self::assertIsString($service);
        self::assertStringContainsString('request()->fullUrlWithQuery([\'panel\' => $panel])', $service);
        self::assertStringContainsString('return redirect()->to($target, 303);', $service);
        self::assertStringNotContainsString("header('Location:", $service);
        self::assertDoesNotMatchRegularExpression('/nextcloud_redirect_back\([^)]*\):[^\{]+\{[^}]*\bexit\s*;/s', $service);
    }

    public function test_nextcloud_group_actions_preserve_the_nextcloud_panel(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/RedmineMantencion/Services/MantencionNextcloudService.php');
        $view = file_get_contents($root.'/resources/views/redmine-mantencion/configuracion.blade.php');

        self::assertIsString($service);
        self::assertIsString($view);
        self::assertGreaterThanOrEqual(3, substr_count($service, "nextcloud_redirect_back('nextcloud')"));
        self::assertGreaterThanOrEqual(3, substr_count($view, "action=\"<?= \$h(\$configPanelUrl('nextcloud')) ?>\""));
    }
}
