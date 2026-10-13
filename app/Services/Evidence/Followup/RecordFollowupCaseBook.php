<?php

namespace App\Services\Evidence\Followup;

use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupCategory;
use Illuminate\Support\Collection;

/**
 * Reads follow-up cases and turns a live count into a status. Never writes.
 *
 * - «Registado no Inovar» (`registered`): the case has a declaration in force,
 *   whatever the count is now — a correction afterwards is reported, not
 *   hidden.
 * - «Por tratar» (`pending`): three or more occurrences and no declaration in
 *   force. Includes the cases that predate alerts (origin `existing`): they
 *   are identified, never silently treated as handled.
 * - nothing (`none`): fewer than three and nothing declared.
 */
final class RecordFollowupCaseBook
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_REGISTERED = 'registered';

    public const string STATUS_NONE = 'none';

    public function __construct(private readonly RecordFollowupCounter $counter) {}

    public static function status(int $count, ?RecordFollowupCase $case): string
    {
        if ($case?->activeConfirmation !== null) {
            return self::STATUS_REGISTERED;
        }

        return $count >= RecordFollowupCategory::ALERT_THRESHOLD ? self::STATUS_PENDING : self::STATUS_NONE;
    }

    /**
     * `alert` when an operation crossed the threshold after alerts existed;
     * `existing` when the case already had three or more without ever being
     * alerted; null below the threshold.
     */
    public static function origin(int $count, ?RecordFollowupCase $case): ?string
    {
        if ($case?->alerted_at !== null) {
            return 'alert';
        }

        return $count >= RecordFollowupCategory::ALERT_THRESHOLD ? 'existing' : null;
    }

    /**
     * The cases of one period and category, keyed «class_id:enrollment_id».
     *
     * @param  list<int>  $classIds
     * @return Collection<string, RecordFollowupCase>
     */
    public function forPeriod(array $classIds, int $periodId, RecordFollowupCategory $category): Collection
    {
        if ($classIds === []) {
            return collect();
        }

        return RecordFollowupCase::query()
            ->whereIn('class_id', $classIds)
            ->where('academic_period_id', $periodId)
            ->where('category', $category->value)
            ->with('activeConfirmation')
            ->get()
            ->keyBy(fn (RecordFollowupCase $case): string => $case->class_id.':'.$case->enrollment_id);
    }

    /**
     * How many cases are «Por tratar» per category in a period window.
     *
     * @param  list<int>  $classIds
     * @return array<string, int>
     */
    public function pendingCounts(array $classIds, FollowupWindow $window, ?int $enrollmentId = null): array
    {
        $pending = [];
        $period = $window->period();

        foreach (RecordFollowupCategory::cases() as $category) {
            $pending[$category->value] = 0;

            if ($period === null) {
                continue;
            }

            $rows = $this->counter->perStudent($classIds, $window, $category, $enrollmentId === null ? null : [$enrollmentId])
                ->filter(fn (array $row): bool => $row['occurrences'] >= RecordFollowupCategory::ALERT_THRESHOLD);

            if ($rows->isEmpty()) {
                continue;
            }

            $cases = $this->forPeriod($classIds, $period->id, $category);

            $pending[$category->value] = $rows
                ->filter(fn (array $row): bool => self::status($row['occurrences'], $cases->get($row['class_id'].':'.$row['enrollment_id'])) === self::STATUS_PENDING)
                ->count();
        }

        return $pending;
    }

    /**
     * Every alert still «Por tratar» in the given classes — any period of the
     * year, so an alert from the first period does not vanish because the
     * screen moved on to the second. Counts are live; an alert whose count fell
     * below three (correction, deletion) is not pending.
     *
     * `$withDetails` false skips loading the student, class and period — for
     * a count (the Início card), no name is decrypted.
     *
     * @param  list<int>  $classIds
     * @return Collection<int, array{case: RecordFollowupCase, occurrences: int}>
     */
    public function pendingAlerts(array $classIds, AssessmentPeriodTimeline $timeline, ?int $enrollmentId = null, bool $withDetails = true): Collection
    {
        if ($classIds === [] || $timeline->isEmpty()) {
            return collect();
        }

        $cases = RecordFollowupCase::query()
            ->whereIn('class_id', $classIds)
            ->whereNotNull('alerted_at')
            ->when($enrollmentId !== null, fn ($query) => $query->where('enrollment_id', $enrollmentId))
            ->whereDoesntHave('confirmations', fn ($query) => $query->whereNull('undone_at'))
            ->when($withDetails, fn ($query) => $query->with(['enrollment.student.identity', 'schoolClass.subject', 'academicPeriod']))
            ->get();

        $pending = collect();

        foreach ($cases->groupBy(fn (RecordFollowupCase $case): string => $case->academic_period_id.':'.$case->category->value) as $group) {
            /** @var RecordFollowupCase $first */
            $first = $group->first();
            $period = $timeline->find($first->academic_period_id);

            if ($period === null) {
                continue;
            }

            $counts = $this->counter
                ->perStudent(
                    array_values(array_unique($group->map(fn (RecordFollowupCase $case): int => $case->class_id)->all())),
                    FollowupWindow::forPeriod($period, $timeline),
                    $first->category,
                    array_values(array_unique($group->map(fn (RecordFollowupCase $case): int => $case->enrollment_id)->all())),
                )
                ->keyBy(fn (array $row): string => $row['class_id'].':'.$row['enrollment_id']);

            foreach ($group as $case) {
                $occurrences = $counts->get($case->class_id.':'.$case->enrollment_id)['occurrences'] ?? 0;

                if ($occurrences >= RecordFollowupCategory::ALERT_THRESHOLD) {
                    $pending->push(['case' => $case, 'occurrences' => $occurrences]);
                }
            }
        }

        // Most recent alert first; the id keeps equal instants in a stable order.
        return $pending
            ->sort(fn (array $left, array $right): int => [$right['case']->alerted_at, $left['case']->id] <=> [$left['case']->alerted_at, $right['case']->id])
            ->values();
    }
}
