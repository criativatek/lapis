<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Support\Entitlements\Entitlements;
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
    public function __construct(protected Entitlements $entitlements) {}

    /**
     * Puts the organization on a plan, now.
     *
     * Closes whatever was in force at the same instant the new one starts, so
     * the history is continuous: every day has exactly one answer to «what was
     * this organization on?», with no gap and no overlap.
     *
     * Asking for the plan that is already in force is a no-op (§5). There is no
     * billing period to renew in this model, so a second identical subscription
     * would record nothing that the first one does not already say, and would
     * only add a row for a future reader to wonder about.
     */
    public function to(Organization $organization, Plan $plan): OrganizationSubscription
    {
        return DB::transaction(function () use ($organization, $plan): OrganizationSubscription {
            $locked = $this->lock($organization);
            $changedAt = Carbon::now();

            $subscriptions = $this->subscriptionsOf($locked);
            $inForce = $subscriptions->filter(fn (OrganizationSubscription $subscription): bool => $subscription->isInForce());

            $unchanged = $inForce->count() === 1
                && $inForce->first()->plan_id === $plan->getKey()
                && $inForce->first()->status === SubscriptionStatus::Active;

            if ($unchanged) {
                return $inForce->first();
            }

            $this->closeOpenSubscriptions($subscriptions, $changedAt);

            $created = OrganizationSubscription::withoutGlobalScope('organization')->create([
                'organization_id' => $locked->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
                'starts_at' => $changedAt,
            ]);

            $this->entitlements->flush();

            return $created;
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
     * `ends_at` is deliberately left alone. A suspension is a pause, not an end,
     * and leaving the window open is what lets reactivate() resume the same
     * subscription rather than invent a new one. Rows that were already closed
     * are not touched at all — suspending is about what is in force, never about
     * rewriting the past.
     */
    public function suspend(Organization $organization): int
    {
        return DB::transaction(function () use ($organization): int {
            $locked = $this->lock($organization);
            $suspended = 0;

            foreach ($this->subscriptionsOf($locked) as $subscription) {
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
            $this->closeOpenSubscriptions($subscriptions->reject(fn (OrganizationSubscription $s): bool => $s->is($resumable)), $now);

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
     * Ends every subscription whose window is still open.
     *
     * `ends_at` says WHEN a subscription stopped applying; `status` says how it
     * stands. So a suspended one being superseded keeps saying «suspended» — that
     * is why it stopped — and merely gains the date. Only a subscription that
     * still grants access is turned into `Expired`, and one that already carries
     * an `ends_at` is left exactly as it is.
     *
     * @param  Collection<int, OrganizationSubscription>  $subscriptions
     */
    protected function closeOpenSubscriptions($subscriptions, Carbon $closedAt): void
    {
        foreach ($subscriptions as $subscription) {
            if ($subscription->ends_at !== null) {
                continue;
            }

            $subscription->forceFill([
                'ends_at' => $closedAt,
                'status' => $subscription->status->grantsAccess()
                    ? SubscriptionStatus::Expired
                    : $subscription->status,
            ])->save();
        }
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
