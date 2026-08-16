<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RosterFixture;
use Tests\TestCase;

/**
 * What the Relação de Turma (EB058e) leaves behind: the student's N.º de
 * processo and their date of birth.
 *
 * Both are the school's own data about a person, so both live on the identity —
 * which is per (student, organization), encrypted at rest. A student in two
 * schools has two process numbers and neither is «the» one.
 *
 * The rule that matters on a RE-IMPORT is that the file fills in and never
 * erases: a cell the export left empty says nothing about the student, and
 * least of all that what a teacher typed by hand should go.
 */
class RosterProcessNumberTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    private function createClass(): SchoolClass
    {
        $context = app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), fn () => [
            'year' => AcademicYear::factory()->recycle($this->user->personalOrganization())
                ->create(['starts_on' => '2026-09-14', 'ends_on' => '2027-06-30'])->id,
            'subject' => Subject::factory()->recycle($this->user->personalOrganization())->create()->id,
        ]);

        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')->firstOrFail();
    }

    /**
     * Uploads a roster and confirms it, exactly as the teacher does.
     *
     * @param  array<string, string|null>  $overrides
     * @return list<array<string, mixed>> the preview rows, as the page received them
     */
    private function importRoster(SchoolClass $class, array $overrides = []): array
    {
        $file = UploadedFile::fake()->createWithContent(
            'relacao-de-turma.xlsx',
            (string) file_get_contents((new RosterFixture)->buildDetailed($overrides)),
        );

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", ['roster' => $file]);
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        /** @var list<array<string, mixed>> $rows */
        $rows = $page['props']['rows'];

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/roster-imports/{$page['props']['token']}/confirm", ['rows' => $rows])
            ->assertRedirect();

        return $rows;
    }

    /**
     * @return array<string, StudentIdentity>
     */
    private function identitiesByName(): array
    {
        $byName = [];

        foreach (StudentIdentity::all() as $identity) {
            $byName[$identity->display_name] = $identity;
        }

        return $byName;
    }

    // ------------------------------------------------- 1. o que fica gravado

    #[Test]
    public function the_process_number_and_the_birth_date_are_kept_from_the_file(): void
    {
        $class = $this->createClass();
        $this->importRoster($class);

        $identities = $this->identitiesByName();

        // Written as a number in the file, kept as the string it is: a process
        // number is an identifier, not a quantity to do arithmetic on.
        $this->assertSame('8019', $identities['Adélia Conceição Ramos']->school_number);
        $this->assertSame('2012-03-08', $identities['Adélia Conceição Ramos']->birth_date->toDateString());

        // Written as text with a leading zero, and the zero survives.
        $this->assertSame('00742', $identities['Nuno Bragança']->school_number);
    }

    #[Test]
    public function a_student_with_no_birth_date_in_the_file_simply_has_none(): void
    {
        $class = $this->createClass();
        $this->importRoster($class);

        // Never a default date, and never today's: an absent cell is an absent
        // fact.
        $this->assertNull($this->identitiesByName()['Nuno Bragança']->birth_date);
        $this->assertNull($this->identitiesByName()['Sara Vilhena']->school_number);
    }

    #[Test]
    public function the_domain_answers_what_a_students_process_number_is_here(): void
    {
        $class = $this->createClass();
        $this->importRoster($class);

        app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function (): void {
            $student = Student::with('identity')->get()
                ->first(fn (Student $candidate): bool => $candidate->identity?->display_name === 'Adélia Conceição Ramos');

            // «Qual é o N.º de processo deste aluno nesta organização?», asked of
            // the domain and answered from one place — which is where a later
            // export will ask it (§14).
            $this->assertSame('8019', $student->processNumber());
        });
    }

    #[Test]
    public function the_process_number_belongs_to_the_identity_and_not_to_the_enrolment(): void
    {
        $class = $this->createClass();
        $this->importRoster($class);

        // It is the school's permanent identifier for the student, not a fact
        // about one year's enrolment (§7).
        $this->assertArrayNotHasKey('school_number', Enrollment::first()->getAttributes());
        $this->assertArrayNotHasKey('process_number', Enrollment::first()->getAttributes());

        $identity = $this->identitiesByName()['Adélia Conceição Ramos'];
        $this->assertSame($this->user->personalOrganization()->getKey(), $identity->organization_id);
    }

    // ------------------------------------------------------ 2. reimportação

    #[Test]
    public function importing_the_same_class_again_enrolls_nobody_twice(): void
    {
        $class = $this->createClass();
        $this->importRoster($class);
        $this->importRoster($class);

        $this->assertSame(3, Student::withoutGlobalScope('organization')->count());
        $this->assertSame(3, Enrollment::withoutGlobalScope('organization')->count());
    }

    #[Test]
    public function a_later_export_fills_in_what_the_first_one_was_missing(): void
    {
        $class = $this->createClass();
        $this->importRoster($class);

        $this->assertNull($this->identitiesByName()['Sara Vilhena']->school_number);

        // The school corrects its own records and exports again.
        $this->importRoster($class, ['process_number' => '5540', 'birth_date' => '2011-09-30']);

        $identity = $this->identitiesByName()['Sara Vilhena'];
        $this->assertSame('5540', $identity->school_number);
        $this->assertSame('2011-09-30', $identity->birth_date->toDateString());
    }

    #[Test]
    public function an_empty_cell_never_erases_what_is_already_recorded(): void
    {
        $class = $this->createClass();
        $this->importRoster($class, ['process_number' => '5540', 'birth_date' => '2011-09-30']);

        // The next export leaves those cells empty — which says nothing about
        // the student, and certainly not that the record should be emptied (§8).
        $this->importRoster($class);

        $identity = $this->identitiesByName()['Sara Vilhena'];
        $this->assertSame('5540', $identity->school_number);
        $this->assertSame('2011-09-30', $identity->birth_date->toDateString());
    }

    #[Test]
    public function a_re_import_is_offered_as_an_update_rather_than_a_second_enrolment(): void
    {
        $class = $this->createClass();
        $this->importRoster($class);

        $file = UploadedFile::fake()->createWithContent(
            'relacao-de-turma.xlsx',
            (string) file_get_contents((new RosterFixture)->buildDetailed()),
        );

        $response = $this->actingAs($this->user)->post("/classes/{$class->ulid}/roster-imports", ['roster' => $file]);

        $response->assertInertia(fn ($page) => $page
            ->component('roster-imports/Preview')
            ->where('rows.0.already_enrolled', true)
            ->where('rows.0.action', 'update')
            ->where('rows.0.include', true)
            ->where('rows.0.enrollment_id', fn ($id) => $id !== null),
        );
    }

    #[Test]
    public function an_enrolment_from_another_class_cannot_be_updated_through_this_one(): void
    {
        $class = $this->createClass();
        $this->importRoster($class);

        $this->actingAs($this->user)->post('/classes', [
            'label' => '7.º B',
            'academic_year_id' => $class->academic_year_id,
            'subject_id' => $class->subject_id,
        ]);
        $other = SchoolClass::withoutGlobalScope('organization')->where('label', '7.º B')->firstOrFail();

        $stranger = Enrollment::withoutGlobalScope('organization')->where('class_id', $class->id)->firstOrFail();

        // A real upload into the other class, for a real token…
        $upload = $this->actingAs($this->user)->post("/classes/{$other->ulid}/roster-imports", [
            'roster' => UploadedFile::fake()->createWithContent(
                'relacao-de-turma.xlsx',
                (string) file_get_contents((new RosterFixture)->buildDetailed()),
            ),
        ]);

        /** @var array<string, mixed> $page */
        $page = $upload->viewData('page');
        $rows = $page['props']['rows'];

        // …and then a forged enrolment id belonging to the FIRST class. It finds
        // nothing when it is re-resolved through this one, so the row is
        // enrolled as new instead of writing into somebody else's record.
        $rows[0]['enrollment_id'] = $stranger->id;
        $rows[0]['process_number'] = '9999';

        $this->actingAs($this->user)
            ->post("/classes/{$other->ulid}/roster-imports/{$page['props']['token']}/confirm", ['rows' => $rows])
            ->assertRedirect();

        // The first class's student was never touched…
        $this->assertSame('8019', StudentIdentity::find($stranger->student->identity->id)->school_number);
        // …and the second class got its own enrolments.
        $this->assertSame(3, Enrollment::withoutGlobalScope('organization')->where('class_id', $other->id)->count());
    }
}
