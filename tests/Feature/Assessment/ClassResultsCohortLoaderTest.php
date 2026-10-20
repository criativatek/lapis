<?php

namespace Tests\Feature\Assessment;

use App\Actions\SubjectParticipation\MarkNotAttendingSubject;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubjectParticipationReason;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\ClassCohort;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `ClassResultsCalculator::forInstrumentCohort()` against `forInstruments()`.
 *
 * The cohort loader reads one instrument for the whole class in a handful of
 * queries; the per-student loader asks again for every student. They must never
 * disagree: both go through the same configuration, the same score inputs and the
 * same engine, and this holds them to the same outcome — every number, every
 * explanation — for every student of a deliberately messy class, under every
 * absence rule.
 */
class ClassResultsCohortLoaderTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();

        $this->seed(SystemScalesSeeder::class);
        $this->seed(InstrumentTypesSeeder::class);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    /**
     * A class that exercises every branch of the inputs: two domains, a question
     * split 60/40, a question without a domain, a bonus and a deduction; students
     * assessed, absent (both kinds), exempt, never marked, who entered after the
     * test, who left before it, and who stopped attending the subject.
     *
     * @return array{0: SchoolClass, 1: Instrument}
     */
    private function messyClass(): array
    {
        $year = AcademicYear::create([
            'label' => '2026/2027', 'starts_on' => '2026-09-14', 'ends_on' => '2027-06-30',
            'status' => 'active', 'country_code' => 'PT',
        ]);
        $period = $year->periods()->create([
            'label' => '1.º Período', 'kind' => 'term', 'sequence' => 1,
            'starts_on' => '2026-09-14', 'ends_on' => '2026-12-19', 'status' => 'open',
        ]);
        $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Matemática']);

        $profile = app(ProfileBuilder::class)->create(
            ['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'name' => 'Matemática', 'description' => 'Cenário.'],
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
            [['name' => 'Números e Operações', 'weight' => 60], ['name' => 'Geometria', 'weight' => 40]],
            ['8.º'],
        );
        $version = app(ActivateProfileVersion::class)->activate($profile->draftVersion(), $this->teacher);

        $class = SchoolClass::create([
            'academic_year_id' => $year->id, 'subject_id' => $subject->id,
            'label' => '8.º A', 'grade_level' => '8.º', 'status' => 'active',
            'assessment_profile_version_id' => $version->id,
        ]);
        $class->teachers()->attach($this->teacher, ['role' => 'owner']);

        $service = app(StudentEnrollmentService::class);
        foreach (range(1, 8) as $n) {
            $service->enrollNew($class, ['name' => "Aluno {$n}", 'class_number' => $n, 'enrolled_on' => '2026-09-14']);
        }
        // #9 enters after the test; #10 left before it.
        $service->enrollNew($class, ['name' => 'Aluno 9', 'class_number' => 9, 'enrolled_on' => '2026-12-01']);
        $service->enrollNew($class, ['name' => 'Aluno 10', 'class_number' => 10, 'enrolled_on' => '2026-09-14']);
        Enrollment::where('class_id', $class->id)->where('class_number', 10)->update(['left_on' => '2026-10-01']);

        $numeros = Domain::where('name', 'Números e Operações')->firstOrFail();
        $geometria = Domain::where('name', 'Geometria')->firstOrFail();

        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste', 'applied_on' => '2026-10-15', 'status' => 'in_correction',
            'counts_toward_classification' => true, 'purpose' => 'summative',
            'total_points' => 40, 'allow_bonus' => true,
        ], [
            ['code' => 'Q1', 'label' => 'Q1', 'points_possible' => 10, 'domains' => [['domain_id' => $numeros->id, 'allocation_percent' => 100]]],
            ['code' => 'Q2', 'label' => 'Q2', 'points_possible' => 10, 'domains' => [['domain_id' => $geometria->id, 'allocation_percent' => 100]]],
            ['code' => 'Q3', 'label' => 'Q3', 'points_possible' => 10, 'domains' => [
                ['domain_id' => $numeros->id, 'allocation_percent' => 60], ['domain_id' => $geometria->id, 'allocation_percent' => 40],
            ]],
            ['code' => 'Q4', 'label' => 'Q4', 'points_possible' => 5],
            ['code' => 'B1', 'label' => 'Bónus', 'points_possible' => 2, 'is_bonus' => true, 'domains' => [['domain_id' => $numeros->id, 'allocation_percent' => 100]]],
            ['code' => 'D1', 'label' => 'Dedução', 'points_possible' => 0, 'is_bonus' => true, 'domains' => [['domain_id' => $geometria->id, 'allocation_percent' => 100]]],
        ]);

        $enrollments = $class->enrollments()->get()->keyBy('class_number');
        $items = $instrument->items()->orderBy('sequence')->get()->keyBy('code');
        $cells = [];
        $mark = function (int $number, string $code, ResultState $state, ?float $points = null) use (&$cells, $enrollments, $items): void {
            $cells[] = [
                'enrollment_id' => $enrollments[$number]->id,
                'instrument_item_id' => $items[$code]->id,
                'result_state' => $state->value,
                ...($points === null ? [] : ['points_earned' => $points]),
            ];
        };

        // #1 everything assessed, with the bonus and the deduction.
        foreach (['Q1' => 8.5, 'Q2' => 7.25, 'Q3' => 9.0, 'Q4' => 3.0, 'B1' => 1.5, 'D1' => -0.5] as $code => $points) {
            $mark(1, $code, ResultState::Assessed, $points);
        }
        // #2 partial: some assessed, some pending.
        $mark(2, 'Q1', ResultState::Assessed, 4.0);
        $mark(2, 'Q3', ResultState::Assessed, 5.5);
        // #3 absent, #4 absent justified, #5 exempt, #6 under review on one question, #7 never marked.
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $code) {
            $mark(3, $code, ResultState::Absent);
            $mark(4, $code, ResultState::AbsentJustified);
            $mark(5, $code, ResultState::Exempt);
        }
        $mark(6, 'Q1', ResultState::Assessed, 10.0);
        $mark(6, 'Q2', ResultState::UnderReview);
        // #8 stopped attending the subject (window below) but has marks.
        $mark(8, 'Q1', ResultState::Assessed, 6.0);
        $mark(8, 'Q2', ResultState::Assessed, 6.0);
        // #9 and #10 do not apply to the test, yet carry marks the engine must ignore.
        $mark(9, 'Q1', ResultState::Assessed, 10.0);
        $mark(10, 'Q1', ResultState::Assessed, 10.0);

        app(RecordScores::class)->save($instrument, $cells, $this->teacher);

        // Opens BEFORE the test date: #8 is not attending the subject that day.
        app(MarkNotAttendingSubject::class)->execute(
            $enrollments[8],
            SubjectParticipationReason::AlternativeSubject,
            '2026-10-01',
            reasonDetail: 'PLNM',
        );

        return [$class, $instrument];
    }

    #[Test]
    public function the_cohort_loader_gives_every_student_the_outcome_the_per_student_loader_gives(): void
    {
        $this->asTenant(function (): void {
            [$class, $instrument] = $this->messyClass();
            $version = $class->profileVersion;

            foreach (['exclude_all', 'zero_all', 'zero_unjustified_only', 'exclude_all_warn'] as $mode) {
                // A frozen version refuses to be edited through the model, and rightly:
                // this test changes the rule underneath it on purpose.
                DB::table('assessment_profile_versions')->where('id', $version->id)->update(['absence_mode' => $mode]);

                $freshClass = SchoolClass::findOrFail($class->id);
                $freshInstrument = Instrument::findOrFail($instrument->id);
                $calculator = app(ClassResultsCalculator::class);
                $enrollments = ClassCohort::enrollmentsFor($freshClass);

                $cohort = $calculator->forInstrumentCohort($freshClass, $freshInstrument, $enrollments);

                $this->assertCount($enrollments->count(), $cohort, $mode);

                foreach ($enrollments as $enrollmentId => $enrollment) {
                    $perStudent = $calculator->forInstruments($freshClass, $enrollment, collect([$freshInstrument]));

                    $this->assertEquals(
                        $perStudent[$instrument->id],
                        $cohort[$enrollmentId],
                        "absence_mode {$mode}, student {$enrollment->class_number}: the two loaders disagree",
                    );
                }
            }
        });
    }

    #[Test]
    public function without_a_profile_or_without_students_there_is_nothing_to_load(): void
    {
        $this->asTenant(function (): void {
            [$class, $instrument] = $this->messyClass();
            $calculator = app(ClassResultsCalculator::class);

            $this->assertSame([], $calculator->forInstrumentCohort($class, $instrument, collect()));

            $class->forceFill(['assessment_profile_version_id' => null])->save();
            $withoutProfile = SchoolClass::findOrFail($class->id);

            $this->assertSame([], $calculator->forInstrumentCohort($withoutProfile, $instrument, ClassCohort::enrollmentsFor($withoutProfile)));
        });
    }
}
