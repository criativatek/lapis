<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Managing the PERSON, as opposed to managing the subscription.
 *
 * Deactivating and suspending are deliberately different acts with different
 * blast radii: one takes the door away from a human being, the other takes the
 * product away from an organization. These tests hold that line, and hold the
 * guards that stop an operator from locking themselves out of their own
 * backoffice.
 */
class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    /** @return array{User, Organization} teacher + their personal org */
    private function targetAccount(): array
    {
        $teacher = User::factory()->create();

        return [$teacher, $teacher->personalOrganization()];
    }

    private function trailHas(Organization $organization, string $event): bool
    {
        return app(CurrentOrganization::class)->runFor($organization, fn () => AuditEvent::where('event', $event)->exists());
    }

    // ---------------------------------------------------------------- deactivation

    #[Test]
    public function deactivating_shuts_the_person_out_and_audits_it(): void
    {
        [$teacher, $organization] = $this->targetAccount();

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/deactivate")->assertRedirect();

        $this->assertTrue($teacher->fresh()->isDeactivated());
        $this->assertTrue($this->trailHas($organization, 'admin.account_deactivated'));
    }

    #[Test]
    public function a_deactivated_account_cannot_sign_in(): void
    {
        [$teacher] = $this->targetAccount();
        $teacher->forceFill(['deactivated_at' => now()])->save();

        $this->post('/login', ['email' => $teacher->email, 'password' => 'password']);

        $this->assertGuest();
    }

    #[Test]
    public function a_session_that_was_already_open_is_ended_on_the_next_request(): void
    {
        // The case a check at the login form would miss entirely: somebody who
        // was already inside when the operator deactivated them.
        [$teacher] = $this->targetAccount();

        $this->actingAs($teacher)->get('/dashboard')->assertOk();

        $teacher->forceFill(['deactivated_at' => now()])->save();

        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function reactivating_lets_the_person_back_in(): void
    {
        [$teacher, $organization] = $this->targetAccount();
        $teacher->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/activate")->assertRedirect();

        $this->assertTrue($teacher->fresh()->isActive());
        $this->assertTrue($this->trailHas($organization, 'admin.account_activated'));

        // Stop being the admin before trying the front door: /login is behind
        // `guest`, and an authenticated visitor is bounced without ever
        // attempting the credentials.
        Auth::logout();
        $this->flushSession();

        $this->post('/login', ['email' => $teacher->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($teacher->fresh());
    }

    #[Test]
    public function deactivating_leaves_the_subscription_alone(): void
    {
        // The whole point of the separation: the organization keeps its plan.
        [$teacher, $organization] = $this->targetAccount();

        $this->actingAs($this->admin())->post("/admin/accounts/{$organization->ulid}/deactivate");

        $this->assertTrue($teacher->fresh()->isDeactivated());
        $this->assertDatabaseHas('organization_subscriptions', [
            'organization_id' => $organization->id,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function an_operator_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->admin();
        $own = $admin->personalOrganization();

        $this->actingAs($admin)->post("/admin/accounts/{$own->ulid}/deactivate")->assertSessionHasErrors('account');

        $this->assertTrue($admin->fresh()->isActive());
    }

    #[Test]
    public function the_last_active_platform_admin_cannot_be_deactivated(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $otherOrganization = $other->personalOrganization();

        // Two admins exist, so this one may go.
        $this->actingAs($admin)->post("/admin/accounts/{$otherOrganization->ulid}/deactivate")->assertRedirect();
        $this->assertTrue($other->fresh()->isDeactivated());

        // Now `admin` is the only active one left, and nobody may take them out.
        $ownOrganization = $admin->personalOrganization();
        $this->actingAs($admin)->post("/admin/accounts/{$ownOrganization->ulid}/deactivate")->assertSessionHasErrors('account');
        $this->assertTrue($admin->fresh()->isActive());
    }

    // ---------------------------------------------------------------- editing

    #[Test]
    public function the_account_page_carries_the_data_the_edit_control_renders_from(): void
    {
        // Nothing here exercises Vue directly — PHPUnit cannot render a template.
        // What it CAN prove is that the Inertia payload the "Editar utilizador"
        // form binds to (name, email) actually reaches the page, which is the
        // half of "the control is visible" that a backend test can hold.
        [$teacher, $organization] = $this->targetAccount();

        $this->actingAs($this->admin())->get("/admin/accounts/{$organization->ulid}")->assertInertia(
            fn ($page) => $page
                ->component('admin/AccountShow')
                ->where('account.owner.name', $teacher->name)
                ->where('account.owner.email', $teacher->email),
        );
    }

    #[Test]
    public function editing_the_name_and_email_saves_and_audits(): void
    {
        [$teacher, $organization] = $this->targetAccount();

        $this->actingAs($this->admin())->put("/admin/accounts/{$organization->ulid}/user", [
            'name' => 'Maria Silva',
            'email' => 'maria.silva@escola.pt',
        ])->assertRedirect();

        $teacher->refresh();
        $this->assertSame('Maria Silva', $teacher->name);
        $this->assertSame('maria.silva@escola.pt', $teacher->email);
        $this->assertTrue($this->trailHas($organization, 'admin.user_updated'));
    }

    #[Test]
    public function saving_flashes_a_success_toast(): void
    {
        // The same flash mechanism `store` and `destroy` already use — a page
        // that redirects with `back()` and no visible confirmation is a save
        // that looks like it silently did nothing.
        [$teacher, $organization] = $this->targetAccount();

        $response = $this->actingAs($this->admin())->put("/admin/accounts/{$organization->ulid}/user", [
            'name' => $teacher->name,
            'email' => $teacher->email,
        ]);

        $response->assertSessionHas('inertia.flash_data', fn ($flash) => ($flash['toast']['type'] ?? null) === 'success');
    }

    #[Test]
    public function a_changed_email_goes_back_to_unverified(): void
    {
        [$teacher, $organization] = $this->targetAccount();
        $this->assertNotNull($teacher->email_verified_at);

        $this->actingAs($this->admin())->put("/admin/accounts/{$organization->ulid}/user", [
            'name' => $teacher->name,
            'email' => 'outro@escola.pt',
        ])->assertRedirect();

        $this->assertNull($teacher->fresh()->email_verified_at);
    }

    #[Test]
    public function keeping_the_same_email_keeps_the_verification(): void
    {
        [$teacher, $organization] = $this->targetAccount();
        $verifiedAt = $teacher->email_verified_at;

        $this->actingAs($this->admin())->put("/admin/accounts/{$organization->ulid}/user", [
            'name' => 'Outro Nome',
            'email' => $teacher->email,
        ])->assertRedirect();

        $this->assertEquals($verifiedAt, $teacher->fresh()->email_verified_at);
    }

    #[Test]
    public function an_email_already_in_use_is_refused(): void
    {
        [, $organization] = $this->targetAccount();
        $somebodyElse = User::factory()->create();

        $this->actingAs($this->admin())->put("/admin/accounts/{$organization->ulid}/user", [
            'name' => 'Qualquer',
            'email' => $somebodyElse->email,
        ])->assertSessionHasErrors('email');
    }

    // ---------------------------------------------------------------- deletion

    #[Test]
    public function an_untouched_account_can_be_deleted(): void
    {
        [$teacher, $organization] = $this->targetAccount();

        $this->actingAs($this->admin())->delete("/admin/accounts/{$organization->ulid}")
            ->assertRedirect('/admin');

        $this->assertDatabaseMissing('users', ['id' => $teacher->id]);
        $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
    }

    #[Test]
    public function an_account_with_pedagogical_data_is_refused_and_nothing_is_deleted(): void
    {
        [$teacher, $organization] = $this->targetAccount();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            SchoolClass::factory()->create(['organization_id' => $organization->id]);
        });

        $this->actingAs($this->admin())->delete("/admin/accounts/{$organization->ulid}")
            ->assertSessionHasErrors('account');

        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);
    }

    #[Test]
    public function an_account_with_an_audit_trail_is_refused(): void
    {
        // Every account provisioned from the backoffice is born with an
        // `admin.account_created` event, so this is the ordinary case: history is
        // not deleted to make a delete succeed.
        [, $organization] = $this->targetAccount();

        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            app(AuditLog::class)->record('admin.account_created', $organization, summary: 'Conta criada.');
        });

        $this->actingAs($this->admin())->delete("/admin/accounts/{$organization->ulid}")
            ->assertSessionHasErrors('account');
    }

    #[Test]
    public function a_platform_admin_is_never_deleted(): void
    {
        [$teacher, $organization] = $this->targetAccount();
        $teacher->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($this->admin())->delete("/admin/accounts/{$organization->ulid}")
            ->assertSessionHasErrors('account');

        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
    }

    #[Test]
    public function an_institutional_organization_is_never_deleted_here(): void
    {
        // «Remove this person» and «remove this workspace» stop being the same
        // request the moment an organization can hold somebody else's work.
        $owner = User::factory()->create();
        $institution = Organization::factory()->institutional()->withMember($owner)->create(['owner_id' => $owner->id]);

        $this->actingAs($this->admin())->delete("/admin/accounts/{$institution->ulid}")
            ->assertSessionHasErrors('account');

        $this->assertDatabaseHas('organizations', ['id' => $institution->id]);
    }

    // ---------------------------------------------------------------- access

    #[Test]
    public function a_teacher_cannot_reach_any_of_the_new_actions(): void
    {
        [, $organization] = $this->targetAccount();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->put("/admin/accounts/{$organization->ulid}/user", [
            'name' => 'X', 'email' => 'x@escola.pt',
        ])->assertForbidden();
        $this->actingAs($intruder)->post("/admin/accounts/{$organization->ulid}/deactivate")->assertForbidden();
        $this->actingAs($intruder)->post("/admin/accounts/{$organization->ulid}/activate")->assertForbidden();
        $this->actingAs($intruder)->delete("/admin/accounts/{$organization->ulid}")->assertForbidden();
    }
}
