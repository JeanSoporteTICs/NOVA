<?php

namespace App\Http\Controllers;

use App\Repositories\Nova\NovaAuditRepository;
use App\Services\Auth\LegacyUserProvider;
use App\Services\Nova\LegacyLoggerService;
use App\Support\Nova\NovaSettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NovaAuthController extends Controller
{
    public function __construct(private LegacyLoggerService $logger)
    {
    }

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
            'username' => ['required', 'string', 'max:180'],
            'password' => ['required', 'string', 'max:512'],
        ]);

        $user = $users->attempt($credentials['username'], $credentials['password']);
        if ($user === null) {
            $audit->record('login_failure', 'Intento de acceso fallido a NOVA.', ['username' => $credentials['username']], $request);
            $this->logger->log('LOGIN_FAILURE', sprintf('Intento NOVA con "%s" | IP %s', $credentials['username'], $request->ip()));

            return back()
                ->withInput(['username' => $credentials['username']])
                ->withErrors(['username' => 'Las credenciales no corresponden.']);
        }

        $request->session()->regenerate();
        $request->session()->put('nova_user', $user);
        $request->session()->put('nova_last_activity', time());
        $this->logger->log('LOGIN_SUCCESS', sprintf('NOVA User %s (ID %s) | IP %s', $user['name'] ?? '', $user['id'] ?? '', $request->ip()));
        $audit->record('login_success', 'Inicio de sesion NOVA.', ['username' => $credentials['username']], $request);

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request, NovaAuditRepository $audit): RedirectResponse
    {
        $sessionUser = $request->session()->get('nova_user');
        if (is_array($sessionUser)) {
            $this->logger->log('LOGOUT', sprintf('NOVA sesion cerrada por %s (ID %s) | IP %s', $sessionUser['name'] ?? '', $sessionUser['id'] ?? '', $request->ip()));
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

    public function extendSession(Request $request, LegacyUserProvider $users, NovaAuditRepository $audit, NovaSettingsRepository $settings): JsonResponse
    {
        $sessionUser = $request->session()->get('nova_user');
        if (!is_array($sessionUser) || empty($sessionUser['id'])) {
            $this->logger->log('SESSION_EXTEND_FAIL', sprintf('NOVA sesion no disponible | IP %s', $request->ip()));
            $audit->record('sesion_extender_error', 'Sesion no disponible.', ['ip' => $request->ip()], $request);

            return response()->json(['ok' => false, 'msg' => 'Sesion no disponible.'], 401);
        }

        $credentials = $request->validate([
            'password' => ['required', 'string', 'max:512'],
        ]);

        $user = $users->attempt((string) $sessionUser['id'], $credentials['password']);
        if ($user === null) {
            $this->logger->log('SESSION_EXTEND_FAIL', sprintf('NOVA contraseña incorrecta para %s (ID %s) | IP %s', $sessionUser['name'] ?? '', $sessionUser['id'] ?? '', $request->ip()));
            $audit->record('sesion_extender_error', 'Contrasena incorrecta.', [
                'user_id'   => $sessionUser['id'] ?? '',
                'user_name' => $sessionUser['name'] ?? '',
                'ip'        => $request->ip(),
            ], $request);

            return response()->json(['ok' => false, 'msg' => 'Contrasena incorrecta.'], 422);
        }

        $timeout = $settings->sessionTimeout();
        $request->session()->put('nova_user', $user);
        $request->session()->put('nova_last_activity', time());
        $this->logger->log('SESSION_EXTEND', sprintf('NOVA sesion extendida por %s (ID %s) | timeout %s | IP %s', $user['name'] ?? '', $user['id'] ?? '', $timeout, $request->ip()));
        $audit->record('sesion_extendida', 'Sesion extendida.', [
            'user_id'   => $user['id'] ?? '',
            'user_name' => $user['name'] ?? '',
            'timeout'   => $timeout,
            'ip'        => $request->ip(),
        ], $request);

        return response()->json([
            'ok'        => true,
            'timeout'   => $timeout,
            'remaining' => $timeout,
        ]);
    }
}
