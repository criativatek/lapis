<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the platform backoffice. Only a platform administrator (the SaaS
 * operator) may pass — a teacher hitting /admin gets a clean 403. This is
 * separate from tenancy: the backoffice runs WITHOUT the `organization`
 * middleware because it spans every organization.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isPlatformAdmin()) {
            abort(403, __('Acesso reservado a administradores da plataforma.'));
        }

        return $next($request);
    }
}
