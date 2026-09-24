<?php

namespace Tests\Integration;

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Hashing\HashServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\TestCase;

/** No Laravel kernel, .env, production connection or external service is loaded. */
abstract class IsolatedMariaDbTestCase extends TestCase
{
    private ?PDO $admin = null;

    private ?string $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = getenv('NOVA_PERSISTENCE_TEST_SOCKET');
        if (! $socket) {
            self::markTestSkipped('Requires NOVA_PERSISTENCE_TEST_SOCKET on a disposable MariaDB instance.');
        }

        $this->admin = new PDO('mysql:unix_socket='.$socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $settings = $this->admin->query('SELECT @@datadir AS datadir, @@skip_networking AS isolated')->fetch(PDO::FETCH_ASSOC);
        $temporaryRoot = rtrim(realpath(sys_get_temp_dir()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        self::assertStringStartsWith($temporaryRoot, realpath($settings['datadir']));
        self::assertSame(1, (int) $settings['isolated'], 'The disposable server must have networking disabled.');

        $this->database = 'nova_persistence_'.bin2hex(random_bytes(6)).'_testing';
        $this->admin->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $connection = [
            'driver' => 'mysql', 'unix_socket' => $socket, 'host' => 'localhost',
            'database' => $this->database, 'username' => 'root', 'password' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ];
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository([
            'database' => ['default' => 'mysql', 'connections' => ['mysql' => $connection, 'concurrent' => $connection]],
            'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->register(DatabaseServiceProvider::class);
        $app->register(HashServiceProvider::class);
    }

    protected function tearDown(): void
    {
        if ($this->database !== null) {
            DB::purge('concurrent');
            DB::purge('mysql');
            $this->admin->exec("DROP DATABASE `{$this->database}`");
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Application::setInstance(null);
        parent::tearDown();
    }
}
