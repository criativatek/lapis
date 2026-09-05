<?php

namespace App\Services\Evidence\Ai;

use App\Models\SchoolClass;
use App\Support\Privacy\Pseudonyms;

/**
 * Names out before a draft description leaves the building, names back after
 * the suggestion returns — the Registos equivalent of
 * `App\Services\Reporting\Writing\PseudonymMap` (SUP-U8FMAE).
 *
 * A SEPARATE CLASS, NOT A REUSE OF THE REPORTING ONE. Both build on the same
 * shared substitution rules (`App\Support\Privacy\Pseudonyms`), but WHICH names
 * are in play is a different question here: a report's roster is the class plus
 * one possible subject, while an incident description is written about the
 * class as it stands today — there is no report, no enrollment history to
 * reach through, nothing to derive from but the roll itself.
 *
 * A disciplinary description is exactly the kind of prose §10 of the AI Core
 * brief was written for: a teacher writing «o Tiago empurrou o colega» has
 * written a sentence about a named minor, and it must leave as «Aluno A
 * empurrou o colega».
 */
readonly class IncidentPseudonymMap
{
    protected function __construct(protected Pseudonyms $pseudonyms) {}

    public static function forClass(SchoolClass $class): self
    {
        $names = $class->activeEnrollments()
            ->with('student.identity')
            ->orderBy('class_number')
            ->get()
            ->map(fn ($enrollment): ?string => $enrollment->student->identity?->display_name)
            ->filter(fn (?string $name): bool => is_string($name) && trim($name) !== '')
            ->values()
            ->all();

        return new self(Pseudonyms::of(array_values($names)));
    }

    public function isEmpty(): bool
    {
        return $this->pseudonyms->isEmpty();
    }

    /** Replace every name this map knows with its pseudonym. */
    public function apply(string $text): string
    {
        return $this->pseudonyms->apply($text);
    }

    /** Put the names back. */
    public function rehydrate(string $text): string
    {
        return $this->pseudonyms->rehydrate($text);
    }

    /**
     * Whether any name this map knows about survived the substitution.
     *
     * Asserted rather than assumed, exactly as the Relatórios equivalent does:
     * the substitution is a regex over text this module did not write.
     */
    public function coversEverythingIn(string $text): bool
    {
        return $this->pseudonyms->coversEverythingIn($text);
    }
}
