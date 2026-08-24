<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

final class NovaCsrfFormPolicyTest extends TestCase
{
    public function test_every_nova_post_form_contains_a_csrf_field(): void
    {
        $root = dirname(__DIR__, 2);
        $directories = [
            'Nova/views/nova',
            'resources/views/monitor-servidores',
            'resources/views/procedimientos',
        ];
        $formsChecked = 0;

        foreach ($directories as $directory) {
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
                        '/@csrf|\bname\s*=\s*(["\'])(?:csrf_token|_token)\1/i',
                        $form,
                        'Formulario POST sin token CSRF en '.$file->getPathname()
                    );
                }
            }
        }

        self::assertGreaterThan(0, $formsChecked);
    }

    public function test_nova_shells_use_the_shared_session_control(): void
    {
        $root = dirname(__DIR__, 2);
        $views = [
            'Nova/views/nova/home.blade.php',
            'Nova/views/nova/admin/index.blade.php',
            'Nova/views/nova/modules/index.blade.php',
            'Nova/views/nova/horas-extra/index.blade.php',
            'Nova/views/nova/integrations/user-config.blade.php',
            'Nova/views/nova/telegram/navigation.blade.php',
            'resources/views/monitor-servidores/index.blade.php',
            'resources/views/procedimientos/index.blade.php',
        ];

        foreach ($views as $view) {
            $content = file_get_contents($root.'/'.$view);
            self::assertIsString($content);
            self::assertStringContainsString(
                "@include('nova.partials.session-control')",
                $content,
                'Vista NOVA sin control de sesión compartido: '.$view
            );
        }
    }

    public function test_nova_ajax_actions_read_the_current_token_at_request_time(): void
    {
        $hours = file_get_contents(
            dirname(__DIR__, 2).'/Nova/views/nova/horas-extra/index.blade.php'
        );

        self::assertIsString($hours);
        self::assertStringContainsString('window.NovaCsrfForms?.token?.()', $hours);
        self::assertStringContainsString("'X-CSRF-TOKEN': currentCsrfToken()", $hours);
        self::assertStringNotContainsString('const csrfToken =', $hours);
    }
}
