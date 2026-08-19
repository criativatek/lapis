<?php

namespace App\Services\Assessment;

use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\ResultState;
use App\Models\StudentItemScore;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Read-only presentation layer for the Avaliações screens (§X). It never
 * writes and never touches CalculationEngine/ClassResultsCalculator — it only
 * summarises what already exists in instruments/instrument_items/
 * student_item_scores for display. "Avaliação" is not a new concept: it is
 * an Instrument, viewed as an application-in-progress rather than a
 * definition being authored.
 */
class AssessmentSummaryQuery
{
    /**
     * The teacher's own instruments, across every class, most recent first —
     * mirrors InstrumentController::index()'s own scoping so the two lists
     * (Elementos de Avaliação vs Avaliações) never disagree about which rows a teacher
     * can see.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFor(User $teacher, ?string $status = null, ?string $purpose = null, ?int $academicPeriodId = null): array
    {
        $instruments = Instrument::query()
            ->whereHas('schoolClass.teachers', fn ($query) => $query->whereKey($teacher->getKey()))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($purpose !== null, fn ($query) => $query->where('purpose', $purpose))
            ->when($academicPeriodId !== null, fn ($query) => $query->where('academic_period_id', $academicPeriodId))
            ->with(['schoolClass', 'academicPeriod'])
            ->withCount('items')
            ->orderByDesc('applied_on')
            ->get();

        $progress = $this->progressFor($instruments);

        return $instruments->map(fn (Instrument $instrument) => [
            'ulid' => $instrument->ulid,
            'title' => $instrument->title,
            'class_label' => $instrument->schoolClass->label,
            'purpose' => $instrument->purpose,
            'purpose_label' => self::purposeLabel($instrument->purpose),
            'period' => $instrument->academicPeriod->label,
            'applied_on' => $instrument->applied_on->toDateString(),
            'status' => $instrument->status->value,
            'state_label' => self::stateLabel($instrument),
            'action_label' => self::actionLabel($instrument),
            'items_count' => $instrument->items_count,
            'progress' => $progress->get($instrument->id),
        ])->values()->all();
    }

    /**
     * Everything the Show screen needs for one instrument: header facts, the
     * five-way summary (aplicáveis/concluídos/por corrigir/faltas/em revisão),
     * a derived state per applicable student, and the raw items/scores/
     * scaleBands a student's result percentage and qualitative label are
     * computed from — reusing, unchanged, the same
     * resources/js/lib/instrumentQualitativeRating.ts functions
     * instruments/Grid.vue already uses, so there is exactly one
     * implementation of that math, not two that could drift apart. This
     * method itself never computes a percentage or a grade — only the
     * per-student STATE (§ below), which is presentation, not calculation.
     *
     * Everything is loaded up front for the whole class in a handful of
     * queries — no query runs per student or per cell.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(Instrument $instrument): array
    {
        $instrument->loadMissing(['items', 'schoolClass.profileVersion.scale', 'academicPeriod']);

        $enrollments = $instrument->schoolClass->enrollments()
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();

        $scores = StudentItemScore::where('instrument_id', $instrument->id)->get();
        $scoresByCell = $scores->keyBy(fn (StudentItemScore $score) => $score->enrollment_id.':'.$score->instrument_item_id);

        $itemIds = $instrument->items->pluck('id');

        $applicableStudents = [];
        $nonApplicableStudents = [];
        $counts = ['completed' => 0, 'pending' => 0, 'absent' => 0, 'under_review' => 0];

        foreach ($enrollments as $enrollment) {
            $name = optional($enrollment->student->identity)->display_name ?? '(sem identidade)';
            // The identity is eager-loaded above, so this costs no extra query.
            $photoUrl = $enrollment->student->photoUrl();

            if (! self::isApplicable($enrollment, $instrument->applied_on)) {
                $nonApplicableStudents[] = [
                    'enrollment_id' => $enrollment->id,
                    'name' => $name,
                    'photo_url' => $photoUrl,
                    'class_number' => $enrollment->class_number,
                ];

                continue;
            }

            if ($itemIds->isEmpty()) {
                $state = ['key' => 'pending', 'label' => (string) __('Por corrigir'), 'detail' => null];
            } else {
                $itemStates = $itemIds->map(function (int $itemId) use ($scoresByCell, $enrollment) {
                    $score = $scoresByCell->get($enrollment->id.':'.$itemId);

                    return $score !== null ? $score->result_state : ResultState::Pending;
                });
                $state = self::studentStateFor($itemStates);
            }

            $counts[self::summaryBucketFor($state['key'])]++;

            $applicableStudents[] = [
                'enrollment_id' => $enrollment->id,
                'name' => $name,
                'photo_url' => $photoUrl,
                'class_number' => $enrollment->class_number,
                'state_key' => $state['key'],
                'state_label' => $state['label'],
                'state_detail' => $state['detail'],
                'action_label' => self::studentActionLabel($state['key']),
            ];
        }

        $scaleBands = $instrument->schoolClass->profileVersion?->scale
            ?->levels()
            ->whereNotNull('band_min_normalized')
            ->whereNotNull('band_max_normalized')
            ->orderBy('sequence')
            ->get()
            ->map(fn ($level) => [
                'label' => $level->label,
                'band_min' => (string) $level->band_min_normalized,
                'band_max' => (string) $level->band_max_normalized,
            ])
            ->all() ?? [];

        return [
            'instrument' => [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'class_label' => $instrument->schoolClass->label,
                'period' => $instrument->academicPeriod->label,
                'applied_on' => $instrument->applied_on->toDateString(),
                'purpose_label' => self::purposeLabel($instrument->purpose),
                'counts_toward_classification' => $instrument->counts_toward_classification,
                'state_label' => self::stateLabel($instrument),
            ],
            'summary' => [
                'applicable' => count($applicableStudents),
                'completed' => $counts['completed'],
                'pending' => $counts['pending'],
                'absent' => $counts['absent'],
                'under_review' => $counts['under_review'],
            ],
            'students' => $applicableStudents,
            'nonApplicableStudents' => $nonApplicableStudents,
            // Raw ingredients for percentFor()/qualitativeLabelFor() in
            // instrumentQualitativeRating.ts — the exact shape Grid.vue
            // already receives from InstrumentController::show().
            'items' => $instrument->items->map(fn ($item) => [
                'id' => $item->id,
                'points_possible' => (float) $item->points_possible,
                'is_bonus' => $item->is_bonus,
            ])->all(),
            'scores' => $scores->map(fn (StudentItemScore $score) => [
                'enrollment_id' => $score->enrollment_id,
                'instrument_item_id' => $score->instrument_item_id,
                'result_state' => $score->result_state->value,
                'points_earned' => $score->points_earned === null ? null : (float) $score->points_earned,
            ])->all(),
            'scaleBands' => $scaleBands,
        ];
    }

    /**
     * Grading progress per instrument, counted in STUDENTS, not cells:
     * applicable = students the instrument's date actually reaches — the same
     * late-entry rule ClassResultsCalculator::scoreInputsFor() derives
     * (§11.4), duplicated here as a single boolean rather than extracted,
     * since that method is private engine-adapter logic this read-only
     * service must not depend on or alter. A student outside the enrollment
     * window never enters the denominator, is never treated as zero, and
     * never affects the count.
     *
     * completed = applicable students whose EVERY item has an explicit
     * resolved state (assessed, absent, absent_justified, exempt,
     * not_applicable, annulled). A student with even one item still pending
     * does not count — partial correction is not completion. A student with
     * any item under_review is pulled out into under_review instead, even if
     * every other item is resolved: the mark is not settled, so the student
     * is not done.
     *
     * An instrument with no items yet reports completed = 0 for every
     * applicable student, never vacuously "all done" — an absent checklist is
     * not a satisfied one.
     *
     * @param  EloquentCollection<int, Instrument>  $instruments
     * @return Collection<int, array{applicable: int<0, max>, completed: int<0, max>, under_review: int<0, max>, complete: bool}>
     */
    protected function progressFor(EloquentCollection $instruments): Collection
    {
        // One rule, one place. "Concluir correção" asks this same service the
        // same question, so what the page shows and what the action allows can
        // never drift apart.
        return app(InstrumentCompleteness::class)->forMany($instruments);
    }

