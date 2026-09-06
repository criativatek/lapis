<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\Instrument;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentStatus;
use App\Models\StudentItemScore;
use App\Support\Assessment\CoverageWording;
use App\Support\Assessment\DecisionScale;
use App\Support\Export\InovarLevelOption;
use Illuminate\Support\Collection;

/**
 * «Preparar fecho» — a preparation layer OVER the Pauta de Avaliação, never a
 * second pauta and never a decision-maker (§3.3).
 *
 * NOTHING IS CALCULATED HERE. Every figure is read from the read model
 * BuildEvaluationSheet already produced for the screen the teacher is looking
 * at, plus a handful of flat aggregate queries (self-assessments, elements
 * under review, the last INOVAR export). The service answers exactly three
 * questions — what is complete, what deserves a look, what does not apply —
 * and refuses to answer any pedagogical one: a pending decision is a note,
 * not an error, and nothing here blocks anything.
 *
 * THREE STATES, NEVER MORE. `ok`, `attention`, `neutral` («não aplicável» /
 * informativo). There is deliberately no «error»: the only true blockers a
 * pauta has (no profile, no period) already stop the sheet itself from being
 * built, upstream of this service.
 *
 * WHETHER THE MOMENT CLOSES THE PERIOD is read from the period's own dates
 * and status through InovarLevelOption::includedByDefault — the one approved
 * rule the product already applies to the same question, and never from the
 * period's name (§6). While the period still runs, a missing decision is
 * listed as neutral: the brief for this screen forbids assuming a level is
 * expected mid-period, and the objective fact is only «not decided yet».
 */
class EvaluationSheetReadiness
{
    public const STATE_OK = 'ok';

    public const STATE_ATTENTION = 'attention';

    public const STATE_NEUTRAL = 'neutral';

    public function __construct(
        protected ClassResultsCalculator $calculator,
    ) {}

