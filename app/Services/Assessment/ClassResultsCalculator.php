<?php

namespace App\Services\Assessment;

use App\Domain\Assessment\CalculationEngine;
use App\Domain\Assessment\CalculationOutcome;
use App\Domain\Assessment\CalculationRule;
use App\Domain\Assessment\ScoreInput;
use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
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
    public function forPeriod(SchoolClass $class, AcademicPeriod $period): array
    {
        $version = $class->profileVersion;

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

        // Instruments that may count: in this period, flagged as counting, in a
        // state the engine reads. Applicability per enrollment is resolved below.
        $instruments = $class->instruments()
            ->where('academic_period_id', $period->id)
            ->where('counts_toward_classification', true)
            ->with(['items.domainAllocations'])
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
                'outcome' => $this->engine->calculate($scoreInputs, $domainWeights, $rule),
            ];
        }

        return $results;
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
