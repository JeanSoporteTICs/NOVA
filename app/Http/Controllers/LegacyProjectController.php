<?php

namespace App\Http\Controllers;

use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\ProjectAccessGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LegacyProjectController extends Controller
{
    private ModuleRegistry $modules;

    public function __construct(ModuleRegistry $modules)
    {
        $this->modules = $modules;
    }

    public function index(string $project, ProjectAccessGuard $access)
    {
        $config = $this->projectConfig($project);
        $this->abortIfDisabled($config);
        if (!$this->userCanAccessProject($project, $config, $access)) {
            return redirect()->route('home')->with('access_error', $access->deniedMessage((string) ($config['name'] ?? $project)));
        }

        return $this->dispatchPhp($project, $config, $config['entry']);
    }

    public function passthrough(Request $request, string $project, ?string $path = null)
    {
        $config = $this->projectConfig($project);
        $this->abortIfDisabled($config);
        $path = $this->normalizePath($path ?: $config['entry']);

        if ($path === '') {
            $path = $config['entry'];
        }

        if (in_array(strtolower($path), ['login.php', 'app/views/auth/login.php'], true)) {
            return redirect()->route('login');
        }

        if (strtolower($path) === 'logout.php') {
            return redirect()->route('logout');
        }

        $fullPath = $this->resolveInsideProject($config, $path);
        if (!is_file($fullPath)) {
            abort(404);
        }

        if (strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)) === 'php') {
            $access = app(ProjectAccessGuard::class);
            if (!$this->userCanAccessProject($project, $config, $access)) {
                return redirect()->route('home')->with('access_error', $access->deniedMessage((string) ($config['name'] ?? $project)));
            }
            $this->assertAllowedRoot($path, $config['allowed_php_roots']);

            return $this->dispatchPhp($project, $config, $path);
        }

        $this->assertAllowedRoot($path, $config['allowed_static_roots']);

        $response = new BinaryFileResponse($fullPath);
        $contentType = $this->staticContentType($fullPath);
        if ($contentType !== null) {
            $response->headers->set('Content-Type', $contentType);
        }

        return $response;
    }

    public function asset(Request $request, string $project, string $path)
    {
        return $this->passthrough($request, $project, 'assets/' . $path);
    }

    private function dispatchPhp(string $project, array $config, string $path): Response
    {
        $fullPath = $this->resolveInsideProject($config, $path);
        if (!is_file($fullPath)) {
            abort(404);
        }

        $previousDirectory = getcwd();
        chdir($config['path']);

        ob_start();
        try {
            $this->prepareLegacyRuntime($project, $config);
            require $fullPath;
        } finally {
            if ($previousDirectory !== false) {
                chdir($previousDirectory);
            }
        }

        $headers = headers_list();
        header_remove();

        $content = $this->rewriteLegacyOutput((string) ob_get_clean());
        $status = http_response_code() ?: 200;
        $response = response($content, $status);

        foreach ($headers as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));
                $response->headers->set('Location', $this->rewriteLegacyUrl($location));
            } elseif (stripos($header, 'Content-Type:') === 0) {
                $response->headers->set('Content-Type', trim(substr($header, strlen('Content-Type:'))));
            }
        }

        return $response;
    }

    private function prepareLegacyRuntime(string $project, array $config): void
    {
        $logDirectory = $config['path'] . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0777, true);
        }

        ini_set('log_errors', '1');
        ini_set('error_log', $logDirectory . DIRECTORY_SEPARATOR . 'php-error.log');
        $this->syncNovaUserToLegacySession($project);
    }

    private function rewriteLegacyOutput(string $content): string
    {
        $prefix = $this->publicBasePrefix();
        if ($prefix === '') {
            return $content;
        }

        foreach (array_keys(config('modules', [])) as $module) {
            $content = str_replace('="/' . $module, '="' . $prefix . '/' . $module, $content);
            $content = str_replace("='/" . $module, "='" . $prefix . '/' . $module, $content);
            $content = str_replace('(/' . $module, '(' . $prefix . '/' . $module, $content);
            $content = str_replace("'/". $module, "'" . $prefix . '/' . $module, $content);
            $content = str_replace('"/' . $module, '"' . $prefix . '/' . $module, $content);
        }

        return $content;
    }

    private function rewriteLegacyUrl(string $url): string
    {
        $prefix = $this->publicBasePrefix();
        if ($prefix === '' || !str_starts_with($url, '/')) {
            return $url;
        }

        foreach (array_keys(config('modules', [])) as $module) {
            if ($url === '/' . $module || str_starts_with($url, '/' . $module . '/')) {
                return $prefix . $url;
            }
        }

        return $url;
    }

    private function publicBasePrefix(): string
    {
        return rtrim(request()->getBaseUrl(), '/');
    }

    private function staticContentType(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => null,
        };
    }

    private function syncNovaUserToLegacySession(string $project): void
    {
        $novaUser = request()->session()->get('nova_user');
        if (!is_array($novaUser)) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $projectUser = app(ProjectAccessGuard::class)->projectUser($project, $novaUser);

        $_SESSION['user'] = is_array($projectUser) ? $projectUser : ($novaUser['legacy'] ?? [
            'id' => $novaUser['id'] ?? '',
            'nombre' => $novaUser['name'] ?? '',
            'rut' => $novaUser['rut'] ?? '',
            'rol' => $novaUser['role'] ?? 'usuario',
        ]);
        $_SESSION['last_activity'] = time();
    }

    private function abortIfDisabled(array $config): void
    {
        abort_if(!($config['enabled'] ?? true), 404);
    }

    private function userCanAccessProject(string $project, array $config, ProjectAccessGuard $access): bool
    {
        $user = request()->session()->get('nova_user', []);
        if (!is_array($user)) {
            return false;
        }

        return $access->canAccess($project, $user);
    }

    private function projectConfig(string $project): array
    {
        $config = $this->modules->get($project);
        if (!is_array($config) || !is_dir($config['path'] ?? null)) {
            abort(404);
        }

        return $config;
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', rawurldecode($path));
        $path = ltrim($path, '/');
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                abort(404);
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function resolveInsideProject(array $config, string $path): string
    {
        $base = realpath($config['path']);
        $fullPath = realpath($config['path'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));

        if ($base === false || $fullPath === false || !str_starts_with($fullPath, $base . DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        return $fullPath;
    }

    private function assertAllowedRoot(string $path, array $allowedRoots): void
    {
        $path = trim($path, '/');

        foreach ($allowedRoots as $root) {
            $root = trim((string) $root, '/');
            if ($root === '' && !str_contains($path, '/')) {
                return;
            }

            if ($root !== '' && ($path === $root || str_starts_with($path, $root . '/'))) {
                return;
            }
        }

        abort(404);
    }
}
