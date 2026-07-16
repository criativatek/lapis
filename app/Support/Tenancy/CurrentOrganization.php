<?php

namespace App\Support\Tenancy;

use App\Models\Organization;

/**
 * Holds the organization the current context is acting for.
 *
 * Bound as a container singleton rather than read from the session, so that
 * HTTP requests, queued jobs, console commands and tests all resolve the
 * tenant the same way. Reading the tenant from the session would make the
 * scope silently vanish outside HTTP, which is what forces a codebase into
 * scattering withoutGlobalScopes() calls to make jobs work at all.
 */
class CurrentOrganization
{
    protected ?Organization $organization = null;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function forget(): void
    {
        $this->organization = null;
    }

    public function isResolved(): bool
    {
        return $this->organization instanceof Organization;
    }

    /**
     * @throws TenantNotResolvedException when no organization is resolved.
     */
    public function get(): Organization
    {
        return $this->organization ?? throw TenantNotResolvedException::make();
    }

    /**
     * @throws TenantNotResolvedException when no organization is resolved.
     */
    public function id(): int
    {
        return $this->get()->getKey();
    }

    /**
     * Run a callback with the given organization resolved, then restore whatever
     * was resolved before. This is the supported way for jobs, commands and
     * seeders to enter a tenant — they pass organization_id explicitly and wrap
     * their work, instead of disabling the scope.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Organization $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
