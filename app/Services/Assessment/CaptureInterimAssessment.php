<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\InterimAssessment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Assessment\AssessmentCutoff;
use App\Support\Assessment\InterimAssessmentException;
use Illuminate\Support\Carbon;

/**
 * Takes the photograph.
 *
 * NOTHING IS CALCULATED HERE. The state at the reference date comes from
 * BuildClassStatistics under a cutoff — the very same read model, the very same
 * numbers the teacher was looking at when they pressed the button. What this
 * adds is the act of keeping it, and the historical identity that makes it
 * still readable when the live configuration has moved on.
 *
 * WHY LABELS ARE COPIED ALONGSIDE IDS. A snapshot that stored only
 * `domain_id: 7` would render as a blank the day somebody renames domain 7, and
 * one that stored only «Leitura» could never be matched back to anything. Both
 * travel: the id is the canonical identity a later export maps from, the label
 * is what was on screen at the time (§15).
 *
 * The INOVAR code travels too. It is reference data and reference data changes —
 * so a grid produced from a November photograph in January must write the code
 * that November's band carried, not whatever the band carries now (§34).
 */
class CaptureInterimAssessment
{
    public function __construct(
        protected BuildClassStatistics $statistics,
        protected ClassResultsCalculator $calculator,
        protected CoverageExplanation $coverage,
    ) {}

    /**
     * @param  array{name?: string|null, note?: string|null}  $attributes
     */
    public function capture(
        SchoolClass $class,
        AcademicPeriod $period,
        Carbon $referenceDate,
        User $author,
        array $attributes = [],
    ): InterimAssessment {
        $this->guardTheDate($class, $period, $referenceDate);

        $cutoff = AssessmentCutoff::on($referenceDate);

        // ONE build, exactly as the screen does it.
        $statistics = $this->statistics->for($class, $period, $cutoff);
        $snapshot = $this->payload($class, $period, $referenceDate, $statistics, $cutoff);

        return InterimAssessment::create([
            'class_id' => $class->id,
            'academic_period_id' => $period->id,
            'created_by' => $author->id,
            'name' => $this->nameFor($attributes['name'] ?? null, $class, $period),
            'reference_date' => $referenceDate->toDateString(),
            'note' => $attributes['note'] ?? null,
            'snapshot_version' => InterimAssessment::CURRENT_VERSION,
            'snapshot' => $snapshot,
            'snapshot_hash' => InterimAssessment::hashFor($snapshot),
            'created_at' => now(),
        ]);
    }

    /**
     * The date has to be a date this period actually contains.
     *
     * READ FROM THE PERIOD'S OWN starts_on/ends_on, never inferred from its
     * name: «1.º Semestre» says nothing about when it runs, and a school that
     * calls its periods something else would be silently mis-validated (§6).
     */
    protected function guardTheDate(SchoolClass $class, AcademicPeriod $period, Carbon $referenceDate): void
    {
        if ((int) $period->academic_year_id !== (int) $class->academic_year_id) {
            throw InterimAssessmentException::periodOutsideClass();
        }

        $date = $referenceDate->copy()->startOfDay();

        // Both are NOT NULL in the schema — a period without dates is not a
        // period, and the database says so.
        if ($date->lessThan($period->starts_on->copy()->startOfDay())) {
            throw InterimAssessmentException::beforePeriodStart($period);
        }

        if ($date->greaterThan($period->ends_on->copy()->endOfDay())) {
            throw InterimAssessmentException::afterPeriodEnd($period);
        }

        // A photograph of a moment that has not arrived would be a photograph of
        // nothing — and would look identical to today's, which is worse.
        if ($date->greaterThan(now()->endOfDay())) {
            throw InterimAssessmentException::inTheFuture();
        }
    }

    /**
     * The name to offer, which the teacher then confirms or replaces.
     *
     * «Avaliação intercalar — 1.º Semestre» the first time, «2.ª Avaliação
     * intercalar — 1.º Semestre» once there is already one in that period.
     * Counting what is already there rather than reading the date, because the
     * ordinal is about position among siblings and not about the calendar.
     *
     * A SUGGESTION AND NOTHING MORE. It is filled into an editable field and
     * saved only once somebody presses the button — a name generated behind a
     * teacher's back is one they never chose.
     */
    public function suggestedName(SchoolClass $class, AcademicPeriod $period): string
    {
        $existing = InterimAssessment::query()
            ->where('class_id', $class->id)
            ->where('academic_period_id', $period->id)
            ->count();

        return $existing === 0
            ? "Avaliação intercalar — {$period->label}"
            : ($existing + 1).".ª Avaliação intercalar — {$period->label}";
    }

    /** What the teacher typed, tidied — never silently replaced. */
    protected function nameFor(?string $given, SchoolClass $class, AcademicPeriod $period): string
    {
        $given = $given === null ? '' : trim(preg_replace('/\s+/u', ' ', $given) ?? '');

        // The form makes this required, so an empty name means the request did
        // not come through the form. Falling back to the suggestion is kinder
        // than refusing, and is exactly what the teacher was shown.
        return $given !== '' ? $given : $this->suggestedName($class, $period);
    }

