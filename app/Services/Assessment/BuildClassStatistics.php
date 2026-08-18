<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Models\AcademicPeriod;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Support\Assessment\AssessmentCutoff;

/**
 * The class read as a whole, instead of student by student.
 *
 * NOTHING ACADEMIC IS COMPUTED HERE. Every figure this aggregates was already
 * decided by BuildResultsProgression — which in turn assembles what
 * ClassResultsCalculator produced — and this makes exactly one call to it. What
 * is added is the layer above: counting, averaging across students, and
 * grouping. Estatística must never be able to disagree with Resultados or with
 * the Quadro Síntese, and the only way to guarantee that is to read the same
 * numbers rather than to derive them again (§0).
 *
 * The arithmetic that IS here is statistical and says so:
 *
 *  - the class average is the mean of the students' own canonical averages,
 *    each student counting once. Not a mean of domain means, which would weigh
 *    a domain by how many students happen to have evidence in it;
 *  - a null never enters a mean. A student with no evidence is not a zero, and
 *    dividing by them would move a class average by the mere absence of a
 *    result (§37);
 *  - counts of movement come from the evolution the progression already
 *    decided: standalone against the previous standalone, never accumulated
 *    against accumulated (§10).
 *
 * Rounded to the same precision the rest of the application shows, so that
 * «72,4%» on Resultados is «72,4%» here (§38).
 */
class BuildClassStatistics
{
    /** The same precision the results are read at. */
    public const PRECISION = BuildResultsProgression::PRECISION;

