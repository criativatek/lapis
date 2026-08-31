<?php

namespace App\Services\Reporting\Source;

use App\Models\EvidenceRecord;
use App\Models\Intervention;
use App\Models\Report;
use App\Models\ReportScopeKind;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\PrimaryResultScope;
use Illuminate\Support\Collection;

/**
 * The facts a class report is built from.
 *
 * ONE CALL INTO THE ASSESSMENT CORE, or none at all when the report is about a
 * kept photograph. BuildClassStatistics already guarantees it agrees with
 * Resultados because it reads the same numbers rather than deriving them again;
 * this inherits that guarantee by making exactly one call and reshaping the
 * answer. If a figure in a report ever disagrees with the same figure on
 * Estatística, the bug is here and nowhere else.
 *
 * WHAT IS ADDED BESIDE THE RESULTS. Three things the assessment core does not
 * hold and a report needs: how many students the class actually has, what was
 * written in the logbook, and which interventions were registered. All three
 * are counted, never interpreted (§12, §19).
 *
 * THE PRIMARY READING IS NOT DECIDED HERE (§7). Whether «como está a turma» is
 * answered by the period's own weighted average or by the accumulated one is
 * PrimaryResultScope's answer, read from the profile version's own periods.
 * This copies it into the facts so that every sentence downstream names the
 * right figure, and no composer re-derives the rule.
 */
