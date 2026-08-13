<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SessionModalLockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sessionControls(): array
    {
        return [
            'nova and redmine tic' => ['Nova/views/nova/partials/session-control.blade.php', 'nova-session-modal'],
            'redmine mantencion' => ['RedmineMantencion/views/partials/navbar.php', 'sessionModal'],
            'emach' => ['Emach/views/partials/navbar.php', 'sessionModal'],
            'telegram' => ['telegram/views/partials/session-control.php', 'sessionModal'],
        ];
    }

    #[DataProvider('sessionControls')]
    public function test_session_modal_cannot_be_dismissed_without_a_decision(string $relativePath, string $modalId): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

        $this->assertIsString($source);
        $this->assertStringContainsString('id="'.$modalId.'"', $source);
        $this->assertStringContainsString('data-bs-backdrop="static"', $source);
        $this->assertStringContainsString('data-bs-keyboard="false"', $source);
        $this->assertStringContainsString("backdrop: 'static'", $source);
        $this->assertStringContainsString('keyboard: false', $source);
        $this->assertStringContainsString("addEventListener('hide.bs.modal'", $source);

        $modalMarkup = substr($source, strrpos($source, '<div class="modal fade" id="'.$modalId.'"'));
        $this->assertStringNotContainsString('btn-close', $modalMarkup);
    }

    #[DataProvider('sessionControls')]
    public function test_cancel_logs_out_with_post_and_returns_to_login(string $relativePath, string $modalId): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

        $this->assertIsString($source);
        $modalMarkup = substr($source, strrpos($source, '<div class="modal fade" id="'.$modalId.'"'));
        $this->assertStringContainsString('>Cancelar</button>', $modalMarkup);
        $this->assertStringContainsString("method = 'POST'", $source);
        $this->assertStringContainsString("name = '_token'", $source);
    }
}
