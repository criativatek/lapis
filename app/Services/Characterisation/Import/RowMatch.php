<?php

namespace App\Services\Characterisation\Import;

/**
 * Which enrolment a row was matched to, and how sure that is.
 *
 * `candidates` is populated whenever the answer is not a single confident one,
 * so the preview can offer the teacher a choice instead of a dead end. It
 * carries enrolment ULIDs, never ids: the preview is a client-side document and
 * a sequential id there would be both a leak and a footgun.
 */
readonly class RowMatch
{
    /**
     * @param  list<array{ulid: string, name: string, class_number: int|null}>  $candidates
     */
    public function __construct(
        public RowMatchState $state,
        public ?string $enrollmentUlid = null,
        public ?string $matchedName = null,
        public ?string $matchedBy = null,
        public array $candidates = [],
    ) {}

    public static function confident(string $ulid, string $name, string $matchedBy): self
    {
        return new self(RowMatchState::Confident, $ulid, $name, $matchedBy);
    }

    /**
     * @param  list<array{ulid: string, name: string, class_number: int|null}>  $candidates
     */
    public static function possible(string $ulid, string $name, array $candidates): self
    {
        return new self(RowMatchState::Possible, $ulid, $name, 'name_subsequence', $candidates);
    }

    /**
     * @param  list<array{ulid: string, name: string, class_number: int|null}>  $candidates
     */
    public static function ambiguous(array $candidates, string $matchedBy): self
    {
        return new self(RowMatchState::Ambiguous, null, null, $matchedBy, $candidates);
    }

    public static function notFound(): self
    {
        return new self(RowMatchState::NotFound);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'enrollment_ulid' => $this->enrollmentUlid,
            'matched_name' => $this->matchedName,
            'matched_by' => $this->matchedBy,
            'candidates' => $this->candidates,
            'preselected' => $this->state->isPreselected(),
        ];
    }
}
