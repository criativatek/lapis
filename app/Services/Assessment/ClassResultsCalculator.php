<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\CalculationEngine;
use App\Domain\Assessment\CalculationOutcome;
use App\Domain\Assessment\CalculationRule;
use App\Domain\Assessment\ScaleBand;
use App\Domain\Assessment\ScoreInput;
use App\Models\AcademicPeriod;
use App\Models\AssessmentProfileVersion;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Support\Assessment\AssessmentCutoff;
use Illuminate\Support\Collection;

/**
 * The impure adapter around CalculationEngine: it loads a class's scores from the
 * database, resolves which instruments apply to each enrollment (the derived
 * late-entry rule, §11.4), and feeds plain data to the pure engine.
 *
 * The engine holds all the arithmetic and rules; this holds all the Eloquent.
 * That split is what lets the engine be tested without a database (§13.1).
 */
class ClassResultsCalculator
{
    public function __construct(protected CalculationEngine $engine) {}

    /**
     * @return list<array{enrollment: Enrollment, outcome: CalculationOutcome}>
     */
    public function forPeriod(SchoolClass $class, AcademicPeriod $period, ?AssessmentCutoff $cutoff = null): array
    {
        return $this->forScope($class, $period, ClassificationScope::Period, cutoff: $cutoff);
    }

    /**
     * The year-to-date result at a period boundary (§6.3, Q4). With
     * `accumulated_mode = all_valid_year_elements` the engine reprocesses the raw
     * elements of every contributing period up to and including this one — never
     * an average of period averages, which would over-weight the earliest marks.
     *
     * @return list<array{enrollment: Enrollment, outcome: CalculationOutcome}>
     */
    public function forAccumulated(SchoolClass $class, AcademicPeriod $period, ?AssessmentCutoff $cutoff = null): array
    {
        return $this->forScope($class, $period, ClassificationScope::Accumulated, cutoff: $cutoff);
    }

    /**
     * @param  AssessmentProfileVersion|null  $versionOverride  compute under this version instead of the class's current one — used to preview a profile migration's impact (A4) without touching the class
     * @return list<array{enrollment: Enrollment, outcome: CalculationOutcome}>
     */
    public function forScope(
        SchoolClass $class,
        AcademicPeriod $period,
        ClassificationScope $scope,
        ?AssessmentProfileVersion $versionOverride = null,
        ?AssessmentCutoff $cutoff = null,
    ): array {
        $version = $versionOverride ?? $class->profileVersion;
        $cutoff ??= AssessmentCutoff::none();

        if ($version === null) {
            return [];
        }

        // The columns are CHECK-constrained to these sets, but the match narrows
        // the DB string to the engine's literal-union types and falls back safely.
        $absence = match ($version->absence_mode) {
            'exclude_all', 'zero_all', 'zero_unjustified_only', 'exclude_all_warn' => $version->absence_mode,
            default => 'exclude_all_warn',
        };
        $rounding = match ($version->rounding_mode) {
            'half_up', 'half_down', 'half_even', 'ceil', 'floor', 'none' => $version->rounding_mode,
            default => 'half_up',
        };
        $stage = match ($version->rounding_stage) {
            'final_only', 'each_domain', 'each_stage' => $version->rounding_stage,
            default => 'final_only',
        };

        $rule = new CalculationRule(
            absenceMode: $absence,
            roundingMode: $rounding,
            roundingScale: $version->rounding_scale,
            roundingStage: $stage,
        );

        /** @var array<int, string> $domainWeights */
        $domainWeights = $version->domains()->pluck('weight_percent', 'domain_id')->all();

        /** @var list<ScaleBand> $scaleBands */
        $scaleBands = $version->scale->levels()
            ->whereNotNull('band_min_normalized')
            ->whereNotNull('band_max_normalized')
            ->get()
            ->map(fn ($level): ScaleBand => new ScaleBand(
                id: $level->id,
                bandMin: (string) $level->band_min_normalized,
                bandMax: (string) $level->band_max_normalized,
            ))
            ->all();

        // Instruments that may count: flagged as counting, in a state the engine
        // reads. A period result sees only its period; an accumulated result sees
        // every contributing period up to it (the union of raw elements, Q4).
        //
        // THE ONE PLACE A CUTOFF IS APPLIED. It narrows the evidence before the
        // engine ever sees it, on `applied_on` — the day the element was given,
        // which is the only date that says anything about the class. The engine
        // is untouched and still knows nothing about dates.
        $instrumentQuery = $class->instruments()
            ->whereIn('academic_period_id', $this->periodIdsFor($class, $period, $scope, $version))
            ->where('counts_toward_classification', true)
            ->with(['items.domainAllocations']);

        $cutoff->applyTo($instrumentQuery, 'applied_on');

        $instruments = $instrumentQuery
            ->get()
            ->filter(fn (Instrument $instrument) => $instrument->status->entersCalculation());

        $scoresByEnrollmentItem = StudentItemScore::query()
            ->whereIn('instrument_id', $instruments->pluck('id'))
            ->get()
            ->keyBy(fn (StudentItemScore $score) => $score->enrollment_id.':'.$score->instrument_item_id);

        $results = [];

        foreach ($class->enrollments()->with('student.identity')->orderBy('class_number')->get() as $enrollment) {
            $scoreInputs = $this->scoreInputsFor($enrollment, $instruments, $scoresByEnrollmentItem);

            $results[] = [
                'enrollment' => $enrollment,
                'outcome' => $this->engine->calculate($scoreInputs, $domainWeights, $rule, $scaleBands),
            ];
        }

        return $results;
    }

