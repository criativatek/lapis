<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the organization for the current request and binds it in the container.
 *
 * The session only ever holds a candidate id — it is never trusted. Membership is
 * re-checked against the database on every request, so a tampered session cookie
 * cannot move a user into someone else's organization.
 *
 * Best-effort by design: it never aborts. Plenty of routes — logout, profile,
 * e-mail verification — are legitimately tenant-less, so requiring an
 * organization here would break them. Routes that do need one declare
 * RequireOrganization; anything tenant-scoped that slips through without it
 * throws TenantNotResolvedException at query time rather than leaking rows.
 */
class ResolveOrganization
{
    public function __construct(protected CurrentOrganization $currentOrganization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $organization = $this->organizationFor($request, $user);

        if ($organization === null) {
            return $next($request);
        }

        $this->currentOrganization->set($organization);
        $request->session()->put('organization_id', $organization->getKey());

        return $next($request);
    }

    protected function organizationFor(Request $request, User $user): ?Organization
    {
        $candidateId = $request->session()->get('organization_id');

        // Membership is the authority, not the session. A session id that the user
        // is not a member of resolves to null and falls back to their first org.
        if ($candidateId !== null) {
            $organization = $user->organizations()->whereKey($candidateId)->first();

            if ($organization !== null) {
                return $organization;
            }
        }

        return $user->organizations()->oldest('organization_memberships.joined_at')->first();
    }
}
