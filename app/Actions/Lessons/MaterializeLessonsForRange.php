<?php

namespace App\Actions\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterializeLessonsForRange
{
    private const TIMEZONE = 'Europe/Lisbon';

    /**
     * @return Collection<int, Lesson>
     */
    public function execute(
        SchoolClass $class,
        CarbonImmutable $from,
        CarbonImmutable $to,
        User $actor,
    ): Collection {
        $from = $from->setTimezone(self::TIMEZONE)->startOfDay();
        $to = $to->setTimezone(self::TIMEZONE)->startOfDay();

        return DB::transaction(function () use ($class, $from, $to, $actor): Collection {
            $lockedClass = SchoolClass::query()
                ->whereKey($class->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $academicYear = $lockedClass->academicYear()->firstOrFail();
            $academicYearStartsOn = CarbonImmutable::parse($academicYear->starts_on, self::TIMEZONE)->startOfDay();
            $academicYearEndsOn = CarbonImmutable::parse($academicYear->ends_on, self::TIMEZONE)->startOfDay();

            if ($from->greaterThan($to)
                || $from->lessThan($academicYearStartsOn)
                || $to->greaterThan($academicYearEndsOn)) {
                throw ValidationException::withMessages([
                    'range' => __('O intervalo tem de ficar dentro do ano letivo da turma.'),
                ]);
            }

            $lessons = collect();

            /** @var RecurringLessonSlot $slot */
            foreach ($lockedClass->recurringLessonSlots()->orderBy('id')->get() as $slot) {
                $slotStartsOn = $slot->starts_on === null
                    ? $from
                    : CarbonImmutable::parse($slot->starts_on, self::TIMEZONE)->startOfDay();
                $slotEndsOn = $slot->ends_on === null
                    ? $to
                    : CarbonImmutable::parse($slot->ends_on, self::TIMEZONE)->startOfDay();
                $occurrenceStartsOn = $from->greaterThan($slotStartsOn) ? $from : $slotStartsOn;
                $occurrenceEndsOn = $to->lessThan($slotEndsOn) ? $to : $slotEndsOn;

                if ($occurrenceStartsOn->greaterThan($occurrenceEndsOn)) {
                    continue;
                }

                for ($date = $occurrenceStartsOn; $date->lessThanOrEqualTo($occurrenceEndsOn); $date = $date->addDay()) {
                    if ($date->dayOfWeekIso !== $slot->day_of_week) {
                        continue;
                    }

                    $startsAt = CarbonImmutable::parse(
                        $date->toDateString().' '.$slot->starts_at,
                        self::TIMEZONE,
                    );
                    $endsAt = CarbonImmutable::parse(
                        $date->toDateString().' '.$slot->ends_at,
                        self::TIMEZONE,
                    );

                    $lessons->push(Lesson::query()->firstOrCreate(
                        [
                            'class_id' => $lockedClass->id,
                            'recurring_lesson_slot_id' => $slot->id,
                            'starts_at' => $startsAt,
                        ],
                        [
                            'ends_at' => $endsAt,
                            'status' => LessonStatus::Preparation,
                            'created_by' => $actor->id,
                        ],
                    ));
                }
            }

            return $lessons->sortBy('starts_at')->values();
        });
    }
}