    /**
     * The periods a scope draws from — the public counterpart of the internal
     * selection, so other services (publication's under-review guard) resolve the
     * same set without re-deriving the rule.
     *
     * @return list<int>
     */
    public function periodIdsInScope(SchoolClass $class, AcademicPeriod $period, ClassificationScope $scope): array
    {
        $version = $class->profileVersion;

        if ($version === null) {
            return [$period->id];
        }

        return $this->periodIdsFor($class, $period, $scope, $version);
    }

    /**
     * Which periods feed the calculation. `period` scope → just this one.
     * `accumulated` scope → every period of the year up to and including this one
     * that the profile version marks as contributing (default true when the
     * version has no per-period config). Only `all_valid_year_elements` is
     * implemented; other accumulated modes reuse the same union for now.
     *
     * @param  AssessmentProfileVersion  $version
     * @return list<int>
     */
    protected function periodIdsFor(SchoolClass $class, AcademicPeriod $period, ClassificationScope $scope, $version): array
    {
        if ($scope === ClassificationScope::Period) {
            return [$period->id];
        }

        /** @var Collection<int, bool> $contributesByPeriod */
        $contributesByPeriod = $version->periods()->pluck('contributes_to_accumulated', 'academic_period_id');

        $periodIds = [];
        foreach (
            AcademicPeriod::query()
                ->where('academic_year_id', $class->academic_year_id)
                ->where('sequence', '<=', $period->sequence)
                ->orderBy('sequence')
                ->get() as $candidate
        ) {
            if ($contributesByPeriod->get($candidate->id, true)) {
                $periodIds[] = (int) $candidate->id;
            }
        }

        return $periodIds;
    }

    /**
     * @param  Collection<int, Instrument>  $instruments
     * @param  Collection<string, StudentItemScore>  $scores
     * @return list<ScoreInput>
     */
    protected function scoreInputsFor(Enrollment $enrollment, $instruments, $scores): array
    {
        $inputs = [];

        foreach ($instruments as $instrument) {
            // Derived late-entry rule (§11.4): an instrument applied before the
            // student enrolled, or after they left, does not apply to them. The
            // engine never sees dates — this is where the derivation happens.
            $applicable = $enrollment->enrolled_on->lessThanOrEqualTo($instrument->applied_on)
                && ($enrollment->left_on === null || $enrollment->left_on->greaterThanOrEqualTo($instrument->applied_on));

            foreach ($instrument->items as $item) {
                $score = $scores->get($enrollment->id.':'.$item->id);

                $allocations = [];
                foreach ($item->domainAllocations as $allocation) {
                    $allocations[] = [
                        'domain_id' => (int) $allocation->domain_id,
                        'allocation_percent' => (string) $allocation->allocation_percent,
                    ];
                }

                $inputs[] = new ScoreInput(
                    instrumentId: $instrument->id,
                    itemCode: $item->code,
                    pointsPossible: (string) $item->points_possible,
                    // No row means the cell was never marked — that is `pending`
                    // (§4.4), which the engine excludes without penalty.
                    state: $score !== null ? $score->result_state : ResultState::Pending,
                    pointsEarned: $score !== null ? $score->points_earned : null,
                    isBonus: $item->is_bonus,
                    eligible: $applicable,
                    allocations: $allocations,
                );
            }
        }

        return $inputs;
    }
}