    /**
     * @param  array{class_id: int, academic_period_id: int, scope: string, domains: list<array<string, mixed>>, students: list<array<string, mixed>>}  $sheet
     * @return array<string, mixed>
     */
    public function for(
        SchoolClass $class,
        AcademicPeriod $period,
        array $sheet,
        bool $canPrepareInovarExport,
    ): array {
        $students = $sheet['students'];
        $enrollmentIds = array_map(fn (array $student): int => (int) $student['enrollment_id'], $students);

        // THE SCOPE IS THE SHEET'S OWN, never assumed. The Pauta asks for a
        // period result today, but the sheet already states which scope
        // produced its figures, and the lookups below — the instruments a
        // review can hold back, the INOVAR record — have to ask about that
        // same universe. Hardcoding `period` here would go on answering
        // confidently the day the screen offers the accumulated result, and
        // answering about the wrong elements is worse than not answering.
        $scope = ClassificationScope::from($sheet['scope']);

        // The moment's shape, from configuration alone. Closed/archived, or a
        // period that has reached its own ends_on, is a closing moment; a
        // period still running is an interim one and expects less.
        $isClosing = InovarLevelOption::includedByDefault($period, now());

        // The word for the decision on THIS class's scale — «nível» on bands,
        // «classificação» on an interval — through the same seam every other
        // decision screen reads (DecisionScale), so no two screens disagree.
        // Read through the relation the sheet already loaded on this very
        // model, not a second query of its own.
        $decision = DecisionScale::for($class->profileVersion?->scale);
        $byLevel = $decision->classifiesByLevel();

        $enrollmentUlids = $this->enrollmentUlids($class, $enrollmentIds);
        $activeEnrollmentIds = $class->activeEnrollments()->pluck('id')
            ->map(fn ($id): int => (int) $id)->flip();
        $selfAssessments = $this->selfAssessments($period, $enrollmentIds);
        $underReview = $this->enrollmentsWithElementsUnderReview($class, $period, $scope, $enrollmentIds);

        // A domain nobody has results in is the class's situation, not each
        // student's: it is reported once, and the per-student lines skip it.
        $emptyDomainIds = $this->domainsWithoutResults($sheet);

        // Self-assessment expectation is USAGE, not doctrine: if at least one
        // self-assessment exists for this class and period, the teacher opened
        // that door and the missing ones are worth a look. If none exists,
        // nothing was asked and nothing is pending (§15 keeps it outside the
        // calculation either way).
        $selfAssessmentExpected = $selfAssessments->isNotEmpty();

        $rows = [];
        $decided = 0;
        $proposalOnly = 0;
        $withoutRow = 0;
        $submittedSelfAssessments = 0;
        $selfAssessmentUniverse = 0;
        $studentsWithResultGaps = 0;

        foreach ($students as $student) {
            $enrollmentId = (int) $student['enrollment_id'];
            $pending = [];

            // A) The decision. Decided means the TEACHER wrote it — a final
            // value or a final level; a proposal alone is a decision not yet
            // made (§3.3, same reading CaptureEvaluationSheet::warnings uses).
            $classification = $student['classification'];
            $hasFinal = $classification !== null
                && ($classification['final_value'] !== null || $classification['final_scale_level_id'] !== null);

            if ($hasFinal) {
                $decided++;
            } else {
                $hasProposal = $classification !== null
                    && ($classification['proposed_value'] !== null || $classification['proposed_scale_level_id'] !== null);
                $hasProposal ? $proposalOnly++ : $withoutRow++;

                // THE PER-STUDENT LIST NAMES ATTENTION POINTS ONLY. While the
                // period still runs, «not decided yet» is the ordinary state of
                // things — the class-level item already counts it, neutrally —
                // and a list that named all 26 students for it would bury the
                // three genuinely worth a look. Only a closing moment promotes
                // the missing decision onto a student's own line.
                if ($isClosing) {
                    $pending[] = [
                        'state' => self::STATE_ATTENTION,
                        'label' => $hasProposal
                            ? 'Proposta do Lapispro ainda não decidida'
                            : ($byLevel ? 'Nível ainda não atribuído' : 'Classificação ainda não decidida'),
                        'action' => 'classifications',
                    ];
                }
            }

            // B) Coverage — read from the flags the engine raised, never
            // re-derived here (§13.4). «Sem elementos» swallows the per-domain
            // detail: every domain of that student is empty, and 25 lines
            // saying so teach nothing the first one did not.
            $coverage = $student['coverage'];
            $gapLines = [];

            if (($coverage['no_elements'] ?? false) === true) {
                $gapLines[] = ['state' => self::STATE_ATTENTION, 'label' => 'Sem elementos avaliados neste momento', 'action' => 'results'];
            } else {
                foreach ($student['domains'] as $domain) {
                    if ($domain['normalized_value'] !== null || isset($emptyDomainIds[(int) $domain['domain_id']])) {
                        continue;
                    }

                    // THE ENGINE DECIDES WHAT IS A GAP (§13.4). A domain can be
                    // valueless with no warning raised — evidence deliberately
                    // outside the denominator, such as bonus-only items — and
                    // the engine saying «this is not a lacuna» is final here.
                    if (($domain['has_coverage_warning'] ?? false) !== true) {
                        continue;
                    }

                    $gapLines[] = [
                        'state' => self::STATE_ATTENTION,
                        'label' => 'Sem resultados no domínio '.$domain['name'],
                        'action' => 'results',
                    ];
                }

                // Partial coverage only when no domain is missing outright AND
                // the explanation names concrete exclusions for THIS student
                // (an absence, a score still pending). Without that, the ⚠ on
                // the sheet is caused by a domain empty for the whole class —
                // already reported once, at class level, and repeating it under
                // every name would be noise, not information.
                //
                // E SÓ ONDE HÁ RESULTADO. «Cobertura parcial» é uma afirmação
                // sobre um valor que existe; dita sobre alguém sem avaliação
                // nenhuma, afirmaria uma avaliação que não houve (§16). O
                // estado sai do mesmo sítio que a fotografia e o ⚠ do ecrã
                // usam, para não haver duas frases para o mesmo facto (§17).
                $state = CoverageWording::state(
                    ($student['overall']['has_coverage_warning'] ?? false) === true,
                    ($student['overall']['normalized_value'] ?? null) !== null,
                );

                if ($gapLines === []
                    && $state === CoverageWording::PARTIAL
                    && ($coverage['absences'] ?? []) !== []) {
                    $gapLines[] = [
                        'state' => self::STATE_ATTENTION,
                        'label' => CoverageWording::partial('overall'),
                        'action' => 'results',
                    ];
                }
            }

            if ($gapLines !== []) {
                $studentsWithResultGaps++;
                $pending = [...$pending, ...$gapLines];
            }

            // C) An element under review holds this student's publication back
            // (§5) — the one pending item that is a product rule rather than a
            // pedagogical reading, so it is always attention.
            if (isset($underReview[$enrollmentId])) {
                $pending[] = [
                    'state' => self::STATE_ATTENTION,
                    'label' => 'Elemento em revisão — retém a publicação da classificação',
                    'action' => 'results',
                ];
            }

            // D) Self-assessment, only where the expectation objectively
            // exists and only for students still in the class — asking someone
            // who left would be asking the wrong person (§17).
            if ($selfAssessmentExpected && $activeEnrollmentIds->has($enrollmentId)) {
                $selfAssessmentUniverse++;
                $selfAssessment = $selfAssessments->get($enrollmentId);

                if ($selfAssessment !== null && $selfAssessment->status !== SelfAssessmentStatus::Draft) {
                    $submittedSelfAssessments++;
                } else {
                    $pending[] = [
                        'state' => self::STATE_ATTENTION,
                        'label' => $selfAssessment === null
                            ? 'Autoavaliação em falta'
                            : 'Autoavaliação em rascunho, por submeter',
                        'action' => 'self-assessment',
                    ];
                }
            }

            if ($pending !== []) {
                $rows[] = [
                    'enrollment_ulid' => $enrollmentUlids[$enrollmentId] ?? null,
                    'class_number' => $student['class_number'],
                    'name' => $student['name'],
                    'pending' => $pending,
                ];
            }
        }

        $items = $this->items(
            $sheet,
            $isClosing,
            $byLevel,
            $decided,
            $proposalOnly,
            $withoutRow,
            count($students),
            $studentsWithResultGaps,
            count($underReview),
            $selfAssessmentExpected,
            $submittedSelfAssessments,
            $selfAssessmentUniverse,
            $emptyDomainIds,
            $this->lastInovarExport($class, $period, $scope),
            $canPrepareInovarExport,
        );

        // Every per-student entry is an attention point by construction — the
        // list carries nothing neutral (see the decisions block above) — so
        // the count of points to look at is simply the count of entries.
        $attentionCount = 0;
        foreach ($rows as $row) {
            $attentionCount += count($row['pending']);
        }

        return [
            // The moment named by its OWN configuration — «Semestre», «1.º
            // Semestre» — never by a word written here (§6).
            'moment' => [
                'period_label' => (string) $period->label,
                'kind_label' => $period->kind->label(),
                'is_closing' => $isClosing,
            ],
            'summary' => [
                'students_total' => count($students),
                'students_with_notes' => count($rows),
                'students_ready' => count($students) - count($rows),
                'attention_count' => $attentionCount,
            ],
            'items' => $items,
            'students' => $rows,
        ];
    }

