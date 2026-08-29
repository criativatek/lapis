<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Trial\TrialEligibility;
use App\Support\Trial\TrialException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place an organization's subscription changes hands.
 *
 * The invariant it exists to keep: **at any instant, at most one subscription
 * of an organization is in force.** History may hold as many as the
 * organization has had plans — that is what `starts_at`/`ends_at` are for — but
 * two of them granting access at the same moment is never valid.
 *
 * Nothing enforced this before, and nothing could: the condition is temporal
 * (`ends_at > now`), MySQL has no partial unique indexes, and in a unique index
 * two NULL `ends_at` count as distinct. So the guarantee has to live in code,
 * and it only holds if every writer comes through here.
 *
 * The cost of not having it was not theoretical. Provisioning an account on a
 * non-Base plan created the Base subscription with the organization and laid the
 * chosen one on top, both Active and open-ended. `Entitlements` resolved that
 * deterministically — newest wins — so nobody was ever given the wrong plan and
 * the defect stayed invisible. Suspension is where it surfaced: suspending the
 * newest subscription let the Base one underneath quietly become effective
 * again, so an operator who suspended an institutional account had in fact
 * downgraded it to Base, while the screen told them it was suspended.
 *
 * Every transition locks the ORGANIZATION row, not the subscriptions. The
 * organization always exists, so it is a stable place to serialize on; locking
 * the subscriptions would leave the interesting case — two requests both about
 * to INSERT — with nothing in common to contend over.
 */
class ChangeOrganizationPlan
{
    public function __construct(
        protected Entitlements $entitlements,
        protected TrialEligibility $trialEligibility,
    ) {}

    /**
     * Puts the organization on a plan, now.
     *
     * Closes whatever was in force at the same instant the new one starts, so
     * the history is continuous: every day has exactly one answer to «what was
     * this organization on?», with no gap and no overlap.
     *
     * WHICH VERSION, AND WHY THE ARGUMENT ACCEPTS BOTH (ADR-0008 §6). Handed a
     * `Plan`, this sells what that plan sells TODAY — the right answer for a
     * sale being made now, and what keeps `ActivateProTrial`,
     * `CreatePersonalOrganization`, `CreateInstitutionalOrganization` and the
     * `AdminAccountController` compiling without a line changed. Handed a
     * `PlanVersion`, it uses exactly that one, which is how an operator moves a
     * single subscription deliberately.
     *
     * THE NO-OP RULE COMPARES VERSIONS, NOT PLANS, and that difference is the
     * ambiguity this method must not have. An organization on Pro **v1** that
     * an operator moves to «Pro» while **v2** is published is being MIGRATED:
     * it ends on v2, with a new row and a continuous history. Comparing
     * `plan_id` would have made that a silent no-op and left the operator
     * believing they had migrated somebody they had not. Asking for the exact
     * version already in force is still a no-op (§5): there is no billing
     * period to renew in this model, so a second identical subscription would
     * record nothing the first one does not already say, and would only add a
     * row for a future reader to wonder about.
     */
    public function to(Organization $organization, Plan|PlanVersion $target): OrganizationSubscription
    {
        return DB::transaction(function () use ($organization, $target): OrganizationSubscription {
            $locked = $this->lock($organization);
            // Resolved INSIDE the lock: «the current version» is live state
            // like any other, and a version published between the caller's own
            // read and this transaction must not be the one that lands.
            $version = $this->resolveVersion($target);
            $changedAt = Carbon::now();

            $subscriptions = $this->subscriptionsOf($locked);
            $inForce = $subscriptions->filter(fn (OrganizationSubscription $subscription): bool => $subscription->isInForce());

            $unchanged = $inForce->count() === 1
                && $inForce->first()->plan_version_id === $version->getKey()
                && $inForce->first()->status === SubscriptionStatus::Active;

            if ($unchanged) {
                return $inForce->first();
            }

            $this->supersede($subscriptions, $changedAt);

            $created = OrganizationSubscription::withoutGlobalScope('organization')->create([
                'organization_id' => $locked->getKey(),
                'plan_id' => $version->plan_id,
                'plan_version_id' => $version->getKey(),
                'status' => SubscriptionStatus::Active,
                'starts_at' => $changedAt,
            ]);

            $this->entitlements->flush();

            return $created;
        });
    }

