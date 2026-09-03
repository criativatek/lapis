<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLog;
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
        $admin->forceFill([
            'is_platform_admin' => true,
            'is_support_technician' => true,
        ])->save();

        return $admin;
    }

    #[Test]
    public function an_admin_impersonates_a_teacher_then_returns(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();

        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/impersonate", [
            'category' => 'technical_assistance',
            'ticket_reference' => 'SUP-42',
            'note' => 'Apoio solicitado pelo professor.',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($teacher);
        $this->assertSame($admin->id, session('impersonator_id'));
        $started = $this->trail($organization, 'admin.impersonation_started');
        $this->assertSame('technical_assistance', $started->properties['category']);
        $this->assertSame('SUP-42', $started->properties['ticket_reference']);
        $this->assertSame('Apoio solicitado pelo professor.', $started->properties['note']);
        $this->assertNotEmpty($started->properties['support_access_id']);

        $this->post('/impersonate/stop')->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('impersonator_id'));
        $stopped = $this->trail($organization, 'admin.impersonation_stopped');
        $this->assertSame($started->properties['support_access_id'], $stopped->properties['support_access_id']);
    }

    #[Test]
    public function an_admin_is_never_impersonated(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $organization = $otherAdmin->personalOrganization();

        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/impersonate", [
            'category' => 'diagnosis',
        ])->assertForbidden();
    }

    #[Test]
    public function a_teacher_cannot_start_impersonation(): void
    {
        $target = User::factory()->create();
        $organization = $target->personalOrganization();

        $this->actingAs(User::factory()->create())->post("/admin/accounts/{$organization->ulid}/impersonate")->assertForbidden();
    }

    #[Test]
    public function a_platform_admin_with_individually_revoked_support_access_keeps_admin_access_but_cannot_impersonate(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill([
            'is_platform_admin' => true,
            'is_support_technician' => false,
        ])->save();
        $target = User::factory()->create();
        $organization = $target->personalOrganization();

        $this->actingAs($admin)->get('/admin')->assertOk();

        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/impersonate", [
            'category' => 'diagnosis',
        ])->assertForbidden();
    }

    #[Test]
    public function a_support_technician_without_platform_admin_access_cannot_start_impersonation(): void
    {
        $technician = User::factory()->create();
        $technician->forceFill(['is_support_technician' => true])->save();
        $target = User::factory()->create();
        $organization = $target->personalOrganization();

        $this->actingAs($technician)->post("/admin/accounts/{$organization->ulid}/impersonate", [
            'category' => 'diagnosis',
        ])->assertForbidden();
    }

    #[Test]
    public function category_is_required_before_impersonation_starts(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $organization = $target->personalOrganization();

        $this->actingAs($admin)
            ->postJson("/admin/accounts/{$organization->ulid}/impersonate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('impersonator_id'));
        $this->assertFalse($this->trailHas($organization, 'admin.impersonation_started'));
    }

    #[Test]
    public function ticket_reference_and_note_are_optional(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $organization = $target->personalOrganization();

        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/impersonate", [
            'category' => 'maintenance',
        ])->assertRedirect('/dashboard');

        $started = $this->trail($organization, 'admin.impersonation_started');
        $this->assertSame('maintenance', $started->properties['category']);
        $this->assertNull($started->properties['ticket_reference']);
        $this->assertNull($started->properties['note']);
    }

    #[Test]
    public function tenant_audits_during_impersonation_preserve_the_real_actor(): void
    {
        $admin = $this->admin();
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();

        $this->actingAs($teacher)->withSession(['impersonator_id' => $admin->id]);

        $event = app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): AuditEvent => app(AuditLog::class)->record('test.impersonated_action', $organization),
        );

        $this->assertSame($admin->id, $event->causer_id);
        $this->assertSame($teacher->id, $event->properties['acting_as_user_id']);
    }

    private function trailHas(Organization $organization, string $event): bool
    {
        return app(CurrentOrganization::class)->runFor($organization, fn () => AuditEvent::where('event', $event)->exists());
    }

    private function trail(Organization $organization, string $event): AuditEvent
    {
        return app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): AuditEvent => AuditEvent::where('event', $event)->sole(),
        );
    }
}