    public function __construct(
        protected BuildResultsProgression $progression,
        protected ScaleProposalResolver $proposals,
        protected PrimaryResultScope $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(SchoolClass $class, ?AcademicPeriod $period = null, ?AssessmentCutoff $cutoff = null): array
    {
        // THE ONE CALL. Everything below is arithmetic over its output — no
        // further queries, whatever the size of the class (§39). The cutoff, if
        // there is one, was already applied to the evidence in there.
        $progression = $this->progression->for($class, $cutoff);

        $periods = $progression['periods'];
        $domains = $progression['domains'];
        $students = $progression['students'];

        $selected = $this->selectedPeriod($periods, $students, $period);

        if ($selected === null) {
            return $this->empty($periods, $domains);
        }

        $scale = $class->profileVersion?->scale()->with('levels')->first();
        $rows = $this->rowsFor($students, $selected['id']);

        // WHICH FIGURE IS THE ANSWER, at each moment of this year. Read from
        // the profile version's own periods, so a school that configures
        // continuity differently gets a different answer here without a line
        // of code changing (§2).
        $scopes = $this->scope->forClass($class, $periods);
        $primaryKind = $scopes[$selected['id']] ?? 'period';

        $previous = $this->previousPeriod($periods, $selected['id']);
        // The same students, read at the period before — so a change of side on
        // the scale can be seen at all. Another reshaping of what is already in
        // hand, not another read.
        $previousRows = $previous === null ? [] : $this->rowsFor($students, $previous['id']);

        return [
            'periods' => $periods,
            'selected_period' => $selected,
            'previous_period' => $previous,
            'domains' => $domains,
            'scale' => $this->scalePayload($scale),
            // Which reading answers «como está a turma» at this moment, and
            // which one is the supplementary «e só neste período?» (§1, §5).
            'primary' => $this->primaryPayload($primaryKind, $scopes, $periods, $selected),
            'summary' => $this->summary($rows, $scale, $primaryKind),
            'evolution' => $this->evolution($rows, $previousRows, $scale),
            'continuous_evolution' => $this->continuousEvolution(
                $rows,
                $previousRows,
                $primaryKind,
                $previous === null ? null : ($scopes[$previous['id']] ?? 'period'),
            ),
            // The grades, and the averages. Two readings, never averaged into
            // one, each named on screen by what it counts (§1, §8).
            'assigned_distribution' => $this->assignedDistribution($rows, $scale),
            'distribution' => $this->distribution($rows, $scale),
            'domain_statistics' => $this->domainStatistics($rows, $domains, $scale),
            'period_series' => $this->periodSeries($students, $periods, $domains, $scopes),
            'students' => $this->students(
                $rows,
                $scale,
                $students,
                $previousRows,
                $primaryKind,
                $scopes,
                $previous === null ? null : ($scopes[$previous['id']] ?? 'period'),
            ),
        ];
    }

    /**
     * The chosen figure of a student's period row.
     *
     * @param  array<string, mixed>  $row
     */
    protected function primaryValueOf(array $row, string $kind): ?string
    {
        return $kind === 'accumulated'
            ? ($row['period']['accumulated_average'] ?? null)
            : ($row['period']['weighted_average'] ?? null);
    }

    /**
     * The other one — null when the two would be the same number.
     *
     * @param  array<string, mixed>  $row
     */
    protected function supplementaryValueOf(array $row, string $kind): ?string
    {
        return $kind === 'accumulated' ? ($row['period']['weighted_average'] ?? null) : null;
    }

    /**
     * What the page should call the two readings, decided once and server-side.
     *
     * @param  array<int, string>  $scopes
     * @param  list<array<string, mixed>>  $periods
     * @param  array<string, mixed>  $selected
     * @return array<string, mixed>
     */
    protected function primaryPayload(string $kind, array $scopes, array $periods, array $selected): array
    {
        $continuous = $kind === 'accumulated';

        return [
            'kind' => $kind,
            'label' => $continuous ? 'Média Ponderada Acumulada' : 'Média Ponderada',
            'short_label' => $continuous ? 'Média acumulada da turma' : 'Média da turma',
            'caption' => $continuous
                ? 'Resultado acumulado · avaliação contínua'
                : "Resultado do {$selected['label']}",
            'supplementary_label' => $continuous ? "Só no {$selected['label']}" : null,
            // Whether there is a second reading at all. At the first moment the
            // two figures are the same number and showing both would invent a
            // distinction the data does not have.
            'has_supplementary' => $continuous,
            // Every period of the year and which figure answers for it, so the
            // longitudinal readings can follow the same rule.
            'scopes' => $scopes,
        ];
    }

    /**
     * The period under analysis, and its row.
     *
     * With none asked for, the LAST period that any student has a standalone
     * result in — the one a teacher is working on. Falling back to the first
     * would open the year on a period that closed months ago.
     *
     * @param  list<array<string, mixed>>  $periods
     * @param  list<array<string, mixed>>  $students
     * @return array<string, mixed>|null
     */
    protected function selectedPeriod(array $periods, array $students, ?AcademicPeriod $period): ?array
    {
        if ($periods === []) {
            return null;
        }

        if ($period !== null) {
            foreach ($periods as $row) {
                if ($row['id'] === $period->id) {
                    return $row;
                }
            }
        }

        $latest = null;

        foreach ($periods as $row) {
            if ($this->valuesOf($this->rowsFor($students, $row['id']), 'weighted_average') !== []) {
                $latest = $row;
            }
        }

        // A year that has not started anywhere opens on its first period, which
        // is the only honest place to be standing.
        return $latest ?? $periods[0];
    }

    /**
     * @param  list<array<string, mixed>>  $periods
     * @return array<string, mixed>|null
     */
    protected function previousPeriod(array $periods, int $selectedId): ?array
    {
        $previous = null;

        foreach ($periods as $row) {
            if ($row['id'] === $selectedId) {
                return $previous;
            }

            $previous = $row;
        }

        return null;
    }

    /**
     * One row per student: their own period entry, lifted out of the
     * progression exactly as it was written there.
     *
     * @param  list<array<string, mixed>>  $students
     * @return list<array<string, mixed>>
     */
    protected function rowsFor(array $students, int $periodId): array
    {
        $rows = [];

        foreach ($students as $student) {
            $entry = null;

            foreach ($student['periods'] as $candidate) {
                if ($candidate['period_id'] === $periodId) {
                    $entry = $candidate;

                    break;
                }
            }

            $rows[] = [
                'enrollment_id' => $student['enrollment_id'],
                'name' => $student['name'],
                'class_number' => $student['class_number'],
                'period' => $entry,
            ];
        }

        return $rows;
    }

    /**
     * The headline figures.
     *
     * `class_average` and `accumulated_average` answer two different questions
     * and are both given a name on screen, because «a média da turma» is
     * ambiguous the moment a year has more than one period (§7).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function summary(array $rows, ?Scale $scale, string $primaryKind = 'period'): array
    {
        $standalone = $this->valuesOf($rows, 'weighted_average');
        $accumulated = $this->valuesOf($rows, 'accumulated_average');

        $partial = 0;

        foreach ($rows as $row) {
            // A VALUE THAT EXISTS AND RESTS ON LESS THAN EVERYTHING EXPECTED.
            //
            // The engine raises the same flag for two different situations: a
            // result built on part of the evidence, and no result at all because
            // nothing could produce one. Counting both as «informação parcial»
            // would tell a teacher that every student in a class that has not
            // been assessed yet has a partial result, which is the opposite of
            // what happened. Without a value there is nothing for the coverage to
            // be partial OF — those students are counted as `without_result`.
            if (($row['period']['coverage_warning'] ?? false) === true
                && ($row['period']['weighted_average'] ?? null) !== null) {
                $partial++;
            }
        }

        return [
            'students_total' => count($rows),
            'students_with_result' => count($standalone),
            'students_without_result' => count($rows) - count($standalone),
            'class_average' => $this->mean($standalone),
            'accumulated_average' => $this->mean($accumulated),
            // THE ANSWER, and the other one. Both are already above under their
            // own names; these two say which is which at this moment, so no
            // screen has to re-derive the rule (§2).
            'primary_average' => $primaryKind === 'accumulated'
                ? $this->mean($accumulated)
                : $this->mean($standalone),
            'supplementary_average' => $primaryKind === 'accumulated'
                ? $this->mean($standalone)
                : null,
            'partial_coverage_count' => $partial,
            'most_common_band' => $this->mostCommonBand($rows, $scale),
            'success' => $this->success($rows, $scale),
        ];
    }

    /**
     * «Quantos alunos tiveram classificação positiva atribuída?»
     *
     * THE OFFICIAL RATE, AND THEREFORE THE TEACHER'S OWN DECISIONS. This is the
     * number a conselho de turma quotes, so it counts grades that were actually
     * given — not the mentions the averages happen to land on. A student whose
     * accumulated figure sits in «Bom» and whose teacher wrote «2» is a
     * negative, and a rate that said otherwise would be reporting something
     * nobody decided (§13, §25).
     *
     * The statistical reading has not gone anywhere: «Como se distribuem os
     * resultados» still bands the Média Ponderada Acumulada, and says so on
     * screen. The two are kept apart rather than averaged into one number that
     * means neither (§4).
     *
     * WHICH SIDE A DECISION IS ON IS THE SCALE'S STATEMENT, through
     * `is_negative`. Hard-coding «>= 50%» or «>= 10» would invent a pedagogical
     * rule and would be wrong the moment a school configures its own scale.
     *
     * THREE GROUPS, AND ONLY ONE DENOMINATOR:
     *
     *  - placed: a student with a decision whose side the scale can state.
     *    Those and only those are counted for or against the rate;
     *  - unplaced: decided, but on a scale that has no statement about that
     *    decision. Counting them either way would answer a question nobody
     *    asked the scale;
     *  - without_classification: nothing decided yet, or still only a proposal.
     *    Never a failure (§11): not having been graded is not a bad grade, and
     *    it stays out of the denominator entirely.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function success(array $rows, ?Scale $scale): array
    {
        $succeeded = 0;
        $failed = 0;
        $unplaced = 0;
        $withoutClassification = 0;

        foreach ($rows as $row) {
            match ($this->assignedOutcomeOf($row, $scale)) {
                'positive' => $succeeded++,
                'negative' => $failed++,
                'unclassified' => $unplaced++,
                default => $withoutClassification++,
            };
        }

        $placed = $succeeded + $failed;

        return [
            'succeeded' => $succeeded,
            'failed' => $failed,
            // Decided, but on a scale that states nothing about that decision.
            'unplaced' => $unplaced,
            'without_classification' => $withoutClassification,
            // The denominator, stated so a screen never has to guess it.
            'placed' => $placed,
            'rate' => $this->percentage($succeeded, $placed),
            'failure_rate' => $this->percentage($failed, $placed),
        ];
    }

    /**
     * How many students moved, and by how much on average.
     *
     * Read from the evolution the progression already decided. «Sem comparação»
     * is its own answer and never folded into «manteve-se»: a student with no
     * previous period did not stand still, they have nothing to stand against
     * (§37).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $previousRows
     * @return array<string, mixed>
     */
    protected function evolution(array $rows, array $previousRows = [], ?Scale $scale = null): array
    {
        $counts = ['progressed' => 0, 'stable' => 0, 'regressed' => 0, 'no_comparison' => 0];
        $changes = [];

        foreach ($rows as $row) {
            $evolution = $row['period']['evolution'] ?? null;

            if ($evolution === null) {
                $counts['no_comparison']++;

                continue;
            }

            $counts[match ($evolution['direction']) {
                'up' => 'progressed',
                'down' => 'regressed',
                default => 'stable',
            }]++;

            $changes[] = (string) $evolution['points'];
        }

        $comparable = count($changes);

        return [
            ...$counts,
            'comparable' => $comparable,
            // Percentage POINTS, and only over the students who had two periods
            // to compare. Averaging over the whole class would dilute the
            // movement with students who did not move because they could not.
            'average_change' => $this->mean($changes),
            'percentages' => [
                'progressed' => $this->percentage($counts['progressed'], count($rows)),
                'stable' => $this->percentage($counts['stable'], count($rows)),
                'regressed' => $this->percentage($counts['regressed'], count($rows)),
                'no_comparison' => $this->percentage($counts['no_comparison'], count($rows)),
            ],
            'transitions' => $this->transitions($rows, $previousRows, $scale),
        ];
    }

    /**
     * How the CONTINUOUS assessment moved — a second reading, never the same
     * field as the first.
     *
     * `evolution` above compares this period's own work against the last
     * period's own work, which is what BuildResultsProgression has always
     * meant by the word and what it still means. That answers «este período
     * correu melhor que o anterior?».
     *
     * This one compares the RESULT OF THE MOMENT at each end — the figure that
     * actually answers «como está o aluno» there. A student who scored 70 in
     * the first period and 50 in the second fell twenty points as a period and
     * five as a year, and both sentences are true. They are given different
     * names because they are different facts, and one may never be quietly
     * substituted for the other (§13, §14, §45).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $previousRows
     * @return array<string, mixed>
     */
    protected function continuousEvolution(
        array $rows,
        array $previousRows,
        string $kind,
        ?string $previousKind,
    ): array {
        $before = [];

        foreach ($previousRows as $row) {
            $before[(int) $row['enrollment_id']] = $row;
        }

        $counts = ['progressed' => 0, 'stable' => 0, 'regressed' => 0, 'no_comparison' => 0];
        $changes = [];

        foreach ($rows as $row) {
            $movement = $this->continuousMovementOf(
                $row,
                $before[(int) $row['enrollment_id']] ?? null,
                $kind,
                $previousKind,
            );

            if ($movement === null) {
                $counts['no_comparison']++;

                continue;
            }

            $counts[match ($movement['direction']) {
                'up' => 'progressed',
                'down' => 'regressed',
                default => 'stable',
            }]++;

            $changes[] = $movement['points'];
        }

        return [
            ...$counts,
            'comparable' => count($changes),
            'average_change' => $this->mean($changes),
            'percentages' => [
                'progressed' => $this->percentage($counts['progressed'], count($rows)),
                'stable' => $this->percentage($counts['stable'], count($rows)),
                'regressed' => $this->percentage($counts['regressed'], count($rows)),
                'no_comparison' => $this->percentage($counts['no_comparison'], count($rows)),
            ],
        ];
    }

    /**
     * One student's movement between the two moments' own results.
     *
     * Rounded and compared at the precision the page shows, so «igual» on
     * screen is «manteve-se» here — the same rule BuildResultsProgression uses
     * for its own subtraction, applied to a different pair of numbers.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $previousRow
     * @return array{direction: string, points: string}|null
     */
    protected function continuousMovementOf(
        array $row,
        ?array $previousRow,
        string $kind,
        ?string $previousKind,
    ): ?array {
        if ($previousRow === null || $previousKind === null) {
            return null;
        }

        $from = $this->primaryValueOf($previousRow, $previousKind);
        $to = $this->primaryValueOf($row, $kind);

        // A missing end is «nothing to compare», never a fall to zero (§13.3).
        if ($from === null || $to === null) {
            return null;
        }

        $before = Bc::round(Bc::of($from), self::PRECISION, 'half_up');
        $after = Bc::round(Bc::of($to), self::PRECISION, 'half_up');
        $comparison = Bc::compare($after, $before);

        return [
            'direction' => match (true) {
                $comparison > 0 => 'up',
                $comparison < 0 => 'down',
                default => 'flat',
            },
            'points' => Bc::round(Bc::sub($after, $before), self::PRECISION, 'half_up'),
        ];
    }

    /**
     * Who changed SIDE of the scale, which is not the same as who moved.
     *
     * A student going from 62% to 68% progressed and stayed exactly where they
     * were pedagogically; one going from 48% to 53% crossed the line the school
     * actually cares about. The two readings answer different questions and the
     * section shows both rather than letting one stand for the other.
     *
     * THE SIDE IS THE TEACHER'S DECISION, read through the scale's own
     * `is_negative`. There is no second engine for positive/negative here and
     * no threshold written anywhere in this file (§2, §4).
     *
     * THE MOVEMENT ABOVE READS THE CALCULATED RESULT AND THIS READS THE
     * ASSIGNED CLASSIFICATION, deliberately and with different denominators:
     * «subiu nove pontos» is arithmetic about evidence, «passou a positivo» is
     * a decision somebody took. A student can do the first without the second
     * and the second without the first.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $previousRows
     * @return array<string, mixed>
     */
    protected function transitions(array $rows, array $previousRows, ?Scale $scale): array
    {
        $before = [];

        foreach ($previousRows as $row) {
            $before[(int) $row['enrollment_id']] = $row;
        }

        $counts = [
            'failure_to_success' => 0,
            'success_to_failure' => 0,
            'success_to_success' => 0,
            'failure_to_failure' => 0,
            'unclassified' => 0,
            'no_assigned_classification' => 0,
        ];

        foreach ($rows as $row) {
            $counts[$this->transitionOf($row, $before[(int) $row['enrollment_id']] ?? null, $scale)]++;
        }

        // THE DENOMINATOR IS STUDENTS WITH A DECISION AT BOTH ENDS whose side
        // the scale can state — not students with two averages, and not the
        // class. Somebody the teacher has not graded yet did not «stay»
        // anywhere, and counting them either way would invent a grade (§11).
        $comparable = $counts['failure_to_success'] + $counts['success_to_failure']
            + $counts['success_to_success'] + $counts['failure_to_failure'];

        return [
            ...$counts,
            'comparable' => $comparable,
            'percentages' => [
                'failure_to_success' => $this->percentage($counts['failure_to_success'], $comparable),
                'success_to_failure' => $this->percentage($counts['success_to_failure'], $comparable),
                'success_to_success' => $this->percentage($counts['success_to_success'], $comparable),
                'failure_to_failure' => $this->percentage($counts['failure_to_failure'], $comparable),
            ],
            // These two sit OUTSIDE that denominator, so their share is of the
            // class — said in its own key rather than mixed into the one above.
            'share_of_class' => [
                'unclassified' => $this->percentage($counts['unclassified'], count($rows)),
                'no_assigned_classification' => $this->percentage($counts['no_assigned_classification'], count($rows)),
            ],
        ];
    }

    /**
     * Which side of the scale the TEACHER put this student on, and which side
     * they are on now.
     *
     * THE DECISION, NOT THE ARITHMETIC. Passing and failing are things a
     * teacher decides, and LÁPIS keeps four different statements about a
     * student deliberately apart (§14): the Média Ponderada is what was
     * calculated, the proposal is what the system suggested, the self
     * assessment is what the student said, and the classification is what the
     * teacher decided. Only the last one is a grade. A student whose average
     * lands in «Bom» and whose teacher wrote «2» has a negative classification,
     * and this must say so.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $previousRow
     */
    protected function transitionOf(array $row, ?array $previousRow, ?Scale $scale): string
    {
        if ($previousRow === null) {
            return 'no_assigned_classification';
        }

        $before = $this->assignedOutcomeOf($previousRow, $scale);
        $after = $this->assignedOutcomeOf($row, $scale);

        // NOTHING WAS DECIDED AT ONE OF THE ENDS. There is no official crossing
        // to report, and filling the gap from the average or the proposal would
        // be putting a grade in the teacher's mouth (§7, §3.3).
        if ($before === 'none' || $after === 'none') {
            return 'no_assigned_classification';
        }

        // Decided, but on a scale that cannot say which side that decision is.
        if ($before === 'unclassified' || $after === 'unclassified') {
            return 'unclassified';
        }

        return match (true) {
            $before === 'negative' && $after === 'positive' => 'failure_to_success',
            $before === 'positive' && $after === 'negative' => 'success_to_failure',
            $before === 'negative' => 'failure_to_failure',
            default => 'success_to_success',
        };
    }

    /**
     * Whether the teacher actually decided this student's period.
     *
     * ONLY A DECISION COUNTS. `proposed` means they have not answered yet;
     * `superseded` is not the live one. The progression already forces a
     * post-cutoff confirmation back to `proposed`, so a page read at a date
     * cannot see a grade written after it.
     *
     * @param  array<string, mixed>  $row
     */
    protected function hasAssignedClassification(array $row): bool
    {
        $classification = $row['period']['classification'] ?? null;

        if ($classification === null) {
            return false;
        }

        if (! in_array($classification['status'] ?? null, ['confirmed', 'published'], true)) {
            return false;
        }

        return ($classification['final'] ?? null) !== null
            || ($classification['final_value'] ?? null) !== null;
    }

    /**
     * The band the teacher's own decision names — the grade, as a mention.
     *
     * On a levelled scale this IS the decision: the level they chose, carried
     * out of the canonical read model with its own identity. On a numeric one
     * the decision is a bare number, and only the scale's own bands may name
     * it; a scale without them gets null rather than an invented interval.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function assignedLevelOf(array $row, ?Scale $scale): ?array
    {
        if (! $this->hasAssignedClassification($row)) {
            return null;
        }

        $classification = $row['period']['classification'];
        $final = $classification['final'] ?? null;

        if ($final !== null) {
            return $final;
        }

        return $this->bandPayload($this->bandForNumericDecision($classification['final_value'] ?? null, $scale));
    }

    /**
     * The side of the scale the teacher's own decision falls on.
     *
     * «none» — nothing was decided for this period, or what exists is still a
     * proposal. «unclassified» — a decision exists, but the scale has no
     * statement about which side it is; saying nothing is the correct answer
     * (§10.4).
     *
     * @param  array<string, mixed>  $row
     * @return 'positive'|'negative'|'unclassified'|'none'
     */
    protected function assignedOutcomeOf(array $row, ?Scale $scale): string
    {
        if (! $this->hasAssignedClassification($row)) {
            return 'none';
        }

        $level = $this->assignedLevelOf($row, $scale);

        if ($level === null) {
            return 'unclassified';
        }

        return ($level['is_negative'] ?? false) ? 'negative' : 'positive';
    }

    /**
     * A decision written as a bare number, placed on the scale's own bands.
     *
     * ONLY THE SCALE'S OWN BANDS MAY ANSWER. A numeric scale that was never
     * given qualitative bands has no statement about where passing begins, and
     * a «>= 10» written here would be inventing a pedagogical rule (§1, §11).
     *
     * When it does have bands, they live in normalized space, so the teacher's
     * number is placed back onto that axis with the inverse of the one approved
     * placement rule — `min + (normalized/100) × (max − min)` — applied to the
     * DECISION and never to a computed result.
     */
    protected function bandForNumericDecision(?string $value, ?Scale $scale): ?ScaleLevel
    {
        if ($value === null || trim($value) === '' || $scale === null) {
            return null;
        }

        if ($scale->levels->isEmpty() || $scale->min_value === null || $scale->max_value === null) {
            return null;
        }

        $span = Bc::sub(Bc::of((string) $scale->max_value), Bc::of((string) $scale->min_value));

        if (Bc::compare($span, '0') === 0) {
            return null;
        }

        return $this->proposals->bandFor($scale, Bc::mul(
            Bc::div(Bc::sub(Bc::of($value), Bc::of((string) $scale->min_value)), $span),
            '100',
        ));
    }

    /**
     * How the class falls across the scale's own bands.
     *
     * The band of a student is read through ScaleProposalResolver — the one
     * service that turns a normalized value into a band, and the very same call
     * the Quadro Síntese makes for each domain's mention. Nothing here decides
     * a threshold.
     *
     * Every band of the scale appears, including the ones nobody is in: a level
     * with zero students is a fact about the class, and omitting it would draw
     * a different chart for every class (§49).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    /**
     * «Quantos alunos tiveram nível 2?» — the grades, counted.
     *
     * THE ANSWER A PAUTA GIVES. The distribution beside it bands the calculated
     * average and answers a different question; both are on the page and each
     * says which it is, because a class where one student was given a «2» and
     * whose averages all land in «Suficiente» is described truthfully by
     * neither on its own (§1).
     *
     * EVERY BAND OF THE SCALE GETS A ROW, in the scale's own sequence and with
     * the scale's own words, including the ones nobody is in — «Nível 2: 0» is
     * an answer and a missing row is not. Nothing here knows what a «3» is: a
     * school using «Não atingiu / Atingiu / Superou» gets exactly those.
     *
     * A band that no longer belongs to the scale but that somebody was graded
     * on still gets its row, at the end: their history is true even if the
     * scale has moved on.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function assignedDistribution(array $rows, ?Scale $scale): array
    {
        $counts = [];
        $seen = [];
        $classified = 0;
        $unplaced = 0;
        $withoutClassification = 0;

        foreach ($rows as $row) {
            if (! $this->hasAssignedClassification($row)) {
                $withoutClassification++;

                continue;
            }

            $level = $this->assignedLevelOf($row, $scale);

            // Graded, on a scale that cannot name it. Counted apart rather than
            // dropped into a band it was never placed in.
            if ($level === null) {
                $unplaced++;

                continue;
            }

            $id = (int) $level['scale_level_id'];
            $counts[$id] = ($counts[$id] ?? 0) + 1;
            $seen[$id] = $level;
            $classified++;
        }

        $bands = [];
        $levels = $scale === null ? collect() : $scale->levels->sortBy('sequence');

        foreach ($levels as $level) {
            $id = (int) $level->id;
            unset($seen[$id]);

            $bands[] = [
                'scale_level_id' => $id,
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'sequence' => (int) $level->sequence,
                'is_negative' => (bool) $level->is_negative,
                'count' => $counts[$id] ?? 0,
                // Of the students actually graded. Dividing by the whole class
                // would let students nobody has classified yet silently shrink
                // every band without appearing anywhere.
                'percentage' => $this->percentage($counts[$id] ?? 0, $classified),
            ];
        }

        foreach ($seen as $id => $level) {
            $bands[] = [
                'scale_level_id' => (int) $id,
                'code' => (string) $level['code'],
                'label' => (string) $level['label'],
                'sequence' => (int) $level['sequence'],
                'is_negative' => (bool) $level['is_negative'],
                'count' => $counts[$id],
                'percentage' => $this->percentage($counts[$id], $classified),
                // The scale no longer has this band; the grade still happened.
                'outside_scale' => true,
            ];
        }

        return [
            'bands' => $bands,
            // The denominator, stated so a screen never has to guess it.
            'classified' => $classified,
            'unplaced' => $unplaced,
            'without_classification' => $withoutClassification,
        ];
    }

    /**
     * Where the CALCULATED averages land, across the scale's bands.
     *
     * The secondary reading, and named as such on screen: it answers «onde está
     * a turma» and not «que notas foram dadas». Kept because it is genuinely
     * useful before a class is graded, and because the gap between the two is
     * itself worth seeing (§7).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function distribution(array $rows, ?Scale $scale): array
    {
        if ($scale === null || $scale->levels->isEmpty()) {
            return [];
        }

        $counts = [];
        $placed = 0;

        foreach ($rows as $row) {
            $level = $this->bandOf($row, $scale);

            if ($level === null) {
                continue;
            }

            $counts[$level->id] = ($counts[$level->id] ?? 0) + 1;
            $placed++;
        }

        $bands = [];

        foreach ($scale->levels->sortBy('sequence') as $level) {
            $count = $counts[$level->id] ?? 0;

            $bands[] = [
                'scale_level_id' => (int) $level->id,
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'sequence' => (int) $level->sequence,
                'is_negative' => (bool) $level->is_negative,
                'count' => $count,
                // Of the students actually placed on the scale. Dividing by the
                // whole class would let students with no result silently shrink
                // every bar without appearing anywhere.
                'percentage' => $this->percentage($count, $placed),
            ];
        }

        return $bands;
    }

    /**
     * Per domain, across the class.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{id: int, name: string}>  $domains
     * @return list<array<string, mixed>>
     */
    protected function domainStatistics(array $rows, array $domains, ?Scale $scale): array
    {
        $statistics = [];

        foreach ($domains as $domain) {
            $period = [];
            $accumulated = [];
            $changes = [];
            $partial = 0;
            $succeeded = 0;
            $placed = 0;

            foreach ($rows as $row) {
                $cell = $this->domainCell($row, $domain['id']);

                if ($cell === null) {
                    continue;
                }

                // The same rule as the class figure, applied to this domain's
                // own mention: the scale decides, and a domain the scale places
                // nothing in counts for neither side.
                $mention = $cell['mention'] ?? null;

                if ($mention !== null) {
                    $placed++;

                    if ($mention['is_negative'] === false) {
                        $succeeded++;
                    }
                }

                if ($cell['weighted_average'] !== null) {
                    $period[] = (string) $cell['weighted_average'];
                }

                if ($cell['accumulated_average'] !== null) {
                    $accumulated[] = (string) $cell['accumulated_average'];
                }

                if (($cell['evolution'] ?? null) !== null) {
                    $changes[] = (string) $cell['evolution']['points'];
                }

                // Same rule as the summary's: partial describes a value that
                // exists. A domain nobody has evidence in is not «6 resultados
                // parciais», it is no results at all.
                if (($cell['coverage_warning'] ?? false) === true && $cell['weighted_average'] !== null) {
                    $partial++;
                }
            }

            $accumulatedMean = $this->mean($accumulated);

            $statistics[] = [
                'domain_id' => $domain['id'],
                'label' => $domain['name'],
                'period_average' => $this->mean($period),
                'accumulated_average' => $accumulatedMean,
                'evolution_average' => $this->mean($changes),
                'students_with_result' => count($period),
                'students_without_result' => count($rows) - count($period),
                'partial_coverage_count' => $partial,
                // Success within this domain — «5 de 6» — so a teacher can see
                // which domain is carrying the class and which is holding it
                // back, without a second chart for each (§7).
                'succeeded' => $succeeded,
                'placed' => $placed,
                'success_rate' => $this->percentage($succeeded, $placed),
                // The band of the CLASS's accumulated mean in this domain. A
                // statistic about the group, never a mention belonging to any
                // student — placed by the same resolver all the same.
                'qualitative_band' => $this->bandPayload($this->proposals->bandFor($scale, $accumulatedMean)),
            ];
        }

        return $statistics;
    }

    /**
     * The class along its periods, for the trend lines.
     *
     * STANDALONE throughout. An accumulated figure already contains the periods
     * before it, so a line drawn from accumulated values slopes towards its own
     * history and reports movement that did not happen (§15).
     *
     * @param  list<array<string, mixed>>  $students
     * @param  list<array<string, mixed>>  $periods
     * @param  array<int, string>  $scopes
     * @param  list<array{id: int, name: string}>  $domains
     * @return list<array<string, mixed>>
     */
    protected function periodSeries(array $students, array $periods, array $domains, array $scopes = []): array
    {
        $series = [];

        foreach ($periods as $period) {
            $rows = $this->rowsFor($students, $period['id']);
            $overall = $this->valuesOf($rows, 'weighted_average');
            $accumulated = $this->valuesOf($rows, 'accumulated_average');
            $kind = $scopes[$period['id']] ?? 'period';

            $byDomain = [];

            foreach ($domains as $domain) {
                $values = [];
                $running = [];

                foreach ($rows as $row) {
                    $cell = $this->domainCell($row, $domain['id']);

                    if ($cell === null) {
                        continue;
                    }

                    if ($cell['weighted_average'] !== null) {
                        $values[] = (string) $cell['weighted_average'];
                    }

                    if (($cell['accumulated_average'] ?? null) !== null) {
                        $running[] = (string) $cell['accumulated_average'];
                    }
                }

                $byDomain[] = [
                    'domain_id' => $domain['id'],
                    'average' => $this->mean($values),
                    'accumulated_average' => $this->mean($running),
                    'students_with_result' => count($values),
                ];
            }

            $series[] = [
                'period_id' => $period['id'],
                'label' => $period['label'],
                'sequence' => $period['sequence'],
                'class_average' => $this->mean($overall),
                'accumulated_average' => $this->mean($accumulated),
                // The figure that answered for the class at THIS moment — the
                // line a «como foi o ano» sparkline should draw (§22).
                'primary_average' => $kind === 'accumulated' ? $this->mean($accumulated) : $this->mean($overall),
                'primary_kind' => $kind,
                'students_with_result' => count($overall),
                'domains' => $byDomain,
            ];
        }

        return $series;
    }

    /**
     * One row per student for the heatmap and the individual reading.
     *
     * Everything here is copied from the progression, not derived: the values,
     * the mentions, the movement, the self-assessment and the decision all keep
     * whatever the canonical read model said about them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $progressionStudents
     * @param  list<array<string, mixed>>  $previousRows
     * @param  array<int, string>  $scopes
     * @return list<array<string, mixed>>
     */
    protected function students(
        array $rows,
        ?Scale $scale,
        array $progressionStudents = [],
        array $previousRows = [],
        string $primaryKind = 'period',
        array $scopes = [],
        ?string $previousPrimaryKind = null,
    ): array {
        // Each student's own line through the year, lifted whole from the
        // progression: their period figures, their movement and their domains,
        // exactly as the canonical model wrote them. Nothing is recomputed and
        // no query is added — the data was already in hand.
        $series = [];

        foreach ($progressionStudents as $student) {
            $series[(int) $student['enrollment_id']] = array_map(fn (array $entry): array => [
                'period_id' => $entry['period_id'],
                'period_label' => $entry['period_label'],
                'weighted_average' => $entry['weighted_average'],
                'accumulated_average' => $entry['accumulated_average'],
                // The figure that answered for this student AT THAT MOMENT, so
                // an individual timeline reads as one continuous line instead
                // of switching meaning halfway through (§29).
                'primary_average' => ($scopes[$entry['period_id']] ?? 'period') === 'accumulated'
                    ? $entry['accumulated_average']
                    : $entry['weighted_average'],
                'coverage_warning' => (bool) $entry['coverage_warning'],
                'evolution' => $entry['evolution'],
                'domains' => array_map(fn (array $cell): array => [
                    'domain_id' => $cell['domain_id'],
                    'weighted_average' => $cell['weighted_average'],
                    'mention' => $cell['mention'],
                ], $entry['domains']),
            ], $student['periods']);
        }

        $before = [];

        foreach ($previousRows as $row) {
            $before[(int) $row['enrollment_id']] = $row;
        }

        $students = [];

        foreach ($rows as $row) {
            $period = $row['period'];

            $students[] = [
                'enrollment_id' => $row['enrollment_id'],
                'name' => $row['name'],
                'class_number' => $row['class_number'],
                'weighted_average' => $period['weighted_average'] ?? null,
                'accumulated_average' => $period['accumulated_average'] ?? null,
                // The same two figures, named by which answers for them here.
                'primary_average' => $this->primaryValueOf($row, $primaryKind),
                'supplementary_average' => $this->supplementaryValueOf($row, $primaryKind),
                'coverage_warning' => $period['coverage_warning'] ?? false,
                'evolution' => $period['evolution'] ?? null,
                // Their own movement in the continuous assessment, beside the
                // period-against-period one. Different question, different key.
                'continuous_evolution' => $this->continuousMovementOf(
                    $row,
                    $before[(int) $row['enrollment_id']] ?? null,
                    $primaryKind,
                    $previousPrimaryKind,
                ),
                'band' => $this->bandPayload($this->bandOf($row, $scale)),
                // The grade, as a mention. Kept beside the calculated band and
                // never in place of it: a selection made on the assigned
                // distribution must highlight the students who were GRADED
                // there, not the ones whose average happens to land there (§14).
                'assigned' => $this->assignedLevelOf($row, $scale),
                'domains' => $period['domains'] ?? [],
                'self_assessment' => $period['self_assessment'] ?? null,
                'classification' => $period['classification'] ?? null,
                // Their whole year, for the individual panel (§3, §5).
                'series' => $series[$row['enrollment_id']] ?? [],
                // Which side of the scale they were on and are on now — the
                // same word the class counts are grouped by, so a card and a
                // student can never disagree about who crossed.
                'transition' => $this->transitionOf($row, $before[(int) $row['enrollment_id']] ?? null, $scale),
            ];
        }

        return $students;
    }

    /**
     * The band a student's accumulated average falls in.
     *
     * The ACCUMULATED figure, because that is what the Quadro Síntese already
     * places a mention on. Banding the standalone one instead would give the
     * same student two different mentions on two screens (§0).
     *
     * @param  array<string, mixed>  $row
     */
    protected function bandOf(array $row, ?Scale $scale): ?ScaleLevel
    {
        return $this->proposals->bandFor($scale, $row['period']['accumulated_average'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    protected function mostCommonBand(array $rows, ?Scale $scale): ?array
    {
        $counts = [];
        $levels = [];

        foreach ($rows as $row) {
            $level = $this->bandOf($row, $scale);

            if ($level === null) {
                continue;
            }

            $counts[$level->id] = ($counts[$level->id] ?? 0) + 1;
            $levels[$level->id] = $level;
        }

        if ($counts === []) {
            return null;
        }

        $highest = max($counts);
        $tied = array_keys($counts, $highest, true);

        // A tie has no single most common band, and naming one of them would be
        // picking by insertion order and calling it a finding.
        if (count($tied) > 1) {
            return null;
        }

        return [
            ...$this->bandPayload($levels[$tied[0]]),
            'count' => $highest,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function bandPayload(?ScaleLevel $level): ?array
    {
        return $level === null ? null : [
            'scale_level_id' => (int) $level->id,
            'code' => (string) $level->code,
            'label' => (string) $level->label,
            'sequence' => (int) $level->sequence,
            'is_negative' => (bool) $level->is_negative,
        ];
    }

    /**
     * The scale's own bands, so the browser can tone a chart without ever
     * deciding what a band means.
     *
     * @return array<string, mixed>|null
     */
    protected function scalePayload(?Scale $scale): ?array
    {
        if ($scale === null) {
            return null;
        }

        $bands = [];

        foreach ($scale->levels->sortBy('sequence') as $level) {
            $bands[] = [
                'label' => (string) $level->label,
                'sequence' => (int) $level->sequence,
                'is_negative' => (bool) $level->is_negative,
            ];
        }

        return [
            'name' => (string) $scale->name,
            'kind' => (string) $scale->kind,
            'bands' => $bands,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function domainCell(array $row, int $domainId): ?array
    {
        foreach ($row['period']['domains'] ?? [] as $cell) {
            if ($cell['domain_id'] === $domainId) {
                return $cell;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    protected function valuesOf(array $rows, string $key): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = $row['period'][$key] ?? null;

            if ($value !== null) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * The mean, or null when there was nothing to average.
     *
     * Null rather than zero, all the way out to the screen: a class with no
     * results does not average zero, it has no average (§37).
     *
     * @param  list<string>  $values
     */
    protected function mean(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $total = '0';

        foreach ($values as $value) {
            $total = Bc::add($total, Bc::of($value));
        }

        return Bc::round(Bc::div($total, (string) count($values)), self::PRECISION, 'half_up');
    }

    protected function percentage(int $count, int $total): ?string
    {
        if ($total === 0) {
            return null;
        }

        return Bc::round(Bc::mul(Bc::div((string) $count, (string) $total), '100'), self::PRECISION, 'half_up');
    }

    /**
     * @param  list<array<string, mixed>>  $periods
     * @param  list<array{id: int, name: string}>  $domains
     * @return array<string, mixed>
     */
    protected function empty(array $periods, array $domains): array
    {
        return [
            'periods' => $periods,
            'selected_period' => null,
            'previous_period' => null,
            'domains' => $domains,
            'scale' => null,
            'primary' => [
                'kind' => 'period', 'label' => 'Média Ponderada', 'short_label' => 'Média da turma',
                'caption' => null, 'supplementary_label' => null, 'has_supplementary' => false, 'scopes' => [],
            ],
            'summary' => [
                'students_total' => 0,
                'students_with_result' => 0,
                'students_without_result' => 0,
                'class_average' => null,
                'accumulated_average' => null,
                'primary_average' => null,
                'supplementary_average' => null,
                'partial_coverage_count' => 0,
                'most_common_band' => null,
                'success' => [
                    'succeeded' => 0, 'failed' => 0, 'unplaced' => 0, 'without_classification' => 0,
                    'placed' => 0, 'rate' => null, 'failure_rate' => null,
                ],
            ],
            'evolution' => [
                'progressed' => 0, 'stable' => 0, 'regressed' => 0, 'no_comparison' => 0,
                'comparable' => 0, 'average_change' => null,
                'percentages' => ['progressed' => null, 'stable' => null, 'regressed' => null, 'no_comparison' => null],
                'transitions' => [
                    'failure_to_success' => 0, 'success_to_failure' => 0,
                    'success_to_success' => 0, 'failure_to_failure' => 0,
                    'unclassified' => 0, 'no_assigned_classification' => 0, 'comparable' => 0,
                    'percentages' => [
                        'failure_to_success' => null, 'success_to_failure' => null,
                        'success_to_success' => null, 'failure_to_failure' => null,
                    ],
                    'share_of_class' => ['unclassified' => null, 'no_assigned_classification' => null],
                ],
            ],
            'continuous_evolution' => [
                'progressed' => 0, 'stable' => 0, 'regressed' => 0, 'no_comparison' => 0,
                'comparable' => 0, 'average_change' => null,
                'percentages' => ['progressed' => null, 'stable' => null, 'regressed' => null, 'no_comparison' => null],
            ],
            'assigned_distribution' => [
                'bands' => [], 'classified' => 0, 'unplaced' => 0, 'without_classification' => 0,
            ],
            'distribution' => [],
            'domain_statistics' => [],
            'period_series' => [],
            'students' => [],
        ];
    }
}
