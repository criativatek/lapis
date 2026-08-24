<?php

namespace Tests\Unit\Import;

use App\Domain\Import\Timetable\TimetableClassMatch;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Services\Import\Timetable\MatchTimetableClasses;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Matching a printed class label to a turma — and, more importantly, not
 * matching it.
 *
 * The whole risk of this import lives here: a wrongly matched turma writes a
 * recurring lesson into somebody else's week, every week, for a year. So the
 * only thing that counts as a match is exact equality after normalisation, and
 * two answers are no answer at all.
 *
 * Which turmas even reach the matcher is settled elsewhere and proved elsewhere
 * — TimetableImportTest covers the tenancy and authorisation of that set against
 * a real database. Here the set is given, and only the deciding is under test.
 */
class MatchTimetableClassesTest extends TestCase
{
    #[Test]
    public function the_printed_label_and_the_stored_label_normalise_to_the_same_key(): void
    {
        // The file prints «7º C»; this application stores «7.º C»; a teacher
        // typing quickly writes «7C». One turma, three spellings.
        $key = MatchTimetableClasses::normalise('7º C');

        $this->assertSame($key, MatchTimetableClasses::normalise('7.º C'));
        $this->assertSame($key, MatchTimetableClasses::normalise('7C'));
        $this->assertSame($key, MatchTimetableClasses::normalise('  7.º   c  '));
        $this->assertSame('7c', $key);
    }

    #[Test]
    public function two_labels_that_merely_look_alike_still_do_not_match(): void
    {
        $this->assertNotSame(
            MatchTimetableClasses::normalise('7.º C'),
            MatchTimetableClasses::normalise('7.º G'),
        );
        $this->assertNotSame(
            MatchTimetableClasses::normalise('7.º C'),
            MatchTimetableClasses::normalise('8.º C'),
        );
        $this->assertNotSame(
            MatchTimetableClasses::normalise('7.º C'),
            MatchTimetableClasses::normalise('17.º C'),
        );
    }

    #[Test]
    public function a_single_turma_with_that_label_is_matched(): void
    {
        $matcher = new MatchTimetableClasses([$this->schoolClass(1, '7.º C', 'MAT')]);

        $match = $matcher->match('7º C', 'MAT');

        $this->assertTrue($match->matched());
        $this->assertSame(1, $match->schoolClass?->getKey());
    }

    #[Test]
    public function a_turma_is_matched_even_when_the_files_subject_code_means_nothing_here(): void
    {
        // «PORT» and a stored code of «PT7» have no deterministic relationship,
        // and inventing one would be guessing. The label is the signal; the
        // subject code only ever breaks a tie.
        $matcher = new MatchTimetableClasses([$this->schoolClass(1, '7.º C', 'PT7')]);

        $this->assertTrue($matcher->match('7º C', 'PORT')->matched());
    }

    #[Test]
    public function two_turmas_with_the_same_label_are_ambiguous_and_never_silently_resolved(): void
    {
        $matcher = new MatchTimetableClasses([
            $this->schoolClass(1, '7.º C', 'MAT'),
            $this->schoolClass(2, '7.º C', 'CID'),
        ]);

        $match = $matcher->match('7º C', 'PORT');

        $this->assertSame(TimetableClassMatch::STATUS_AMBIGUOUS, $match->status);
        $this->assertNull($match->schoolClass);
        // Both are named, so the teacher has something to choose between.
        $this->assertSame([1, 2], array_map(
            fn (SchoolClass $class): int => (int) $class->getKey(),
            $match->candidates,
        ));
    }

    #[Test]
    public function the_files_subject_code_breaks_a_tie_when_it_matches_exactly_one_of_them(): void
    {
        $matcher = new MatchTimetableClasses([
            $this->schoolClass(1, '7.º C', 'MAT'),
            $this->schoolClass(2, '7.º C', 'CID'),
        ]);

        $match = $matcher->match('7º C', 'CID');

        $this->assertTrue($match->matched());
        $this->assertSame(2, $match->schoolClass?->getKey());
    }

    #[Test]
    public function no_turma_with_that_label_is_reported_as_not_found(): void
    {
        $matcher = new MatchTimetableClasses([$this->schoolClass(1, '7.º C', 'MAT')]);

        $match = $matcher->match('9º D', 'PORT');

        $this->assertSame(TimetableClassMatch::STATUS_NOT_FOUND, $match->status);
        $this->assertNull($match->schoolClass);
        $this->assertSame([], $match->candidates);
    }

    #[Test]
    public function an_entry_with_no_class_label_at_all_is_never_matched(): void
    {
        $matcher = new MatchTimetableClasses([$this->schoolClass(1, '7.º C', 'MAT')]);

        $this->assertSame(TimetableClassMatch::STATUS_NOT_FOUND, $matcher->match(null, 'MAT')->status);
        $this->assertSame(TimetableClassMatch::STATUS_NOT_FOUND, $matcher->match('   ', 'MAT')->status);
    }

    private function schoolClass(int $id, string $label, string $subjectCode): SchoolClass
    {
        $schoolClass = new SchoolClass(['label' => $label]);
        $schoolClass->forceFill(['id' => $id]);
        // Set rather than loaded: this test never touches a database, and
        // reading the relation would go looking for one.
        $schoolClass->setRelation('subject', new Subject(['name' => 'Disciplina', 'code' => $subjectCode]));

        return $schoolClass;
    }
}
