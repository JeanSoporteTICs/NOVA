<?php

namespace Tests\Production;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ReleaseBuildIsolationTest extends TestCase
{
    public function test_actual_builder_excludes_recovery_schema_and_verifier_rejects_injected_dump(): void
    {
        $temporary = sys_get_temp_dir().'/nova-p01-release-'.bin2hex(random_bytes(8));
        $source = $temporary.'/source';
        $release = $temporary.'/release';
        $fs = new Filesystem;
        $fs->makeDirectory($source.'/ops/production', 0700, true);
        try {
            foreach (['build-release.sh', 'verify-release.sh'] as $script) {
                $fs->copy(dirname(__DIR__, 2).'/ops/production/'.$script, $source.'/ops/production/'.$script);
                chmod($source.'/ops/production/'.$script, 0700);
            }
            $files = [
                'artisan' => '<?php', 'bootstrap/app.php' => '<?php',
                'public/index.php' => '<?php', 'public/.htaccess' => '# fixture',
                'database/migrations/example.php' => '<?php',
                'database/baselines/example/schema.sql' => 'CREATE TABLE recovery_only (id INT);',
                '.env' => 'TEST_ONLY=excluded', 'private.sql' => '-- excluded',
                'ops/production/release-allowlist.txt' => "artisan\nbootstrap\npublic\ndatabase\n",
            ];
            foreach ($files as $path => $contents) {
                $fs->ensureDirectoryExists(dirname($source.'/'.$path));
                $fs->put($source.'/'.$path, $contents);
            }
            $fs->ensureDirectoryExists($temporary.'/dependencies/vendor');
            $fs->put($temporary.'/dependencies/vendor/autoload.php', '<?php');
            foreach ([['git', 'init', '-q'], ['git', 'add', '.'],
                ['git', '-c', 'user.name=P01 Test', '-c', 'user.email=p01@example.invalid', 'commit', '-qm', 'Isolated fixture']] as $command) {
                (new Process($command, $source))->mustRun();
            }
            $build = new Process(['bash', $source.'/ops/production/build-release.sh', '--ref', 'HEAD', '--output', $release, '--dependency-source', $temporary.'/dependencies'], $source);
            $build->mustRun();
            self::assertFileExists($release.'/database/migrations/example.php');
            self::assertDirectoryDoesNotExist($release.'/database/baselines');
            self::assertFileDoesNotExist($release.'/.env');
            self::assertFileDoesNotExist($release.'/private.sql');
            $fs->put($release.'/database/accidental.sql', '-- synthetic leak');
            $verify = new Process(['bash', $source.'/ops/production/verify-release.sh', $release], $source);
            self::assertSame(1, $verify->run());
            self::assertStringContainsString('database dump or backup artifact', $verify->getErrorOutput());
        } finally {
            $fs->deleteDirectory($temporary);
        }
    }
}
