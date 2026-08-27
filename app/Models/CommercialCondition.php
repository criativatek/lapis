<?php

namespace App\Models;

/**
 * On what terms an organization got the plan it has.
 *
 * NOT A PLAN, and never a substitute for one. `plan_id` answers what the
 * organization may use; this answers why it is entitled to it. The pair
 * `plan = pro, commercial_condition = founder` is a Membro Fundador — there is
 * no "Fundador" plan, and there must never be one, because a Fundador and a
 * standard Pro are entitled to byte-for-byte the same modules. Nothing in
 * `App\Support\Entitlements\Entitlements` reads this enum, by design.
 *
 * ABSENCE IS NOT A CASE HERE. A subscription whose condition nobody recorded
 * stores NULL, presented as "Origem não registada" — see
 * `App\Support\Commercial\SubscriptionCondition::labelFor()`. That is deliberately
 * different from `Other`, which means an operator looked at the account and
 * decided none of the named conditions fits. Unknown and other-but-known are
 * not the same fact and must not collapse into one another.
 *
 * `Trial` is missing from this enum on purpose: it is not stored in the column
 * at all. `organization_subscriptions.status = SubscriptionStatus::Trial`
 * already records it as an immutable historical fact, and duplicating it here
 * would create two places to disagree. Callers that need "trial" as a
 * commercial condition derive it from the status — see
 * `App\Support\Commercial\SubscriptionCondition`.
 */
enum CommercialCondition: string
{
    /** Pro at list price. */
    case Standard = 'standard';

    /** The launch condition on Pro — first 250 or until 31/12/2026. */
    case Founder = 'founder';

    /** A code was presented. No voucher backend exists; the code is a literal string. */
    case Voucher = 'voucher';

    /** An operator granted the plan directly, with no money involved. */
    case AdminGrant = 'admin_grant';

    /** A school's arrangement, priced under consultation and never automatically. */
    case Institutional = 'institutional';

    /** Predates the commercial model, and is recorded as such rather than guessed at. */
    case Legacy = 'legacy';

    /** Genuinely none of the above, decided by a person who looked. */
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Standard => __('Standard'),
            self::Founder => __('Membro Fundador'),
            self::Voucher => __('Voucher'),
            self::AdminGrant => __('Concessão administrativa'),
            self::Institutional => __('Institucional'),
            self::Legacy => __('Anterior ao modelo comercial'),
            self::Other => __('Outra'),
        };
    }

    /**
     * Whether a payment would normally be expected under this condition. Used
     * only to phrase the backoffice honestly — never to infer that one exists,
     * and never to invent an amount.
     */
    public function normallyPaid(): bool
    {
        return match ($this) {
            self::Standard, self::Founder, self::Institutional => true,
            self::Voucher, self::AdminGrant, self::Legacy, self::Other => false,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $condition): array => ['value' => $condition->value, 'label' => $condition->label()],
            self::cases(),
        );
    }
}
