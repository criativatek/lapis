<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The backoffice acts on organizations across tenants: verify email, change plan,
 * suspend, grant admin — each audited under the target org's trail.
 */
class AdminAccountManagementTest extends TestCase
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

    #[Test]
    public function verifying_email_marks_the_owner_and_audits_it(): void
    {
        [$teacher, $org] = $this->targetAccount();
        $teacher->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->admin())->post("/admin/accounts/{$org->ulid}/verify-email")->assertRedirect();

        $this->assertNotNull($teacher->fresh()->email_verified_at);
        $this->assertTrue($this->trailHas($org, 'admin.email_verified'));
    }

    #[Test]
    public function changing_the_plan_creates_a_new_subscription_and_grants_its_modules(): void
    {
        [, $org] = $this->targetAccount();

        $this->actingAs($this->admin())->post("/admin/accounts/{$org->ulid}/plan", ['plan_key' => 'pro'])
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data', fn ($flash) => ($flash['toast']['message'] ?? null) === 'Plano atualizado para LÁPIS Pro.');

        $latest = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->latest('starts_at')->latest('id')->with('plan')->first();
        $this->assertSame('pro', $latest->plan->key);

        // Pro includes calendar; Base does not — the entitlement now resolves it.
        $entitlements = app(Entitlements::class);
        $entitlements->flush();
        $this->assertContains('calendar', $entitlements->modulesFor($org->fresh()));
    }

    #[Test]
    public function suspending_cuts_access(): void
    {
        [, $org] = $this->targetAccount();

        $this->actingAs($this->admin())->post("/admin/accounts/{$org->ulid}/suspend")->assertRedirect();

        $latest = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->latest('starts_at')->latest('id')->first();
        $this->assertSame(SubscriptionStatus::Suspended, $latest->status);

        $entitlements = app(Entitlements::class);
        $entitlements->flush();
        $this->assertSame([], $entitlements->modulesFor($org->fresh()));
    }

    #[Test]
    public function granting_admin_toggles_the_owner_flag(): void
    {
        [$teacher, $org] = $this->targetAccount();

        $this->actingAs($this->admin())->post("/admin/accounts/{$org->ulid}/toggle-admin")->assertRedirect();
        $this->assertTrue($teacher->fresh()->isPlatformAdmin());

        $this->actingAs($this->admin())->post("/admin/accounts/{$org->ulid}/toggle-admin")->assertRedirect();
        $this->assertFalse($teacher->fresh()->isPlatformAdmin());
    }

    #[Test]
    public function provisioning_creates_a_verified_account_on_the_chosen_plan(): void
    {
        $this->actingAs($this->admin())->post('/admin/accounts', [
            'name' => 'Novo Professor',
            'email' => 'novo@escola.pt',
            'password' => 'segredo-forte',
            'plan_key' => 'pro',
        ])->assertRedirect();

        $user = User::where('email', 'novo@escola.pt')->firstOrFail();
        $this->assertNotNull($user->email_verified_at); // provisioned accounts skip verification
        $org = $user->personalOrganization();
        $this->assertNotNull($org);

        $entitlements = app(Entitlements::class);
        $entitlements->flush();
        $this->assertContains('calendar', $entitlements->modulesFor($org)); // pro plan applied
        $this->assertTrue($this->trailHas($org, 'admin.account_created'));
    }

    #[Test]
    public function provisioning_rejects_a_duplicate_email(): void
    {
        $existing = User::factory()->create();

        $this->actingAs($this->admin())->post('/admin/accounts', [
            'name' => 'Repetido',
            'email' => $existing->email,
            'plan_key' => 'base',
        ])->assertSessionHasErrors('email');
    }

    #[Test]
    public function a_teacher_cannot_act_on_accounts(): void
    {
        [, $org] = $this->targetAccount();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post("/admin/accounts/{$org->ulid}/plan", ['plan_key' => 'pro'])->assertForbidden();
    }

    private function trailHas(Organization $org, string $event): bool
    {
        return app(CurrentOrganization::class)->runFor($org, fn () => AuditEvent::where('event', $event)->exists());
    }
}
