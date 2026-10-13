<?php

namespace App\Services\Evidence\Followup;

use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\LessonAttendance;
use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupCategory;
use App\Models\RecordFollowupConfirmation;
use App\Models\SchoolClass;
use App\Services\Evidence\EvidenceRecordLabels;
use App\Services\Lessons\ConsolidatedAbsences;
use App\Services\Lessons\StudentAttendanceHistory;
use App\Support\Language\StudentNameParts;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Shapes the follow-up for the consultation screen. Reads only what the
 * counters and the case book give it — it never counts on its own.
 *
 * Four direct accesses: the three logbook categories (with alerts, status and
 * the Inovar message) and «Faltas de presença», read from the lessons'
 * attendance (ConsolidatedAbsences) — consultation and counts only, no alert
 * and no message (decisão do dono do produto, 2026-10-13).
 */
final class RecordFollowupPresenter
{
    public const int FOLLOWUP_PER_PAGE = 25;

    /** The view key of the attendance access — not a RecordFollowupCategory. */
    public const string ABSENCES = 'absences';

    public function __construct(
        private readonly RecordFollowupCounter $counter,
        private readonly RecordFollowupCaseBook $cases,
        private readonly ConsolidatedAbsences $absences,
        private readonly StudentAttendanceHistory $attendanceHistory,
    ) {}

    /**
     * The three direct accesses: distinct students and occurrences per
     * category in the window, plus «por tratar» when the window is a period.
     *
     * @return list<array{category: string, label: string, unit: string, students: int, cases: int, occurrences: int, pending: int|null}>
     */
    public function tiles(RecordConsultationScope $scope): array
    {
        if ($scope->window === null) {
            return [];
        }

        $summary = $this->counter->summary($scope->classIds(), $scope->window, $scope->studentId());
        $pending = $scope->window->isPeriod() ? $this->cases->pendingCounts($scope->classIds(), $scope->window, $scope->studentId()) : null;

        $tiles = array_map(fn (RecordFollowupCategory $category): array => [
            'category' => $category->value,
            'label' => $category->label(),
            'unit' => 'occurrence',
            'students' => $summary[$category->value]['students'],
            'cases' => $summary[$category->value]['cases'],
            'occurrences' => $summary[$category->value]['occurrences'],
            'pending' => $pending === null ? null : $pending[$category->value],
        ], RecordFollowupCategory::cases());

        $absences = $this->absences->summary($scope->classIds(), $scope->window, $scope->studentId());

        $tiles[] = [
            'category' => self::ABSENCES,
            'label' => (string) __('Faltas de presença'),
            'unit' => 'absence',
            'students' => $absences['students'],
            'cases' => $absences['cases'],
            'occurrences' => $absences['absences'],
            // No alert, no «por tratar»: consultation and counts only.
            'pending' => null,
        ];

        return $tiles;
    }