    /**
     * The class-level lines of the checklist, in reading order.
     *
     * @param  array{domains: list<array<string, mixed>>, students: list<array<string, mixed>>}  $sheet
     * @param  array<int, true>  $emptyDomainIds
     * @return list<array<string, mixed>>
     */
    protected function items(
        array $sheet,
        bool $isClosing,
        bool $byLevel,
        int $decided,
        int $proposalOnly,
        int $withoutRow,
        int $studentsTotal,
        int $studentsWithResultGaps,
        int $underReviewCount,
        bool $selfAssessmentExpected,
        int $submittedSelfAssessments,
        int $selfAssessmentUniverse,
        array $emptyDomainIds,
        ?EvaluationSheetExport $lastInovar,
        bool $canPrepareInovarExport,
    ): array {
        $items = [];

        // Decisions. On an interim moment a shortfall stays neutral — the
        // period still runs and nothing says a decision was due yet.
        $pendingDecisions = $proposalOnly + $withoutRow;
        $detailParts = [];
        if ($proposalOnly > 0) {
            $detailParts[] = $proposalOnly.' '.($proposalOnly === 1 ? 'proposta por decidir' : 'propostas por decidir');
        }
        if ($withoutRow > 0) {
            $detailParts[] = $withoutRow.' sem registo';
        }

        $items[] = [
            'key' => 'decisions',
            'state' => $pendingDecisions === 0
                ? self::STATE_OK
                : ($isClosing ? self::STATE_ATTENTION : self::STATE_NEUTRAL),
            'label' => $byLevel
                ? "{$decided} de {$studentsTotal} níveis atribuídos"
                : "{$decided} de {$studentsTotal} classificações decididas",
            'detail' => $detailParts === [] ? null : implode(' · ', $detailParts),
            'action' => 'classifications',
        ];

        // Coverage of results — the engine's own flags, aggregated.
        if ($emptyDomainIds !== []) {
            $names = [];
            foreach ($sheet['domains'] as $domain) {
                if (isset($emptyDomainIds[(int) $domain['domain_id']])) {
                    $names[] = (string) $domain['name'];
                }
            }

            $items[] = [
                'key' => 'domains',
                'state' => self::STATE_ATTENTION,
                'label' => count($names) === 1
                    ? 'Domínio sem resultados neste momento: '.$names[0]
                    : 'Domínios sem resultados neste momento: '.implode(', ', $names),
                'detail' => 'Nenhum aluno tem elementos avaliados nestes domínios.',
                'action' => 'results',
            ];
        }

        $items[] = [
            'key' => 'coverage',
            'state' => $studentsWithResultGaps === 0 ? self::STATE_OK : self::STATE_ATTENTION,
            'label' => $studentsWithResultGaps === 0
                ? 'Sem lacunas de cobertura detetadas'
                : $studentsWithResultGaps.' '.($studentsWithResultGaps === 1
                    ? 'aluno com lacunas de cobertura'
                    : 'alunos com lacunas de cobertura'),
            'detail' => null,
            'action' => 'results',
        ];

        // Elements under review — shown only when there are any: a permanent
        // «0 em revisão ✓» line would be bureaucracy, not information.
        if ($underReviewCount > 0) {
            $items[] = [
                'key' => 'under-review',
                'state' => self::STATE_ATTENTION,
                'label' => $underReviewCount.' '.($underReviewCount === 1
                    ? 'aluno com elemento em revisão'
                    : 'alunos com elementos em revisão'),
                'detail' => 'A publicação dessas classificações fica retida enquanto a revisão durar.',
                'action' => 'results',
            ];
        }

        // Self-assessments: expectation comes from usage, never from doctrine.
        if ($selfAssessmentExpected) {
            $missing = $selfAssessmentUniverse - $submittedSelfAssessments;
            $items[] = [
                'key' => 'self-assessments',
                'state' => $missing === 0 ? self::STATE_OK : self::STATE_ATTENTION,
                'label' => "{$submittedSelfAssessments} de {$selfAssessmentUniverse} autoavaliações submetidas",
                'detail' => null,
                'action' => 'self-assessments',
            ];
        } else {
            $items[] = [
                'key' => 'self-assessments',
                'state' => self::STATE_NEUTRAL,
                'label' => 'Autoavaliações não utilizadas neste momento',
                'detail' => 'Nenhuma autoavaliação foi lançada para este momento — não conta como pendência.',
                'action' => null,
            ];
        }

        // INOVAR — information, never obligation (the brief is explicit). The
        // line only exists where it can mean something: the school is entitled
        // to prepare one, or one was already prepared and stays theirs to see
        // whatever the plan says today.
        if ($canPrepareInovarExport || $lastInovar !== null) {
            if ($lastInovar === null) {
                $items[] = [
                    'key' => 'inovar',
                    'state' => self::STATE_NEUTRAL,
                    'label' => 'Exportação Inovar ainda não realizada',
                    'detail' => null,
                    // Reached only when the school can prepare one: without the
                    // entitlement AND without history there is no line at all.
                    'action' => 'inovar',
                ];
            } else {
                $withWarnings = $lastInovar->exported_with_warnings;
                $items[] = [
                    'key' => 'inovar',
                    'state' => $withWarnings ? self::STATE_ATTENTION : self::STATE_OK,
                    'label' => 'Última exportação Inovar a '.$lastInovar->exported_at->format('d/m/Y'),
                    'detail' => $withWarnings
                        ? 'Exportada com '.$lastInovar->warning_count.' '.($lastInovar->warning_count === 1 ? 'aviso' : 'avisos').'.'
                        : null,
                    'action' => $canPrepareInovarExport ? 'inovar' : null,
                ];
            }
        }

        return $items;
    }

