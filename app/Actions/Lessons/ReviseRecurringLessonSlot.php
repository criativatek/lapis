<?php

namespace App\Actions\Lessons;

use App\Models\RecurringLessonSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Splits an already-in-vigor RecurringLessonSlot into two rows instead of
 * editing it in place, so a schedule change never rewrites history.
 *
 * A materialized Lesson is permanently tied to the SPECIFIC slot row (by
 * numeric id) that produced it (App\Actions\Lessons\MaterializeLessonsForRange
 * reads slots fresh on every call and never mutates them). Creating a NEW
 * RecurringLessonSlot row for the changed pattern is therefore what preserves
 * history: old Lessons keep pointing at the old (now-closed) row, untouched,
 * simply because nothing here ever writes to `lessons`.
 *
 * LOCK, RE-CHECK, ACT — the current row is re-fetched with lockForUpdate()
 * inside the transaction (the same pattern MaterializeLessonsForRange and
 * ExecuteDataImport already use), so two concurrent revisions of the same
 * slot cannot both split it.
 */
class ReviseRecurringLessonSlot
{
    /**
     * @param  array{day_of_week: int, starts_at: string, ends_at: string, ends_on: string|null}  $newAttributes
     */
    public function execute(RecurringLessonSlot $slot, string $effectiveFrom, array $newAttributes): RecurringLessonSlot
    {
        return DB::transaction(function () use ($slot, $effectiveFrom, $newAttributes): RecurringLessonSlot {
            /** @var RecurringLessonSlot $current */
            $current = RecurringLessonSlot::query()->whereKey($slot->getKey())->lockForUpdate()->firstOrFail();

            $closesOn = CarbonImmutable::parse($effectiveFrom)->subDay()->toDateString();

            // Clamp, NEVER extend: an ends_on already earlier than
            // effective_from - 1 day is left exactly as it was, producing a
            // deliberate gap where neither version is active rather than
            // resurrecting a slot that was already scheduled to stop sooner.
            if ($current->ends_on === null || $current->ends_on->toDateString() > $closesOn) {
                $current->update(['ends_on' => $closesOn]);
            }

            // class_id/organization_id are the old row's own: organization_id
            // is not set explicitly here, exactly like LessonScheduleController
            // ::store() does not set it either — BelongsToOrganization stamps
            // it from the still-current tenant, which is the same organization
            // $current already belongs to for the whole length of this request.
            return RecurringLessonSlot::create([
                'class_id' => $current->class_id,
                'day_of_week' => $newAttributes['day_of_week'],
                'starts_at' => $newAttributes['starts_at'],
                'ends_at' => $newAttributes['ends_at'],
                'starts_on' => $effectiveFrom,
                'ends_on' => $newAttributes['ends_on'],
            ]);
        });
    }
}
