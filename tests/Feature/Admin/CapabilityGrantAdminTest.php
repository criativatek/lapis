<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\CapabilityGrant;
use App\Models\CapabilityVoucher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CapabilityGrantAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    #[Test]
    public function only_platform_admins_reach_direct_grants(): void
    {
        $owner = User::factory()->create();
        $organization = $owner->personalOrganization();
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);
        $payload = ['module_keys' => ['calendar_import'], 'duration_days' => 5, 'reason' => 'Teste'];
        $this->actingAs($owner)->post("/admin/accounts/{$organization->ulid}/capability-grants", $payload)->assertForbidden();
        $this->actingAs($member)->post("/admin/accounts/{$organization->ulid}/capability-grants", $payload)->assertForbidden();
    }

    #[Test]
    public function direct_grant_requires_and_stores_reason_and_can_be_revoked_idempotently(): void
    {
        $admin = $this->admin();
        $organization = User::factory()->create()->personalOrganization();
        $url = "/admin/accounts/{$organization->ulid}/capability-grants";
        $this->actingAs($admin)->post($url, ['module_keys' => ['calendar_import'], 'duration_days' => 5])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post($url, ['module_keys' => ['calendar_import'], 'duration_days' => 5, 'reason' => 'Incidente acompanhado'])->assertRedirect();
        $grant = CapabilityGrant::withoutGlobalScope('organization')->sole();
        $this->assertSame('Incidente acompanhado', $grant->reason);
        $revoke = "{$url}/{$grant->ulid}/revoke";
        $this->actingAs($admin)->post($revoke)->assertRedirect();
        $first = CapabilityGrant::withoutGlobalScope('organization')->findOrFail($grant->getKey())->revoked_at;
        $this->actingAs($admin)->post($revoke)->assertRedirect();
        $current = CapabilityGrant::withoutGlobalScope('organization')->findOrFail($grant->getKey());
        $this->assertTrue($first->equalTo($current->revoked_at));
        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')->where('event', 'capability.grant_issued')->count());
        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')->where('event', 'capability.grant_revoked')->count());
    }

    #[Test]
    public function presets_and_vouchers_are_platform_only_and_audited(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/capabilities/presets', ['key' => 'calendar-pack', 'name' => 'Calendário', 'default_duration_days' => 10, 'module_keys' => ['calendar_import']])->assertRedirect();
        $this->actingAs($admin)->post('/admin/capabilities/vouchers', ['label' => 'Formação', 'duration_days' => 10, 'module_keys' => ['calendar_import']])->assertRedirect();
        $voucher = CapabilityVoucher::query()->sole();
        $this->actingAs($admin)->post("/admin/capabilities/vouchers/{$voucher->ulid}/disable")->assertRedirect();
        foreach (['capability.preset_created', 'capability.voucher_issued', 'capability.voucher_disabled'] as $event) {
            $this->assertDatabaseHas('audit_events', ['event' => $event, 'causer_id' => $admin->getKey()]);
        }
    }
}
