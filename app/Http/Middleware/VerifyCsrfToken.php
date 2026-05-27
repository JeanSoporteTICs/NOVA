<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];

    protected function inExceptArray($request)
    {
        $legacyModules = array_filter(
            config('modules', []),
            static fn (array $module): bool => ($module['type'] ?? 'legacy') === 'legacy'
        );

        foreach (array_keys($legacyModules) as $module) {
            if ($request->is($module . '/*')) {
                return true;
            }
        }

        return parent::inExceptArray($request);
    }
}
