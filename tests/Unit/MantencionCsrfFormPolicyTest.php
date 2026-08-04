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
        $root = dirname(__DIR__, 2).'/RedmineMantencion';
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
                    '/(?:\bname\s*=\s*(["\'])(?:csrf_token|_token)\1|@csrf\b)/i',
                    $form,
                    'Formulario POST sin token CSRF en '.$file->getPathname()
                );
            }
        }

        self::assertGreaterThan(0, $formsChecked);
    }
}
