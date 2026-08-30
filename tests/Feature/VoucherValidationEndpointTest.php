<?php

namespace Tests\Feature;

use App\Models\Voucher;
use App\Models\VoucherBenefitType;
use App\Models\VoucherRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O endpoint público da landing: responde a verdade, em três categorias, e
 * nunca o suficiente para enumerar códigos — nem para saber quanto valem.
 */
class VoucherValidationEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    protected function percentVoucher(array $overrides = []): Voucher
    {
        return Voucher::create(array_merge([
            'code' => 'LPRO-PUBL-2026',
            'label' => 'Campanha',
            'benefit_type' => VoucherBenefitType::PercentDiscount,
            'benefit_percent' => 30,
        ], $overrides));
    }

    #[Test]
    public function a_real_code_is_confirmed_without_revealing_what_it_is_worth(): void
    {
        $this->percentVoucher();

        $response = $this->postJson('/voucher/validate', ['code' => 'lpro publ 2026'])
            ->assertOk()
            ->assertJson(['category' => 'valid']);

        // Nem a percentagem, nem cêntimos, nem o tipo: o benefício mostra-se a
        // quem resgata, autenticado.
        $body = $response->getContent();
        $this->assertStringNotContainsString('30', preg_replace('/LPRO-PUBL-2026/', '', $body));
        $this->assertStringNotContainsString('percent', $body);
        $this->assertStringNotContainsString('cents', $body);
    }

    #[Test]
    public function a_priced_code_points_at_the_checkout_and_a_free_until_code_at_the_plan_page(): void
    {
        $this->percentVoucher();
        Voucher::create([
            'code' => 'LPRO-GRAT-2027', 'label' => 'Piloto',
            'benefit_type' => VoucherBenefitType::FreeUntil,
            'benefit_free_until' => Carbon::parse('2027-08-31'),
        ]);

        $this->postJson('/voucher/validate', ['code' => 'LPRO-PUBL-2026'])
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'no checkout'));

        $this->postJson('/voucher/validate', ['code' => 'LPRO-GRAT-2027'])
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'na página do plano'));
    }

    #[Test]
    public function nonexistent_disabled_and_malformed_are_indistinguishable_outside(): void
    {
        $desligado = $this->percentVoucher(['code' => 'LPRO-OFF-2026']);
        $desligado->disabled_at = Carbon::now();
        $desligado->save();

        $respostas = collect(['LPRO-NADA-AQUI', 'LPRO-OFF-2026'])
            ->map(fn (string $code) => $this->postJson('/voucher/validate', ['code' => $code])->json());

        // Mesma categoria, mesma mensagem: nada distingue «nunca existiu» de
        // «foi desactivado» para quem anda a adivinhar.
        $this->assertSame('invalid', $respostas[0]['category']);
        $this->assertSame($respostas[0], $respostas[1]);
    }

    #[Test]
    public function an_expired_or_exhausted_code_is_told_so_because_its_holder_deserves_it(): void
    {
        $this->percentVoucher(['code' => 'LPRO-VELH-2025', 'valid_until' => Carbon::now()->subDay()]);

        $this->postJson('/voucher/validate', ['code' => 'LPRO-VELH-2025'])
            ->assertOk()
            ->assertJson(['category' => 'expired']);
    }

    #[Test]
    public function the_endpoint_is_throttled_against_enumeration(): void
    {
        RateLimiter::clear('');

        foreach (range(1, 10) as $i) {
            $this->postJson('/voucher/validate', ['code' => 'LPRO-TENT-'.$i])->assertOk();
        }

        $this->postJson('/voucher/validate', ['code' => 'LPRO-TENT-11'])->assertStatus(429);
    }

    #[Test]
    public function it_never_redeems_or_writes_anything(): void
    {
        $this->percentVoucher();

        $this->postJson('/voucher/validate', ['code' => 'LPRO-PUBL-2026'])->assertOk();

        $this->assertSame(0, VoucherRedemption::query()->count());
    }
}
