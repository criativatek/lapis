<?php

namespace Tests\Feature\Export;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * INOVAR is an export, not a condition of use.
 *
 * A N.º de processo comes from a Relação de Turma, and plenty of classes never
 * see one: a teacher who typed their students in by hand has none, and nothing
 * about teaching them changes for it. The number is needed the day somebody
 * chooses to export to INOVAR, and not one moment before.
 *
 * These hold that line. Every one of them runs against students with no process
 * number at all — which is what the demo class has, and what a hand-typed class
 * always has to begin with.
 */
class InovarIsOptionalTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function firstPeriodUlid(): string
    {
        return $this->asTenant(fn (): string => $this->schoolClass()
            ->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid);
    }

    private function open(string $url): TestResponse
    {
        return $this->actingAs($this->teacher)->get($url);
    }

    // ------------------------------------- 1. o ponto de partida desta prova

    #[Test]
    public function the_class_under_test_has_no_process_number_anywhere(): void
    {
        $this->asTenant(function (): void {
            $students = Student::with('identity')->get();

            $this->assertGreaterThan(0, $students->count());

            // Every assertion below is therefore about students INOVAR could not
            // be exported for — which is the whole point.
            foreach ($students as $student) {
                $this->assertNull($student->processNumber());
            }
        });
    }

    // ------------------------------- 2. inscrever à mão continua a funcionar

    #[Test]
    public function a_student_typed_in_by_hand_needs_no_process_number(): void
    {
        $class = $this->schoolClass();

        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/students", ['name' => 'Aluno Escrito à Mão'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->asTenant(function (): void {
            $student = Student::with('identity')->get()
                ->first(fn (Student $candidate): bool => $candidate->identity?->display_name === 'Aluno Escrito à Mão');

            // Enrolled, named, and with no school number — a complete, valid
            // student record.
            $this->assertNotNull($student);
            $this->assertNull($student->processNumber());
            $this->assertSame(1, Enrollment::where('student_id', $student->id)->count());
        });
    }

    #[Test]
    public function the_class_page_and_its_roll_work_without_one(): void
    {
        $class = $this->schoolClass();

        $this->open("/classes/{$class->ulid}")->assertOk();
    }

    // ------------------------------- 3. avaliar e ler resultados sem o número

    #[Test]
    public function instruments_results_and_the_quadro_sintese_all_work_without_one(): void
    {
        $class = $this->schoolClass();
        $period = $this->firstPeriodUlid();

        // The engine's own output, read through the model every results screen
        // reads: it knows nothing about school numbers.
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($class));

        $this->assertNotEmpty($progression['students']);
        $this->assertNotEmpty($progression['domains']);
        $this->assertNotNull($progression['students'][0]['periods'][0]['weighted_average']);

        // An instrument of this class opens and can be graded, with no student
        // on it carrying a school number.
        $instrument = $this->asTenant(fn (): ?string => $class->instruments()->orderBy('id')->first()?->ulid);
        $this->assertNotNull($instrument);
        $this->open("/instruments/{$instrument}")->assertOk();

        $this->open("/classes/{$class->ulid}/results/{$period}")->assertOk();
        $this->open("/classes/{$class->ulid}/results/quadro-sintese")->assertOk();
    }

    #[Test]
    public function the_qualitative_mention_is_produced_without_one(): void
    {
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->schoolClass()));
        $domains = $progression['students'][0]['periods'][0]['domains'];

        // The mention comes from the scale and the accumulated figure. A
        // process number is not one of its inputs.
        $this->assertNotEmpty(array_filter($domains, fn (array $domain): bool => $domain['mention'] !== null));
    }

    // ------------------------------------ 4. classificar e autoavaliar também

    #[Test]
    public function proposing_and_deciding_a_classification_works_without_one(): void
    {
        $class = $this->schoolClass();

        $this->asTenant(function () use ($class): void {
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($class, $period);

            $classification = Classification::where('academic_period_id', $period->id)
                ->where('scope', ClassificationScope::Period)->firstOrFail();

            app(ConfirmClassification::class)->confirm($classification, $this->teacher);

            $this->assertNotNull($classification->fresh()->final_scale_level_id);
        });

        $this->open("/classes/{$class->ulid}/classifications/{$this->firstPeriodUlid()}")->assertOk();
    }

    #[Test]
    public function the_self_assessment_works_without_one(): void
    {
        $class = $this->schoolClass();
        $period = $this->firstPeriodUlid();

        $enrollment = $this->asTenant(fn (): string => $class->enrollments()->orderBy('class_number')->firstOrFail()->ulid);

        $this->open("/classes/{$class->ulid}/self-assessments/{$period}/{$enrollment}")->assertOk();
    }

    // --------------------------------- 5. e o número nunca é obrigatório

    #[Test]
    public function the_school_number_is_nullable_and_nothing_requires_it(): void
    {
        // Nullable in the schema, absent from every validation rule outside the
        // roster import that happens to carry it, and read by nothing that
        // teaches. Adding one later is an update, never a recreation.
        $this->asTenant(function (): void {
            $student = Student::with('identity')->firstOrFail();
            $identity = $student->identity;

            $this->assertNull($identity->school_number);

            $identity->update(['school_number' => '4821']);

            // The same student, the same enrolments, the same everything —
            // filled in afterwards without recreating a thing.
            $this->assertSame('4821', $student->fresh()->processNumber());
            $this->assertSame($student->pseudonym_code, $student->fresh()->pseudonym_code);
        });
    }
}
