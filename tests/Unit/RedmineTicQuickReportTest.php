<?php

namespace Tests\Unit;

use RedmineTic\Services\QuickReportService;
use Tests\TestCase;

final class RedmineTicQuickReportTest extends TestCase
{
    public function test_quick_input_requires_the_telegram_three_part_format(): void
    {
        $service = app(QuickReportService::class);

        $invalid = $service->createDraft('Impresora sin red, SOME HBV', [], [], '44');
        $valid = $service->createDraft(
            'Impresora no imprime, SOME HBV, Ana Pérez',
            [['nombre' => 'Impresoras'], ['nombre' => 'Equipos']],
            [['nombre' => 'SOME HBV'], ['nombre' => 'Hospital']],
            '44'
        );

        $this->assertFalse($invalid['ok']);
        $this->assertSame('Escribe problema, unidad y solicitante separados por comas.', $invalid['error']);
        $this->assertTrue($valid['ok']);
        $this->assertSame('Impresora no imprime / SOME HBV', $valid['draft']['asunto']);
        $this->assertSame('Ana Pérez', $valid['draft']['solicitante']);
        $this->assertSame('SOME HBV', $valid['draft']['unidad_solicitante']);
        $this->assertSame('Impresoras', $valid['draft']['categoria']);
        $this->assertSame('44', $valid['draft']['asignado_a']);
        $this->assertSame('', $valid['draft']['descripcion']);
        $this->assertSame('manual_rapido', $valid['draft']['origen']);
    }

    public function test_unknown_unit_selects_existing_hbv_without_adding_text(): void
    {
        $draft = app(QuickReportService::class)->createDraft(
            'Problema de acceso, unidad informática, Ana Pérez',
            [['nombre' => 'Equipos']],
            [['nombre' => 'HBV'], ['nombre' => 'Abastecimiento']],
            '44'
        );

        $this->assertTrue($draft['ok']);
        $this->assertSame('unidad informática', $draft['draft']['unidad']);
        $this->assertSame('HBV', $draft['draft']['unidad_solicitante']);
    }

    public function test_unknown_unit_stays_empty_when_hbv_is_not_in_the_catalog(): void
    {
        $draft = app(QuickReportService::class)->createDraft(
            'Problema de acceso, unidad informática, Ana Pérez',
            [],
            [['nombre' => 'Abastecimiento']],
            '44'
        );

        $this->assertTrue($draft['ok']);
        $this->assertSame('', $draft['draft']['unidad_solicitante']);
    }

    public function test_request_unit_uses_catalog_match_while_unit_keeps_free_text(): void
    {
        $draft = app(QuickReportService::class)->createDraft(
            'Instalar computadores, de Farmacia a ex Pediatría, Erick',
            [['nombre' => 'Equipos']],
            [['nombre' => 'HBV'], ['nombre' => 'PEDIATRÍA']],
            '44'
        );

        $this->assertTrue($draft['ok']);
        $this->assertSame('de Farmacia a ex Pediatría', $draft['draft']['unidad']);
        $this->assertSame('PEDIATRÍA', $draft['draft']['unidad_solicitante']);
    }

    public function test_assigned_recipient_uses_the_central_telegram_chat_id(): void
    {
        $recipient = app(QuickReportService::class)->assignedRecipient([
            [
                'id' => 'local-id',
                'redmine_id' => '44',
                'nombre' => 'Diego',
                'apellido' => 'Soto',
                'estado_usuario' => 'activo',
                'estado_nova' => 'activo',
                'telegram_chat_id' => '998877',
            ],
        ], '44');

        $this->assertSame([
            'id' => '44',
            'name' => 'Diego Soto',
            'chat_id' => '998877',
        ], $recipient);
    }

    public function test_inactive_or_non_redmine_users_cannot_be_assigned(): void
    {
        $service = app(QuickReportService::class);

        $this->assertNull($service->assignedRecipient([[
            'id' => '44',
            'redmine_id' => '44',
            'estado_usuario' => 'activo',
            'estado_nova' => 'inactivo',
        ]], '44'));
        $this->assertNull($service->assignedRecipient([[
            'id' => '55',
            'redmine_id' => '',
            'estado_usuario' => 'activo',
            'estado_nova' => 'activo',
        ]], '55'));
    }

    public function test_notification_contains_report_data_id_and_clickable_url(): void
    {
        $message = app(QuickReportService::class)->notificationMessage([
            'asunto' => 'Impresora no imprime / SOME HBV',
            'solicitante' => 'Ana Pérez',
            'unidad_solicitante' => 'SOME HBV',
            'categoria' => 'Impresoras',
            'prioridad' => 'ALTA',
        ], '127765', 'https://redmine.example.test/issues/127765');

        $this->assertStringContainsString('Redmine #127765', $message);
        $this->assertStringContainsString('Problema: Impresora no imprime / SOME HBV', $message);
        $this->assertStringContainsString('Solicitante: Ana Pérez', $message);
        $this->assertStringContainsString('https://redmine.example.test/issues/127765', $message);
    }

