<?php

namespace App\Services\Reporting\Source;

use App\Domain\Reporting\LearningAttitude;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\Report;
use App\Models\ReportStatus;
use App\Models\ReportType;
use App\Models\Scale;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;

/**
 * The facts a school-wide report is built from (§24–§28).
 *
 * IT COUNTS DECISIONS; IT DOES NOT RECOMPUTE RESULTS. Every figure here is a
 * count of classifications the teachers ASSIGNED — never a re-derived average,
 * never a mean of class means. That distinction is what keeps it inside the
 * module's central rule: there is no second academic engine here, because
 * nothing academic is calculated at all. Which side a decision falls on is the
 * scale's own statement through `is_negative`, exactly as everywhere else.
 *
 * IT NEVER AVERAGES ACROSS INCOMPATIBLE SCALES (§26). «1 a 5» and «0 a 20» are
 * not the same axis, and a school-wide average over both would be a number with
 * no meaning that nonetheless looks authoritative. Classifications are grouped
 * BY SCALE, each group reports its own distribution and its own rate, and what
 * could not be pooled is stated rather than quietly merged.
 *
 * IT PRODUCES NO RANKING (§25). There is no «melhor turma», no ordering of
 * teachers, no league table. Breakdowns are by year of schooling and by
 * subject — pedagogical cuts a school acts on — and each carries its own
 * denominator.
 *
 * BEHAVIOUR COMES FROM TEACHERS, NOT FROM OCCURRENCES (§27). A count of
 * disciplinary records across a school says how much was written down. What the
 * institutional report reads instead is the characterisations teachers
 * VALIDATED in their own class reports, together with how many classes have one
 * — because «62% das turmas com caracterização disponível» is only honest if
 * the coverage is on the same page.
 */
class SchoolReportSource implements ReportSource
{
    /**
     * @return array<string, mixed>
     */
    public function factsFor(Report $report): array
    {
        $yearId = $report->academic_year_id;

        if ($yearId === null) {
            return ['available' => false, 'origin' => 'none'];
        }

        $classes = SchoolClass::query()
            ->where('academic_year_id', $yearId)
            ->with(['subject', 'profileVersion.scale.levels'])
            ->withCount('activeEnrollments')
            ->get();

        if ($classes->isEmpty()) {
            return ['available' => false, 'origin' => 'live'];
        }

        $classifications = $this->classifications($classes, $report);

        return [
            'available' => true,
            'origin' => 'live',
            'year' => $report->academicYear?->label,
            'overview' => $this->overview($classes),
            'coverage' => $this->coverage($classes, $classifications),
            'groups' => $this->byScale($classes, $classifications),
            'by_grade_level' => $this->byDimension($classes, $classifications, 'grade_level'),
            'by_subject' => $this->byDimension($classes, $classifications, 'subject'),
            'characterisation' => $this->characterisation($report, $classes),
            'comparability' => $this->comparability($classes),
        ];
    }

    /**
     * The decided classifications of every class in the year.
     *
     * ONLY DECIDED ONES. A proposal is not a grade (§7), so `Proposed` never
     * enters an institutional count — a school reading its own pass rate must
     * be reading what its teachers signed.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @return Collection<int, Classification>
     */
    protected function classifications(Collection $classes, Report $report): Collection
    {
        return Classification::query()
            ->whereIn('enrollment_id', Enrollment::query()
                ->whereIn('class_id', $classes->pluck('id'))
                ->select('id'))
            ->where('scope', ClassificationScope::Period)
            ->whereIn('status', [ClassificationStatus::Confirmed->value, ClassificationStatus::Published->value])
            ->when($report->academic_period_id !== null,
                fn ($query) => $query->where('academic_period_id', $report->academic_period_id))
            ->with(['finalScaleLevel', 'enrollment'])
            ->get();
    }

    /**
     * @param  Collection<int, SchoolClass>  $classes
     * @return array<string, mixed>
     */
    protected function overview(Collection $classes): array
    {
        return [
            'classes' => $classes->count(),
            'students' => (int) $classes->sum('active_enrollments_count'),
            'subjects' => $classes->pluck('subject.name')->unique()->count(),
            'grade_levels' => $classes->pluck('grade_level')->filter()->unique()->values()->all(),
        ];
    }

