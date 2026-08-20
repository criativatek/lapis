<?php

namespace Tests\Feature\Organizations;

use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Scale;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fatia 1 — shared, organization-scoped configuration: a member reads and uses
 * it because ordinary work needs to (§11 of the multi-user security brief); the
 * organization's owner is the only one who may create, change or retire it
 * (§13, §16). Covers the four resources the audit found with no personal
 * variant — academic years, subjects, assessment profiles, scales — which is
 * exactly why each is "institutional" by construction, never "personal", the
 * moment an organization has more than one member.
 */
class SharedConfigurationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Organization, User, User} organization, owner, member — both attached as members (ResolveOrganization reads the pivot, not owner_id).
     */
    private function institutionalOrganizationWithMember(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $member = User::factory()->withoutOrganization()->create();

        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach([$owner->id, $member->id], ['joined_at' => now()]);

        // The routes under test are module-gated (`module:assessment_profiles`);
        // the factory does not subscribe an organization to anything, unlike
        // CreatePersonalOrganization. Institucional, so every module this fatia
        // touches is entitled.
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);

        return [$organization, $owner, $member];
    }

    private function asMemberOf(Organization $organization, User $user): self
    {
        return $this->actingAs($user)->withSession(['organization_id' => $organization->id]);
    }

    // ---------------------------------------------------------------- academic years

    #[Test]
    public function a_member_reads_academic_years_but_only_the_owner_creates_one(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();

        $this->asMemberOf($organization, $member)->get('/academic-years')->assertOk();

        $payload = [
            'label' => '2030/2031',
            'starts_on' => '2030-09-01',
            'ends_on' => '2031-08-31',
            'status' => 'draft',
            'country_code' => 'PT',
            'periods' => [
                ['label' => '1.º Período', 'kind' => 'term', 'sequence' => 1, 'starts_on' => '2030-09-01', 'ends_on' => '2030-12-19'],
            ],
        ];

        $this->asMemberOf($organization, $member)->post('/academic-years', $payload)->assertForbidden();
        $this->assertSame(0, AcademicYear::count());

        $this->asMemberOf($organization, $owner)->post('/academic-years', $payload)->assertRedirect();
        $this->assertSame(1, AcademicYear::withoutGlobalScope('organization')->where('organization_id', $organization->id)->count());
    }

    #[Test]
    public function a_member_cannot_update_or_delete_an_academic_year_the_owner_can(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();
        $year = AcademicYear::factory()->recycle($organization)->create();

        $update = [
            'label' => $year->label,
            'starts_on' => $year->starts_on->toDateString(),
            'ends_on' => $year->ends_on->toDateString(),
            'status' => 'draft',
            'country_code' => 'PT',
            'periods' => [
                ['label' => '1.º Período', 'kind' => 'term', 'sequence' => 1, 'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString()],
            ],
        ];

        $this->asMemberOf($organization, $member)->put("/academic-years/{$year->ulid}", $update)->assertForbidden();
        $this->asMemberOf($organization, $member)->delete("/academic-years/{$year->ulid}")->assertForbidden();
        $this->assertDatabaseHas('academic_years', ['id' => $year->id]);

        $this->asMemberOf($organization, $owner)->put("/academic-years/{$year->ulid}", $update)->assertRedirect();
        $this->asMemberOf($organization, $owner)->delete("/academic-years/{$year->ulid}")->assertRedirect();
        $this->assertDatabaseMissing('academic_years', ['id' => $year->id]);
    }

    // --------------------------------------------------------------------- subjects

    #[Test]
    public function a_member_reads_subjects_but_only_the_owner_manages_the_catalogue(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();

        $this->asMemberOf($organization, $member)->get('/subjects')->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManage', false));
        $this->asMemberOf($organization, $owner)->get('/subjects')->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManage', true));

        $this->asMemberOf($organization, $member)->post('/subjects', ['name' => 'Português', 'code' => 'PT7'])->assertForbidden();
        $this->assertSame(0, Subject::count());

        $this->asMemberOf($organization, $owner)->post('/subjects', ['name' => 'Português', 'code' => 'PT7'])->assertRedirect();
        $subject = Subject::withoutGlobalScope('organization')->where('organization_id', $organization->id)->firstOrFail();

        $this->asMemberOf($organization, $member)->put("/subjects/{$subject->ulid}", ['name' => 'X', 'code' => 'X9'])->assertForbidden();
        $this->asMemberOf($organization, $member)->delete("/subjects/{$subject->ulid}")->assertForbidden();
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
    }

    // -------------------------------------------------------- assessment profiles

    #[Test]
    public function a_member_reads_and_uses_an_assessment_profile_but_only_the_owner_writes_it(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();
        $profile = AssessmentProfile::factory()->recycle($organization)->create();

        // Reading — needed to teach a class graded by this profile.
        $this->asMemberOf($organization, $member)->get("/assessment-profiles/{$profile->ulid}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManage', false));

        // Creating a NEW profile is not open to a member at all.
        $this->asMemberOf($organization, $member)->get('/assessment-profiles/create')->assertForbidden();

        $this->asMemberOf($organization, $owner)->get('/assessment-profiles/create')->assertOk();
    }

    #[Test]
    public function a_member_cannot_delete_a_draft_assessment_profile_the_owner_can(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();
        $profile = AssessmentProfile::factory()->recycle($organization)->create();

        $this->asMemberOf($organization, $member)->delete("/assessment-profiles/{$profile->ulid}")->assertForbidden();
        $this->assertDatabaseHas('assessment_profiles', ['id' => $profile->id]);

        $this->asMemberOf($organization, $owner)->delete("/assessment-profiles/{$profile->ulid}")->assertRedirect();
        $this->assertSoftDeleted('assessment_profiles', ['id' => $profile->id]);
    }

    // ------------------------------------------------------------------------ scales

    #[Test]
    public function a_member_reads_scales_but_only_the_owner_creates_one(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganizationWithMember();

        $this->asMemberOf($organization, $member)
            ->post('/scales', ['name' => 'Escala da turma', 'min_value' => 0, 'max_value' => 10])
            ->assertForbidden();
        $this->assertSame(0, Scale::withoutGlobalScope('organization')->whereNotNull('organization_id')->count());

        $this->asMemberOf($organization, $owner)
            ->post('/scales', ['name' => 'Escala da turma', 'min_value' => 0, 'max_value' => 10])
            ->assertRedirect();
        $this->assertSame(1, Scale::withoutGlobalScope('organization')->where('organization_id', $organization->id)->count());
    }

    #[Test]
    public function nobody_may_edit_a_system_scale_owner_included(): void
    {
        [$organization, $owner] = $this->institutionalOrganizationWithMember();
        $systemScale = Scale::factory()->create(['organization_id' => null]);

        $this->assertFalse($owner->can('update', $systemScale));
        $this->assertFalse($owner->can('delete', $systemScale));
    }

    // ----------------------------------------------------------- cross-organization

    #[Test]
    public function the_owner_of_one_organization_cannot_touch_another_organizations_configuration(): void
    {
        [$organizationA, $ownerA] = $this->institutionalOrganizationWithMember();
        [$organizationB, $ownerB] = $this->institutionalOrganizationWithMember();

        $subjectB = Subject::factory()->recycle($organizationB)->create();

        // A's owner, resolved into A's tenant, cannot even find B's subject —
        // the global scope hides it before the policy is ever asked.
        $this->asMemberOf($organizationA, $ownerA)
            ->put("/subjects/{$subjectB->ulid}", ['name' => 'Intruso', 'code' => 'INT'])
            ->assertNotFound();

        $this->assertDatabaseHas('subjects', ['id' => $subjectB->id, 'name' => $subjectB->name]);

        // B's own owner still can, in their own tenant.
        $this->asMemberOf($organizationB, $ownerB)
            ->put("/subjects/{$subjectB->ulid}", ['name' => 'Renomeada', 'code' => $subjectB->code])
            ->assertRedirect();
        $this->assertSame('Renomeada', $subjectB->fresh()->name);
    }

    #[Test]
    public function a_manipulated_session_organization_id_does_not_grant_ownership_of_another_organization(): void
    {
        [, $ownerA] = $this->institutionalOrganizationWithMember();
        [$organizationB] = $this->institutionalOrganizationWithMember();

        // ownerA is not a member of B at all — ResolveOrganization refuses the
        // candidate and falls back to ownerA's own organization, so B's write
        // routes still 404/403 against a subject that does not resolve as theirs.
        $subjectB = Subject::factory()->recycle($organizationB)->create();

        $this->actingAs($ownerA)
            ->withSession(['organization_id' => $organizationB->id])
            ->put("/subjects/{$subjectB->ulid}", ['name' => 'X', 'code' => 'X9'])
            ->assertNotFound();
    }

    // -------------------------------------------------------------- personal organization

    #[Test]
    public function a_solo_teacher_keeps_editing_everything_exactly_as_before(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();

        $this->actingAs($teacher)->get('/subjects')->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManage', true));

        $this->actingAs($teacher)->post('/subjects', ['name' => 'Português', 'code' => 'PT7'])->assertRedirect();
        $subject = Subject::firstOrFail();
        $this->actingAs($teacher)->put("/subjects/{$subject->ulid}", ['name' => 'Português A', 'code' => 'PTA'])->assertRedirect();
        $this->assertSame('Português A', $subject->fresh()->name);

        $this->actingAs($teacher)->get('/academic-years')->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManage', true));

        $profile = AssessmentProfile::factory()->recycle($organization)->create();
        $this->actingAs($teacher)->get("/assessment-profiles/{$profile->ulid}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->where('canManage', true));
    }
}
