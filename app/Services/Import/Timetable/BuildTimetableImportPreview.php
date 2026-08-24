<?php

namespace App\Services\Import\Timetable;

use App\Domain\Import\Timetable\ParsedTimetable;
use App\Domain\Import\Timetable\TimetableCandidateRow;
use App\Domain\Import\Timetable\TimetableClassMatch;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;

/**
 * The screen the teacher actually decides on: every block from the file, grouped
 * under the turma it was matched to, and an honest list of everything that was
 * not matched.
 *
 * WRITES NOTHING. Building a preview is a read, from beginning to end — no slot,
 * no lesson, no turma is created here or anywhere on the way to here. Only the
 * explicit confirmation writes, and it re-runs the same checks against the
 * database as it does.
 */
class BuildTimetableImportPreview
{
    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_AMBIGUOUS = 'ambiguous';

    public const REASON_NOT_CURRICULAR = 'not_curricular';

    public function __construct(protected DetectSlotConflicts $conflicts) {}

    /**
     * @param  Collection<int, SchoolClass>  $classes  the teacher's own turmas for the selected year, with `subject` and `recurringLessonSlots` loaded
     * @return array{groups: list<array<string, mixed>>, unassociated: list<array<string, mixed>>, classes: list<array<string, mixed>>}
     */
    public function build(ParsedTimetable $timetable, Collection $classes): array
    {
        $matcher = new MatchTimetableClasses($classes);
        $everyClass = array_values($classes->map($this->classOption(...))->all());

        $groups = [];
        $unassociated = [];

        foreach ($timetable->rows as $row) {
            if (! $row->isCurricularCandidate()) {
                // Support blocks, co-teaching codes, anything the file records
                // in the same grid that is not an ordinary lesson. Never
                // matched, never offered a turma to be assigned to — showing it
                // is the whole point, importing it is exactly what must not
                // happen.
                $unassociated[] = $this->unassociatedRow($row, self::REASON_NOT_CURRICULAR, []);

                continue;
            }

            $match = $matcher->match($row->classRaw, $row->subjectRaw);

            if ($match->status === TimetableClassMatch::STATUS_AMBIGUOUS) {
                $unassociated[] = $this->unassociatedRow(
                    $row,
                    self::REASON_AMBIGUOUS,
                    array_map($this->classOption(...), $match->candidates),
                );

                continue;
            }

            if (! $match->matched() || $match->schoolClass === null) {
                // Nothing plausible was found — but the whole (short) list of
                // the teacher's own turmas travels with the row anyway, because
                // a conservative matcher with an empty dropdown is correct and
                // useless at the same time.
                $unassociated[] = $this->unassociatedRow($row, self::REASON_NOT_FOUND, $everyClass);

                continue;
            }

            $schoolClass = $match->schoolClass;
            $key = (int) $schoolClass->getKey();

            $groups[$key] ??= [
                ...$this->classOption($schoolClass),
                'rows' => [],
            ];

            $status = $this->conflicts->status(
                $schoolClass->recurringLessonSlots,
                $row->dayOfWeek,
                $row->startsAt,
                $row->endsAt,
            );

            $groups[$key]['rows'][] = [
                'day_of_week' => $row->dayOfWeek,
                'weekday_label' => $row->weekdayLabel,
                'starts_at' => $row->startsAt,
                'ends_at' => $row->endsAt,
                'subject_raw' => $row->subjectRaw,
                'room_raw' => $row->roomRaw,
                'raw_text' => $row->rawText,
                'status' => $status,
                // Only a block that is genuinely new starts out selected: a
                // duplicate or a conflict is shown, explained, and left alone.
                // The confirmation re-checks this against the database anyway,
                // so an unticked box is a convenience, never the safeguard.
                'include' => $status === DetectSlotConflicts::STATUS_NEW,
            ];
        }

        foreach ($groups as $key => $group) {
            $groups[$key]['rows'] = $this->sorted($group['rows']);
        }

        usort($groups, fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['label'],
            (string) $right['label'],
        ));

        return [
            'groups' => $groups,
            'unassociated' => $this->sorted($unassociated),
            'classes' => $everyClass,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    protected function unassociatedRow(TimetableCandidateRow $row, string $reason, array $candidates): array
    {
        return [
            'day_of_week' => $row->dayOfWeek,
            'weekday_label' => $row->weekdayLabel,
            'starts_at' => $row->startsAt,
            'ends_at' => $row->endsAt,
            'subject_raw' => $row->subjectRaw,
            'class_raw' => $row->classRaw,
            'room_raw' => $row->roomRaw,
            'raw_text' => $row->rawText,
            'reason' => $reason,
            'candidates' => $candidates,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function classOption(SchoolClass $schoolClass): array
    {
        return [
            'class_id' => (int) $schoolClass->getKey(),
            'class_ulid' => $schoolClass->ulid,
            'label' => $schoolClass->label,
            'subject' => $schoolClass->subject?->name,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function sorted(array $rows): array
    {
        usort($rows, function (array $left, array $right): int {
            return [$left['day_of_week'], $left['starts_at']] <=> [$right['day_of_week'], $right['starts_at']];
        });

        return $rows;
    }
}
