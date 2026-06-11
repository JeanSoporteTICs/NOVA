<?php

if (!function_exists('storage_base_path')) {
    function storage_base_path(string $path = ''): string {
        $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__);
        return $path === '' ? $base : $base . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    function storage_data_path(string $path = ''): string {
        return storage_base_path('data' . ($path === '' ? '' : '/' . ltrim(str_replace('\\', '/', $path), '/')));
    }

    function storage_ensure_dir(string $dir): void {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    function storage_json_flags(): int {
        return JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    }

    function storage_db_repository() {
        if (!function_exists('app') || !class_exists(\App\Support\RedmineMantencion\RedmineMantencionStorageRepository::class)) {
            return null;
        }
        try {
            $repo = app(\App\Support\RedmineMantencion\RedmineMantencionStorageRepository::class);
            return $repo->tableReady() ? $repo : null;
        } catch (Throwable) {
            return null;
        }
    }

    function storage_relative_data_path(string $path): ?string {
        $dataRoot = realpath(storage_data_path());
        if ($dataRoot === false) {
            return null;
        }
        $dataRoot = rtrim(str_replace('\\', '/', $dataRoot), '/');

        $fullNorm = str_replace('\\', '/', $path);
        if (!str_starts_with($fullNorm, '/')) {
            $fullNorm = storage_base_path($fullNorm);
        }
        $parts = [];
        foreach (explode('/', $fullNorm) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $parts[] = $part;
        }
        $fullNorm = '/' . implode('/', $parts);

        if ($fullNorm !== $dataRoot && strpos($fullNorm, $dataRoot . '/') !== 0) {
            return null;
        }
        $rel = ltrim(substr($fullNorm, strlen($dataRoot)), '/');
        return $rel === '' ? null : $rel;
    }

    function storage_read_json(string $path, $default = []) {
        $rel = storage_relative_data_path($path);
        $repo = $rel !== null ? storage_db_repository() : null;
        if ($repo !== null) {
            try {
                $data = $repo->readJson($rel);
                if ($data !== null) {
                    return $data;
                }
            } catch (Throwable) {
            }
        }

        if (getenv('REDMINE_MANTENCION_JSON_FALLBACK') !== '1') {
            return $default;
        }

        if (!is_file($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    function storage_backup_file(string $path): void {
        static $done = [];
        if (!is_file($path) || filesize($path) === 0) {
            return;
        }
        $rel = storage_relative_data_path($path);
        if ($rel === null || strpos($rel, 'backups/') === 0 || strpos($rel, 'logs/') === 0) {
            return;
        }
        $real = realpath($path);
        if ($real === false || isset($done[$real])) {
            return;
        }
        $done[$real] = true;

        $dest = storage_data_path('backups/on-write/' . date('Y-m-d') . '/' . $rel . '.' . date('His') . '.bak');
        storage_ensure_dir(dirname($dest));
        @copy($path, $dest);
    }

    function storage_write_file_locked(string $path, string $contents, int $flags = 0, bool $backup = true): bool {
        storage_ensure_dir(dirname($path));
        $append = (bool)($flags & FILE_APPEND);
        if (!$append && $backup) {
            storage_backup_file($path);
        }
        $handle = @fopen($path, $append ? 'ab' : 'c+b');
        if (!$handle) {
            return false;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }
        if (!$append) {
            ftruncate($handle, 0);
            rewind($handle);
        }
        $ok = fwrite($handle, $contents) !== false;
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        @chmod($path, 0666);
        return $ok;
    }

    function storage_write_json(string $path, $data, ?int $flags = null, bool $backup = true): bool {
        $json = json_encode($data, $flags ?? storage_json_flags());
        if ($json === false) {
            return false;
        }
        $rel = storage_relative_data_path($path);
        $repo = $rel !== null ? storage_db_repository() : null;
        if ($repo !== null) {
            try {
                $repo->writeJson($rel, $data);
                return true;
            } catch (Throwable) {
                return false;
            }
        }

        return getenv('REDMINE_MANTENCION_JSON_FALLBACK') === '1'
            ? storage_write_file_locked($path, $json, 0, $backup)
            : false;
    }

    function storage_json_by_prefix(string $prefix): array {
        $repo = storage_db_repository();
        if ($repo !== null) {
            try {
                return $repo->jsonByPrefix($prefix);
            } catch (Throwable) {
                return [];
            }
        }

        if (getenv('REDMINE_MANTENCION_JSON_FALLBACK') !== '1') {
            return [];
        }

        $root = storage_data_path($prefix);
        if (!is_dir($root)) {
            return [];
        }

        $items = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'json') {
                continue;
            }
            $rel = storage_relative_data_path($file->getPathname());
            if ($rel === null) {
                continue;
            }
            $decoded = storage_read_json($file->getPathname(), null);
            if (is_array($decoded)) {
                $items[$rel] = $decoded;
            }
        }

        ksort($items);
        return $items;
    }

    function storage_read_text(string $path, string $default = ''): string {
        $rel = storage_relative_data_path($path);
        $repo = $rel !== null ? storage_db_repository() : null;
        if ($repo !== null) {
            try {
                $data = $repo->readText($rel);
                if ($data !== null) {
                    return $data;
                }
            } catch (Throwable) {
            }
        }

        if (getenv('REDMINE_MANTENCION_JSON_FALLBACK') !== '1') {
            return $default;
        }

        return is_file($path) ? (string)@file_get_contents($path) : $default;
    }

    function storage_write_text(string $path, string $contents): bool {
        $rel = storage_relative_data_path($path);
        $repo = $rel !== null ? storage_db_repository() : null;
        if ($repo !== null) {
            try {
                $repo->writeText($rel, $contents);
                return true;
            } catch (Throwable) {
                return false;
            }
        }

        return getenv('REDMINE_MANTENCION_JSON_FALLBACK') === '1'
            ? storage_write_file_locked($path, $contents, 0, true)
            : false;
    }

    function storage_append_line(string $path, string $line): bool {
        $current = storage_read_text($path, '');
        if ($current !== '' || storage_relative_data_path($path) !== null) {
            return storage_write_text($path, rtrim($current, "\r\n") . ($current !== '' ? PHP_EOL : '') . rtrim($line, "\r\n") . PHP_EOL);
        }

        return storage_write_file_locked($path, rtrim($line, "\r\n") . PHP_EOL, FILE_APPEND, false);
    }

    function storage_truncate_file(string $path): bool {
        if (storage_relative_data_path($path) !== null) {
            return storage_write_text($path, '');
        }

        return storage_write_file_locked($path, '', 0, true);
    }

    function storage_copy_recursive(string $source, string $dest): void {
        if (is_file($source)) {
            storage_ensure_dir(dirname($dest));
            @copy($source, $dest);
            return;
        }
        if (!is_dir($source)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                storage_ensure_dir($target);
            } elseif ($item->isFile()) {
                storage_ensure_dir(dirname($target));
                @copy($item->getPathname(), $target);
            }
        }
    }

    function storage_prune_backups(int $retentionDays = 30): void {
        $root = storage_data_path('backups');
        if (!is_dir($root)) {
            return;
        }
        $limit = time() - max(1, $retentionDays) * 86400;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getMTime() < $limit) {
                @unlink($item->getPathname());
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
    }

    function storage_run_auto_backup(?array $paths = null): void {
        if (getenv('APP_BACKUP_ENABLED') === '0') {
            return;
        }
        $today = date('Y-m-d');
        $marker = storage_data_path('backups/.last_auto_backup');
        storage_ensure_dir(dirname($marker));
        $handle = @fopen($marker, 'c+b');
        if (!$handle) {
            return;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }
        $current = trim(stream_get_contents($handle));
        if ($current === $today) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }

        $paths = $paths ?? [
            'mensaje.json',
            'usuarios.json',
            'roles.json',
            'configuracion.json',
            'categorias.json',
            'procedimientos',
            'reportes',
            'horasExtras',
        ];
        $destRoot = storage_data_path('backups/auto/' . $today);
        foreach ($paths as $rel) {
            $source = storage_data_path($rel);
            if (file_exists($source)) {
                storage_copy_recursive($source, $destRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel));
            }
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $today);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        storage_prune_backups((int)(getenv('APP_BACKUP_RETENTION_DAYS') ?: 30));
    }
}
