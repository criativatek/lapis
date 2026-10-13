<?php

namespace App\Services\Evidence\Followup;

use App\Models\EvidenceRecord;
use App\Models\RecordFollowupCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * THE count of the follow-up — the one place that decides what an
 * occurrence is. Summaries, per-student lists, the detail, the alerts and the
 * Inovar message all ask here; nothing else counts these three things.
 *
 * An occurrence is one logbook row (soft-deleted rows never count) that names
 * one student and belongs to a RecordFollowupCategory, inside the window.
 * Each row counts once: two rows on the same day are two occurrences, and no
 * query here joins anything that could multiply rows (groups, timetables and
 * lessons never enter).
 *
 * Callers pass the class ids they are ALLOWED to read; this class does not
 * authorize, it only counts what it is given.
 */
final class RecordFollowupCounter
{
    /**
     * @param  list<int>  $classIds
     * @return Builder<EvidenceRecord>
     */
    public function occurrences(array $classIds, FollowupWindow $window, ?RecordFollowupCategory $category = null, ?int $enrollmentId = null): Builder
    {
        $query = EvidenceRecord::query()
            ->whereIn('evidence_records.class_id', $classIds)
            ->whereNotNull('evidence_records.enrollment_id')
            ->when($enrollmentId !== null, fn (Builder $query) => $query->where('evidence_records.enrollment_id', $enrollmentId));

        $window->applyTo($query);

        if ($category !== null) {
            $category->constrain($query);
        } else {
            RecordFollowupCategory::constrainAny($query);
        }

        return $query;
    }

    /**
     * Students and occurrences per category — every category is present, zeros
     * included. «Students» counts what the per-student list shows: one per
     * student per class (an enrollment belongs to one class), so the tile and
     * its list always agree — a student followed in two subjects is two lines
     * there and two here.
     *
     * @param  list<int>  $classIds
     * @return array<string, array{students: int, occurrences: int}>
     */
    public function summary(array $classIds, FollowupWindow $window, ?int $enrollmentId = null): array
    {
        $summary = [];

        foreach (RecordFollowupCategory::cases() as $category) {
            $summary[$category->value] = ['students' => 0, 'occurrences' => 0];
        }

        if ($classIds === []) {
            return $summary;
        }

        $rows = $this->occurrences($classIds, $window, null, $enrollmentId)
            ->toBase()
            ->selectRaw(RecordFollowupCategory::SQL_EXPRESSION.' as category')
            ->selectRaw('count(*) as occurrences')
            ->selectRaw('count(distinct evidence_records.enrollment_id) as students')
            ->groupByRaw(RecordFollowupCategory::SQL_EXPRESSION)
            ->get();

        foreach ($rows as $row) {
            $summary[(string) $row->category] = ['students' => (int) $row->students, 'occurrences' => (int) $row->occurrences];
        }

        return $summary;
    }

    /**
     * One row per (class, enrollment) with at least one occurrence of the
     * category, unsorted — the caller orders it with the names it loads.
     *
     * @param  list<int>  $classIds
     * @param  list<int>|null  $enrollmentIds
     * @return Collection<int, array{class_id: int, enrollment_id: int, occurrences: int, first_at: string, last_at: string}>
     */
    public function perStudent(array $classIds, FollowupWindow $window, RecordFollowupCategory $category, ?array $enrollmentIds = null): Collection
    {
        if ($classIds === [] || $enrollmentIds === []) {
            return collect();
        }

        return $this->occurrences($classIds, $window, $category)
            ->when($enrollmentIds !== null, fn (Builder $query) => $query->whereIn('evidence_records.enrollment_id', $enrollmentIds))
            ->toBase()
            ->select('evidence_records.class_id', 'evidence_records.enrollment_id')
            ->selectRaw('count(*) as occurrences')
            ->selectRaw('min(evidence_records.occurred_at) as first_at')
            ->selectRaw('max(evidence_records.occurred_at) as last_at')
            ->groupBy('evidence_records.class_id', 'evidence_records.enrollment_id')
            ->get()
            ->map(fn (object $row): array => [
                'class_id' => (int) $row->class_id,
                'enrollment_id' => (int) $row->enrollment_id,
                'occurrences' => (int) $row->occurrences,
                'first_at' => (string) $row->first_at,
                'last_at' => (string) $row->last_at,
            ]);
    }

    /**
     * The occurrences of one student in one class, oldest first — the exact
     * set the detail shows, the message covers and a confirmation declares.
     *
     * @return Collection<int, EvidenceRecord>
     */
    public function caseOccurrences(int $classId, int $enrollmentId, RecordFollowupCategory $category, FollowupWindow $window): Collection
    {
        return $this->occurrences([$classId], $window, $category, $enrollmentId)
            ->orderBy('evidence_records.occurred_at')
            ->orderBy('evidence_records.id')
            ->get();
    }

    public function countFor(int $classId, int $enrollmentId, RecordFollowupCategory $category, FollowupWindow $window): int
    {
        return $this->occurrences([$classId], $window, $category, $enrollmentId)->count();
    }
}
