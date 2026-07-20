<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\Organization;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Support\Assessment\EmptyIsNotZeroException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rule the whole assessment model is built to protect (§12.4, §13.3):
 * a missing value is not a zero. A zero is a mark the teacher gave; an empty
 * cell means there is no data. Confusing them changes a student's grade.
 */
class EmptyIsNotZeroTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->user->personalOrganization();
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    /**
     * @return array{item: InstrumentItem, enrollment: Enrollment, instrument: Instrument}
     */
    protected function scenario(): array
    {
        $org = $this->organization;

        $year = AcademicYear::factory()->recycle($org)->create();
        $period = AcademicPeriod::factory()->recycle($org)->for($year)->create();
        $subject = Subject::factory()->recycle($org)->create();
        $class = SchoolClass::factory()->recycle($org)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ]);
        $instrument = Instrument::factory()->recycle($org)->create([
            'class_id' => $class->id,
            'academic_period_id' => $period->id,
        ]);
        $item = InstrumentItem::factory()->recycle($org)->create(['instrument_id' => $instrument->id]);

        $student = Student::factory()->recycle($org)->create();
        $enrollment = Enrollment::factory()->recycle($org)->create([
            'class_id' => $class->id,
            'student_id' => $student->id,
        ]);

        return ['item' => $item, 'enrollment' => $enrollment, 'instrument' => $instrument];
    }

    /**
     * @param  array{item: InstrumentItem, enrollment: Enrollment, instrument: Instrument}  $scenario
     * @param  array<string, mixed>  $attributes
     */
    protected function score(array $scenario, array $attributes): StudentItemScore
    {
        return StudentItemScore::create([
            'instrument_id' => $scenario['instrument']->id,
            'instrument_item_id' => $scenario['item']->id,
            'enrollment_id' => $scenario['enrollment']->id,
            ...$attributes,
        ]);
    }

    #[Test]
    public function an_absent_student_cannot_be_given_a_zero(): void
    {
        $this->inTenant(function (): void {
            $scenario = $this->scenario();

            // This is the bug the whole design exists to prevent: silently turning
            // "was not there" into "scored nothing".
            $this->expectException(EmptyIsNotZeroException::class);

            $this->score($scenario, [
                'result_state' => ResultState::Absent,
                'points_earned' => 0,
            ]);
        });
    }

    #[Test]
    public function a_pending_score_cannot_carry_a_value(): void
    {
        $this->inTenant(function (): void {
            $scenario = $this->scenario();

            $this->expectException(EmptyIsNotZeroException::class);

            $this->score($scenario, ['result_state' => ResultState::Pending, 'points_earned' => 7.5]);
        });
    }

    #[Test]
    public function a_not_applicable_score_cannot_carry_a_value(): void
    {
        $this->inTenant(function (): void {
            $scenario = $this->scenario();

            $this->expectException(EmptyIsNotZeroException::class);

            $this->score($scenario, ['result_state' => ResultState::NotApplicable, 'points_earned' => 0]);
        });
    }

    #[Test]
    public function an_assessed_score_must_actually_carry_a_value(): void
    {
        $this->inTenant(function (): void {
            $scenario = $this->scenario();

            // "No value yet" is `pending`, not `assessed`.
            $this->expectException(EmptyIsNotZeroException::class);

            $this->score($scenario, ['result_state' => ResultState::Assessed, 'points_earned' => null]);
        });
    }

    #[Test]
    public function an_assessed_score_of_genuinely_zero_is_allowed(): void
    {
        $this->inTenant(function (): void {
            $scenario = $this->scenario();

            // A real zero — the teacher marked it and the student scored nothing.
            // This must remain possible; it is a mark, not missing data.
            $score = $this->score($scenario, ['result_state' => ResultState::Assessed, 'points_earned' => 0]);

            $this->assertSame(ResultState::Assessed, $score->result_state);
            $this->assertSame('0.0000', $score->points_earned);
        });
    }

    #[Test]
    public function states_without_a_value_are_stored_with_null_not_zero(): void
    {
        $this->inTenant(function (): void {
            $scenario = $this->scenario();

            $score = $this->score($scenario, [
                'result_state' => ResultState::Absent,
                'state_reason' => 'Faltou ao teste.',
            ]);

            $this->assertNull($score->points_earned);
        });
    }

    #[Test]
    public function the_absence_of_a_row_means_pending(): void
    {
        $this->inTenant(function (): void {
            $scenario = $this->scenario();

            // The grid does not pre-create empty cells; no row is a legitimate
            // "por avaliar" and must not be read as a zero.
            $this->assertSame(0, StudentItemScore::where('instrument_item_id', $scenario['item']->id)->count());
        });
    }

    #[Test]
    public function only_assessed_carries_a_value_according_to_the_enum(): void
    {
        foreach (ResultState::cases() as $state) {
            $this->assertSame(
                $state === ResultState::Assessed,
                $state->carriesValue(),
                "State {$state->value} disagrees about carrying a value.",
            );
        }
    }

    #[Test]
    public function absences_defer_to_the_profile_rule_rather_than_assuming(): void
    {
        // §13.3: "ausências seguem regra configurável e não assumida". The enum
        // must not decide for them — it returns null, and the engine asks the
        // profile version's absence_mode.
        $this->assertNull(ResultState::Absent->entersDenominator());
        $this->assertNull(ResultState::AbsentJustified->entersDenominator());

        // These are decided, and never depend on the profile.
        $this->assertTrue(ResultState::Assessed->entersDenominator());
        $this->assertFalse(ResultState::NotApplicable->entersDenominator());
        $this->assertFalse(ResultState::Pending->entersDenominator());
    }
}
