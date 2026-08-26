<?php

namespace App\Services\Assessment\Progress;

use App\Domain\Assessment\Bc;
use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\InterimAssessment;
use App\Models\Intervention;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\PrimaryResultScope;
use App\Services\Assessment\ScaleProposalResolver;
use App\Support\Assessment\DecisionScale;
use Illuminate\Support\Collection;

/**
 * One student's year, assembled from what LÁPIS already knows.
 *
 * NOT A SECOND ENGINE, AND NOT EVEN A SECOND OPINION (§1). Every academic figure
 * on this page was decided before this class was called: the weighted averages
 * by ClassResultsCalculator, the longitudinal shape by BuildResultsProgression,
 * the reading that answers at each moment by PrimaryResultScope, the class
 * averages and the movement by BuildClassStatistics. What is added here is
 * SELECTION and ARRANGEMENT — lifting one student out of the class read, putting
 * their moments in order, and placing beside them the records, the interventions
 * and the photographs that were never part of a results model.
 *
 * TWO CANONICAL LAYERS, ONE PASS OVER THE CLASS.
 * BuildResultsProgression holds the per-period classification and the per-period
 * self-assessment, which is what «3 → 4» and «Auto 3 | Atribuída 4» are made of.
 * BuildClassStatistics holds the aggregate layer — which reading answers, how the
 * continuous assessment moved, what the class averaged — and none of that may be
 * re-derived here without becoming a second answer to a question that has one.
 *
 * The progression is therefore built ONCE and handed to the statistics rather
 * than left to be built again inside it. Both layers are still canonical and
 * neither is reimplemented; what disappeared is the second walk over the same
 * year (§56).
 *
 * WHAT THIS REFUSES TO DO, throughout:
 *
 *   absence      is never a zero. A period with no evidence has a null, the
 *                chart shows a gap, and the coverage says «sem elementos
 *                avaliados» (§24, §52).
 *
 *   a proposal   is never a classification. When nothing was decided the answer
 *                is «sem classificação atribuída», never the proposal wearing
 *                its clothes (§16, §17).
 *
 *   a photograph is never rebuilt. An interim point is read out of its stored
 *                document; correcting a score today cannot move where November
 *                already is (§12, §57).
 *
 *   a coincidence is never a cause. Interventions and results share a timeline
 *                and nothing here relates them (§35).
 */
class BuildStudentProgress
{
    public function __construct(
        protected BuildResultsProgression $progression,
        protected BuildClassStatistics $statistics,
        protected PrimaryResultScope $scope,
        protected ClassResultsCalculator $calculator,
        protected ScaleProposalResolver $proposals,
    ) {}

    /**
     * @param  string|null  $reading  'accumulated' | 'period', or null for the canonical answer
     * @return array<string, mixed>
     */
    public function for(SchoolClass $class, Enrollment $enrollment, ?string $reading = null): array
    {
        // ONE PASS OVER THE CLASS, and both layers read from it. The
        // longitudinal model is needed here in its own right — the per-period
        // classification and self-assessment live only there — and the
        // aggregate layer is built ON it rather than beside it.
        $progression = $this->progression->for($class);
        $statistics = $this->statistics->for($class, progression: $progression);

        $periods = $this->periodsOf($class);
        $scopes = $this->scope->forClass($class, $progression['periods']);

        $line = $this->lineOf($progression, $enrollment);
        $row = $this->rowOf($statistics, $enrollment);

        // WHICH READING ANSWERS. The canonical one comes from the profile
        // version's own continuity flags; the teacher may look at the other, and
        // the toggle only exists where the two are different figures (§9, §10).
        $canonical = (string) ($statistics['primary']['kind'] ?? 'period');
        $hasSupplementary = (bool) ($statistics['primary']['has_supplementary'] ?? false);
        $reading = $hasSupplementary && in_array($reading, ['accumulated', 'period'], true)
            ? $reading
            : $canonical;

        $interims = $this->interimsOf($class, $enrollment);
        $moments = $this->moments($progression['periods'], $line, $interims, $scopes, $reading, $periods, $enrollment);

        return [
            'student' => $this->studentPayload($enrollment),
            // `schoolClass`, not `class`: this travels to a Vue template as a
            // prop name, and `class` is a reserved word in a JavaScript
            // expression — `class.label` parses as a class declaration. The
            // rest of the application already uses this name for the same
            // reason.
            'schoolClass' => $this->classPayload($class),
            'scale' => DecisionScale::for($class->profileVersion?->scale)->toPayload(),
            'bands' => $statistics['scale'] ?? null,
            'reading' => [
                'kind' => $reading,
                'canonical' => $canonical,
                'has_toggle' => $hasSupplementary,
                'label' => $reading === 'accumulated' ? 'Média Ponderada Acumulada' : 'Média Ponderada',
                'caption' => $reading === 'accumulated'
                    ? 'Resultado acumulado no ano letivo até ao momento.'
                    : 'Só os elementos realizados no período analisado.',
            ],
            'periods' => $this->periodPayload($progression['periods'], $scopes),
            'selectedPeriod' => $this->selectedPeriodPayload($statistics['selected_period'] ?? null, $periods),
            'headline' => $this->headline($row, $reading),
            'moments' => $moments,
            'classifications' => $this->classifications($line),
            'selfAssessments' => $this->selfAssessments($line),
            'domains' => $this->domains($progression['domains'], $row, $line),
            'sinceLast' => $this->sinceLast($line, $scopes, $reading, $periods),
            'classComparison' => $this->classComparison($statistics, $row, $reading),
            'records' => $this->records($class, $enrollment),
            'interventions' => $this->interventions($class, $enrollment),
            // The recent avaliações themselves, including this student's own
            // result when the canonical calculation can resolve one, so the
            // timeline names both what was applied and what actually changed.
            'recentInstruments' => $this->recentInstruments($class, $enrollment),
        ];
    }

