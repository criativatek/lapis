<?php

namespace Tests\Feature\Organizations;

use App\Actions\Organizations\AddOrganizationMember;
use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fatia 2 — attaching an EXISTING user to an organization. Not an invitation:
 * the minimum technical step (a pivot row), with nothing to accept.
 */
class AddOrganizationMemberTest extends TestCase
{
    use RefreshDatabase;

    private function institutionalOrganization(): Organization
    {
        $owner = User::factory()->withoutOrganization()->create();

        return app(CreateInstitutionalOrganization::class)->create('Escola Secundária X', $owner);
    }

    #[Test]
    public function it_attaches_an_existing_user_as_a_member(): void
    {
        $organization = $this->institutionalOrganization();
        $teacher = User::factory()->create();

        $added = app(AddOrganizationMember::class)->add($organization, $teacher);

        $this->assertTrue($added);
        $this->assertTrue($organization->members()->whereKey($teacher->getKey())->exists());
    }

    #[Test]
    public function adding_the_same_member_twice_is_idempotent_not_an_error(): void
    {
        $organization = $this->institutionalOrganization();
        $teacher = User::factory()->create();

        app(AddOrganizationMember::class)->add($organization, $teacher);
        $addedAgain = app(AddOrganizationMember::class)->add($organization, $teacher);

        $this->assertFalse($addedAgain);
        $this->assertSame(1, $organization->members()->whereKey($teacher->getKey())->count());
    }

    #[Test]
    public function the_new_members_personal_organization_is_untouched(): void
    {
        $organization = $this->institutionalOrganization();
        $teacher = User::factory()->create();
        $personal = $teacher->personalOrganization();

        app(AddOrganizationMember::class)->add($organization, $teacher);

        $this->assertTrue($teacher->fresh()->organizations()->whereKey($personal->id)->exists());
        $this->assertSame('personal', $personal->fresh()->type->value);
        $this->assertSame($teacher->id, $personal->fresh()->owner_id);
    }

    #[Test]
    public function the_owner_is_never_removed_by_adding_someone_else(): void
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = app(CreateInstitutionalOrganization::class)->create('Escola Secundária X', $owner);
        $teacher = User::factory()->create();

        app(AddOrganizationMember::class)->add($organization, $teacher);

        $this->assertTrue($organization->members()->whereKey($owner->getKey())->exists());
        $this->assertSame($owner->id, $organization->fresh()->owner_id);
    }

    #[Test]
    public function a_user_can_belong_to_their_personal_organization_and_an_institutional_one_at_once(): void
    {
        $organization = $this->institutionalOrganization();
        $teacher = User::factory()->create();

        app(AddOrganizationMember::class)->add($organization, $teacher);

        $this->assertSame(2, $teacher->fresh()->organizations()->count());
    }
}