    /**
     * The derived late-entry rule (§11.4), shared by progressFor() and
     * summaryFor() — the same boolean ClassResultsCalculator::
     * scoreInputsFor() computes, duplicated rather than extracted, since that
     * method is private engine-adapter logic this read-only service must not
     * depend on or alter.
     */
    protected static function isApplicable(Enrollment $enrollment, CarbonInterface $appliedOn): bool
    {
        return $enrollment->enrolled_on->lessThanOrEqualTo($appliedOn)
            && ($enrollment->left_on === null || $enrollment->left_on->greaterThanOrEqualTo($appliedOn));
    }

    /**
     * One applicable student's derived state, from the ResultState of every
     * item this instrument holds (never empty — the caller special-cases an
     * itemless instrument before reaching here, since "every() over nothing"
     * is vacuously true and would otherwise read as "all resolved").
     *
     * Priority, matching what the teacher needs to act on first:
     *  B. any item under_review  → "Em revisão", not completed.
     *  C. any item pending       → "Por corrigir", not completed.
     *  D/E/F. every item has some resolved state:
     *    - any item assessed     → "Corrigida" (a real mark exists).
     *    - else uniformly absent/absent_justified → "Faltou" (+ detail).
     *    - else uniformly exempt/annulled/not_applicable → that state's own
     *      label, preserved rather than folded into a zero or into
     *      "Corrigida".
     *    - else (a genuine mix of non-assessed special states, e.g. one item
     *      exempt and another annulled) → "Situação especial", with the
     *      distinct states involved listed in detail. Every item has a
     *      settled state — this student counts as operationally completed,
     *      not left pending — but it must not read like an ordinary
     *      correction, so it deliberately does not borrow "Corrigida" or any
     *      single-state label. Rare in practice, since an instrument's items
     *      are normally marked uniformly for a given student.
     *
     * @param  Collection<int, ResultState>  $itemStates
     * @return array{key: string, label: string, detail: string|null}
     */
    protected static function studentStateFor(Collection $itemStates): array
    {
        if ($itemStates->contains(ResultState::UnderReview)) {
            return ['key' => 'under_review', 'label' => (string) __('Em revisão'), 'detail' => null];
        }

        if ($itemStates->contains(ResultState::Pending)) {
            return ['key' => 'pending', 'label' => (string) __('Por corrigir'), 'detail' => null];
        }

        if ($itemStates->contains(ResultState::Assessed)) {
            return ['key' => 'assessed', 'label' => (string) __('Corrigida'), 'detail' => null];
        }

        if ($itemStates->every(fn (ResultState $state) => in_array($state, [ResultState::Absent, ResultState::AbsentJustified], true))) {
            $justified = $itemStates->every(fn (ResultState $state) => $state === ResultState::AbsentJustified);

            return [
                'key' => 'absent',
                'label' => (string) __('Faltou'),
                'detail' => $justified ? (string) __('justificada') : null,
            ];
        }

        if ($itemStates->every(fn (ResultState $state) => $state === ResultState::Exempt)) {
            return ['key' => 'exempt', 'label' => (string) __('Dispensada'), 'detail' => null];
        }

        if ($itemStates->every(fn (ResultState $state) => $state === ResultState::Annulled)) {
            return ['key' => 'annulled', 'label' => (string) __('Anulada'), 'detail' => null];
        }

        if ($itemStates->every(fn (ResultState $state) => $state === ResultState::NotApplicable)) {
            return ['key' => 'not_applicable', 'label' => (string) __('Não aplicável'), 'detail' => null];
        }

        $involved = $itemStates->unique()->map(fn (ResultState $state) => $state->label())->implode(', ');

        return ['key' => 'special', 'label' => (string) __('Situação especial'), 'detail' => $involved];
    }

