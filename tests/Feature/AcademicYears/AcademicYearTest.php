<?php

namespace Tests\Feature\AcademicYears;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfileVersion;
use App\Models\CalculationSnapshot;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\SchoolClass;
use App\Models\SnapshotTrigger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'label' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'status' => 'draft',
            'country_code' => 'PT',
            'region_code' => null,
            'periods' => [
                ['label' => '1.º Semestre', 'kind' => 'semester', 'sequence' => 1, 'starts_on' => '2026-09-14', 'ends_on' => '2027-01-29'],
                ['label' => '2.º Semestre', 'kind' => 'semester', 'sequence' => 2, 'starts_on' => '2027-02-01', 'ends_on' => '2027-06-16'],
            ],
        ], $overrides);
    }

    /**
     * The exact shape the real edit page submits for an existing period —
     * every editable field plus the ulid that identifies it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function periodPayload(AcademicPeriod $period, array $overrides = []): array
    {
        return array_merge([
            'ulid' => $period->ulid,
            'label' => $period->label,
            'kind' => $period->kind->value,
            'sequence' => $period->sequence,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
        ], $overrides);
    }

    /**
     * The minimal (class, enrollment) chain needed to attach a dependent row
     * to a period, without running any of the real enrollment-import flow.
     */
    protected function enrollmentIn(AcademicPeriod $period, User $user): Enrollment
    {
        $organization = $user->personalOrganization();

        $class = SchoolClass::factory()->recycle($organization)->create([
            'academic_year_id' => $period->academic_year_id,
        ]);

        return Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id]);
    }

    /**
     * Attaches a CalculationSnapshot to a period by constructing it directly
     * (this project has no factory for it), rather than running the full
     * proposal/confirmation pipeline just to get one row on the table.
     */
    protected function attachSnapshot(AcademicPeriod $period, User $user): CalculationSnapshot
    {
        $organization = $user->personalOrganization();
        $enrollment = $this->enrollmentIn($period, $user);
        $version = AssessmentProfileVersion::factory()->recycle($organization)->create();

        $payload = ['inputs' => ['dummy' => true]];

        // organization_id is deliberately absent: it is not mass-assignable
        // (see CalculationSnapshot's #[Fillable]) and BelongsToOrganization
        // stamps it from CurrentOrganization automatically when the key is
        // absent altogether — the same reason ConfirmClassification never
        // passes it explicitly either.
        return CalculationSnapshot::create([
            'enrollment_id' => $enrollment->id,
            'academic_period_id' => $period->id,
            'scope' => ClassificationScope::Period,
            'assessment_profile_version_id' => $version->id,
            'trigger' => SnapshotTrigger::ProposalConfirmed,
            'engine_version' => 'test',
            'payload' => $payload,
            'payload_hash' => CalculationSnapshot::hashPayload($payload),
            'result_normalized_value' => null,
            'result_value' => null,
            'result_scale_level_id' => null,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
    }

    protected function attachInstrument(AcademicPeriod $period, User $user): Instrument
    {
        $organization = $user->personalOrganization();

        $class = SchoolClass::factory()->recycle($organization)->create([
            'academic_year_id' => $period->academic_year_id,
        ]);

        return Instrument::factory()->recycle($organization)->create([
            'class_id' => $class->id,
            'academic_period_id' => $period->id,
        ]);
    }

    protected function attachClassification(AcademicPeriod $period, User $user): Classification
    {
        $organization = $user->personalOrganization();
        $enrollment = $this->enrollmentIn($period, $user);
        $version = AssessmentProfileVersion::factory()->recycle($organization)->create();

        // organization_id absent for the same reason as in attachSnapshot()
        // above — not mass-assignable, and auto-stamped from the tenant.
        return Classification::create([
            'enrollment_id' => $enrollment->id,
            'academic_period_id' => $period->id,
            'scope' => ClassificationScope::Period,
            'assessment_profile_version_id' => $version->id,
            'status' => ClassificationStatus::Proposed,
        ]);
    }

    #[Test]
    public function a_teacher_creates_a_year_with_its_periods(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/academic-years', $this->validPayload())->assertRedirect('/academic-years');

        $year = AcademicYear::first();
        $this->assertSame('2026/2027', $year->label);
        $this->assertSame($user->personalOrganization()->getKey(), $year->organization_id);
        $this->assertCount(2, $year->periods);
        $this->assertSame('1.º Semestre', $year->periods->first()->label);
    }

    #[Test]
    public function the_year_and_periods_are_created_in_one_transaction(): void
    {
        $user = User::factory()->create();

        // Duplicate sequence fails validation before any write — but prove the
        // all-or-nothing guarantee: nothing is left behind on a rejected request.
        $payload = $this->validPayload();
        $payload['periods'][1]['sequence'] = 1;

        $this->actingAs($user)->post('/academic-years', $payload)->assertSessionHasErrors('periods');

        $this->assertDatabaseCount('academic_years', 0);
        $this->assertDatabaseCount('academic_periods', 0);
    }

    #[Test]
    public function a_year_needs_at_least_one_period(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/academic-years', $this->validPayload(['periods' => []]))
            ->assertSessionHasErrors('periods');
    }

    #[Test]
    public function end_date_must_be_after_start_date(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/academic-years', $this->validPayload(['ends_on' => '2026-08-31']))
            ->assertSessionHasErrors('ends_on');
    }

    #[Test]
    public function a_period_must_fall_within_the_year(): void
    {
        $user = User::factory()->create();
        $payload = $this->validPayload();
        $payload['periods'][0]['ends_on'] = '2027-12-31'; // past the year's end

        $this->actingAs($user)->post('/academic-years', $payload)->assertSessionHasErrors('periods.0.starts_on');
    }

    #[Test]
    public function the_label_is_unique_within_the_organization_but_not_across(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());

        // Same label again in the same org: rejected.
        $this->actingAs($user)->post('/academic-years', $this->validPayload())->assertSessionHasErrors('label');

        // Same label in a different org: allowed — the unique rule is org-scoped.
        $other = User::factory()->create();
        $this->actingAs($other)->post('/academic-years', $this->validPayload())->assertRedirect('/academic-years');

        $this->assertDatabaseCount('academic_years', 2);
    }

    #[Test]
    public function a_teacher_cannot_see_or_edit_another_organizations_year(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::withoutGlobalScope('organization')->firstOrFail();

        // Scenario A7: another teacher, knowing the ulid, gets a 404 — the record
        // does not resolve outside its organization.
        $intruder = User::factory()->create();
        $this->actingAs($intruder)->get("/academic-years/{$year->ulid}/edit")->assertNotFound();
        $this->actingAs($intruder)->delete("/academic-years/{$year->ulid}")->assertNotFound();
    }

    #[Test]
    public function editing_replaces_the_periods(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();

        // No ulid on any submitted period, so under the diff-based sync every
        // existing period is a removal candidate (none has dependents, so all
        // are actually removed) and all three submitted ones are created as
        // new — the outcome this test asserts still holds, even though the
        // mechanism underneath is no longer a blanket delete-and-recreate.
        $payload = $this->validPayload([
            'periods' => [
                ['label' => '1.º Período', 'kind' => 'trimester', 'sequence' => 1, 'starts_on' => '2026-09-14', 'ends_on' => '2026-12-15'],
                ['label' => '2.º Período', 'kind' => 'trimester', 'sequence' => 2, 'starts_on' => '2027-01-05', 'ends_on' => '2027-03-30'],
                ['label' => '3.º Período', 'kind' => 'trimester', 'sequence' => 3, 'starts_on' => '2027-04-10', 'ends_on' => '2027-06-16'],
            ],
        ]);

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $payload)->assertRedirect('/academic-years');

        $year->refresh();
        $this->assertCount(3, $year->periods);
        $this->assertSame('trimester', $year->periods->first()->kind->value);
    }

    #[Test]
    public function a_closed_year_cannot_be_edited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $year->update(['status' => 'closed']);

        // update() policy returns false for a non-editable year.
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload())->assertForbidden();
    }

    #[Test]
    public function only_a_draft_year_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();

        $year->update(['status' => 'active']);
        $this->actingAs($user)->delete("/academic-years/{$year->ulid}")->assertForbidden();

        $year->update(['status' => 'draft']);
        $this->actingAs($user)->delete("/academic-years/{$year->ulid}")->assertRedirect('/academic-years');
        $this->assertDatabaseCount('academic_years', 0);
    }

    #[Test]
    public function saving_unchanged_periods_does_not_delete_a_period_with_a_snapshot(): void
    {
        // THE EXACT REGRESSION TEST: opening the edit page for an existing
        // year, changing nothing, and saving used to throw a raw 500 from
        // `delete from academic_periods where ...` failing a FK constraint,
        // the moment any period had so much as one dependent row.
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $periods = $year->periods()->orderBy('sequence')->get();
        $period = $periods->first();
        $periodId = $period->id;

        $snapshot = $this->attachSnapshot($period, $user);

        $payload = $this->validPayload([
            'periods' => $periods->map(fn (AcademicPeriod $p) => $this->periodPayload($p))->all(),
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect('/academic-years');

        $this->assertDatabaseCount('academic_periods', 2);
        $reloaded = AcademicPeriod::find($periodId);
        $this->assertNotNull($reloaded);
        $this->assertSame($period->ulid, $reloaded->ulid);
        $this->assertSame($periodId, $snapshot->fresh()->academic_period_id);
        $this->assertDatabaseHas('calculation_snapshots', ['id' => $snapshot->id]);
    }

    #[Test]
    public function saving_with_no_changes_at_all_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $periods = $year->periods()->orderBy('sequence')->get();
        $idsBefore = $periods->pluck('id')->all();

        $payload = $this->validPayload([
            'periods' => $periods->map(fn (AcademicPeriod $p) => $this->periodPayload($p))->all(),
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect('/academic-years');

        $this->assertCount(2, $year->fresh()->periods);
        $this->assertSame($idsBefore, $year->periods()->orderBy('sequence')->pluck('id')->all());
    }

    #[Test]
    public function editing_a_periods_label_with_a_snapshot_attached_keeps_its_id(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $periods = $year->periods()->orderBy('sequence')->get();
        $period = $periods->first();
        $periodId = $period->id;
        $snapshot = $this->attachSnapshot($period, $user);

        $payload = $this->validPayload([
            'periods' => [
                $this->periodPayload($period, ['label' => '1.º Semestre (renomeado)', 'ends_on' => '2027-01-30']),
                $this->periodPayload($periods->last()),
            ],
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertRedirect('/academic-years');

        $reloaded = AcademicPeriod::find($periodId);
        $this->assertNotNull($reloaded);
        $this->assertSame('1.º Semestre (renomeado)', $reloaded->label);
        $this->assertSame('2027-01-30', $reloaded->ends_on->toDateString());
        $this->assertSame($periodId, $snapshot->fresh()->academic_period_id);
    }

    #[Test]
    public function adding_a_new_period_keeps_existing_ids_untouched(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $periods = $year->periods()->orderBy('sequence')->get();
        $idsBefore = $periods->pluck('id')->all();

        $payload = $this->validPayload([
            'periods' => [
                $this->periodPayload($periods[0]),
                $this->periodPayload($periods[1]),
                ['label' => '3.º Período extra', 'kind' => 'module', 'sequence' => 3, 'starts_on' => '2027-06-17', 'ends_on' => '2027-08-30'],
            ],
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertRedirect('/academic-years');

        $year->refresh();
        $this->assertCount(3, $year->periods);
        $newIds = $year->periods()->orderBy('sequence')->pluck('id')->all();
        $this->assertSame($idsBefore, array_slice($newIds, 0, 2));
        $this->assertNotContains($newIds[2], $idsBefore);
    }

    #[Test]
    public function a_period_with_a_calculation_snapshot_cannot_be_removed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $periods = $year->periods()->orderBy('sequence')->get();
        $withDependent = $periods->first();
        $periodId = $withDependent->id;
        $snapshot = $this->attachSnapshot($withDependent, $user);

        // The client's existing "remove period" behaviour: the period simply
        // stops being submitted.
        $payload = $this->validPayload([
            'periods' => [$this->periodPayload($periods->last())],
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasErrors('periods');

        $this->assertNotNull(AcademicPeriod::find($periodId));
        $this->assertDatabaseHas('calculation_snapshots', ['id' => $snapshot->id, 'academic_period_id' => $periodId]);
    }

    #[Test]
    public function a_period_with_an_instrument_cannot_be_removed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $periods = $year->periods()->orderBy('sequence')->get();
        $withDependent = $periods->first();
        $periodId = $withDependent->id;
        $instrument = $this->attachInstrument($withDependent, $user);

        $payload = $this->validPayload([
            'periods' => [$this->periodPayload($periods->last())],
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasErrors('periods');

        $this->assertNotNull(AcademicPeriod::find($periodId));
        $this->assertDatabaseHas('instruments', ['id' => $instrument->id, 'academic_period_id' => $periodId]);
    }

    #[Test]
    public function a_period_with_a_classification_cannot_be_removed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $periods = $year->periods()->orderBy('sequence')->get();
        $withDependent = $periods->first();
        $periodId = $withDependent->id;
        $classification = $this->attachClassification($withDependent, $user);

        $payload = $this->validPayload([
            'periods' => [$this->periodPayload($periods->last())],
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasErrors('periods');

        $this->assertNotNull(AcademicPeriod::find($periodId));
        $this->assertDatabaseHas('classifications', ['id' => $classification->id, 'academic_period_id' => $periodId]);
    }

    #[Test]
    public function a_period_without_dependents_can_be_removed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $periods = $year->periods()->orderBy('sequence')->get();
        $removedId = $periods->first()->id;

        $payload = $this->validPayload([
            'periods' => [$this->periodPayload($periods->last())],
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertRedirect('/academic-years');

        $this->assertNull(AcademicPeriod::find($removedId));
        $this->assertDatabaseCount('academic_periods', 1);
    }

    #[Test]
    public function a_period_ulid_belonging_to_a_different_year_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $yearA = AcademicYear::where('label', '2026/2027')->firstOrFail();

        $this->actingAs($user)->post('/academic-years', $this->validPayload([
            'label' => '2027/2028',
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-08-31',
            'periods' => [
                ['label' => '1.º Semestre', 'kind' => 'semester', 'sequence' => 1, 'starts_on' => '2027-09-14', 'ends_on' => '2028-01-29'],
                ['label' => '2.º Semestre', 'kind' => 'semester', 'sequence' => 2, 'starts_on' => '2028-02-01', 'ends_on' => '2028-06-16'],
            ],
        ]));
        $yearB = AcademicYear::where('label', '2027/2028')->firstOrFail();
        $foreignPeriod = $yearB->periods()->firstOrFail();
        $originalSequence = $foreignPeriod->sequence;
        $originalLabel = $foreignPeriod->label;

        $payload = $this->validPayload();
        $payload['periods'][0]['ulid'] = $foreignPeriod->ulid;

        $this->actingAs($user)
            ->put("/academic-years/{$yearA->ulid}", $payload)
            ->assertSessionHasErrors('periods.0.ulid');

        $foreignPeriod->refresh();
        $this->assertSame($originalSequence, $foreignPeriod->sequence);
        $this->assertSame($originalLabel, $foreignPeriod->label);
    }

    #[Test]
    public function a_period_ulid_belonging_to_another_organization_is_rejected(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();

        $other = User::factory()->create();
        $this->actingAs($other)->post('/academic-years', $this->validPayload());
        $otherYear = AcademicYear::withoutGlobalScope('organization')
            ->where('organization_id', $other->personalOrganization()->getKey())
            ->firstOrFail();
        $foreignPeriod = $otherYear->periods()->firstOrFail();

        $payload = $this->validPayload();
        $payload['periods'][0]['ulid'] = $foreignPeriod->ulid;

        $this->actingAs($owner)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasErrors('periods.0.ulid');
    }

    #[Test]
    public function reordering_two_periods_swaps_their_sequence_without_a_collision(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $periods = $year->periods()->orderBy('sequence')->get();
        $first = $periods[0];
        $second = $periods[1];
        $firstId = $first->id;
        $secondId = $second->id;

        $payload = $this->validPayload([
            'periods' => [
                $this->periodPayload($first, ['sequence' => 2]),
                $this->periodPayload($second, ['sequence' => 1]),
            ],
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertRedirect('/academic-years');

        $this->assertSame(2, AcademicPeriod::find($firstId)?->sequence);
        $this->assertSame(1, AcademicPeriod::find($secondId)?->sequence);
    }

    #[Test]
    public function a_rejected_removal_leaves_every_other_change_in_the_same_request_unapplied(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/academic-years', $this->validPayload());
        $year = AcademicYear::firstOrFail();
        $periods = $year->periods()->orderBy('sequence')->get();
        $withDependent = $periods->first();
        $other = $periods->last();
        $this->attachSnapshot($withDependent, $user);

        $originalYearLabel = $year->label;
        $originalOtherLabel = $other->label;

        // Illegitimate removal of $withDependent, bundled with an otherwise
        // legitimate rename of both the year and the other period in the
        // very same request — none of it should stick.
        $payload = $this->validPayload([
            'label' => 'Ano renomeado',
            'periods' => [
                $this->periodPayload($other, ['label' => 'Período renomeado']),
            ],
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasErrors('periods');

        $this->assertSame($originalYearLabel, $year->fresh()->label);
        $this->assertSame($originalOtherLabel, $other->fresh()->label);
        $this->assertNotNull(AcademicPeriod::find($withDependent->id));
        $this->assertDatabaseCount('academic_periods', 2);
    }
}
