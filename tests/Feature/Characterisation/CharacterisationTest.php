<?php

namespace Tests\Feature\Characterisation;

use App\Models\AcademicYear;
use App\Models\CharacterisationRevision;
use App\Models\ClassCharacterisation;
use App\Models\Enrollment;
use App\Models\EnrollmentCharacterisation;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Writing, and re-writing, what a teacher knows about a class and its students.
 *
 * The two things these tests exist to make impossible: one teacher's text
 * landing on another class's student, and an edit that quietly erases what the
 * previous edit said.
 */
class CharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The page renders through the root Blade, which asks Vite for a
        // manifest this suite has no reason to build.
        $this->withoutVite();

        $this->user = User::factory()->create();
    }

    private function createClass(User $owner, string $label = '7.º A'): SchoolClass
    {
        $organization = $owner->personalOrganization();

        $context = app(CurrentOrganization::class)->runFor($organization, fn (): array => [
            'year' => AcademicYear::factory()->recycle($organization)
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($organization)->create()->id,
        ]);

        $this->actingAs($owner)->post('/classes', [
            'label' => $label,
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('label', $label)
            ->firstOrFail();
    }

    private function enrol(User $owner, SchoolClass $class, string $name, ?string $schoolNumber = null): Enrollment
    {
        return app(CurrentOrganization::class)->runFor(
            $owner->personalOrganization(),
            fn (): Enrollment => app(StudentEnrollmentService::class)->enrollNew($class, array_filter([
                'name' => $name,
                'school_number' => $schoolNumber,
            ])),
        );
    }

    // ------------------------------------------------ 1. a turma

    #[Test]
    public function the_class_characterisation_can_be_written_and_read_back(): void
    {
        $class = $this->createClass($this->user);

        $this->actingAs($this->user)
            ->put("/classes/{$class->ulid}/characterisation", ['summary' => 'Turma participativa, com dois grupos muito distintos.'])
            ->assertRedirect();

        $characterisation = ClassCharacterisation::withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame('Turma participativa, com dois grupos muito distintos.', $characterisation->summary);
        $this->assertSame($this->user->getKey(), $characterisation->updated_by);
        $this->assertNotNull($characterisation->last_updated_at);
    }

    // ------------------------------------------------ 2. o aluno

    #[Test]
    public function a_students_characterisation_is_written_per_section(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Ana Silva');

        $this->actingAs($this->user)
            ->put("/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation", [
                'strengths' => 'Trabalha bem em grupo.',
                'interests' => 'Música.',
            ])
            ->assertRedirect();

        $characterisation = EnrollmentCharacterisation::withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame('Trabalha bem em grupo.', $characterisation->strengths);
        $this->assertSame('Música.', $characterisation->interests);
        $this->assertNull($characterisation->barriers, 'A section nobody wrote in stays empty.');
    }

    /**
     * The characterisation hangs off the ENROLMENT, which is what scopes it to
     * one class and one academic year without anything having to remember to.
     */
    #[Test]
    public function the_characterisation_belongs_to_the_enrolment_not_the_student(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Ana Silva');

        $this->actingAs($this->user)->put(
            "/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation",
            ['summary' => 'Texto da turma A.'],
        );

        $characterisation = EnrollmentCharacterisation::withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame($enrollment->getKey(), $characterisation->enrollment_id);
    }

    // ------------------------------------------------ 3. histórico

    #[Test]
    public function editing_keeps_the_previous_text_in_the_history(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Ana Silva');
        $url = "/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation";

        $this->actingAs($this->user)->put($url, ['summary' => 'Primeira versão.']);
        $this->actingAs($this->user)->put($url, ['summary' => 'Segunda versão.']);

        $revisions = CharacterisationRevision::withoutGlobalScope('organization')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $revisions);
        $this->assertSame(['summary'], $revisions[0]->changed_sections);
        $this->assertNull($revisions[0]->previous_values['summary'], 'The first write had nothing before it.');
        $this->assertSame('Primeira versão.', $revisions[1]->previous_values['summary']);
        $this->assertSame($this->user->getKey(), $revisions[1]->author_id);
    }

    /**
     * Opening a student, reading, and saving without changing anything is the
     * commonest thing on this screen. It must not bury the edits that mattered.
     */
    #[Test]
    public function a_save_that_changes_nothing_writes_no_revision(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Ana Silva');
        $url = "/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation";

        $this->actingAs($this->user)->put($url, ['summary' => 'Mesma frase.']);
        $this->actingAs($this->user)->put($url, ['summary' => 'Mesma frase.']);

        $this->assertSame(1, CharacterisationRevision::withoutGlobalScope('organization')->count());
    }

    /** History is written once and never rewritten. */
    #[Test]
    public function a_revision_cannot_be_updated(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Ana Silva');

        $this->actingAs($this->user)->put(
            "/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation",
            ['summary' => 'Uma frase.'],
        );

        $revision = CharacterisationRevision::withoutGlobalScope('organization')->firstOrFail();

        $this->expectException(\LogicException::class);

        $revision->update(['previous_values' => ['summary' => 'reescrito']]);
    }

    /**
     * A section the request never mentions is a section the caller has no
     * opinion about — not an instruction to blank it.
     */
    #[Test]
    public function an_absent_section_is_left_alone_rather_than_emptied(): void
    {
        $class = $this->createClass($this->user);
        $enrollment = $this->enrol($this->user, $class, 'Ana Silva');
        $url = "/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation";

        $this->actingAs($this->user)->put($url, ['strengths' => 'Desenha muito bem.']);
        $this->actingAs($this->user)->put($url, ['interests' => 'Banda desenhada.']);

        $characterisation = EnrollmentCharacterisation::withoutGlobalScope('organization')->firstOrFail();

        $this->assertSame('Desenha muito bem.', $characterisation->strengths);
        $this->assertSame('Banda desenhada.', $characterisation->interests);
    }

    // ------------------------------------------------ 4. autorização e isolamento

    #[Test]
    public function a_teacher_who_does_not_teach_the_class_cannot_read_it(): void
    {
        $owner = $this->user;
        $stranger = User::factory()->create();
        $class = $this->createClass($owner);

        $this->actingAs($stranger)
            ->get("/classes/{$class->ulid}/characterisation")
            ->assertNotFound();
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_class_cannot_write_to_it(): void
    {
        $stranger = User::factory()->create();
        $class = $this->createClass($this->user);

        $this->actingAs($stranger)
            ->put("/classes/{$class->ulid}/characterisation", ['summary' => 'Não devia entrar.'])
            ->assertNotFound();

        $this->assertSame(0, ClassCharacterisation::withoutGlobalScope('organization')->count());
    }

    /**
     * An enrolment ULID from ANOTHER class of the same organization is a 404,
     * not a 403: confirming that the enrolment exists somewhere else would say
     * more than the asker is entitled to know (ADR-0002).
     */
    #[Test]
    public function an_enrolment_from_another_class_cannot_be_characterised_through_this_one(): void
    {
        $classA = $this->createClass($this->user, '7.º A');
        $classB = $this->createClass($this->user, '7.º B');
        $enrollmentInB = $this->enrol($this->user, $classB, 'Bruno Costa');

        $this->actingAs($this->user)
            ->put("/classes/{$classA->ulid}/students/{$enrollmentInB->ulid}/characterisation", [
                'summary' => 'Texto na turma errada.',
            ])
            ->assertNotFound();

        $this->assertSame(0, EnrollmentCharacterisation::withoutGlobalScope('organization')->count());
    }

    #[Test]
    public function one_organization_never_sees_another_organizations_characterisation(): void
    {
        $classA = $this->createClass($this->user);
        $enrollmentA = $this->enrol($this->user, $classA, 'Ana Silva');

        $this->actingAs($this->user)->put(
            "/classes/{$classA->ulid}/students/{$enrollmentA->ulid}/characterisation",
            ['summary' => 'Segredo da organização A.'],
        );

        $otherUser = User::factory()->create();
        $otherClass = $this->createClass($otherUser);

        $response = $this->actingAs($otherUser)->get("/classes/{$otherClass->ulid}/characterisation");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('classes/Characterisation')
            // The other organization's class has no students of its own, and
            // organization A's text reaches this page through nothing.
            ->where('students', [])
            ->where('classCharacterisation.summary', null)
        );
    }

    // ------------------------------------------------ 5. o ecrã

    #[Test]
    public function the_page_reports_which_students_have_no_characterisation_yet(): void
    {
        $class = $this->createClass($this->user);
        $written = $this->enrol($this->user, $class, 'Ana Silva');
        $this->enrol($this->user, $class, 'Bruno Costa');

        $this->actingAs($this->user)->put(
            "/classes/{$class->ulid}/students/{$written->ulid}/characterisation",
            ['summary' => 'Já tem texto.'],
        );

        $this->actingAs($this->user)
            ->get("/classes/{$class->ulid}/characterisation")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('classes/Characterisation')
                ->where('students.0.has_characterisation', true)
                ->where('students.1.has_characterisation', false)
                ->where('students.1.last_updated_at', null)
            );
    }

    /**
     * Thirty students, their characterisations, their measures and their
     * history must not become a query per student.
     */
    #[Test]
    public function the_page_does_not_issue_a_query_per_student(): void
    {
        $class = $this->createClass($this->user);

        for ($i = 1; $i <= 25; $i++) {
            $enrollment = $this->enrol($this->user, $class, "Aluno {$i}");

            $this->actingAs($this->user)->put(
                "/classes/{$class->ulid}/students/{$enrollment->ulid}/characterisation",
                ['summary' => "Texto do aluno {$i}."],
            );
        }

        \DB::enableQueryLog();

        $this->actingAs($this->user)->get("/classes/{$class->ulid}/characterisation")->assertOk();

        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(
            30,
            $queries,
            "Rendering 25 students took {$queries} queries — that is an N+1."
        );
    }
}
