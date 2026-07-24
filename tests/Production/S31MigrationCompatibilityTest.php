<?php

namespace Tests\Production;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('production')]
final class S31MigrationCompatibilityTest extends TestCase
{
    private const S31 = 'database/migrations/2026_06_16_000000_s31_drop_dead_columns_and_tables.php';

    private PDO $admin;

    /** @var list<string> */
    private array $databases = [];

    protected function setUp(): void
    {
        parent::setUp();

        $socket = getenv('PROD03_DB_SOCKET');
        if (!is_string($socket) || $socket === '') {
            self::markTestSkipped('Set PROD03_DB_SOCKET to run the MariaDB migration integration test.');
        }

        $user = getenv('PROD03_DB_ADMIN_USER') ?: 'root';
        $password = getenv('PROD03_DB_ADMIN_PASSWORD') ?: '';
        $this->admin = new PDO("mysql:unix_socket={$socket};charset=utf8mb4", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->databases as $database) {
            $this->admin->exec("DROP DATABASE IF EXISTS `{$database}`");
        }

        parent::tearDown();
    }

    public function test_clean_install_and_s31_upgrade_variants(): void
    {
        $this->runScenario('clean');
        $this->runScenario('without_index', static function (PDO $database): void {});
        $this->runScenario('legacy_index', static function (PDO $database): void {
            $database->exec('CREATE INDEX idx_integraciones_chat_id ON integraciones_usuario(chat_id)');
            $database->exec('DROP INDEX modulos_nova_activo_index ON modulos_nova');
            $database->exec('CREATE INDEX idx_modulos_nova_activo ON modulos_nova(activo)');
        });
        $this->runScenario('laravel_index', static function (PDO $database): void {
            $database->exec('CREATE INDEX integraciones_usuario_chat_id_index ON integraciones_usuario(chat_id)');
        });
        $this->runRollbackScenario();
    }

    private function runScenario(string $name, ?callable $prepareBeforeS31 = null): void
    {
        $database = $this->createDatabase($name);

        if ($prepareBeforeS31 === null) {
            $this->artisan($database, ['migrate', '--force']);
        } else {
            $this->migrateRange($database, static fn (string $path): bool => $path < self::S31);
            $pdo = $this->connection($database);
            $prepareBeforeS31($pdo);
            $this->migrateRange($database, static fn (string $path): bool => $path >= self::S31);
        }

        $pdo = $this->connection($database);
        self::assertFalse($this->columnExists($pdo, 'integraciones_usuario', 'chat_id'), "{$name}: chat_id remains");
        self::assertFalse($this->columnExists($pdo, 'modulos_nova', 'activo'), "{$name}: activo remains");
        self::assertTrue($this->foreignKeyExists($pdo, 'integraciones_usuario_usuario_id_foreign'), "{$name}: unrelated FK was removed");
        self::assertSame(0, $this->pendingMigrationCount($database), "{$name}: migrations remain pending");
    }

    private function runRollbackScenario(): void
    {
        $database = $this->createDatabase('rollback');
        $this->migrateRange($database, static fn (string $path): bool => $path < self::S31);
        $this->artisan($database, ['migrate', '--force', '--path=' . self::S31]);
        $this->artisan($database, ['migrate:rollback', '--force', '--step=1']);

        $pdo = $this->connection($database);
        self::assertTrue($this->columnExists($pdo, 'integraciones_usuario', 'chat_id'));
        self::assertTrue($this->indexExists($pdo, 'integraciones_usuario', 'idx_integraciones_chat_id'));
        self::assertTrue($this->columnExists($pdo, 'modulos_nova', 'activo'));
        self::assertTrue($this->indexExists($pdo, 'modulos_nova', 'idx_modulos_nova_activo'));
        self::assertTrue($this->foreignKeyExists($pdo, 'integraciones_usuario_usuario_id_foreign'));
    }

    private function migrateRange(string $database, callable $include): void
    {
        $paths = glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [];
        sort($paths);

        foreach ($paths as $path) {
            $relative = 'database/migrations/' . basename($path);
            if ($include($relative)) {
                $this->artisan($database, ['migrate', '--force', '--path=' . $relative]);
            }
        }
    }

    private function createDatabase(string $scenario): string
    {
        $suffix = substr(hash('sha256', $scenario . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
        $database = 'nova_prod03_' . $suffix;
        $this->admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->databases[] = $database;

        return $database;
    }

    private function connection(string $database): PDO
    {
        $socket = (string) getenv('PROD03_DB_SOCKET');
        $user = getenv('PROD03_DB_ADMIN_USER') ?: 'root';
        $password = getenv('PROD03_DB_ADMIN_PASSWORD') ?: '';

        return new PDO("mysql:unix_socket={$socket};dbname={$database};charset=utf8mb4", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /** @param list<string> $arguments */
    private function artisan(string $database, array $arguments): string
    {
        $root = dirname(__DIR__, 2);
        $command = array_merge(['/opt/lampp/bin/php', $root . '/artisan'], $arguments);
        $environment = array_merge($_ENV, [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:cHJvZDAzLW1pZ3JhdGlvbi10ZXN0LWtleS0wMDA=',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'localhost',
            'DB_DATABASE' => $database,
            'DB_USERNAME' => getenv('PROD03_DB_ADMIN_USER') ?: 'root',
            'DB_PASSWORD' => getenv('PROD03_DB_ADMIN_PASSWORD') ?: '',
            'DB_SOCKET' => (string) getenv('PROD03_DB_SOCKET'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ]);

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Artisan.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(implode(' ', $command) . " failed:\n" . $stdout . $stderr);
        }

        return $stdout . $stderr;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $statement->execute([$table, $column]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $statement->execute([$table, $index]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function foreignKeyExists(PDO $pdo, string $constraint): bool
    {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        $statement->execute([$constraint]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function pendingMigrationCount(string $database): int
    {
        $output = $this->artisan($database, ['migrate:status']);

        return substr_count($output, 'Pending');
    }
}
