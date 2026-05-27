<?php

namespace App\Http\Middleware;

use App\Support\Auth\NovaUserRepository;
use App\Support\Nova\NovaSettingsRepository;
use Closure;
use Illuminate\Http\Request;

class EnsureNovaAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->session()->get('nova_user');
        $lastActivity = (int) $request->session()->get('nova_last_activity', 0);
        $timeout = app(NovaSettingsRepository::class)->sessionTimeout();
        $expired = $lastActivity > 0 && time() - $lastActivity > $timeout;
        $isSessionExtend = $request->routeIs('session.extend');

        if (!$user || !$this->sessionUserStillAllowed($user) || ($expired && !$isSessionExtend)) {
            $request->session()->forget(['nova_user', 'nova_last_activity']);

            return redirect()->route('login')->withErrors([
                'username' => 'Debes iniciar sesion en NOVA o contactar con el administrador.',
            ]);
        }

        if (!$isSessionExtend) {
            $request->session()->put('nova_last_activity', time());
        }

        return $next($request);
    }

    private function sessionUserStillAllowed(mixed $user): bool
    {
        if (!is_array($user)) {
            return false;
        }

        try {
            $repository = app(NovaUserRepository::class);
            foreach (['username', 'id', 'rut_sin_dv', 'core_user', 'rut'] as $field) {
                $value = trim((string) ($user[$field] ?? ''));
                $record = $value !== '' ? $repository->find($value) : null;
                if (is_array($record)) {
                    $state = strtolower(trim((string) ($record['status'] ?? 'activo')));

                    return !in_array($state, ['bloqueado', 'inactivo', 'baneado'], true);
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return false;
    }
}
