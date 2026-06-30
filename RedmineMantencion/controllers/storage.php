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

        $resolved = realpath($path);
        if ($resolved === false) {
            $directory = realpath(dirname($path));
            if ($directory !== false) {
                $resolved = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($path);
            }
        }

        $fullNorm = str_replace('\\', '/', $resolved !== false ? $resolved : $path);
        $isWindowsAbsolute = (bool)preg_match('/^[A-Za-z]:\//', $fullNorm);
        if (!str_starts_with($fullNorm, '/') && !$isWindowsAbsolute) {
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
        $fullNorm = implode('/', $parts);
        if (!$isWindowsAbsolute) {
            $fullNorm = '/' . ltrim($fullNorm, '/');
        }

        $compareFull = strtolower($fullNorm);
        $compareRoot = strtolower($dataRoot);
        if ($compareFull !== $compareRoot && strpos($compareFull, $compareRoot . '/') !== 0) {
            return null;
        }
        $rel = ltrim(substr($fullNorm, strlen($dataRoot)), '/');
        return $rel === '' ? null : $rel;
    }

    function storage_write_file_locked(string $path, string $contents, int $flags = 0): bool {
        storage_ensure_dir(dirname($path));
        $append = (bool)($flags & FILE_APPEND);
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

        return $default;
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

        return false;
    }

    function storage_append_line(string $path, string $line): bool {
        $current = storage_read_text($path, '');
        if ($current !== '' || storage_relative_data_path($path) !== null) {
            return storage_write_text($path, rtrim($current, "\r\n") . ($current !== '' ? PHP_EOL : '') . rtrim($line, "\r\n") . PHP_EOL);
        }

        return storage_write_file_locked($path, rtrim($line, "\r\n") . PHP_EOL, FILE_APPEND);
    }

    function storage_truncate_file(string $path): bool {
        if (storage_relative_data_path($path) !== null) {
            return storage_write_text($path, '');
        }

        return storage_write_file_locked($path, '');
    }

    function config_mantencion_repository() {
        if (!function_exists('app') || !class_exists(\App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository::class)) {
            return null;
        }
        try {
            $repo = app(\App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository::class);
            return $repo->tableReady() ? $repo : null;
        } catch (\Throwable) {
            return null;
        }
    }

    function mantencion_catalog_repository() {
        if (!function_exists('app') || !class_exists(\App\Modulos\RedmineMantencion\Repositories\MantencionCatalogRepository::class)) {
            return null;
        }
        try {
            $repo = app(\App\Modulos\RedmineMantencion\Repositories\MantencionCatalogRepository::class);
            return $repo->tableReady() ? $repo : null;
        } catch (\Throwable) {
            return null;
        }
    }

    function mantencion_report_repository() {
        if (!function_exists('app') || !class_exists(\App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository::class)) {
            return null;
        }
        try {
            $repo = app(\App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository::class);
            return $repo->tableReady() ? $repo : null;
        } catch (\Throwable) {
            return null;
        }
    }

    function mantencion_hours_extra_repository() {
        if (!function_exists('app') || !class_exists(\App\Modulos\RedmineMantencion\Repositories\MantencionHoursExtraRepository::class)) {
            return null;
        }
        try {
            $repo = app(\App\Modulos\RedmineMantencion\Repositories\MantencionHoursExtraRepository::class);
            return $repo->tableReady() ? $repo : null;
        } catch (\Throwable) {
            return null;
        }
    }

    function mantencion_procedimiento_repository() {
        if (!function_exists('app') || !class_exists(\App\Modulos\RedmineMantencion\Repositories\MantencionProcedimientoRepository::class)) {
            return null;
        }
        try {
            $repo = app(\App\Modulos\RedmineMantencion\Repositories\MantencionProcedimientoRepository::class);
            return $repo->tableReady() ? $repo : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
