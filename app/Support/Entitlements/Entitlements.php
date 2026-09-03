<?php

namespace App\Support\Entitlements;

use App\Models\CapabilityGrant;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionStatus;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Answers "may this organization use this module, and how much?".
 *
 * The answer is the modules of the plan VERSION the organization contracted
 * (ADR-0008 — never the composition that plan carries today), plus
 * per-organization overrides on top — resolved into one of three states
 * (`AccessState`) rather than a bare boolean, so a temporarily-suspended
 * organization can be told apart from one that never had the module at all
 * (§Lote 2). `allows()`/`modules()` are the
 * original boolean primitive, kept byte-for-byte compatible and now
 * implemented on top of the state resolution below, not duplicating it.
 *
 * Every check runs here, on the server (§8.2): hiding a menu item in the UI is
 * presentation, not access control.
 */
class Entitlements
{
    /** @var array<int, array<string, AccessState>> Resolved access state per module key, per organization, for this request. */
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
        return $this->accessStateFor($organization, $moduleKey) === AccessState::Allowed;
    }

    public function accessState(string $moduleKey): AccessState
    {
        if (! $this->currentOrganization->isResolved()) {
            return AccessState::Locked;
        }

        return $this->accessStateFor($this->currentOrganization->get(), $moduleKey);
    }

    public function accessStateFor(Organization $organization, string $moduleKey): AccessState
    {
        return $this->accessStatesFor($organization)[$moduleKey] ?? AccessState::Locked;
    }

    /**
     * @return array<string, AccessState>
     */
    public function accessStates(): array
    {
        if (! $this->currentOrganization->isResolved()) {
            return [];
        }

        return $this->accessStatesFor($this->currentOrganization->get());
    }

    /**
     * The full access-state map for this organization, computed once and
     * reused by every other method below — never one subscription/override
     * query per module key asked about.
     *
     * A key absent from the map is `Locked`: only keys the base subscription
     * grants (`Allowed`/`ReadOnly`) or an override touches are ever stored, so
     * this is not literally every catalogued key, but it answers exactly as
     * if it were — `accessStateFor()` falls back to `Locked` for anything
     * missing, which is the correct answer for a key nothing currently grants.
     *
     * @return array<string, AccessState>
     */
    public function accessStatesFor(Organization $organization): array
    {
        return $this->resolved[$organization->getKey()] ??= $this->resolve($organization);
    }

    public function canRead(string $moduleKey): bool
    {
        if (! $this->currentOrganization->isResolved()) {
            return false;
        }

        return $this->canReadFor($this->currentOrganization->get(), $moduleKey);
    }

    public function canReadFor(Organization $organization, string $moduleKey): bool
    {
        return $this->accessStateFor($organization, $moduleKey)->permitsRead();
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
     * The module keys currently `Allowed` — the exact set `allowsFor()` would
     * say yes to, one at a time. Identical output to the pre-Lote-2 boolean
     * resolver for every scenario that existed before it: a `ReadOnly` module
     * (new in this Lote) is deliberately excluded here, the same as `Locked`.
     *
     * @return list<string>
     */
    public function modulesFor(Organization $organization): array
    {
        return array_keys(array_filter(
            $this->accessStatesFor($organization),
            fn (AccessState $state): bool => $state === AccessState::Allowed,
        ));
    }

    /**
     * @return list<string>
     */
    public function readOnlyModules(): array
    {
        if (! $this->currentOrganization->isResolved()) {
            return [];
        }

        return $this->readOnlyModulesFor($this->currentOrganization->get());
    }

    /**
     * @return list<string>
     */
    public function readOnlyModulesFor(Organization $organization): array
    {
        return array_keys(array_filter(
            $this->accessStatesFor($organization),
            fn (AccessState $state): bool => $state === AccessState::ReadOnly,
        ));
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
     * Rule 4 of `resolve()`: what a plan change leaves consultable.
     *
     * The organization's own history is the only source — no column, no flag
     * and no migration. Every subscription that ever TOOK EFFECT (`starts_at`
     * at or before now, whatever its status became afterwards) is read for the
     * capabilities ITS OWN CONTRACTED VERSION granted; a capability that
     * appears there, is not granted by whatever is in force today, and is
     * named by `RetainedOnDowngrade`, becomes `ReadOnly`.
     *
     * THE VERSION, NEVER THE PLAN — this is the method ADR-0008 was written
     * around. Reading `$subscription->plan->modules` answered «what could this
     * teacher do when they wrote this?» with the composition that plan has
     * TODAY, so re-running the seeder silently rewrote the past. A historical
     * subscription now names the historical offer, and publishing Pro v2
     * cannot reach an organization that only ever had v1.
     *
     * Rows SCHEDULED to start later are skipped, and that exclusion is
     * load-bearing rather than tidy: `ChangeOrganizationPlan::startProTrial()`
     * pre-creates a dormant Base row dated at the trial's end, and reading it
     * as history would describe a plan the organization has not been on yet.
     * Once the trial does end, the Trial row itself is history like any other,
     * so a teacher who wrote sumários during a trial can still open them —
     * which is exactly the promise «downgrade não destrutivo» makes.
     *
     * Never overwrites an entry already decided: a key the current plan
     * grants stays `Allowed`. Overrides are applied after this returns and
     * therefore still win, in both directions — an `enabled = false` override
     * locks a key this would have made `ReadOnly`.
     *
     * @param  array<string, AccessState>  $states
     * @param  Collection<int, OrganizationSubscription>  $subscriptions
     */
    protected function retainReadOnlyAfterDowngrade(array &$states, $subscriptions, OrganizationSubscription $inForce): void
    {
        $now = Carbon::now();

        foreach ($subscriptions as $subscription) {
            if ($subscription->is($inForce) || $subscription->starts_at->greaterThan($now)) {
                continue;
            }

            foreach ($subscription->planVersion->modules as $module) {
                if (isset($states[$module->key]) || ! RetainedOnDowngrade::includes($module->key)) {
                    continue;
                }

                $states[$module->key] = AccessState::ReadOnly;
            }
        }
    }

    /**
     * The state-resolution algorithm (§Lote 2):
     *
     * 1. The subscription currently `isInForce()`, if any, sets every one of
     *    the modules of ITS OWN CONTRACTED VERSION to `Allowed`.
     * 2. Otherwise, if the MOST RECENT subscription overall (same ordering as
     *    below — newest `starts_at`, then newest `id`) is specifically
     *    `Suspended`, every one of the modules of ITS OWN CONTRACTED VERSION
     *    is `ReadOnly` instead — a suspension is a pause, not a removal, so
     *    what was there stays visible.
     * 3. Otherwise — no subscription at all, the most recent one is
     *    `Expired`, or a grants-access status (`Active`/`Trial`) whose date
     *    window simply lapsed without anyone explicitly suspending it —
     *    nothing is granted at all. This is deliberately identical to
     *    pre-Lote-2 behaviour: only an EXPLICIT `suspend()` produces
     *    `ReadOnly`; a naturally time-lapsed subscription is exactly as
     *    locked as it always was (see the Lote 2 report's open questions).
     * 4. WHEN — AND ONLY WHEN — SOMETHING IS IN FORCE (case 1), a capability
     *    the organization USED TO HOLD, no longer holds, and that
     *    `RetainedOnDowngrade` names, resolves to `ReadOnly` rather than
     *    falling through to `Locked`. This is §19 of the Matriz Mestre —
     *    «alguns workflows Pro com dados históricos podem ficar read_only» —
     *    and §15's literal Pro → Base example, which expects
     *    `lessons_workspace` and `teacher_timetable` to be consultable
     *    after the change rather than gone. It is scoped to case 1 on
     *    purpose: a subscription that simply lapsed leaving NOTHING in force
     *    is not a downgrade, it is an account with no plan, and it keeps the
     *    behaviour it always had. `RetainedOnDowngrade` is where the list of
     *    keys and the reason for each lives.
     *
     * Overrides are then applied on top, independently per module key: an
     * `enabled=false` override forces `Locked` regardless of the base state
     * (even downgrading a base `ReadOnly`); an `enabled=true` override forces
     * `Allowed` regardless of the base state (even upgrading a base `Locked`
     * or `ReadOnly`). Both are exactly today's pre-existing override
     * behaviour, made explicit under the three-state model rather than
     * changed by it — see the Lote 2 report for why this conservative choice
     * was kept rather than revisited.
     *
     * @return array<string, AccessState>
     */
    protected function resolve(Organization $organization): array
    {
        $subscriptions = OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('planVersion.modules')
            // id as tiebreaker: two subscriptions with the same starts_at (a plan
            // changed the same second it was created) must resolve deterministically
            // to the newest one, not an arbitrary row.
            ->latest('starts_at')
            ->latest('id')
            ->get();

        $inForce = $subscriptions->first(fn (OrganizationSubscription $subscription) => $subscription->isInForce());

        $states = [];

        if ($inForce !== null) {
            foreach ($inForce->planVersion->modules as $module) {
                $states[$module->key] = AccessState::Allowed;
            }

            $this->retainReadOnlyAfterDowngrade($states, $subscriptions, $inForce);
        } else {
            // Newest overall, thanks to the same ordering the query above
            // already applied.
            $mostRecent = $subscriptions->first();

            if ($mostRecent !== null && $mostRecent->status === SubscriptionStatus::Suspended) {
                foreach ($mostRecent->planVersion->modules as $module) {
                    $states[$module->key] = AccessState::ReadOnly;
                }
            }
        }

        $overrides = OrganizationModuleOverride::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('module')
            ->get()
            ->filter(fn (OrganizationModuleOverride $override) => $override->isInForce());

        foreach ($overrides as $override) {
            $states[$override->module->key] = $override->enabled ? AccessState::Allowed : AccessState::Locked;
        }

        // Temporary grants are the final additive layer. They can only upgrade
        // to Allowed, and an explicit in-force disabled override remains a hard
        // lock even here. Overlapping grants need no precedence: existence of
        // one active row is sufficient.
        $explicitlyLockedModuleIds = $overrides
            ->filter(fn (OrganizationModuleOverride $override): bool => ! $override->enabled)
            ->pluck('module_id')
            ->all();

        $grants = CapabilityGrant::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', Carbon::now())
            ->where('expires_at', '>', Carbon::now())
            ->with('modules')
            ->get();

        foreach ($grants as $grant) {
            foreach ($grant->modules as $module) {
                if (! in_array($module->getKey(), $explicitlyLockedModuleIds, true)) {
                    $states[$module->key] = AccessState::Allowed;
                }
            }
        }

        return $states;
    }
}
