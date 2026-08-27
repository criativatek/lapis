<?php

namespace App\Domain\Reporting;

/**
 * Which way a flagged indicator points (§11).
 *
 * WHY THIS EXISTS AT ALL. A bare list — «assinalam-se: a participação, a
 * autonomia, a organização» — tells the reader nothing: they cannot know
 * whether those were the class's strengths or its gaps, and the system must not
 * decide for them. A valence Lapispro invented would be exactly the inference §13
 * rules out; a valence the teacher chose is their own statement, and is
 * reportable.
 *
 * `Flagged` is the honest default for «relevante, sem dizer em que sentido»,
 * and it produces its own neutral clause rather than being quietly counted as
 * either side.
 */
enum IndicatorStanding: string
{
    case Strength = 'strength';
    case ToImprove = 'to_improve';
    case Irregular = 'irregular';
    case Flagged = 'flagged';

    public function label(): string
    {
        return match ($this) {
            self::Strength => __('Ponto forte'),
            self::ToImprove => __('A melhorar'),
            self::Irregular => __('Irregular'),
            self::Flagged => __('Apenas assinalar'),
        };
    }

    /**
     * The clause each group opens with, in a sentence whose subject is the
     * list of indicators that share this standing.
     */
    public function lead(): string
    {
        return match ($this) {
            self::Strength => __('Destacam-se positivamente'),
            self::ToImprove => __('Requerem reforço'),
            self::Irregular => __('Revelaram-se irregulares'),
            self::Flagged => __('Assinalam-se, pela relevância no período'),
        };
    }

    /** The same clause when it governs a single indicator. */
    public function singularLead(): string
    {
        return match ($this) {
            self::Strength => __('Destaca-se positivamente'),
            self::ToImprove => __('Requer reforço'),
            self::Irregular => __('Revelou-se irregular'),
            self::Flagged => __('Assinala-se, pela relevância no período'),
        };
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
