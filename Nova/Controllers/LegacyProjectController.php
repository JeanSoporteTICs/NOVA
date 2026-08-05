<?php

namespace App\Modulos\Nova\Controllers;

use App\Http\Controllers\Controller;

use App\Modulos\Nova\Repositories\ModuleRegistry;
use App\Modulos\Nova\Services\ProjectAccessGuard;
use App\Modulos\Nova\Support\LegacyPhpSession;
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

        if ($project === 'redmine-mantencion' && strtolower($path) === 'usuarios/usuarios.php') {
            $path = 'views/Usuarios/usuarios.php';
        }

        if ($project === 'redmine-mantencion' && strtolower($path) === 'views/dashboard.php') {
            $path = 'views/Dashboard/dashboard.php';
        }

        if ($project === 'emach' && strtolower($path) === 'views/mantenedor/mantenedor.php') {
            return redirect()->route('integrations.emach');
        }

        if (in_array(strtolower($path), ['login.php', 'app/views/auth/login.php'], true)) {
            return redirect()->route('login');
        }

        if (strtolower($path) === 'logout.php') {
            $url   = route('logout');
            $token = csrf_token();
            return response(
                "<form id='_lf' method='POST' action='" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "'>" .
                "<input type='hidden' name='_token' value='" . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . "'>" .
                "</form><script>document.getElementById('_lf').submit();</script>"
            );
        }

        $fullPath = $this->resolveInsideProject($config, $path);
        if (!is_file($fullPath)) {
            abort(404);
        }

        if (strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)) === 'php') {
            $isPublicEndpoint = $this->isPublicLegacyEndpoint($request, $project, $path);
            $access = app(ProjectAccessGuard::class);
            if (!$isPublicEndpoint && !$this->userCanAccessProject($project, $config, $access)) {
                return redirect()->route('home')->with('access_error', $access->deniedMessage((string) ($config['name'] ?? $project)));
            }
            $this->assertAllowedRoot($path, $config['allowed_php_roots']);

            return $this->dispatchPhp($project, $config, $path, !$isPublicEndpoint);
        }

        $this->assertAllowedRoot($path, $config['allowed_static_roots']);

        $response = new BinaryFileResponse($fullPath);
        $contentType = $this->staticContentType($fullPath);
        if ($contentType !== null) {
            $response->headers->set('Content-Type', $contentType);
        }

        return $response;
    }

    public function toggleMantencionHoursExtra(Request $request, ProjectAccessGuard $access)
    {
        $config = $this->projectConfig('redmine-mantencion');
        $this->abortIfDisabled($config);
        if (!$this->userCanAccessProject('redmine-mantencion', $config, $access)) {
            return response()->json(['ok' => false, 'message' => 'No tienes acceso a Redmine Mantención.'], 403);
        }

        $submittedToken = (string) $request->input('_token', $request->header('X-CSRF-TOKEN', ''));
        if ($submittedToken === '' || !hash_equals((string) $request->session()->token(), $submittedToken)) {
            return response()->json(['ok' => false, 'message' => 'La validación de seguridad venció. Recarga la página.'], 419);
        }

        $this->prepareLegacyRuntime('redmine-mantencion', $config);
        require_once $config['path'] . '/controllers/dashboard.php';

        if (!auth_can('horas_extra_editar')) {
            return response()->json(['ok' => false, 'message' => 'No tienes permiso para editar Horas extra.'], 403);
        }

        $id = trim((string) $request->input('id', ''));
        $messages = load_messages();
        if ($id === '' || !dashboard_can_access_message($messages, $id)) {
            return response()->json(['ok' => false, 'message' => 'No se encontró la solicitud o no tienes acceso.'], 404);
        }

        $updatedMessage = null;
        $enabled = false;
        foreach ($messages as $message) {
            if ((string) ($message['id'] ?? '') !== $id) {
                continue;
            }
            $enabled = normalize_hour_extra_value($message['hora_extra'] ?? '') !== '1';
            $message['hora_extra'] = $enabled ? '1' : '0';
            $message['tiempo_estimado'] = $enabled ? '1' : '';
            $updatedMessage = $message;
            break;
        }

        if (!is_array($updatedMessage) || !dashboard_update_message_hora_extra($updatedMessage)) {
            return response()->json(['ok' => false, 'message' => 'No se pudo actualizar la hora extra.'], 422);
        }

        if ($enabled) {
            append_hours_extra_record($updatedMessage);
        } else {
            remove_hours_extra_record_by_id($id);
        }
        dashboard_log_action('HORA_EXTRA', ($enabled ? 'Activo' : 'Desactivo') . ' hora extra en reporte ID ' . $id);

        return response()->json([
            'ok' => true,
            'message' => $enabled ? 'Hora extra activada.' : 'Hora extra desactivada.',
            'row' => [
                'id' => $id,
                'hora_extra' => $enabled ? '1' : '0',
                'tiempo_estimado' => $updatedMessage['tiempo_estimado'],
            ],
        ]);
    }

    public function asset(Request $request, string $project, string $path)
    {
        return $this->passthrough($request, $project, 'assets/' . $path);
    }

    private function dispatchPhp(string $project, array $config, string $path, bool $rewriteOutput = true): Response
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

        $content = (string) ob_get_clean();
        if ($rewriteOutput) {
            $content = $this->rewriteLegacyOutput($content);
        }
        $status = http_response_code() ?: 200;
        $response = response($content, $status);

        foreach ($headers as $header) {
            [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
            $name = trim($name);
            $value = trim($value);
            if ($name === '' || $value === '') {
                continue;
            }
            if (strcasecmp($name, 'Location') === 0) {
                $value = $this->rewriteLegacyUrl($value);
            }
            $response->headers->set($name, $value, strcasecmp($name, 'Set-Cookie') !== 0);
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
        $content = $this->injectLaravelCsrfIntoPostForms($content);
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

    /**
     * Add the NOVA session token to legacy POST forms as a second line of
     * defence. Mantencion validates its legacy csrf_token itself, while this
     * hidden field keeps forms valid when the separate NOVALEGACY session is
     * rebuilt between rendering and submission.
     */
    private function injectLaravelCsrfIntoPostForms(string $content): string
    {
        $token = trim((string) csrf_token());
        if ($token === '' || stripos($content, '<form') === false) {
            return $content;
        }

        $rewritten = preg_replace_callback(
            '/<form\b(?=[^>]*\bmethod\s*=\s*(["\']?)post\1)[^>]*>.*?<\/form>/is',
            static function (array $match) use ($token): string {
                $form = $match[0];
                if (preg_match('/\bname\s*=\s*(["\'])_token\1/i', $form) === 1) {
                    return $form;
                }

                $field = '<input type="hidden" name="_token" value="'
                    . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
                    . '">';

                return (string) preg_replace('/(<form\b[^>]*>)/i', '$1' . $field, $form, 1);
            },
            $content
        );

        return is_string($rewritten) ? $rewritten : $content;
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

        LegacyPhpSession::start(request(), $project);

        $projectUser = app(ProjectAccessGuard::class)->projectUser($project, $novaUser);

        if (is_array($projectUser)) {
            // El perfil del modulo puede usar como `id` el identificador de
            // Redmine. Conservamos aparte la identidad central para que las
            // integraciones personales siempre resuelvan usuarios_nova.
            $projectUser['_nova_user_id'] = (string) ($novaUser['id'] ?? $projectUser['_nova_user_id'] ?? '');
        }

        $_SESSION['user'] = is_array($projectUser) ? $projectUser : ($novaUser['legacy'] ?? [
            'id' => $novaUser['id'] ?? '',
            'nombre' => $novaUser['name'] ?? '',
            'rut' => $novaUser['rut'] ?? '',
            'rol' => $novaUser['role'] ?? 'usuario',
        ]);
        $_SESSION['last_activity'] = time();

        // Release the legacy PHP session file lock as soon as we're done writing to
        // it. PHP's default file-based session handler holds an exclusive lock from
        // session_start() until the script ends (or session_write_close() is called),
        // and LegacyPhpSession derives a deterministic, per-user+module session ID —
        // so without an early close, concurrent AJAX requests from the same user in
        // the same module queue up waiting for each other's lock instead of running
        // in parallel. Precedent: emach already did this; redmine-mantencion's own
        // toggle_hora_extra AJAX action was serializing on exactly this lock (confirmed
        // via DevTools timing showing requests finishing ~6-7s apart in sequence).
        // Any legacy code path that still needs to WRITE new session data later
        // (e.g. a non-AJAX flash/toast message before a redirect) already reopens the
        // session itself via auth_start_session() before writing — see dashboard_set_flash()
        // / dashboard_set_toast() in RedmineMantencion/controllers/dashboard.php.
        if (in_array($project, ['emach', 'redmine-mantencion', 'telegram'], true)) {
            session_write_close();
        }
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

    private function isPublicLegacyEndpoint(Request $request, string $project, string $path): bool
    {
        if ($project !== 'redmine-mantencion') {
            return false;
        }

        return false;
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

        $normalized = implode('/', $parts);

        return $this->normalizeLegacyViewShortcut($normalized);
    }

    private function normalizeLegacyViewShortcut(string $path): string
    {
        if (str_starts_with($path, 'views/') || !str_ends_with(strtolower($path), '.php')) {
            return $path;
        }

        $section = strtolower(strtok($path, '/') ?: '');
        $knownViewSections = [
            'categorias',
            'configuracion',
            'dashboard',
            'estadisticas',
            'historico',
            'horasextra',
            'integraciones',
            'pendientes',
            'procedimientos',
            'security',
            'usuarios',
        ];

        return in_array($section, $knownViewSections, true) ? 'views/' . $path : $path;
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
