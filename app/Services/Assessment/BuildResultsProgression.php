<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationOutcome;
use App\Domain\Assessment\DomainOutcome;
use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentStatus;
use App\Support\Assessment\AssessmentCutoff;
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
        protected ScaleProposalResolver $proposals,
        protected SelfAssessmentReading $selfAssessments,
    ) {}

    /**
     * What a progression was built FOR, so a reader can tell whether the one it
     * holds answers the question it is about to ask.
     *
     * TWO THINGS AND ONLY TWO DECIDE THE ANSWER: the class, and the cutoff. The
     * period does not — a progression covers the whole year and callers pick a
     * period out of it afterwards.
     *
     * It exists because BuildClassStatistics may now be HANDED a progression
     * instead of building one, and a progression built «até 15 de novembro»
     * placed beside a screen that says «hoje» would be a photograph presented as
     * the present. Stamping the context is what turns that from a documented
     * risk into an impossible one.
     *
     * @return array{class_id: int, cutoff: string|null}
     */
    public static function contextFor(SchoolClass $class, ?AssessmentCutoff $cutoff = null): array
    {
        return [
            'class_id' => (int) $class->getKey(),
            'cutoff' => ($cutoff ?? AssessmentCutoff::none())->toIso(),
        ];
    }

    /**
     * @return array{
     *     periods: list<array<string, mixed>>,
     *     domains: list<array{id: int, name: string}>,
     *     students: list<array<string, mixed>>,
     *     context: array{class_id: int, cutoff: string|null},
     * }
     */
    public function for(SchoolClass $class, ?AssessmentCutoff $cutoff = null): array
    {
        $cutoff ??= AssessmentCutoff::none();
        $context = self::contextFor($class, $cutoff);

        $periods = AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')
            ->get();

        if ($periods->isEmpty()) {
            return ['periods' => [], 'domains' => [], 'students' => [], 'context' => $context];
        }

        // One pass per period for each scope. The calculator is the canonical
        // source of both, and asking it is cheaper than keeping a second
        // implementation of the rule in agreement with it.
        $standalone = [];
        $accumulated = [];

        foreach ($periods as $period) {
            $standalone[$period->id] = $this->byEnrollment($this->calculator->forPeriod($class, $period, $cutoff));
            $accumulated[$period->id] = $this->byEnrollment($this->calculator->forAccumulated($class, $period, $cutoff));
        }

        $enrollments = $this->enrollmentsOf($class);
        $domains = $this->domainsIn($standalone, $accumulated);

        // The scale and its rounding, resolved ONCE for the whole page: both the
        // proposals and the per-domain mentions are read on it, and asking the
        // profile version per row would be a query for an answer that never
        // changes (§17).
        $version = $class->profileVersion;
        $scale = $version?->scale()->with('levels')->first();
        $roundingMode = $version->rounding_mode ?? 'half_up';
        $roundingScale = $version->rounding_scale ?? 0;

        $selfAssessments = $this->selfAssessments($class, $periods, $cutoff);
        $classifications = $this->classifications($periods, $scale, $roundingMode, $roundingScale, $cutoff);

        $students = [];

        foreach ($enrollments as $enrollmentId => $enrollment) {
            $students[] = [
                'enrollment_id' => $enrollmentId,
                // O ULID DA MATRÍCULA. Esta leitura passou a ser endereçável:
                // cada valor acumulado abre a decomposição que o explica, e uma
                // rota leva o ulid e nunca o id sequencial (§11.2).
                'enrollment_ulid' => (string) $enrollment->ulid,
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
                    $scale,
                ),
            ];
        }

        // Built by hand rather than mapped: a list is what travels to the
        // browser, and `map()->all()` keeps whatever keys the collection had.
        $periodRows = [];

        // QUAL DELAS FECHA O ANO, dito pelo servidor e pela mesma função que a
        // decisão final por domínio já usa. O Quadro Síntese precisa disto para
        // não repetir, na síntese da última unidade, a proposta e o nível que o
        // bloco final já mostra — e uma regra de apresentação assente em «a
        // última do array» seria a mesma pergunta respondida onde
        // `ContinuousAssessment::finalUnitOf()` não chega: um ano com uma
        // unidade acrescentada ao fim limparia a síntese da unidade errada, sem
        // erro nenhum (§43).
        $finalUnit = ContinuousAssessment::finalUnitOf($class);

        foreach ($periods as $period) {
            $periodRows[] = [
                'id' => (int) $period->id,
                'ulid' => (string) $period->ulid,
                'label' => (string) $period->label,
                'sequence' => (int) $period->sequence,
                'closes_the_year' => $finalUnit !== null
                    && (int) $finalUnit->getKey() === (int) $period->id,
            ];
        }

        $domainRows = [];

        foreach ($domains as $domain) {
            // O ulid ao lado do id pela mesma razão que a matrícula o leva: a
            // decomposição de um acumulado é endereçada por domínio, e uma rota
            // nunca leva um id sequencial (§11.2).
            $domainRows[] = ['id' => (int) $domain->id, 'ulid' => (string) $domain->ulid, 'name' => (string) $domain->name];
        }

        return [
            'periods' => $periodRows,
            'domains' => $domainRows,
            'students' => $students,
            // Additive, and read by nobody who does not need it: what this
            // particular build is an answer about.
            'context' => $context,
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
     * @param  array<string, array<string, mixed>>  $classifications
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
        ?Scale $scale = null,
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
                'domains' => $this->domainRows($domains, $own, $running, $before, $selfAssessment, $scale),
                'self_assessment' => $this->globalSelfAssessment($selfAssessment),
                'classification' => $classifications[$enrollmentId.':'.$period->id] ?? null,
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
        ?Scale $scale = null,
    ): array {
        $find = fn (?CalculationOutcome $outcome, int $domainId): ?DomainOutcome => $outcome === null
            ? null
            : collect($outcome->domains)->firstWhere('domainId', $domainId);

        $rows = [];

        foreach ($domains as $domain) {
            $ownDomain = $find($own, $domain->id);
            $previousDomain = $find($before, $domain->id);
            $accumulated = $find($running, $domain->id)?->normalizedValue;

            $rows[] = [
                'domain_id' => (int) $domain->id,
                'weighted_average' => $ownDomain?->normalizedValue,
                'accumulated_average' => $accumulated,
                // Where the year-to-date figure of THIS domain falls on the
                // profile's scale. Read through the resolver that owns that
                // translation, so a later export maps from the same band.
                'mention' => $this->mentionFor($scale, $accumulated),
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
     * The qualitative mention of an accumulated figure, named by the scale
     * itself and never by anything written here.
     *
     * Carries the band's own IDENTITY, not only its words: the id, the code and
     * the rank. A later export to another system maps from those, never from the
     * label, which is authored text somebody will one day translate or rewrite.
     *
     * A scale with no bands configured has no mention to give, and null is the
     * truthful answer (§10.4).
     *
     * @return array<string, mixed>|null
     */
    protected function mentionFor(?Scale $scale, ?string $accumulated): ?array
    {
        $level = $this->proposals->bandFor($scale, $accumulated);

        return $level === null ? null : [
            'scale_level_id' => (int) $level->id,
            'code' => (string) $level->code,
            'label' => (string) $level->label,
            'sequence' => (int) $level->sequence,
            'is_negative' => (bool) $level->is_negative,
        ];
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
     * READ THROUGH THE ONE READER (`SelfAssessmentReading`), which Pautas de
     * Avaliação also uses. Two copies of this is how two screens end up
     * disagreeing about what the same student said about themselves.
     *
     * @return array<string, mixed>|null
     */
    protected function globalSelfAssessment(?SelfAssessment $selfAssessment): ?array
    {
        return $this->selfAssessments->global($selfAssessment);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function levelOf(?SelfAssessment $selfAssessment, int $domainId): ?array
    {
        return $this->selfAssessments->forDomain($selfAssessment, $domainId);
    }

    /**
     * What Lapispro proposed and what the teacher decided, kept apart.
     *
     * @return array<string, mixed>
     */
    protected function classificationRow(
        Classification $classification,
        ?Scale $scale,
        string $roundingMode,
        int $roundingScale,
        AssessmentCutoff $cutoff,
    ): array {
        $level = fn ($scaleLevel): ?array => $scaleLevel === null ? null : [
            // The band's own identity, so a reader can GROUP by it rather than
            // by the words on it — two scales may both call a level «Bom».
            'scale_level_id' => (int) $scaleLevel->id,
            'code' => $scaleLevel->code,
            'label' => $scaleLevel->label,
            'sequence' => $scaleLevel->sequence,
            'is_negative' => (bool) $scaleLevel->is_negative,
        ];

        // A DECISION TAKEN AFTER THE CUTOFF HAD NOT BEEN TAKEN YET.
        //
        // The row itself already survived the query — it existed as a proposal
        // by then. What must not travel is the decision written onto it later:
        // showing January's grade inside November's photograph would put words
        // in the teacher's mouth, dated to a day they had not said them.
        //
        // So the row is presented as it stood: proposed, undecided, and with no
        // affordance to act, because this is history and not a working screen.
        $decided = $cutoff->covers($classification->confirmed_at);

        if (! $decided) {
            return [
                'ulid' => $classification->ulid,
                'status' => ClassificationStatus::Proposed->value,
                'can_confirm' => false,
                'can_change' => false,
                'is_published' => false,
                'proposal' => $this->proposals->resolve(
                    $scale,
                    $classification->proposed_scale_level_id,
                    $classification->proposed_normalized_value,
                    $classification->proposed_value,
                    $roundingMode,
                    $roundingScale,
                )->toPayload(),
                'proposed' => $level($classification->proposedScaleLevel),
                'final' => null,
                'final_value' => null,
                'differs_from_proposal' => false,
            ];
        }

        return [
            'ulid' => $classification->ulid,
            'status' => $classification->status->value,
            // Read from the status, so the screen that offers the decision and
            // the screen that manages it can never disagree about who may still
            // touch it. Stated here rather than re-derived in each browser.
            'can_confirm' => $classification->status === ClassificationStatus::Proposed,
            'can_change' => $classification->status->allowsDecision(),
            'is_published' => $classification->status->isPublished(),
            // The proposal read on the profile's own scale, through the one
            // service that owns that translation. `proposed` below is the band
            // it matched, which is null on a scale that has no bands — this is
            // what a screen shows, on every kind of scale.
            'proposal' => $this->proposals->resolve(
                $scale,
                $classification->proposed_scale_level_id,
                $classification->proposed_normalized_value,
                $classification->proposed_value,
                $roundingMode,
                $roundingScale,
            )->toPayload(),
            'proposed' => $level($classification->proposedScaleLevel),
            // The decision. Never filled in from the proposal by this service or
            // by any other: the teacher decides, the system proposes (§3.3).
            'final' => $level($classification->finalScaleLevel),
            // The decision written as a bare number, which is what a numeric
            // scale takes. Carried beside the level rather than instead of it,
            // because on a levelled scale both are set and the level is the
            // more specific statement.
            'final_value' => $classification->final_value,
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
    protected function selfAssessments(SchoolClass $class, Collection $periods, AssessmentCutoff $cutoff): array
    {
        // `submitted_at` is when the student said it. A cutoff leaves out what
        // they had not said yet — never what they had not yet been asked.
        $query = SelfAssessment::query()
            ->whereIn('academic_period_id', $periods->pluck('id'))
            ->whereHas('enrollment', fn ($inner) => $inner->where('class_id', $class->id))
            ->whereIn('status', [SelfAssessmentStatus::Submitted, SelfAssessmentStatus::Reviewed])
            ->with(['responses.question', 'responses.scaleLevel']);

        $cutoff->applyTo($query, 'submitted_at');

        $assessments = $query->get();

        $byKey = [];

        foreach ($assessments as $assessment) {
            $byKey[$assessment->enrollment_id.':'.$assessment->academic_period_id] = $assessment;
        }

        return $byKey;
    }

    /**
     * Every live classification of these periods, already read as a payload.
     *
     * The scale and its rounding are resolved ONCE here, not per row: the
     * proposal on an interval scale is derived from the normalized value, and
     * asking the profile version for that translation on every student would be
     * a query per row for an answer that never changes (§17).
     *
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<string, array<string, mixed>>
     */
    protected function classifications(
        Collection $periods,
        ?Scale $scale,
        string $roundingMode,
        int $roundingScale,
        AssessmentCutoff $cutoff,
    ): array {
        $query = Classification::query()
            ->whereIn('academic_period_id', $periods->pluck('id'))
            ->where('scope', ClassificationScope::Period)
            ->whereNull('superseded_by_id')
            ->with(['proposedScaleLevel', 'finalScaleLevel']);

        // A PROPOSAL EXISTS FROM THE MOMENT ITS ROW WAS WRITTEN.
        //
        // There is no `proposed_at` column because there does not need to be:
        // ProposeClassifications creates the row, so the row IS the proposal and
        // `created_at` is its semantic date rather than bookkeeping. A proposal
        // generated in January simply did not exist in November, and a
        // photograph that showed it would be showing something that had not
        // happened.
        //
        // Whether the DECISION on a surviving row had been taken by then is a
        // separate question, answered per row in classificationRow().
        $cutoff->applyTo($query, 'created_at');

        $classifications = $query->get();

        $byKey = [];

        foreach ($classifications as $classification) {
            $byKey[$classification->enrollment_id.':'.$classification->academic_period_id] = $this->classificationRow(
                $classification,
                $scale,
                $roundingMode,
                $roundingScale,
                $cutoff,
            );
        }

        return $byKey;
    }
}
