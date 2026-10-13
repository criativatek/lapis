<?php

namespace App\Services\Evidence\Followup;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupCategory;
use App\Models\RecordFollowupConfirmation;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The follow-up in the data export (schema v15, docs/backup-schema.md):
 *
 * - the technical backup carries the FACTS — each case (key, alert) and every
 *   «Registado no Inovar» declaration, undone ones included, with who and when
 *   as emails and the occurrences each one covered. Never a count: counts are
 *   recomputed from the logbook after a restore;
 * - the readable workbook carries the STATE — per student, class, category and
 *   period: the occurrences now, the status, the alert and the declarations —
 *   including cases that reached three before alerts existed.
 *
 * Scoped by the classes the export already includes (the ones the person
 * teaches) — never another teacher's class, never another organization.
 */
final class RecordFollowupExport
{
    public function __construct(private readonly RecordFollowupCounter $counter) {}

    /**
     * @param  Collection<int, int>  $classIds
     * @return Collection<int, RecordFollowupCase>
     */
    public function cases(Collection $classIds): Collection
    {
        if ($classIds->isEmpty()) {
            return collect();
        }

        return RecordFollowupCase::query()
            ->whereIn('class_id', $classIds)
            ->with('confirmations')
            ->orderBy('id')
            ->get();
    }

    /**
     * Who confirmed or undid any declaration — merged into the export's
     * author map so they travel as emails.
     *
     * @param  Collection<int, RecordFollowupCase>  $cases
     * @return list<int>
     */
    public function authorIds(Collection $cases): array
    {
        $ids = [];

        foreach ($cases as $case) {
            foreach ($case->confirmations as $confirmation) {
                foreach ([$confirmation->confirmed_by, $confirmation->undone_by] as $id) {
                    if ($id !== null) {
                        $ids[(int) $id] = (int) $id;
                    }
                }
            }
        }

        return array_values($ids);
    }

    /**
     * @param  Collection<int, RecordFollowupCase>  $cases
     * @param  Collection<int, SchoolClass>  $classesById
     * @param  Collection<int, Enrollment>  $enrollmentsById
     * @param  Collection<int, AcademicPeriod>  $periodsById
     * @param  Collection<int, User>  $authorsById
     * @return array{record_followup_cases: list<array<string, mixed>>, record_followup_confirmations: list<array<string, mixed>>}
     */
    public function backupRows(Collection $cases, Collection $classesById, Collection $enrollmentsById, Collection $periodsById, Collection $authorsById): array
    {
        $caseRows = [];
        $confirmationRows = [];

        foreach ($cases as $case) {
            $class = $classesById->get($case->class_id);
            $enrollment = $enrollmentsById->get($case->enrollment_id);
            $period = $periodsById->get($case->academic_period_id);

            if ($class === null || $enrollment === null || $period === null) {
                continue;
            }

            $caseRows[] = [
                'ulid' => $case->ulid,
                'class_ulid' => $class->ulid,
                'enrollment_ulid' => $enrollment->ulid,
                'academic_period_ulid' => $period->ulid,
                'category' => $case->category->value,
                'alerted_at' => $case->alerted_at?->toIso8601String(),
                'alerted_count' => $case->alerted_count,
                'created_at' => $case->created_at?->toIso8601String(),
                'updated_at' => $case->updated_at?->toIso8601String(),
            ];

            foreach ($case->confirmations as $confirmation) {
                $confirmationRows[] = [
                    'ulid' => $confirmation->ulid,
                    'case_ulid' => $case->ulid,
                    'confirmed_by_email' => $confirmation->confirmed_by === null ? null : $authorsById->get($confirmation->confirmed_by)?->email,
                    'confirmed_at' => $confirmation->confirmed_at->toIso8601String(),
                    'occurrence_count' => $confirmation->occurrence_count,
                    'occurrences' => array_map(
                        fn (array $occurrence): array => ['ulid' => $occurrence['ulid'] ?? null, 'date' => $occurrence['date']],
                        $confirmation->occurrences,
                    ),
                    'undone_by_email' => $confirmation->undone_by === null ? null : $authorsById->get($confirmation->undone_by)?->email,
                    'undone_at' => $confirmation->undone_at?->toIso8601String(),
                    'created_at' => $confirmation->created_at?->toIso8601String(),
                    'updated_at' => $confirmation->updated_at?->toIso8601String(),
                ];
            }
        }

        return ['record_followup_cases' => $caseRows, 'record_followup_confirmations' => $confirmationRows];
    }

