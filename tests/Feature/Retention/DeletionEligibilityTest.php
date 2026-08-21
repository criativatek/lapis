<?php

namespace Tests\Feature\Retention;

use App\Actions\Accounts\RequestPersonalAccountClosure;
use App\Actions\Organizations\RequestOrganizationClosure;
use App\Models\AuditEvent;
use App\Models\DataExport;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Retention\DeletionEligibility;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Read-only previews only — every test here asserts something still EXISTS
 * afterward. Nothing in DeletionEligibility, nor `retention:status`, is
 * permitted to delete, purge or mutate (§18, §83 of the lifecycle brief).
 */
class DeletionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Organization, User} */
    private function institutionalOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);

        return [$organization, $owner];
    }

    #[Test]
    public function personal_accounts_are_listed_with_the_correct_eligibility_boundary_and_nothing_is_deleted(): void
    {
        $recoverable = User::factory()->create();
        app(RequestPersonalAccountClosure::class)->request($recoverable);
        $recoverable->forceFill(['closure_requested_at' => now()->subDays(30)])->save();

        $eligible = User::factory()->create();
        app(RequestPersonalAccountClosure::class)->request($eligible);
        $eligible->forceFill(['closure_requested_at' => now()->subDays(61)])->save();

        $rows = app(DeletionEligibility::class)->personalAccounts()->keyBy(fn (array $row) => $row['user']->getKey());

        $this->assertFalse($rows[$recoverable->id]['eligible']);
        $this->assertTrue($rows[$eligible->id]['eligible']);
        $this->assertTrue(User::whereKey([$recoverable->id, $eligible->id])->count() === 2);
    }

    #[Test]
    public function institutional_organizations_are_listed_with_the_correct_eligibility_boundary_and_nothing_is_deleted(): void
    {
        [$recoverableOrg, $owner1] = $this->institutionalOrganization();
        app(RequestOrganizationClosure::class)->request($recoverableOrg, $owner1);
        $recoverableOrg->forceFill(['closure_requested_at' => now()->subDays(30)])->save();

        [$eligibleOrg, $owner2] = $this->institutionalOrganization();
        app(RequestOrganizationClosure::class)->request($eligibleOrg, $owner2);
        $eligibleOrg->forceFill(['closure_requested_at' => now()->subDays(91)])->save();

        $rows = app(DeletionEligibility::class)->institutionalOrganizations()->keyBy(fn (array $row) => $row['organization']->getKey());

        $this->assertFalse($rows[$recoverableOrg->id]['eligible']);
        $this->assertTrue($rows[$eligibleOrg->id]['eligible']);
        $this->assertTrue(Organization::whereKey([$recoverableOrg->id, $eligibleOrg->id])->count() === 2);
    }

    #[Test]
    public function expired_exports_are_listed_without_being_removed(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $export = app(CurrentOrganization::class)->runFor($organization, fn () => DataExport::create([
            'requested_by' => $owner->id,
            'status' => 'ready',
            'disk_path' => 'data-exports/whatever/export.zip',
            'expires_at' => now()->subDay(),
        ]));

        $expired = app(DeletionEligibility::class)->expiredDataExports();

        $this->assertTrue($expired->contains(fn (DataExport $row) => $row->is($export)));
        $this->assertNotNull($export->fresh()->disk_path);
    }

    #[Test]
    public function audit_events_outside_the_security_retention_window_are_counted_but_never_purged(): void
    {
        [$organization, $owner] = $this->institutionalOrganization();
        $old = app(CurrentOrganization::class)->runFor($organization, fn () => AuditEvent::create([
            'causer_id' => $owner->id,
            'event' => 'organization.created',
            'created_at' => now()->subYears(4),
        ]));
        app(CurrentOrganization::class)->runFor($organization, fn () => AuditEvent::create([
            'causer_id' => $owner->id,
            'event' => 'organization.created',
            'created_at' => now()->subMonths(6),
        ]));

        $count = app(DeletionEligibility::class)->auditEventsOutsideRetention();

        $this->assertSame(1, $count);
        $this->assertTrue(AuditEvent::withoutGlobalScope('organization')->whereKey($old->id)->exists());
    }

    #[Test]
    public function the_command_runs_read_only_and_reports_json(): void
    {
        $user = User::factory()->create();
        app(RequestPersonalAccountClosure::class)->request($user);

        $this->artisan('retention:status --json')
            ->assertSuccessful()
            ->expectsOutputToContain('personal_accounts');

        $this->assertNotNull($user->fresh()->closure_requested_at);
    }
}
