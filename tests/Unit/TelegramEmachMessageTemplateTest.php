<?php

namespace Tests\Unit;

use App\Modulos\Telegram\Repositories\TelegramCommandSettingsRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TelegramEmachMessageTemplateTest extends TestCase
{
    #[DataProvider('markTypes')]
    public function test_it_selects_the_emach_template_for_the_mark_type(string $type, string $expectedKey): void
    {
        $repository = new TelegramCommandSettingsRepository;

        $this->assertSame($expectedKey, $repository->emachMessageKey($type));
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function markTypes(): array
    {
        return [
            'entrada' => ['ENTRADA', 'emach_success_entrada'],
            'entrada normalizada' => ['  Entrada manual  ', 'emach_success_entrada'],
            'salida' => ['SALIDA', 'emach_success_salida'],
            'salida normalizada' => ['Salida reloj', 'emach_success_salida'],
            'tipo desconocido' => ['COLACION', 'emach_success'],
            'tipo vacio' => ['', 'emach_success'],
        ];
    }

    public function test_it_exposes_independent_editable_templates_with_type_placeholders(): void
    {
        $messages = (new TelegramCommandSettingsRepository)->defaults()['messages'];

        $this->assertStringContainsString('🟢', $messages['emach_success_entrada']);
        $this->assertStringContainsString('🔴', $messages['emach_success_salida']);
        $this->assertStringContainsString('{tipo}', $messages['emach_success_entrada']);
        $this->assertStringContainsString('{tipo}', $messages['emach_success_salida']);
        $this->assertArrayHasKey('emach_success', $messages);
    }

    public function test_it_renders_the_configured_template_for_each_mark_type(): void
    {
        $repository = new class extends TelegramCommandSettingsRepository
        {
            public function all(): array
            {
                return ['messages' => [
                    'emach_success_entrada' => '🟢 {tipo} a las {hora}',
                    'emach_success_salida' => '🔴 {tipo} a las {hora}',
                    'emach_success' => '⚪ {tipo} a las {hora}',
                ]];
            }
        };

        $this->assertSame('🟢 ENTRADA a las 08:00', $repository->renderEmachMark(['tipo' => 'ENTRADA', 'hora' => '08:00']));
        $this->assertSame('🔴 SALIDA a las 17:00', $repository->renderEmachMark(['tipo' => 'SALIDA', 'hora' => '17:00']));
        $this->assertSame('⚪ COLACION a las 13:00', $repository->renderEmachMark(['tipo' => 'COLACION', 'hora' => '13:00']));
    }

    public function test_the_manager_groups_all_emach_variants_under_one_message(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/Nova/views/nova/admin/index.blade.php');

        $this->assertIsString($view);
        $this->assertStringContainsString("'emach_success_entrada' => 'Marcación EMACH'", $view);
        $this->assertStringNotContainsString('Marcación EMACH · Entrada', $view);
        $this->assertStringContainsString('data-telegram-emach-variant-option', $view);
        $this->assertStringContainsString('data-telegram-emach-variant', $view);
    }
}
