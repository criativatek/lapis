<?php

namespace App\Services\Evidence\Followup;

use App\Models\RecordFollowupCase;
use App\Models\RecordFollowupCategory;
use App\Models\SchoolClass;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The alert at the third occurrence (spec 2026-10-12, decisão do dono do
 * produto: substitui o aviso 3/6/9 da 0.125.0).
 *
 * A write that can ADD an occurrence calls `before()` with what it is about to
 * write — inside its transaction, after locking the class row — then writes,
 * then calls `after()`. A key (class × enrollment × category × period) is
 * alerted exactly when that operation took its count from below 3 to 3 or
 * more: 2→3 and 2→4 alert once, with the real count; 3→4, 5→6 never do. There
 * are no further cycles.
 *
 * At most one alert per key, ever: the row enters through `insertOrIgnore`
 * against the unique key, or turns `alerted_at` from null to now on a row a
 * confirmation created first. A re-save, a repeated request or two concurrent
 * saves therefore never produce a second one — and reading a page never
 * writes.
 *
 * A date outside every configured period has no key at all: no count, no
 * alert, and `outsidePeriods` tells the caller to say how to fix it, never to
 * fall back to the whole year.
 */
final class RecordFollowupAlerts
{
    public function __construct(
        private readonly RecordFollowupCounter $counter,
        private readonly CurrentOrganization $organization,
    ) {}

    /**
     * @param  iterable<array{enrollment_id: int|null, category: RecordFollowupCategory|null, date: CarbonInterface}>  $candidates
     */
    public function before(SchoolClass $class, iterable $candidates): RecordFollowupSnapshot
    {
        $timeline = AssessmentPeriodTimeline::forYear($class->academic_year_id);
        $keys = [];
        $outsidePeriods = false;

        foreach ($candidates as $candidate) {
            if ($candidate['enrollment_id'] === null || $candidate['category'] === null) {
                continue;
            }

            $period = $timeline->periodOn($candidate['date']);

            if ($period === null) {
                $outsidePeriods = true;

                continue;
            }

            $key = implode(':', [$candidate['enrollment_id'], $candidate['category']->value, $period->id]);

            if (isset($keys[$key])) {
                continue;
            }

            $window = FollowupWindow::forPeriod($period, $timeline);

            $keys[$key] = [
                'enrollment_id' => $candidate['enrollment_id'],
                'category' => $candidate['category'],
                'period' => $period,
                'window' => $window,
                'before' => $this->counter->countFor($class->id, $candidate['enrollment_id'], $candidate['category'], $window),
            ];
        }

        return new RecordFollowupSnapshot($class, array_values($keys), $outsidePeriods);
    }

    /**
     * Recounts every key of the snapshot and records the alerts this
     * operation caused.
     */
    public function after(RecordFollowupSnapshot $snapshot): RecordFollowupOutcome
    {
        $alerted = [];

        foreach ($snapshot->keys as $key) {
            $after = $this->counter->countFor($snapshot->class->id, $key['enrollment_id'], $key['category'], $key['window']);

            if ($key['before'] >= RecordFollowupCategory::ALERT_THRESHOLD || $after < RecordFollowupCategory::ALERT_THRESHOLD) {
                continue;
            }

            $case = $this->claim($snapshot->class, $key['enrollment_id'], $key['category'], $key['period']->id, $after);

            if ($case !== null) {
                $alerted[] = $case;
            }
        }

        return new RecordFollowupOutcome($alerted, $snapshot->outsidePeriods);
    }

    /**
     * Creates the alert for a key, or returns null when that key already had
     * one. Never throws on the race: the unique key decides.
     */
    private function claim(SchoolClass $class, int $enrollmentId, RecordFollowupCategory $category, int $periodId, int $count): ?RecordFollowupCase
    {
        $now = now();
        $key = [
            'class_id' => $class->id,
            'enrollment_id' => $enrollmentId,
            'academic_period_id' => $periodId,
            'category' => $category->value,
        ];

        $inserted = DB::table('record_followup_cases')->insertOrIgnore(array_merge($key, [
            'ulid' => (new RecordFollowupCase)->newUniqueId(),
            'organization_id' => $this->organization->id(),
            'alerted_at' => $now,
            'alerted_count' => $count,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        // A row may already exist without an alert: the teacher confirmed a
        // case that predates alerts, its count later fell below 3 and has now
        // crossed again. That crossing is the first alert this key ever gets.
        $claimed = $inserted === 1 || RecordFollowupCase::query()
            ->where($key)
            ->whereNull('alerted_at')
            ->update(['alerted_at' => $now, 'alerted_count' => $count, 'updated_at' => $now]) === 1;

        if (! $claimed) {
            return null;
        }

        return RecordFollowupCase::query()
            ->where($key)
            ->with(['enrollment.student.identity', 'academicPeriod', 'activeConfirmation'])
            ->first();
    }
}
