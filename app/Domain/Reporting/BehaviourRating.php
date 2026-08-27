<?php

namespace App\Domain\Reporting;

/**
 * How the teacher characterises behaviour (§9).
 *
 * THE SYSTEM NEVER PICKS ONE. Lapispro holds disciplinary occurrences and merit
 * records; it does not hold behaviour. Eight incidents in a class of twenty-six
 * is a count, not a characterisation, and turning one into the other is exactly
 * the inference §12 forbids. So this is asked, never derived — and «não
 * caracterizar» is a real answer that produces no sentence at all rather than a
 * neutral-sounding one.
 *
 * BEHAVIOUR IS NOT ATTITUDE (§8). A class can be perfectly well behaved and
 * disengaged, or lively and hard-working. They are separate questions with
 * separate scales, and merging them would lose the distinction a conselho de
 * turma actually cares about.
 *
 * The words below are a starting set, not a fixture: they live here so a school
 * can one day be given its own, and so that nothing downstream matches on a
 * literal string.
 */
enum BehaviourRating: string
{
    case VeryGood = 'very_good';
    case Good = 'good';
    case Satisfactory = 'satisfactory';
    case Irregular = 'irregular';
    case Problematic = 'problematic';
    case NotCharacterised = 'not_characterised';

    public function label(): string
    {
        return match ($this) {
            self::VeryGood => __('Muito bom'),
            self::Good => __('Bom'),
            self::Satisfactory => __('Satisfatório'),
            self::Irregular => __('Irregular'),
            self::Problematic => __('Problemático'),
            self::NotCharacterised => __('Não caracterizar'),
        };
    }

    /**
     * The words that go into a sentence — «O comportamento da turma foi …».
     *
     * Separate from label() because a dropdown option and a clause are not the
     * same string: «Muito bom» reads as a heading, «muito bom» reads as prose.
     *
     * NOT PUT THROUGH __() ON PURPOSE, unlike label(). This is half a sentence,
     * and half a sentence cannot be translated independently of the clause it
     * agrees with — gender, number and word order all belong to the whole. When
     * the narrative layer is localised it will be localised as sentences (§42),
     * not as fragments a translator would have to reassemble blind.
     */
    public function clause(): ?string
    {
        return match ($this) {
            self::VeryGood => 'muito bom',
            self::Good => 'bom',
            self::Satisfactory => 'satisfatório',
            self::Irregular => 'irregular',
            self::Problematic => 'problemático',
            self::NotCharacterised => null,
        };
    }

    public function characterises(): bool
    {
        return $this !== self::NotCharacterised;
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
