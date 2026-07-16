<?php

namespace App\Http\Middleware;

use App\Support\Entitlements\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a route whose module the organization is not entitled to.
 *
 * Declared per route — `->middleware('module:agenda')` — rather than through a
 * central route-name → module map. A map has to be kept in sync by hand and
 * silently stops gating a route the day someone renames it; the requirement
 * sitting on the route itself cannot drift away from it.
 */
class RequireModule
{
    public function __construct(protected Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! $this->entitlements->allows($module)) {
            abort(403, __('O seu plano não inclui este módulo.'));
        }

        return $next($request);
    }
}
