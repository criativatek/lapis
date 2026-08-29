<?php

namespace Tests\Feature\Billing;

use App\Actions\Commercial\RequestBankTransferPayment;
use App\Mail\BankTransferInstructionsMail;
use App\Models\BillingProfile;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O checkout por transferência, do lado de quem compra.
 *
 * A linha condutora: **este caminho não aprovisiona nada**. Recolhe a quem se
 * passa a fatura, devolve uma referência, e deixa a conta exactamente no plano
 * em que estava. Tudo o que estes testes fixam é isso e o que o protege — que
 * um segundo clique não gera uma segunda referência, que quem não é dono não
 * compra em nome da conta, e que sem IBAN configurado ninguém chega a um ecrã
 * com o campo em branco.
 */
class BankTransferCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.bank_transfer.enabled' => true,
            'billing.bank_transfer.iban' => 'PT50000000000000000000000',
            'billing.bank_transfer.beneficiary' => 'Criativatek',
            'billing.prices.pro' => 4490,
            'billing.founder.price_cents' => 2990,
            'billing.founder.seats' => 250,
            'billing.founder.deadline' => '2026-12-31',
        ]);

        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    /** @return array{User, Organization} */
    protected function owner(): array
    {
        $user = User::factory()->create();

        return [$user, $user->personalOrganization()];
    }

    /** @return array<string, string> */
    protected function billingData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Escola de Alvalade',
            'tax_number' => '501234567',
            'address_line1' => 'Rua das Escolas, 12',
            'postal_code' => '1700-001',
            'city' => 'Lisboa',
            'country' => 'PT',
            'email' => 'faturacao@exemplo.pt',
        ], $overrides);
    }

    protected function paymentOf(Organization $organization): ?SubscriptionPayment
    {
        return SubscriptionPayment::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->latest('id')
            ->first();
    }

    // ------------------------------------------------------------------ o ecrã

    #[Test]
    public function the_owner_sees_the_price_and_the_form(): void
    {
        [$user] = $this->owner();

        $this->actingAs($user)->get('/settings/plan/checkout')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Checkout')
                ->where('plan.key', 'pro')
                ->where('bankTransferReady', true));
    }

    /**
     * Comprar é acto do dono. Um professor que se juntou a um agrupamento não
     * subscreve em nome dele: a fatura sai com o NIF da instituição.
     */
    #[Test]
    public function a_member_who_does_not_own_the_account_cannot_buy_for_it(): void
    {
        [, $organization] = $this->owner();
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);

        $this->actingAs($member)->withSession(['organization_id' => $organization->getKey()])
            ->get('/settings/plan/checkout')
            ->assertForbidden();
    }

    /**
     * Sem IBAN, um botão levaria a um ecrã com o campo em branco — e o cliente
     * julgaria ter transferido para lado nenhum.
     */
    #[Test]
    public function without_bank_details_configured_nothing_is_offered(): void
    {
        config(['billing.bank_transfer.iban' => null]);
        [$user] = $this->owner();

        $this->actingAs($user)->get('/settings/plan/checkout')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('bankTransferReady', false));

        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData())
            ->assertSessionHasErrors('checkout');

        $this->assertNull($this->paymentOf($user->personalOrganization()));
    }

    // -------------------------------------------------------------- o pedido

    #[Test]
    public function it_records_a_pending_request_and_the_billing_details(): void
    {
        Mail::fake();
        [$user, $organization] = $this->owner();

        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData())
            ->assertRedirect();

        $payment = $this->paymentOf($organization);

        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(PaymentMethod::BankTransfer, $payment->method);
        $this->assertSame(RequestBankTransferPayment::PROVIDER, $payment->provider);
        $this->assertMatchesRegularExpression('/^LPRO-[A-Z2-9]{6}$/', (string) $payment->provider_reference);

        // Uma intenção declarada por quem compra, não dinheiro que um operador
        // recebeu: sem data de pagamento e sem ninguém a tê-lo registado.
        $this->assertNull($payment->paid_at);
        $this->assertNull($payment->recorded_by);

        $profile = BillingProfile::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->firstOrFail();

        $this->assertSame('Escola de Alvalade', $profile->name);
        $this->assertSame('501234567', $profile->tax_number);
    }

    /** O ponto todo deste caminho: pedir não é receber, e receber não é ativar. */
    #[Test]
    public function requesting_does_not_change_the_plan(): void
    {
        Mail::fake();
        [$user, $organization] = $this->owner();

        $antes = app(ChangeOrganizationPlan::class)->inForce($organization)?->plan?->key;

        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData());

        $depois = app(ChangeOrganizationPlan::class)->inForce($organization->fresh())?->plan?->key;

        $this->assertSame($antes, $depois);
        $this->assertNotSame('pro', $depois);
    }

    /**
     * Duas referências para a mesma compra é a forma mais rápida de ninguém
     * saber o que foi pago.
     */
    #[Test]
    public function a_second_attempt_reuses_the_same_reference(): void
    {
        Mail::fake();
        [$user, $organization] = $this->owner();

        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData());
        $primeira = $this->paymentOf($organization)->provider_reference;

        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData(['city' => 'Porto']));

        $pagamentos = SubscriptionPayment::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->get();

        $this->assertCount(1, $pagamentos);
        $this->assertSame($primeira, $pagamentos->first()->provider_reference);

        // Mas os dados de faturação actualizam-se, que é a razão de alguém
        // voltar ao formulário.
        $this->assertSame('Porto', BillingProfile::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->firstOrFail()->city);
    }

    #[Test]
    public function the_instructions_are_emailed_to_the_billing_address(): void
    {
        Mail::fake();
        [$user] = $this->owner();

        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData());

        Mail::assertSent(BankTransferInstructionsMail::class, fn ($mail) => $mail->hasTo('faturacao@exemplo.pt'));
    }

    // --------------------------------------------------------------- validação

    #[Test]
    public function a_portuguese_tax_number_must_have_nine_digits(): void
    {
        [$user] = $this->owner();

        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData(['tax_number' => '12345']))
            ->assertSessionHasErrors('tax_number');
    }

    /** Opcional é opcional: um particular pode pedir fatura sem contribuinte. */
    #[Test]
    public function the_tax_number_may_be_left_out(): void
    {
        Mail::fake();
        [$user, $organization] = $this->owner();

        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData(['tax_number' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull(BillingProfile::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->firstOrFail()->tax_number);
    }

    // ----------------------------------------------------------- condição

    #[Test]
    public function the_founder_price_applies_while_seats_remain(): void
    {
        Mail::fake();
        [$user, $organization] = $this->owner();

        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData());

        $payment = $this->paymentOf($organization);

        $this->assertSame(2990, $payment->amount_cents);
        $this->assertSame(CommercialCondition::Founder, $payment->commercial_condition);
    }

    /**
     * Esgotados os lugares, o preço volta ao normal — e a condição registada
     * deixa de ser Fundador, porque não é.
     *
     * O LUGAR OCUPADO É AGORA UM LUGAR, E NÃO UMA SUBSCRIÇÃO MARCADA À MÃO.
     * Este teste enchia a lotação criando uma subscrição com
     * `commercial_condition = founder`, porque era isso que o contador antigo
     * lia — uma população que nenhum fluxo escrevia, e que por isso o deixava
     * em «restam 250» para sempre em produção. Agora o lugar toma-se pelo
     * checkout, e é pelo checkout que este teste o enche: o primeiro comprador
     * leva o único lugar que há, o segundo paga tabela.
     */
    #[Test]
    public function once_the_seats_are_gone_the_standard_price_applies(): void
    {
        Mail::fake();
        config(['billing.founder.seats' => 1]);

        [$primeiro] = $this->owner();
        $this->actingAs($primeiro)->post('/settings/plan/checkout', $this->billingData());

        [$user, $organization] = $this->owner();
        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData());

        $payment = $this->paymentOf($organization);

        $this->assertSame(4490, $payment->amount_cents);
        $this->assertSame(CommercialCondition::Standard, $payment->commercial_condition);
    }

    /** Passado o prazo, a condição fecha mesmo com lugares por ocupar. */
    #[Test]
    public function the_deadline_closes_the_founder_condition(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2027-01-01 00:01'));

        [$user, $organization] = $this->owner();
        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData());

        $this->assertSame(4490, $this->paymentOf($organization)->amount_cents);
    }

    // ------------------------------------------------- a intenção sobrevive

    /*
     * O passo em que se desistia.
     *
     * O botão «Escolher Pro» da página pública aponta para o checkout, e não
     * para o registo: assim o `Authenticate` guarda o destino em sessão. Sem
     * as respostas do Fortify a lerem-no, quem entrava aterrava em
     * `/dashboard` — um painel com dezoito entradas de menu e nenhuma pista
     * sobre onde se subscreve.
     */
    #[Test]
    public function a_guest_sent_to_the_checkout_arrives_there_after_signing_in(): void
    {
        [$user] = $this->owner();

        $this->get('/settings/plan/checkout')->assertRedirect('/login');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/settings/plan/checkout');
    }

    /** Sem destino guardado, entrar continua a levar ao painel. */
    #[Test]
    public function signing_in_without_an_intent_still_lands_on_the_dashboard(): void
    {
        [$user] = $this->owner();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(config('fortify.home'));
    }

    /**
     * Quem se REGISTA só volta à aplicação depois de confirmar o email, muitas
     * vezes noutro dispositivo. O destino tem de sobreviver a essa ida à caixa
     * de correio — consumi-lo no registo deitava-o fora antes de servir.
     */
    #[Test]
    public function the_intent_survives_the_email_verification_round_trip(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get('/settings/plan/checkout')->assertRedirect('/login');
        $this->actingAs($user);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->email)],
        );

        $this->get($url)->assertRedirect('/settings/plan/checkout');
    }

    // ----------------------------------------------------------- isolamento

    #[Test]
    public function one_account_never_sees_another_accounts_request(): void
    {
        Mail::fake();
        [$user, $organization] = $this->owner();
        $this->actingAs($user)->post('/settings/plan/checkout', $this->billingData());
        $alheio = $this->paymentOf($organization);

        [$intruso] = $this->owner();

        $this->actingAs($intruso)->get('/settings/plan/checkout/'.$alheio->ulid)
            ->assertNotFound();
    }
}
