<?php

namespace App\Services\Reporting\Source;

use App\Models\EvidenceRecord;
use App\Models\Intervention;
use App\Models\Report;
use App\Models\SchoolClass;

/**
 * The facts an individual report is built from.
 *
 * THE SAME SINGLE READ AS THE CLASS REPORT, then narrowed. A student's numbers
 * and their class's numbers come out of one call to BuildClassStatistics, which
 * is what guarantees that «72,1%, acima da média da turma (66,4%)» is two
 * figures from one moment rather than two reads that might disagree. Extending
 * the class source rather than writing a second one is the mechanism: there is
 * no other path to the numbers.
 *
 * WHAT IS NARROWED. The student's own row is lifted out under `student`, and
 * the logbook and the interventions are scoped to them — a report about João
 * counts João's records, not the class's. The class-level figures stay in place
 * beside them, because that is what the comparison sentences need.
 *
 * A STUDENT WHO IS NOT IN THE READ IS NOT INVENTED. If the enrolment has no row
 * — they left, or the class has no results at all — `student` is null and every
 * section says so rather than printing a document about nobody (§41).
 */
class StudentReportSource extends ClassReportSource
{
    /**
     * @return array<string, mixed>
     */
    public function factsFor(Report $report): array
    {
        $facts = parent::factsFor($report);

        return [
            ...$facts,
            'student' => $this->studentRow($facts, (int) $report->enrollment_id),
            'enrollment' => $this->enrollmentFacts($report),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>|null
     */
    protected function studentRow(array $facts, int $enrollmentId): ?array
    {
        foreach ((array) ($facts['students'] ?? []) as $student) {
            if (is_array($student) && (int) ($student['enrollment_id'] ?? 0) === $enrollmentId) {
                return $student;
            }
        }

        return null;
    }

    /**
     * The enrolment's own facts — the ones the class read model has no reason
     * to carry.
     *
     * @return array<string, mixed>
     */
    protected function enrollmentFacts(Report $report): array
    {
        $enrollment = $report->enrollment;

        if ($enrollment === null) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
            'class_number' => $enrollment->class_number,
            'is_late_entry' => (bool) $enrollment->is_late_entry,
            'enrolled_on' => $enrollment->enrolled_on->toDateString(),
            'left_on' => $enrollment->left_on?->toDateString(),
            'status' => $enrollment->status->value,
            'status_label' => $enrollment->status->label(),
            'is_current' => $enrollment->status->isCurrent(),
            'status_reason' => $enrollment->status_reason?->label(),
        ];
    }

    /**
     * This student's logbook, not the class's.
     *
     * The `enrollment_id IS NULL` entries — the ones about the class as a
     * whole — are deliberately left out: an individual report that counted
     * class-wide observations as the student's own would attribute to one
     * person what was written about twenty-six.
     *
     * @return array<string, mixed>
     */
    protected function recordFacts(Report $report, SchoolClass $class): array
    {
        $records = EvidenceRecord::query()
            ->forClass($class->id)
            ->where('enrollment_id', $report->enrollment_id)
            ->when($report->academic_period_id !== null,
                fn ($query) => $query->where('academic_period_id', $report->academic_period_id))
            ->get(['id', 'kind', 'enrollment_id', 'homework_status', 'occurred_at']);

        $byKind = [];

        foreach ($records as $record) {
            $key = $record->kind->value;

            $byKind[$key] ??= [
                'kind' => $key,
                'label' => $record->kind->label(),
                'group' => $record->kind->group()->value,
                'records' => 0,
                'students_involved' => 1,
            ];

            $byKind[$key]['records']++;
        }

        usort($byKind, fn (array $a, array $b) => $b['records'] <=> $a['records']);

        return [
            'total' => $records->count(),
            'students_involved' => $records->isEmpty() ? 0 : 1,
            'kinds' => $byKind,
            'homework' => $this->homeworkFacts($records),
        ];
    }

    /**
     * The interventions registered FOR this student, plus the class-wide ones
     * they were part of — named apart, because «uma medida dirigida a si» and
     * «uma medida dirigida à turma» are different facts about the same student.
     *
     * @return array<string, mixed>
     */
    protected function interventionFacts(Report $report, SchoolClass $class): array
    {
        $interventions = Intervention::query()
            ->where('class_id', $class->id)
            ->where('available_for_reports', true)
            ->where(fn ($query) => $query
                ->where('enrollment_id', $report->enrollment_id)
                ->orWhereNull('enrollment_id'))
            ->when($report->academic_period_id !== null, fn ($query) => $query
                ->where(fn ($inner) => $inner
                    ->where('academic_period_id', $report->academic_period_id)
                    ->orWhereNull('academic_period_id')))
            ->with('domain')
            ->orderBy('started_on')
            ->get();

        $individual = $interventions->where('enrollment_id', $report->enrollment_id);
        $classWide = $interventions->whereNull('enrollment_id');

        $byType = [];

        foreach ($individual as $intervention) {
            $key = $intervention->intervention_type->value ?? 'uncategorised';

            $byType[$key] ??= [
                'type' => $key,
                // Never the title — see ClassReportSource::interventionFacts.
                'label' => $intervention->intervention_type?->label(),
                'count' => 0,
            ];

            $byType[$key]['count']++;
        }

        usort($byType, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return [
            'total' => $individual->count(),
            'class_wide' => $classWide->count(),
            'students_involved' => $individual->isEmpty() ? 0 : 1,
            'types' => $byType,
            'concluded' => $individual->filter(fn (Intervention $intervention) => $intervention->status->value === 'concluded')->count(),
            'highlighted' => array_values($individual
                // Typed only — see ClassReportSource::interventionFacts.
                ->filter(fn (Intervention $intervention) => $intervention->include_in_report
                    && $intervention->intervention_type !== null)
                ->map(fn (Intervention $intervention) => [
                    'title' => $intervention->title,
                    'type' => $intervention->intervention_type?->label(),
                    'domain' => $intervention->domain?->name,
                    'status' => $intervention->status->label(),
                    'started_on' => $intervention->started_on->toDateString(),
                ])->all()),
        ];
    }
}
