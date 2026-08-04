<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NativeBrowserDialogPolicyTest extends TestCase
{
    public function test_application_flows_do_not_call_native_browser_dialogs(): void
    {
        $paths = [
            dirname(__DIR__, 2).'/RedmineTic/views/native-sections/history.blade.php',
            dirname(__DIR__, 2).'/RedmineTic/views/native-sections/config.blade.php',
            dirname(__DIR__, 2).'/Nova/views/nova/auth/login.blade.php',
            dirname(__DIR__, 2).'/resources/views/procedimientos/browser.blade.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($path);

            self::assertIsString($source, $path);
            self::assertDoesNotMatchRegularExpression('/(?:window\\s*\\.\\s*)?(?:confirm|alert)\\s*\\(/', $source, $path);
        }
    }
}
