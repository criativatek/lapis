<?php

namespace Tests\Feature\Console;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Support\Commercial\FounderSeats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O pré-voo, e a única coisa que ele nunca pode fazer: escrever.
 *
 * É um passo obrigatório antes do deploy desta funcionalidade — a pergunta que
 * só produção responde é «existe alguma conta real criada enquanto a landing
 * prometia Gratuito 2026/27?» — e um comando de inspecção que também soubesse
 * corrigir seria um comando que alguém corrige por engano.
 */
class CommercialPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.promotions.free_base.enabled' => true,
            'billing.promotions.free_base.ends_at' => '2027-08-31',
            'billing.founder.price_cents' => 2990,
            'billing.founder.seats' => 250,
            'billing.founder.deadline' => '2026-12-31',
        ]);

        $this->travelTo(Carbon::parse('2026-06-01 10:00'));
    }

    #[Test]
    public function a_clean_database_passes(): void
    {
        // Uma conta criada HOJE já grava a condição, por isso não aparece como
        // por classificar. Que é o ponto: depois desta funcionalidade, o que
        // sobra na lista é exactamente o que precede.
        $this->organization();

        $this->artisan('lapis:commercial-preflight')
            ->expectsOutputToContain('Nenhuma subscrição em vigor sem condição registada')
            ->assertExitCode(0);
    }

    #[Test]
    public function an_account_that_predates_the_promotion_is_reported_and_stops_the_deploy(): void
    {
        $organization = $this->organization();

        // Uma linha como as que existiam antes das colunas: sem condição e sem
        // preço.
        DB::table('organization_subscriptions')
            ->where('organization_id', $organization->getKey())
            ->update([
                'commercial_condition' => null,
                'contracted_price_cents' => null,
                'contracted_currency' => null,
                'billing_period' => null,
                'commercial_term_ends_at' => null,
            ]);

        $this->artisan('lapis:commercial-preflight')
            ->expectsOutputToContain($organization->name)
            ->expectsOutputToContain('decisão comercial')
            // Saída diferente de zero para que um procedimento de deploy pare
            // sem ninguém ter de LER a saída com atenção.
            ->assertExitCode(1);
    }

    #[Test]
    public function it_never_writes_anything(): void
    {
        $organization = $this->organization();

        DB::table('organization_subscriptions')
            ->where('organization_id', $organization->getKey())
            ->update(['commercial_condition' => null, 'contracted_price_cents' => null]);

        app(FounderSeats::class)->claim($this->organization());

        $antes = $this->fingerprint();

        $this->artisan('lapis:commercial-preflight')->run();

        $this->assertSame($antes, $this->fingerprint(), 'o pré-voo alterou dados');
    }

    #[Test]
    public function it_reports_the_founder_seats_and_their_state(): void
    {
        $confirmada = $this->organization();
        $reservada = $this->organization();

        app(FounderSeats::class)->claim($confirmada);
        app(FounderSeats::class)->confirm($confirmada);
        app(FounderSeats::class)->claim($reservada);

        $this->artisan('lapis:commercial-preflight')
            ->expectsOutputToContain($confirmada->name)
            ->expectsOutputToContain($reservada->name)
            ->expectsOutputToContain('confirmado')
            ->run();
    }

    /**
     * A PASSAGEM DE ANTES DA MIGRAÇÃO — a que nenhum teste cobria.
     *
     * O deploy extrai o código desta release por cima do que está no ar e só
     * DEPOIS corre `migrate`. Entre uma coisa e outra existe uma janela — a
     * única em que ainda dá para parar sem ter mudado nada — em que este
     * comando é a versão nova a olhar para o esquema antigo, sem
     * `founder_seats`. Todos os testes deste ficheiro corriam sobre a base já
     * migrada, e por isso nenhum via que, nessa janela, o comando rebentava.
     */
    #[Test]
    public function it_still_reports_against_the_schema_that_precedes_the_migration(): void
    {
        Schema::drop('founder_seats');

        $this->organization();

        $this->artisan('lapis:commercial-preflight')
            ->expectsOutputToContain('tabela ainda não existe')
            ->assertExitCode(0);
    }

    /**
     * E o veredicto continua a poder dizer PÁRA nessa mesma janela.
     *
     * É a razão de a tabela em falta não contaminar o código de saída: 1 tem de
     * continuar a significar «há contas por classificar, alguém tem de decidir»
     * e não «correste-me cedo demais». Um portão que não distingue as duas
     * coisas é um portão que se aprende a ignorar.
     */
    #[Test]
    public function the_verdict_still_stops_the_deploy_before_the_migration(): void
    {
        Schema::drop('founder_seats');

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

        $this->artisan('lapis:commercial-preflight')
            ->expectsOutputToContain($organization->name)
            ->assertExitCode(1);
    }

    // ------------------------------------------------------------ fixtures

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }

    /** Tudo o que o comando lê, como uma impressão digital comparável. */
    private function fingerprint(): string
    {
        return md5(json_encode([
            DB::table('organization_subscriptions')->orderBy('id')->get()->toArray(),
            DB::table('founder_seats')->orderBy('id')->get()->toArray(),
            DB::table('audit_events')->orderBy('id')->get()->toArray(),
            OrganizationSubscription::withoutGlobalScope('organization')->count(),
        ]));
    }
}
