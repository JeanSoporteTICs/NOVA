<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

final class RedmineTicCsrfFormPolicyTest extends TestCase
{
    public function test_every_tic_post_form_contains_a_csrf_field(): void
    {
        $root = dirname(__DIR__, 2).'/RedmineTic/views';
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
            '/\.(?:php|blade\.php)$/i'
        );
        $formsChecked = 0;

        foreach ($files as $file) {
            $content = file_get_contents($file->getPathname());
            self::assertIsString($content);
            preg_match_all(
                '/<form\b(?=[^>]*\bmethod\s*=\s*(["\']?)post\1)[^>]*>.*?<\/form>/is',
                $content,
                $matches
            );

            foreach ($matches[0] as $form) {
                $formsChecked++;
                self::assertMatchesRegularExpression(
                    '/@csrf|\bname\s*=\s*(["\'])(?:csrf_token|_token)\1/i',
                    $form,
                    'Formulario POST sin token CSRF en '.$file->getPathname()
                );
            }
        }

        self::assertGreaterThan(0, $formsChecked);
    }

    public function test_shared_session_control_applies_the_refreshed_token(): void
    {
        $root = dirname(__DIR__, 2);
        $sessionControl = file_get_contents($root.'/Nova/views/nova/partials/session-control.blade.php');

        self::assertIsString($sessionControl);
        self::assertStringContainsString('let csrf =', $sessionControl);
        self::assertStringContainsString('data?.csrf_token', $sessionControl);
        self::assertStringContainsString('window.NovaCsrfForms?.setToken?.(refreshedToken)', $sessionControl);
        self::assertStringContainsString('applyRefreshedCsrfToken(data)', $sessionControl);
    }

    public function test_tic_uses_laravel_csrf_middleware_instead_of_the_legacy_exemption(): void
    {
        $root = dirname(__DIR__, 2);
        $middleware = file_get_contents($root.'/app/Http/Middleware/VerifyCsrfToken.php');
        $modules = file_get_contents($root.'/config/modules.php');

        self::assertIsString($middleware);
        self::assertIsString($modules);
        self::assertStringContainsString("'legacy_csrf_validation'", $middleware);
        self::assertStringNotContainsString("allowed_php_roots'] ?? []", $middleware);

        $ticConfig = strstr($modules, "'redmine_tic' => [");
        self::assertIsString($ticConfig);
        $ticConfig = strstr($ticConfig, "'redmine-mantencion' => [", true);
        self::assertIsString($ticConfig);
        self::assertStringNotContainsString("'legacy_csrf_validation' => true", $ticConfig);
    }

    public function test_tic_ajax_actions_read_the_current_token_at_request_time(): void
    {
        $hours = file_get_contents(
            dirname(__DIR__, 2).'/RedmineTic/views/native-sections/hours.blade.php'
        );

        self::assertIsString($hours);
        self::assertStringContainsString('window.NovaCsrfForms?.token?.()', $hours);
        self::assertStringContainsString("'X-CSRF-TOKEN': currentCsrfToken()", $hours);
        self::assertStringNotContainsString('const csrfToken =', $hours);
    }
}
