<?php

// app/Domain/Import/RosterMatch.php

namespace App\Domain\Import;

/**
 * What the class already knows about one roster row, before anything is
 * written.
 *
 * A re-import's whole difficulty is here: the same person can arrive under a
 * corrected spelling, and two different people can arrive under the same one.
 * Answering merely "is this student already enrolled?" with a boolean cannot
 * tell those two apart, so this says WHICH enrolment, HOW it was recognised,
 * and — the case that must never be resolved by guessing — whether more than
 * one enrolment answered to the same evidence.
 *
 * `matchedBy` is not decoration: the preview shows it, because «reconhecido
 * pelo n.º de processo» and «reconhecido pelo nome» deserve different amounts
 * of trust from the teacher reading the screen.
 */
final readonly class RosterMatch
{
    public const BY_PROCESS_NUMBER = 'process_number';

    public const BY_NAME = 'name';

    /**
     * @param  ?int  $enrollmentId  the enrolment this row updates, or null for a student this class does not have
     * @param  ?string  $matchedBy  self::BY_PROCESS_NUMBER or self::BY_NAME — never a guess
     * @param  bool  $ambiguous  more than one enrolment answered; the teacher decides, this class never does
     * @param  ?string  $currentName  the name on record today, so the preview can show a change and not only a destination
     * @param  bool  $hasPhoto  whether that student already has a photo — the difference between «associar» and «substituir»
     * @param  list<array{enrollment_id: int, name: string, class_number: ?int}>  $candidates  who answered, when more than one did
     */
    public function __construct(
        public ?int $enrollmentId = null,
        public ?string $matchedBy = null,
        public bool $ambiguous = false,
        public ?string $currentName = null,
        public bool $hasPhoto = false,
        public array $candidates = [],
    ) {}

    /**
     * Nobody on this roll answers to this row — it is a new student.
     */
    public static function none(): self
    {
        return new self;
    }
}
