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

}
