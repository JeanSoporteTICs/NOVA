<?php

namespace App\Modulos\Nova\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

final class DatabaseSqlBackupService
{
    public function create(string $label): string
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}");
        if (!is_array($database) || ($database['driver'] ?? '') !== 'mysql') {
            throw new RuntimeException('El respaldo SQL automatico solo admite conexiones MySQL/MariaDB.');
        }

        $binary = $this->binary();
        $directory = (string) env(
            'NOVA_BACKUP_PATH',
            storage_path('app' . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'sql')
        );
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio protegido de respaldos SQL.');
        }

        $safeLabel = preg_replace('/[^a-z0-9_-]+/i', '-', trim($label)) ?: 'backup';
        $path = $directory . DIRECTORY_SEPARATOR . $safeLabel . '-' . date('Ymd-His') . '.sql';
        $command = [
            $binary,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--default-character-set=utf8mb4',
            '--host=' . (string) ($database['host'] ?? '127.0.0.1'),
            '--port=' . (string) ($database['port'] ?? '3306'),
            '--user=' . (string) ($database['username'] ?? ''),
            '--result-file=' . $path,
            '--databases',
            (string) ($database['database'] ?? ''),
        ];

        $process = new Process($command, base_path(), [
            'MYSQL_PWD' => (string) ($database['password'] ?? ''),
        ]);
        $process->setTimeout(300);
        $process->run();
        if (!$process->isSuccessful() || !is_file($path) || filesize($path) === 0) {
            @unlink($path);
            $error = trim($process->getErrorOutput());
            throw new RuntimeException('No se pudo crear el respaldo SQL' . ($error !== '' ? ': ' . $error : '.'));
        }
        @chmod($path, 0600);

        return $path;
    }

    private function binary(): string
    {
        $configured = trim((string) env('MYSQLDUMP_BINARY', ''));
        $candidates = array_filter([
            $configured,
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            '/opt/lampp/bin/mysqldump',
            '/usr/bin/mysqldump',
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('No se encontro mysqldump. Configura MYSQLDUMP_BINARY.');
    }
}
