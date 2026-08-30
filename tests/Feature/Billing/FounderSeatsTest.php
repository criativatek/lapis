<?php

namespace Tests\Feature\Billing;

use App\Models\CommercialCondition;
use App\Models\FounderSeat;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Commercial\FounderAvailability;
use App\Support\Commercial\FounderSeats;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «OS PRIMEIROS 250» — a promessa que a landing faz, contada.
 *
 * A REGRA TÉCNICA QUE ESTE FICHEIRO FIXA, e a razão de ela ser esta:
 *
 *   Um lugar é atribuído no instante em que uma organização **conclui o
 *   checkout** do Pro e recebe a referência de transferência. Segura-se
 *   durante a janela de transferência, torna-se definitivo quando o pagamento
 *   é confirmado, e liberta-se sozinho se a janela passar sem confirmação. Um
 *   lugar por organização, alguma vez, com um ordinal denso de 1 a 250.
 *
 * As alternativas foram consideradas e rejeitadas por razões que estes testes
 * tornam concretas: contar **pagamentos confirmados** deixaria 250 pessoas a
 * transferir 29,90 € ao mesmo tempo e quase todas a descobrir depois que não
 * eram fundadoras; contar **pedidos sem prazo** entregaria a promessa a
 * carrinhos abandonados.
 *
 * O QUE ISTO SUBSTITUI. `FounderAvailability::taken()` contava as subscrições
 * com `commercial_condition = founder` — uma população que nenhum fluxo
 * escrevia. O contador estava, por isso, permanentemente em «restam 250», e o
 * 251.º comprador via o preço de fundador sem forma de saber que era o 251.º.
 */
class FounderSeatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.founder.price_cents' => 2990,
            'billing.founder.seats' => 250,
            'billing.founder.deadline' => '2026-12-31',
            'billing.bank_transfer.window_days' => 14,
        ]);

        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    // ------------------------------------------------------ o teto dos 250

    #[Test]
    public function the_two_hundred_and_fifty_first_does_not_get_a_seat(): void
    {
        config(['billing.founder.seats' => 3]);

        $seats = app(FounderSeats::class);

        foreach (range(1, 3) as $expected) {
            $seat = $seats->claim($this->organization());

            $this->assertNotNull($seat);
            $this->assertSame($expected, $seat->seat_number, 'os ordinais são densos e começam em 1');
        }

        $this->assertNull($seats->claim($this->organization()), 'o 4.º de 3 não pode ter lugar');
        $this->assertSame(0, app(FounderAvailability::class)->remaining());
        $this->assertFalse(app(FounderAvailability::class)->isOpen());
    }

    #[Test]
    public function concurrency_cannot_produce_a_two_hundred_and_fifty_first_seat(): void
    {
        // A GARANTIA É DA BASE DE DADOS, e é isso que este teste mede em vez do
        // caminho feliz. `UNIQUE(seat_number)` significa que duas transações
        // que calculem o mesmo número não podem ambas gravar — reproduzido aqui
        // pela via directa, porque uma corrida verdadeira num SQLite de teste
        // não é reproduzível e provaria menos do que isto.
        config(['billing.founder.seats' => 2]);

        $seats = app(FounderSeats::class);
        $primeiro = $seats->claim($this->organization());

        $this->assertNotNull($primeiro);
        $this->assertSame(1, $primeiro->seat_number);

        $duplicado = fn () => DB::table('founder_seats')->insert([
            'seat_number' => 1,
            'organization_id' => $this->organization()->getKey(),
            'price_cents' => 2990,
            'currency' => 'EUR',
            'claimed_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        try {
            $duplicado();
            $this->fail('a base de dados aceitou dois lugares com o mesmo número');
        } catch (UniqueConstraintViolationException) {
            // É esta a garantia.
        }

        // E o mesmo para a organização: um segundo pedido não gasta um segundo
        // lugar, nem sequer chegando à base de dados.
        $organizacao = Organization::withoutGlobalScopes()->findOrFail($primeiro->organization_id);
        $this->assertSame($primeiro->id, $seats->claim($organizacao)?->id, 'claim() é idempotente por organização');
        $this->assertSame(1, FounderSeat::count());
    }

    /**
     * A CORRIDA DA TABELA VAZIA, resolvida em vez de rebentada.
     *
     * O buraco original: `lockForUpdate()` corria sobre «a última linha de
     * `founder_seats`», que numa tabela VAZIA não é linha nenhuma. As duas
     * primeiras compras simultâneas calculavam ambas `seat_number = 1`, o
     * `UNIQUE` recusava a segunda, e a segunda compradora levava com um erro de
     * base de dados a meio do checkout.
     *
     * O lock passou a correr sobre uma linha que existe sempre, mas o ciclo de
     * tentativas ficou como rede — e é a rede que este teste mede, porque é ela
     * que tem de funcionar quando o lock não funcionar. O duplo simula
     * exactamente a corrida: a primeira tentativa pede um número que outra
     * transação acabou de levar.
     */
    #[Test]
    public function a_collision_on_the_first_seat_resolves_instead_of_erroring(): void
    {
        $jaTomado = app(FounderSeats::class)->claim($this->organization());
        $this->assertSame(1, $jaTomado?->seat_number);

        // Esta organização calcula o n.º 1 — o número que a outra transação
        // levou entre o cálculo e a inserção — e só depois relê.
        $seats = new class(app(FounderAvailability::class), app(AuditLog::class), app(CurrentOrganization::class)) extends FounderSeats
        {
            public int $attempts = 0;

            protected function nextSeatNumber(): ?int
            {
                $this->attempts++;

                return $this->attempts === 1 ? 1 : parent::nextSeatNumber();
            }
        };

        $seat = $seats->claim($this->organization());

        $this->assertNotNull($seat, 'a colisão devia resolver-se, não devolver «esgotado»');
        $this->assertSame(2, $seat->seat_number, 'a segunda tentativa pede o menor número ainda livre');
        $this->assertSame(2, $seats->attempts, 'converge à segunda: nunca insiste no mesmo número');
        $this->assertSame(2, FounderSeat::count());
    }

    #[Test]
    public function sustained_contention_serves_the_list_price_instead_of_throwing(): void
    {
        // Esgotadas as tentativas, quem está a comprar NÃO pode receber uma
        // excepção. Vender ao preço de tabela quando havia lugar é um erro
        // pequeno, visível no trilho e corrigível; rebentar um checkout com um
        // erro de chave duplicada não é nenhuma dessas coisas.
        app(FounderSeats::class)->claim($this->organization());

        $seats = new class(app(FounderAvailability::class), app(AuditLog::class), app(CurrentOrganization::class)) extends FounderSeats
        {
            /** Colide sempre: nunca sai do número que já está tomado. */
            protected function nextSeatNumber(): ?int
            {
                return 1;
            }
        };

        $seat = $seats->claim($this->organization());

        $this->assertNull($seat, 'contenção sustentada devolve «sem lugar», não uma excepção');
        $this->assertSame(1, FounderSeat::count(), 'e nada foi gravado a mais');
    }

    // ------------------------------------------------ lotação e libertação

    #[Test]
    public function the_full_house_is_reached_exactly_and_the_next_one_is_a_commercial_answer(): void
    {
        config(['billing.founder.seats' => 250]);

        $seats = app(FounderSeats::class);

        for ($i = 1; $i <= 250; $i++) {
            $this->assertSame($i, $seats->claim($this->organization())?->seat_number);
        }

        $availability = app(FounderAvailability::class);
        $this->assertSame(250, $availability->taken());
        $this->assertSame(0, $availability->remaining());
        $this->assertFalse($availability->isOpen());

        // O 251.º: uma resposta comercial, não uma excepção.
        $this->assertNull($seats->claim($this->organization()));
        $this->assertSame(250, FounderSeat::count());
    }

    #[Test]
    public function releasing_one_seat_frees_exactly_one_and_its_ordinal_comes_back(): void
    {
        config(['billing.founder.seats' => 3]);

        $seats = app(FounderSeats::class);
        $organizacoes = [];

        foreach (range(1, 3) as $i) {
            $organizacoes[$i] = $this->organization();
            $seats->claim($organizacoes[$i]);
        }

        $this->assertSame(0, app(FounderAvailability::class)->remaining());

        // Liberta-se o do MEIO, para provar que o ordinal que volta é o buraco
        // e não o seguinte ao maior — que é o que `max + 1` daria, deixando a
        // condição «esgotada» com um lugar realmente livre.
        $seats->release($organizacoes[2], 'Conta de teste interna.');

        $this->assertSame(1, app(FounderAvailability::class)->remaining());
        $this->assertSame(2, FounderSeat::count());

        $novo = $seats->claim($this->organization());

        $this->assertSame(2, $novo?->seat_number);
        $this->assertSame(0, app(FounderAvailability::class)->remaining());
    }

    #[Test]
    public function confirming_does_not_create_a_second_seat(): void
    {
        $seats = app(FounderSeats::class);
        $organization = $this->organization();

        $reservado = $seats->claim($organization);
        $confirmado = $seats->confirm($organization);

        $this->assertSame($reservado?->id, $confirmado?->id);
        $this->assertSame(1, FounderSeat::count());
        $this->assertSame(249, app(FounderAvailability::class)->remaining());
    }

    #[Test]
    public function the_deadline_closes_the_condition_even_with_seats_left(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 00:01'));

        $this->assertNull(app(FounderSeats::class)->claim($this->organization()));
        $this->assertSame(250, app(FounderAvailability::class)->remaining(), 'os lugares sobram; é o prazo que fecha');
    }

    #[Test]
    public function a_request_on_the_final_day_still_counts(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:59'));

        $this->assertNotNull(app(FounderSeats::class)->claim($this->organization()));
    }

    // --------------------------------------------------- reserva e consumo

    #[Test]
    public function a_seat_is_reserved_at_checkout_and_expires_if_nobody_pays(): void
    {
        $seats = app(FounderSeats::class);
        $availability = app(FounderAvailability::class);

        $abandonado = $this->organization();
        $seat = $seats->claim($abandonado);

        $this->assertNotNull($seat);
        $this->assertFalse($seat->isConfirmed());
        $this->assertSame(
            Carbon::parse('2026-06-15 10:00')->toDateTimeString(),
            $seat->reserved_until?->toDateTimeString(),
            'a reserva dura a janela de transferência que o comprador viu',
        );
        $this->assertSame(249, $availability->remaining());

        // Catorze dias depois, ninguém pagou. O lugar já não conta — antes
        // mesmo de alguém correr a limpeza, porque quem lê o número não pode
        // depender de uma limpeza ter corrido primeiro.
        $this->travelTo(Carbon::parse('2026-06-16 10:00'));
        $this->assertSame(250, $availability->remaining());

        // E o próximo a chegar recebe o ordinal libertado, e não o seguinte:
        // quem nunca chegou a ser fundador não gasta um dos 250.
        $novo = $seats->claim($this->organization());
        $this->assertSame(1, $novo?->seat_number);
        $this->assertNull(FounderSeat::where('organization_id', $abandonado->getKey())->first());
    }

    #[Test]
    public function a_confirmed_seat_never_expires(): void
    {
        $seats = app(FounderSeats::class);
        $organization = $this->organization();

        $seats->claim($organization);
        $seat = $seats->confirm($organization);

        $this->assertNotNull($seat);
        $this->assertTrue($seat->isConfirmed());
        $this->assertNull($seat->reserved_until, 'confirmar não prolonga o prazo: retira-o');

        $this->travelTo(Carbon::parse('2030-01-01 00:00'));
        $this->assertSame(249, app(FounderAvailability::class)->remaining());
    }

    #[Test]
    public function confirming_twice_does_not_rewrite_the_first_confirmation(): void
    {
        $seats = app(FounderSeats::class);
        $organization = $this->organization();

        $seats->claim($organization);
        $primeira = $seats->confirm($organization)?->confirmed_at;

        $this->travelTo(Carbon::parse('2026-07-01 10:00'));
        $segunda = $seats->confirm($organization)?->confirmed_at;

        $this->assertSame($primeira?->toDateTimeString(), $segunda?->toDateTimeString());
    }

    // -------------------------------------------------- o preço congelado

    #[Test]
    public function the_price_is_frozen_when_the_seat_is_taken(): void
    {
        // §11: a prova histórica não pode depender de `config/billing.php`, que
        // diz o que se pede HOJE e é livre de mudar amanhã.
        $seat = app(FounderSeats::class)->claim($this->organization());

        $this->assertSame(2990, $seat?->price_cents);
        $this->assertSame('EUR', $seat?->currency);

        config(['billing.founder.price_cents' => 9990]);

        $this->assertSame(2990, $seat?->fresh()->price_cents, 'mexer na config reescreveu um contrato');
    }

    // ------------------------------------------------------- libertar

    #[Test]
    public function an_operator_can_release_a_seat_and_the_trail_says_why(): void
    {
        // O MECANISMO PARA AS CONTAS DE TESTE. O esquema não tem marca de conta
        // interna, e inventá-la a partir do domínio do email seria criar uma
        // classificação que o produto não tem. Um operador que reconhece uma
        // liberta o lugar, e fica registado quem o disse e porquê.
        $seats = app(FounderSeats::class);
        $organization = $this->organization();
        $operator = User::factory()->create();

        $seat = $seats->claim($organization);
        $numero = $seat?->seat_number;

        $this->assertTrue($seats->release($organization, 'Conta de teste interna.', $operator));

        $this->assertSame(250, app(FounderAvailability::class)->remaining());
        $this->assertNull($seats->seatOf($organization));

        $evento = DB::table('audit_events')
            ->where('event', 'commercial.founder_seat_released')
            ->where('organization_id', $organization->getKey())
            ->first();

        $this->assertNotNull($evento, 'libertar um lugar tem de ficar no trilho');
        $this->assertStringContainsString('Conta de teste interna.', (string) $evento->summary);
        $this->assertStringContainsString((string) $numero, (string) $evento->summary);
        $this->assertSame($operator->getKey(), $evento->causer_id);

        $this->assertFalse($seats->release($organization, 'Já não há nada para libertar.'));
    }

    #[Test]
    public function claiming_a_seat_is_audited_with_its_number_and_frozen_price(): void
    {
        // §19: qualquer atribuição de condição especial tem de ser auditável, e
        // um dos 250 é a mais especial que este produto tem.
        $organization = $this->organization();

        app(FounderSeats::class)->claim($organization);

        $evento = DB::table('audit_events')
            ->where('event', 'commercial.founder_seat_claimed')
            ->where('organization_id', $organization->getKey())
            ->first();

        $this->assertNotNull($evento);

        $properties = json_decode((string) $evento->properties, true);

        $this->assertSame(1, $properties['seat_number']);
        $this->assertSame(2990, $properties['price_cents']);
        $this->assertSame('EUR', $properties['currency']);
        $this->assertSame('admin', $properties['source'], 'a origem do benefício fica registada');
    }

    // ------------------------------------------- o contador certo

    #[Test]
    public function the_counter_no_longer_reads_a_table_nothing_writes(): void
    {
        // A INCOERÊNCIA QUE ISTO FECHA. Marcar uma subscrição como fundadora —
        // o que um operador faz com `SetCommercialCondition`, e o único facto
        // que o contador antigo lia — não consome um lugar, porque não é assim
        // que se toma um. Contá-lo daria dois lugares gastos por uma pessoa.
        $organization = $this->organization();

        DB::table('organization_subscriptions')
            ->where('organization_id', $organization->getKey())
            ->update(['commercial_condition' => CommercialCondition::Founder->value]);

        $this->assertSame(250, app(FounderAvailability::class)->remaining());
        $this->assertSame(0, app(FounderAvailability::class)->taken());
    }

    // ------------------------------------------------------------ fixtures

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }
}
