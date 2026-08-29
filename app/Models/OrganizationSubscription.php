<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * What an organization is entitled to, and for how long.
 *
 * `plan_version_id` is WHAT — the exact offer this organization contracted,
 * frozen (ADR-0008). `plan_id` is the same answer at the commercial altitude
 * («Pro»), kept because the backoffice filters on it, the CSV exports it and
 * the mails read `plan->name`. The two can never disagree: the database holds a
 * COMPOSITE foreign key on `(plan_version_id, plan_id)` into
 * `plan_versions (id, plan_id)`, and the `creating` guard below fills whichever
 * one a writer left out, so no caller has to keep them in step by hand.
 *
 * `commercial_condition` is on what TERMS. It never influences the first, and
 * `App\Support\Entitlements\Entitlements` never reads it — a Membro Fundador is
 * `plan = pro` with `commercial_condition = founder`, entitled to exactly what a
 * standard Pro is. A NULL condition is «origem não registada», not a default:
 * see the 2026_09_08_000100 migration for why no existing row was backfilled.
 *
 * THE FOUR SNAPSHOT COLUMNS ARE COMMERCIAL PROOF, NOT ENTITLEMENT. What was
 * agreed — the price, its currency, the periodicity, and until when the
 * condition holds — is written once and then immutable, the same way
 * `SubscriptionPayment` refuses to have its money rewritten. Nothing in
 * `Entitlements` or in `isInForce()` reads any of them, and
 * `commercial_term_ends_at` in particular is NOT `ends_at`: the term is until
 * when the CONDITION holds, `ends_at` is until when the ACCESS runs. A free Base
 * whose promotional term expires in August 2027 does not lose access in August
 * 2027 — it loses the price it had.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $plan_id
 * @property int $plan_version_id
 * @property SubscriptionStatus $status
 * @property CommercialCondition|null $commercial_condition
 * @property string|null $commercial_condition_note
 * @property int|null $contracted_price_cents
 * @property string|null $contracted_currency
 * @property BillingPeriod|null $billing_period
 * @property Carbon|null $commercial_term_ends_at
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 */
#[Fillable([
    'organization_id', 'plan_id', 'plan_version_id', 'status',
    'commercial_condition', 'commercial_condition_note',
    'contracted_price_cents', 'contracted_currency', 'billing_period', 'commercial_term_ends_at',
    'starts_at', 'ends_at',
])]
class OrganizationSubscription extends Model
{
    use BelongsToOrganization;

    /**
     * Written once, at creation, and history afterwards.
     *
     * Narrower than `SubscriptionPayment`'s guard on purpose: only these four
     * are commercial proof. `starts_at`, `ends_at` and `status` move constantly
     * — `ChangeOrganizationPlan` does `forceFill()->save()` on exactly those
     * three when it supersedes, suspends and reactivates — and this guard is
     * written not to collide with any of it.
     *
     * @var list<string>
     */
    protected const IMMUTABLE_COMMERCIAL_SNAPSHOT = [
        'contracted_price_cents', 'contracted_currency', 'billing_period', 'commercial_term_ends_at',
    ];

