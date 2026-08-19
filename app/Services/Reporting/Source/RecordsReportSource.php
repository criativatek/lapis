<?php

namespace App\Services\Reporting\Source;

use App\Domain\Reporting\RecordValence;
use App\Models\EvidenceInternalGroup;
use App\Models\EvidenceRecord;
use App\Models\Report;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The facts a Registos report is built from (§21, §22, §23).
 *
 * THE UNIT IS THE RECORD, AND EVERY COUNT SAYS SO. «18 ocorrências disciplinares
 * envolvendo 7 alunos» is two facts about two different objects. Keeping both,
 * separately named, is what stops «82% dos registos de TPC estavam realizados»
 * from ever becoming «18% dos alunos não trabalham» — a statement about people
 * that the data does not contain and that would be defamatory about children
 * (§70, §71).
 *
 * IT COUNTS WHAT IS THERE AND CONCLUDES NOTHING (§22). A rise in occurrences
 * between two months is a rise in occurrences: it may mean behaviour worsened,
 * or that a teacher started writing things down. The report does not choose.
 *
 * THE FILTERS ARE PART OF THE FACTS. A reader must be able to see that this
 * document covers one class, one kind and six weeks — otherwise «18 registos» is
 * a number about nothing in particular (§29).
 */
class RecordsReportSource implements ReportSource
{
    /** A detailed timeline is a long document; this is where it stops. */
    public const TIMELINE_LIMIT = 500;

    /**
     * @return array<string, mixed>
     */
    public function factsFor(Report $report): array
    {
        $records = $this->query($report)
            ->with(['enrollment.student.identity', 'domain', 'schoolClass.subject'])
            ->orderBy('occurred_at')
            ->get();

        if ($records->isEmpty()) {
            return [
                'available' => true,
                'origin' => 'live',
                'class' => $this->classFacts($report),
                'filters' => $this->filterFacts($report),
                'total' => 0,
                'students_involved' => 0,
                'kinds' => [],
                'groups' => [],
                'valences' => [],
                'months' => [],
                'homework' => null,
                'timeline' => [],
                'truncated' => false,
            ];
        }

        return [
            'available' => true,
            'origin' => 'live',
            'class' => $this->classFacts($report),
            'filters' => $this->filterFacts($report),
            'total' => $records->count(),
            'students_involved' => $records->whereNotNull('enrollment_id')->pluck('enrollment_id')->unique()->count(),
            'class_wide' => $records->whereNull('enrollment_id')->count(),
            // Non-null: the empty case returned above.
            'first_on' => $records->first()->occurred_at->toDateString(),
            'last_on' => $records->last()->occurred_at->toDateString(),
            'kinds' => $this->byKind($records),
            'groups' => $this->byGroup($records),
            'valences' => $this->byValence($records),
            'months' => $this->byMonth($records),
            'homework' => $this->homework($records),
            'timeline' => $this->timeline($report, $records),
            'truncated' => $records->count() > self::TIMELINE_LIMIT,
        ];
    }

    /**
     * @return Builder<EvidenceRecord>
     */
    protected function query(Report $report): Builder
    {
        $query = EvidenceRecord::query();

        if ($report->class_id !== null) {
            $query->where('class_id', $report->class_id);
        } else {
            // NO CLASS CHOSEN MEANS «AS MINHAS TURMAS», NOT «TODAS».
            //
            // The tenant scope already keeps another school out. Within a
            // school, a report must not quietly aggregate a colleague's logbook
            // — «only my classes» is this application's central rule (§23 of
            // CLAUDE.md), and a records report is the one place where dropping
            // a filter could otherwise widen it silently.
            $query->whereIn('class_id', SchoolClass::query()
                ->whereHas('teachers', fn ($teachers) => $teachers->whereKey($report->created_by))
                ->select('id'));
        }

        // The year is always present on a records report — the CHECK says so —
        // and it bounds the read even when no class does.
        if ($report->academic_year_id !== null) {
            $query->whereIn('class_id', SchoolClass::query()
                ->where('academic_year_id', $report->academic_year_id)
                ->select('id'));
        }

        if ($report->enrollment_id !== null) {
            $query->where('enrollment_id', $report->enrollment_id);
        }

        if ($report->academic_period_id !== null) {
            $query->where('academic_period_id', $report->academic_period_id);
        }

        $kinds = $report->option('kinds');

        if (is_array($kinds) && $kinds !== []) {
            $query->whereIn('kind', $kinds);
        }

        // §29: an explicit interval, applied as a whole-day range so that a
        // record made in the evening of the last day is inside it.
        if ($report->starts_on !== null) {
            $query->where('occurred_at', '>=', $report->starts_on->copy()->startOfDay());
        }

        if ($report->ends_on !== null) {
            $query->where('occurred_at', '<=', $report->ends_on->copy()->endOfDay());
        }

        return $query;
    }

