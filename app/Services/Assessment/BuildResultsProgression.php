<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationOutcome;
use App\Domain\Assessment\DomainOutcome;
use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentStatus;
use Illuminate\Support\Collection;

/**
 * The class read along its periods, instead of one period at a time.
 *
 * Everything here is ASSEMBLED and nothing is computed: the weighted average of
 * a period and the accumulated one both come from ClassResultsCalculator, which
 * is where the canonical rule already lives. What was missing was only the
 * longitudinal layer — holding two periods side by side so that progress can be
 * read at all (§23).
 *
 * THE ONE ARITHMETIC THIS DOES DO is the subtraction, and it is deliberately
 * narrow: evolution is the difference between the STANDALONE weighted average
 * of this period and the STANDALONE one of the period before. Never between
 * accumulated figures — a class that went 55% then 75% has improved by twenty
 * points, and comparing the accumulated 65% against the 55% would report ten,
 * which is true of nothing anybody asked about (§9).
 *
 * Reusable on purpose. Estatística, Evolução do Aluno and Relatórios all want
 * this same shape later, and none of them should have to re-derive it (§26).
 */
class BuildResultsProgression
{
    /**
     * Decimal places the difference is judged at.
     *
     * The same precision the results are shown in, so that «igual» on screen is
     * «igual» here. Comparing the raw six-decimal figures would report movement
     * nobody can see, which is how a grid ends up full of arrows that mean
     * nothing (§13).
     */
    public const PRECISION = 1;

    public function __construct(
        protected ClassResultsCalculator $calculator,
    ) {}

    /**
     * @return array{
     *     periods: list<array<string, mixed>>,
     *     domains: list<array{id: int, name: string}>,
     *     students: list<array<string, mixed>>,
     * }
     */
    public function for(SchoolClass $class): array
    {
        $periods = AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')
            ->get();

        if ($periods->isEmpty()) {
            return ['periods' => [], 'domains' => [], 'students' => []];
        }

        // One pass per period for each scope. The calculator is the canonical
        // source of both, and asking it is cheaper than keeping a second
        // implementation of the rule in agreement with it.
        $standalone = [];
        $accumulated = [];

        foreach ($periods as $period) {
            $standalone[$period->id] = $this->byEnrollment($this->calculator->forPeriod($class, $period));
            $accumulated[$period->id] = $this->byEnrollment($this->calculator->forAccumulated($class, $period));
        }

        $enrollments = $this->enrollmentsOf($class);
        $domains = $this->domainsIn($standalone, $accumulated);

        $selfAssessments = $this->selfAssessments($class, $periods);
        $classifications = $this->classifications($periods);

        $students = [];

        foreach ($enrollments as $enrollmentId => $enrollment) {
            $students[] = [
                'enrollment_id' => $enrollmentId,
                'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
                'class_number' => $enrollment->class_number,
                'periods' => $this->periodsFor(
                    $enrollmentId,
                    $periods,
                    $standalone,
                    $accumulated,
                    $domains,
                    $selfAssessments,
                    $classifications,
                ),
            ];
        }

        // Built by hand rather than mapped: a list is what travels to the
        // browser, and `map()->all()` keeps whatever keys the collection had.
        $periodRows = [];

        foreach ($periods as $period) {
            $periodRows[] = [
                'id' => (int) $period->id,
                'ulid' => (string) $period->ulid,
                'label' => (string) $period->label,
                'sequence' => (int) $period->sequence,
            ];
        }

        $domainRows = [];

        foreach ($domains as $domain) {
            $domainRows[] = ['id' => (int) $domain->id, 'name' => (string) $domain->name];
        }

        return [
            'periods' => $periodRows,
            'domains' => $domainRows,
            'students' => $students,
        ];
    }

