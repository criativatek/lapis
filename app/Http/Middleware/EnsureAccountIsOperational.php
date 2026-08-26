<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ChecksSafeHttpMethod;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks new pedagogical/organizational activity while an account or the
 * current organization is in a recoverable closure window (§6, §11 of the
 * lifecycle brief).
 *
 * Deliberately never blocks a safe method (GET/HEAD/OPTIONS — see
 * ChecksSafeHttpMethod, shared with RequireModule's own read/write
 * distinction, §Lote 2): someone in closure can still browse everything they
 * could before — read access was never the concern, and refusing it would
 * turn a recoverable, low-drama pause into something that looks broken. What
 * is refused is the next WRITE: creating a class, grading, inviting a member,
 * editing the school's identity. That single rule covers every "não
 * permitir" in §6 and §11 at once, because every item on both lists is a
 * mutation.
 *
 * An explicit allow-list, not a block-list: the routes a person in closure
 * is still allowed to use (cancel, export, switch organization, sign out,
 * manage their own login) are few and known; the pedagogical surface is
 * large and grows. Defaulting to "blocked unless listed" is the side that
 * fails safe.
 *
 * Runs after ResolveOrganization (needs the resolved tenant, if any) and
 * after EnsureUserIsActive (deactivation is a different, earlier gate).
 */
class EnsureAccountIsOperational
{
    use ChecksSafeHttpMethod;

    /**
     * @var list<string>
     */
    protected const ALLOWED_ROUTE_NAMES = [
        'logout',
        'account.closure.request',
        'account.closure.cancel',
        'organization.closure.request',
        'organization.closure.cancel',
        'organizations.switch',
        'data-exports.store',
        'user-password.update',
        'password.confirm.store',
    ];

    /**
     * @var list<string>
     */
    protected const ALLOWED_ROUTE_NAME_PREFIXES = [
        'two-factor.',
        'passkey.',
    ];

    public function __construct(protected CurrentOrganization $currentOrganization) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->requestIsSafe($request)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user === null || $this->routeIsAllowed($request)) {
            return $next($request);
        }

        if ($user->isClosureRequested()) {
            abort(403, __('A sua conta está em processo de encerramento. Só pode ver o estado, exportar dados, reativar a conta ou terminar sessão.'));
        }

        if ($this->currentOrganization->isResolved() && $this->currentOrganization->get()->isClosureRequested()) {
            abort(403, __('Esta organização está em processo de encerramento. Só é possível ver o estado, exportar dados, reativar a organização ou mudar de organização.'));
        }

        return $next($request);
    }

    protected function routeIsAllowed(Request $request): bool
    {
        $name = $request->route()?->getName();

        if ($name === null) {
            return false;
        }

        if (in_array($name, self::ALLOWED_ROUTE_NAMES, true)) {
            return true;
        }

        foreach (self::ALLOWED_ROUTE_NAME_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