class ClassReportSource implements ReportSource
{
    public function __construct(
        protected BuildClassStatistics $statistics,
        protected PrimaryResultScope $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function factsFor(Report $report): array
    {
        $class = $report->schoolClass;

        if (! $class instanceof SchoolClass) {
            return ['available' => false];
        }

        $facts = $report->scope_kind === ReportScopeKind::Interim
            ? $this->fromSnapshot($report, $class)
            : $this->fromLive($report, $class);

        return [
            ...$facts,
            'class' => $this->classFacts($class),
            'roster' => $this->rosterFacts($class),
            'records' => $this->recordFacts($report, $class),
            'interventions' => $this->interventionFacts($report, $class),
        ];
    }

    // ------------------------------------------------------------------ live

    /**
     * @return array<string, mixed>
     */
    protected function fromLive(Report $report, SchoolClass $class): array
    {
        $period = $report->academicPeriod;

        // THE ONE CALL.
        $statistics = $this->statistics->for($class, $period);

        $selected = $statistics['selected_period'] ?? null;

        if ($selected === null) {
            return ['available' => false, 'origin' => 'live'];
        }

        return [
            'available' => true,
            'origin' => 'live',
            'period_label' => $selected['label'] ?? null,
            'previous_period_label' => $statistics['previous_period']['label'] ?? null,
            'scale' => $statistics['scale'] ?? null,
            'primary' => $statistics['primary'] ?? null,
            'summary' => $statistics['summary'] ?? null,
            'assigned_distribution' => $statistics['assigned_distribution'] ?? null,
            'distribution' => $statistics['distribution'] ?? null,
            'domains' => $statistics['domain_statistics'] ?? [],
            'evolution' => $statistics['evolution'] ?? null,
            'continuous_evolution' => $statistics['continuous_evolution'] ?? null,
            'period_series' => $statistics['period_series'] ?? [],
            'students' => $statistics['students'] ?? [],
        ];
    }

    // -------------------------------------------------------------- snapshot

    /**
     * A report built ON a photograph reads the photograph, and never
     * reconstructs it from today's numbers (§30).
     *
     * WHAT AN OLD SNAPSHOT DOES NOT HAVE, IT NEVER GETS. A version-3 document
     * has no `assigned_distribution`; the fact here is null and the section
     * says so. Filling it from live data would silently answer a question about
     * November with an answer from March.
     *
     * @return array<string, mixed>
     */
    protected function fromSnapshot(Report $report, SchoolClass $class): array
    {
        $interim = $report->interimAssessment;

        if ($interim === null) {
            return ['available' => false, 'origin' => 'interim_snapshot'];
        }

        $snapshot = $interim->snapshot;
        $version = (int) $interim->snapshot_version;

        return [
            'available' => true,
            'origin' => 'interim_snapshot',
            'snapshot_version' => $version,
            'snapshot_intact' => $interim->isIntact(),
            'reference_date' => $snapshot['reference_date'] ?? null,
            'period_label' => $snapshot['period']['label_snapshot'] ?? null,
            'previous_period_label' => null,
            'scale' => $this->snapshotScale($snapshot),
            // A photograph never recorded which reading was primary at the
            // time. Saying «acumulado» now would be an assertion about a moment
            // nobody observed, so the fact is absent and the sentences that
            // depend on it name the figure they actually use.
            'primary' => null,
            'summary' => $snapshot['summary'] ?? null,
            'assigned_distribution' => $snapshot['assigned_distribution'] ?? null,
            'distribution' => $snapshot['distribution'] ?? null,
            'domains' => $this->snapshotDomains($snapshot),
            'evolution' => $snapshot['evolution'] ?? null,
            'continuous_evolution' => null,
            'period_series' => [],
            'students' => $this->snapshotStudents($snapshot),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    protected function snapshotScale(array $snapshot): ?array
    {
        $scale = $snapshot['scale'] ?? null;

        if (! is_array($scale)) {
            return null;
        }

        return [
            'name' => $scale['name_snapshot'] ?? null,
            'kind' => $scale['kind'] ?? null,
            'bands' => array_map(fn (array $band): array => [
                'scale_level_id' => $band['scale_level_id'] ?? null,
                'code' => $band['code_snapshot'] ?? null,
                'label' => $band['label_snapshot'] ?? null,
                'sequence' => $band['sequence'] ?? null,
                'is_negative' => $band['is_negative'] ?? null,
            ], is_array($scale['bands'] ?? null) ? $scale['bands'] : []),
        ];
    }

    /**
     * The snapshot's `domain_statistics` already matches the live shape except
     * for the label, which travelled as `label_snapshot`.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    protected function snapshotDomains(array $snapshot): array
    {
        $rows = $snapshot['domain_statistics'] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    protected function snapshotStudents(array $snapshot): array
    {
        $rows = is_array($snapshot['students'] ?? null) ? $snapshot['students'] : [];

        return array_values(array_map(fn (array $student): array => [
            ...$student,
            // The live shape calls it `name`; the photograph kept
            // `name_snapshot` on purpose. One vocabulary downstream.
            'name' => $student['name_snapshot'] ?? ($student['name'] ?? null),
        ], $rows));
    }

    // ------------------------------------------------------------- the class

    /**
     * @return array<string, mixed>
     */
    protected function classFacts(SchoolClass $class): array
    {
        return [
            'label' => $class->label,
            'grade_level' => $class->grade_level,
            'subject' => $class->subject->name,
            'academic_year' => $class->academicYear->label,
            'profile_version' => $class->profileVersion?->profile->name,
        ];
    }

    /**
     * How many students the class has, and how many it has lost.
     *
     * ACTIVE AND HISTORICAL ARE COUNTED APART (§58). A student who transferred
     * out in February did not stop existing in January's results, and a student
     * who left is not deleted from history — but «a turma é constituída por 26
     * alunos» must mean the 26 who are in it, not 28 including two who left.
     *
     * KNOWN LIMITATION, STATED RATHER THAN GUESSED. `left_on` is nullable and
     * imports do not always fill it, so a report cannot always say WHEN somebody
     * left. Where the date is missing the fact is null and no sentence claims a
     * moment (§58).
     *
     * @return array<string, mixed>
     */
    protected function rosterFacts(SchoolClass $class): array
    {
        $enrollments = $class->enrollments()->get();

        $active = $enrollments->filter(fn ($enrollment) => $enrollment->status->isCurrent());
        $departed = $enrollments->reject(fn ($enrollment) => $enrollment->status->isCurrent());

        return [
            'active' => $active->count(),
            'total_ever' => $enrollments->count(),
            'departed' => $departed->count(),
            'departed_with_known_date' => $departed->filter(fn ($enrollment) => $enrollment->left_on !== null)->count(),
            'late_entries' => $active->filter(fn ($enrollment) => $enrollment->is_late_entry)->count(),
        ];
    }

    // ------------------------------------------------------- logbook & action

    /**
     * The logbook, COUNTED (§12, §22).
     *
     * Counts of records, per kind. Never a conclusion: «existem 8 registos
     * associados a atenção/concentração» is a fact about the logbook, and «a
     * turma tem problemas de concentração» is a claim about the class that only
     * a teacher may make.
     *
     * THE UNIT IS THE RECORD, NOT THE STUDENT (§70, §71). Both counts are
     * returned, separately named, so a sentence can never convert one into the
     * other.
     *
     * @return array<string, mixed>
     */
    protected function recordFacts(Report $report, SchoolClass $class): array
    {
        $query = EvidenceRecord::query()->forClass($class->id);

        if ($report->academic_period_id !== null) {
            $query->where('academic_period_id', $report->academic_period_id);
        }

        $records = $query->get(['id', 'kind', 'enrollment_id', 'homework_status', 'occurred_at']);

        $byKind = [];

        foreach ($records as $record) {
            $key = $record->kind->value;

            $byKind[$key] ??= [
                'kind' => $key,
                'label' => $record->kind->label(),
                'group' => $record->kind->group()->value,
                'records' => 0,
                'students' => [],
            ];

            $byKind[$key]['records']++;

            if ($record->enrollment_id !== null) {
                $byKind[$key]['students'][$record->enrollment_id] = true;
            }
        }

        $kinds = array_values(array_map(fn (array $row): array => [
            'kind' => $row['kind'],
            'label' => $row['label'],
            'group' => $row['group'],
            'records' => $row['records'],
            // How many DIFFERENT students the records concern — a second,
            // separately-named unit, never a rewrite of the first.
            'students_involved' => count($row['students']),
        ], $byKind));

        usort($kinds, fn (array $a, array $b) => $b['records'] <=> $a['records']);

        return [
            'total' => $records->count(),
            'students_involved' => $records->whereNotNull('enrollment_id')->pluck('enrollment_id')->unique()->count(),
            'kinds' => $kinds,
            'homework' => $this->homeworkFacts($records),
        ];
    }

    /**
     * Homework verifications, which have their own unit and their own trap.
     *
     * «Foram realizadas 126 verificações de trabalho de casa. Em 82% dos
     * registos, o trabalho encontrava-se realizado» is what the data supports.
     * «18% dos alunos não trabalham» is a different claim about different
     * objects, and the numbers here are named so it cannot be made by accident
     * (§70).
     *
     * @param  Collection<int, EvidenceRecord>  $records
     * @return array<string, mixed>|null
     */
    protected function homeworkFacts(Collection $records): ?array
    {
        $homework = $records->filter(fn (EvidenceRecord $record) => $record->homework_status !== null);

        if ($homework->isEmpty()) {
            return null;
        }

        $done = $homework->filter(fn (EvidenceRecord $record) => $record->homework_status?->value === 'done')->count();
        $checks = $homework->count();

        return [
            'checks' => $checks,
            'done' => $done,
            'not_done' => $checks - $done,
            'students_involved' => $homework->whereNotNull('enrollment_id')->pluck('enrollment_id')->unique()->count(),
            'done_rate' => $checks === 0 ? null : (string) round($done * 100 / $checks, 1),
        ];
    }

    /**
     * The interventions ACTUALLY REGISTERED (§19).
     *
     * Only those the teacher marked as available for reports: an intervention
     * is a record of a pedagogical action, and whether it belongs in a document
     * that leaves the classroom is their call, not the system's. Nothing is ever
     * invented here — a class with no registered intervention produces no list,
     * and the section says «não foram registadas», never «não foram
     * necessárias» (§41).
     *
     * @return array<string, mixed>
     */
    protected function interventionFacts(Report $report, SchoolClass $class): array
    {
        $query = Intervention::query()
            ->where('class_id', $class->id)
            ->where('available_for_reports', true);

        if ($report->academic_period_id !== null) {
            $query->where(function ($query) use ($report): void {
                $query->where('academic_period_id', $report->academic_period_id)
                    ->orWhereNull('academic_period_id');
            });
        }

        $interventions = $query->with('domain')->orderBy('started_on')->get();

        $byType = [];

        foreach ($interventions as $intervention) {
            // `->` rather than `?->`: the left side of ?? already tolerates a
            // null object, and the nullsafe would be dead weight.
            $key = $intervention->intervention_type->value ?? 'uncategorised';

            $byType[$key] ??= [
                'type' => $key,
                // NEVER THE TITLE. A title is the teacher's words about ONE
                // intervention; using it as a category label is how «Legado sem
                // dominio» — the title of a row that predates the type column —
                // ended up printed as a kind of pedagogical action. A record
                // with no type is reported as having none (§10, §11).
                'label' => $intervention->intervention_type?->label(),
                'count' => 0,
            ];

            $byType[$key]['count']++;
        }

        // usort reindexes, so this is already a list.
        usort($byType, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return [
            'total' => $interventions->count(),
            'students_involved' => $interventions->whereNotNull('enrollment_id')->pluck('enrollment_id')->unique()->count(),
            'class_wide' => $interventions->whereNull('enrollment_id')->count(),
            'types' => $byType,
            'concluded' => $interventions->filter(fn (Intervention $intervention) => $intervention->status->value === 'concluded')->count(),
            // Marked by the teacher for inclusion in the document itself, as
            // opposed to merely counted.
            'highlighted' => array_values($interventions
                // TWO CONDITIONS, AND THE SECOND IS THE ONE THAT GUARDS (§10).
                //
                // A type is evidence that somebody curated this row, and
                // `include_in_report` predates the type column, so requiring one
                // keeps the untouched imports out. But it is not a guard against
                // the placeholder itself: assigning a type to an imported
                // intervention is an ordinary thing to do in the UI, and the
                // moment somebody does it the row becomes quotable and prints
                // the title an old process wrote to fill a NOT NULL column —
                // which is how «Legado sem dominio» reached a printed document.
                //
                // So what decides is whether the row has anything a person
                // wrote: `pedagogicalTitle()` reads the strategy, then the
                // title, then the type's own label, each through
                // PedagogicalText, and answers null when none of them says
                // anything. A row with nothing to quote is counted, never
                // quoted — as it was before it had a type.
                ->filter(fn (Intervention $intervention) => $intervention->include_in_report
                    && $intervention->intervention_type !== null
                    && $intervention->pedagogicalTitle() !== null)
                ->map(fn (Intervention $intervention) => [
                    // The accessor, never the column: see above.
                    'title' => $intervention->pedagogicalTitle(),
                    'type' => $intervention->intervention_type?->label(),
                    // No domain is no domain. The absence never becomes a
                    // category of its own (§2).
                    'domain' => $intervention->domain?->name,
                    'status' => $intervention->status->label(),
                    'started_on' => $intervention->started_on->toDateString(),
                ])->all()),
        ];
    }
}
