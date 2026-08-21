<?php

namespace Tests\Feature\Admin;

use App\Actions\Organizations\CreateInstitutionalOrganization;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fatia 2 — the platform admin creates an institutional organization from the
 * SAME backoffice screen that already provisions personal accounts (§9, §10 of
 * the multi-user security brief). No second backoffice.
 */
class InstitutionalAccountCreationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    private function trailHas(Organization $organization, string $event): bool
    {
        return app(CurrentOrganization::class)->runFor($organization, fn () => AuditEvent::where('event', $event)->exists());
    }

    #[Test]
    public function the_admin_creates_an_institutional_organization_with_a_brand_new_owner(): void
    {
        $this->actingAs($this->admin())->post('/admin/accounts', [
            'type' => 'institutional',
            'organization_name' => 'Escola Secundária X',
            'owner_mode' => 'new',
            'name' => 'Maria Silva',
            'email' => 'maria@escola.pt',
            'password' => 'segredo-forte',
            'plan_key' => 'institutional',
        ])->assertRedirect();

        $organization = Organization::where('name', 'Escola Secundária X')->firstOrFail();
        $this->assertSame('institutional', $organization->type->value);

        $owner = User::where('email', 'maria@escola.pt')->firstOrFail();
        $this->assertSame($owner->id, $organization->owner_id);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue($organization->members()->whereKey($owner->getKey())->exists());

        // The new owner ALSO got their own personal organization — the same
        // provisioning every new account gets, not a replacement for it.
        $this->assertNotNull($owner->personalOrganization());
        $this->assertSame(2, $owner->organizations()->count());

        $this->assertTrue($this->trailHas($organization, 'admin.account_created'));
    }

    #[Test]
    public function a_blank_password_generates_one_for_the_new_owner_same_as_personal_accounts(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/accounts', [
            'type' => 'institutional',
            'organization_name' => 'Escola Secundária X',
            'owner_mode' => 'new',
            'name' => 'Maria Silva',
            'email' => 'maria@escola.pt',
            'password' => '',
            'plan_key' => 'institutional',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('inertia.flash_data', fn ($flash) => str_contains($flash['toast']['message'] ?? '', 'Palavra-passe temporária'));
    }

    #[Test]
    public function the_admin_creates_an_institutional_organization_for_an_existing_user(): void
    {
        $teacher = User::factory()->create(['email' => 'joao@escola.pt']);
        $personal = $teacher->personalOrganization();

        $this->actingAs($this->admin())->post('/admin/accounts', [
            'type' => 'institutional',
            'organization_name' => 'Escola Secundária X',
            'owner_mode' => 'existing',
            'owner_email' => 'joao@escola.pt',
            'plan_key' => 'institutional',
        ])->assertRedirect();

        $this->assertSame(1, User::where('email', 'joao@escola.pt')->count());

        $organization = Organization::where('name', 'Escola Secundária X')->firstOrFail();
        $this->assertSame($teacher->id, $organization->owner_id);
        $this->assertTrue($organization->members()->whereKey($teacher->getKey())->exists());

        // The existing personal organization is completely untouched.
        $this->assertTrue($teacher->fresh()->organizations()->whereKey($personal->id)->exists());
        $this->assertSame('personal', $personal->fresh()->type->value);
        $this->assertSame(2, $teacher->fresh()->organizations()->count());
    }

    #[Test]
    public function an_existing_owner_email_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->admin())->post('/admin/accounts', [
            'type' => 'institutional',
            'organization_name' => 'Escola Secundária X',
            'owner_mode' => 'existing',
            'owner_email' => 'ninguem@escola.pt',
            'plan_key' => 'institutional',
        ])->assertSessionHasErrors('owner_email');

        $this->assertDatabaseMissing('organizations', ['name' => 'Escola Secundária X']);
    }

    #[Test]
    public function the_organization_is_subscribed_to_the_chosen_plan(): void
    {
        $this->actingAs($this->admin())->post('/admin/accounts', [
            'type' => 'institutional',
            'organization_name' => 'Escola Secundária X',
            'owner_mode' => 'new',
            'name' => 'Maria Silva',
            'email' => 'maria@escola.pt',
            'plan_key' => 'pro',
        ]);

        $organization = Organization::where('name', 'Escola Secundária X')->firstOrFail();
        $subscription = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame('pro', $subscription->plan->key);
    }

    #[Test]
    public function personal_account_creation_is_completely_unaffected(): void
    {
        // Regression: the pre-existing flow, with no `type` field at all — the
        // way the form has always posted before this fatia.
        $this->actingAs($this->admin())->post('/admin/accounts', [
            'name' => 'Novo Professor',
            'email' => 'novo@escola.pt',
            'password' => 'segredo-forte',
            'plan_key' => 'base',
        ])->assertRedirect();

        $user = User::where('email', 'novo@escola.pt')->firstOrFail();
        $organization = $user->personalOrganization();
        $this->assertNotNull($organization);
        $this->assertSame('personal', $organization->type->value);
        $this->assertSame(1, $user->organizations()->count());
    }

    #[Test]
    public function a_teacher_cannot_reach_institutional_creation(): void
    {
        $teacher = User::factory()->create();

        $this->actingAs($teacher)->post('/admin/accounts', [
            'type' => 'institutional',
            'organization_name' => 'Escola Intrusa',
            'owner_mode' => 'new',
            'name' => 'X',
            'email' => 'x@escola.pt',
            'plan_key' => 'institutional',
        ])->assertForbidden();
    }

    // ------------------------------------------------------------------ members

    #[Test]
    public function the_admin_adds_an_existing_user_as_a_member_from_the_account_page(): void
    {
        $owner = User::factory()->create(['email' => 'dono@escola.pt']);
        $this->actingAs($this->admin())->post('/admin/accounts', [
            'type' => 'institutional',
            'organization_name' => 'Escola Secundária X',
            'owner_mode' => 'existing',
            'owner_email' => 'dono@escola.pt',
            'plan_key' => 'institutional',
        ]);
        $organization = Organization::where('name', 'Escola Secundária X')->firstOrFail();

        $teacher = User::factory()->create(['email' => 'colega@escola.pt']);

        $this->actingAs($this->admin())
            ->post("/admin/accounts/{$organization->ulid}/members", ['email' => 'colega@escola.pt'])
            ->assertRedirect();

        $this->assertTrue($organization->fresh()->members()->whereKey($teacher->getKey())->exists());
        $this->assertTrue($this->trailHas($organization, 'admin.member_added'));
    }

    #[Test]
    public function adding_a_member_to_a_personal_organization_is_refused(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $someone = User::factory()->create();

        $this->actingAs($this->admin())
            ->post("/admin/accounts/{$organization->ulid}/members", ['email' => $someone->email])
            ->assertSessionHasErrors('member');

        $this->assertSame(1, $organization->fresh()->members()->count());
    }

    #[Test]
    public function adding_a_nonexistent_email_is_refused(): void
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = app(CreateInstitutionalOrganization::class)->create('Escola Secundária X', $owner);

        $this->actingAs($this->admin())
            ->post("/admin/accounts/{$organization->ulid}/members", ['email' => 'ninguem@escola.pt'])
            ->assertSessionHasErrors('email');
    }
}