    /**
     * @param  list<int>  $enrollmentIds
     * @return array<int, string>
     */
    protected function enrollmentUlids(SchoolClass $class, array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        return $class->enrollments()
            ->whereIn('id', $enrollmentIds)
            ->pluck('ulid', 'id')
            ->all();
    }

    /**
     * @param  list<int>  $enrollmentIds
     * @return Collection<int, SelfAssessment>
     */
    protected function selfAssessments(AcademicPeriod $period, array $enrollmentIds): Collection
    {
        if ($enrollmentIds === []) {
            return collect();
        }

        return SelfAssessment::query()
            ->where('academic_period_id', $period->getKey())
            ->whereIn('enrollment_id', $enrollmentIds)
            ->get()
            ->keyBy('enrollment_id');
    }

    /**
     * The enrollments whose publication would be held back, read with exactly
     * the instrument universe PublishClassifications uses (§570): counting
     * instruments, in a state the engine reads, over the periods THIS SCOPE
     * feeds on — one period for a period result, the year's contributing
     * periods for an accumulated one, resolved by the one service that owns
     * that question rather than restated here.
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, true>
     */
    protected function enrollmentsWithElementsUnderReview(
        SchoolClass $class,
        AcademicPeriod $period,
        ClassificationScope $scope,
        array $enrollmentIds,
    ): array {
        if ($enrollmentIds === []) {
            return [];
        }

        $countingInstrumentIds = Instrument::query()
            ->where('class_id', $class->getKey())
            ->whereIn('academic_period_id', $this->calculator->periodIdsInScope($class, $period, $scope))
            ->where('counts_toward_classification', true)
            ->get()
            ->filter(fn (Instrument $instrument): bool => $instrument->status->entersCalculation())
            ->pluck('id');

        if ($countingInstrumentIds->isEmpty()) {
            return [];
        }

        $flagged = [];
        $ids = StudentItemScore::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('instrument_id', $countingInstrumentIds)
            ->where('result_state', ResultState::UnderReview->value)
            ->distinct()
            ->pluck('enrollment_id');

        foreach ($ids as $id) {
            $flagged[(int) $id] = true;
        }

        return $flagged;
    }

