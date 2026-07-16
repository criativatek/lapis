<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards routes that only make sense inside an organization.
 *
 * ResolveOrganization runs first and binds the tenant when there is one. This
 * turns "no tenant" into a clear 403 for the teacher instead of letting the
 * request reach a query that would throw TenantNotResolvedException and 500.
 */
class RequireOrganization
{
    public function __construct(protected CurrentOrganization $currentOrganization) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->currentOrganization->isResolved()) {
            abort(403, __('Não tem nenhuma organização ativa.'));
        }

        return $next($request);
    }
}
