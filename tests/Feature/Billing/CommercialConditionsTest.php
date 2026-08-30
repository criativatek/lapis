<?php

namespace Tests\Feature\Billing;

use App\Actions\Commercial\ConfirmBankTransferRequest;
use App\Actions\Commercial\RequestBankTransferPayment;
use App\Actions\Organizations\ActivateProTrial;
use App\Models\BillingPeriod;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\PaymentStatus;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Organizations\ChangeOrganizationPlan;
use App\Support\Commercial\FounderSeats;
use App\Support\Entitlements\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AS PROMESSAS PÚBLICAS, ESCRITAS NA BASE DE DADOS.
 *
 * A 0.88.0 deu a `organization_subscriptions` quatro colunas de prova comercial
 * e nada que as escrevesse. Este ficheiro fixa o que passou a escrevê-las, e
 * fixa-o pelo caminho por onde o cliente passa — o registo, o checkout, a
 * confirmação do operador — e não chamando o resolvedor directamente, porque a
 * pergunta não é «o `CommercialTerms` sabe responder?» mas «o que é que fica
 * gravado quando alguém adere?».
 *
 * As três camadas continuam separadas, e é isso que a maior parte destes testes
 * está a proteger:
 *
 *  - `PlanVersion` = direitos funcionais;
 *  - o snapshot na subscrição = a condição comercial acordada;
 *  - `SubscriptionPayment` = dinheiro que entrou.
 *
 * Nenhuma delas se deduz das outras. Um Fundador tem exactamente os módulos de
 * um Pro normal; pagar 29,90 € não faz de ninguém fundador; e o fim de um termo
 * comercial não corta o acesso a ninguém.
 */
class CommercialConditionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.bank_transfer.enabled' => true,
            'billing.bank_transfer.iban' => 'PT50000000000000000000000',
            'billing.bank_transfer.window_days' => 14,
            'billing.prices.pro' => 4490,
            'billing.founder.price_cents' => 2990,
            'billing.founder.seats' => 250,
            'billing.founder.deadline' => '2026-12-31',
            'billing.promotions.free_base.enabled' => true,
            'billing.promotions.free_base.ends_at' => '2027-08-31',
        ]);

        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    // ============================================== 1. GRATUITO 2026/27

    #[Test]
    public function a_new_account_records_the_free_base_promotion(): void
    {
        $subscription = $this->currentOf($this->organization());

        $this->assertSame(CommercialCondition::Promotional, $subscription->commercial_condition);
        $this->assertSame(0, $subscription->contracted_price_cents);
        $this->assertSame('EUR', $subscription->contracted_currency);
        $this->assertSame(BillingPeriod::None, $subscription->billing_period);
        $this->assertSame('2027-08-31', $subscription->commercial_term_ends_at?->toDateString());
    }

    #[Test]
    public function zero_is_a_recorded_price_and_not_the_absence_of_one(): void
    {
        // A distinção que a coluna existe para guardar: NULL é «ninguém sabe»,
        // `0` é «alguém acordou explicitamente que isto não custa nada». Se as
        // duas colapsassem, cada conta de origem desconhecida passaria a
        // parecer uma adesão gratuita documentada.
        $subscription = $this->currentOf($this->organization());

        $this->assertNotNull($subscription->contracted_price_cents);
        $this->assertSame(0, $subscription->contracted_price_cents);
        $this->assertFalse(CommercialCondition::Promotional->normallyPaid());
    }

    #[Test]
    public function the_commercial_term_does_not_end_the_access(): void
    {
        // A frase que a ADR-0008 §8 recusa transformar em expiração automática:
        // «a condição promocional acabou» não é «a conta foi cortada».
        $organization = $this->organization();

        $this->travelTo(Carbon::parse('2027-09-01 10:00'));

        $subscription = $this->currentOf($organization);

        $this->assertTrue($subscription->commercial_term_ends_at?->isPast());
        $this->assertNull($subscription->ends_at, 'o acesso não tem fim marcado');
        $this->assertTrue($subscription->isInForce());

        app(Entitlements::class)->flush();
        $this->assertTrue(app(Entitlements::class)->allowsFor($organization->fresh(), 'classes'));
    }

    #[Test]
    public function nothing_at_all_happens_to_a_promotional_account_when_its_term_lapses(): void
    {
        // A POLÍTICA DE TRANSIÇÃO PÓS-2026/27 NÃO EXISTE, E NADA A SIMULA. Este
        // teste é o guarda contra alguém a construir por acidente: passada a
        // data, nem o acesso termina, nem há downgrade, nem cobrança, nem
        // renovação, nem mudança de plano, nem alteração de entitlement. A
        // subscrição fica exactamente como estava e a condição histórica fica
        // preservada, à espera de uma decisão comercial explícita.
        $organization = $this->organization();

        $antes = $this->currentOf($organization);
        $planoAntes = $antes->plan->key;
        $mapaAntes = app(Entitlements::class)->accessStatesFor($organization->fresh());

        $this->travelTo(Carbon::parse('2027-09-01 10:00'));
        app(Entitlements::class)->flush();

        $depois = $this->currentOf($organization);

        // A MESMA LINHA, não uma nova. Nada criou uma subscrição de substituição.
        $this->assertSame($antes->getKey(), $depois->getKey());
        $this->assertSame(1, OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->count());

        // A condição histórica sobrevive intacta ao fim do seu próprio termo.
        $this->assertSame(CommercialCondition::Promotional, $depois->commercial_condition);
        $this->assertSame(0, $depois->contracted_price_cents);
        $this->assertSame('2027-08-31', $depois->commercial_term_ends_at?->toDateString());
        $this->assertTrue($depois->commercial_term_ends_at?->isPast());

        // O plano, o estado e o acesso não se mexeram.
        $this->assertSame($planoAntes, $depois->plan->key);
        $this->assertSame(SubscriptionStatus::Active, $depois->status);
        $this->assertNull($depois->ends_at);
        $this->assertTrue($depois->isInForce());
        $this->assertSame($mapaAntes, app(Entitlements::class)->accessStatesFor($organization->fresh()));

        // E ninguém inventou dinheiro a receber.
        $this->assertSame(0, SubscriptionPayment::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->count());
    }

    #[Test]
    public function an_account_created_after_the_promotion_records_nothing_rather_than_a_guess(): void
    {
        // Terminada a promoção, o Base continua gratuito hoje — mas NADA foi
        // decidido sobre o que passa a custar, e gravar `promotional` numa
        // adesão que nunca foi oferecida nessa condição seria inventar-lhe uma
        // data. NULL é a resposta honesta até alguém decidir o que a substitui.
        $this->travelTo(Carbon::parse('2027-09-01 10:00'));

        $subscription = $this->currentOf($this->organization());

        $this->assertNull($subscription->commercial_condition);
        $this->assertNull($subscription->contracted_price_cents);
        $this->assertNull($subscription->commercial_term_ends_at);
    }

    // ------------------------------- quem recebe a promoção, e quem não

    /**
     * ONDE A PROMOÇÃO É CARIMBADA, CASO A CASO.
     *
     * `ChangeOrganizationPlan::to()` resolve os termos por DEFEITO quando o
     * chamador não os dá, e o defeito é «uma adesão feita agora». A pergunta
     * que isso levanta — carimba uma promoção indevida numa mudança
     * administrativa? — responde-se lendo a promessa pública em vez de a
     * adivinhar: «Gratuito no ano letivo 2026/27» é dito sobre o PLANO BASE
     * nesse ano, e não sobre a data em que alguém se inscreveu. Quem estiver no
     * Base durante 2026/27 tem-no gratuitamente, tenha chegado lá por registo,
     * por fim de trial ou por descida de plano.
     *
     * Dizer NULL numa descida seria afirmar «não se sabe quanto custa este
     * Base», que é falso: sabe-se, e é zero até 31/08/2027.
     */
    #[Test]
    public function every_route_onto_base_during_the_promotion_records_it(): void
    {
        $plans = app(ChangeOrganizationPlan::class);
        $base = Plan::where('key', 'base')->firstOrFail();

        // 1. Adesão nova, pelo registo.
        $registo = $this->organization();
        $this->assertPromotional($this->currentOf($registo), 'uma conta nova');

        // 2. A linha dormente que um trial deixa para trás.
        $comTrial = $this->organization();
        app(ActivateProTrial::class)->activate(User::find($comTrial->owner_id), $comTrial);
        $dormente = OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $comTrial->getKey())
            ->where('status', SubscriptionStatus::Active)
            ->latest('starts_at')
            ->firstOrFail();
        $this->assertPromotional($dormente, 'a base dormente de um trial');

        // 3. Descida administrativa Pro → Base. Recebe a promoção, e a decisão
        //    está documentada acima: a promessa é sobre o plano no ano lectivo,
        //    não sobre a data de inscrição.
        $descida = $this->organization();
        $plans->to($descida, Plan::where('key', 'pro')->firstOrFail());
        $plans->to($descida->fresh(), $base);
        $this->assertPromotional($this->currentOf($descida), 'uma descida para Base');

        // 4. Um Institucional movido para Base — mesma leitura, mesmo resultado.
        $institucional = $this->organization();
        $plans->to($institucional, Plan::where('key', 'institutional')->firstOrFail());
        $plans->to($institucional->fresh(), $base);
        $this->assertPromotional($this->currentOf($institucional), 'um institucional movido para Base');
    }

    #[Test]
    public function no_route_onto_base_records_it_once_the_promotion_has_closed(): void
    {
        $this->travelTo(Carbon::parse('2027-09-01 10:00'));

        $plans = app(ChangeOrganizationPlan::class);
        $base = Plan::where('key', 'base')->firstOrFail();

        $descida = $this->organization();
        $plans->to($descida, Plan::where('key', 'pro')->firstOrFail());
        $plans->to($descida->fresh(), $base);

        $subscription = $this->currentOf($descida);

        $this->assertNull($subscription->commercial_condition);
        $this->assertNull($subscription->contracted_price_cents);
        $this->assertNull($subscription->billing_period);
        $this->assertNull($subscription->commercial_term_ends_at);
    }

    #[Test]
    public function moving_onto_a_paid_plan_never_receives_the_base_promotion(): void
    {
        // O defeito é «uma adesão feita agora», e uma adesão ao Pro feita agora
        // não é gratuita. `forNewAdhesion()` recusa qualquer plano que não seja
        // o Base, de modo que nenhuma mudança administrativa para Pro ou
        // Institucional pode acabar marcada como promocional.
        $plans = app(ChangeOrganizationPlan::class);

        foreach (['pro', 'institutional'] as $key) {
            $organization = $this->organization();
            $plans->to($organization, Plan::where('key', $key)->firstOrFail());

            $subscription = $this->currentOf($organization);

            $this->assertNotSame(
                CommercialCondition::Promotional,
                $subscription->commercial_condition,
                "o plano {$key} foi marcado como promocional",
            );
            $this->assertNull($subscription->contracted_price_cents);
        }
    }

    // ================================================ 2. SUBSCRIÇÃO ANUAL

    #[Test]
    public function a_contracted_pro_records_the_annual_period_the_page_promises(): void
    {
        // «Subscrição anual. Não existe pagamento mensal.» — `commercial.ts:95`,
        // e até agora em lado nenhum da base de dados.
        [$organization] = $this->boughtPro();

        $subscription = $this->currentOf($organization);

        $this->assertSame(BillingPeriod::Annual, $subscription->billing_period);
        $this->assertSame('pro', $subscription->plan->key);
        $this->assertSame(
            '2027-06-01',
            $subscription->commercial_term_ends_at?->toDateString(),
            'o termo comercial é o fim do período que o dinheiro comprou',
        );
    }

    #[Test]
    public function a_later_price_change_does_not_rewrite_an_earlier_contract(): void
    {
        // §11, e a razão de o preço vir do PAGAMENTO e não de
        // `config/billing.php`: uma tabela de preços diz o que se pede hoje; um
        // contrato diz o que alguém acordou.
        [$organization] = $this->boughtPro();

        $antes = $this->currentOf($organization)->contracted_price_cents;

        config(['billing.prices.pro' => 9990, 'billing.founder.price_cents' => 8990]);

        $this->assertSame($antes, $this->currentOf($organization)->fresh()->contracted_price_cents);
    }

    // ====================================================== 3. FUNDADOR

    #[Test]
    public function a_founder_contract_records_the_condition_and_the_frozen_price(): void
    {
        [$organization] = $this->boughtPro();

        $subscription = $this->currentOf($organization);

        $this->assertSame(CommercialCondition::Founder, $subscription->commercial_condition);
        $this->assertSame(2990, $subscription->contracted_price_cents);
        $this->assertSame('EUR', $subscription->contracted_currency);
        $this->assertSame(BillingPeriod::Annual, $subscription->billing_period);

        // E o lugar ficou ligado ao contrato que dele resultou.
        $seat = app(FounderSeats::class)->seatOf($organization);
        $this->assertSame($subscription->getKey(), $seat?->organization_subscription_id);
        $this->assertTrue($seat?->isConfirmed());
    }

    #[Test]
    public function a_founder_is_not_a_plan_and_is_entitled_to_exactly_what_a_standard_pro_is(): void
    {
        // A regra que o `CommercialCondition` foi escrito para proteger, e que
        // a existência de uma tabela de lugares não pode quebrar.
        [$founder] = $this->boughtPro();

        $standard = $this->organization();
        app(ChangeOrganizationPlan::class)->to($standard, Plan::where('key', 'pro')->firstOrFail());

        $entitlements = app(Entitlements::class);
        $entitlements->flush();

        $mapOf = function (Organization $organization) use ($entitlements): array {
            $states = $entitlements->accessStatesFor($organization->fresh());
            ksort($states);

            return $states;
        };

        $this->assertSame($mapOf($standard), $mapOf($founder));
        $this->assertSame('pro', $this->currentOf($founder)->plan->key, 'não existe um plano «Fundador»');
    }

    #[Test]
    public function the_two_hundred_and_fifty_first_buyer_is_not_a_founder(): void
    {
        config(['billing.founder.seats' => 1]);

        $this->boughtPro();

        [$tarde, $payment] = $this->boughtPro();

        $this->assertSame(4490, $payment->amount_cents, 'o 2.º de 1 paga tabela');
        $this->assertSame(CommercialCondition::Standard, $this->currentOf($tarde)->commercial_condition);
        $this->assertNull(app(FounderSeats::class)->seatOf($tarde));
    }

    // ========================================================= 4. TRIAL

    #[Test]
    public function a_trial_never_looks_like_a_paid_contract(): void
    {
        $organization = $this->organization();
        $owner = User::find($organization->owner_id);

        $trial = app(ActivateProTrial::class)->activate($owner, $organization);

        $this->assertSame(SubscriptionStatus::Trial, $trial->status);
        $this->assertSame(0, $trial->contracted_price_cents, 'um trial custa zero, e isso é um facto registado');
        $this->assertSame(BillingPeriod::None, $trial->billing_period);

        // Sem condição comercial: `status = Trial` já diz o que isto é, e o
        // enum não tem — de propósito — um caso `trial`.
        $this->assertNull($trial->commercial_condition);

        // Sem termo comercial: para um trial, o termo e a janela de acesso são
        // o mesmo instante, e `ends_at` já o carrega. Escrevê-lo duas vezes
        // ensinaria a quem lesse que as duas colunas são sinónimos.
        $this->assertNull($trial->commercial_term_ends_at);
        $this->assertNotNull($trial->ends_at);

        // E nenhum pagamento foi inventado.
        $this->assertSame(0, SubscriptionPayment::withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())->count());
    }

    #[Test]
    public function when_a_trial_ends_the_account_returns_to_the_condition_it_was_promised(): void
    {
        $organization = $this->organization();
        $owner = User::find($organization->owner_id);

        app(ActivateProTrial::class)->activate($owner, $organization);

        // A linha dormente que espera o fim do trial já carrega a condição
        // subjacente — a do Base à venda no dia em que o trial começou, e não a
        // que estiver aberta trinta dias depois.
        $this->travelTo(Carbon::parse('2026-08-01 10:00'));

        $depois = $this->currentOf($organization);

        $this->assertSame('base', $depois->plan->key);
        $this->assertSame(CommercialCondition::Promotional, $depois->commercial_condition);
        $this->assertSame(0, $depois->contracted_price_cents);
        $this->assertSame('2027-08-31', $depois->commercial_term_ends_at?->toDateString());
    }

    // =================================================== 5. IMUTABILIDADE

    #[Test]
    public function the_recorded_contract_cannot_be_rewritten_by_a_later_plan_change(): void
    {
        [$organization] = $this->boughtPro();

        $contratado = $this->currentOf($organization);
        $this->assertSame(2990, $contratado->contracted_price_cents);

        // Descer para Base cria uma linha NOVA. A antiga fica exactamente como
        // estava — é história — e a nova leva a sua própria condição.
        app(ChangeOrganizationPlan::class)->to($organization->fresh(), Plan::where('key', 'base')->firstOrFail());

        $this->assertSame(2990, $contratado->fresh()->contracted_price_cents, 'a história foi reescrita');
        $this->assertSame(CommercialCondition::Founder, $contratado->fresh()->commercial_condition);

        $agora = $this->currentOf($organization);
        $this->assertNotSame($contratado->getKey(), $agora->getKey());
        $this->assertSame(CommercialCondition::Promotional, $agora->commercial_condition);
    }

    // ==================================================== 6. SEM PROVA

    #[Test]
    public function an_operator_moving_a_plan_with_no_payment_behind_it_claims_nothing(): void
    {
        // O silêncio é a resposta certa. Uma concessão administrativa não tem
        // preço acordado que o sistema possa ver, e escrever um plausível seria
        // pior do que não escrever nenhum: o backoffice continua a dizer
        // «Origem não registada», que é a verdade.
        $organization = $this->organization();

        $this->activatePlan($this->platformAdmin(), $organization, 'pro');

        $subscription = $this->currentOf($organization);

        $this->assertSame('pro', $subscription->plan->key);
        $this->assertNull($subscription->contracted_price_cents);
        $this->assertNull($subscription->contracted_currency);
        $this->assertNull($subscription->billing_period);
        $this->assertNull($subscription->commercial_condition);
    }

    // ------------------------------------------------------------ fixtures

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    /** O operador activa o plano no backoffice, como activa qualquer outro. */
    private function activatePlan(User $operator, Organization $organization, string $planKey): void
    {
        $this->actingAs($operator)
            ->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => $planKey])
            ->assertRedirect();
    }

    private function assertPromotional(OrganizationSubscription $subscription, string $what): void
    {
        $this->assertSame(CommercialCondition::Promotional, $subscription->commercial_condition, "{$what} não ficou promocional");
        $this->assertSame(0, $subscription->contracted_price_cents, "{$what} não ficou a zero");
        $this->assertSame('EUR', $subscription->contracted_currency);
        $this->assertSame(BillingPeriod::None, $subscription->billing_period);
        $this->assertSame('2027-08-31', $subscription->commercial_term_ends_at?->toDateString());
        $this->assertSame('base', $subscription->plan->key);
    }

    private function currentOf(Organization $organization): OrganizationSubscription
    {
        $subscription = app(ChangeOrganizationPlan::class)->inForce($organization->fresh());

        $this->assertNotNull($subscription, 'a organização tem de ter uma subscrição em vigor');

        return $subscription;
    }

    /**
     * O caminho completo de uma compra: pedido no checkout, dinheiro
     * confirmado pelo operador, plano activado por ele a seguir.
     *
     * É de propósito que sejam três passos e não um: aprovisionar e cobrar são
     * factos independentes neste produto, e é atravessando os três que se
     * verifica que a prova comercial sobrevive à distância entre eles.
     *
     * @return array{Organization, SubscriptionPayment}
     */
    private function boughtPro(): array
    {
        $organization = $this->organization();
        $buyer = User::find($organization->owner_id);
        $operator = $this->platformAdmin();
        $pro = Plan::where('key', 'pro')->firstOrFail();

        $request = app(RequestBankTransferPayment::class)->request($organization, $buyer, $pro, [
            'name' => 'Escola Teste',
            'tax_number' => null,
            'address_line1' => 'Rua Um, 1',
            'address_line2' => null,
            'postal_code' => '2400-000',
            'city' => 'Leiria',
            'country' => 'PT',
            'email' => 'faturacao@exemplo.pt',
        ]);

        $paid = app(ConfirmBankTransferRequest::class)->confirm(
            $request,
            $organization,
            $operator,
            $request->amount_cents,
            Carbon::now(),
        );

        $this->assertSame(PaymentStatus::Paid, $paid->status);

        $this->activatePlan($operator, $organization, 'pro');

        return [$organization->fresh(), $paid];
    }
}
