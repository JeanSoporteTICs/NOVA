<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TelegramChatIdHelpTest extends TestCase
{
    public function test_chat_id_help_identifies_the_bot_and_exposes_a_scannable_qr(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/Nova/views/nova/telegram/index.blade.php');
        $qrPath = $root.'/public/assets/img/telegram-nvkerrigan-bot-qr.svg';

        self::assertIsString($view);
        self::assertStringContainsString('@NVKerrigan_Bot', $view);
        self::assertStringContainsString('https://t.me/NVKerrigan_Bot', $view);
        self::assertStringContainsString("asset('assets/img/telegram-nvkerrigan-bot-qr.svg')", $view);
        self::assertStringContainsString('@getidsbot', $view);
        self::assertStringNotContainsString('Envía <code>/id</code>', $view);
        self::assertFileExists($qrPath);
        self::assertGreaterThan(1000, filesize($qrPath));
    }
}
