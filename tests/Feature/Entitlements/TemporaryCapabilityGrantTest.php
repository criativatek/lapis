<?php

namespace Tests\Feature\Entitlements;

use App\Actions\Entitlements\GrantCapabilitiesDirectly;
use App\Actions\Entitlements\RevokeCapabilityGrant;
use App\Models\CapabilityGrant;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;

class TemporaryCapabilityGrantTest extends TestCase
{
    use RefreshDatabase, SubscribesOrganizations;

    private User $operator;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-03 10:00'));
        $this->operator = User::factory()->create();
        $this->operator->forceFill(['is_platform_admin' => true])->save();
        $this->organization = User::factory()->create()->personalOrganization();
        $this->subscribeOrganizationTo($this->organization, 'base');
    }

    private function grant(array $keys = ['calendar_import'], int $days = 10, ?CarbonInterface $starts = null): CapabilityGrant
    {
        return app(GrantCapabilitiesDirectly::class)->handle($this->organization, $this->operator, $keys, $days, 'Apoio temporário', $starts);
    }

    private function allows(string $key): bool
    {
        app(Entitlements::class)->flush();

        return app(Entitlements::class)->allowsFor($this->organization, $key);
    }

    private function reload(CapabilityGrant $grant): CapabilityGrant
    {
        return CapabilityGrant::withoutGlobalScope('organization')->findOrFail($grant->getKey());
    }

    #[Test]
    public function base_stays_locked_without_a_grant(): void
    {
        $this->assertFalse($this->allows('calendar_import'));
    }

    #[Test]
    public function an_in_force_direct_grant_allows_without_changing_plan_version(): void
    {
        $before = OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $this->organization->getKey())->value('plan_version_id');
        $this->grant();
        $this->assertTrue($this->allows('calendar_import'));
        $this->assertSame($before, OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $this->organization->getKey())->value('plan_version_id'));
    }

    #[Test]
    public function dates_alone_start_and_expire_access(): void
    {
        $this->grant(days: 2, starts: now()->addDay());
        $this->assertFalse($this->allows('calendar_import'));
        $this->travel(1)->day();
        $this->assertTrue($this->allows('calendar_import'));
        $this->travel(2)->days();
        $this->assertFalse($this->allows('calendar_import'));
    }

    #[Test]
    public function revocation_is_immediate_and_idempotent(): void
    {
        $grant = $this->grant();
        $action = app(RevokeCapabilityGrant::class);
        $action->handle($grant, $this->operator);
        $first = $this->reload($grant)->revoked_at;
        $action->handle($this->reload($grant), $this->operator);
        $this->assertFalse($this->allows('calendar_import'));
        $this->assertTrue($first->equalTo($this->reload($grant)->revoked_at));
    }

    #[Test]
    public function an_explicit_disabled_override_wins(): void
    {
        $grant = $this->grant();
        app(CurrentOrganization::class)->runFor($this->organization, fn () => OrganizationModuleOverride::create(['module_id' => Module::where('key', 'calendar_import')->value('id'), 'enabled' => false, 'reason' => 'Bloqueio explícito']));
        $this->assertFalse($this->allows('calendar_import'));
        $this->assertNotNull($grant);
    }

    #[Test]
    public function another_organization_does_not_inherit_access(): void
    {
        $this->grant();
        $other = User::factory()->create()->personalOrganization();
        $this->subscribeOrganizationTo($other, 'base');
        app(Entitlements::class)->flush();
        $this->assertFalse(app(Entitlements::class)->allowsFor($other, 'calendar_import'));
    }

    #[Test]
    public function one_multi_capability_grant_revokes_both(): void
    {
        $grant = $this->grant(['calendar_import', 'lessons']);
        $this->assertTrue($this->allows('calendar_import'));
        $this->assertTrue($this->allows('lessons'));
        app(RevokeCapabilityGrant::class)->handle($grant, $this->operator);
        $this->assertFalse($this->allows('calendar_import'));
        $this->assertFalse($this->allows('lessons'));
    }

    #[Test]
    public function overlapping_grants_allow_until_all_are_inactive(): void
    {
        $first = $this->grant(days: 2);
        $second = $this->grant(days: 5);
        $this->travel(3)->days();
        $this->assertTrue($this->allows('calendar_import'));
        app(RevokeCapabilityGrant::class)->handle($this->reload($second), $this->operator);
        $this->assertFalse($this->allows('calendar_import'));
        $this->assertNotNull($first);
    }
}
