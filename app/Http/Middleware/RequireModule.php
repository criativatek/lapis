<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ChecksSafeHttpMethod;
use App\Support\Entitlements\AccessState;
use App\Support\Entitlements\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a route whose module the organization is not entitled to, and — new
 * in §Lote 2 — restricts a `ReadOnly` module (a paused subscription, §9 of the
 * Entitlements brief) to safe HTTP methods only.
 *
 * Declared per route — `->middleware('module:agenda')` — rather than through a
 * central route-name → module map. A map has to be kept in sync by hand and
 * silently stops gating a route the day someone renames it; the requirement
 * sitting on the route itself cannot drift away from it.
 *
 * `ReadOnly` never bypasses anything else. Tenancy (global scopes), policies
 * and every other authorization layer still run exactly as before — this
 * only adds the read/write distinction on top of the existing entitlement
 * gate.
 */
class RequireModule
{
    use ChecksSafeHttpMethod;

    public function __construct(protected Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $state = $this->entitlements->accessState($module);

        if ($state === AccessState::Locked) {
            abort(403, __('O seu plano não inclui este módulo.'));
        }

        if ($state === AccessState::ReadOnly && ! $this->requestIsSafe($request)) {
            abort(403, __('Este módulo está temporariamente só para consulta — a assinatura da organização está suspensa. Reative a assinatura para voltar a alterar dados.'));
        }

        return $next($request);
    }
}
