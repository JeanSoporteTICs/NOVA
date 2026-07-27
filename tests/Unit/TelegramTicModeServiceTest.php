<?php

namespace Tests\Unit;

use App\Modulos\Telegram\Repositories\TelegramCommandCatalog;
use App\Modulos\Telegram\Repositories\TelegramCommandSettingsRepository;
use App\Modulos\Telegram\Services\TelegramTicModeService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

class TelegramTicModeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        app('cache')->store('array')->flush();
    }

    public function test_mode_is_active_only_for_the_activation_day(): void
    {
        $service = app(TelegramTicModeService::class);
        $timezone = new DateTimeZone('America/Santiago');
        $today = new DateTimeImmutable('2099-07-25 09:15:00', $timezone);
        $tomorrow = new DateTimeImmutable('2099-07-26 00:01:00', $timezone);

        $state = $service->activate('chat-123', $today);

        $this->assertTrue($state['active']);
        $this->assertSame('2099-07-25', $state['date']);
        $this->assertSame('25/07/2099 23:59', $service->formattedUntil($state));
        $this->assertTrue($service->isActive('chat-123', $today));
        $this->assertFalse($service->isActive('chat-123', $tomorrow));
    }

    public function test_mode_can_be_deactivated_manually(): void
    {
        $service = app(TelegramTicModeService::class);
        $now = new DateTimeImmutable('2099-07-25 10:00:00', new DateTimeZone('America/Santiago'));

        $service->activate('chat-456', $now);
        $service->deactivate('chat-456');

        $this->assertFalse($service->isActive('chat-456', $now));
    }

    public function test_plain_report_requires_exactly_three_non_empty_fields(): void
    {
        $service = app(TelegramTicModeService::class);

        $valid = $service->validateReportText('Impresora sin red, SOME HBV, Juan Pérez');
        $this->assertTrue($valid['valid']);
        $this->assertSame('Impresora sin red, SOME HBV, Juan Pérez', $valid['text']);

        $this->assertFalse($service->validateReportText('Impresora sin red, SOME HBV')['valid']);
        $this->assertFalse($service->validateReportText('Impresora sin red, , Juan Pérez')['valid']);
        $this->assertFalse($service->validateReportText('Uno, Dos, Tres, Cuatro')['valid']);
    }

    public function test_tic_catalog_documents_daily_mode_commands(): void
    {
        $settings = app(TelegramCommandSettingsRepository::class);
        $catalog = new TelegramCommandCatalog($settings);
        $tic = collect($catalog->commands())->firstWhere('key', 'tic');

        $this->assertIsArray($tic);
        $this->assertStringContainsString('/tic activar', $tic['input']);
        $this->assertNotSame('', $settings->message('tic_mode_activated'));
        $this->assertNotSame('', $settings->message('tic_mode_invalid_format'));
    }
}
