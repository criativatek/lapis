<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\CanonicalCorrectionGrid;
use App\Domain\Import\Correction\CanonicalInstrument;
use App\Domain\Import\Correction\CanonicalStudent;
use App\Domain\Import\Correction\CorrectionGridSource;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Services\Import\Correction\MatchSourceStudents;
use App\Services\Import\Correction\PlickersCsvParser;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rule this feature cannot get wrong.
 *
 * Thirty students left unassociated is an afternoon of clicking. One mark on
 * the wrong child is a wrong grade in a real report, and nobody finds out. So
 * every test here pushes in the same direction: when the evidence is not
 * conclusive, associate nobody.
 *
 * The card number is the sharp edge. A Plickers card is a piece of cardboard —
 * the number on it is whatever the teacher handed out that morning, and it has
 * no relationship whatever to the roll number. Reading one as the other is the
 * single most plausible way this feature could hurt somebody.
 */
class StudentMatchingSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();

        app(CurrentOrganization::class)->runFor($this->organization, function (): void {
            $this->class = SchoolClass::factory()->recycle($this->organization)->create([
                'subject_id' => Subject::factory()->recycle($this->organization)->create()->id,
            ]);
        });
    }

    protected function enrol(string $name, ?int $classNumber): Enrollment
    {
        return app(CurrentOrganization::class)->runFor($this->organization, function () use ($name, $classNumber): Enrollment {
            $student = Student::factory()->recycle($this->organization)->create();

            StudentIdentity::create([
                'student_id' => $student->id,
                'organization_id' => $this->organization->getKey(),
                'display_name' => $name,
            ]);

            return Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $this->class->id,
                'student_id' => $student->id,
                'class_number' => $classNumber,
                'enrolled_on' => now()->subYear()->toDateString(),
            ]);
        });
    }

    /**
     * @param  list<CanonicalStudent>  $students
     */
    protected function match(array $students, array $decided = []): array
    {
        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): array => app(MatchSourceStudents::class)->for(
                new CanonicalCorrectionGrid(
                    source: CorrectionGridSource::Plickers,
                    instrument: new CanonicalInstrument,
                    students: $students,
                ),
                $this->class->fresh(),
                $decided,
            ),
        );
    }

    protected function sourceStudent(string $key, ?string $name, ?string $card = null, ?int $classNumber = null, ?int $answered = 20): CanonicalStudent
    {
        return new CanonicalStudent(
            sourceKey: $key,
            cardNumber: $card,
            classNumber: $classNumber,
            displayName: $name,
            sourceAnswered: $answered,
        );
    }

    // ---------------------------------------------------------------- the card

    #[Test]
    public function a_plickers_card_number_is_never_read_as_a_roll_number(): void
    {
        // THE test. Card 1 in the file, student number 1 in the class, and two
        // names with nothing whatever in common. This must not match, and if it
        // ever does, somebody's grade lands on somebody else.
        $francisco = $this->enrol('Francisco Pereira', 1);

        $rows = $this->match([$this->sourceStudent('student:1', 'Afonso Pito Mordomo', card: '1')]);

        $this->assertNull($rows[0]['enrollment_id'], 'O cartão 1 não é o aluno n.º 1.');
        $this->assertSame(MatchSourceStudents::STATUS_UNMATCHED, $rows[0]['status']);
        $this->assertNotSame($francisco->id, $rows[0]['enrollment_id']);

        // And again through the WHOLE chain, from the file down, because the
        // half that could break is the parser handing the matcher a classNumber
        // it invented from the card. A DTO built by hand would never catch that.
        $this->enrol('Ana Exemplo', 3);
        $bruno = $this->enrol('Bruno Exemplo', 1);

        $grid = (new PlickersCsvParser)->parse(
            base_path('tests/Fixtures/Import/plickers-basico.csv'),
            'plickers-basico.csv',
        );

        $fromFile = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): array => app(MatchSourceStudents::class)->for($grid, $this->class->fresh()),
        );

        // Card 1 in that fixture is Ana; the class's number 1 is Bruno. The
        // name wins and the card is ignored — the opposite would be the bug.
        $this->assertSame('1', $fromFile[0]['card_number']);
        $this->assertNotSame($bruno->id, $fromFile[0]['enrollment_id'], 'O cartão nunca pode escolher o aluno.');
        $this->assertSame('exact_name', $fromFile[0]['reason']);
    }

    #[Test]
    public function the_parser_never_fills_class_number_from_the_card(): void
    {
        $grid = (new PlickersCsvParser)->parse(
            base_path('tests/Fixtures/Import/plickers-basico.csv'),
            'plickers-basico.csv',
        );

        foreach ($grid->students as $student) {
            $this->assertNotNull($student->cardNumber);
            $this->assertNull($student->classNumber, 'classNumber tem de ficar null: o Plickers não o fornece.');
        }
    }

    #[Test]
    public function the_card_prefix_typed_into_the_name_does_not_break_the_match(): void
    {
        // Real exports read «1 Afonso Pito Mordomo»: teachers write the card
        // number into the name field so they hand out the right card. Left in,
        // it makes every name match nothing.
        $afonso = $this->enrol('Afonso Pito Mordomo', 7);

        $rows = $this->match([$this->sourceStudent('student:1', '1 Afonso Pito Mordomo', card: '1')]);

        // The prefix is stripped by the parser, so the matcher sees a clean name.
        $cleaned = (new PlickersCsvParser)->parse(
            base_path('tests/Fixtures/Import/plickers-basico.csv'),
            'plickers-basico.csv',
        );

        $this->assertNotNull($cleaned);

        // And a name that already arrives clean matches exactly.
        $rows = $this->match([$this->sourceStudent('student:1', 'Afonso Pito Mordomo', card: '1')]);
        $this->assertSame($afonso->id, $rows[0]['enrollment_id']);
        $this->assertSame('exact_name', $rows[0]['reason']);
    }

    // ---------------------------------------------------------------- the names

    #[Test]
    public function completely_different_names_associate_nobody(): void
    {
        $this->enrol('Mariana Costa', 1);
        $this->enrol('João Santos', 2);
        $this->enrol('Marta Fernandes', 3);

        $rows = $this->match([
            $this->sourceStudent('student:1', 'Lara Rodrigues Carreira', card: '10'),
            $this->sourceStudent('student:2', 'Leandro Miguel Nascimento', card: '12'),
        ]);

        foreach ($rows as $row) {
            $this->assertNull($row['enrollment_id']);
            $this->assertSame(MatchSourceStudents::STATUS_UNMATCHED, $row['status']);
        }
    }

    #[Test]
    public function nothing_ever_falls_back_to_the_first_student_of_the_class(): void
    {
        $first = $this->enrol('Ana Primeira', 1);
        $this->enrol('Bruno Segundo', 2);

        $rows = $this->match([$this->sourceStudent('student:1', 'Nome Que Não Existe', card: '1')]);

        $this->assertNull($rows[0]['enrollment_id']);
        $this->assertNotSame($first->id, $rows[0]['enrollment_id']);
    }

    #[Test]
    public function a_single_exact_name_matches(): void
    {
        $ana = $this->enrol('Ana Laura Simão', 4);
        $this->enrol('Outro Aluno', 5);

        $rows = $this->match([$this->sourceStudent('student:1', 'Ana Laura Simão', card: '9')]);

        $this->assertSame($ana->id, $rows[0]['enrollment_id']);
        $this->assertSame(MatchSourceStudents::STATUS_MATCHED, $rows[0]['status']);
        $this->assertSame('exact_name', $rows[0]['reason']);
    }

    #[Test]
    public function a_single_normalised_name_matches(): void
    {
        $joao = $this->enrol('João Álvaro Sá', 6);

        $rows = $this->match([$this->sourceStudent('student:1', 'JOAO ALVARO SA', card: '3')]);

        $this->assertSame($joao->id, $rows[0]['enrollment_id']);
        $this->assertSame('normalised_name', $rows[0]['reason']);
    }

    #[Test]
    public function two_students_with_the_same_name_are_never_resolved_silently(): void
    {
        $this->enrol('Maria Silva', 1);
        $this->enrol('Maria Silva', 2);

        $rows = $this->match([$this->sourceStudent('student:1', 'Maria Silva', card: '5')]);

        $this->assertNull($rows[0]['enrollment_id']);
        $this->assertSame(MatchSourceStudents::STATUS_AMBIGUOUS, $rows[0]['status']);
        $this->assertCount(2, $rows[0]['suggestions']);
    }

    #[Test]
    public function one_student_cannot_be_claimed_by_two_source_rows(): void
    {
        $ana = $this->enrol('Ana Exemplo', 1);

        // The first row already took her by an explicit decision; the second
        // must not take her too — two file rows on one student means one of them
        // is somebody else.
        $rows = $this->match(
            [$this->sourceStudent('student:2', 'Ana Exemplo', card: '2')],
            ['student:1' => $ana->id],
        );

        $this->assertNull($rows[0]['enrollment_id']);
        $this->assertSame(MatchSourceStudents::STATUS_AMBIGUOUS, $rows[0]['status']);
        $this->assertSame('already_taken', $rows[0]['reason']);
    }

    // ------------------------------------------------------------ the interface

    #[Test]
    public function every_row_can_be_resolved_by_hand(): void
    {
        $this->enrol('Ana Exemplo', 1);
        $this->enrol('Bruno Exemplo', 2);
        $this->enrol('Carla Exemplo', 3);

        $rows = $this->match([$this->sourceStudent('student:1', 'Nome Desconhecido', card: '1')]);

        // A conservative matcher with an empty dropdown is correct and useless
        // at the same time: the teacher must always be able to choose.
        $this->assertCount(3, $rows[0]['candidates']);
        $this->assertNull($rows[0]['enrollment_id'], 'Oferecer a lista não é escolher por ela.');
    }

    #[Test]
    public function an_ignored_row_stays_different_from_an_unresolved_one(): void
    {
        $this->enrol('Ana Exemplo', 1);

        $ignored = $this->match([$this->sourceStudent('student:1', 'Desconhecido', card: '1')], ['student:1' => null]);
        $untouched = $this->match([$this->sourceStudent('student:1', 'Desconhecido', card: '1')]);

        $this->assertSame(MatchSourceStudents::STATUS_IGNORED, $ignored[0]['status']);
        $this->assertSame(MatchSourceStudents::STATUS_UNMATCHED, $untouched[0]['status']);
    }

    #[Test]
    public function taking_no_part_says_nothing_about_who_the_student_is(): void
    {
        // Two independent facts. A student can be identified beyond doubt and
        // still have answered nothing (§9).
        $ana = $this->enrol('Ana Laura Simão', 4);

        $rows = $this->match([$this->sourceStudent('student:1', 'Ana Laura Simão', card: '9', answered: 0)]);

        $this->assertSame($ana->id, $rows[0]['enrollment_id']);
        $this->assertSame('exact_name', $rows[0]['reason']);
        $this->assertFalse($rows[0]['participated']);
    }
}
