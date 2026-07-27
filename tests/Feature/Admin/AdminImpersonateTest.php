<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Support impersonation: a platform admin becomes a teacher, then returns —
 * both ends audited, and never an admin as the target.
 */
class AdminImpersonateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    #[Test]
    public function an_admin_impersonates_a_teacher_then_returns(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create();
        $org = $teacher->personalOrganization();

        $this->actingAs($admin)->post("/admin/accounts/{$org->ulid}/impersonate")->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($teacher);
        $this->assertSame($admin->id, session('impersonator_id'));
        $this->assertTrue($this->trailHas($org, 'admin.impersonation_started'));

        $this->post('/impersonate/stop')->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('impersonator_id'));
        $this->assertTrue($this->trailHas($org, 'admin.impersonation_stopped'));
    }

    #[Test]
    public function an_admin_is_never_impersonated(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $org = $otherAdmin->personalOrganization();

        $this->actingAs($admin)->post("/admin/accounts/{$org->ulid}/impersonate")->assertForbidden();
    }

    #[Test]
    public function a_teacher_cannot_start_impersonation(): void
    {
        $target = User::factory()->create();
        $org = $target->personalOrganization();

        $this->actingAs(User::factory()->create())->post("/admin/accounts/{$org->ulid}/impersonate")->assertForbidden();
    }

    private function trailHas(Organization $org, string $event): bool
    {
        return app(CurrentOrganization::class)->runFor($org, fn () => AuditEvent::where('event', $event)->exists());
    }
}
