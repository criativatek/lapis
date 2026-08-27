<?php

namespace App\Support\Commercial;

use App\Models\SubscriptionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What the operator asked to see. Built once from the request and handed to
 * both the listing and the CSV export, so an export can never disagree with the
 * table it was exported from.
 *
 * THERE IS NO SEPARATE "trial" FILTER, deliberately. A trial IS a commercial
 * condition — `SubscriptionCondition::keyOf()` derives it from the status — so
 * it appears in the condition list alongside Fundador and voucher. A second
 * checkbox that meant the same thing would be a filter without practical use
 * (§11) and a second place for the two to drift apart.
 *
 * `from`/`to` do two clearly-labelled things at once: they bound the revenue
 * "no período" figure, and they bound the listing by when each subscription
 * STARTED. One control, because two date ranges on one screen is how an
 * operator ends up reading a total that does not match the rows under it.
 */
final class CommercialFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly ?string $planKey = null,
        public readonly ?string $condition = null,
        public readonly ?SubscriptionStatus $status = null,
        /** 'paid' | 'unpaid' | null */
        public readonly ?string $payment = null,
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query('search', '')),
            planKey: self::nullableString($request->query('plan')),
            condition: self::nullableString($request->query('condition')),
            status: SubscriptionStatus::tryFrom((string) $request->query('status', '')),
            payment: in_array($request->query('payment'), ['paid', 'unpaid'], true)
                ? (string) $request->query('payment')
                : null,
            from: self::date($request->query('from')),
            // End of day, so "to = 31/12" includes everything that happened on
            // the 31st rather than only the midnight instant.
            to: self::date($request->query('to'))?->endOfDay(),
        );
    }

    /**
     * Echoed back to the page so the controls keep their state, and appended to
     * the export link so the CSV inherits the same view.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'search' => $this->search,
            'plan' => $this->planKey,
            'condition' => $this->condition,
            'status' => $this->status?->value,
            'payment' => $this->payment,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function isEmpty(): bool
    {
        return $this->toQuery() === [];
    }

    protected static function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    protected static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        // A malformed date filters nothing rather than throwing a 500 at an
        // operator who mistyped one character in a URL.
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
