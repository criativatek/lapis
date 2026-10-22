<?php

namespace App\Services\Assessment\Advanced;

use App\Domain\Assessment\Bc;

/**
 * What two moments must share for their figures to be put side by side: the SIGNATURES.
 *
 * TWO SIGNATURES, BECAUSE THEY ANSWER TWO QUESTIONS.
 *
 *  - the `levels` signature is the scale — its kind and its levels, in order. Two
 *    classifications are the same sentence only when the scale they were given on is the
 *    same one, and two scales may both call a level «Bom»;
 *  - the `percentages` signature is the domains of the profile and their weights. A global
 *    result of 68 % under 40/30/30 and a global result of 70 % under 50/50 are not the same
 *    quantity, and a difference between them would be a change of rule dressed as a change
 *    of student.
 *
 * DEMONSTRATED, NEVER ASSUMED. A moment that cannot say what it was made of — a photograph
 * kept before the scale travelled with it — has no signature (`null`), and a `null` equals
 * nothing, not even another `null`: «I do not know» on both sides does not prove the same.
 *
 * Pure: arrays in, a string or null out. No database, no clock.
 */
final class TimelineCriteria
{
    /**
     * @param  array{name?: string, kind?: string, levels?: list<array{code?: string, label?: string, sequence?: int, is_negative?: bool}>}|null  $scale  as `ScaleSummary::of()` writes it
     */
    public static function levels(?array $scale): ?string
    {
        if ($scale === null || ($scale['kind'] ?? null) === null) {
            return null;
        }

        $levels = [];

        foreach ($scale['levels'] ?? [] as $level) {
            $levels[] = [
                (string) ($level['code'] ?? ''),
                (string) ($level['label'] ?? ''),
                (int) ($level['sequence'] ?? 0),
                (bool) ($level['is_negative'] ?? false),
            ];
        }

        // The scale's NAME is deliberately not in it: renaming «Escala 1 a 5» changes no level.
        return sha1((string) json_encode([(string) $scale['kind'], $levels]));
    }

    /**
     * @param  list<array{domain_id?: int|string, weight_percent?: string|int|float|null}>  $domains
     */
    public static function percentages(array $domains): ?string
    {
        if ($domains === []) {
            return null;
        }

        $weights = [];

        foreach ($domains as $domain) {
            if (! isset($domain['domain_id']) || ! array_key_exists('weight_percent', $domain) || $domain['weight_percent'] === null) {
                return null;
            }

            $weights[(int) $domain['domain_id']] = Bc::round(Bc::of((string) $domain['weight_percent']), 4, 'half_up');
        }

        ksort($weights);

        return sha1((string) json_encode($weights));
    }
}