    public function test_new_view_keeps_the_existing_webhook_separate(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/RedmineTic/views/native-sections/quick-report.blade.php');
        $layout = file_get_contents($root.'/RedmineTic/views/native.blade.php');
        $routes = file_get_contents($root.'/routes/web.php');
        $controller = file_get_contents($root.'/RedmineTic/Controllers/RedmineDashboardController.php');

        $this->assertIsString($view);
        foreach ([
            'quick_input', 'asunto', 'descripcion', 'solicitante', 'unidad',
            'unidad_solicitante', 'categoria', 'prioridad', 'tipo', 'asignado_a',
            'fecha_inicio', 'fecha_fin', 'fecha', 'hora', 'mensaje', 'hora_extra',
            'tiempo_estimado', 'chat_id_telegram',
        ] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $view, $field);
        }
        $this->assertStringContainsString('Generar vista previa', $view);
        $this->assertStringNotContainsString('1 · Ingreso', $view);
        $this->assertStringNotContainsString('Describe el caso en una línea', $view);
        $this->assertStringContainsString('name="quick_description"', $view);
        $this->assertStringContainsString('data-quick-notes', $view);
        $this->assertStringContainsString("session('redmine_tic.quick_report_notes', '')", $view);
        $this->assertStringContainsString('id="tic-quick-preview-drawer"', $view);
        $this->assertStringContainsString('data-nova-drawer-open="tic-quick-preview-drawer"', $view);
        $this->assertStringContainsString('data-quick-drawer-minimize', $view);
        $this->assertStringContainsString('data-quick-drawer-maximize', $view);
        $this->assertStringContainsString('class="tic-quick-drawer-form"', $view);
        $this->assertMatchesRegularExpression('/id="tic-quick-preview-drawer".*2 · Revisión.*Modifica lo necesario/s', $view);
        $this->assertStringNotContainsString('class="tic-quick-workspace"', $view);
        $this->assertStringContainsString('Enviar directamente a Redmine', $view);
        $this->assertStringContainsString('data-telegram-state', $view);
        $this->assertStringContainsString("\$projectState === 'activo' && \$novaState === 'activo' && ctype_digit(\$redmineId)", $view);
        $this->assertSame(4, substr_count($view, 'data-tic-webhook-select2'));
        $this->assertMatchesRegularExpression('/name="unidad_solicitante"\s+required/', $view);
        $this->assertStringContainsString("@include('redmine_tic::native-sections.quick-report')", $layout);
        $this->assertStringContainsString('/redmine_tic/app/reporte-rapido', $routes);
        $this->assertStringContainsString('/redmine_tic/app/reporte-rapido/notas', $routes);
        $this->assertStringContainsString("'reporte-rapido' => 'reporte_rapido'", $controller);
        $this->assertStringContainsString('function quickReportNotes(', $controller);
        $this->assertStringContainsString('Rule::in($activeUnits)', $controller);
        $this->assertStringContainsString('La unidad solicitante seleccionada ya no existe en la lista vigente.', $controller);
        $this->assertStringContainsString('data-app-confirm-preview="#tic-quick-confirm-summary"', $view);
        $this->assertMatchesRegularExpression('/id="tic-quick-confirm-summary"[^>]+hidden/', $view);
        $this->assertStringContainsString('data-confirm-preview', $layout);
        $this->assertStringContainsString('renderConfirmPreview', $layout);
    }

    public function test_call_notes_are_kept_only_for_the_active_nova_session(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/RedmineTic/Controllers/RedmineDashboardController.php');
        $script = file_get_contents($root.'/public/assets/redmine-tic-quick-report.js');
        $middleware = file_get_contents($root.'/app/Http/Middleware/EnsureNovaAuthenticated.php');

        $this->assertIsString($controller);
        $this->assertIsString($script);
        $this->assertIsString($middleware);
        $this->assertStringContainsString("session()->put('redmine_tic.quick_report_notes'", $controller);
        $this->assertStringNotContainsString("(string) (\$validated['quick_description'] ?? '')\n            );", $controller);
        $this->assertStringContainsString("session()->forget('redmine_tic.quick_report_notes'", $controller);
        $this->assertStringContainsString('dataset.notesSaveUrl', $script);
        $this->assertStringContainsString('navigator.sendBeacon', $script);
        $this->assertStringContainsString("style.setProperty(\n      '--tic-quick-notes-height'", $script);
        $this->assertStringContainsString("classList.toggle('is-minimized'", $script);
        $this->assertStringContainsString("classList.toggle('is-maximized'", $script);
        $this->assertStringContainsString('window.NovaDrawer?.open(previewDrawer)', $script);
        $this->assertStringNotContainsString('localStorage', $script);
        $this->assertStringNotContainsString('sessionStorage', $script);
        $this->assertStringContainsString("'redmine_tic.quick_report_notes'", $middleware);
    }
}
