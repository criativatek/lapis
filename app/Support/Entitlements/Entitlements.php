<?php

namespace App\Support\Entitlements;

use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Answers "may this organization use this module?".
 *
 * The answer is the plan's modules, plus per-organization overrides on top.
 * Every check runs here, on the server (§8.2): hiding a menu item in the UI is
 * presentation, not access control.
 */
class Entitlements
{
    /** @var array<int, list<string>> Resolved module keys, per organization, for this request. */
    protected array $resolved = [];

    public function __construct(protected CurrentOrganization $currentOrganization) {}

    public function allows(string $moduleKey): bool
    {
        if (! $this->currentOrganization->isResolved()) {
            return false;
        }

        return $this->allowsFor($this->currentOrganization->get(), $moduleKey);
    }

    public function allowsFor(Organization $organization, string $moduleKey): bool
    {
        return in_array($moduleKey, $this->modulesFor($organization), strict: true);
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        if (! $this->currentOrganization->isResolved()) {
            return [];
        }

        return $this->modulesFor($this->currentOrganization->get());
    }

    /**
     * @return list<string>
     */
    public function modulesFor(Organization $organization): array
    {
        return $this->resolved[$organization->getKey()] ??= $this->resolve($organization);
    }

    /**
     * Drop any memoized answer. Needed after changing a plan or an override
     * inside a single request or test.
     */
    public function flush(): void
    {
        $this->resolved = [];
    }

    /**
     * @return list<string>
     */
    protected function resolve(Organization $organization): array
    {
        $subscription = OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('plan.modules')
            ->latest('starts_at')
            ->get()
            ->first(fn (OrganizationSubscription $subscription) => $subscription->isInForce());

        $modules = $subscription === null
            ? []
            : $subscription->plan->modules->pluck('key')->all();

        $overrides = OrganizationModuleOverride::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('module')
            ->get()
            ->filter(fn (OrganizationModuleOverride $override) => $override->isInForce());

        foreach ($overrides as $override) {
            $key = $override->module->key;

            $modules = $override->enabled
                ? [...$modules, $key]
                : array_filter($modules, fn (string $module) => $module !== $key);
        }

        return array_values(array_unique($modules));
    }
}
