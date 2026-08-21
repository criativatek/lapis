<?php

namespace Tests\Feature\Organizations;

use App\Actions\Accounts\RequestPersonalAccountClosure;
use App\Actions\Organizations\CancelOrganizationClosure;
use App\Actions\Organizations\RequestOrganizationClosure;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstitutionAdminPageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Organization, User, User} */
    private function institutionalOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $member = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach([$owner->id, $member->id], ['joined_at' => now()]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
        ]);

        return [$organization, $owner, $member];
    }

    private function asMemberOf(Organization $organization, User $user): self
    {
        return $this->actingAs($user)->withSession(['organization_id' => $organization->id]);
    }

    #[Test]
    public function the_owner_sees_the_institution_page_before_and_after_requesting_closure(): void
    {
        Carbon::setTestNow('2026-08-21 10:00:00');
        [$organization, $owner] = $this->institutionalOrganization();

        $this->asMemberOf($organization, $owner)->get('/institution')->assertInertia(fn ($page) => $page
            ->component('institution/Index')
            ->where('organization.name', $organization->name)
            ->where('organization.type', 'institutional')
            ->where('closure', null)
            ->where('closureRetentionDays', 90));

        app(RequestOrganizationClosure::class)->request($organization, $owner);
        $organization = $organization->fresh();

        $this->asMemberOf($organization, $owner)->get('/institution')->assertInertia(fn ($page) => $page
            ->component('institution/Index')
            ->where('closure.requested_at', $organization->closure_requested_at->toIso8601String())
            ->where('closure.scheduled_deletion_at', $organization->scheduled_deletion_at->toIso8601String())
            ->where('closure.days_remaining', 90)
            ->where('closure.recoverable', true)
            ->where('closureRetentionDays', 90));
    }

    #[Test]
    public function a_member_cannot_view_the_institution_page(): void
    {
        [$organization, , $member] = $this->institutionalOrganization();

        $this->asMemberOf($organization, $member)->get('/institution')->assertForbidden();
    }

    #[Test]
    public function only_the_owner_sees_the_built_institution_link_in_navigation(): void
    {
        [$organization, $owner, $member] = $this->institutionalOrganization();

        $ownerNav = $this->asMemberOf($organization, $owner)->get('/dashboard');
        $ownerNav->assertInertia(fn ($page) => $page
            ->where('nav.sections', function ($sections): bool {
                $institution = $sections->flatMap(fn ($section) => $section['items'])->firstWhere('key', 'institution');

                return $institution !== null
                    && $institution['built'] === true
                    && $institution['href'] === route('institution.index');
            }));

        $memberNav = $this->asMemberOf($organization, $member)->get('/dashboard');
        $memberNav->assertInertia(fn ($page) => $page
            ->where('nav.sections', fn ($sections) => ! $sections->flatMap(fn ($section) => $section['items'])->pluck('key')->contains('institution')));
    }

    #[Test]
    public function organization_and_personal_account_closure_lifecycles_are_independent(): void
    {
        $user = User::factory()->create();
        $personalOrganization = $user->personalOrganization();
        $institution = Organization::factory()->institutional()->create(['owner_id' => $user->id]);
        $institution->members()->attach($user, ['joined_at' => now()]);

        app(RequestOrganizationClosure::class)->request($institution, $user);

        $this->assertNull($user->fresh()->closure_requested_at);
        $this->assertNull($user->fresh()->scheduled_deletion_at);

        app(CancelOrganizationClosure::class)->cancel($institution->fresh(), $user);
        $newOwner = User::factory()->withoutOrganization()->create();
        $institution->members()->attach($newOwner, ['joined_at' => now()]);
        $institution->update(['owner_id' => $newOwner->id]);
        app(RequestPersonalAccountClosure::class)->request($user->fresh());

        $this->assertNotNull($user->fresh()->closure_requested_at);
        $this->assertNull($personalOrganization->fresh()->closure_requested_at);
        $this->assertNull($institution->fresh()->closure_requested_at);
        $this->assertNull($institution->fresh()->scheduled_deletion_at);
    }

    #[Test]
    public function an_organization_is_eligible_for_deletion_once_the_ninety_day_window_has_elapsed(): void
    {
        // isEligibleForDeletion() is scheduled_deletion_at->isPast() — strictly
        // "before now", so the exact boundary instant itself is not yet past.
        // This mirrors the existing, already-approved convention in
        // OrganizationClosureTest::day_eighty_nine_is_recoverable_day_ninety_is_not,
        // which asserts the same 90-day edge via real time having moved on.
        Carbon::setTestNow('2026-08-21 10:00:00');
        [$organization, $owner] = $this->institutionalOrganization();
        app(RequestOrganizationClosure::class)->request($organization, $owner);

        Carbon::setTestNow(now()->addDays(90)->addSecond());

        $this->assertTrue($organization->fresh()->isEligibleForDeletion());
    }

    #[Test]
    public function reactivating_an_organization_preserves_pedagogical_rows(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            $schoolClass = SchoolClass::factory()->recycle($organization)->create();
            $student = Student::factory()->recycle($organization)->create();
            Enrollment::factory()->recycle($organization)->create([
                'class_id' => $schoolClass->id,
                'student_id' => $student->id,
            ]);
        });

        app(RequestOrganizationClosure::class)->request($organization, $owner);
        app(CancelOrganizationClosure::class)->cancel($organization->fresh(), $owner);

        app(CurrentOrganization::class)->runFor($organization->fresh(), function (): void {
            $this->assertSame(1, SchoolClass::count());
            $this->assertSame(1, Student::count());
            $this->assertSame(1, Enrollment::count());
        });
    }
}
