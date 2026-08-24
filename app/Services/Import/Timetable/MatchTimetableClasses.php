<?php

namespace App\Services\Import\Timetable;

use App\Domain\Import\Timetable\TimetableClassMatch;
use App\Models\SchoolClass;

/**
 * Says which turma a printed class label means — and refuses to decide when it
 * cannot be certain.
 *
 * Built on the same principle as MatchSourceStudents, and for the same reason:
 * THERE IS NO FUZZY MATCHING HERE, ON PURPOSE. A near-miss that looks confident
 * is worse than an honest blank, because a wrongly matched turma writes a
 * recurring lesson into somebody else's schedule every week for a year. The only
 * rung is exact equality of a normalised label; anything matching two turmas
 * matches none of them and goes to the teacher.
 *
 * Normalisation exists only to survive the one difference that is pure
 * typography: the file prints «7º C» and this application stores «7.º C». The
 * ordinal indicators are removed EXPLICITLY rather than left to iconv, whose
 * TRANSLIT would turn «º» into a letter «o» and make «7º C» and «7C» normalise
 * differently — the exact silent inconsistency this whole method exists to
 * prevent.
 *
 * THE CANDIDATE SET IS THE CALLER'S RESPONSIBILITY AND IS ALWAYS ALREADY
 * NARROWED: only turmas in the current organization that the current teacher
 * actually teaches ever reach this class, so no amount of matching can surface a
 * colleague's turma or another school's.
 */
class MatchTimetableClasses
{
    /** @var array<string, list<SchoolClass>> */
    protected array $byLabel = [];

    /**
     * @param  iterable<SchoolClass>  $classes
     */
    public function __construct(iterable $classes = [])
    {
        foreach ($classes as $class) {
            $this->byLabel[self::normalise($class->label)][] = $class;
        }
    }

    public function match(?string $classRaw, ?string $subjectRaw = null): TimetableClassMatch
    {
        if ($classRaw === null || trim($classRaw) === '') {
            return new TimetableClassMatch(TimetableClassMatch::STATUS_NOT_FOUND, null);
        }

        $candidates = $this->byLabel[self::normalise($classRaw)] ?? [];

        if ($candidates === []) {
            return new TimetableClassMatch(TimetableClassMatch::STATUS_NOT_FOUND, null);
        }

        if (count($candidates) === 1) {
            return new TimetableClassMatch(TimetableClassMatch::STATUS_MATCHED, $candidates[0], $candidates);
        }

        // Two turmas with the same label — the teacher takes «7.º C» for both
        // Português and Cidadania, say. The file's own subject code is the only
        // extra fact available, and it is used ONLY TO NARROW: if exactly one
        // candidate's subject code is literally that code, it wins; otherwise
        // the ambiguity stands and the teacher chooses. Nothing here invents a
        // relationship between «PORT» and «Português» — that mapping does not
        // exist in this application's data, so it is not guessed at.
        $narrowed = $this->withSubjectCode($candidates, $subjectRaw);

        if (count($narrowed) === 1) {
            return new TimetableClassMatch(TimetableClassMatch::STATUS_MATCHED, $narrowed[0], $candidates);
        }

        return new TimetableClassMatch(TimetableClassMatch::STATUS_AMBIGUOUS, null, $candidates);
    }

    /**
     * @param  list<SchoolClass>  $candidates
     * @return list<SchoolClass>
     */
    protected function withSubjectCode(array $candidates, ?string $subjectRaw): array
    {
        if ($subjectRaw === null || trim($subjectRaw) === '') {
            return [];
        }

        $wanted = self::normalise($subjectRaw);

        return array_values(array_filter($candidates, function (SchoolClass $class) use ($wanted): bool {
            $code = $class->subject?->code;

            return $code !== null && self::normalise($code) === $wanted;
        }));
    }

    /**
     * «7º C», «7.º C» and «7C» are one turma, written three ways.
     *
     * Case, accents, ordinal indicators, punctuation and spacing are all
     * removed; nothing else is. Two labels that differ by a single letter or a
     * single digit still normalise differently, and still do not match.
     */
    public static function normalise(string $label): string
    {
        $label = mb_strtolower(trim($label));
        $label = str_replace(['º', 'ª', '°'], '', $label);
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);

        if ($transliterated !== false) {
            $label = $transliterated;
        }

        return (string) preg_replace('/[^a-z0-9]/', '', $label);
    }
}