    /**
     * One line per student × class × category × period that is part of the
     * follow-up: three or more occurrences now, or a case on record (an alert
     * or a declaration). Oldest year and period first, then class, number.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, RecordFollowupCase>  $cases
     * @param  Collection<int, Enrollment>  $enrollmentsById  with student.identity
     * @param  Collection<int, User>  $authorsById
     * @return list<array<string, mixed>>
     */
    public function sheetRows(Collection $classes, Collection $cases, Collection $enrollmentsById, Collection $authorsById): array
    {
        $casesByKey = $cases->keyBy(fn (RecordFollowupCase $case): string => $case->class_id.':'.$case->enrollment_id.':'.$case->academic_period_id.':'.$case->category->value);
        $lines = [];

        foreach ($classes->groupBy('academic_year_id') as $yearId => $yearClasses) {
            $timeline = AssessmentPeriodTimeline::forYear((int) $yearId);
            $classIds = array_values($yearClasses->map(fn (SchoolClass $class): int => $class->id)->all());

            foreach ($timeline->periods() as $period) {
                $window = FollowupWindow::forPeriod($period, $timeline);

                // One query per period: every follow-up occurrence of these classes.
                $datesByKey = [];

                foreach ($this->counter->occurrences($classIds, $window)->get() as $record) {
                    /** @var EvidenceRecord $record */
                    $category = RecordFollowupCategory::fromRecord($record->kind, $record->homework_status);

                    if ($category !== null) {
                        $datesByKey[$record->class_id.':'.$record->enrollment_id.':'.$period->id.':'.$category->value][] = $record->occurred_at->format('Y-m-d');
                    }
                }

                // Cases on record for this period and these classes, whatever
                // their count now (a declaration whose records were corrected
                // since still belongs in the follow-up).
                $caseKeys = $casesByKey->keys()->filter(function (string $key) use ($period, $classIds): bool {
                    [$classId, , $periodId] = explode(':', $key);

                    return (int) $periodId === $period->id && in_array((int) $classId, $classIds, true);
                })->all();

                $keys = array_unique(array_merge(
                    array_keys(array_filter($datesByKey, fn (array $dates): bool => count($dates) >= RecordFollowupCategory::ALERT_THRESHOLD)),
                    $caseKeys,
                ));

                foreach ($keys as $key) {
                    [$classId, $enrollmentId, , $categoryValue] = explode(':', (string) $key);
                    $class = $yearClasses->firstWhere('id', (int) $classId);
                    $enrollment = $enrollmentsById->get((int) $enrollmentId);
                    $case = $casesByKey->get($key);
                    $dates = $datesByKey[$key] ?? [];
                    sort($dates);

                    if ($class === null || $enrollment === null) {
                        continue;
                    }

                    $active = $case?->confirmations->first(fn (RecordFollowupConfirmation $confirmation): bool => $confirmation->undone_at === null);
                    $status = RecordFollowupCaseBook::status(count($dates), $case === null ? null : $case->setRelation('activeConfirmation', $active));

                    $lines[] = [
                        'sort' => [$class->academicYear->starts_on->format('Y-m-d'), $period->sequence, $class->label, $enrollment->class_number ?? PHP_INT_MAX, $enrollment->id, $categoryValue],
                        'academic_year' => $class->academicYear->label,
                        'class' => $class->label,
                        'subject' => $class->subject->name,
                        'number' => $enrollment->class_number,
                        'student' => $enrollment->student->identity->display_name ?? __('(sem identidade)'),
                        'category' => RecordFollowupCategory::from($categoryValue)->label(),
                        'period' => $period->label,
                        'occurrences' => count($dates),
                        'dates' => $dates,
                        'status' => match ($status) {
                            RecordFollowupCaseBook::STATUS_REGISTERED => __('Registado no Inovar'),
                            RecordFollowupCaseBook::STATUS_PENDING => __('Por tratar'),
                            default => '—',
                        },
                        'alerted_at' => $case?->alerted_at,
                        'confirmed_at' => $active?->confirmed_at,
                        'confirmed_by' => $active?->confirmed_by === null ? null : $authorsById->get($active->confirmed_by)?->name,
                        'declared_dates' => $active === null ? [] : array_map(fn (array $occurrence): string => $occurrence['date'], $active->occurrences),
                        'history' => $case === null ? [] : $case->confirmations
                            ->filter(fn (RecordFollowupConfirmation $confirmation): bool => $confirmation->undone_at !== null)
                            ->map(fn (RecordFollowupConfirmation $confirmation): string => (string) __('Marcado em :marked e desfeito em :undone', [
                                'marked' => $confirmation->confirmed_at->format('d/m/Y H:i'),
                                'undone' => $confirmation->undone_at?->format('d/m/Y H:i') ?? '—',
                            ]))
                            ->values()->all(),
                    ];
                }
            }
        }

        usort($lines, fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        return array_map(function (array $line): array {
            unset($line['sort']);

            return $line;
        }, $lines);
    }
}
