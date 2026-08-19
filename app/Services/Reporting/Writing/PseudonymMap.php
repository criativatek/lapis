<?php

namespace App\Services\Reporting\Writing;

use App\Models\Enrollment;
use App\Models\Report;

/**
 * Names out before the text leaves, names back after it returns (§10).
 *
 * THE PREFERENCE IN §10 IS STRONG AND IT IS THE RIGHT ONE. «Maria Silva revela
 * dificuldades na planificação da escrita» is a sentence about a named minor. It
 * leaves as «Aluno A revela dificuldades na planificação da escrita», comes back
 * rephrased, and «Aluno A» becomes «Maria Silva» again in this process, on this
 * machine. What a remote system holds in its logs is a letter.
 *
 * BUILT FROM THE ROSTER, WHICH IS THE ONLY LIST THAT EXISTS. Every enrolled
 * student of the report's class, plus the subject of an individual report. Longer
 * names are substituted first, so «Maria Silva Costa» is never half-replaced by
 * an entry for «Maria Silva».
 *
 * FIRST NAMES ARE INCLUDED, AND THAT IS A JUDGEMENT CALL. A teacher writing
 * about their own class writes «a Maria», not «a Maria Silva Costa», so a map
 * that only knew full names would cover almost nothing of what teachers actually
 * type. The cost is that a first name shared with an ordinary word would be
 * replaced too — so single-token names shorter than four characters are left
 * out, and the whole substitution is whole-word only.
 *
 * WHAT THIS CANNOT DO, AND IT IS WRITTEN DOWN RATHER THAN HOPED AWAY (§40): it
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
    protected function __construct(public array $byName) {}

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
        $map = [];
        $next = 'A';

        foreach (array_values(array_unique(array_map('trim', $names))) as $name) {
            if ($name === '') {
                continue;
            }

            $pseudonym = 'Aluno '.$next;
            $next++;

            $map[$name] = $pseudonym;

            // The parts a teacher actually types. Two-letter and three-letter
            // tokens are skipped: «Ana» is a name, but so is the risk of
            // replacing a preposition, and this map errs towards leaving a rare
            // short name in the hands of the notice on the screen rather than
            // mangling every sentence that contains «dos».
            foreach (preg_split('/\s+/u', $name) ?: [] as $part) {
                if (mb_strlen($part) >= 4 && ! array_key_exists($part, $map)) {
                    $map[$part] = $pseudonym;
                }
            }
        }

        // Longest first, so «Maria Silva Costa» is never half-replaced by the
        // entry for «Maria».
        uksort($map, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

        return new self($map);
    }

    public function isEmpty(): bool
    {
        return $this->byName === [];
    }

    /** Replace every name this map knows with its pseudonym. */
    public function apply(string $text): string
    {
        foreach ($this->byName as $name => $pseudonym) {
            $text = (string) preg_replace(
                '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u',
                $pseudonym,
                $text,
            );
        }

        return $text;
    }

    /**
     * Put the names back.
     *
     * The FIRST entry that maps to a pseudonym wins, which is the full name —
     * `of()` inserts it before the parts. So a rewrite that shortened «Aluno A»
     * to nothing loses nothing, and one that kept it restores the person's whole
     * name rather than a fragment of it.
     */
    public function rehydrate(string $text): string
    {
        foreach ($this->fullNames() as $pseudonym => $name) {
            $text = str_replace($pseudonym, $name, $text);
        }

        return $text;
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
        foreach (array_keys($this->byName) as $name) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $text) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * pseudonym => the fullest name that maps to it.
     *
     * @return array<string, string>
     */
    protected function fullNames(): array
    {
        $names = [];

        foreach ($this->byName as $name => $pseudonym) {
            if (! array_key_exists($pseudonym, $names) || mb_strlen($name) > mb_strlen($names[$pseudonym])) {
                $names[$pseudonym] = $name;
            }
        }

        return $names;
    }
}
