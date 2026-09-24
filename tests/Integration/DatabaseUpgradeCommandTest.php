<?php

namespace Tests\Integration;

use App\Services\Database\UpgradeSafety;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

final class DatabaseUpgradeCommandTest extends IsolatedMariaDbTestCase
{
    public function test_artisan_blocks_before_any_migration_on_the_explicit_connection(): void
    {
        $this->runBlockedUpgrade('command');
        self::assertFalse(Schema::hasTable('earlier_migration'));
        self::assertFalse(Schema::hasTable('migrations'));
    }

    public function test_direct_migrator_also_blocks_the_cleanup_event(): void
    {
        $this->runBlockedUpgrade('migrator');
        self::assertTrue(Schema::hasTable('earlier_migration'));
        self::assertSame(0, DB::table('migrations')->where('migration', UpgradeSafety::CLEANUP)->count());
    }

    private function runBlockedUpgrade(string $mode): void
    {
        Schema::create('redmine_tic_reportes', fn (Blueprint $t) => $t->id());
        DB::table('redmine_tic_reportes')->insert(['id' => 47]);
        $temporary = sys_get_temp_dir().'/nova-p07-kernel-'.bin2hex(random_bytes(8));
        mkdir($temporary.'/migrations', 0700, true);
        file_put_contents($temporary.'/migrations/2000_01_01_000000_earlier.php', '<?php return new class extends Illuminate\Database\Migrations\Migration { public function up(): void { Illuminate\Support\Facades\Schema::create("earlier_migration", fn ($t) => $t->id()); } };');
        copy(dirname(__DIR__, 2).'/database/migrations/'.UpgradeSafety::CLEANUP.'.php', $temporary.'/migrations/'.UpgradeSafety::CLEANUP.'.php');
        try {
            $process = new Process([PHP_BINARY, __DIR__.'/fixtures/database_upgrade.php', $mode, getenv('NOVA_PERSISTENCE_TEST_SOCKET'), DB::connection()->getDatabaseName(), $temporary]);
            $process->run();
            self::assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            self::assertStringContainsString('limpieza histórica pendiente', $process->getOutput().$process->getErrorOutput());
            self::assertSame([47], DB::table('redmine_tic_reportes')->pluck('id')->all());
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($temporary);
        }
    }
}
