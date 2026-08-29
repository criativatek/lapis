<?php

namespace Tests\Feature\DataImports;

use App\Models\DataImport;
use App\Models\DataImportStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may reach a restore session, and when the wizard itself is closed off
 * entirely — independent of what the backup contains (DataImportFileSafetyTest)
 * or what confirming actually writes (DataImportTest).
 *
 * Tenant isolation (route-model binding, scoped by the `organization` global
 * scope) and the `requested_by` ownership check (DataImportPolicy) are two
 * different gates and fail two different ways: another organization's ulid
 * is never found at all (404), a colleague in the SAME organization is found
 * and refused (403). Both are asserted here so a regression in either shows
 * up as the right failure, not a false pass through the other gate.
 */
class DataImportAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * organization_id is deliberately not fillable on DataImport (always
     * stamped from the resolved tenant) — a test with no tenant in the
     * container has to set it the same way the model itself would.
     */
    private function createImport(Organization $organization, User $requester, DataImportStatus $status = DataImportStatus::Validated): DataImport
    {
        $import = new DataImport;
        $import->forceFill([
            'organization_id' => $organization->id,
            'status' => $status->value,
            'stored_path' => 'data-imports/'.Str::uuid().'.zip',
            'requested_by' => $requester->id,
            'canonical_snapshot' => [
                'schema_version' => 3,
                'app_version' => '0.43.0',
                'generated_at' => now()->toIso8601String(),
                'organization' => ['ulid' => (string) Str::ulid(), 'name' => 'Origem', 'type' => 'personal'],
                'classes' => [],
                'students' => [],
                'enrollments' => [],
                'instruments' => [],
                'classifications' => [],
            ],
            'expires_at' => now()->addDay(),
        ]);
        $import->save();

        return $import;
    }

    #[Test]
    public function a_member_of_a_different_organization_gets_not_found_not_forbidden(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $import = $this->createImport($organization, $owner);

        $stranger = User::factory()->create();
        $strangerOrg = $stranger->personalOrganization();

        $this->actingAs($stranger)->withSession(['organization_id' => $strangerOrg->id])
            ->get("/data-imports/{$import->ulid}")->assertNotFound();

        $this->actingAs($stranger)->withSession(['organization_id' => $strangerOrg->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertNotFound();

        $this->actingAs($stranger)->withSession(['organization_id' => $strangerOrg->id])
            ->delete("/data-imports/{$import->ulid}")->assertNotFound();
    }

    #[Test]
    public function a_colleague_in_the_same_organization_may_never_act_on_someone_elses_import(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);

        $import = $this->createImport($organization, $owner);

        $this->actingAs($colleague)->withSession(['organization_id' => $organization->id])
            ->get("/data-imports/{$import->ulid}")->assertForbidden();

        $this->actingAs($colleague)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertForbidden();

        $this->actingAs($colleague)->withSession(['organization_id' => $organization->id])
            ->delete("/data-imports/{$import->ulid}")->assertForbidden();

        $this->assertSame('validated', $import->fresh()->status->value);
    }

    #[Test]
    public function even_the_owner_may_not_act_on_a_members_import(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);

        $import = $this->createImport($organization, $member);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertForbidden();
    }

    #[Test]
    public function impersonation_blocks_uploading_confirming_and_cancelling(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $this->subscribeToPro($organization);
        $import = $this->createImport($organization, $owner);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id, 'impersonator_id' => 999])
            ->post('/data-imports')->assertForbidden();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id, 'impersonator_id' => 999])
            ->post("/data-imports/{$import->ulid}/confirm")->assertForbidden();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id, 'impersonator_id' => 999])
            ->delete("/data-imports/{$import->ulid}")->assertForbidden();

        $this->assertSame('validated', $import->fresh()->status->value);

        // Read access is untouched — support impersonating a teacher can
        // still SEE what a pending import would do, just never act on it.
        $this->actingAs($owner)->withSession(['organization_id' => $organization->id, 'impersonator_id' => 999])
            ->get("/data-imports/{$import->ulid}")->assertOk();
    }

    #[Test]
    public function an_account_in_closure_cannot_upload_confirm_or_cancel_but_can_still_see_the_wizard(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->personalOrganization();
        $this->subscribeToPro($organization);
        $owner->forceFill(['closure_requested_at' => now(), 'scheduled_deletion_at' => now()->addDays(60)])->save();
        $import = $this->createImport($organization, $owner);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-imports')->assertForbidden();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertForbidden();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete("/data-imports/{$import->ulid}")->assertForbidden();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->get('/data-imports/create')->assertOk();

        $this->assertSame('validated', $import->fresh()->status->value);
    }

    #[Test]
    public function an_organization_in_closure_cannot_upload_confirm_or_cancel_but_can_still_see_the_wizard(): void
    {
        [$owner, $organization] = $this->ownerAndOrganization();
        $this->subscribeToPro($organization);
        $organization->forceFill(['closure_requested_at' => now(), 'scheduled_deletion_at' => now()->addDays(90)])->save();
        $import = $this->createImport($organization, $owner);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-imports')->assertForbidden();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertForbidden();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->delete("/data-imports/{$import->ulid}")->assertForbidden();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->get("/data-imports/{$import->ulid}")->assertOk();

        $this->assertSame('validated', $import->fresh()->status->value);
    }

    /** @return array{0: User, 1: Organization} */
    private function ownerAndOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);

        return [$owner, $organization];
    }

    /**
     * The restore wizard is Pro and Institucional since the Base/Pro
     * realignment (Matriz §7, `data_backup_restore`). Tests that assert what
     * happens INSIDE the wizard — impersonation, a closure window — need an
     * organization entitled to reach it at all, or they assert a 403 that came
     * from the plan rather than from the rule they are about.
     */
    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
        ]);

        app(Entitlements::class)->flush();
    }
}