    // ------------------------------------------------------------- quem é

    /**
     * @return array<string, mixed>
     */
    protected function studentPayload(Enrollment $enrollment): array
    {
        return [
            'ulid' => $enrollment->ulid,
            'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
            'class_number' => $enrollment->class_number,
            // A DATE THAT WAS NEVER RECORDED IS NOT INVENTED (§25). The flag and
            // the date are separate facts: a class may know somebody arrived
            // late without knowing the day.
            'is_late_entry' => (bool) $enrollment->is_late_entry,
            'enrolled_on' => $enrollment->enrolled_on->toDateString(),
            'left_on' => $enrollment->left_on?->toDateString(),
            'status' => $enrollment->status->value,
            'status_label' => $enrollment->status->label(),
            // «Já não integra a turma» is shown, and the history stays (§26).
            'is_current' => $enrollment->status->isCurrent(),
            'status_reason' => $enrollment->status_reason?->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function classPayload(SchoolClass $class): array
    {
        return [
            'ulid' => $class->ulid,
            'label' => $class->label,
            'subject' => $class->subject->name,
            'academic_year' => $class->academicYear->label,
            'has_profile' => $class->assessment_profile_version_id !== null,
            'scale_name' => $class->profileVersion?->scale?->name,
        ];
    }

    // --------------------------------------------------------- os resultados

    /**
     * This student's own periods, out of the class progression.
     *
     * @param  array<string, mixed>  $progression
     * @return list<array<string, mixed>>
     */
    protected function lineOf(array $progression, Enrollment $enrollment): array
    {
        foreach ($progression['students'] as $student) {
            if ((int) $student['enrollment_id'] === (int) $enrollment->getKey()) {
                return $student['periods'];
            }
        }

        return [];
    }

    /**
     * This student's row of the selected period, out of the class statistics.
     *
     * @param  array<string, mixed>  $statistics
     * @return array<string, mixed>|null
     */
    protected function rowOf(array $statistics, Enrollment $enrollment): ?array
    {
        foreach ($statistics['students'] ?? [] as $student) {
            if ((int) $student['enrollment_id'] === (int) $enrollment->getKey()) {
                return $student;
            }
        }

        return null;
    }

    /**
     * The four or five figures at the top, and not one more (§7, §47).
     *
     * EACH SAYS WHICH QUESTION IT ANSWERS. «65,2%» alone is not a fact — it is a
     * fact only once it says whether it is this period's work or the year's, and
     * the reading travels with it rather than in a caption somewhere else (§8).
     *
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>
     */
    protected function headline(?array $row, string $reading): array
    {
        $value = $row === null
            ? null
            : ($reading === 'accumulated' ? $row['accumulated_average'] : $row['weighted_average']);

        $supplementary = $row === null
            ? null
            : ($reading === 'accumulated' ? $row['weighted_average'] : $row['accumulated_average']);

        return [
            'value' => $value,
            'supplementary_value' => $supplementary,
            'band' => $row['band'] ?? null,
            'coverage' => $this->coverageOf($value, (bool) ($row['coverage_warning'] ?? false)),
            // The teacher's decision. Never the proposal standing in for it: a
            // report that showed one as the other would report a decision
            // nobody took (§16, §17).
            'classification' => $row['classification'] ?? null,
            'self_assessment' => $row['self_assessment'] ?? null,
            // Two movements, two names, never interchangeable — the period
            // against the period before, and the reading against the reading
            // before. BuildClassStatistics decided both.
            'evolution' => $row['evolution'] ?? null,
            'continuous_evolution' => $row['continuous_evolution'] ?? null,
        ];
    }

    /**
     * The word for how much of the picture is there (§24, §51, §52).
     *
     * Three states and no fourth. «Sem elementos avaliados» is not a bad result,
     * «cobertura parcial» is not an error, and neither is ever a zero.
     */
    protected function coverageOf(?string $value, bool $warning): string
    {
        return match (true) {
            $value === null => 'none',
            $warning => 'partial',
            default => 'complete',
        };
    }

    // ------------------------------------------------------------- momentos

    /**
     * Every real moment of this student's year, in order (§11).
     *
     * TWO KINDS, AND THEY ARE READ DIFFERENTLY. A period is read live from the
     * canonical model; an interim is read out of its own stored document and
     * never rebuilt, because a photograph records what was true then and
     * correcting a score today must not move it (§12, §57).
     *
     * NOTHING IS INVENTED TO FILL THE CHART. A period the student has no result
     * for carries a null and draws a gap. A period that ended before they
     * enrolled is not theirs at all and says so, which is what stops a late
     * arrival from looking like a collapse (§25, §73).
     *
     * @param  list<array<string, mixed>>  $periodRows
     * @param  list<array<string, mixed>>  $line
     * @param  Collection<int, InterimAssessment>  $interims
     * @param  array<int, string>  $scopes
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return list<array<string, mixed>>
     */
    protected function moments(
        array $periodRows,
        array $line,
        Collection $interims,
        array $scopes,
        string $reading,
        Collection $periods,
        Enrollment $enrollment,
    ): array {
        $byPeriod = [];

        foreach ($line as $entry) {
            $byPeriod[(int) $entry['period_id']] = $entry;
        }

        $moments = [];

        foreach ($periodRows as $period) {
            $periodId = (int) $period['id'];
            $model = $periods->get($periodId);

            // Interims belonging to this period come first, oldest first: they
            // happened inside it.
            foreach ($interims->where('academic_period_id', $periodId) as $interim) {
                $moments[] = $this->interimMoment($interim, $enrollment, $reading, $period);
            }

            $entry = $byPeriod[$periodId] ?? null;
            $enrolled = $model === null || $this->wasEnrolledDuring($enrollment, $model);

            $value = match (true) {
                ! $enrolled, $entry === null => null,
                $reading === 'accumulated' => $entry['accumulated_average'],
                default => $entry['weighted_average'],
            };

            $moments[] = [
                'key' => 'period-'.$periodId,
                'kind' => 'period',
                'label' => (string) $period['label'],
                'date' => $model?->ends_on->toDateString(),
                'value' => $value,
                // Which reading this point IS. Stated per point, because the
                // canonical answer changes at the first contributing period and
                // a line that quietly switched meaning halfway through would be
                // two charts drawn as one (§8, §10).
                'reading' => $reading === 'accumulated' && ($scopes[$periodId] ?? 'period') === 'accumulated'
                    ? 'accumulated'
                    : 'period',
                'coverage' => $this->coverageOf($value, (bool) ($entry['coverage_warning'] ?? false)),
                'classification' => $entry['classification'] ?? null,
                'self_assessment' => $entry['self_assessment'] ?? null,
                // Not «zero», not «regressão»: this period is not theirs.
                'before_enrolment' => ! $enrolled,
            ];
        }

        return $moments;
    }

    /**
     * One photograph, read out of its own document.
     *
     * A student who was not in the class when it was taken has no row in it, and
     * the moment carries a null rather than being handed today's figure — which
     * is the whole reason the document exists (§12).
     *
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    protected function interimMoment(
        InterimAssessment $interim,
        Enrollment $enrollment,
        string $reading,
        array $period,
    ): array {
        $student = null;

        foreach ((array) ($interim->snapshot['students'] ?? []) as $candidate) {
            if (is_array($candidate) && (int) ($candidate['enrollment_id'] ?? 0) === (int) $enrollment->getKey()) {
                $student = $candidate;

                break;
            }
        }

        $key = $reading === 'accumulated' ? 'accumulated_average' : 'weighted_average';
        $value = $student[$key] ?? null;

        return [
            'key' => 'interim-'.$interim->ulid,
            'kind' => 'interim',
            // The teacher's own name for the moment, never one rebuilt from a
            // date.
            'label' => $interim->name,
            'date' => $interim->reference_date->toDateString(),
            'period_label' => (string) $period['label'],
            'value' => $value,
            'reading' => $reading,
            'coverage' => $this->coverageOf(
                is_string($value) ? $value : null,
                (bool) ($student['coverage_warning'] ?? false),
            ),
            'classification' => $student['classification'] ?? null,
            'self_assessment' => $student['self_assessment'] ?? null,
            'before_enrolment' => false,
            // A photograph too old to have recorded this reading says so rather
            // than being given today's (§12).
            'is_available' => $student !== null && array_key_exists($key, $student),
        ];
    }

    /**
     * Whether this period is one this student was in the class for.
     *
     * Answered from the enrolment's own dates. A period that ended before they
     * arrived, or began after they left, is not a period they have a result in —
     * and the difference between «não tem resultado» and «não estava cá» is the
     * difference between a worry and a fact (§25, §26).
     */
    protected function wasEnrolledDuring(Enrollment $enrollment, AcademicPeriod $period): bool
    {
        if ($enrollment->enrolled_on->greaterThan($period->ends_on)) {
            return false;
        }

        return $enrollment->left_on === null || ! $enrollment->left_on->lessThan($period->starts_on);
    }

    /**
     * @return Collection<int, InterimAssessment>
     */
    protected function interimsOf(SchoolClass $class, Enrollment $enrollment): Collection
    {
        return InterimAssessment::query()
            ->where('class_id', $class->getKey())
            ->orderBy('reference_date')
            ->get();
    }

    // ------------------------------------------------- classificação e auto

    /**
     * The decisions, in sequence (§17, §18).
     *
     * ONLY WHAT WAS DECIDED. A period whose classification is still a proposal
     * contributes «sem classificação atribuída», and the proposal travels beside
     * it as secondary information rather than in its place (§16).
     *
     * The sequence is shown and never characterised: «3 → 4» is a fact, and
     * «melhorou significativamente» is a judgement nobody made (§18).
     *
     * @param  list<array<string, mixed>>  $line
     * @return list<array<string, mixed>>
     */
    protected function classifications(array $line): array
    {
        $rows = [];

        foreach ($line as $entry) {
            $classification = $entry['classification'] ?? null;
            $decided = in_array($classification['status'] ?? null, ['confirmed', 'published'], true);

            $rows[] = [
                'period_id' => (int) $entry['period_id'],
                'period_label' => (string) $entry['period_label'],
                'is_decided' => $decided,
                'assigned' => $decided ? ($classification['final'] ?? null) : null,
                'assigned_value' => $decided ? ($classification['final_value'] ?? null) : null,
                'is_published' => (bool) ($classification['is_published'] ?? false),
                // Secondary, always, and labelled as a proposal wherever it is
                // shown.
                'proposal' => $classification['proposal'] ?? null,
                'differs_from_proposal' => (bool) ($classification['differs_from_proposal'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * What the student said, beside what the teacher decided (§27, §29).
     *
     * NEVER ON THE SAME AXIS AS THE WEIGHTED AVERAGE. A self-assessment is an
     * answer on a scale of levels and an average is a calculated percentage;
     * drawing them as one series would be inventing a comparison the data does
     * not support (§29).
     *
     * THE DIFFERENCE IS ARITHMETIC AND NOTHING MORE. «Autoavaliou-se abaixo da
     * classificação atribuída» is a fact. Anything about confidence, awareness
     * or self-esteem is a diagnosis, and this module does not make them (§28).
     *
     * @param  list<array<string, mixed>>  $line
     * @return list<array<string, mixed>>
     */
    protected function selfAssessments(array $line): array
    {
        $rows = [];

        foreach ($line as $entry) {
            $self = $entry['self_assessment'] ?? null;
            $classification = $entry['classification'] ?? null;
            $decided = in_array($classification['status'] ?? null, ['confirmed', 'published'], true);
            $assigned = $decided ? ($classification['final'] ?? null) : null;

            // Comparable only when both are levels of the same scale. Two
            // numbers on a screen are not a comparison until something says
            // they measure the same thing.
            $comparison = null;

            if ($self !== null && $assigned !== null
                && isset($self['sequence'], $assigned['sequence'])) {
                $difference = (int) $self['sequence'] - (int) $assigned['sequence'];

                $comparison = [
                    'difference' => $difference,
                    'direction' => match (true) {
                        $difference > 0 => 'above',
                        $difference < 0 => 'below',
                        default => 'same',
                    },
                ];
            }

            $rows[] = [
                'period_id' => (int) $entry['period_id'],
                'period_label' => (string) $entry['period_label'],
                'self_assessment' => $self,
                'assigned' => $assigned,
                'comparison' => $comparison,
            ];
        }

        return $rows;
    }

    // -------------------------------------------------------------- domínios

    /**
     * Each domain, now and before (§20, §21, §22).
     *
     * MATCHED BY THE CANONICAL ID and never by the name, so a domain renamed
     * mid-year is one domain and two domains that happen to share a word are
     * two (§61).
     *
     * «NÃO COMPARÁVEL» IS A REAL ANSWER. A domain the student has no earlier
     * figure in — because it was added later, because they were not enrolled,
     * because nothing was assessed in it — has no variation, and BuildResults-
     * Progression already returns null for it. Nothing here turns that into a
     * zero, which would read as «did not move» (§22).
     *
     * @param  list<array{id: int, name: string}>  $domains
     * @param  array<string, mixed>|null  $row
     * @param  list<array<string, mixed>>  $line
     * @return array<string, mixed>
     */
    protected function domains(array $domains, ?array $row, array $line): array
    {
        $cells = [];

        foreach ((array) ($row['domains'] ?? []) as $cell) {
            $cells[(int) $cell['domain_id']] = $cell;
        }

        $rows = [];

        foreach ($domains as $domain) {
            $cell = $cells[(int) $domain['id']] ?? null;
            $value = $cell['accumulated_average'] ?? null;
            $standalone = $cell['weighted_average'] ?? null;

            $rows[] = [
                'domain_id' => (int) $domain['id'],
                'name' => (string) $domain['name'],
                'weighted_average' => $standalone,
                'accumulated_average' => $value,
                'mention' => $cell['mention'] ?? null,
                'self_assessment' => $cell['self_assessment'] ?? null,
                'evolution' => $cell['evolution'] ?? null,
                'coverage' => $this->coverageOf(
                    is_string($standalone) ? $standalone : null,
                    (bool) ($cell['coverage_warning'] ?? false),
                ),
                'series' => $this->domainSeries($line, (int) $domain['id']),
            ];
        }

        return [
            'rows' => $rows,
            'highlights' => $this->domainHighlights($rows),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $line
     * @return list<array<string, mixed>>
     */
    protected function domainSeries(array $line, int $domainId): array
    {
        $series = [];

        foreach ($line as $entry) {
            $found = null;

            foreach ($entry['domains'] as $cell) {
                if ((int) $cell['domain_id'] === $domainId) {
                    $found = $cell;

                    break;
                }
            }

            $series[] = [
                'period_id' => (int) $entry['period_id'],
                'period_label' => (string) $entry['period_label'],
                'weighted_average' => $found['weighted_average'] ?? null,
                'accumulated_average' => $found['accumulated_average'] ?? null,
            ];
        }

        return $series;
    }

    /**
     * The four facts a teacher would otherwise find by scanning the table (§23).
     *
     * FACTS, NOT FINDINGS. «O resultado mais baixo regista-se em Gramática» is
     * a reading of the table. «Tem dificuldades em Gramática» is a pedagogical
     * judgement, and it belongs to the teacher — this module names the cell and
     * stops (§23, §39).
     *
     * A tie has no single answer, and naming one of the tied domains would be
     * picking by array order and calling it a result.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     highest: array{domain_id: int, name: string, value: string}|null,
     *     lowest: array{domain_id: int, name: string, value: string}|null,
     *     largest_rise: array{domain_id: int, name: string, value: string}|null,
     *     largest_fall: array{domain_id: int, name: string, value: string}|null
     * }
     */
    protected function domainHighlights(array $rows): array
    {
        $withValue = array_values(array_filter(
            $rows,
            fn (array $row): bool => is_string($row['accumulated_average']),
        ));

        $withMovement = array_values(array_filter(
            $rows,
            fn (array $row): bool => is_array($row['evolution']) && $row['evolution']['direction'] !== 'flat',
        ));

        return [
            'highest' => $this->singleBest($withValue, fn (array $row): string => (string) $row['accumulated_average'], true),
            'lowest' => $this->singleBest($withValue, fn (array $row): string => (string) $row['accumulated_average'], false),
            'largest_rise' => $this->singleBest(
                array_values(array_filter($withMovement, fn (array $row): bool => $row['evolution']['direction'] === 'up')),
                fn (array $row): string => (string) $row['evolution']['points'],
                true,
            ),
            'largest_fall' => $this->singleBest(
                array_values(array_filter($withMovement, fn (array $row): bool => $row['evolution']['direction'] === 'down')),
                fn (array $row): string => (string) $row['evolution']['points'],
                false,
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): string  $value
     * @return array{domain_id: int, name: string, value: string}|null
     */
    protected function singleBest(array $rows, callable $value, bool $highest): ?array
    {
        if ($rows === []) {
            return null;
        }

        $best = null;
        $tied = false;

        foreach ($rows as $row) {
            if ($best === null) {
                $best = $row;

                continue;
            }

            $comparison = Bc::compare(Bc::of($value($row)), Bc::of($value($best)));

            if ($comparison === 0) {
                $tied = true;

                continue;
            }

            if (($highest && $comparison > 0) || (! $highest && $comparison < 0)) {
                $best = $row;
                $tied = false;
            }
        }

        return $tied ? null : [
            'domain_id' => (int) $best['domain_id'],
            'name' => (string) $best['name'],
            'value' => $value($best),
        ];
    }

    // -------------------------------------------------- desde o momento anterior

    /**
     * What changed since the last comparable moment (§37).
     *
     * FACTS IN A LIST, and the list is allowed to be empty. «61,3% → 66,7%» is
     * a statement about two readings. There is no «graças a», no «devido a» and
     * no «como consequência» anywhere in this payload or in the screen that
     * renders it, because nothing in this data supports one (§35, §37).
     *
     * @param  list<array<string, mixed>>  $line
     * @param  array<int, string>  $scopes
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<string, mixed>|null
     */
    protected function sinceLast(array $line, array $scopes, string $reading, Collection $periods): ?array
    {
        $withValue = array_values(array_filter(
            $line,
            fn (array $entry): bool => ($entry['weighted_average'] ?? null) !== null
                || ($entry['accumulated_average'] ?? null) !== null,
        ));

        if (count($withValue) < 2) {
            return null;
        }

        $current = $withValue[count($withValue) - 1];
        $previous = $withValue[count($withValue) - 2];

        $key = $reading === 'accumulated' ? 'accumulated_average' : 'weighted_average';

        return [
            'from_label' => (string) $previous['period_label'],
            'to_label' => (string) $current['period_label'],
            'from_date' => $periods->get((int) $previous['period_id'])?->ends_on->toDateString(),
            'to_date' => $periods->get((int) $current['period_id'])?->ends_on->toDateString(),
            'from' => $previous[$key] ?? null,
            'to' => $current[$key] ?? null,
            'classification_from' => $this->decidedLevel($previous['classification'] ?? null),
            'classification_to' => $this->decidedLevel($current['classification'] ?? null),
            'domains' => $this->sinceLastDomains($previous, $current),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $classification
     * @return array<string, mixed>|null
     */
    protected function decidedLevel(?array $classification): ?array
    {
        if (! in_array($classification['status'] ?? null, ['confirmed', 'published'], true)) {
            return null;
        }

        return $classification['final'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     * @return list<array<string, mixed>>
     */
    protected function sinceLastDomains(array $previous, array $current): array
    {
        $before = [];

        foreach ($previous['domains'] as $cell) {
            $before[(int) $cell['domain_id']] = $cell;
        }

        $rows = [];

        foreach ($current['domains'] as $cell) {
            // The evolution the canonical model already decided for this cell.
            // Recomputing the subtraction here would be a second answer to a
            // question BuildResultsProgression has answered (§1).
            if (($cell['evolution'] ?? null) === null) {
                continue;
            }

            $rows[] = [
                'domain_id' => (int) $cell['domain_id'],
                'evolution' => $cell['evolution'],
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------ comparação com a turma

    /**
     * The student beside the class (§40).
     *
     * SECONDARY, AND NEVER A RANKING. Two averages and the distance between
     * them. There is no position, no ordinal, no «3.º melhor aluno» — a class is
     * not a league table and this page will not turn it into one.
     *
     * The class figure is the one BuildClassStatistics already computed, on the
     * same reading, from the same students. A mean taken here would be a second
     * opinion about a number that has one (§1).
     *
     * @param  array<string, mixed>  $statistics
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>|null
     */
    protected function classComparison(array $statistics, ?array $row, string $reading): ?array
    {
        $key = $reading === 'accumulated' ? 'accumulated_average' : 'class_average';

        $classValue = $statistics['summary'][$key] ?? null;
        $studentValue = $row === null
            ? null
            : ($reading === 'accumulated' ? $row['accumulated_average'] : $row['weighted_average']);

        if (! is_string($classValue) || ! is_string($studentValue)) {
            return null;
        }

        return [
            'student' => $studentValue,
            'class' => $classValue,
            'students_with_result' => (int) ($statistics['summary']['students_with_result'] ?? 0),
            // Percentage POINTS, at the precision the rest of the application
            // reads at. The difference between two percentages is not itself a
            // percentage, and «igual» on screen has to be «igual» here.
            'difference' => Bc::round(
                Bc::sub(Bc::of($studentValue), Bc::of($classValue)),
                BuildResultsProgression::PRECISION,
                'half_up',
            ),
            'series' => $statistics['period_series'] ?? [],
        ];
    }

    // -------------------------------------------------------------- registos

    /**
     * This student's logbook, in time (§30, §31, §32).
     *
     * THEIRS ALONE. The class-wide entries — the ones with no enrolment — are
     * left out, because a page about one child that counted observations
     * written about twenty-six would attribute to them what was said about the
     * class.
     *
     * A RECORD IS A RECORD. Ten homework checks are ten records about one
     * student, and never «ten students»: the unit is stated so nothing on the
     * screen has to guess (§32).
     *
     * @return array<string, mixed>
     */
    protected function records(SchoolClass $class, Enrollment $enrollment): array
    {
        $records = EvidenceRecord::query()
            ->where('class_id', $class->getKey())
            ->where('enrollment_id', $enrollment->getKey())
            ->with('domain')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $rows = [];
        $counts = [];

        foreach ($records as $record) {
            $counts[$record->kind->value] = ($counts[$record->kind->value] ?? 0) + 1;

            $rows[] = [
                'ulid' => $record->ulid,
                'kind' => $record->kind->value,
                // The teacher's word for it, from the enum. Never a raw value
                // and never a code (§30, §33).
                'kind_label' => $record->kind->label(),
                'group' => $record->kind->group()->value,
                'occurred_at' => $record->occurred_at->toDateString(),
                'description' => $record->description,
                'domain' => $record->domain?->name,
                'severity' => $record->disciplinary_severity?->label(),
                'homework_status' => $record->homework_status?->label(),
                'participation_level' => $record->participation_level?->label(),
            ];
        }

        $kinds = [];

        foreach ($counts as $kind => $count) {
            $kinds[] = [
                'value' => $kind,
                'label' => EvidenceKind::from($kind)->label(),
                'count' => $count,
            ];
        }

        usort($kinds, fn (array $first, array $second): int => $second['count'] <=> $first['count']);

        return [
            'total' => count($rows),
            // The filters offered are the kinds this student actually has, from
            // the system's own enum — never a list written by hand (§30).
            'kinds' => $kinds,
            'rows' => $rows,
        ];
    }

    /**
     * What was DONE, as opposed to what was observed (§33, §34).
     *
     * CONCLUDED ONES TOO. A view of a year that showed only what is still
     * running would be a view of today, and the point of this page is the
     * history (§34).
     *
     * A ROW WITH NO TYPE SHOWS NO TYPE. Never `legacy`, never `null`, never an
     * internal code — the title is the teacher's own words and stands alone,
     * and the category chip is simply absent (§33).
     *
     * @return array<string, mixed>
     */
    protected function interventions(SchoolClass $class, Enrollment $enrollment): array
    {
        $interventions = Intervention::query()
            ->where('class_id', $class->getKey())
            ->where(fn ($query) => $query
                ->where('enrollment_id', $enrollment->getKey())
                ->orWhereNull('enrollment_id'))
            ->with(['domain', 'reviews'])
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->get();

        $rows = [];

        foreach ($interventions as $intervention) {
            $rows[] = [
                'ulid' => $intervention->ulid,
                // The strategy the teacher named, falling back to the title and
                // then to the catalogue type. Never a code (§35).
                'title' => $intervention->displayTitle(),
                // Absent rather than falsified when the row predates the type
                // column.
                'type' => $intervention->intervention_type?->label(),
                // The three questions the timeline can show without becoming a
                // second detail screen: what motivated it, what it was for, and
                // what the teacher has observed so far. All null-safe — an older
                // intervention has none of them, and shows none (§4, §30).
                'motive' => $intervention->motive_label,
                'objective' => $intervention->objective,
                'effectiveness' => $intervention->currentEffectiveness()?->shortLabel(),
                'last_followup_on' => $intervention->reviews->first()?->reviewed_on->toDateString(),
                'followup_count' => $intervention->reviews->count(),
                'status' => $intervention->status->label(),
                'is_concluded' => $intervention->status->value === 'concluded',
                'started_on' => $intervention->started_on->toDateString(),
                'concluded_on' => $intervention->concluded_on?->toDateString(),
                'domain' => $intervention->domain?->name,
                // «Dirigida a si» and «dirigida à turma» are different facts
                // about the same student, and the screen says which.
                'is_individual' => $intervention->enrollment_id !== null,
                // The date the teacher chose, and nothing else — never a rule
                // like "30 days without follow-up" (see Intervention::needsReview()).
                'needs_review' => $intervention->needsReview(),
                // Recuperação / Consolidação / Melhoria, and the teacher's own
                // frequency and indicador de acompanhamento. Absent on a row
                // recorded before this existed (§8) — never guessed.
                'purpose' => $intervention->purpose?->value,
                'purpose_label' => $intervention->purpose?->label(),
                'frequency' => $intervention->frequency,
                'tracking_indicator' => $intervention->tracking_indicator,
            ];
        }

        return [
            'total' => count($rows),
            'individual' => count(array_filter($rows, fn (array $row): bool => $row['is_individual'])),
            'needing_review' => count(array_filter($rows, fn (array $row): bool => $row['needs_review'])),
            'rows' => $rows,
        ];
    }

    /**
     * The class's own recent avaliações, with one student's own result
     * resolved through ClassResultsCalculator. The arithmetic stays in the
     * same CalculationEngine and frozen profile rules used everywhere else;
     * this method only asks for one outcome per instrument and compares the
     * resulting normalized values in chronological order.
     *
     * @return list<array<string, mixed>>
     */
    protected function recentInstruments(SchoolClass $class, Enrollment $enrollment): array
    {
        $instruments = Instrument::query()
            ->where('class_id', $class->getKey())
            ->with(['type', 'items.domainAllocations'])
            ->orderByDesc('applied_on')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $outcomes = $this->calculator->forInstruments($class, $enrollment, $instruments);
        $chronological = $instruments->sortBy([
            ['applied_on', 'asc'],
            ['id', 'asc'],
        ])->values();
        $byInstrument = [];
        $previous = null;

        foreach ($chronological as $instrument) {
            $outcome = $outcomes[(int) $instrument->getKey()] ?? null;
            $value = $outcome?->normalizedValue === null
                ? null
                : Bc::round(Bc::of($outcome->normalizedValue), BuildResultsProgression::PRECISION, 'half_up');
            $level = $this->proposals->bandFor($class->profileVersion?->scale, $outcome?->normalizedValue);

            $byInstrument[(int) $instrument->getKey()] = [
                'result' => $value,
                'scale_label' => $level?->label,
                'evolution' => $this->instrumentEvolution($previous, $value),
            ];

            if ($value !== null) {
                $previous = $value;
            }
        }

        $rows = [];

        foreach ($instruments as $instrument) {
            $rows[] = [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'type' => $instrument->type?->name,
                'applied_on' => $instrument->applied_on->toDateString(),
                'status' => $instrument->status->label(),
                'counts_toward_classification' => (bool) $instrument->counts_toward_classification,
                ...($byInstrument[(int) $instrument->getKey()] ?? [
                    'result' => null,
                    'scale_label' => null,
                    'evolution' => null,
                ]),
            ];
        }

        return $rows;
    }

    /**
     * The same adjacent-result convention used by BuildResultsProgression:
     * rounded values, a signed difference in percentage points, and no answer
     * when either side is absent.
     *
     * @return array{direction: string, points: string}|null
     */
    protected function instrumentEvolution(?string $previous, ?string $current): ?array
    {
        if ($previous === null || $current === null) {
            return null;
        }

        $difference = Bc::sub(Bc::of($current), Bc::of($previous));
        $comparison = Bc::compare(Bc::of($current), Bc::of($previous));

        return [
            'direction' => match (true) {
                $comparison > 0 => 'up',
                $comparison < 0 => 'down',
                default => 'flat',
            },
            'points' => Bc::round($difference, BuildResultsProgression::PRECISION, 'half_up'),
        ];
    }

    // ---------------------------------------------------------------- suporte

    /**
     * @param  list<array<string, mixed>>  $periodRows
     * @param  array<int, string>  $scopes
     * @return list<array<string, mixed>>
     */
    protected function periodPayload(array $periodRows, array $scopes): array
    {
        $rows = [];

        foreach ($periodRows as $period) {
            $rows[] = [
                'id' => (int) $period['id'],
                'label' => (string) $period['label'],
                'sequence' => (int) $period['sequence'],
                'scope' => $scopes[(int) $period['id']] ?? 'period',
            ];
        }

        return $rows;
    }

    /**
     * Add dates already loaded for the selected period, so the query-free
     * insights layer can constrain qualitative records to the same window.
     *
     * @param  array<string, mixed>|null  $selected
     * @param  Collection<int, AcademicPeriod>  $periods
     * @return array<string, mixed>|null
     */
    protected function selectedPeriodPayload(?array $selected, Collection $periods): ?array
    {
        if ($selected === null) {
            return null;
        }

        $period = $periods->get((int) $selected['id']);

        return [
            ...$selected,
            'starts_on' => $period?->starts_on->toDateString(),
            'ends_on' => $period?->ends_on->toDateString(),
        ];
    }

    /**
     * @return Collection<int, AcademicPeriod>
     */
    protected function periodsOf(SchoolClass $class): Collection
    {
        return AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')
            ->get()
            ->keyBy('id');
    }
}
