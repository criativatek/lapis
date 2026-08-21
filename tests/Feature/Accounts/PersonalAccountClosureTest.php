<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\CancelPersonalAccountClosure;
use App\Actions\Accounts\RequestPersonalAccountClosure;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Accounts\AccountClosureException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PersonalAccountClosureTest extends TestCase
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
    public function requesting_closure_stamps_a_sixty_day_deadline_and_an_audit_event(): void
    {
        $user = User::factory()->create();
        $personal = $user->personalOrganization();

        $this->actingAs($user)->withSession(['organization_id' => $personal->id])
            ->post('/settings/account-closure')
            ->assertRedirect(route('profile.edit'));

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->closure_requested_at);
        $this->assertEqualsWithDelta(
            $fresh->closure_requested_at->addDays(60)->getTimestamp(),
            $fresh->scheduled_deletion_at->getTimestamp(),
            2,
        );
        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $personal,
            fn () => AuditEvent::where('event', 'account.closure_requested')->exists(),
        ));
    }

    #[Test]
    public function requesting_closure_twice_is_refused(): void
    {
        $user = User::factory()->create();
        app(RequestPersonalAccountClosure::class)->request($user);

        $this->expectException(AccountClosureException::class);

        app(RequestPersonalAccountClosure::class)->request($user->fresh());
    }

    #[Test]
    public function owning_an_active_institution_blocks_personal_closure_until_ownership_is_transferred(): void
    {
        // A regular factory user — has a personal organization AND, on top
        // of it, owns an institutional one, exactly the situation §29 of
        // the lifecycle brief guards against.
        $owner = User::factory()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);

        $this->actingAs($owner)->withSession(['organization_id' => $owner->personalOrganization()->id])
            ->post('/settings/account-closure')
            ->assertSessionHasErrors([
                'account' => 'Antes de encerrar a sua conta, transfira a responsabilidade das organizações institucionais que gere.',
            ]);
        $this->assertNull($owner->fresh()->closure_requested_at);

        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);
        $organization->update(['owner_id' => $member->id]);

        $this->actingAs($owner)->withSession(['organization_id' => $owner->personalOrganization()->id])
            ->post('/settings/account-closure')
            ->assertRedirect(route('profile.edit'));
        $this->assertNotNull($owner->fresh()->closure_requested_at);
    }

    #[Test]
    public function cancelling_within_the_window_restores_normal_access_with_an_audit_event(): void
    {
        $user = User::factory()->create();
        $personal = $user->personalOrganization();
        app(RequestPersonalAccountClosure::class)->request($user);

        $this->actingAs($user->fresh())->withSession(['organization_id' => $personal->id])
            ->delete('/settings/account-closure')
            ->assertRedirect(route('profile.edit'));

        $fresh = $user->fresh();
        $this->assertNull($fresh->closure_requested_at);
        $this->assertNull($fresh->scheduled_deletion_at);
        $this->assertTrue(app(CurrentOrganization::class)->runFor(
            $personal,
            fn () => AuditEvent::where('event', 'account.closure_cancelled')->exists(),
        ));
    }

    #[Test]
    public function day_fifty_nine_is_recoverable_day_sixty_is_not(): void
    {
        $recoverable = User::factory()->create();
        app(RequestPersonalAccountClosure::class)->request($recoverable);
        $recoverable->forceFill(['closure_requested_at' => now()->subDays(59)])->save();
        app(CancelPersonalAccountClosure::class)->cancel($recoverable->fresh());
        $this->assertNull($recoverable->fresh()->closure_requested_at);

        $expired = User::factory()->create();
        app(RequestPersonalAccountClosure::class)->request($expired);
        $expired->forceFill(['closure_requested_at' => now()->subDays(60)])->save();

        $this->expectException(AccountClosureException::class);
        app(CancelPersonalAccountClosure::class)->cancel($expired->fresh());
    }

    #[Test]
    public function pedagogical_writes_are_blocked_while_reads_exports_and_cancellation_stay_available(): void
    {
        $user = User::factory()->create();
        $personal = $user->personalOrganization();
        app(RequestPersonalAccountClosure::class)->request($user);
        $user = $user->fresh();

        // Blocked: creating a class is ordinary pedagogical activity.
        $this->actingAs($user)->withSession(['organization_id' => $personal->id])
            ->post('/classes', ['label' => 'Turma X', 'subject' => 'Matemática', 'academic_year' => '2026/2027'])
            ->assertForbidden();

        // Allowed: reading still works.
        $this->actingAs($user)->withSession(['organization_id' => $personal->id])
            ->get('/classes')->assertOk();

        // Allowed: exporting data.
        $this->actingAs($user)->withSession(['organization_id' => $personal->id])
            ->post('/data-exports')->assertRedirect();

        // Allowed: cancelling the closure itself.
        $this->actingAs($user)->withSession(['organization_id' => $personal->id])
            ->delete('/settings/account-closure')->assertRedirect(route('profile.edit'));
        $this->assertNull($user->fresh()->closure_requested_at);
    }

    #[Test]
    public function impersonation_blocks_both_request_and_cancel(): void
    {
        $user = User::factory()->create();
        $personal = $user->personalOrganization();

        $this->actingAs($user)->withSession(['organization_id' => $personal->id, 'impersonator_id' => 999])
            ->post('/settings/account-closure')->assertForbidden();
        $this->assertNull($user->fresh()->closure_requested_at);

        app(RequestPersonalAccountClosure::class)->request($user->fresh());

        $this->actingAs($user->fresh())->withSession(['organization_id' => $personal->id, 'impersonator_id' => 999])
            ->delete('/settings/account-closure')->assertForbidden();
        $this->assertNotNull($user->fresh()->closure_requested_at);
    }

    #[Test]
    public function another_user_cannot_affect_someone_elses_closure(): void
    {
        $affected = User::factory()->create();
        app(RequestPersonalAccountClosure::class)->request($affected);
        $bystander = User::factory()->create();

        $this->actingAs($bystander)->withSession(['organization_id' => $bystander->personalOrganization()->id])
            ->delete('/settings/account-closure')
            ->assertSessionHasErrors(['account']);

        $this->assertNotNull($affected->fresh()->closure_requested_at);
    }
}
