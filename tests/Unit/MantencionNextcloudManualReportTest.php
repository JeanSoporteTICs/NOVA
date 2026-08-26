<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionNextcloudService;
use App\Modulos\RedmineMantencion\Services\MantencionPendientesService;
use Tests\TestCase;

final class MantencionNextcloudManualReportTest extends TestCase
{
    public function test_history_batch_builds_the_expected_manual_report_draft(): void
    {
        $service = app(MantencionNextcloudService::class);
        $draft = $service->nextcloud_manual_report_draft_from_batch([
            'numero_lote' => 4,
            'created_at' => '2026-08-25T15:15:13-04:00',
            'solicitante_nombre' => 'Persona Solicitante',
            'result_users' => [[
                'userid' => '12345678',
                'displayName' => 'Persona Nextcloud',
                'email' => 'persona@example.test',
                'group' => 'Grupo Uno',
                'status' => 'created',
                'message' => 'Creado correctamente.',
            ]],
        ]);

        self::assertSame('Creación de usuario Nextcloud', $draft['asunto']);
        self::assertSame('Persona Solicitante', $draft['solicitante']);
        self::assertSame('Nextcloud', $draft['categoria']);
        self::assertStringContainsString('Lote: 4', $draft['descripcion']);
        self::assertStringContainsString('Usuario: 12345678', $draft['descripcion']);
        self::assertStringContainsString('Nombre: Persona Nextcloud', $draft['descripcion']);
        self::assertStringContainsString('Estado: Creado', $draft['descripcion']);
    }

    public function test_nextcloud_category_is_selected_only_when_it_exists_in_the_catalog(): void
    {
        $service = app(MantencionPendientesService::class);
        $form = ['asunto' => '', 'descripcion' => '', 'solicitante' => '', 'categoria' => ''];
        $prefill = [
            'source' => 'nextcloud_history',
            'asunto' => 'Creación de usuario Nextcloud',
            'descripcion' => 'Detalle del lote',
            'solicitante' => 'Persona Solicitante',
            'categoria' => 'Nextcloud',
        ];

        $withCategory = $service->applyNextcloudHistoryPrefill($form, [
            ['nombre' => 'Equipos'],
            ['nombre' => 'NEXTCLOUD'],
        ], $prefill);
        self::assertSame('NEXTCLOUD', $withCategory['categoria']);

        $withoutCategory = $service->applyNextcloudHistoryPrefill($form, [
            ['nombre' => 'Equipos'],
        ], $prefill);
        self::assertSame('', $withoutCategory['categoria']);
    }

    public function test_manual_category_view_never_inserts_a_non_catalog_option(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/pendientes-manual.blade.php'
        );

        self::assertIsString($view);
        self::assertStringNotContainsString('!in_array($currentManualCategory, $categoryOptions, true)', $view);
        self::assertStringNotContainsString('<option value="<?= $h($currentManualCategory) ?>"', $view);
    }

    public function test_back_navigation_clears_stale_submit_button_loading_state(): void
    {
        $script = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/assets/js/app-modal.js'
        );

        self::assertIsString($script);
        self::assertStringContainsString("window.addEventListener('pageshow', resetSubmittingButtons)", $script);
        self::assertStringContainsString("button.classList.remove('is-submitting')", $script);
        self::assertStringContainsString("button.removeAttribute('aria-busy')", $script);
    }
}
