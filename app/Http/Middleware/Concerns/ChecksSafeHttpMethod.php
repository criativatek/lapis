<?php

namespace App\Http\Middleware\Concerns;

use Illuminate\Http\Request;

/**
 * A "safe" HTTP method — GET, HEAD, OPTIONS (RFC 9110 §9.2.1) — is by
 * definition one that never itself changes server state.
 *
 * Two middlewares gate on exactly this distinction, for two different
 * reasons: `EnsureAccountIsOperational` for an account-wide closure window,
 * `RequireModule` for a per-module read-only access state (§Lote 2). Both
 * need the same one-line answer to "is this request even capable of writing
 * anything?", so it lives here once rather than as two inline checks that
 * could drift apart.
 */
trait ChecksSafeHttpMethod
{
    protected function requestIsSafe(Request $request): bool
    {
        return $request->isMethod('GET') || $request->isMethod('HEAD') || $request->isMethod('OPTIONS');
    }
}
