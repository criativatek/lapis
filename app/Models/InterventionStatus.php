<?php

namespace App\Models;

/**
 * Where an intervention stands — and nothing about whether it worked.
 *
 * A STATE IS NOT AN APPRAISAL. There is deliberately no «bem-sucedida» and no
 * «eficaz» here: whether a child improved is a judgement the teacher records in
 * a follow-up, and folding it into the lifecycle would make the list claim
 * outcomes nobody stated (§21, §25).
 *
 * `new` READS AS «PLANEADA», which is what it always meant: something decided
 * and not yet under way. Only the word changed, and no stored value moved.
 *
 * `suspended` AND `cancelled` BOTH EXIST, and they are different sentences.
 * Suspended is paused and may resume; cancelled was abandoned. New work is
 * offered «Suspender», because that is the pedagogically honest option — an
 * intervention that stopped being right is still part of the year. Rows that
 * already say «cancelada» keep saying it, because rewriting them would be
 * recategorising history (§4, §45).
 */
enum InterventionStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Concluded = 'concluded';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => __('Planeada'),
            self::InProgress => __('Em curso'),
            self::Concluded => __('Concluída'),
            self::Suspended => __('Suspensa'),
            self::Cancelled => __('Cancelada'),
        };
    }

    /** A finished intervention — no further status change is offered. */
    public function isClosed(): bool
    {
        return $this === self::Concluded || $this === self::Cancelled;
    }

    /**
     * Whether the intervention is still running, which is what makes a review
     * date worth watching. A concluded one cannot be pending (§69).
     */
    public function isOpen(): bool
    {
        return $this === self::New || $this === self::InProgress;
    }

    /**
     * The states a teacher may move an intervention into.
     *
     * `cancelled` is absent: it is history that exists and is never offered
     * again, because «suspensa» says the same thing without claiming the work
     * was a mistake.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            [self::New, self::InProgress, self::Suspended, self::Concluded],
        );
    }
}
