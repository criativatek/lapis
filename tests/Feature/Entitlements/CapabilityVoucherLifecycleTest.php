<?php

namespace Tests\Feature\Entitlements;

use App\Actions\Entitlements\GenerateCapabilityVoucher;
use App\Actions\Entitlements\RedeemCapabilityVoucher;
use App\Models\CapabilityVoucher;
use App\Models\Organization;
use App\Models\User;
use App\Support\Entitlements\CapabilityVoucherUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CapabilityVoucherLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-03 10:00'));
        $this->operator = User::factory()->create();
        $this->operator->forceFill(['is_platform_admin' => true])->save();
        $this->owner = User::factory()->create();
        $this->organization = $this->owner->personalOrganization();
    }

    private function issue(array $overrides = []): CapabilityVoucher
    {
        return app(GenerateCapabilityVoucher::class)->handle(operator: $this->operator, label: 'Formação', durationDays: $overrides['durationDays'] ?? 7, moduleKeys: ['calendar_import', 'lessons'], code: $overrides['code'] ?? null, validFrom: $overrides['validFrom'] ?? null, validUntil: $overrides['validUntil'] ?? null, maxRedemptions: $overrides['maxRedemptions'] ?? null, restrictedOrganizationId: $overrides['restrictedOrganizationId'] ?? null);
    }

    #[Test]
    public function issue_and_redeem_copy_duration_and_capabilities_and_audit(): void
    {
        $voucher = $this->issue();
        $grant = app(RedeemCapabilityVoucher::class)->handle($voucher->code, $this->organization, $this->owner);
        $this->assertSame(['calendar_import', 'lessons'], $grant->modules()->orderBy('key')->pluck('key')->all());
        $this->assertTrue($grant->expires_at->equalTo(now()->addDays(7)));
        $this->assertDatabaseHas('capability_voucher_redemptions', ['capability_voucher_id' => $voucher->getKey(), 'organization_id' => $this->organization->getKey()]);
        $this->assertDatabaseHas('audit_events', ['event' => 'capability.voucher_issued', 'causer_id' => $this->operator->getKey()]);
        $this->assertDatabaseHas('audit_events', ['event' => 'capability.voucher_redeemed', 'causer_id' => $this->owner->getKey()]);
    }

    #[Test]
    public function redemption_window_is_enforced(): void
    {
        $future = $this->issue(['validFrom' => now()->addDay()]);
        $expired = $this->issue(['validUntil' => now()->subSecond()]);
        foreach ([$future, $expired] as $voucher) {
            try {
                app(RedeemCapabilityVoucher::class)->handle($voucher->code, $this->organization, $this->owner);
                $this->fail('Expected refusal.');
            } catch (CapabilityVoucherUnavailable) {
                $this->assertTrue(true);
            }
        }
    }

    #[Test]
    public function capacity_is_derived_under_the_voucher_lock(): void
    {
        $voucher = $this->issue(['maxRedemptions' => 1]);
        app(RedeemCapabilityVoucher::class)->handle($voucher->code, $this->organization, $this->owner);
        $other = User::factory()->create();
        $this->expectException(CapabilityVoucherUnavailable::class);
        app(RedeemCapabilityVoucher::class)->handle($voucher->code, $other->personalOrganization(), $other);
    }

    #[Test]
    public function disabled_restricted_and_duplicate_codes_are_rejected(): void
    {
        $voucher = $this->issue(['restrictedOrganizationId' => $this->organization->getKey()]);
        $other = User::factory()->create();
        $this->expectException(CapabilityVoucherUnavailable::class);
        app(RedeemCapabilityVoucher::class)->handle($voucher->code, $other->personalOrganization(), $other);
    }

    #[Test]
    public function duplicate_redemption_is_rejected_forever(): void
    {
        $voucher = $this->issue();
        $action = app(RedeemCapabilityVoucher::class);
        $action->handle($voucher->code, $this->organization, $this->owner);
        $this->expectException(CapabilityVoucherUnavailable::class);
        $action->handle($voucher->code, $this->organization, $this->owner);
    }

    #[Test]
    public function a_disabled_voucher_cannot_be_redeemed(): void
    {
        $voucher = $this->issue();
        $voucher->forceFill(['disabled_at' => now(), 'disabled_by' => $this->operator->getKey()])->save();
        $this->expectException(CapabilityVoucherUnavailable::class);
        app(RedeemCapabilityVoucher::class)->handle($voucher->code, $this->organization, $this->owner);
    }

    #[Test]
    public function only_the_organization_owner_can_use_the_redeem_endpoint(): void
    {
        $voucher = $this->issue();
        $member = User::factory()->create();
        $this->organization->members()->attach($member, ['joined_at' => now()]);
        $session = ['organization_id' => $this->organization->getKey()];
        $this->actingAs($member)->withSession($session)->post('/settings/plan/capability-code', ['capability_code' => $voucher->code])->assertForbidden();
        $this->actingAs($this->owner)->withSession($session)->post('/settings/plan/capability-code', ['capability_code' => $voucher->code])->assertRedirect('/settings/plan');
    }
}
