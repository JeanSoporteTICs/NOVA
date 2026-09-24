<?php

use App\Modulos\Nova\Repositories\HorasExtraRepository;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

// Independent process used only against the disposable, socket-only test server.
require dirname(__DIR__, 3).'/vendor/autoload.php';
[$script, $socket, $database, $action, $groupId] = $argv;
if (! preg_match('/^nova_persistence_[a-f0-9]+_testing$/', $database)) {
    exit(2);
}
$pdo = new PDO('mysql:unix_socket='.$socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$settings = $pdo->query('SELECT @@datadir AS dir, @@skip_networking AS isolated')->fetch(PDO::FETCH_ASSOC);
if ((int) $settings['isolated'] !== 1 || ! str_starts_with(realpath($settings['dir']), '/tmp/')) {
    exit(2);
}
$app = new Application(dirname(__DIR__, 3));
$app->instance('config', new Repository(['database' => [
    'default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql', 'unix_socket' => $socket, 'host' => 'localhost', 'database' => $database, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true]],
]]));
Facade::setFacadeApplication($app);
$app->register(DatabaseServiceProvider::class);
DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
echo DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n";
flush();
try {
    $shared = new HorasExtraRepository;
    if ($action === 'create') {
        $id = $shared->atomic(function () use ($shared): int {
            $id = $shared->findOrCreateGroup(1, '2026-09-12', '19:00:00', '20:00:00');
            if (! $id) {
                throw new RuntimeException('No group');
            }
            $shared->attachReporte($id, 'tic', 2);

            return $id;
        });
        echo 'CREATED:'.$id;
    } elseif ($action === 'attach') {
        $shared->attachReporte((int) $groupId, 'tic', 2);
        echo 'ATTACHED';
    } else {
        exit(2);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    echo 'FAILED';
}
