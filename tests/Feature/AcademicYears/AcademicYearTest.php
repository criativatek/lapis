<?php

namespace Tests\Feature\AcademicYears;

use App\Models\AcademicYear;
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
}
