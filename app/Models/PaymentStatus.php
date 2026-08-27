<?php

namespace App\Models;

/**
 * What happened to a payment.
 *
 * `countsAsRevenue()` is the single authority behind every euro the backoffice
 * ever shows. It is a `match` over every case with no default arm, so adding a
 * state to this enum without deciding whether it is revenue is a compile-time
 * error, not a silently wrong total.
 */
enum PaymentStatus: string
{
    /** Announced, not received. Never revenue. */
    case Pending = 'pending';

    /** The money arrived. The only case that is revenue. */
    case Paid = 'paid';

    /** The attempt did not go through. Never revenue. */
    case Failed = 'failed';

    /** Refunded in full — the money went back. Leaves net revenue, entire. */
    case Refunded = 'refunded';

    /** Recorded in error, or called off before it happened. Never revenue. */
    case Cancelled = 'cancelled';

    /**
     * THE revenue rule. Only money that arrived and stayed.
     *
     * `Refunded` is false because this slice supports total refunds only: the
     * whole payment left, so the whole payment leaves the total. A partial
     * refund cannot be expressed here and is deliberately not faked — it needs
     * a refunded amount alongside the original, which this schema does not
     * carry.
     */
    public function countsAsRevenue(): bool
    {
        return match ($this) {
            self::Paid => true,
            self::Pending, self::Failed, self::Refunded, self::Cancelled => false,
        };
    }

    /**
     * Whether this is an end state a correction may still move away from.
     * A payment already refunded or cancelled is finished being corrected —
     * correcting a correction would be editing history through the back door.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Refunded, self::Cancelled => true,
            self::Pending, self::Paid, self::Failed => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pendente'),
            self::Paid => __('Pago'),
            self::Failed => __('Falhado'),
            self::Refunded => __('Reembolsado'),
            self::Cancelled => __('Anulado'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
