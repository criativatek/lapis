<?php

namespace App\Support\Limits;

use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Answers "how much of this quantitative capacity may this organization use,
 * and how much has it used?" — distinct from `Entitlements`, which answers
 * "may it use this capability at all?" (§Lote 3). The two systems are never
 * mixed: a capability's entitlement state says nothing about its limit (a
 * Base organization fully `Allowed` on `classes` can still be at its 8-turma
 * cap), and this class never decides whether a module is available — it only
 * reads `plans.limits` once a caller already knows the capability itself is
 * allowed.
 *
 * The plan-of-record lookup deliberately DUPLICATES the query inside
 * `Entitlements::resolve()` (subscription `isInForce()`, newest `starts_at`
 * then newest `id` as tiebreaker) rather than extracting a shared dependency.
 * `Entitlements`'s design is closed as of Lote 2 and the owner asked that it
 * not be reopened; this small, easily-kept-in-sync duplication is the
 * conservative trade-off against a shared abstraction that would touch it.
 *
 * "Usage" is always a real count from the canonical predicate for the thing
 * being measured — `SchoolClass::scopeCountingTowardsLimit()` for turmas,
 * `Enrollment::scopeActive()` (distinct student_id) for alunos — never a
 * number invented or cached here. `usage > limit` is a valid, permanent
 * state (a Pro organization with 12 turmas that downgrades to Base keeps all
 * 12; it is merely blocked from a 13th until it drops to 7) — this class
 * never deletes, archives or otherwise reacts to being over a limit.
 */
class Limits
{
    public function __construct(protected CurrentOrganization $currentOrganization) {}

    public function limitFor(Organization $organization, LimitKey $key): LimitValue
    {
        $plan = $this->planInForce($organization);

        // No subscription in force at all grants nothing — the same
        // conservative reading Entitlements gives an unsubscribed
        // organization (Locked, i.e. nothing). In practice every
        // organization is created with a subscription (CreatePersonalOrganization),
        // so this is a defensive floor, not a state real traffic lives in.
        if ($plan === null) {
            return LimitValue::finite(0);
        }

        return $this->parse($plan, $key);
    }

    public function limit(LimitKey $key): LimitValue
    {
        return $this->limitFor($this->currentOrganization->get(), $key);
    }

    /**
     * The real, current count towards $key — distinct student_id for
     * ActiveStudents, so a student on two active turmas is one unit, never
     * two (§Lote 3).
     */
    public function usageFor(Organization $organization, LimitKey $key): int
    {
        return match ($key) {
            LimitKey::ActiveClasses => SchoolClass::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organization->getKey())
                ->countingTowardsLimit()
                ->count(),

            LimitKey::ActiveStudents => Enrollment::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organization->getKey())
                ->active()
                ->distinct()
                ->count('student_id'),
        };
    }

    public function usage(LimitKey $key): int
    {
        return $this->usageFor($this->currentOrganization->get(), $key);
    }

    /**
     * What is left before hitting the limit. Unlimited stays `unlimited()`
     * — never a computed huge finite number — and a finite value is never
     * negative: an organization already over its limit has zero remaining,
     * not a negative one (§Lote 3).
     */
    public function remainingFor(Organization $organization, LimitKey $key): LimitValue
    {
        return $this->limitFor($organization, $key)->remainingAfter($this->usageFor($organization, $key));
    }

    public function remaining(LimitKey $key): LimitValue
    {
        return $this->remainingFor($this->currentOrganization->get(), $key);
    }

    public function canIncreaseFor(Organization $organization, LimitKey $key, int $by = 1): bool
    {
        return $this->limitFor($organization, $key)->accommodates($this->usageFor($organization, $key), $by);
    }

    public function canIncrease(LimitKey $key, int $by = 1): bool
    {
        return $this->canIncreaseFor($this->currentOrganization->get(), $key, $by);
    }

    /**
     * Guards a write that is about to grow usage of $key by $by. Callers are
     * expected to have already row-locked the Organization inside the same
     * DB::transaction() before calling this (see ClassService::create() and
     * StudentEnrollmentService), so the read here and the write right after
     * it are serialized against a concurrent request on the same
     * organization — no separate lock table needed.
     *
     * @throws ValidationException in the project's established convention
     *                             for a business rule violated inside a
     *                             Service/Action (never a bare 403) — pt-PT,
     *                             with the real configured limit, never a
     *                             number written into the string.
     */
    public function assertCanIncreaseFor(Organization $organization, LimitKey $key, int $by = 1): void
    {
        if ($this->canIncreaseFor($organization, $key, $by)) {
            return;
        }

        $limit = $this->limitFor($organization, $key);

        throw ValidationException::withMessages([
            'limit' => $this->limitReachedMessage($key, $limit),
        ]);
    }

    public function assertCanIncrease(LimitKey $key, int $by = 1): void
    {
        $this->assertCanIncreaseFor($this->currentOrganization->get(), $key, $by);
    }

    protected function limitReachedMessage(LimitKey $key, LimitValue $limit): string
    {
        // $limit is always finite here: an unlimited value never fails
        // canIncreaseFor(), so this branch is only ever reached with a real
        // configured number to quote back to the teacher.
        return match ($key) {
            LimitKey::ActiveClasses => __(
                'Atingiu o limite de :limit turmas ativas do seu plano. Os dados existentes são mantidos — para criar outra, reduza primeiro o número de turmas ativas.',
                ['limit' => $limit->value()],
            ),
            LimitKey::ActiveStudents => __(
                'Atingiu o limite de :limit alunos ativos do seu plano. Os dados existentes são mantidos — para inscrever outro, reduza primeiro o número de alunos ativos.',
                ['limit' => $limit->value()],
            ),
        };
    }

    /**
     * The plan of the subscription currently in force, or null when none is
     * — the same subscription `Entitlements::resolve()` would pick (newest
     * `starts_at`, then newest `id`), read again here rather than shared
     * (see the class doc above for why).
     */
    protected function planInForce(Organization $organization): ?Plan
    {
        $inForce = OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with('plan')
            ->latest('starts_at')
            ->latest('id')
            ->get()
            ->first(fn (OrganizationSubscription $subscription): bool => $subscription->isInForce());

        return $inForce?->plan;
    }

    /**
     * Reads one key out of a plan's `limits` JSON (§Lote 3 format: a
     * non-negative integer, or the literal string "unlimited" — never
     * `null` meaning unlimited). A key the catalogue knows about but the
     * plan's stored JSON does not is a configuration error and fails loudly
     * rather than silently defaulting to 0 or unlimited.
     */
    protected function parse(Plan $plan, LimitKey $key): LimitValue
    {
        $limits = $plan->limits ?? [];

        if (! array_key_exists($key->value, $limits)) {
            throw new RuntimeException(
                "Plan [{$plan->key}] has no configured limit for [{$key->value}] in plans.limits.",
            );
        }

        $raw = $limits[$key->value];

        if ($raw === 'unlimited') {
            return LimitValue::unlimited();
        }

        if (is_int($raw) && $raw >= 0) {
            return LimitValue::finite($raw);
        }

        throw new RuntimeException(
            "Plan [{$plan->key}] has an invalid limit for [{$key->value}]: expected a non-negative integer or the string \"unlimited\", got ".json_encode($raw).'.',
        );
    }
}
