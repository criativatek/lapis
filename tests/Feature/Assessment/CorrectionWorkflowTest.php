<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\ResultState;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\CompleteCorrection;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\InstrumentCompleteness;
use App\Services\Assessment\RecordScores;
use App\Support\Assessment\CorrectionWorkflowException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Finishing a correction, and undoing that.
 *
 * The distinction these tests defend: saving persists work, completing declares
 * the work over. A grid at 6/6 is not finished until the teacher says so — the
 * system proposes, the teacher decides (§3.3) — and completing recalculates
 * nothing.
 */
class CorrectionWorkflowTest extends TestCase
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

    protected function schoolClass(): SchoolClass
    {
        $organization = $this->organization;
        $year = AcademicYear::factory()->recycle($organization)->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        AcademicPeriod::factory()->recycle($organization)->for($year)->create();
        $subject = Subject::factory()->recycle($organization)->create();

        $class = SchoolClass::factory()->recycle($organization)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ]);
        $class->teachers()->attach($this->user, ['role' => 'owner']);

        return $class;
    }

    protected function enroll(SchoolClass $class, string $enrolledOn = '2026-09-01', ?string $leftOn = null): Enrollment
    {
        $student = Student::factory()->recycle($this->organization)->create();

        return Enrollment::factory()->recycle($this->organization)->create([
            'class_id' => $class->id,
            'student_id' => $student->id,
            'enrolled_on' => $enrolledOn,
            'left_on' => $leftOn,
            'status' => 'active',
        ]);
    }

    protected function instrumentFor(SchoolClass $class, int $items = 1): Instrument
    {
        $itemRows = [];
        foreach (range(1, $items) as $index) {
            $itemRows[] = ['code' => "Q{$index}", 'points_possible' => 100 / $items];
        }

        return app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => AcademicPeriod::where('academic_year_id', $class->academic_year_id)->firstOrFail()->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste',
            'applied_on' => '2026-10-15',
            'status' => 'prepared',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], $itemRows);
    }

    /**
     * Marks every applicable cell, which is what puts the instrument in
     * correction and leaves it at N/N.
     */
    protected function markAll(Instrument $instrument, ResultState $state = ResultState::Assessed): void
    {
        $cells = [];

        foreach ($instrument->schoolClass->enrollments as $enrollment) {
            foreach ($instrument->items as $item) {
                $cells[] = [
                    'enrollment_id' => $enrollment->id,
                    'instrument_item_id' => $item->id,
                    'result_state' => $state->value,
                    'points_earned' => $state === ResultState::Assessed ? (float) $item->points_possible : null,
                ];
            }
        }

        app(RecordScores::class)->save($instrument, $cells, $this->user);
    }

    // ------------------------------------------------- saving never completes

    #[Test]
    public function marking_moves_prepared_into_correction_but_never_further(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);

            $this->assertSame(InstrumentStatus::Prepared, $instrument->status);

            $this->markAll($instrument);

            // Every cell resolved — and still in correction, because nobody
            // declared it finished.
            $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
            $this->assertNull($instrument->fresh()->completed_at);
        });
    }

    #[Test]
    public function a_fully_marked_instrument_stays_in_correction_until_the_teacher_completes_it(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            // 2/2 — the case in the screenshot.
            $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")
                ->assertRedirect()->assertSessionHasNoErrors();

            $this->assertSame(InstrumentStatus::Completed, $instrument->fresh()->status);
        });
    }

    // ------------------------------------------------------------ completing

    #[Test]
    public function completing_records_when_and_by_whom(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")->assertRedirect();

            $fresh = $instrument->fresh();

            $this->assertSame(InstrumentStatus::Completed, $fresh->status);
            $this->assertNotNull($fresh->completed_at);
            $this->assertSame($this->user->id, $fresh->completed_by);
        });
    }

    #[Test]
    public function an_instrument_with_a_pending_cell_cannot_be_completed(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $marked = $this->enroll($class);
            $this->enroll($class); // never marked
            $instrument = $this->instrumentFor($class);

            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $marked->id,
                'instrument_item_id' => $instrument->items()->firstOrFail()->id,
                'result_state' => 'assessed',
                'points_earned' => 100,
            ]], $this->user);

            // 1/2 — refused, and the message says how many are missing.
            $response = $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete");

            $response->assertSessionHasErrors('status');
            $this->assertStringContainsString(
                'Ainda existe 1 classificação por registar',
                session('errors')->getBag('default')->first('status'),
            );
            $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
            $this->assertNull($instrument->fresh()->completed_at);
        });
    }

    #[Test]
    public function an_empty_cell_is_never_treated_as_a_zero(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $enrollment = $this->enroll($class);
            $instrument = $this->instrumentFor($class, items: 2);

            // Only one of the two questions marked.
            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $instrument->items()->orderBy('sequence')->firstOrFail()->id,
                'result_state' => 'assessed',
                'points_earned' => 50,
            ]], $this->user);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")
                ->assertSessionHasErrors('status');

            // The unmarked cell has no row at all — it is "por avaliar", not 0.
            $this->assertSame(1, StudentItemScore::withoutGlobalScopes()->where('instrument_id', $instrument->id)->count());
        });
    }

    #[Test]
    public function absence_dispensation_and_not_applicable_all_resolve_a_cell(): void
    {
        $this->inTenant(function (): void {
            foreach ([ResultState::Absent, ResultState::AbsentJustified, ResultState::Exempt, ResultState::NotApplicable, ResultState::Annulled] as $state) {
                $class = $this->schoolClass();
                $this->enroll($class);
                $instrument = $this->instrumentFor($class);
                $this->markAll($instrument, $state);

                $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")
                    ->assertSessionHasNoErrors();

                $this->assertSame(
                    InstrumentStatus::Completed,
                    $instrument->fresh()->status,
                    "{$state->value} should resolve a cell without carrying a value.",
                );
            }
        });
    }

    #[Test]
    public function a_cell_under_review_blocks_completion(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument, ResultState::UnderReview);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")
                ->assertSessionHasErrors('status');
        });
    }

    // --------------------------------------------------- who is applicable

    #[Test]
    public function a_student_who_joined_after_the_instrument_does_not_block_it(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $onTime = $this->enroll($class, '2026-09-01');
            // Joined in November; the instrument was applied on 15 October.
            $this->enroll($class, '2026-11-03');
            $instrument = $this->instrumentFor($class);

            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $onTime->id,
                'instrument_item_id' => $instrument->items()->firstOrFail()->id,
                'result_state' => 'assessed',
                'points_earned' => 100,
            ]], $this->user);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")
                ->assertSessionHasNoErrors();

            $this->assertSame(InstrumentStatus::Completed, $instrument->fresh()->status);
        });
    }

    #[Test]
    public function a_student_who_left_before_the_instrument_does_not_block_it(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $present = $this->enroll($class, '2026-09-01');
            $this->enroll($class, '2026-09-01', leftOn: '2026-09-30');
            $instrument = $this->instrumentFor($class);

            app(RecordScores::class)->save($instrument, [[
                'enrollment_id' => $present->id,
                'instrument_item_id' => $instrument->items()->firstOrFail()->id,
                'result_state' => 'assessed',
                'points_earned' => 100,
            ]], $this->user);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")
                ->assertSessionHasNoErrors();
        });
    }

    // ----------------------------------------- completing changes no result

    #[Test]
    public function completing_leaves_every_score_untouched(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class, items: 2);
            $this->markAll($instrument);

            $before = StudentItemScore::withoutGlobalScopes()
                ->where('instrument_id', $instrument->id)
                ->orderBy('id')
                ->get(['id', 'result_state', 'points_earned', 'enrollment_id', 'instrument_item_id'])
                ->toArray();

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")->assertRedirect();

            $after = StudentItemScore::withoutGlobalScopes()
                ->where('instrument_id', $instrument->id)
                ->orderBy('id')
                ->get(['id', 'result_state', 'points_earned', 'enrollment_id', 'instrument_item_id'])
                ->toArray();

            $this->assertSame($before, $after, 'Completing is a workflow transition, never a recalculation.');
        });
    }

    // ------------------------------------------------------ read-only after

    #[Test]
    public function a_completed_correction_refuses_further_marking(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $enrollment = $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete");

            $response = $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/scores", [
                'cells' => [[
                    'enrollment_id' => $enrollment->id,
                    'instrument_item_id' => $instrument->items()->firstOrFail()->id,
                    'result_state' => 'assessed',
                    'points_earned' => 10,
                ]],
            ]);

            // A message, never a 500.
            $response->assertSessionHasErrors('cells');
            $this->assertStringContainsString(
                'Reabra a correção',
                session('errors')->getBag('default')->first('cells'),
            );
            // And the original mark survives.
            $this->assertSame('100.0000', StudentItemScore::withoutGlobalScopes()
                ->where('instrument_id', $instrument->id)->firstOrFail()->points_earned);
        });
    }

    #[Test]
    public function the_grid_reports_a_completed_correction_as_read_only(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page
                    ->where('instrument.is_completed', false)
                    ->where('instrument.can_complete', true)
                    ->where('instrument.pending_count', 0));

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete");

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page
                    ->where('instrument.is_completed', true)
                    ->where('instrument.can_complete', false));
        });
    }

    #[Test]
    public function the_grid_explains_why_it_cannot_be_completed(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page
                    ->where('instrument.can_complete', false)
                    ->where('instrument.pending_count', 2)
                    ->where('instrument.applicable_count', 2));
        });
    }

    // -------------------------------------------------------------- reopening

    #[Test]
    public function reopening_returns_it_to_correction_and_clears_the_completion(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $enrollment = $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete");

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/reopen")
                ->assertRedirect()->assertSessionHasNoErrors();

            $fresh = $instrument->fresh();

            $this->assertSame(InstrumentStatus::InCorrection, $fresh->status);
            $this->assertNull($fresh->completed_at);
            $this->assertNull($fresh->completed_by);

            // And marking works again.
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/scores", [
                'cells' => [[
                    'enrollment_id' => $enrollment->id,
                    'instrument_item_id' => $instrument->items()->firstOrFail()->id,
                    'result_state' => 'assessed',
                    'points_earned' => 80,
                ]],
            ])->assertSessionHasNoErrors();

            $this->assertSame('80.0000', StudentItemScore::withoutGlobalScopes()
                ->where('instrument_id', $instrument->id)->firstOrFail()->points_earned);
        });
    }

    #[Test]
    public function completing_again_after_reopening_stamps_the_new_completion(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete");
            $first = $instrument->fresh()->completed_at;

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/reopen");

            $this->travel(2)->minutes();
            $other = User::factory()->create();
            $other->organizations()->attach($this->organization, ['joined_at' => now()]);
            $class->teachers()->attach($other, ['role' => 'co_teacher']);

            $this->actingAs($other)->post("/instruments/{$instrument->ulid}/complete")->assertSessionHasNoErrors();

            $fresh = $instrument->fresh();

            $this->assertNotNull($fresh->completed_at);
            $this->assertTrue($fresh->completed_at->greaterThan($first));
            $this->assertSame($other->id, $fresh->completed_by);
        });
    }

    // ------------------------------------------------- idempotency and guards

    #[Test]
    public function completing_twice_is_refused_with_a_readable_message(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete");
            $stamp = $instrument->fresh()->completed_at;

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")
                ->assertSessionHasErrors('status');

            // The first completion is not overwritten by the refused second.
            $this->assertEquals($stamp, $instrument->fresh()->completed_at);
        });
    }

    #[Test]
    public function reopening_something_that_is_not_completed_is_refused(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/reopen")
                ->assertSessionHasErrors('status');

            $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
        });
    }

    #[Test]
    public function a_cancelled_instrument_can_neither_be_completed_nor_reopened(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Enunciado errado']);
            $this->assertSame(InstrumentStatus::Cancelled, $instrument->fresh()->status);

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")
                ->assertSessionHasErrors('status');
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/reopen")
                ->assertSessionHasErrors('status');

            $this->assertSame(InstrumentStatus::Cancelled, $instrument->fresh()->status);
        });
    }

    #[Test]
    public function published_is_never_touched_by_this_workflow(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);
            $instrument->update(['status' => InstrumentStatus::Published]);

            // Published is deliberately out of scope in both directions.
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete")
                ->assertSessionHasErrors('status');
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/reopen")
                ->assertSessionHasErrors('status');

            $this->assertSame(InstrumentStatus::Published, $instrument->fresh()->status);
        });
    }

    // ------------------------------------------------------------- security

    #[Test]
    public function a_teacher_from_another_organization_cannot_complete_or_reopen(): void
    {
        $instrument = $this->inTenant(function (): Instrument {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            return $instrument;
        });

        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post("/instruments/{$instrument->ulid}/complete")->assertNotFound();
        $this->actingAs($outsider)->post("/instruments/{$instrument->ulid}/reopen")->assertNotFound();

        $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_cannot_reach_it(): void
    {
        $instrument = $this->inTenant(function (): Instrument {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            return $instrument;
        });

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);

        // 404, not 403: the tenant resolves to the colleague's own organization,
        // so the instrument does not exist for them — the answer never confirms
        // that it does.
        $this->actingAs($colleague)->post("/instruments/{$instrument->ulid}/complete")->assertNotFound();
        $this->actingAs($colleague)->post("/instruments/{$instrument->ulid}/reopen")->assertNotFound();

        $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status);
    }

    // ------------------------------------------------ the Avaliações listing

    #[Test]
    public function the_assessments_page_shows_a_completed_instrument_as_finished(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            $this->actingAs($this->user)->get('/assessments')
                ->assertInertia(fn ($page) => $page
                    ->where('assessments.0.state_label', 'Em correção')
                    ->where('assessments.0.action_label', 'Continuar')
                    ->where('assessments.0.progress.complete', true));

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/complete");

            // 6/6 with "Em correção" and "Continuar" was the reported problem;
            // after completing, the listing reads as finished.
            $this->actingAs($this->user)->get('/assessments')
                ->assertInertia(fn ($page) => $page
                    ->where('assessments.0.state_label', 'Concluída')
                    ->where('assessments.0.action_label', 'Ver'));
        });
    }

    #[Test]
    public function the_completeness_rule_is_the_one_the_page_and_the_action_share(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);

            // What the listing reports...
            $progress = app(InstrumentCompleteness::class)->for($instrument);
            $this->assertTrue($progress['complete']);
            $this->assertSame(2, $progress['applicable']);
            $this->assertSame(2, $progress['completed']);

            // ...is exactly what the action acts on.
            app(CompleteCorrection::class)->complete($instrument, $this->user);
            $this->assertSame(InstrumentStatus::Completed, $instrument->fresh()->status);
        });
    }

    #[Test]
    public function the_service_throws_a_readable_domain_error_rather_than_a_database_one(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $this->enroll($class);
            $instrument = $this->instrumentFor($class);
            $this->markAll($instrument);
            app(CompleteCorrection::class)->complete($instrument, $this->user);

            $this->expectException(CorrectionWorkflowException::class);
            $this->expectExceptionMessage('já está concluída');

            app(CompleteCorrection::class)->complete($instrument->fresh(), $this->user);
        });
    }
}