    /**
     * Only the students with occurrences of the category, most first; ties
     * broken by class, number in class and enrollment — stable across pages.
     * Names are decrypted for the page shown, never for the whole list.
     *
     * @param  array<string, mixed>  $query
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function followupRows(RecordConsultationScope $scope, RecordFollowupCategory|string $view, int $page, string $path, array $query): LengthAwarePaginator
    {
        $window = $scope->window;

        if ($window === null) {
            return (new LengthAwarePaginator([], 0, self::FOLLOWUP_PER_PAGE, 1, ['path' => $path, 'pageName' => 'followup_page']))->appends($query);
        }

        $students = $scope->studentId() === null ? null : [$scope->studentId()];
        $category = $view instanceof RecordFollowupCategory ? $view : null;

        $rows = $category === null
            ? $this->absences->perStudent($scope->classIds(), $window, $students)
            : $this->counter->perStudent($scope->classIds(), $window, $category, $students)
                ->map(fn (array $row): array => array_merge($row, ['days' => null]));

        $enrollments = Enrollment::query()
            ->whereIn('id', $rows->pluck('enrollment_id')->unique()->values()->all())
            ->get(['id', 'ulid', 'class_id', 'class_number', 'student_id'])
            ->keyBy('id');
        $classes = $scope->yearClasses->keyBy('id');
        // The class order the filters already show (label, then id).
        $classPosition = $scope->yearClasses->pluck('id')->flip();

        $sorted = $rows
            ->sort(function (array $left, array $right) use ($enrollments, $classPosition): int {
                return [$right['occurrences'], $classPosition->get($left['class_id']), $enrollments->get($left['enrollment_id'])->class_number ?? PHP_INT_MAX, $left['enrollment_id']]
                    <=> [$left['occurrences'], $classPosition->get($right['class_id']), $enrollments->get($right['enrollment_id'])->class_number ?? PHP_INT_MAX, $right['enrollment_id']];
            })
            ->values();

        // How many cases each student has in the WHOLE list (not just this
        // page) — so a line can say the same student is followed elsewhere.
        $casesPerStudent = $sorted->countBy(fn (array $row): int => (int) $enrollments->get($row['enrollment_id'])?->student_id);

        $lastPage = max(1, (int) ceil($sorted->count() / self::FOLLOWUP_PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $slice = $sorted->slice(($page - 1) * self::FOLLOWUP_PER_PAGE, self::FOLLOWUP_PER_PAGE)->values();

        $identities = Enrollment::query()
            ->whereIn('id', $slice->pluck('enrollment_id')->all())
            ->with('student.identity')
            ->get()
            ->keyBy('id');

        $caseRows = $window->period() === null || $category === null ? collect() : $this->cases->forPeriod($scope->classIds(), $window->period()->id, $category);

        $data = array_values($slice->map(fn (array $row): array => $this->followupRow(
            $row,
            $identities->get($row['enrollment_id']),
            $classes->get($row['class_id']),
            $caseRows->get($row['class_id'].':'.$row['enrollment_id']),
            $category === null ? null : $window,
            (int) $casesPerStudent->get((int) $enrollments->get($row['enrollment_id'])?->student_id, 1),
        ))->all());

        return (new LengthAwarePaginator($data, $sorted->count(), self::FOLLOWUP_PER_PAGE, $page, [
            'path' => $path,
            'pageName' => 'followup_page',
        ]))->appends($query);
    }

    /**
     * `$window` null for absences: they have no status and no alert.
     *
     * @param  array{class_id: int, enrollment_id: int, occurrences: int, days: int|null, first_at: string, last_at: string}  $row
     * @return array<string, mixed>
     */
    private function followupRow(array $row, ?Enrollment $enrollment, ?SchoolClass $class, ?RecordFollowupCase $case, ?FollowupWindow $window, int $studentCases): array
    {
        return [
            // The student's identity in this list (never an id): the same key
            // on two lines means the same student, followed in two classes.
            'student_key' => $enrollment?->student->ulid,
            'student_cases' => $studentCases,
            'class_ulid' => $class?->ulid,
            'class_label' => $class?->label,
            'subject' => $class?->subject->name,
            'student_ulid' => $enrollment?->ulid,
            'number' => $enrollment?->class_number,
            'name' => optional($enrollment?->student->identity)->display_name ?? __('(sem identidade)'),
            'occurrences' => $row['occurrences'],
            // Absences only: on how many distinct days.
            'days' => $row['days'],
            'first_date' => substr($row['first_at'], 0, 10),
            'last_date' => substr($row['last_at'], 0, 10),
            'status' => $window?->isPeriod() === true ? RecordFollowupCaseBook::status($row['occurrences'], $case) : null,
            'origin' => $window?->isPeriod() === true ? RecordFollowupCaseBook::origin($row['occurrences'], $case) : null,
        ];
    }

    /**
     * @param  Collection<int, array{case: RecordFollowupCase, occurrences: int}>  $alerts
     * @return list<array<string, mixed>>
     */
    public function alertRows(Collection $alerts): array
    {
        return array_values($alerts->map(fn (array $alert): array => $this->alertRow($alert))->all());
    }

    /**
     * @param  array{case: RecordFollowupCase, occurrences: int}  $alert
     * @return array<string, mixed>
     */
    private function alertRow(array $alert): array
    {
        return [
            'case_ulid' => $alert['case']->ulid,
            'class_ulid' => $alert['case']->schoolClass->ulid,
            'class_label' => $alert['case']->schoolClass->label,
            'subject' => $alert['case']->schoolClass->subject->name,
            'student_ulid' => $alert['case']->enrollment->ulid,
            'number' => $alert['case']->enrollment->class_number,
            'name' => optional($alert['case']->enrollment->student->identity)->display_name ?? __('(sem identidade)'),
            'category' => $alert['case']->category->value,
            'category_label' => $alert['case']->category->label(),
            'occurrences' => $alert['occurrences'],
            'period_ulid' => $alert['case']->academicPeriod->ulid,
            'period_label' => $alert['case']->academicPeriod->label,
            'alerted_at' => $alert['case']->alerted_at?->toIso8601String(),
        ];
    }

