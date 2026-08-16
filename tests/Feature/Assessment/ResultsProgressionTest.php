<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading a class along its periods.
 *
 * The arithmetic under test is one subtraction, and it is the one the whole
 * feature turns on: progress is the difference between the STANDALONE weighted
 * average of this period and the STANDALONE one of the period before. A student
 * who went 55% then 75% improved by twenty points; the accumulated figure of
 * 65% is a true and different fact, and comparing it against the 55% would
 * report ten — a number that answers no question anybody asked.
 *
 * Everything else here is assembly: the averages come from the calculator that
 * already owns the rule, and this service is not allowed to have an opinion
 * about them.
 */
class ResultsProgressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected SchoolClass $class;

    /** @var array<int, AcademicPeriod> */
    protected array $periods = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
    }

    /**
     * A class with as many periods as asked for — never a fixed two or three.
     */
    protected function makeClass(int $periodCount): void
    {
        app(CurrentOrganization::class)->runFor($this->organization, function () use ($periodCount): void {
            $year = AcademicYear::factory()->recycle($this->organization)->create();

            for ($sequence = 1; $sequence <= $periodCount; $sequence++) {
                $this->periods[] = AcademicPeriod::factory()->recycle($this->organization)->for($year)->create([
                    'label' => $sequence.'.º Período',
                    'sequence' => $sequence,
                ]);
            }

            $this->class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $year->id,
                'subject_id' => Subject::factory()->recycle($this->organization)->create()->id,
            ]);
            $this->class->teachers()->attach($this->teacher, ['role' => 'owner']);
        });
    }

    protected function enrol(string $name, int $number): Enrollment
    {
        return app(CurrentOrganization::class)->runFor($this->organization, function () use ($name, $number): Enrollment {
            $student = Student::factory()->recycle($this->organization)->create();

            StudentIdentity::create([
                'student_id' => $student->getKey(),
                'organization_id' => $this->organization->getKey(),
                'display_name' => $name,
            ]);

            return Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $this->class->id,
                'student_id' => $student->getKey(),
                'class_number' => $number,
                'enrolled_on' => now()->subMonths(9)->toDateString(),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function progression(): array
    {
        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): array => app(BuildResultsProgression::class)->for($this->class->fresh()),
        );
    }

    // -------------------------------------------------- 1. shape and dynamism

    #[Test]
    public function the_periods_and_domains_come_from_the_configuration_and_not_from_a_constant(): void
    {
        $this->makeClass(3);
        $this->enrol('Ana Exemplo', 1);

        $progression = $this->progression();

        // Three because three were configured. Nothing here knows about
        // semesters, or about a Português profile (§1, §14).
        $this->assertCount(3, $progression['periods']);
        $this->assertSame(['1.º Período', '2.º Período', '3.º Período'], array_column($progression['periods'], 'label'));

        // One row per student, one entry per period inside it.
        $this->assertCount(1, $progression['students']);
        $this->assertCount(3, $progression['students'][0]['periods']);
        $this->assertSame('Ana Exemplo', $progression['students'][0]['name']);
    }

    #[Test]
    public function a_class_with_two_periods_produces_two_and_not_three(): void
    {
        $this->makeClass(2);
        $this->enrol('Bruno Teste', 1);

        $this->assertCount(2, $this->progression()['periods']);
    }

    // ------------------------------------------------------- 2. the subtraction

    #[Test]
    public function progress_is_measured_between_standalone_periods_and_never_between_accumulated_ones(): void
    {
        $service = app(BuildResultsProgression::class);
        $evolution = $this->callEvolution($service, '55', '75');

        // 55 → 75 is twenty points. The accumulated 65 is a different and also
        // true number, and comparing it against 55 would report ten (§9).
        $this->assertSame('up', $evolution['direction']);
        $this->assertSame('20.0', $evolution['points']);
        $this->assertSame('55.0', $evolution['previous']);
        $this->assertSame('75.0', $evolution['current']);
    }

    #[Test]
    public function going_backwards_is_reported_as_going_backwards(): void
    {
        $evolution = $this->callEvolution(app(BuildResultsProgression::class), '75', '55');

        $this->assertSame('down', $evolution['direction']);
        $this->assertSame('-20.0', $evolution['points']);
    }

    #[Test]
    public function standing_still_is_neither_progress_nor_regression(): void
    {
        $service = app(BuildResultsProgression::class);

        $this->assertSame('flat', $this->callEvolution($service, '62', '62')['direction']);

        // And movement nobody can see at the shown precision is standing still:
        // comparing the raw six-decimal figures would fill the grid with arrows
        // that mean nothing (§13).
        $this->assertSame('flat', $this->callEvolution($service, '62.001', '62.004')['direction']);
    }

    #[Test]
    public function a_period_with_nothing_to_compare_against_gets_no_arrow_at_all(): void
    {
        $service = app(BuildResultsProgression::class);

        // A period with no evidence is not a zero, and reading its absence as a
        // fall would be inventing a result out of having none (§21).
        $this->assertNull($this->callEvolution($service, null, '70'));
        $this->assertNull($this->callEvolution($service, '70', null));
        $this->assertNull($this->callEvolution($service, null, null));
    }

    #[Test]
    public function the_first_period_never_claims_an_evolution(): void
    {
        $this->makeClass(2);
        $this->enrol('Carla Fictícia', 1);

        $periods = $this->progression()['students'][0]['periods'];

        // There is nothing before the first period to have improved on.
        $this->assertNull($periods[0]['evolution']);
    }

    // ----------------------------------------------- 3. what each period carries

    #[Test]
    public function every_period_reports_the_standalone_and_the_accumulated_average_separately(): void
    {
        $this->makeClass(2);
        $this->enrol('Diogo Inventado', 1);

        foreach ($this->progression()['students'][0]['periods'] as $period) {
            // Two distinct facts, never one: «Média Ponderada» is this period's
            // own evidence, «Média Ponderada Acumulada» is whatever counts up to
            // here by the profile's own rule (§22).
            $this->assertArrayHasKey('weighted_average', $period);
            $this->assertArrayHasKey('accumulated_average', $period);
            $this->assertArrayHasKey('domains', $period);
            $this->assertArrayHasKey('self_assessment', $period);
            $this->assertArrayHasKey('classification', $period);
        }
    }

    #[Test]
    public function a_student_with_no_evidence_has_no_average_rather_than_a_zero(): void
    {
        $this->makeClass(2);
        $this->enrol('Elsa Suposta', 1);

        $periods = $this->progression()['students'][0]['periods'];

        // «—», never 0. An empty period is a lack of data, not a lack of merit.
        $this->assertNull($periods[0]['weighted_average']);
        $this->assertNull($periods[0]['accumulated_average']);
    }

    // -------------------------------------------- 4. self-assessment and levels

    #[Test]
    public function a_student_who_answered_nothing_has_no_self_assessment_and_no_level(): void
    {
        $this->makeClass(2);
        $this->enrol('Filipe Imaginário', 1);

        $period = $this->progression()['students'][0]['periods'][0];

        // Null, which the screen shows as «—». Never a zero, and never derived
        // from anything: an overall judgement the student did not make is one
        // nobody may make for them (§11 of the self-assessment decision).
        $this->assertNull($period['self_assessment']);
        $this->assertNull($period['classification']);
    }

    #[Test]
    public function the_service_never_fills_a_level_in_from_the_proposal(): void
    {
        // The one thing this read model must never do. Proposals and decisions
        // are separate columns of the same row, and only an explicit act by the
        // teacher writes the second (§3.3, §6).
        $source = (string) file_get_contents(app_path('Services/Assessment/BuildResultsProgression.php'));

        $this->assertStringNotContainsString('final_scale_level_id =', $source);
        $this->assertStringNotContainsString('->update(', $source);
        $this->assertStringNotContainsString('->save(', $source);
        $this->assertStringContainsString("'proposed' => \$level(\$classification->proposedScaleLevel)", $source);
        $this->assertStringContainsString("'final' => \$level(\$classification->finalScaleLevel)", $source);
    }

    #[Test]
    public function the_global_self_assessment_is_the_question_belonging_to_no_domain(): void
    {
        // Identified structurally and never by its wording, and never computed
        // from the per-domain answers (§2, §11 of the decision).
        $source = (string) file_get_contents(app_path('Services/Assessment/BuildResultsProgression.php'));

        // By its stated ROLE — not by its wording, which somebody will rephrase
        // for a younger class, and not by its position, which changes the moment
        // a question is inserted and would silently reassign what every stored
        // answer meant.
        $this->assertStringContainsString('$question?->role !== SelfAssessmentQuestionRole::Global', $source);
        $this->assertStringNotContainsString('sequence ===', $source, 'nem pela posição');
        $this->assertStringContainsString('$question->domain_id !== $domainId', $source);
        $this->assertStringNotContainsString('prompt', $source, 'a pergunta nunca é encontrada pelo texto');
        $this->assertStringNotContainsString('avg(', $source);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>|null
     */
    protected function callEvolution(BuildResultsProgression $service, ?string $previous, ?string $current): ?array
    {
        $method = new \ReflectionMethod($service, 'evolution');

        /** @var array<string, mixed>|null $result */
        $result = $method->invoke($service, $previous, $current);

        return $result;
    }
}