    protected static function booted(): void
    {
        // The pair (plan_id, plan_version_id) is resolved and checked in ONE
        // place, at the boundary, so that no writer anywhere can produce a
        // subscription without a contracted version — §18 of the brief — and
        // none can produce one whose two columns name different plans.
        static::creating(function (self $subscription): void {
            $subscription->reconcileOnCreate();
        });

        static::updating(function (self $subscription): void {
            $forbidden = array_values(array_intersect(
                array_keys($subscription->getDirty()),
                self::IMMUTABLE_COMMERCIAL_SNAPSHOT,
            ));

            if ($forbidden !== []) {
                throw new LogicException(
                    'The contracted commercial condition of a subscription is immutable: refused change to '
                    .implode(', ', $forbidden).'. Record the new condition on a new subscription instead.'
                );
            }

            $subscription->reconcileOnUpdate();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'commercial_condition' => CommercialCondition::class,
            'contracted_price_cents' => 'integer',
            'billing_period' => BillingPeriod::class,
            'commercial_term_ends_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The offer this organization actually contracted — the ONLY source of its
     * modules and its limits, today and in every historical reading of what it
     * could do back then.
     *
     * @return BelongsTo<PlanVersion, $this>
     */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /**
     * Payments recorded against THIS subscription period specifically.
     *
     * Not every payment of the organization: a payment whose subscription was
     * later superseded keeps pointing at the period it actually bought, and one
     * recorded without a period at all points at nothing. The account-level
     * total is a query on `organization_id`, not this relation.
     *
     * @return HasMany<SubscriptionPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'organization_subscription_id');
    }

    /**
     * In force right now: a status that grants access, and inside its date window.
     *
     * DELIBERATELY BLIND TO `commercial_term_ends_at`. Reading the commercial
     * term here would silently turn «the promotional price ended» into «the
     * account lost access» — a much larger decision, deliberately not taken by
     * ADR-0008.
     */
    public function isInForce(): bool
    {
        if (! $this->status->grantsAccess()) {
            return false;
        }

        $now = Carbon::now();

        return $this->starts_at->lessThanOrEqualTo($now)
            && ($this->ends_at === null || $this->ends_at->greaterThan($now));
    }

    /**
     * Fills in whichever half of the (plan, version) pair the writer left out,
     * and refuses the pair outright when the two disagree.
     *
     * A writer that names only a PLAN is buying what that plan sells today —
     * the same rule `ChangeOrganizationPlan::to(Plan)` applies, expressed once
     * here so a subscription without a contracted version is not merely
     * discouraged but unconstructible. A writer that names only a VERSION gets
     * its plan, which is the one answer that can never be wrong.
     */
    protected function reconcileOnCreate(): void
    {
        // Read from the raw attribute bag, not through the typed properties:
        // BEFORE the insert either column may legitimately be absent, which is
        // the whole reason this method exists, while the declared types
        // describe the row once it is stored.
        $planId = $this->attributes['plan_id'] ?? null;
        $versionId = $this->attributes['plan_version_id'] ?? null;

        if ($versionId === null && $planId === null) {
            throw new LogicException('A subscription needs a plan or a plan version; neither was given.');
        }

        if ($versionId === null) {
            $this->plan_version_id = $this->currentVersionIdOf((int) $planId);

            return;
        }

        if ($planId === null) {
            $this->plan_id = PlanVersion::query()->findOrFail((int) $versionId)->plan_id;

            return;
        }

        $this->assertPairAgrees();
    }

    /**
     * The same rule, applied to whichever half an update touched.
     *
     * Moving a subscription's `plan_id` alone moves it to what that plan sells
     * today; moving its `plan_version_id` alone brings `plan_id` along.
     * Neither is a convenience: the pair is one fact in two columns, and
     * letting an update change one of them without the other would produce a
     * row the composite foreign key refuses anyway — with a far less
     * intelligible error.
     */
    protected function reconcileOnUpdate(): void
    {
        $planChanged = $this->isDirty('plan_id');
        $versionChanged = $this->isDirty('plan_version_id');

        if ($planChanged && ! $versionChanged) {
            $this->plan_version_id = $this->currentVersionIdOf($this->plan_id);

            return;
        }

        if ($versionChanged && ! $planChanged) {
            $this->plan_id = PlanVersion::query()->findOrFail($this->plan_version_id)->plan_id;

            return;
        }

        if ($planChanged) {
            $this->assertPairAgrees();
        }
    }

    protected function currentVersionIdOf(int $planId): int
    {
        return Plan::query()->findOrFail($planId)->currentVersionOrFail()->getKey();
    }

    /**
     * Duplicates the composite foreign key on purpose: the database is the
     * guarantee, this is the readable error. SQLite — the test engine —
     * enforces the same key after its table rebuild, so both are exercised.
     */
    protected function assertPairAgrees(): void
    {
        $version = PlanVersion::query()->findOrFail($this->plan_version_id);

        if ($this->plan_id !== $version->plan_id) {
            throw new LogicException(
                "Subscription plan_id [{$this->plan_id}] disagrees with plan_version_id [{$this->plan_version_id}], "
                ."which belongs to plan [{$version->plan_id}]."
            );
        }
    }
}
