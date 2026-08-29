<?php

namespace Tests\Feature\Admin\Commercial;

use App\Actions\Commercial\ConfirmBankTransferRequest;
use App\Actions\Commercial\RequestBankTransferPayment;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «QUE CONDIÇÃO É QUE ESTA ORGANIZAÇÃO CONTRATOU?» — respondida sem SQL.
 *
 * As quatro colunas do snapshot comercial existem desde a 0.88.0 e a ficha da
 * conta nunca as mostrou: um administrador que precisasse de saber o que uma
 * conta acordou tinha de abrir a base de dados. Este ficheiro fixa que a
 * pergunta se responde no ecrã — e que se responde SÓ A LER.
 *
 * READ-ONLY NÃO É UMA ESCOLHA DE DESIGN, É A CONSEQUÊNCIA. O modelo recusa
 * qualquer alteração a estas quatro colunas depois de existirem; um campo
 * editável neste ecrã seria um botão que só sabe rebentar. Corrigir um erro
 * administrativo é criar um contrato novo, não reescrever o antigo.
 */
class ContractedConditionVisibilityTest extends TestCase
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

    #[Test]
    public function the_account_screen_states_the_free_base_promotion_it_adhered_under(): void
    {
        $organization = $this->organization();

        $this->actingAs($this->admin())
            ->get("/admin/commercial/{$organization->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/CommercialAccount')
                ->where('current.condition_label', 'Condição promocional')
                ->where('current.contracted_price_cents', 0)
                ->where('current.contracted_currency', 'EUR')
                ->where('current.billing_period', 'none')
                ->where('current.billing_period_label', 'Sem periodicidade')
                ->where('current.commercial_term_ends_at', '2027-08-31')
                ->where('current.plan_version', 1));
    }

    #[Test]
    public function an_unrecorded_condition_shows_as_unrecorded_and_not_as_free(): void
    {
        // A distinção que o ecrã não pode deixar colapsar: `null` é «ninguém
        // registou», `0` é «acordado como gratuito». Uma conta anterior às
        // colunas tem de continuar a ler-se como a primeira.
        $organization = $this->organization();

        DB::table('organization_subscriptions')
            ->where('organization_id', $organization->getKey())
            ->update([
                'commercial_condition' => null,
                'contracted_price_cents' => null,
                'contracted_currency' => null,
                'billing_period' => null,
                'commercial_term_ends_at' => null,
            ]);

        $this->actingAs($this->admin())
            ->get("/admin/commercial/{$organization->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('current.condition_label', 'Origem não registada')
                ->where('current.contracted_price_cents', null)
                ->where('current.billing_period_label', null));
    }

    #[Test]
    public function a_founder_account_shows_its_seat_number_and_frozen_price(): void
    {
        $organization = $this->boughtPro();

        $this->actingAs($this->admin())
            ->get("/admin/commercial/{$organization->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('current.condition_label', 'Membro Fundador')
                ->where('current.contracted_price_cents', 2990)
                ->where('current.billing_period', 'annual')
                ->where('founderSeat.number', 1)
                ->where('founderSeat.capacity', 250)
                ->where('founderSeat.price_cents', 2990)
                ->where('founderSeat.is_confirmed', true));
    }

    #[Test]
    public function an_account_with_no_seat_sends_none_rather_than_an_empty_one(): void
    {
        $organization = $this->organization();

        $this->actingAs($this->admin())
            ->get("/admin/commercial/{$organization->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('founderSeat', null));
    }

    #[Test]
    public function the_screen_offers_no_way_to_edit_the_contracted_snapshot(): void
    {
        // O que se pode marcar continua a ser a CONDIÇÃO (`SetCommercialCondition`,
        // que não toca no plano nem nas datas). O preço, a moeda, a
        // periodicidade e o termo não estão entre as opções que o ecrã oferece,
        // porque não há caminho pelo qual pudessem ser aceites.
        $organization = $this->organization();

        $this->actingAs($this->admin())
            ->get("/admin/commercial/{$organization->ulid}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $options = $page->toArray()['props']['options'];

                $this->assertArrayHasKey('conditions', $options);
                $this->assertArrayNotHasKey('billing_periods', $options);
                $this->assertArrayNotHasKey('prices', $options);
            });
    }

    #[Test]
    public function the_trail_names_the_founder_seat_events(): void
    {
        // §19: a atribuição de uma condição especial tem de ser auditável, e o
        // ecrã da conta é onde um operador a lê.
        $organization = $this->boughtPro();

        $this->actingAs($this->admin())
            ->get("/admin/commercial/{$organization->ulid}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $events = array_column($page->toArray()['props']['audit'], 'event');

                $this->assertContains('commercial.founder_seat_claimed', $events);
                $this->assertContains('commercial.founder_seat_confirmed', $events);
                $this->assertContains('commercial.payment_requested', $events);
            });
    }

    // ------------------------------------------------------------ fixtures

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }

    /** O caminho completo: pedido, dinheiro confirmado, plano activado. */
    private function boughtPro(): Organization
    {
        $organization = $this->organization();
        $buyer = User::find($organization->owner_id);
        $operator = $this->admin();

        $request = app(RequestBankTransferPayment::class)->request(
            $organization,
            $buyer,
            Plan::where('key', 'pro')->firstOrFail(),
            [
                'name' => 'Escola Teste',
                'tax_number' => null,
                'address_line1' => 'Rua Um, 1',
                'address_line2' => null,
                'postal_code' => '2400-000',
                'city' => 'Leiria',
                'country' => 'PT',
                'email' => 'faturacao@exemplo.pt',
            ],
        );

        app(ConfirmBankTransferRequest::class)->confirm(
            $request,
            $organization,
            $operator,
            $request->amount_cents,
            Carbon::now(),
        );

        $this->actingAs($operator)
            ->post("/admin/accounts/{$organization->ulid}/plan", ['plan_key' => 'pro'])
            ->assertRedirect();

        return $organization->fresh();
    }
}
