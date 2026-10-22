<?php

namespace App\Services\Assessment\Advanced;

use InvalidArgumentException;

/**
 * Whether two moments of the timeline may be compared — and, when they may not, WHY, in
 * words a teacher can read.
 *
 * «NÃO COMPARAR SEM DEMONSTRAR» (decisão 8). Classifications of different scales, or results
 * under different criteria, are not compared directly. The default answer of this class is
 * NO; it says yes only when both moments carry the same signature (`TimelineCriteria`), and
 * a moment that carries none — one that cannot say what it was made of — is never comparable.
 * The reason always travels with the refusal: a screen that greyed out a comparison without
 * saying why would leave the teacher guessing about their own data.
 *
 * THREE QUESTIONS, ASKED APART:
 *  - `percentages`: the same domains and weights, so a difference in points is a difference
 *    in the students and not in the rule;
 *  - `levels`: the same scale, so «3» and «4» mean the same two things at both ends;
 *  - per student, `classification()`: a decision taken on another scale than the moment's own
 *    (after a profile migration) never enters a level comparison, whatever the moments say.
 *
 * Pure: points in, a verdict out.
 */
final class TimelineComparability
{
    public const CRITERIA_UNKNOWN = 'criteria_unknown';

    public const CRITERIA_DIFFER = 'criteria_differ';

    public const SCALE_UNKNOWN = 'scale_unknown';

    public const SCALE_DIFFERS = 'scale_differs';

    public const DECIDED_ON_ANOTHER_SCALE = 'decided_on_another_scale';

    /**
     * @param  array{percentage_signature: string|null, level_signature: string|null}  $first
     * @param  array{percentage_signature: string|null, level_signature: string|null}  $second
     * @return array{percentages: bool, levels: bool, reasons: list<array{code: string, text: string}>}
     */
    public static function between(array $first, array $second): array
    {
        $reasons = [];

        $percentages = self::same($first['percentage_signature'], $second['percentage_signature']);

        if (! $percentages) {
            $reasons[] = self::reason(
                $first['percentage_signature'] === null || $second['percentage_signature'] === null
                    ? self::CRITERIA_UNKNOWN
                    : self::CRITERIA_DIFFER,
            );
        }

        $levels = self::same($first['level_signature'], $second['level_signature']);

        if (! $levels) {
            $reasons[] = self::reason(
                $first['level_signature'] === null || $second['level_signature'] === null
                    ? self::SCALE_UNKNOWN
                    : self::SCALE_DIFFERS,
            );
        }

        return ['percentages' => $percentages, 'levels' => $levels, 'reasons' => $reasons];
    }

    /**
     * Whether this student's classification at BOTH moments may enter a level comparison.
     *
     * @param  array{level_comparable?: bool}|null  $first  the `classification` of a student's cell
     * @param  array{level_comparable?: bool}|null  $second
     * @return array{comparable: bool, reason: array{code: string, text: string}|null}
     */
    public static function classification(?array $first, ?array $second): array
    {
        foreach ([$first, $second] as $classification) {
            if ($classification !== null && ($classification['level_comparable'] ?? true) === false) {
                return ['comparable' => false, 'reason' => self::reason(self::DECIDED_ON_ANOTHER_SCALE)];
            }
        }

        return ['comparable' => true, 'reason' => null];
    }

    private static function same(?string $first, ?string $second): bool
    {
        return $first !== null && $second !== null && $first === $second;
    }

    /**
     * @return array{code: string, text: string}
     */
    private static function reason(string $code): array
    {
        return ['code' => $code, 'text' => match ($code) {
            self::CRITERIA_UNKNOWN => __('Um dos momentos não traz os critérios de avaliação (domínios e pesos), por isso não se demonstra que os resultados sejam comparáveis.'),
            self::CRITERIA_DIFFER => __('Os domínios ou os pesos de avaliação mudaram entre os dois momentos: as percentagens não são diretamente comparáveis.'),
            self::SCALE_UNKNOWN => __('Um dos momentos foi guardado antes de a escala viajar com ele, por isso não se demonstra que os níveis sejam os mesmos.'),
            self::SCALE_DIFFERS => __('A escala de avaliação mudou entre os dois momentos: os níveis não são comparáveis.'),
            self::DECIDED_ON_ANOTHER_SCALE => __('Uma das classificações foi decidida noutra escala: não se compara com a de agora.'),
            default => throw new InvalidArgumentException("Unknown comparability reason «{$code}»."),
        }];
    }
}