    /**
     * Which of the five Show-screen summary cards a student's derived state
     * counts toward. Every "settled with a real or preserved outcome" state
     * (assessed, exempt, annulled, not_applicable, the mixed-resolved
     * fallback) folds into "completed" — only pending, under_review and the
     * absent bucket get their own card.
     */
    protected static function summaryBucketFor(string $stateKey): string
    {
        return match ($stateKey) {
            'pending' => 'pending',
            'under_review' => 'under_review',
            'absent' => 'absent',
            default => 'completed',
        };
    }

    /**
     * The verb on a student row's action button — always a link into the
     * same instruments.show grid, never a new route; only the label adapts
     * to how urgently that student needs attention.
     */
    protected static function studentActionLabel(string $stateKey): string
    {
        return match ($stateKey) {
            'pending' => __('Corrigir'),
            'under_review' => __('Rever'),
            'assessed' => __('Ver/Editar'),
            default => __('Editar'),
        };
    }

    /**
     * The Index row's call-to-action verb, one per derived instrument state
     * (§7) — always a link into assessments.show, never a new route. Draft
     * and both Prepared sub-states (Agendada/Por iniciar) all read "Abrir":
     * only in_correction and the settled states get a more specific verb.
     */
    public static function actionLabel(Instrument $instrument): string
    {
        return match ($instrument->status) {
            InstrumentStatus::Draft, InstrumentStatus::Prepared => __('Abrir'),
            InstrumentStatus::InCorrection => __('Continuar'),
            InstrumentStatus::Completed, InstrumentStatus::Published => __('Ver'),
            InstrumentStatus::Cancelled, InstrumentStatus::Archived => __('Ver'),
        };
    }

    public static function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            'diagnostic' => __('Diagnóstica'),
            'formative' => __('Formativa'),
            'summative' => __('Sumativa'),
            default => __('Outra'),
        };
    }

    /**
     * The Avaliações module's own label table — distinct from
     * InstrumentStatus::label() (used by Elementos de Avaliação), both in wording
     * ("prepared" splits by whether applied_on has arrived) and in gender
     * ("avaliação" is feminine; "instrumento" is not). Completed and
     * published deliberately collapse into the same "Concluída": from this
     * list, a teacher only cares that the result is settled, not which of
     * the two internal states got it there — the raw status value is still
     * returned separately in listFor() for anything that needs the precise
     * one. This never touches InstrumentStatus itself or any persisted value.
     */
    public static function stateLabel(Instrument $instrument): string
    {
        return match ($instrument->status) {
            InstrumentStatus::Draft => __('Rascunho'),
            InstrumentStatus::Prepared => $instrument->applied_on->isFuture() ? __('Agendada') : __('Por iniciar'),
            InstrumentStatus::InCorrection => __('Em correção'),
            InstrumentStatus::Completed, InstrumentStatus::Published => __('Concluída'),
            InstrumentStatus::Cancelled => __('Anulada'),
            InstrumentStatus::Archived => __('Arquivada'),
        };
    }
}