    /**
     * @param  array<string, mixed>  $statistics
     * @return array<string, mixed>
     */
    protected function payload(
        SchoolClass $class,
        AcademicPeriod $period,
        Carbon $referenceDate,
        array $statistics,
        AssessmentCutoff $cutoff,
    ): array {
        $scale = $class->profileVersion?->scale()->with('levels')->first();

        // The reason behind each ⚠, from the service that already answers it —
        // read under the SAME cutoff, so the explanation belongs to the same
        // moment as the flag (§17).
        $notes = $this->coverage->forResults($this->calculator->forPeriod($class, $period, $cutoff));

        $domainLabels = [];

        foreach ($statistics['domains'] as $domain) {
            $domainLabels[$domain['id']] = $domain['name'];
        }

        return [
            'version' => InterimAssessment::CURRENT_VERSION,
            'reference_date' => $referenceDate->toDateString(),
            'captured_at' => now()->toIso8601String(),
            'class' => [
                'id' => (int) $class->id,
                'label_snapshot' => (string) $class->label,
                'subject_label_snapshot' => (string) $class->subject->name,
            ],
            'period' => [
                'id' => (int) $period->id,
                'label_snapshot' => (string) $period->label,
                'sequence' => (int) $period->sequence,
            ],
            // The scale as it stood, INCLUDING each band's INOVAR code: the grid
            // produced from this photograph months later must carry the code the
            // band had at the time.
            'scale' => $scale === null ? null : [
                'id' => (int) $scale->id,
                'name_snapshot' => (string) $scale->name,
                'kind' => (string) $scale->kind,
                'bands' => $scale->levels->sortBy('sequence')->values()->map(fn ($level): array => [
                    'scale_level_id' => (int) $level->id,
                    'code_snapshot' => (string) $level->code,
                    'label_snapshot' => (string) $level->label,
                    'sequence' => (int) $level->sequence,
                    'is_negative' => (bool) $level->is_negative,
                    'inovar_code_snapshot' => $level->inovar_code,
                ])->all(),
            ],
            'domains' => array_map(fn (array $domain): array => [
                'domain_id' => $domain['id'],
                'label_snapshot' => $domain['name'],
            ], $statistics['domains']),

            // The class-level figures, exactly as the screen showed them.
            'summary' => $statistics['summary'],
            'evolution' => $statistics['evolution'],
            // The grades as they stood, and the averages as they stood. Both
            // kept, because a photograph that recorded only one of them cannot
            // later be asked the other (§12).
            'assigned_distribution' => $statistics['assigned_distribution'],
            'distribution' => $statistics['distribution'],
            'domain_statistics' => $statistics['domain_statistics'],

            'students' => array_map(
                fn (array $student): array => $this->studentRow($student, $notes, $domainLabels),
                $statistics['students'],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $student
     * @param  array<int, array<string, mixed>>  $notes
     * @param  array<int, string>  $domainLabels
     * @return array<string, mixed>
     */
    protected function studentRow(array $student, array $notes, array $domainLabels): array
    {
        $enrollmentId = (int) $student['enrollment_id'];

        return [
            'enrollment_id' => $enrollmentId,
            // The name as it read then. Enough to show the photograph again
            // without joining anything live, and nothing beyond that (§45).
            'name_snapshot' => (string) $student['name'],
            'class_number' => $student['class_number'],
            'weighted_average' => $student['weighted_average'],
            'accumulated_average' => $student['accumulated_average'],
            'coverage_warning' => (bool) $student['coverage_warning'],
            'evolution' => $student['evolution'],
            'band' => $this->bandRow($student['band']),
            'domains' => array_map(function (array $cell) use ($enrollmentId, $notes, $domainLabels): array {
                $domainId = (int) $cell['domain_id'];

                return [
                    'domain_id' => $domainId,
                    'label_snapshot' => $domainLabels[$domainId] ?? null,
                    'weighted_average' => $cell['weighted_average'],
                    'accumulated_average' => $cell['accumulated_average'],
                    'coverage_warning' => (bool) $cell['coverage_warning'],
                    'evolution' => $cell['evolution'],
                    'mention' => $this->bandRow($cell['mention']),
                    'self_assessment' => $cell['self_assessment'],
                    // Instrument, date and the state RECORDED against it —
                    // never a state inferred from a missing score.
                    'coverage_elements' => $notes[$enrollmentId]['domains'][$domainId]['absences'] ?? [],
                ];
            }, $student['domains']),
            'self_assessment' => $student['self_assessment'],
            // What the teacher had decided BY THEN. Kept apart from the
            // proposal and from the mention: three different statements (§19).
            'classification' => $student['classification'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $band
     * @return array<string, mixed>|null
     */
    protected function bandRow(?array $band): ?array
    {
        return $band === null ? null : [
            'scale_level_id' => $band['scale_level_id'] ?? null,
            'code_snapshot' => $band['code'] ?? null,
            'label_snapshot' => $band['label'] ?? null,
            'sequence' => $band['sequence'] ?? null,
            'is_negative' => $band['is_negative'] ?? null,
        ];
    }
}