    /**
     * Everything the case dialog shows: the occurrences, the message prepared
     * from exactly those occurrences, the status and the declarations.
     *
     * @return array<string, mixed>
     */
    public function caseDetail(SchoolClass $class, Enrollment $enrollment, RecordFollowupCategory $category, FollowupWindow $window, bool $canConfirm): array
    {
        $occurrences = $this->counter->caseOccurrences($class->id, $enrollment->id, $category, $window);
        $enrollment->loadMissing('student.identity');
        $fullName = $enrollment->student->identity?->display_name;
        $shortName = StudentNameParts::firstAndLast($fullName);

        $case = $window->period() === null
            ? null
            : RecordFollowupCase::query()
                ->where('class_id', $class->id)
                ->where('enrollment_id', $enrollment->id)
                ->where('academic_period_id', $window->period()->id)
                ->where('category', $category->value)
                ->with(['activeConfirmation.confirmer', 'confirmations.confirmer', 'confirmations.undoer'])
                ->first();

        // Nothing to say about zero occurrences — never «acumulou zero».
        $message = $occurrences->isEmpty()
            ? ['text' => '', 'missing' => []]
            : InovarFollowupMessage::compose(
                $category,
                $enrollment->class_number,
                $shortName,
                $class->subject->name,
                $window->messagePhrase(),
                array_values($occurrences->map(fn (EvidenceRecord $record): CarbonInterface => $record->occurred_at)->all()),
            );

        $currentPairs = array_values($occurrences->map(fn (EvidenceRecord $record): string => $record->ulid.'|'.$record->occurred_at->format('Y-m-d'))->all());
        $active = $case?->activeConfirmation;

        return [
            'class' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'student' => [
                'ulid' => $enrollment->ulid,
                'number' => $enrollment->class_number,
                'name' => $fullName ?? __('(sem identidade)'),
                'short_name' => $shortName,
            ],
            'category' => $category->value,
            'category_label' => $category->label(),
            'window' => [
                'is_period' => $window->isPeriod(),
                'label' => $window->label(),
                'period_ulid' => $window->period()?->ulid,
            ],
            'occurrences' => $occurrences->map(fn (EvidenceRecord $record): array => [
                'ulid' => $record->ulid,
                'date' => $record->occurred_at->format('Y-m-d'),
                'detail_label' => EvidenceRecordLabels::detail($record),
                'description' => $record->description,
            ])->values()->all(),
            'count' => $occurrences->count(),
            'status' => $window->isPeriod() ? RecordFollowupCaseBook::status($occurrences->count(), $case) : null,
            'origin' => $window->isPeriod() ? RecordFollowupCaseBook::origin($occurrences->count(), $case) : null,
            'alerted_at' => $case?->alerted_at?->toIso8601String(),
            'alerted_count' => $case?->alerted_count,
            'message' => $message,
            'confirmation' => $active === null ? null : $this->confirmationRow($active, $currentPairs),
            'history' => $case === null ? [] : $case->confirmations
                ->map(fn (RecordFollowupConfirmation $confirmation): array => [
                    'ulid' => $confirmation->ulid,
                    'confirmed_by' => $confirmation->confirmer?->name,
                    'confirmed_at' => $confirmation->confirmed_at->toIso8601String(),
                    'count' => $confirmation->occurrence_count,
                    'undone_by' => $confirmation->undoer?->name,
                    'undone_at' => $confirmation->undone_at?->toIso8601String(),
                    'restored' => $confirmation->restored_at !== null,
                ])->values()->all(),
            'can_confirm' => $canConfirm && $window->isPeriod(),
            'homework' => $category === RecordFollowupCategory::HomeworkNotDone ? $this->homeworkBreakdown($class, $enrollment, $window) : null,
        ];
    }

    /**
     * What the teacher marked for this student's homework in the window, by
     * state — and on how many days the class had homework marked while the
     * student was in it without a marking of their own. Never «days TPC was
     * set»: the application does not know that, and does not invent it.
     *
     * @return array{done: int, partially_done: int, not_done: int, verified: int, class_days: int, unmarked_days: int}
     */
    private function homeworkBreakdown(SchoolClass $class, Enrollment $enrollment, FollowupWindow $window): array
    {
        $base = EvidenceRecord::query()
            ->where('evidence_records.class_id', $class->id)
            ->whereNotNull('evidence_records.enrollment_id')
            ->where('evidence_records.kind', EvidenceKind::Homework->value);
        $window->applyTo($base);

        $own = (clone $base)->where('evidence_records.enrollment_id', $enrollment->id);
        $byStatus = (clone $own)->toBase()
            ->select('evidence_records.homework_status')
            ->selectRaw('count(*) as total')
            ->groupBy('evidence_records.homework_status')
            ->pluck('total', 'homework_status')
            ->map(fn ($total): int => (int) $total);

        $day = 'date(evidence_records.occurred_at)';
        $ownDays = (clone $own)->toBase()->selectRaw("{$day} as day")->distinct()->pluck('day')->map(fn ($value): string => substr((string) $value, 0, 10));
        $enrolledOn = $enrollment->enrolled_on->format('Y-m-d');
        $leftOn = $enrollment->left_on?->format('Y-m-d');
        $classDays = (clone $base)->toBase()->selectRaw("{$day} as day")->distinct()->pluck('day')
            ->map(fn ($value): string => substr((string) $value, 0, 10))
            ->filter(fn (string $value): bool => $value >= $enrolledOn && ($leftOn === null || $value <= $leftOn))
            ->values();

        return [
            'done' => $byStatus->get(HomeworkStatus::Done->value, 0),
            'partially_done' => $byStatus->get(HomeworkStatus::PartiallyDone->value, 0),
            'not_done' => $byStatus->get(HomeworkStatus::NotDone->value, 0),
            'verified' => $byStatus->sum(),
            'class_days' => $classDays->count(),
            'unmarked_days' => $classDays->diff($ownDays)->count(),
        ];
    }

