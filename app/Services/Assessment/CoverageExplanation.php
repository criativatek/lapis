<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\CalculationOutcome;
use App\Models\Enrollment;
use App\Models\Instrument;
use Illuminate\Support\Collection;

/**
 * Turns the engine's structured explanation into the note a teacher reads when
 * hovering the ⚠ on the Resultados page.
 *
 * It answers "why is this flagged?" and nothing else: it computes no values and
 * re-derives no rules. WHICH exclusions caused the warning is decided by
 * CalculationEngine and travels in the explanation as `raises_coverage_warning`
 * — a page that re-applied the absence rule itself would eventually disagree
 * with the engine that raised the flag.
 *
 * Two decisions shape the output:
 *
 *  - It groups by INSTRUMENT, not by item. A student absent from a three-question
 *    test has one occurrence, not three: naming the test is what a teacher
 *    recognises, "Q1, Q2, Q3" is only how the engine stores it.
 *  - It reports the recorded STATE and stops there. The page turns the state into
 *    words; this never decides that an exclusion means someone was absent, because
 *    a domain with no elements at all raises the same ⚠ with nobody absent from
 *    anything.
 */
class CoverageExplanation
{
    /**
     * @param  list<array{enrollment: Enrollment, outcome: CalculationOutcome}>  $results
     * @return array<int, array{overall: array{absences: list<array{instrument: string, applied_on: string, reason: string, item_count: int}>, no_elements: bool, excluded_domain_ids: list<int>}, domains: array<int, array{absences: list<array{instrument: string, applied_on: string, reason: string, item_count: int}>, no_elements: bool, excluded_domain_ids: list<int>}>}>
     */
    public function forResults(array $results): array
    {
        $instruments = $this->instrumentsBehind($results);

        $notes = [];

        foreach ($results as $row) {
            $outcome = $row['outcome'];
            $everyExclusion = [];
            $domainNotes = [];

            foreach ($outcome->domains as $domain) {
                $exclusions = $this->raisingExclusionsFor($outcome, $domain->domainId);

                $domainNotes[$domain->domainId] = [
                    'absences' => $this->groupByInstrument($exclusions, $instruments),
                    // A domain flagged with nothing contributing is the other cause:
                    // no evidence yet, rather than evidence lost to an absence.
                    'no_elements' => $domain->coverageWarning && $domain->contributingCount === 0,
                    'excluded_domain_ids' => [],
                ];

                foreach ($exclusions as $exclusion) {
                    $everyExclusion[] = $exclusion;
                }
            }

            $notes[(int) $row['enrollment']->getKey()] = [
                'overall' => [
                    'absences' => $this->groupByInstrument($everyExclusion, $instruments),
                    'no_elements' => $outcome->coverageWarning && $outcome->contributingCount === 0,
                    'excluded_domain_ids' => $this->excludedDomainIds($outcome),
                ],
                'domains' => $domainNotes,
            ];
        }

        return $notes;
    }

    /**
     * The shape of "nothing to explain", so a row without a note and a row with an
     * empty one look identical to the page.
     *
     * @return array{absences: list<array{instrument: string, applied_on: string, reason: string, item_count: int}>, no_elements: bool, excluded_domain_ids: list<int>}
     */
    public static function none(): array
    {
        return ['absences' => [], 'no_elements' => false, 'excluded_domain_ids' => []];
    }

    /**
     * One query for the whole page: every instrument named by any student's note.
     *
     * @param  list<array{enrollment: Enrollment, outcome: CalculationOutcome}>  $results
     * @return Collection<int, Instrument>
     */
    protected function instrumentsBehind(array $results): Collection
    {
        $ids = [];

        foreach ($results as $row) {
            foreach ($row['outcome']->domains as $domain) {
                foreach ($this->raisingExclusionsFor($row['outcome'], $domain->domainId) as $exclusion) {
                    $ids[$exclusion['instrument_id']] = true;
                }
            }
        }

        if ($ids === []) {
            return collect();
        }

        /** @var Collection<int, Instrument> $instruments */
        $instruments = Instrument::query()
            ->whereKey(array_keys($ids))
            ->get(['id', 'title', 'applied_on'])
            ->keyBy('id');

        return $instruments;
    }

    /**
     * The exclusions the engine itself marked as the reason for this domain's ⚠.
     * Items excluded for any other reason — pending, exempt, not applicable to
     * this enrollment — did not raise it and must not be named as if they had.
     *
     * @return list<array{instrument_id: int, item: string, reason: string}>
     */
    protected function raisingExclusionsFor(CalculationOutcome $outcome, int $domainId): array
    {
        /** @var list<array<string, mixed>> $domainExplanations */
        $domainExplanations = $outcome->explanation['domains'] ?? [];

        $raising = [];

        foreach ($domainExplanations as $explanation) {
            if (($explanation['domain_id'] ?? null) !== $domainId) {
                continue;
            }

            /** @var list<array<string, mixed>> $excluded */
            $excluded = $explanation['excluded'] ?? [];

            foreach ($excluded as $exclusion) {
                // Snapshots written before the engine recorded this key have no
                // flag; absent means "did not raise it", never "assume it did".
                if (($exclusion['raises_coverage_warning'] ?? false) !== true) {
                    continue;
                }

                if (! isset($exclusion['instrument_id'])) {
                    continue;
                }

                $raising[] = [
                    'instrument_id' => (int) $exclusion['instrument_id'],
                    'item' => (string) ($exclusion['item'] ?? ''),
                    'reason' => (string) ($exclusion['reason'] ?? ''),
                ];
            }
        }

        return $raising;
    }

    /**
     * Collapses cells into the events a teacher recognises. Deduplicated by item
     * first: an item split across two domains is one absence seen twice, and the
     * overall note must not count it as two.
     *
     * @param  list<array{instrument_id: int, item: string, reason: string}>  $exclusions
     * @param  Collection<int, Instrument>  $instruments
     * @return list<array{instrument: string, applied_on: string, reason: string, item_count: int}>
     */
    protected function groupByInstrument(array $exclusions, Collection $instruments): array
    {
        $seen = [];
        $grouped = [];

        foreach ($exclusions as $exclusion) {
            $cell = $exclusion['instrument_id'].'|'.$exclusion['item'].'|'.$exclusion['reason'];

            if (isset($seen[$cell])) {
                continue;
            }

            $seen[$cell] = true;

            $instrument = $instruments->get($exclusion['instrument_id']);

            if ($instrument === null) {
                continue;
            }

            // Grouped per state, not merely per instrument: a justified absence
            // and an unjustified one are different decisions and are described
            // differently, so they must not collapse into one line.
            $key = $exclusion['instrument_id'].'|'.$exclusion['reason'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'instrument' => $instrument->title,
                    'applied_on' => $instrument->applied_on->format('d/m/Y'),
                    // The recorded state itself travels to the page, which turns
                    // it into words. Sending a boolean would force the UI to
                    // guess at everything that is not an absence.
                    'reason' => $exclusion['reason'],
                    'item_count' => 0,
                ];
            }

            $grouped[$key]['item_count']++;
        }

        return array_values($grouped);
    }

    /**
     * Domains with weight that carried no value and were left out of the weighted
     * average, their weight renormalized over the rest (§13.4). This is the third
     * thing the overall ⚠ can mean, and the one a teacher is least likely to guess.
     *
     * @return list<int>
     */
    protected function excludedDomainIds(CalculationOutcome $outcome): array
    {
        /** @var list<int|string> $dropped */
        $dropped = $outcome->explanation['dropped_domains'] ?? [];

        return array_map(intval(...), $dropped);
    }
}