    /**
     * Domains the class as a whole has no result in — the class's gap, told
     * once, instead of the same line under every single name.
     *
     * TWO CONDITIONS, BOTH THE ENGINE'S. Nobody carries a value, AND the
     * engine raised the coverage warning for at least one student. The second
     * is what keeps a domain out of here that has no value for a reason the
     * engine does not consider a gap — evidence deliberately outside the
     * denominator, such as bonus-only items (§13.4).
     *
     * @param  array{domains: list<array<string, mixed>>, students: list<array<string, mixed>>}  $sheet
     * @return array<int, true>
     */
    protected function domainsWithoutResults(array $sheet): array
    {
        if ($sheet['students'] === []) {
            return [];
        }

        $withoutValue = [];
        foreach ($sheet['domains'] as $domain) {
            $withoutValue[(int) $domain['domain_id']] = true;
        }

        $flagged = [];

        foreach ($sheet['students'] as $student) {
            foreach ($student['domains'] as $domain) {
                $domainId = (int) $domain['domain_id'];

                if ($domain['normalized_value'] !== null) {
                    unset($withoutValue[$domainId]);
                }

                if (($domain['has_coverage_warning'] ?? false) === true) {
                    $flagged[$domainId] = true;
                }
            }
        }

        return array_intersect_key($withoutValue, $flagged);
    }

    /**
     * The most recent INOVAR record for this class and period. Recency is
     * derived from exported_at — there is deliberately no stored «is latest»
     * (see EvaluationSheetExport).
     */
    protected function lastInovarExport(SchoolClass $class, AcademicPeriod $period, ClassificationScope $scope): ?EvaluationSheetExport
    {
        return EvaluationSheetExport::query()
            ->where('class_id', $class->getKey())
            ->where('academic_period_id', $period->getKey())
            ->where('scope', $scope)
            ->where('adapter', 'inovar')
            ->orderByDesc('exported_at')
            ->orderByDesc('id')
            ->first();
    }
}
