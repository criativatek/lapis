<?php

namespace Tests\Feature\Settings;

use App\Actions\Entitlements\GenerateCapabilityVoucher;
use App\Models\CapabilityGrant;
use App\Models\CapabilityGrantSource;
use App\Models\CapabilityVoucher;
use App\Models\CommercialCondition;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBenefitType;
use App\Models\VoucherRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O campo único "Tem um código?" (`POST /settings/plan/code`) — resolve por
 * EXISTÊNCIA para que universo um código pertence, sem duplicar a lógica
 * interna de nenhum dos dois sistemas (`Vouchers`/`RedeemVoucher` para o
 * comercial, `RedeemCapabilityVoucher` para as capacidades).
 */
class RedeemUnifiedCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.currency' => 'EUR', 'billing.prices.pro' => 4490]);
        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    /** @return array{User, Organization} */
    protected function owner(): array
    {
        $user = User::factory()->create();

        return [$user, $user->personalOrganization()];
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    /** @param array<string, mixed> $overrides */
    protected function freeUntilVoucher(array $overrides = []): Voucher
    {
        return Voucher::create(array_merge([
            'code' => 'LPRO-GRAT-2027',
            'label' => 'Piloto gratuito',
            'benefit_type' => VoucherBenefitType::FreeUntil,
            'benefit_free_until' => Carbon::parse('2027-08-31')->endOfDay(),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    protected function capabilityVoucher(array $overrides = []): CapabilityVoucher
    {
        return app(GenerateCapabilityVoucher::class)->handle(
            operator: $this->admin(),
            label: $overrides['label'] ?? 'Formação',
            durationDays: $overrides['durationDays'] ?? 30,
            moduleKeys: $overrides['moduleKeys'] ?? ['calendar_import', 'lessons'],
            code: $overrides['code'] ?? 'LPRO-CAPA-0001',
            validFrom: $overrides['validFrom'] ?? null,
            validUntil: $overrides['validUntil'] ?? null,
            maxRedemptions: $overrides['maxRedemptions'] ?? null,
            restrictedOrganizationId: $overrides['restrictedOrganizationId'] ?? null,
        );
    }

    /**
     * A direct grant, written straight to the row (not through the admin
     * endpoint, which is exercised elsewhere) — `organization_id` is not
     * mass-assignable, it is stamped by `BelongsToOrganization` when unset,
     * so a grant for another organization than the one currently resolved
     * has to be force-filled instead of created.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function directGrant(array $attributes): CapabilityGrant
    {
        $grant = new CapabilityGrant;
        $grant->forceFill($attributes)->save();

        return $grant;
    }

    protected function redeem(User $user, string $code): TestResponse
    {
        return $this->actingAs($user)->post('/settings/plan/code', ['code' => $code]);
    }

    #[Test]
    public function a_valid_commercial_code_still_changes_the_plan(): void
    {
        [$user, $organization] = $this->owner();
        $this->freeUntilVoucher();

        $this->redeem($user, 'lpro grat 2027')->assertRedirect('/settings/plan');

        $subscription = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('pro', $subscription->plan->key);
        $this->assertSame(CommercialCondition::Voucher, $subscription->commercial_condition);
        $this->assertSame('2027-08-31', $subscription->commercial_term_ends_at->toDateString());
    }

    #[Test]
    public function a_valid_capability_code_still_creates_a_grant_without_touching_the_plan(): void
    {
        [$user, $organization] = $this->owner();
        $this->capabilityVoucher();

        $planNameBefore = $this->planNameFor($organization);

        $this->redeem($user, 'LPRO-CAPA-0001')->assertRedirect('/settings/plan');

        $grant = CapabilityGrant::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->sole();

        $this->assertSame(['calendar_import', 'lessons'], $grant->modules()->orderBy('key')->pluck('key')->all());
        $this->assertSame($planNameBefore, $this->planNameFor($organization));
    }

    /** @return array<string, mixed> */
    protected function planProps(User $user): array
    {
        $props = [];

        $this->actingAs($user)->get('/settings/plan')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    protected function planNameFor(Organization $organization): ?string
    {
        return $this->planProps($organization->owner)['currentPlanName'] ?? null;
    }

    #[Test]
    public function the_field_routes_to_the_right_system_depending_on_the_code(): void
    {
        [$commercialUser, $commercialOrganization] = $this->owner();
        $this->freeUntilVoucher();
        $this->redeem($commercialUser, 'LPRO-GRAT-2027')->assertRedirect('/settings/plan');
        $this->assertSame(1, VoucherRedemption::query()->count());
        $this->assertSame(0, CapabilityGrant::withoutGlobalScope('organization')->count());

        [$capabilityUser] = $this->owner();
        $this->capabilityVoucher();
        $this->redeem($capabilityUser, 'LPRO-CAPA-0001')->assertRedirect('/settings/plan');
        $this->assertSame(1, VoucherRedemption::query()->count());
        $this->assertSame(1, CapabilityGrant::withoutGlobalScope('organization')->count());
    }

    #[Test]
    public function an_unknown_code_gets_the_generic_message(): void
    {
        [$user] = $this->owner();

        $this->redeem($user, 'NAO-EXISTE-0000')
            ->assertSessionHasErrors(['code' => 'Este código não é válido ou já não está disponível.']);
    }

    #[Test]
    public function an_expired_commercial_code_gets_the_specific_expired_message(): void
    {
        [$user] = $this->owner();
        $this->freeUntilVoucher(['valid_until' => Carbon::parse('2026-01-01')]);

        $this->redeem($user, 'LPRO-GRAT-2027')
            ->assertSessionHasErrors(['code' => 'Este código já expirou.']);
    }

    #[Test]
    public function a_capability_code_already_redeemed_by_this_organization_gets_the_specific_message(): void
    {
        [$user] = $this->owner();
        $this->capabilityVoucher();

        $this->redeem($user, 'LPRO-CAPA-0001')->assertRedirect('/settings/plan');
        $this->redeem($user, 'LPRO-CAPA-0001')
            ->assertSessionHasErrors(['code' => 'Esta organização já resgatou este código.']);
    }

    #[Test]
    public function a_capability_code_restricted_to_another_organization_gets_a_specific_message_without_naming_it(): void
    {
        [$user] = $this->owner();
        $otherOrganization = User::factory()->create()->personalOrganization();
        $this->capabilityVoucher(['restrictedOrganizationId' => $otherOrganization->getKey()]);

        $this->redeem($user, 'LPRO-CAPA-0001')
            ->assertSessionHasErrors(['code' => 'Este código pertence a outra organização.']);
    }

    #[Test]
    public function a_code_existing_in_both_tables_resolves_to_capability_not_commercial(): void
    {
        [$user, $organization] = $this->owner();

        // Escrito directamente, contornando os geradores (que agora recusam
        // esta colisão na emissão — ver as verificações cruzadas) — não devia
        // acontecer em produção, mas se acontecer, capability tem sempre
        // precedência.
        Voucher::create([
            'code' => 'COLIDE-0001',
            'label' => 'Comercial colidente',
            'benefit_type' => VoucherBenefitType::FreeUntil,
            'benefit_free_until' => Carbon::parse('2027-08-31')->endOfDay(),
        ]);
        $capabilityVoucher = CapabilityVoucher::create([
            'code' => 'COLIDE-0001',
            'label' => 'Capability colidente',
            'duration_days' => 30,
            'created_by' => $this->admin()->getKey(),
        ]);
        $capabilityVoucher->modules()->attach(Module::query()->where('key', 'calendar_import')->firstOrFail()->getKey());

        $this->redeem($user, 'COLIDE-0001')->assertRedirect('/settings/plan');

        $this->assertSame(0, VoucherRedemption::query()->count());
        $grant = CapabilityGrant::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->sole();
        $this->assertSame(CapabilityGrantSource::Voucher, $grant->source);
    }

    #[Test]
    public function the_old_routes_keep_working_exactly_as_before(): void
    {
        [$user, $organization] = $this->owner();
        $this->freeUntilVoucher();

        $this->actingAs($user)->post('/settings/plan/voucher', [
            'voucher_code' => 'LPRO-GRAT-2027',
            'plan_key' => 'pro',
        ])->assertRedirect('/settings/plan');

        $subscription = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->latest('id')->firstOrFail();
        $this->assertSame('pro', $subscription->plan->key);

        [$capabilityUser] = $this->owner();
        $this->capabilityVoucher(['code' => 'LPRO-CAPA-0002']);
        $this->actingAs($capabilityUser)->post('/settings/plan/capability-code', [
            'capability_code' => 'LPRO-CAPA-0002',
        ])->assertRedirect('/settings/plan');
    }

    #[Test]
    public function issuing_a_commercial_voucher_with_a_code_already_used_as_capability_is_refused(): void
    {
        $admin = $this->admin();
        $this->capabilityVoucher(['code' => 'LPRO-DUPL-0001']);

        $this->actingAs($admin)->post('/admin/commercial/vouchers', [
            'label' => 'Colisão',
            'benefit_type' => 'percent_discount',
            'benefit_percent' => 20,
            'code' => 'LPRO-DUPL-0001',
        ])->assertSessionHasErrors('code');

        $this->assertSame(0, Voucher::query()->count());
    }

    #[Test]
    public function issuing_a_capability_code_already_used_as_commercial_voucher_is_refused(): void
    {
        $this->freeUntilVoucher(['code' => 'LPRO-DUPL-0002']);

        try {
            $this->capabilityVoucher(['code' => 'LPRO-DUPL-0002']);
            $this->fail('Expected a validation refusal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $this->assertSame(0, CapabilityVoucher::query()->count());
    }

    #[Test]
    public function a_redeemed_benefit_appears_in_the_active_capability_benefits_prop(): void
    {
        [$user] = $this->owner();
        $this->capabilityVoucher();

        $this->redeem($user, 'LPRO-CAPA-0001')->assertRedirect('/settings/plan');

        $benefits = $this->planProps($user)['activeCapabilityBenefits'];
        $keys = array_column($benefits, 'moduleKey');
        $this->assertContains('calendar_import', $keys);
        $this->assertContains('lessons', $keys);
    }

    #[Test]
    public function an_expired_grant_does_not_appear(): void
    {
        [$user, $organization] = $this->owner();
        $admin = $this->admin();
        $expired = $this->directGrant([
            'organization_id' => $organization->getKey(),
            'source' => CapabilityGrantSource::Direct,
            'granted_by' => $admin->getKey(),
            'reason' => 'Teste',
            'starts_at' => Carbon::now()->subDays(10),
            'expires_at' => Carbon::now()->subDay(),
        ]);
        $expired->modules()->attach(Module::query()->where('key', 'calendar_import')->firstOrFail()->getKey());

        $benefits = $this->planProps($user)['activeCapabilityBenefits'];
        $this->assertSame([], $benefits);
    }

    #[Test]
    public function a_revoked_grant_does_not_appear(): void
    {
        [$user, $organization] = $this->owner();
        $admin = $this->admin();
        $grant = $this->directGrant([
            'organization_id' => $organization->getKey(),
            'source' => CapabilityGrantSource::Direct,
            'granted_by' => $admin->getKey(),
            'reason' => 'Teste',
            'starts_at' => Carbon::now()->subDay(),
            'expires_at' => Carbon::now()->addDays(10),
        ]);
        $grant->modules()->attach(Module::query()->where('key', 'calendar_import')->firstOrFail()->getKey());
        $grant->forceFill(['revoked_at' => Carbon::now(), 'revoked_by' => $admin->getKey()])->save();

        $benefits = $this->planProps($user)['activeCapabilityBenefits'];
        $this->assertSame([], $benefits);
    }

    #[Test]
    public function two_distinct_capabilities_appear_as_two_entries(): void
    {
        [$user] = $this->owner();
        $this->capabilityVoucher(['moduleKeys' => ['calendar_import', 'lessons']]);

        $this->redeem($user, 'LPRO-CAPA-0001')->assertRedirect('/settings/plan');

        $benefits = $this->planProps($user)['activeCapabilityBenefits'];
        $this->assertCount(2, $benefits);
    }

    #[Test]
    public function two_overlapping_active_grants_for_the_same_capability_collapse_into_one_entry_with_the_longer_expiry(): void
    {
        [$user, $organization] = $this->owner();
        $admin = $this->admin();
        $module = Module::query()->where('key', 'calendar_import')->firstOrFail();

        $shorter = $this->directGrant([
            'organization_id' => $organization->getKey(),
            'source' => CapabilityGrantSource::Direct,
            'granted_by' => $admin->getKey(),
            'reason' => 'Curto',
            'starts_at' => Carbon::now()->subDay(),
            'expires_at' => Carbon::now()->addDays(5),
        ]);
        $shorter->modules()->attach($module->getKey());

        $longer = $this->directGrant([
            'organization_id' => $organization->getKey(),
            'source' => CapabilityGrantSource::Direct,
            'granted_by' => $admin->getKey(),
            'reason' => 'Longo',
            'starts_at' => Carbon::now()->subDay(),
            'expires_at' => Carbon::now()->addDays(20),
        ]);
        $longer->modules()->attach($module->getKey());

        $benefits = $this->planProps($user)['activeCapabilityBenefits'];
        $this->assertCount(1, $benefits);
        $this->assertSame(
            Carbon::now()->addDays(20)->toIso8601String(),
            $benefits[0]['expiresAt'],
        );
    }
}
