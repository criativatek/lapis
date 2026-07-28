<?php

namespace App\Domain\Import;

/**
 * One student, as read from the roster spreadsheet, before any matching or
 * persistence. Plain data — never touches Eloquent.
 */
final readonly class RosterRow
{
    public function __construct(
        public string $name,
        public ?int $classNumber,
        public ?string $birthDate,
        public string $situationCode,
        public ?string $processNumber,
        public ?string $note,
    ) {}
}
