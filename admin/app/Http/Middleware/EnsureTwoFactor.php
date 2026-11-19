<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is optional. Once a user turned it on, a code is asked once per
 * session, whether they signed in with a password or with Google.
 */
class EnsureTwoFactor
{
    public const SESSION_KEY = 'two_factor_passed';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->hasTwoFactor() && ! $request->session()->get(self::SESSION_KEY)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Two-factor code required.'], 423)
                : redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
