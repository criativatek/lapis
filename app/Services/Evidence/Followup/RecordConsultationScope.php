<?php

namespace App\Services\Evidence\Followup;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Retention\ResolveSelectedAcademicYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What a consultation of the logbook may read, resolved from the query string
 * — always inside the classes the person TEACHES (SchoolClassPolicy::view is
 * `teaches()`; an organization owner does not read colleagues' classes, and
 * these summaries do not change that).
 *
 * A ULID that is not in that set is a 404, never a silent fallback to «all».
 * The period is the one in course when today falls inside one; otherwise none
 * is picked and the screen asks for a choice — never a wrong period chosen
 * silently, never the whole year.
 */
final class RecordConsultationScope
{
    public const string MODE_PERIOD = 'period';

    public const string MODE_RANGE = 'range';

    /**
     * @param  Collection<int, AcademicYear>  $years
     * @param  Collection<int, SchoolClass>  $yearClasses  every class of the year the person teaches
     * @param  Collection<int, SchoolClass>  $classes  the ones the filters keep
     * @param  EloquentCollection<int, Enrollment>  $roster  the selected class's roll (empty without a class)
     * @param  array<string, string|null>  $invalid  filter → message, for values that could not be used
     */
    private function __construct(
        public readonly Collection $years,
        public readonly ?AcademicYear $year,
        public readonly Collection $yearClasses,
        public readonly Collection $classes,
        public readonly ?Subject $subject,
        public readonly ?SchoolClass $class,
        public readonly Collection $roster,
        public readonly ?Enrollment $student,
        public readonly ?EvidenceKind $kind,
        public readonly string $mode,
        public readonly AssessmentPeriodTimeline $timeline,
        public readonly ?AcademicPeriod $period,
        public readonly bool $periodIsCurrent,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
        public readonly ?FollowupWindow $window,
        public readonly array $invalid,
    ) {}

    public static function resolve(Request $request, User $teacher): self
    {
        $taughtYearIds = SchoolClass::query()->taughtBy($teacher)->distinct()->pluck('academic_year_id');

        $years = AcademicYear::query()
            ->whereIn('id', $taughtYearIds)
            ->orderByDesc('starts_on')
            ->get();

        $year = self::pickYear($request, $years);

        $yearClasses = $year === null
            ? collect()
            : SchoolClass::query()
                ->taughtBy($teacher)
                ->where('academic_year_id', $year->id)
                ->with('subject')
                ->orderBy('label')
                ->orderBy('id')
                ->get();

        $subject = null;
        $subjectUlid = self::text($request, 'subject');

        if ($subjectUlid !== null) {
            $subject = $yearClasses->pluck('subject')->first(fn (Subject $candidate): bool => $candidate->ulid === $subjectUlid);
            abort_if($subject === null, 404);
        }

        $class = null;
        $classUlid = self::text($request, 'class');

        if ($classUlid !== null) {
            $class = $yearClasses->first(fn (SchoolClass $candidate): bool => $candidate->ulid === $classUlid);
            abort_if($class === null, 404);
        }

        $classes = $yearClasses
            ->when($subject !== null, fn (Collection $classes) => $classes->where('subject_id', $subject->id))
            ->when($class !== null, fn (Collection $classes) => $classes->where('id', $class->id))
            ->values();

        // The roll is everyone who was EVER on the class — a record from
        // November still belongs to November's student.
        $roster = $class === null
            ? new EloquentCollection
            : $class->enrollments()->orderBy('class_number')->orderBy('id')->get();

        $student = null;
        $studentUlid = self::text($request, 'student');

        if ($studentUlid !== null) {
            $student = $roster->first(fn (Enrollment $enrollment): bool => $enrollment->ulid === $studentUlid);
            abort_if($student === null, 404);
        }

        $kind = EvidenceKind::tryFrom((string) self::text($request, 'kind'));

        $mode = self::text($request, 'mode') === self::MODE_RANGE ? self::MODE_RANGE : self::MODE_PERIOD;
        $timeline = $year === null ? AssessmentPeriodTimeline::fromPeriods(collect()) : AssessmentPeriodTimeline::forYear($year->id);

        $invalid = [];
        $period = null;
        $periodIsCurrent = false;
        $from = null;
        $to = null;
        $window = null;

        if ($mode === self::MODE_PERIOD) {
            $periodUlid = self::text($request, 'period');

            if ($periodUlid !== null) {
                $period = $timeline->findByUlid($periodUlid);
                abort_if($period === null, 404);
            } else {
                $period = $timeline->periodOn(CarbonImmutable::today());
                $periodIsCurrent = $period !== null;
            }

            $window = $period === null ? null : FollowupWindow::forPeriod($period, $timeline);
        } else {
            $from = self::date($request, 'from');
            $to = self::date($request, 'to');

            if ($from === null || $to === null) {
                $invalid['range'] = __('Indique a data inicial e a data final.');
            } elseif ($from->gt($to)) {
                $invalid['range'] = __('A data inicial não pode ser posterior à data final.');
            } else {
                $window = FollowupWindow::forRange($from, $to);
            }
        }

        return new self(
            $years, $year, $yearClasses, $classes, $subject, $class, $roster, $student, $kind,
            $mode, $timeline, $period, $periodIsCurrent, $from, $to, $window, $invalid,
        );
    }

    /**
     * @return list<int>
     */
    public function classIds(): array
    {
        return array_values($this->classes->map(fn (SchoolClass $class): int => $class->id)->all());
    }

    /**
     * Every class of the year the person teaches, whatever the filters say.
     *
     * @return list<int>
     */
    public function yearClassIds(): array
    {
        return array_values($this->yearClasses->map(fn (SchoolClass $class): int => $class->id)->all());
    }

    public function studentId(): ?int
    {
        return $this->student?->id;
    }

    /**
     * @param  Collection<int, AcademicYear>  $years
     */
    private static function pickYear(Request $request, Collection $years): ?AcademicYear
    {
        $yearUlid = self::text($request, 'year');

        if ($yearUlid !== null) {
            $year = $years->first(fn (AcademicYear $candidate): bool => $candidate->ulid === $yearUlid);
            abort_if($year === null, 404);

            return $year;
        }

        if ($years->isEmpty()) {
            return null;
        }

        $sessionYearId = $request->session()->get('academic_year_id');

        return app(ResolveSelectedAcademicYear::class)->for($years, is_numeric($sessionYearId) ? (int) $sessionYearId : null)
            ?? $years->first();
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function date(Request $request, string $key): ?CarbonImmutable
    {
        $value = self::text($request, $key);

        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }
}
