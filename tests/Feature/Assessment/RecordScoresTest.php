<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
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
use App\Support\Assessment\ScoreExceedsMaximumException;
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
                'lock_version' => 0,
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
                'lock_version' => 0,
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
                'lock_version' => 0,
                'points_earned' => 5,
            ]], $this->user);
            $this->assertSame(1, StudentItemScore::count());

            // Back to empty: the row goes away, because no row IS "por avaliar".
            $service->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'pending',
                'lock_version' => 1,
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

            $version = 0;
            foreach ([4, 8] as $points) {
                $result = $service->save($instrument, [[
                    'enrollment_id' => $enrollment->id,
                    'instrument_item_id' => $item->id,
                    'result_state' => 'assessed',
                    'lock_version' => $version,
                    'points_earned' => $points,
                ]], $this->user);
                $version = $result->versions[0]['lock_version'];
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
                'lock_version' => 0,
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
                'lock_version' => 0,
                'points_earned' => 0,
            ]], $this->user);

            // Zero given by the teacher is data, and must survive.
            $score = StudentItemScore::firstOrFail();
            $this->assertSame('0.0000', $score->points_earned);
            $this->assertSame(ResultState::Assessed, $score->result_state);
        });
    }

    #[Test]
    public function a_score_above_the_items_points_possible_is_rejected(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scenario();
            $this->assertSame('10.0000', $item->points_possible, 'InstrumentItemFactory default — sanity check the test is exercising the right ceiling.');

            $this->expectException(ScoreExceedsMaximumException::class);

            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'lock_version' => 0,
                'points_earned' => 15,
            ]], $this->user);

            $this->assertSame(0, StudentItemScore::count(), 'Nothing should have been written.');
        });
    }

    #[Test]
    public function a_score_exactly_at_the_items_points_possible_is_allowed(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scenario();

            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'lock_version' => 0,
                'points_earned' => 10,
            ]], $this->user);

            $this->assertSame('10.0000', StudentItemScore::firstOrFail()->points_earned);
        });
    }

    #[Test]
    public function a_bonus_item_still_cannot_score_above_its_own_points_possible(): void
    {
        $this->inTenant(function (): void {
            $org = $this->organization;
            $year = AcademicYear::factory()->recycle($org)->create();
            $period = AcademicPeriod::factory()->recycle($org)->for($year)->create();
            $subject = Subject::factory()->recycle($org)->create();
            $class = SchoolClass::factory()->recycle($org)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $class->teachers()->attach($this->user, ['role' => 'owner']);
            $instrument = Instrument::factory()->recycle($org)->create([
                'class_id' => $class->id,
                'academic_period_id' => $period->id,
                'status' => 'prepared',
            ]);
            $item = InstrumentItem::factory()->recycle($org)->create([
                'instrument_id' => $instrument->id,
                'points_possible' => 5,
                'is_bonus' => true,
            ]);
            $student = Student::factory()->recycle($org)->create();
            $enrollment = Enrollment::factory()->recycle($org)->create([
                'class_id' => $class->id,
                'student_id' => $student->id,
            ]);

            $this->expectException(ScoreExceedsMaximumException::class);

            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'lock_version' => 0,
                'points_earned' => 8,
            ]], $this->user);
        });
    }

    #[Test]
    public function a_new_cell_with_version_zero_is_written_and_returns_its_next_version(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scenario();

            $result = app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'points_earned' => 7,
                'lock_version' => 0,
            ]], $this->user);

            $this->assertSame(1, $result->written);
            $this->assertSame([], $result->stale);
            $this->assertSame(1, $result->versions[0]['lock_version']);
            $this->assertSame(1, StudentItemScore::firstOrFail()->lock_version);
            $this->assertSame(0, AuditEvent::where('event', 'scores.stale_write_rejected')->count());
        });
    }

    #[Test]
    public function a_second_write_from_the_same_original_version_is_rejected_as_stale(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $item, 'enrollment' => $enrollment] = $this->scenario();
            $service = app(RecordScores::class);
            $originalVersion = 0;

            $first = $service->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'points_earned' => 4,
                'state_reason' => 'Primeiro separador.',
                'lock_version' => $originalVersion,
            ]], $this->user);
            $second = $service->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'points_earned' => 9,
                'state_reason' => 'Segundo separador.',
                'lock_version' => $originalVersion,
            ]], $this->user);

            $this->assertSame(1, $first->written);
            $this->assertSame(0, $second->written);
            $this->assertSame([], $second->versions);
            $this->assertSame([[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'points_earned' => 4.0,
                'state_reason' => 'Primeiro separador.',
                'lock_version' => 1,
            ]], $second->stale);
            $this->assertSame('4.0000', StudentItemScore::firstOrFail()->points_earned);

            $audit = AuditEvent::where('event', 'scores.stale_write_rejected')->sole();
            $this->assertSame($instrument->id, $audit->subject_id);
            $this->assertSame($this->user->id, $audit->causer_id);
            $this->assertSame([[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $item->id,
            ]], $audit->properties['cells']);
        });
    }

    #[Test]
    public function writes_to_different_cells_do_not_conflict(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $firstItem, 'enrollment' => $enrollment] = $this->scenario();
            $secondItem = InstrumentItem::factory()->recycle($this->organization)->create(['instrument_id' => $instrument->id]);
            $service = app(RecordScores::class);

            $first = $service->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $firstItem->id,
                'result_state' => 'assessed',
                'points_earned' => 3,
                'lock_version' => 0,
            ]], $this->user);
            $second = $service->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $secondItem->id,
                'result_state' => 'assessed',
                'points_earned' => 6,
                'lock_version' => 0,
            ]], $this->user);

            $this->assertSame(1, $first->written);
            $this->assertSame(1, $second->written);
            $this->assertSame([], $first->stale);
            $this->assertSame([], $second->stale);
            $this->assertSame(2, StudentItemScore::count());
        });
    }

    #[Test]
    public function a_mixed_batch_writes_fresh_cells_and_returns_only_stale_cells(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'item' => $staleItem, 'enrollment' => $enrollment] = $this->scenario();
            $freshItem = InstrumentItem::factory()->recycle($this->organization)->create(['instrument_id' => $instrument->id]);
            $service = app(RecordScores::class);

            $service->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $staleItem->id,
                'result_state' => 'assessed',
                'points_earned' => 2,
                'lock_version' => 0,
            ]], $this->user);

            $result = $service->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $staleItem->id,
                'result_state' => 'assessed',
                'points_earned' => 8,
                'lock_version' => 0,
            ], [
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $freshItem->id,
                'result_state' => 'assessed',
                'points_earned' => 5,
                'lock_version' => 0,
            ]], $this->user);

            $this->assertSame(1, $result->written);
            $this->assertCount(1, $result->stale);
            $this->assertSame($staleItem->id, $result->stale[0]['instrument_item_id']);
            $this->assertSame($freshItem->id, $result->versions[0]['instrument_item_id']);
            $this->assertSame('2.0000', StudentItemScore::where('instrument_item_id', $staleItem->id)->sole()->points_earned);
            $this->assertSame('5.0000', StudentItemScore::where('instrument_item_id', $freshItem->id)->sole()->points_earned);
        });
    }

    #[Test]
    public function the_endpoint_flashes_the_authoritative_stale_cell_and_warning(): void
    {
        $context = $this->inTenant(function () {
            $context = $this->scenario();
            app(RecordScores::class)->save($context['instrument'], [[
                'enrollment_id' => $context['enrollment']->id,
                'instrument_item_id' => $context['item']->id,
                'result_state' => 'assessed',
                'points_earned' => 4,
                'lock_version' => 0,
            ]], $this->user);

            return $context;
        });

        $this->actingAs($this->user)
            ->post("/instruments/{$context['instrument']->ulid}/scores", [
                'cells' => [[
                    'enrollment_id' => $context['enrollment']->id,
                    'instrument_item_id' => $context['item']->id,
                    'result_state' => 'assessed',
                    'points_earned' => 9,
                    'lock_version' => 0,
                ]],
            ])
            ->assertRedirect();

        $flash = session('inertia.flash_data', []);
        $result = $flash['scoreSaveResult'] ?? [];
        $stale = $result['stale'][0] ?? [];

        $this->assertSame('warning', $flash['toast']['type'] ?? null);
        $this->assertSame(0, $result['written'] ?? null);
        $this->assertSame($context['item']->id, $stale['instrument_item_id'] ?? null);
        $this->assertSame(4.0, $stale['points_earned'] ?? null);
        $this->assertSame(1, $stale['lock_version'] ?? null);
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
                    'lock_version' => 0,
                    'points_earned' => 10,
                ]],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function the_endpoint_requires_a_non_negative_integer_lock_version(): void
    {
        $context = $this->inTenant(fn () => $this->scenario());

        $this->actingAs($this->user)
            ->from("/instruments/{$context['instrument']->ulid}")
            ->post("/instruments/{$context['instrument']->ulid}/scores", [
                'cells' => [[
                    'enrollment_id' => $context['enrollment']->id,
                    'instrument_item_id' => $context['item']->id,
                    'result_state' => 'assessed',
                    'points_earned' => 5,
                    'lock_version' => -1,
                ]],
            ])
            ->assertRedirect("/instruments/{$context['instrument']->ulid}")
            ->assertSessionHasErrors('cells.0.lock_version');

        $this->assertSame(0, $this->inTenant(fn () => StudentItemScore::count()));
    }

    #[Test]
    public function the_endpoint_returns_a_clean_validation_error_when_a_score_exceeds_the_maximum(): void
    {
        $context = $this->inTenant(fn () => $this->scenario());

        $this->actingAs($this->user)
            ->from("/instruments/{$context['instrument']->ulid}")
            ->post("/instruments/{$context['instrument']->ulid}/scores", [
                'cells' => [[
                    'enrollment_id' => $context['enrollment']->id,
                    'instrument_item_id' => $context['item']->id,
                    'result_state' => 'assessed',
                    'lock_version' => 0,
                    'points_earned' => 999,
                ]],
            ])
            ->assertRedirect("/instruments/{$context['instrument']->ulid}")
            ->assertSessionHasErrors('cells');

        $this->assertSame(0, $this->inTenant(fn () => StudentItemScore::count()));
    }
}
