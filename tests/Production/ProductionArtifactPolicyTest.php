<?php

namespace Tests\Production;

use PHPUnit\Framework\TestCase;

final class ProductionArtifactPolicyTest extends TestCase
{
    public function test_allowlist_never_contains_sensitive_or_runtime_paths(): void
    {
        $root = dirname(__DIR__, 2);
        $lines = file($root . '/ops/production/release-allowlist.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);

        $paths = array_values(array_filter($lines, static fn (string $line): bool => !str_starts_with(trim($line), '#')));
        $forbidden = ['.env', '.git', 'tests', 'node_modules', '.tools', '.claude', 'storage/logs/laravel.log'];

        foreach ($forbidden as $path) {
            self::assertNotContains($path, $paths);
        }

        foreach ($paths as $path) {
            if (basename($path) === '.gitignore') {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/(?:^|\/)(?:backups?|logs?|sessions?|cache\/data)(?:\/|$)/i', $path);
            self::assertDoesNotMatchRegularExpression('/\.(?:sql|bak|log|key|pem|tmp)$/i', $path);
        }
    }

    public function test_web_server_examples_publish_only_public(): void
    {
        $root = dirname(__DIR__, 2);
        $apache = file_get_contents($root . '/ops/production/apache-vhost.example.conf');
        $nginx = file_get_contents($root . '/ops/production/nginx-site.example.conf');

        self::assertStringContainsString('DocumentRoot "/srv/nova/current/public"', (string) $apache);
        self::assertStringContainsString('Require all denied', (string) $apache);
        self::assertStringContainsString('root /srv/nova/current/public;', (string) $nginx);
    }
}
