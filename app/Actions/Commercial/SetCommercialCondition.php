<?php

namespace App\Actions\Commercial;

use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Commercial\CommercialConditionException;
use App\Support\Commercial\SubscriptionCondition;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Marks WHY a subscription exists, without touching WHAT it grants.
 *
 * This is the only way an existing account can ever become identifiable as a
 * Membro Fundador, because nothing in the database has ever recorded it and §12
 * of the brief forbids inferring it from an amount paid. So an operator who
 * knows says so, explicitly, and the trail records who said it and when.
 *
 * IT NEVER TOUCHES `plan_id`, `status`, `starts_at` OR `ends_at`. Nothing here
 * goes near `ChangeOrganizationPlan`, no subscription is superseded, no new row
 * is created, and `Entitlements` is not even flushed — because nothing this
 * writes can change an access decision. Marking an account as Fundador gives it
 * nothing; it is Pro because it is on Pro.
 *
 * A TRIAL CANNOT BE MARKED. `status = Trial` already states the condition, and
 * writing a different one into the column would leave the subscription claiming
 * two contradictory things at once.
 */
class SetCommercialCondition
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    /**
     * @param  CommercialCondition|null  $condition  Null clears it back to "origem não registada".
     *
     * @throws CommercialConditionException
     */
    public function set(
        OrganizationSubscription $subscription,
        Organization $organization,
        User $operator,
        ?CommercialCondition $condition,
        ?string $note = null,
    ): OrganizationSubscription {
        return DB::transaction(function () use ($subscription, $organization, $operator, $condition, $note): OrganizationSubscription {
            $locked = OrganizationSubscription::query()
                ->withoutGlobalScope('organization')
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Re-checked under the lock, not before: a trial could have been
            // started between the page load and this call.
            if ($locked->status === SubscriptionStatus::Trial) {
                throw CommercialConditionException::trialIsDerived();
            }

            if ($locked->organization_id !== $organization->getKey()) {
                throw CommercialConditionException::wrongOrganization();
            }

            $previous = $locked->commercial_condition;
            $note = $note === null ? null : (trim($note) === '' ? null : trim($note));

            $locked->forceFill([
                'commercial_condition' => $condition,
                'commercial_condition_note' => $note,
            ])->save();

            $this->currentOrganization->runFor($organization, fn () => $this->audit->record(
                'commercial.condition_set',
                $organization,
                $operator,
                summary: sprintf(
                    'Condição comercial: %s passou a %s.',
                    $previous?->label() ?? SubscriptionCondition::labelFor(SubscriptionCondition::UNKNOWN),
                    $condition?->label() ?? SubscriptionCondition::labelFor(SubscriptionCondition::UNKNOWN),
                ),
                properties: [
                    'organization_subscription_id' => $locked->getKey(),
                    'previous_condition' => $previous?->value,
                    'condition' => $condition?->value,
                    'note' => $note,
                    // Recorded so the trail proves the plan did NOT move — the
                    // whole guarantee of this action, stated in the evidence
                    // rather than only in a docblock.
                    'plan_id' => $locked->plan_id,
                ],
            ));

            return $locked;
        });
    }
}
