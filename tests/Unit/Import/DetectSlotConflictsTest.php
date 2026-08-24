<?php

namespace Tests\Unit\Import;

use App\Models\RecurringLessonSlot;
use App\Services\Import\Timetable\DetectSlotConflicts;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * New, already there, or in the way.
 *
 * Nothing in this application prevented two overlapping recurring slots before
 * this import existed, so every rule here is new and every one of them is
 * tested: there is no older behaviour to lean on.
 */
class DetectSlotConflictsTest extends TestCase
{
    private DetectSlotConflicts $conflicts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conflicts = new DetectSlotConflicts;
    }

    #[Test]
    public function an_hour_nothing_else_occupies_is_new(): void
    {
        $this->assertSame(
            DetectSlotConflicts::STATUS_NEW,
            $this->conflicts->status([$this->slot(1, '09:30', '10:20')], 1, '11:00', '11:50'),
        );
    }

    #[Test]
    public function the_very_same_block_is_reported_as_already_there(): void
    {
        // This is what makes importing the same file twice create nothing the
        // second time.
        $this->assertSame(
            DetectSlotConflicts::STATUS_EXISTS,
            $this->conflicts->status([$this->slot(1, '09:30', '10:20')], 1, '09:30', '10:20'),
        );
    }

    #[Test]
    public function the_stored_seconds_do_not_make_an_identical_block_look_different(): void
    {
        // The column is a `time`, so Eloquent hands back «HH:MM:SS» while the
        // file and the manual form both speak «HH:MM». Comparing the strings
        // directly would make every existing slot look unlike every imported
        // one, and duplicate the entire timetable on a re-import.
        $this->assertSame(
            DetectSlotConflicts::STATUS_EXISTS,
            $this->conflicts->status([$this->slot(1, '09:30:00', '10:20:00')], 1, '09:30', '10:20'),
        );
    }

    #[Test]
    public function an_overlapping_but_different_block_is_a_conflict_and_is_never_merged(): void
    {
        $this->assertSame(
            DetectSlotConflicts::STATUS_CONFLICT,
            $this->conflicts->status([$this->slot(1, '10:00', '11:00')], 1, '10:00', '10:50'),
        );
        $this->assertSame(
            DetectSlotConflicts::STATUS_CONFLICT,
            $this->conflicts->status([$this->slot(1, '10:00', '11:00')], 1, '10:30', '11:30'),
        );
        $this->assertSame(
            DetectSlotConflicts::STATUS_CONFLICT,
            $this->conflicts->status([$this->slot(1, '10:00', '11:00')], 1, '09:00', '12:00'),
        );
    }

    #[Test]
    public function two_blocks_that_merely_touch_do_not_overlap(): void
    {
        // 09:20–10:10 straight after 08:30–09:20 is the ordinary school day, not
        // a clash.
        $this->assertSame(
            DetectSlotConflicts::STATUS_NEW,
            $this->conflicts->status([$this->slot(1, '08:30', '09:20')], 1, '09:20', '10:10'),
        );
    }

    #[Test]
    public function the_same_hour_on_another_weekday_is_not_a_conflict(): void
    {
        $this->assertSame(
            DetectSlotConflicts::STATUS_NEW,
            $this->conflicts->status([$this->slot(1, '09:30', '10:20')], 3, '09:30', '10:20'),
        );
    }

    #[Test]
    public function an_exact_duplicate_wins_over_an_overlap_whichever_order_they_are_seen_in(): void
    {
        // Already-there is the more specific and the more useful answer: the
        // block must not be created again, whatever else it happens to touch.
        $slots = [$this->slot(1, '09:00', '10:00'), $this->slot(1, '09:30', '10:20')];

        $this->assertSame(
            DetectSlotConflicts::STATUS_EXISTS,
            $this->conflicts->status($slots, 1, '09:30', '10:20'),
        );
        $this->assertSame(
            DetectSlotConflicts::STATUS_EXISTS,
            $this->conflicts->status(array_reverse($slots), 1, '09:30', '10:20'),
        );
    }

    private function slot(int $dayOfWeek, string $startsAt, string $endsAt): RecurringLessonSlot
    {
        return new RecurringLessonSlot([
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
