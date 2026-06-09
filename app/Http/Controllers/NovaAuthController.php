<?php

namespace App\Http\Controllers;

use App\Support\Auth\LegacyUserProvider;
use App\Support\Nova\NovaAuditRepository;
use App\Support\Nova\NovaSettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RedmineTic\Support\Redmine\RedmineDataRepository;

class NovaAuthController extends Controller
{
    public function showLogin(Request $request)
    {
        if ($request->session()->has('nova_user')) {
            return redirect()->route('home');
        }

        return view('nova.auth.login');
    }

    public function login(Request $request, LegacyUserProvider $users, NovaAuditRepository $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = $users->attempt($credentials['username'], $credentials['password']);
        if ($user === null) {
            $audit->record('login_failure', 'Intento de acceso fallido a NOVA.', ['username' => $credentials['username']], $request);
            require_once $this->legacyLoggerPath();
            if (function_exists('log_security_event')) {
                log_security_event('LOGIN_FAILURE', sprintf('Intento NOVA con "%s" | IP %s', $credentials['username'], $request->ip()));
            }

            return back()
                ->withInput(['username' => $credentials['username']])
                ->withErrors(['username' => 'Las credenciales no corresponden.']);
        }

        $request->session()->regenerate();
        $request->session()->put('nova_user', $user);
        $request->session()->put('nova_last_activity', time());
        require_once $this->legacyLoggerPath();
        if (function_exists('log_security_event')) {
            log_security_event('LOGIN_SUCCESS', sprintf('NOVA User %s (ID %s) | IP %s', $user['name'] ?? '', $user['id'] ?? '', $request->ip()));
        }
        $audit->record('login_success', 'Inicio de sesion NOVA.', ['username' => $credentials['username']], $request);

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request, NovaAuditRepository $audit): RedirectResponse
    {
        $sessionUser = $request->session()->get('nova_user');
        require_once $this->legacyLoggerPath();
        if (is_array($sessionUser) && function_exists('log_security_event')) {
            log_security_event('LOGOUT', sprintf('NOVA sesion cerrada por %s (ID %s) | IP %s', $sessionUser['name'] ?? '', $sessionUser['id'] ?? '', $request->ip()));
        }
        if (is_array($sessionUser)) {
            $audit->record('logout', 'Cierre de sesion NOVA.', [], $request);
        }
        $request->session()->forget(['nova_user', 'nova_last_activity']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (session_status() === PHP_SESSION_NONE && $request->cookies->has(session_name())) {
            session_start();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        return redirect()->route('login');
    }

    public function extendSession(Request $request, LegacyUserProvider $users, RedmineDataRepository $redmine, NovaSettingsRepository $settings): JsonResponse
    {
        $sessionUser = $request->session()->get('nova_user');
        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            require_once $this->legacyLoggerPath();
            if (function_exists('log_security_event')) {
                log_security_event('SESSION_EXTEND_FAIL', sprintf('NOVA sesion no disponible | IP %s', $request->ip()));
            }
            $redmine->recordActivity('sesion_extender_error', [
                'error' => 'Sesion no disponible.',
                'ip' => $request->ip(),
            ]);
            return response()->json(['ok' => false, 'msg' => 'Sesion no disponible.'], 401);
        }

        $credentials = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $users->attempt((string) $sessionUser['id'], $credentials['password']);
        if ($user === null) {
            require_once $this->legacyLoggerPath();
            if (function_exists('log_security_event')) {
                log_security_event('SESSION_EXTEND_FAIL', sprintf('NOVA contraseña incorrecta para %s (ID %s) | IP %s', $sessionUser['name'] ?? '', $sessionUser['id'] ?? '', $request->ip()));
            }
            $redmine->recordActivity('sesion_extender_error', [
                'user_id' => $sessionUser['id'] ?? '',
                'user_name' => $sessionUser['name'] ?? '',
                'error' => 'Contrasena incorrecta.',
                'ip' => $request->ip(),
            ]);
            return response()->json(['ok' => false, 'msg' => 'Contrasena incorrecta.'], 422);
        }

        $timeout = $settings->sessionTimeout();
        $request->session()->put('nova_user', $user);
        $request->session()->put('nova_last_activity', time());
        require_once $this->legacyLoggerPath();
        if (function_exists('log_security_event')) {
            log_security_event('SESSION_EXTEND', sprintf('NOVA sesion extendida por %s (ID %s) | timeout %s | IP %s', $user['name'] ?? '', $user['id'] ?? '', $timeout, $request->ip()));
        }
        $redmine->recordActivity('sesion_extendida', [
            'user_id' => $user['id'] ?? '',
            'user_name' => $user['name'] ?? '',
            'timeout' => $timeout,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'ok' => true,
            'timeout' => $timeout,
            'remaining' => $timeout,
        ]);
    }

    private function legacyLoggerPath(): string
    {
        $modulePath = rtrim((string) data_get(config('modules.redmine-mantencion', []), 'path', base_path('redmine-mantencion')), DIRECTORY_SEPARATOR);

        return $modulePath . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'logger.php';
    }
}
