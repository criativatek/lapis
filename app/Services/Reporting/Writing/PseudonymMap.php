<?php

namespace App\Services\Reporting\Writing;

use App\Models\Enrollment;
use App\Models\Report;
use App\Support\Privacy\Pseudonyms;

/**
 * Names out before the text leaves, names back after it returns (§10).
 *
 * THE PREFERENCE IN §10 IS STRONG AND IT IS THE RIGHT ONE. «Maria Silva revela
 * dificuldades na planificação da escrita» is a sentence about a named minor. It
 * leaves as «Aluno A revela dificuldades na planificação da escrita», comes back
 * rephrased, and «Aluno A» becomes «Maria Silva» again in this process, on this
 * machine. What a remote system holds in its logs is a letter.
 *
 * WHAT THIS CLASS OWNS, AFTER THE AI CORE: the report-specific question of WHICH
 * names are in play — every enrolled student of the report's class, plus the
 * subject of an individual report. The substitution rules themselves moved to
 * `App\Support\Privacy\Pseudonyms`, unchanged, because the AI core needs the
 * same rules for material that is not a report and two implementations of «how a
 * name becomes a pseudonym» would eventually disagree. This class's public
 * behaviour is exactly what it was.
 *
 * WHAT IT CANNOT DO, AND IT IS WRITTEN DOWN RATHER THAN HOPED AWAY (§40): it
 * only knows the names it was given. A teacher who types the name of a sibling,
 * a colleague or a student from another class into a free-text field has written
 * a name this map has never seen, and no amount of substitution will catch it.
 * That is why the screen says out loud, on the sections that can carry names,
 * what is about to happen — so that sending them is a decision somebody made
 * rather than one the software made for them.
 */
readonly class PseudonymMap
{
    /**
     * @param  array<string, string>  $byName  the real name => «Aluno A»
     */
    protected function __construct(public array $byName, protected Pseudonyms $pseudonyms) {}

    public static function for(Report $report): self
    {
        $names = [];

        $class = $report->schoolClass;

        if ($class !== null) {
            $names = $class->activeEnrollments()
                ->with('student.identity')
                ->orderBy('class_number')
                ->get()
                ->map(fn (Enrollment $enrollment): ?string => $enrollment->student->identity?->display_name)
                ->filter(fn (?string $name): bool => is_string($name) && trim($name) !== '')
                ->values()
                ->all();
        }

        // An individual report is about somebody who may not be in that list —
        // a report on a student who has since left the class still names them.
        $subject = $report->enrollment?->student?->identity?->display_name;

        if (is_string($subject) && trim($subject) !== '') {
            $names[] = $subject;
        }

        return self::of(array_values($names));
    }

    /**
     * @param  list<string>  $names
     */
    public static function of(array $names): self
    {
        $pseudonyms = Pseudonyms::of($names);

        return new self($pseudonyms->byName, $pseudonyms);
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
     * Asserted rather than assumed: the substitution is a regex over text this
     * module did not write, and «it cannot happen» is not a thing to find out
     * from a support ticket.
     */
    public function coversEverythingIn(string $text): bool
    {
        return $this->pseudonyms->coversEverythingIn($text);
    }
}