    /**
     * One student's absences in one class — the lessons, each with its date,
     * time and number, and a way to open the lesson where the attendance was
     * registered. Also how many taught lessons of the class are still without
     * attendance in the window, which are NOT absences and are said apart.
     *
     * @return array<string, mixed>
     */
    public function absenceDetail(SchoolClass $class, Enrollment $enrollment, FollowupWindow $window): array
    {
        $absences = $this->absences->caseAbsences($class->id, $enrollment->id, $window);
        $enrollment->loadMissing('student.identity');

        // The same history the student's Evolução shows — for the lessons
        // still without attendance in the window (never counted as absences).
        $history = $this->attendanceHistory->for($enrollment, $window->from()->toDateString(), $window->to()->toDateString());

        return [
            'kind' => self::ABSENCES,
            'class' => ['ulid' => $class->ulid, 'label' => $class->label, 'subject' => $class->subject->name],
            'student' => [
                'ulid' => $enrollment->ulid,
                'number' => $enrollment->class_number,
                'name' => $enrollment->student->identity->display_name ?? __('(sem identidade)'),
            ],
            'category_label' => (string) __('Faltas de presença'),
            'window' => ['is_period' => $window->isPeriod(), 'label' => $window->label(), 'period_ulid' => $window->period()?->ulid],
            'absences' => array_values($absences->map(fn (LessonAttendance $attendance): array => [
                'ulid' => $attendance->ulid,
                'lesson_ulid' => $attendance->lesson->ulid,
                'date' => $attendance->lesson->starts_at->format('Y-m-d'),
                'time' => $attendance->lesson->starts_at->format('H:i'),
                'lesson_number' => $attendance->lesson->lesson_number,
                'context_label' => $attendance->lesson->contextLabel(),
            ])->all()),
            'count' => $absences->count(),
            'days' => $absences->map(fn (LessonAttendance $attendance): string => $attendance->lesson->starts_at->format('Y-m-d'))->unique()->count(),
            'not_recorded' => $history['totals']['not_recorded'],
        ];
    }

    /**
     * The declaration in force and how the source records moved since — what
     * was declared and no longer counts, and what counts now and was not
     * declared. It never says the Inovar record was corrected.
     *
     * @param  list<string>  $currentPairs  «ulid|Y-m-d» of the occurrences counting now
     * @return array<string, mixed>
     */
    private function confirmationRow(RecordFollowupConfirmation $confirmation, array $currentPairs): array
    {
        // Compared as {ulid, date}: a date moved inside the period is a change
        // too — the old date no longer counts, the new one is not declared.
        // An occurrence without a ulid was declared but its record could not
        // be recovered by a restore: said apart («não recuperada»), never as
        // a record that stopped counting.
        $declared = collect($confirmation->occurrences);
        [$recovered, $missing] = $declared->partition(fn (array $occurrence): bool => $occurrence['ulid'] !== null);
        $declaredPairs = $recovered->map(fn (array $occurrence): string => $occurrence['ulid'].'|'.$occurrence['date'])->all();

        return [
            'ulid' => $confirmation->ulid,
            'confirmed_by' => $confirmation->confirmer?->name,
            'confirmed_at' => $confirmation->confirmed_at->toIso8601String(),
            'count' => $confirmation->occurrence_count,
            'dates' => $declared->pluck('date')->values()->all(),
            'restored' => $confirmation->restored_at !== null,
            'missing' => $missing->pluck('date')->sort()->values()->all(),
            'changes' => [
                'added' => collect($currentPairs)
                    ->reject(fn (string $pair): bool => in_array($pair, $declaredPairs, true))
                    ->map(fn (string $pair): string => substr($pair, -10))
                    ->sort()->values()->all(),
                'removed' => $recovered
                    ->reject(fn (array $occurrence): bool => in_array($occurrence['ulid'].'|'.$occurrence['date'], $currentPairs, true))
                    ->pluck('date')
                    ->sort()->values()->all(),
            ],
        ];
    }
}
