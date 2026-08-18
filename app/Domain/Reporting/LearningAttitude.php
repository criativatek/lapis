<?php

namespace App\Domain\Reporting;

/**
 * How the teacher characterises the class's or student's attitude towards
 * learning (§10).
 *
 * A SEPARATE QUESTION FROM BEHAVIOUR (§8), with a scale of its own: behaviour
 * is graded good to problematic, attitude is graded favourable to unfavourable,
 * and neither word belongs on the other's scale.
 *
 * Asked, never inferred — for the same reason as BehaviourRating, and one more:
 * attitude is the dimension a report is most tempted to read off a low average,
 * which is precisely the inference §13 rules out.
 */
enum LearningAttitude: string
{
    case VeryPositive = 'very_positive';
    case Positive = 'positive';
    case Adequate = 'adequate';
    case Irregular = 'irregular';
    case Unfavourable = 'unfavourable';
    case NotCharacterised = 'not_characterised';

    public function label(): string
    {
        return match ($this) {
            self::VeryPositive => __('Muito positiva'),
            self::Positive => __('Positiva'),
            self::Adequate => __('Adequada'),
            self::Irregular => __('Irregular'),
            self::Unfavourable => __('Pouco favorável'),
            self::NotCharacterised => __('Não caracterizar'),
        };
    }

    /** Half a sentence, so a literal rather than a __() key — see BehaviourRating::clause(). */
    public function clause(): ?string
    {
        return match ($this) {
            self::VeryPositive => 'muito positiva',
            self::Positive => 'positiva',
            self::Adequate => 'adequada',
            self::Irregular => 'irregular',
            self::Unfavourable => 'pouco favorável',
            self::NotCharacterised => null,
        };
    }

    public function characterises(): bool
    {
        return $this !== self::NotCharacterised;
    }

    /**
     * Whether this reading is on the favourable side.
     *
     * Used by the institutional report to say «em 62% das turmas com
     * caracterização disponível, a atitude foi positiva ou muito positiva»
     * (§27) — a count of what teachers stated, never a judgement added here.
     * «Adequada» is deliberately not counted as positive: it is the middle of
     * this scale, and rounding it upward would inflate an institutional figure.
     */
    public function isFavourable(): bool
    {
        return $this === self::VeryPositive || $this === self::Positive;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