    /**
     * HOW MUCH OF THE SCHOOL THIS ACTUALLY DESCRIBES (§27).
     *
     * Stated before any figure, because a distribution over three of forty
     * classes is not a school's results — and a reader who is not told will
     * assume it is.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, Classification>  $classifications
     * @return array<string, mixed>
     */
    protected function coverage(Collection $classes, Collection $classifications): array
    {
        $classIdsWithGrades = $classifications
            ->map(fn (Classification $classification) => $classification->enrollment?->class_id)
            ->filter()
            ->unique();

        return [
            'classes_total' => $classes->count(),
            'classes_with_classifications' => $classIdsWithGrades->count(),
            'classifications' => $classifications->count(),
            'students_classified' => $classifications->pluck('enrollment_id')->unique()->count(),
        ];
    }

    /**
     * The distribution and the rate, per SCALE (§26).
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, Classification>  $classifications
     * @return list<array<string, mixed>>
     */
    protected function byScale(Collection $classes, Collection $classifications): array
    {
        $scaleOfClass = [];

        foreach ($classes as $class) {
            $scaleOfClass[(int) $class->id] = $class->profileVersion?->scale;
        }

        /** @var array<int, array<string, mixed>> $groups */
        $groups = [];

        foreach ($classifications as $classification) {
            $classId = (int) ($classification->enrollment->class_id ?? 0);
            $scale = $scaleOfClass[$classId] ?? null;

            if (! $scale instanceof Scale) {
                continue;
            }

            $key = (int) $scale->id;

            $groups[$key] ??= [
                'scale' => ['id' => $key, 'name' => $scale->name, 'kind' => $scale->kind],
                'classified' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'unplaced' => 0,
                'bands' => [],
                'classes' => [],
            ];

            $groups[$key]['classified']++;
            $groups[$key]['classes'][$classId] = true;

            $level = $classification->finalScaleLevel;

            if ($level === null) {
                // Decided, on a scale that states nothing about the decision.
                // Counted apart rather than dropped into either side.
                $groups[$key]['unplaced']++;

                continue;
            }

            $level->is_negative ? $groups[$key]['failed']++ : $groups[$key]['succeeded']++;

            $bandKey = (int) $level->id;

            $groups[$key]['bands'][$bandKey] ??= [
                'label' => $level->label,
                'code' => $level->code,
                'sequence' => (int) $level->sequence,
                'is_negative' => (bool) $level->is_negative,
                'count' => 0,
            ];

            $groups[$key]['bands'][$bandKey]['count']++;
        }

        $rows = [];

        foreach ($groups as $group) {
            $placed = $group['succeeded'] + $group['failed'];
            $bands = array_values($group['bands']);

            usort($bands, fn (array $a, array $b) => $a['sequence'] <=> $b['sequence']);

            $rows[] = [
                'scale' => $group['scale'],
                'classes' => count($group['classes']),
                'classified' => $group['classified'],
                'succeeded' => $group['succeeded'],
                'failed' => $group['failed'],
                'unplaced' => $group['unplaced'],
                'placed' => $placed,
                'success_rate' => $this->percentage($group['succeeded'], $placed),
                'bands' => array_map(fn (array $band): array => [
                    ...$band,
                    'percentage' => $this->percentage($band['count'], $group['classified']),
                ], $bands),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['classified'] <=> $a['classified']);

        return $rows;
    }

    /**
     * The same counts cut by year of schooling or by subject.
     *
     * WITHIN ONE SCALE ONLY. A row that pooled «7.º ano» across a 1–5 subject
     * and a 0–20 one would be the very comparison §26 forbids, so a dimension
     * whose classes use more than one scale reports each scale separately and
     * says so.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @param  Collection<int, Classification>  $classifications
     * @return list<array<string, mixed>>
     */
    protected function byDimension(Collection $classes, Collection $classifications, string $dimension): array
    {
        $labelOfClass = [];
        $scaleOfClass = [];

        foreach ($classes as $class) {
            $labelOfClass[(int) $class->id] = $dimension === 'subject'
                ? (string) $class->subject->name
                : (string) ($class->grade_level ?? '—');
            $scaleOfClass[(int) $class->id] = $class->profileVersion?->scale;
        }

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        foreach ($classifications as $classification) {
            $classId = (int) ($classification->enrollment->class_id ?? 0);
            $label = $labelOfClass[$classId] ?? null;
            $scale = $scaleOfClass[$classId] ?? null;

            if ($label === null || ! $scale instanceof Scale) {
                continue;
            }

            $key = $label.'|'.$scale->id;

            $rows[$key] ??= [
                'label' => $label,
                'scale' => $scale->name,
                'classified' => 0,
                'succeeded' => 0,
                'placed' => 0,
                'classes' => [],
            ];

            $rows[$key]['classified']++;
            $rows[$key]['classes'][$classId] = true;

            $level = $classification->finalScaleLevel;

            if ($level === null) {
                continue;
            }

            $rows[$key]['placed']++;

            if (! $level->is_negative) {
                $rows[$key]['succeeded']++;
            }
        }

        $result = array_values(array_map(fn (array $row): array => [
            'label' => $row['label'],
            'scale' => $row['scale'],
            'classes' => count($row['classes']),
            'classified' => $row['classified'],
            'succeeded' => $row['succeeded'],
            'placed' => $row['placed'],
            'success_rate' => $this->percentage($row['succeeded'], $row['placed']),
        ], $rows));

        // Alphabetical, NOT by rate. Ordering a school's own years or subjects
        // by success is a league table with the word removed (§25).
        usort($result, fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return $result;
    }

    /**
     * Behaviour and attitude, read from what teachers VALIDATED (§27).
     *
     * From FINALIZED class reports of the same year: a draft is somebody still
     * thinking, and an institutional figure built on drafts would move as
     * colleagues edited. Coverage travels with the counts, because a percentage
     * over «as turmas com caracterização» is meaningless without knowing how
     * many that is.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @return array<string, mixed>
     */
    protected function characterisation(Report $report, Collection $classes): array
    {
        $reports = Report::query()
            ->where('type', ReportType::SchoolClass)
            ->where('status', ReportStatus::Finalized)
            ->where('academic_year_id', $report->academic_year_id)
            ->whereIn('class_id', $classes->pluck('id'))
            ->get();

        $withAttitude = 0;
        $favourable = 0;
        $withBehaviour = 0;

        /** @var array<string, int> $attitudes */
        $attitudes = [];

        foreach ($reports as $classReport) {
            $answer = $classReport->input('attitude');
            $attitude = is_string($answer) ? LearningAttitude::tryFrom($answer) : null;

            if ($attitude !== null && $attitude->characterises()) {
                $withAttitude++;
                $attitudes[$attitude->value] = ($attitudes[$attitude->value] ?? 0) + 1;

                if ($attitude->isFavourable()) {
                    $favourable++;
                }
            }

            $behaviour = $classReport->input('behaviour');

            if (is_string($behaviour) && $behaviour !== 'not_characterised') {
                $withBehaviour++;
            }
        }

        return [
            'classes_total' => $classes->count(),
            'reports_finalized' => $reports->count(),
            'with_attitude' => $withAttitude,
            'with_behaviour' => $withBehaviour,
            'favourable' => $favourable,
            'favourable_rate' => $this->percentage($favourable, $withAttitude),
            'attitudes' => array_map(
                fn (string $value, int $count): array => [
                    'value' => $value,
                    'label' => LearningAttitude::from($value)->label(),
                    'count' => $count,
                ],
                array_keys($attitudes),
                array_values($attitudes),
            ),
        ];
    }

    /**
     * What could NOT be put side by side, and why (§26).
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @return array<string, mixed>
     */
    protected function comparability(Collection $classes): array
    {
        $scales = [];
        $withoutProfile = 0;

        foreach ($classes as $class) {
            $scale = $class->profileVersion?->scale;

            if ($scale === null) {
                $withoutProfile++;

                continue;
            }

            $scales[(int) $scale->id] ??= ['name' => $scale->name, 'kind' => $scale->kind, 'classes' => 0];
            $scales[(int) $scale->id]['classes']++;
        }

        return [
            'scales' => array_values($scales),
            'distinct_scales' => count($scales),
            'classes_without_profile' => $withoutProfile,
            // The one sentence a school-wide report must never omit when true.
            'comparable' => count($scales) <= 1 && $withoutProfile === 0,
        ];
    }

    protected function percentage(int $part, int $total): ?string
    {
        return $total === 0 ? null : (string) round($part * 100 / $total, 1);
    }
}