    /**
     * One entry per period, with the previous period's standalone figure
     * already compared against.
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @param  array<int, array<int, array<string, mixed>>>  $standalone
     * @param  array<int, array<int, array<string, mixed>>>  $accumulated
     * @param  Collection<int, Domain>  $domains
     * @param  array<string, SelfAssessment>  $selfAssessments
     * @param  array<string, Classification>  $classifications
     * @return list<array<string, mixed>>
     */
    protected function periodsFor(
        int $enrollmentId,
        Collection $periods,
        array $standalone,
        array $accumulated,
        Collection $domains,
        array $selfAssessments,
        array $classifications,
    ): array {
        $rows = [];
        $previousPeriodId = null;

        foreach ($periods as $period) {
            $own = $standalone[$period->id][$enrollmentId]['outcome'] ?? null;
            $running = $accumulated[$period->id][$enrollmentId]['outcome'] ?? null;
            $before = $previousPeriodId === null
                ? null
                : ($standalone[$previousPeriodId][$enrollmentId]['outcome'] ?? null);

            $selfAssessment = $selfAssessments[$enrollmentId.':'.$period->id] ?? null;
            $classification = $classifications[$enrollmentId.':'.$period->id] ?? null;

            $rows[] = [
                'period_id' => $period->id,
                'period_label' => $period->label,
                // «Média Ponderada» — this period's own evidence and nothing else.
                'weighted_average' => $own?->normalizedValue,
                // «Média Ponderada Acumulada» — whatever counts up to here, by
                // the profile version's own rule.
                'accumulated_average' => $running?->normalizedValue,
                'coverage_warning' => $own->coverageWarning ?? false,
                'evolution' => $this->evolution($before?->normalizedValue, $own?->normalizedValue),
                'domains' => $this->domainRows($domains, $own, $running, $before, $selfAssessment),
                'self_assessment' => $this->globalSelfAssessment($selfAssessment),
                'classification' => $this->classificationRow($classification),
            ];

            $previousPeriodId = $period->id;
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Domain>  $domains
     * @return list<array<string, mixed>>
     */
    protected function domainRows(
        Collection $domains,
        ?CalculationOutcome $own,
        ?CalculationOutcome $running,
        ?CalculationOutcome $before,
        ?SelfAssessment $selfAssessment,
    ): array {
        $find = fn (?CalculationOutcome $outcome, int $domainId): ?DomainOutcome => $outcome === null
            ? null
            : collect($outcome->domains)->firstWhere('domainId', $domainId);

        $rows = [];

        foreach ($domains as $domain) {
            $ownDomain = $find($own, $domain->id);
            $previousDomain = $find($before, $domain->id);

            $rows[] = [
                'domain_id' => (int) $domain->id,
                'weighted_average' => $ownDomain?->normalizedValue,
                'accumulated_average' => $find($running, $domain->id)?->normalizedValue,
                'coverage_warning' => $ownDomain->coverageWarning ?? false,
                'evolution' => $this->evolution($previousDomain?->normalizedValue, $ownDomain?->normalizedValue),
                // What the student said about THIS domain, kept beside what the
                // evidence says about it (§9 of the self-assessment decision).
                'self_assessment' => $this->levelOf($selfAssessment, $domain->id),
            ];
        }

        return $rows;
    }

    /**
     * The movement between two standalone figures, or nothing.
     *
     * Nothing is exactly right when either period has no comparable value: a
     * period with no evidence is not a zero, and inventing progress out of its
     * absence would be reading an absence as a result (§21).
     *
     * @return array{direction: string, points: string}|null
     */
    protected function evolution(?string $previous, ?string $current): ?array
    {
        if ($previous === null || $current === null) {
            return null;
        }

        $before = Bc::round(Bc::of($previous), self::PRECISION, 'half_up');
        $after = Bc::round(Bc::of($current), self::PRECISION, 'half_up');
        $difference = Bc::sub($after, $before);
        $comparison = Bc::compare($after, $before);

        return [
            'direction' => match (true) {
                $comparison > 0 => 'up',
                $comparison < 0 => 'down',
                default => 'flat',
            },
            // Percentage points, not per cent: the difference between two
            // percentages is not itself a percentage.
            'points' => Bc::round($difference, self::PRECISION, 'half_up'),
            'previous' => $before,
            'current' => $after,
        ];
    }

    /**
     * The student's own overall judgement — an ANSWER, never a calculation.
     *
     * The global question is the one belonging to no domain. It is identified
     * structurally, never by reading its wording, and it is never derived from
     * the per-domain answers: a student who rated four domains and skipped the
     * overall question has not made an overall judgement, and averaging the
     * four on their behalf would put words in their mouth (§11).
     *
     * @return array<string, mixed>|null
     */
    protected function globalSelfAssessment(?SelfAssessment $selfAssessment): ?array
    {
        if ($selfAssessment === null) {
            return null;
        }

        foreach ($selfAssessment->responses as $response) {
            $question = $response->question;

            // Identified by its stated ROLE. Not by its wording, which somebody
            // will rephrase, and not by its position, which changes the moment a
            // question is inserted — and would then silently reassign what every
            // answer already given meant.
            if ($question?->role !== SelfAssessmentQuestionRole::Global) {
                continue;
            }

            return $response->scaleLevel === null ? null : [
                'label' => $response->scaleLevel->label,
                'sequence' => $response->scaleLevel->sequence,
                'is_negative' => (bool) $response->scaleLevel->is_negative,
            ];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function levelOf(?SelfAssessment $selfAssessment, int $domainId): ?array
    {
        if ($selfAssessment === null) {
            return null;
        }

        foreach ($selfAssessment->responses as $response) {
            $question = $response->question;

            if ($question === null || $question->answer_kind !== 'scale') {
                continue;
            }

            if ($question->domain_id !== $domainId || $response->scaleLevel === null) {
                continue;
            }

            return [
                'label' => $response->scaleLevel->label,
                'sequence' => $response->scaleLevel->sequence,
                'is_negative' => (bool) $response->scaleLevel->is_negative,
            ];
        }

        return null;
    }

    /**
     * What LÁPIS proposed and what the teacher decided, kept apart.
     *
     * @return array<string, mixed>|null
     */
    protected function classificationRow(?Classification $classification): ?array
    {
        if ($classification === null) {
            return null;
        }

        $level = fn ($scaleLevel): ?array => $scaleLevel === null ? null : [
            'label' => $scaleLevel->label,
            'sequence' => $scaleLevel->sequence,
            'is_negative' => (bool) $scaleLevel->is_negative,
        ];

        return [
            'ulid' => $classification->ulid,
            'status' => $classification->status->value,
            'proposed' => $level($classification->proposedScaleLevel),
            // The decision. Never filled in from the proposal by this service or
            // by any other: the teacher decides, the system proposes (§3.3).
            'final' => $level($classification->finalScaleLevel),
            'differs_from_proposal' => $classification->final_scale_level_id !== null
                && $classification->proposed_scale_level_id !== null
                && $classification->final_scale_level_id !== $classification->proposed_scale_level_id,
        ];
    }

    /**
     * The class roll, in roll order, taken from the class itself.
     *
     * Deliberately NOT derived from the calculator's output. A class with no
     * assessment profile yet, and a student with no evidence in any period,
     * both produce no rows there — and a results screen that answered by
     * omitting the student would be saying something false about them. They
     * appear, with «—» against every column, which is the true answer (§21).
     *
     * @return array<int, Enrollment>
     */
    protected function enrollmentsOf(SchoolClass $class): array
    {
        $enrollments = [];

        $rows = Enrollment::query()
            ->where('class_id', $class->id)
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();

        foreach ($rows as $enrollment) {
            $enrollments[(int) $enrollment->id] = $enrollment;
        }

        return $enrollments;
    }

    /**
     * Every domain any period touched, named once.
     *
     * @param  array<int, array<int, array<string, mixed>>>  ...$sets
     * @return Collection<int, Domain>
     */
    protected function domainsIn(array ...$sets): Collection
    {
        $ids = [];

        foreach ($sets as $set) {
            foreach ($set as $rows) {
                foreach ($rows as $row) {
                    foreach ($row['outcome']->domains as $domain) {
                        $ids[$domain->domainId] = true;
                    }
                }
            }
        }

        return Domain::query()->whereIn('id', array_keys($ids))->orderBy('name')->get();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function byEnrollment(array $rows): array
    {
        $byEnrollment = [];

        foreach ($rows as $row) {
            $byEnrollment[(int) $row['enrollment']->id] = $row;
        }

        return $byEnrollment;
    }

    /**
     * Every self-assessment of this class, in one query rather than one per
     * student per period (§24).
     *
     * A draft is not a submission: what the student is still writing is not yet
     * what they said.
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<string, SelfAssessment>
     */
    protected function selfAssessments(SchoolClass $class, Collection $periods): array
    {
        $assessments = SelfAssessment::query()
            ->whereIn('academic_period_id', $periods->pluck('id'))
            ->whereHas('enrollment', fn ($query) => $query->where('class_id', $class->id))
            ->whereIn('status', [SelfAssessmentStatus::Submitted, SelfAssessmentStatus::Reviewed])
            ->with(['responses.question', 'responses.scaleLevel'])
            ->get();

        $byKey = [];

        foreach ($assessments as $assessment) {
            $byKey[$assessment->enrollment_id.':'.$assessment->academic_period_id] = $assessment;
        }

        return $byKey;
    }

    /**
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<string, Classification>
     */
    protected function classifications(Collection $periods): array
    {
        $classifications = Classification::query()
            ->whereIn('academic_period_id', $periods->pluck('id'))
            ->where('scope', ClassificationScope::Period)
            ->whereNull('superseded_by_id')
            ->with(['proposedScaleLevel', 'finalScaleLevel'])
            ->get();

        $byKey = [];

        foreach ($classifications as $classification) {
            $byKey[$classification->enrollment_id.':'.$classification->academic_period_id] = $classification;
        }

        return $byKey;
    }
}
