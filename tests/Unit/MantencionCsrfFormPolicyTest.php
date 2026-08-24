<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

final class MantencionCsrfFormPolicyTest extends TestCase
{
    public function test_every_explicit_post_form_contains_a_csrf_field(): void
    {
        $root = dirname(__DIR__, 2);
        $formsChecked = 0;

        foreach (['RedmineMantencion', 'resources/views/redmine-mantencion'] as $directory) {
            $files = new RegexIterator(
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)),
                '/\.(?:php|blade\.php)$/i'
            );

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
                        '/\bname\s*=\s*(["\'])(?:csrf_token|_token)\1/i',
                        $form,
                        'Formulario POST sin token CSRF en '.$file->getPathname()
                    );
                }
            }
        }

        self::assertGreaterThan(0, $formsChecked);
    }

    public function test_session_reactivation_resynchronizes_every_mantencion_token(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/Nova/Controllers/NovaAuthController.php');
        $navbar = file_get_contents($root.'/RedmineMantencion/views/partials/navbar.php');
        $ui = file_get_contents($root.'/public/assets/nova-ui.js');

        self::assertIsString($controller);
        self::assertIsString($navbar);
        self::assertIsString($ui);
        self::assertStringContainsString("'csrf_token' => \$request->session()->token()", $controller);
        self::assertStringContainsString('window.NovaCsrfForms?.setToken?.(refreshedCsrfToken)', $navbar);
        self::assertStringContainsString('input[name="_token"], input[name="csrf_token"]', $ui);
        self::assertStringContainsString("root.querySelectorAll?.('[data-csrf]')", $ui);
        self::assertStringContainsString('legacyInput.value = currentToken', $ui);
    }

    public function test_mantencion_ajax_actions_read_the_current_token_at_request_time(): void
    {
        $root = dirname(__DIR__, 2);
        $views = [
            $root.'/resources/views/redmine-mantencion/horas-extra.blade.php',
            $root.'/resources/views/redmine-mantencion/integraciones-nextcloud-gestion-usuarios.blade.php',
            $root.'/RedmineMantencion/views/Procedimientos/_nc_browser.php',
        ];

        foreach ($views as $view) {
            $content = file_get_contents($view);
            self::assertIsString($content);
            self::assertStringContainsString(
                'window.NovaCsrfForms?.token?.()',
                $content,
                'La acción AJAX conserva un token capturado al cargar la vista: '.$view
            );
        }
    }
}