    /**
     * Starts a voluntary, time-boxed Pro trial for the organization, now.
     *
     * Two rows are created in the same transaction, not one:
     *
     *  - The Trial itself: `$trialPlan`, `status = Trial`, `starts_at = now()`,
     *    `ends_at = now()->addDays($days)`.
     *  - A dormant Base-plan fallback: `$fallbackPlan`, `status = Active`,
     *    `starts_at` = the Trial's OWN `ends_at` (the identical instant, never
     *    recomputed), `ends_at = null`. It grants nothing today —
     *    `isInForce()` already requires `starts_at <= now()` — and simply
     *    starts mattering the moment the Trial's window closes. No job, no
     *    scheduler, nothing has to "wake up": the row sits there and
     *    `isInForce()`/`Entitlements` do the rest, with no gap and no overlap
     *    against the Trial (inclusive `starts_at`, exclusive `ends_at`, same
     *    boundary convention as everywhere else).
     *
     * Whatever was in force is superseded exactly like `to()` does — a Base
     * subscription in force is closed at `now()`, becoming `Expired`.
     *
     * EVERY eligibility condition — history AND which plan is currently in
     * force — is re-checked HERE, against the LOCKED organization, not
     * before. That is what makes two concurrent activation requests unable
     * to both succeed, and what stops a plan change landing in the gap
     * between the caller's own pre-lock reads and this call from being
     * silently superseded by a trial: whichever request gets the row lock
     * first commits; the second re-reads live state under that same lock and
     * is refused, never acting on a value read before either one had it.
     *
     *  - `TrialEligibility::usedBefore()`: a Trial-status row must never have
     *    existed before for this organization, or defensively for any other
     *    Personal organization of the same owner.
     *  - `TrialEligibility::canActivate()`: the organization must still be
     *    Personal, still owned by `$user`, and — the rule this parameter
     *    exists to enforce — currently on an eligible Base plan. Without
     *    this, an organization an operator put on Institutional (or Pro)
     *    between page load and this call would have that administrative
     *    assignment silently superseded by a Trial. `canActivate()`
     *    re-checks `usedBefore()` internally too; that duplication is
     *    harmless — one extra cheap query — and means every condition is
     *    genuinely re-evaluated here, not assumed from before the lock.
     *
     * Deliberately policy-free: `$days` arrives already resolved by the
     * caller (`App\Support\Trial\TrialPolicy`) rather than read from config
     * in here, the same way `$trialPlan`/`$fallbackPlan` arrive already
     * resolved instead of being looked up by a hardcoded plan key.
     *
     * @throws TrialException when the organization (or another Personal
     *                        organization of the same owner) has ever had a
     *                        Trial subscription before, or is not currently
     *                        eligible (wrong type, not owned by `$user`, or
     *                        not currently on an eligible Base plan).
     */
    public function startProTrial(Organization $organization, User $user, Plan|PlanVersion $trialPlan, Plan|PlanVersion $fallbackPlan, int $days): OrganizationSubscription
    {
        return DB::transaction(function () use ($organization, $user, $trialPlan, $fallbackPlan, $days): OrganizationSubscription {
            $locked = $this->lock($organization);

            if ($this->trialEligibility->usedBefore($locked)) {
                throw TrialException::alreadyUsed();
            }

            if (! $this->trialEligibility->canActivate($locked, $user)) {
                throw TrialException::notEligible();
            }

            // BOTH VERSIONS ARE FIXED HERE, AT THE START (ADR-0008 §7). The
            // trial gets the Pro on sale today, and the dormant fallback gets
            // the BASE ON SALE TODAY — not one resolved when the trial ends.
            // Two reasons: nothing has to «wake up» to resolve it, which is
            // precisely the property the dormant row exists to have; and if
            // Base changes during the trial the organization lands on the Base
            // it was promised. Moving it forward afterwards is an operator's
            // explicit `to()`, recorded like any other change.
            $trialVersion = $this->resolveVersion($trialPlan);
            $fallbackVersion = $this->resolveVersion($fallbackPlan);

            $startsAt = Carbon::now();
            $endsAt = $startsAt->copy()->addDays($days);

            $this->supersede($this->subscriptionsOf($locked), $startsAt);

            $trial = OrganizationSubscription::withoutGlobalScope('organization')->create([
                'organization_id' => $locked->getKey(),
                'plan_id' => $trialVersion->plan_id,
                'plan_version_id' => $trialVersion->getKey(),
                'status' => SubscriptionStatus::Trial,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            OrganizationSubscription::withoutGlobalScope('organization')->create([
                'organization_id' => $locked->getKey(),
                'plan_id' => $fallbackVersion->plan_id,
                'plan_version_id' => $fallbackVersion->getKey(),
                'status' => SubscriptionStatus::Active,
                'starts_at' => $endsAt,
            ]);

            $this->entitlements->flush();

            return $trial;
        });
    }

    /**
     * Takes the organization's access away.
     *
     * EVERY subscription in force is suspended, not merely the newest one. That
     * is the defensive half: whatever historical overlap a database has
     * accumulated, an operator who suspends an account must end up with an
     * account that is suspended, and not with one that quietly fell back to an
     * older plan.
     *
     * For an ordinary subscription in force, `ends_at` is deliberately left
     * alone and only `status` moves to Suspended. A suspension is a pause, not
     * an end, and leaving the window open is what lets reactivate() resume the
     * same subscription rather than invent a new one. Rows that were already
     * closed are not touched at all — suspending is about what is in force,
     * never about rewriting the past.
     *
     * Two shapes get different treatment, for the same reason `supersede()`
     * treats them differently from an ordinary Active row:
     *
     *  - A Trial in force is never relabelled away from Trial, not even by a
     *    suspension — the same exception `supersede()` already makes, because
     *    a future feature depends on `status = Trial` surviving forever as an
     *    immutable historical fact. Only its window is cut short,
     *    `ends_at = now()`, permanently — a suspended trial does not wait out
     *    the suspension and resume with days remaining; it ends, exactly like
     *    being superseded would. (Defensively, a Trial scheduled to start
     *    later — which cannot happen today, trials always start immediately —
     *    is collapsed to a zero-width window instead, the same way
     *    `supersede()`'s own scheduled bucket handles one.)
     *  - A subscription scheduled to start later that would eventually grant
     *    access (the dormant Base fallback `startProTrial()` leaves behind, or
     *    defensively anything shaped like it) is not merely skipped, because
     *    "not currently in force" is exactly what lets it silently become
     *    effective the moment its `starts_at` arrives — undoing the
     *    suspension with nobody touching anything. It is repurposed instead
     *    into the suspended placeholder: `starts_at` pulled back to now,
     *    `status` set to Suspended. That is precisely the shape
     *    `reactivate()` already knows how to find and resume, so reactivating
     *    later lands the organization back on whatever plan this row already
     *    carries — Base, for the trial-fallback case — never on a resurrected
     *    Pro and never with the trial's remaining days restored. (A future row
     *    that already grants nothing is left alone; not expected to occur
     *    today, kept only for symmetry with `supersede()`'s own generality.)
     *
     * These two cases are checked before the generic "in force" fallthrough,
     * so an in-force Trial is always caught by the Trial case and never falls
     * through to being overwritten with `status = Suspended`.
     */
    public function suspend(Organization $organization): int
    {
        return DB::transaction(function () use ($organization): int {
            $locked = $this->lock($organization);
            $now = Carbon::now();
            $suspended = 0;

            foreach ($this->subscriptionsOf($locked) as $subscription) {
                $scheduled = $subscription->starts_at->greaterThan($now);

                if ($subscription->status === SubscriptionStatus::Trial && $subscription->isInForce()) {
                    $subscription->forceFill(['ends_at' => $now])->save();
                    $suspended++;

                    continue;
                }

                if ($scheduled && $subscription->status === SubscriptionStatus::Trial) {
                    $subscription->forceFill(['ends_at' => $subscription->starts_at])->save();

                    continue;
                }

                if ($scheduled && $subscription->status->grantsAccess()) {
                    $subscription->forceFill(['starts_at' => $now, 'status' => SubscriptionStatus::Suspended])->save();
                    $suspended++;

                    continue;
                }

                if ($scheduled) {
                    continue;
                }

                if (! $subscription->isInForce()) {
                    continue;
                }

                $subscription->forceFill(['status' => SubscriptionStatus::Suspended])->save();
                $suspended++;
            }

            $this->entitlements->flush();

            return $suspended;
        });
    }

    /**
     * Resumes the subscription a suspension paused.
     *
     * The newest one that is suspended and whose window is still open — never an
     * expired one, because an expired subscription ended for a reason and
     * reviving it would put a period back into force that the history says was
     * over (§9). If reactivating would leave a second subscription in force, that
     * one is closed, so the invariant survives even a database that arrived here
     * with overlap.
     *
     * Returns null when there is nothing to resume, which the caller reports
     * rather than treating as success.
     */
    public function reactivate(Organization $organization): ?OrganizationSubscription
    {
        return DB::transaction(function () use ($organization): ?OrganizationSubscription {
            $locked = $this->lock($organization);
            $now = Carbon::now();

            $subscriptions = $this->subscriptionsOf($locked);

            $resumable = $subscriptions
                ->filter(fn (OrganizationSubscription $subscription): bool => $subscription->status === SubscriptionStatus::Suspended
                    && $subscription->starts_at->lessThanOrEqualTo($now)
                    && ($subscription->ends_at === null || $subscription->ends_at->greaterThan($now)))
                ->last();

            if ($resumable === null) {
                return null;
            }

            $resumable->forceFill(['status' => SubscriptionStatus::Active])->save();

            // Anything else that would now be in force alongside it is closed —
            // the invariant holds regardless of what the data looked like before.
            $this->supersede($subscriptions->reject(fn (OrganizationSubscription $s): bool => $s->is($resumable)), $now);

            $this->entitlements->flush();

            return $resumable;
        });
    }

    /**
     * Which subscription is in force right now, or null. The same answer
     * `Entitlements` uses, asked from one place.
     */
    public function inForce(Organization $organization): ?OrganizationSubscription
    {
        return $this->subscriptionsOf($organization)
            ->last(fn (OrganizationSubscription $subscription): bool => $subscription->isInForce());
    }

    /**
     * Supersedes whatever the organization already had, at the instant a new
     * subscription takes its place.
     *
     * Every subscription belonging to the organization falls into exactly one
     * of three shapes:
     *
     *  - Already closed: not scheduled to start later, and its own `ends_at`
     *    already fell at or before `$changedAt`. History. Left exactly as it
     *    is — not even a save() — because `RepairOverlappingSubscriptionsTest`
     *    and every reader of the past depend on it staying untouched.
     *  - Scheduled to start later (`starts_at > $changedAt`): a fallback row
     *    pre-created to take over automatically, or a trial set up ahead of
     *    time. It must never be allowed to take effect, but setting
     *    `ends_at = $changedAt` would end it before it starts. Collapsed
     *    instead to a zero-width window, `ends_at = starts_at`: no interval
     *    is ever incoherent, and `isInForce()` can never return true for it —
     *    at every instant either `starts_at <= now` still fails, or it holds
     *    and `ends_at > now` already fails.
     *  - In force right now, whether open-ended or carrying a pre-set future
     *    `ends_at` that has not arrived yet (a time-boxed trial, say): cut
     *    short at `ends_at = $changedAt`.
     *
     * `status` follows `ends_at` in what it says happened, with one
     * exception: a Trial is NEVER relabelled away from Trial here, not even
     * one being voided before it ever took effect — a future feature depends
     * on `status = Trial` surviving forever as an immutable historical fact.
     * Anything else that still grants access becomes `Expired`; a Suspended
     * row stays Suspended, because superseding is not what changed it.
     *
     * @param  Collection<int, OrganizationSubscription>  $subscriptions
     */
    protected function supersede($subscriptions, Carbon $changedAt): void
    {
        foreach ($subscriptions as $subscription) {
            $scheduled = $subscription->starts_at->greaterThan($changedAt);

            $alreadyClosed = ! $scheduled
                && $subscription->ends_at !== null
                && $subscription->ends_at->lessThanOrEqualTo($changedAt);

            if ($alreadyClosed) {
                continue;
            }

            $subscription->forceFill([
                'ends_at' => $scheduled ? $subscription->starts_at : $changedAt,
                'status' => match (true) {
                    $subscription->status === SubscriptionStatus::Trial => SubscriptionStatus::Trial,
                    $subscription->status->grantsAccess() => SubscriptionStatus::Expired,
                    default => $subscription->status,
                },
            ])->save();
        }
    }

    /**
     * A plan means «what it sells today»; a version means itself.
     *
     * The single place that rule is expressed for the write side, mirroring
     * `OrganizationSubscription`'s own `creating` guard on the model side. A
     * plan with nothing published fails loudly here rather than producing a
     * subscription entitled to nothing.
     */
    protected function resolveVersion(Plan|PlanVersion $target): PlanVersion
    {
        return $target instanceof PlanVersion ? $target : $target->currentVersionOrFail();
    }

    /**
     * @return Collection<int, OrganizationSubscription>
     */
    protected function subscriptionsOf(Organization $organization)
    {
        return OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->collect();
    }

    /**
     * Serialize on the organization itself. It always exists, so two concurrent
     * plan changes contend even when both of them are about to INSERT and there
     * is no shared subscription row to lock.
     */
    protected function lock(Organization $organization): Organization
    {
        return Organization::query()
            ->withoutGlobalScope('organization')
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
