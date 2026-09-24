<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

// Separate Laravel kernel; never read the application's .env or runtime caches.
[$script, $mode, $socket, $database, $temporary] = $argv;
if (! preg_match('/^nova_persistence_[a-f0-9]+_testing$/D', $database)) {
    exit(2);
}
$pdo = new PDO('mysql:unix_socket='.$socket, 'root', '');
$settings = $pdo->query('SELECT @@datadir AS datadir, @@skip_networking AS isolated')->fetch(PDO::FETCH_ASSOC);
if (! str_starts_with(realpath($settings['datadir']), realpath(sys_get_temp_dir()).'/') || (int) $settings['isolated'] !== 1) {
    exit(2);
}
$environment = [
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
    'DATABASE_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_SOCKET' => $socket,
    'DB_DATABASE' => 'nova_missing_testing', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '',
    'CACHE_STORE' => 'array', 'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array',
    'LOG_CHANNEL' => 'stderr', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'TELEGRAM_BOT_TOKEN' => '', 'LARAVEL_STORAGE_PATH' => $temporary,
    'APP_CONFIG_CACHE' => $temporary.'/config.php', 'APP_SERVICES_CACHE' => $temporary.'/services.php',
    'APP_PACKAGES_CACHE' => $temporary.'/packages.php', 'APP_ROUTES_CACHE' => $temporary.'/routes.php',
    'VIEW_COMPILED_PATH' => $temporary,
];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->useEnvironmentPath($temporary);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
// Laravel suppresses command events under APP_ENV=testing; enable the real CLI path.
$kernel->rerouteSymfonyCommandEvents();
$connection = config('database.connections.mysql');
$connection['database'] = $database;
config(['database.connections.recovery' => $connection]);
try {
    if ($mode === 'command') {
        exit($kernel->handle(new ArrayInput([
            'command' => 'migrate', '--database' => 'recovery', '--force' => true,
            '--path' => [$temporary.'/migrations'], '--realpath' => true,
        ]), new ConsoleOutput));
    }
    $app['migrator']->usingConnection('recovery', function () use ($app, $temporary): void {
        $app['migration.repository']->createRepository();
        $app['migrator']->run([$temporary.'/migrations']);
    });
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
