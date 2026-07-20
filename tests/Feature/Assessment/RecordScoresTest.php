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
use App\Services\Assessment\RecordScores;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecordScoresTest extends TestCase
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
     * @return array{instrument: Instrument, item: InstrumentItem, enrollment: Enrollment}
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
        // The policy requires the acting user to teach the class — the factory
        // does not create that link, so the HTTP tests need it explicitly.
        $class->teachers()->attach($this->user, ['role' => 'owner']);

        $instrument = Instrument::factory()->recycle($org)->create([
            'class_id' => $class->id,
            'academic_period_id' => $period->id,
            'status' => 'prepared',
        ]);
        $item = InstrumentItem::factory()->recycle($org)->create(['instrument_id' => $instrument->id]);
        $student = Student::factory()->recycle($org)->create();
        $enrollment = Enrollment::factory()->recycle($org)->create([
            'class_id' => $class->id,
            'student_id' => $student->id,
        ]);

        return ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment];
    }

    #[Test]
    public function it_records_an_assessed_score(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scenario();

            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'points_earned' => 7.5,
            ]], $this->user);

            $score = StudentItemScore::firstOrFail();
            $this->assertSame(ResultState::Assessed, $score->result_state);
            $this->assertSame('7.5000', $score->points_earned);
            $this->assertNotNull($score->assessed_at);
        });
    }

    #[Test]
    public function marking_an_absence_stores_no_value_even_if_one_is_sent(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scenario();

            // A stale client could send a leftover number with an absence. The
            // service must drop it rather than let it become a zero-like mark.
            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'absent',
                'points_earned' => 0,
                'state_reason' => 'Faltou.',
            ]], $this->user);

            $score = StudentItemScore::firstOrFail();
            $this->assertSame(ResultState::Absent, $score->result_state);
            $this->assertNull($score->points_earned);
            $this->assertSame('Faltou.', $score->state_reason);
        });
    }

    #[Test]
    public function clearing_a_cell_removes_the_row_rather_than_storing_a_blank(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scenario();
            $service = app(RecordScores::class);

            $service->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'points_earned' => 5,
            ]], $this->user);
            $this->assertSame(1, StudentItemScore::count());

            // Back to empty: the row goes away, because no row IS "por avaliar".
            $service->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'pending',
            ]], $this->user);

            $this->assertSame(0, StudentItemScore::count());
        });
    }

    #[Test]
    public function saving_the_same_cell_twice_updates_rather_than_duplicates(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scenario();
            $service = app(RecordScores::class);

            foreach ([4, 8] as $points) {
                $service->save($instrument, [[
                    'enrollment_id' => $enrollment->id,
                    'instrument_item_id' => $item->id,
                    'result_state' => 'assessed',
                    'points_earned' => $points,
                ]], $this->user);
            }

            $this->assertSame(1, StudentItemScore::count());
            $this->assertSame('8.0000', StudentItemScore::firstOrFail()->points_earned);
        });
    }

    #[Test]
    public function marking_moves_a_prepared_instrument_into_correction(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scenario();
            $this->assertSame('prepared', $instrument->status->value);

            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'points_earned' => 6,
            ]], $this->user);

            $this->assertSame('in_correction', $instrument->refresh()->status->value);
        });
    }

    #[Test]
    public function a_genuine_zero_is_recorded_as_a_mark(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scenario();

            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'points_earned' => 0,
            ]], $this->user);

            // Zero given by the teacher is data, and must survive.
            $score = StudentItemScore::firstOrFail();
            $this->assertSame('0.0000', $score->points_earned);
            $this->assertSame(ResultState::Assessed, $score->result_state);
        });
    }

    #[Test]
    public function the_endpoint_refuses_a_cell_from_another_class(): void
    {
        $context = $this->inTenant(fn () => $this->scenario());
        $intruderEnrollment = $this->inTenant(function () {
            $other = $this->scenario();

            return $other['enrollment']->id;
        });

        // A tampered payload pointing at an enrollment outside this instrument's
        // class must be refused, not silently written.
        $this->actingAs($this->user)
            ->post("/instruments/{$context['instrument']->ulid}/scores", [
                'cells' => [[
                    'enrollment_id' => $intruderEnrollment,
                    'instrument_item_id' => $context['item']->id,
                    'result_state' => 'assessed',
                    'points_earned' => 10,
                ]],
            ])
            ->assertStatus(422);
    }
}
