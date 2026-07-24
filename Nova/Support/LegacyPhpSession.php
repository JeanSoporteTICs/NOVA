<?php

namespace App\Modulos\Nova\Support;

use Illuminate\Http\Request;

final class LegacyPhpSession
{
    private const SESSION_NAME = 'NOVALEGACY';

    public static function start(Request $request, string $scope): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $sessionId = self::sessionId($request, $scope);
        session_name(self::SESSION_NAME);
        session_id($sessionId);

        $_COOKIE[self::SESSION_NAME] = $sessionId;
        unset($_COOKIE['PHPSESSID']);

        session_start();
    }

    public static function destroyIfActive(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        session_destroy();
    }

    private static function sessionId(Request $request, string $scope): string
    {
        $laravelId = method_exists($request->session(), 'getId') ? (string) $request->session()->getId() : '';
        if ($laravelId === '') {
            $laravelId = (string) ($request->cookies->get(config('session.cookie', 'laravel_session')) ?? '');
        }

        return substr(hash('sha256', $scope . '|' . $laravelId), 0, 48);
    }
}
