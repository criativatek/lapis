<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shuts a deactivated account out, whichever door it came through.
 *
 * Deliberately a middleware on the whole `web` group rather than a
 * `Fortify::authenticateUsing` callback. The login form is only one way into
 * this application: there are also passkeys, the "remember me" cookie, and —
 * the case that matters most — the session of somebody who was already inside
 * when the operator deactivated them. A check at the login form would let all
 * three straight past. Here, the very next request logs them out.
 *
 * Runs BEFORE ResolveOrganization, so a deactivated user never resolves a
 * tenant and never reaches a query that would need one.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isActive()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // On the login form's own field, so the message lands where the person
        // is looking rather than in a flash they have to go find.
        return redirect()->route('login')->withErrors([
            'email' => __('A sua conta foi desativada. Contacte o suporte do LÁPIS.'),
        ]);
    }
}
