<?php

namespace App\Http\Controllers;

use App\Support\Auth\LegacyUserProvider;
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

    public function login(Request $request, LegacyUserProvider $users): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = $users->attempt($credentials['username'], $credentials['password']);
        if ($user === null) {
            return back()
                ->withInput(['username' => $credentials['username']])
                ->withErrors(['username' => 'Credenciales invalidas.']);
        }

        $request->session()->regenerate();
        $request->session()->put('nova_user', $user);
        $request->session()->put('nova_last_activity', time());

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
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
}
