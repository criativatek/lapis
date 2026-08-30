<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBenefitType;
use App\Support\Commercial\VoucherCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Admin > Comercial > Vouchers: emitir e desactivar, com autoria — e nada mais,
 * porque um voucher emitido é imutável.
 */
class VoucherAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.currency' => 'EUR', 'billing.reference_prefix' => 'LPRO']);
        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    protected function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    #[Test]
    public function the_vouchers_area_is_for_platform_admins_only(): void
    {
        $teacher = User::factory()->create();
        $teacher->personalOrganization();

        $this->actingAs($teacher)->get('/admin/commercial/vouchers')->assertForbidden();
        $this->actingAs($teacher)->post('/admin/commercial/vouchers', [])->assertForbidden();
    }

    #[Test]
    public function issuing_generates_a_code_when_none_is_given_and_audits_the_act(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/commercial/vouchers', [
            'label' => 'Campanha formação',
            'benefit_type' => 'percent_discount',
            'benefit_percent' => 30,
            'max_redemptions' => 25,
        ])->assertRedirect();

        $voucher = Voucher::query()->sole();
        $this->assertSame(VoucherBenefitType::PercentDiscount, $voucher->benefit_type);
        $this->assertSame(30, $voucher->benefit_percent);
        $this->assertSame(25, $voucher->max_redemptions);
        $this->assertSame($admin->getKey(), $voucher->created_by);
        // Gerado: prefixo da instalação + 3 grupos de 4, no alfabeto sem O/0/I/1.
        $this->assertMatchesRegularExpression('/^LPRO-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/', $voucher->code);
        $this->assertSame(VoucherCode::normalize($voucher->code), $voucher->normalized_code);

        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')->where('event', 'commercial.voucher_issued')->count());
    }

    #[Test]
    public function a_custom_code_must_be_well_formed_and_unique(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/commercial/vouchers', [
            'label' => 'Parceiro', 'benefit_type' => 'percent_discount', 'benefit_percent' => 20,
            'code' => 'ANPRI-2026',
        ])->assertRedirect();

        // O mesmo código escrito de outra maneira é O MESMO código.
        $this->actingAs($admin)->post('/admin/commercial/vouchers', [
            'label' => 'Parceiro outra vez', 'benefit_type' => 'percent_discount', 'benefit_percent' => 20,
            'code' => 'anpri 2026',
        ])->assertSessionHasErrors('code');

        $this->actingAs($admin)->post('/admin/commercial/vouchers', [
            'label' => 'Sem forma', 'benefit_type' => 'percent_discount', 'benefit_percent' => 20,
            'code' => '!!',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Voucher::query()->count());
    }

    #[Test]
    public function each_family_demands_exactly_its_own_fields(): void
    {
        $admin = $this->admin();

        // percent_discount sem percentagem.
        $this->actingAs($admin)->post('/admin/commercial/vouchers', [
            'label' => 'x', 'benefit_type' => 'percent_discount',
        ])->assertSessionHasErrors('benefit_percent');

        // fixed_price sem quantia.
        $this->actingAs($admin)->post('/admin/commercial/vouchers', [
            'label' => 'x', 'benefit_type' => 'fixed_price',
        ])->assertSessionHasErrors('benefit_amount_cents');

        // free_until sem data.
        $this->actingAs($admin)->post('/admin/commercial/vouchers', [
            'label' => 'x', 'benefit_type' => 'free_until',
        ])->assertSessionHasErrors('benefit_free_until');

        // Campo da família errada: proibido, não ignorado.
        $this->actingAs($admin)->post('/admin/commercial/vouchers', [
            'label' => 'x', 'benefit_type' => 'percent_discount', 'benefit_percent' => 30,
            'benefit_amount_cents' => 1000,
        ])->assertSessionHasErrors('benefit_amount_cents');

        $this->assertSame(0, Voucher::query()->count());
    }

    #[Test]
    public function a_voucher_can_be_restricted_to_a_plan_by_configuration_not_by_code(): void
    {
        $admin = $this->admin();
        $pro = Plan::where('key', 'pro')->firstOrFail();

        $this->actingAs($admin)->post('/admin/commercial/vouchers', [
            'label' => 'Só Pro', 'benefit_type' => 'percent_discount', 'benefit_percent' => 30,
            'plan_id' => $pro->getKey(),
        ])->assertRedirect();

        $this->assertSame($pro->getKey(), Voucher::query()->sole()->plan_id);
    }

    #[Test]
    public function disabling_is_the_only_correction_and_leaves_its_trail(): void
    {
        $admin = $this->admin();

        $voucher = Voucher::create([
            'code' => 'LPRO-ERRO-1234', 'label' => 'Enganado',
            'benefit_type' => VoucherBenefitType::PercentDiscount, 'benefit_percent' => 99,
        ]);

        $this->actingAs($admin)
            ->post("/admin/commercial/vouchers/{$voucher->getKey()}/disable")
            ->assertRedirect();

        $voucher->refresh();
        $this->assertTrue($voucher->isDisabled());
        $this->assertSame($admin->getKey(), $voucher->disabled_by);
        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')->where('event', 'commercial.voucher_disabled')->count());

        // Idempotente: desactivar outra vez não reescreve a autoria nem a data.
        $antes = $voucher->disabled_at;
        $this->travel(1)->day();
        $this->actingAs($admin)
            ->post("/admin/commercial/vouchers/{$voucher->getKey()}/disable")
            ->assertRedirect();
        $this->assertTrue($antes->equalTo($voucher->fresh()->disabled_at));
    }

    #[Test]
    public function the_listing_shows_usage_derived_from_live_rows(): void
    {
        $admin = $this->admin();

        Voucher::create([
            'code' => 'LPRO-VIVO-0001', 'label' => 'Vivo',
            'benefit_type' => VoucherBenefitType::PercentDiscount, 'benefit_percent' => 30,
            'max_redemptions' => 5,
        ]);

        $this->withoutVite();

        $this->actingAs($admin)->get('/admin/commercial/vouchers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/Vouchers')
                ->has('vouchers', 1)
                ->where('vouchers.0.code', 'LPRO-VIVO-0001')
                ->where('vouchers.0.maxRedemptions', 5)
                ->where('vouchers.0.confirmedCount', 0)
                ->where('vouchers.0.reservedCount', 0));
    }
}
