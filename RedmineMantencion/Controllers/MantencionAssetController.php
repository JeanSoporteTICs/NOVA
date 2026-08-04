<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class MantencionAssetController extends Controller
{
    public function show(string $path): BinaryFileResponse
    {
        $path = str_replace('\\', '/', trim($path, '/'));
        abort_if($path === '' || str_contains($path, '..') || ! preg_match('~^[A-Za-z0-9_./-]+$~', $path), 404);
        abort_unless(preg_match('/\.(?:css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|xlsx)$/i', $path), 404);
        $root = realpath(base_path('RedmineMantencion/assets'));
        $file = realpath(base_path('RedmineMantencion/assets/'.$path));
        abort_if($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file), 404);

        $contentTypes = [
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        return response()->file($file, [
            'Cache-Control' => 'public, max-age=86400',
            'Content-Type' => $contentTypes[$extension] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