    /**
     * The class, when the report is about one. Null when it spans every class
     * the author teaches — and then the scope paragraph says so rather than
     * naming a class the reader would take as the only one.
     *
     * @return array<string, mixed>|null
     */
    protected function classFacts(Report $report): ?array
    {
        $class = $report->schoolClass;

        if ($class === null) {
            return null;
        }

        return [
            'label' => $class->label,
            'grade_level' => $class->grade_level,
            'subject' => $class->subject->name,
            'academic_year' => $class->academicYear->label,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function filterFacts(Report $report): array
    {
        $kinds = $report->option('kinds');

        return [
            'kinds' => is_array($kinds) ? array_values($kinds) : [],
            'group' => $report->option('group'),
            'valence' => $report->option('valence'),
            'detailed' => (bool) $report->option('detailed', false),
            'names_students' => $report->namesStudents(),
            'starts_on' => $report->starts_on?->toDateString(),
            'ends_on' => $report->ends_on?->toDateString(),
        ];
    }

    /**
     * @param  Collection<int, EvidenceRecord>  $records
     * @return list<array<string, mixed>>
     */
    protected function byKind(Collection $records): array
    {
        $rows = [];

        foreach ($records as $record) {
            $key = $record->kind->value;

            $rows[$key] ??= [
                'kind' => $key,
                'label' => $record->kind->label(),
                'group' => $record->kind->group()->value,
                'records' => 0,
                'students' => [],
            ];

            $rows[$key]['records']++;

            if ($record->enrollment_id !== null) {
                $rows[$key]['students'][$record->enrollment_id] = true;
            }
        }

        $rows = array_values(array_map(fn (array $row): array => [
            'kind' => $row['kind'],
            'label' => $row['label'],
            'group' => $row['group'],
            'records' => $row['records'],
            'students_involved' => count($row['students']),
        ], $rows));

        usort($rows, fn (array $a, array $b) => $b['records'] <=> $a['records']);

        return $rows;
    }

    /**
     * @param  Collection<int, EvidenceRecord>  $records
     * @return list<array<string, mixed>>
     */
    protected function byGroup(Collection $records): array
    {
        $rows = [];

        foreach ($records as $record) {
            $group = $record->kind->group();

            $rows[$group->value] ??= ['group' => $group->value, 'label' => $group->label(), 'records' => 0];
            $rows[$group->value]['records']++;
        }

        return array_values($rows);
    }

    /**
     * @param  Collection<int, EvidenceRecord>  $records
     * @return list<array<string, mixed>>
     */
    protected function byValence(Collection $records): array
    {
        $counts = [];

        foreach ($records as $record) {
            $valence = RecordValence::of($record);

            $counts[$valence->value] ??= ['valence' => $valence->value, 'label' => $valence->label(), 'records' => 0];
            $counts[$valence->value]['records']++;
        }

        // A stable order regardless of what happens to appear first.
        $ordered = [];

        foreach (RecordValence::cases() as $case) {
            if (isset($counts[$case->value])) {
                $ordered[] = $counts[$case->value];
            }
        }

        return $ordered;
    }

    /**
     * The distribution over time (§23).
     *
     * BY MONTH, NOT BY WEEK OR DAY. A logbook is written irregularly, and a
     * finer grain would produce a jagged line that invites reading a trend into
     * what is mostly when the teacher had time to type.
     *
     * @param  Collection<int, EvidenceRecord>  $records
     * @return list<array<string, mixed>>
     */
    protected function byMonth(Collection $records): array
    {
        $months = [];

        foreach ($records as $record) {
            $key = $record->occurred_at->format('Y-m');

            $months[$key] ??= ['month' => $key, 'label' => $record->occurred_at->translatedFormat('F \d\e Y'), 'records' => 0];
            $months[$key]['records']++;
        }

        ksort($months);

        return array_values($months);
    }

    /**
     * @param  Collection<int, EvidenceRecord>  $records
     * @return array<string, mixed>|null
     */
    protected function homework(Collection $records): ?array
    {
        $homework = $records->filter(fn (EvidenceRecord $record) => $record->homework_status !== null);

        if ($homework->isEmpty()) {
            return null;
        }

        $checks = $homework->count();
        $done = $homework->filter(fn (EvidenceRecord $record) => $record->homework_status?->value === 'done')->count();

        return [
            'checks' => $checks,
            'done' => $done,
            'not_done' => $homework->filter(fn (EvidenceRecord $record) => $record->homework_status?->value === 'not_done')->count(),
            'partially_done' => $homework->filter(fn (EvidenceRecord $record) => $record->homework_status?->value === 'partially_done')->count(),
            'students_involved' => $homework->whereNotNull('enrollment_id')->pluck('enrollment_id')->unique()->count(),
            'done_rate' => (string) round($done * 100 / $checks, 1),
        ];
    }

    /**
     * The chronology (§23, «detalhado»).
     *
     * NAMES ONLY WHERE THE TEACHER ALLOWED THEM. Without that decision the row
     * exists — the date, the kind, the description — and the student column is
     * absent, not blanked: the name never leaves the database (§28).
     *
     * @param  Collection<int, EvidenceRecord>  $records
     * @return list<array<string, mixed>>
     */
    protected function timeline(Report $report, Collection $records): array
    {
        if ((bool) $report->option('detailed', false) !== true) {
            return [];
        }

        $names = $report->namesStudents();

        return array_values($records->take(self::TIMELINE_LIMIT)->map(function (EvidenceRecord $record) use ($names): array {
            $row = [
                'occurred_on' => $record->occurred_at->toDateString(),
                'kind' => $record->kind->value,
                'kind_label' => $record->kind->label(),
                'valence' => RecordValence::of($record)->value,
                'domain' => $record->domain?->name,
                'description' => $record->description,
            ];

            if ($names && $record->enrollment !== null) {
                $row['student'] = optional($record->enrollment->student->identity)->display_name ?? '(sem identidade)';
            }

            return $row;
        })->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function groupOptions(): array
    {
        return array_map(
            fn (EvidenceInternalGroup $group) => ['value' => $group->value, 'label' => $group->label()],
            EvidenceInternalGroup::cases(),
        );
    }
}
