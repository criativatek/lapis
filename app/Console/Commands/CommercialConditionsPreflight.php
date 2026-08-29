<?php

namespace App\Console\Commands;

use App\Models\CommercialCondition;
use App\Models\FounderSeat;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Support\Commercial\CommercialTerms;
use App\Support\Commercial\SubscriptionCondition;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * O QUE ESTÁ LÁ, ANTES DE ISTO IR PARA PRODUÇÃO. Só leitura.
 *
 * A partir do momento em que esta funcionalidade estiver no ar, uma adesão Base
 * nova passa a gravar `Promotional / 0 / EUR / 2027-08-31`. As contas que já lá
 * estavam **não** são tocadas — nem por migração, nem por este comando, nem por
 * nada nesta branch — porque aplicar a promoção retroactivamente afirmaria uma
 * coisa que a base de dados nunca teve prova para dizer.
 *
 * Isso deixa uma pergunta em aberto que só produção pode responder: **existe
 * alguma conta real criada enquanto a landing prometia «Gratuito no ano letivo
 * 2026/27»?** Se existir, é uma decisão comercial — não técnica — o que lhe
 * fazer, e o deploy pára até alguém a tomar.
 *
 * NÃO ESCREVE NADA, E NÃO TEM `--apply`. A ausência é deliberada: um comando de
 * inspecção que também sabe corrigir é um comando que alguém corrige por
 * engano. Se a decisão for aplicar uma condição a contas antigas, isso faz-se
 * uma a uma no backoffice, por `SetCommercialCondition`, que regista quem o
 * disse e quando.
 *
 * NÃO CLASSIFICA CONTAS. Mostra os factos (quando foi criada, em que plano
 * está, que condição tem registada) e deixa a leitura a quem sabe. Ver o
 * ADR-0009 sobre a mesma recusa nos lugares de fundador.
 *
 * HÁ UMA MARCA DE CONTA DE TESTE, E ESTE COMANDO LÊ-A SEM A DEDUZIR.
 * `organizations.is_test_account` existe desde que este portão disparou sobre
 * uma população inteira de contas de ensaio — não havia contrato nenhum para
 * registar, e as únicas saídas eram fabricar um ou desligar o portão. Uma
 * organização marcada é contada à parte e não bloqueia; uma conta real sem
 * condição registada continua a parar o deploy. A marca vem de um operador que
 * a escreveu com o seu nome no rasto (`SetTestAccount`) e NUNCA de uma
 * inferência sobre o email, o domínio, o nome, o plano, o id ou a ausência de
 * pagamentos — deduzir «isto é de teste» é a mesma família de erro que deduzir
 * «isto é fundador» a partir de um valor pago.
 */
class CommercialConditionsPreflight extends Command
{
    protected $signature = 'lapis:commercial-preflight';

    protected $description = 'Read-only: report which subscriptions carry no recorded commercial terms, and the state of the Membro Fundador seats';

    public function handle(CommercialTerms $terms): int
    {
        $this->components->info('Pré-voo das condições comerciais — só leitura, nada é alterado.');

        $this->promotionWindow($terms);
        $this->newLine();

        $semTermos = $this->subscriptionsWithoutTerms();
        $this->newLine();

        $lugaresLidos = $this->founderSeats();
        $this->newLine();

        return $this->verdict($semTermos, $lugaresLidos);
    }

    protected function promotionWindow(CommercialTerms $terms): void
    {
        $promocao = $terms->freeBasePromotion();

        $this->components->twoColumnDetail(
            '<fg=gray>Promoção Base 2026/27</>',
            $promocao === null
                ? '<fg=yellow>fechada</> — uma adesão Base nova não grava condição nenhuma'
                : '<fg=green>aberta</> até '.$terms->freeBaseEndsAt()->toDateString(),
        );
    }

    /**
     * As subscrições EM VIGOR sem termos comerciais registados.
     *
     * Em vigor e não todas: uma linha histórica sem termos é apenas história —
     * a coluna não existia quando ela foi escrita. O que interessa antes de um
     * deploy é quem está no ar agora sem que se saiba em que condições.
     *
     * E REAIS, não todas as que estão em vigor: uma organização marcada como
     * conta de teste é contada e mostrada à parte, sem bloquear. Ver o
     * comentário no corpo — a distinção é o que evita ter de fabricar contratos
     * para o portão passar.
     *
     * @return int quantas foram encontradas — só as reais, que são as que bloqueiam
     */
    protected function subscriptionsWithoutTerms(): int
    {
        $emVigorSemTermos = OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->whereNull('contracted_price_cents')
            ->whereNull('commercial_condition')
            ->with(['plan', 'organization.owner'])
            ->orderBy('organization_id')
            ->get()
            ->filter(fn (OrganizationSubscription $subscription): bool => $subscription->isInForce());

        // CONTAS DE TESTE NÃO SÃO PERGUNTA COMERCIAL NENHUMA.
        //
        // O portão existe para «alguém pode dever-nos alguma coisa e não está
        // escrito». Uma organização que um operador marcou explicitamente como
        // conta de teste já respondeu a isso: não lhe foi prometido nada, e não
        // há contrato para registar. Bloquear nela obrigaria a inventar um — a
        // fabricar uma condição `promotional` que ninguém acordou só para o
        // comando passar —, que é exatamente a mentira que este comando existe
        // para impedir.
        //
        // A MARCA É EXPLÍCITA E SÓ EXPLÍCITA. Nada aqui deduz «conta de teste»
        // a partir do email, do domínio, do nome, do plano, do id nem da
        // ausência de pagamentos: lê-se a coluna que um operador escreveu com o
        // seu nome no rasto (`SetTestAccount`), e mais nada. Uma conta real sem
        // condição registada continua a parar o deploy, como sempre parou.
        [$deTeste, $candidatas] = $emVigorSemTermos->partition(
            fn (OrganizationSubscription $subscription): bool => (bool) $subscription->organization->is_test_account,
        );

        $this->components->twoColumnDetail(
            '<fg=gray>Subscrições em vigor sem condição nem preço registados</>',
            (string) $candidatas->count(),
        );

        $this->components->twoColumnDetail(
            '<fg=gray>Contas de teste excluídas do gate comercial</>',
            $deTeste->isEmpty() ? '0' : '<fg=cyan>'.$deTeste->count().'</>',
        );

        if ($candidatas->isEmpty()) {
            return 0;
        }

        $this->newLine();
        $this->table(
            ['Organização', 'Tipo', 'Criada em', 'Plano', 'Estado', 'Condição efetiva'],
            $candidatas->map(fn (OrganizationSubscription $subscription): array => [
                $subscription->organization->name,
                $subscription->organization->type->value,
                $subscription->organization->created_at?->toDateString() ?? '—',
                $subscription->plan->key,
                $subscription->status->value,
                SubscriptionCondition::labelOf($subscription),
            ])->all(),
        );

        return $candidatas->count();
    }

    /**
     * O ESTADO DOS LUGARES — QUANDO HÁ ONDE O LER.
     *
     * Este comando corre DUAS VEZES no deploy, e a primeira é antes de
     * `migrate`: é o único momento em que ainda dá para parar sem ter mudado
     * nada. Nessa passagem `founder_seats` ainda não existe — o código desta
     * release já está no disco, o esquema ainda é o da anterior — e ler a
     * tabela rebentava o comando a meio, com um rasto de pilha em vez de um
     * veredicto, e com o mesmo código de saída 1 que significa «há contas por
     * classificar». Um portão que não distingue «pára, decide» de «correste-me
     * cedo demais» não é um portão.
     *
     * A ausência da tabela antes da migração não é uma anomalia, é o esperado,
     * e por isso não contamina o veredicto — apenas se diz, alto, que esta
     * secção fica por ver até `migrate` correr.
     *
     * @return bool se os lugares chegaram a ser lidos
     */
    protected function founderSeats(): bool
    {
        if (! Schema::hasTable('founder_seats')) {
            $this->components->twoColumnDetail(
                '<fg=gray>Lugares de Membro Fundador</>',
                '<fg=yellow>tabela ainda não existe</> — secção adiada para depois da migração',
            );

            return false;
        }

        $seats = FounderSeat::query()->orderBy('seat_number')->get();
        $agora = Carbon::now();

        $confirmados = $seats->filter(fn (FounderSeat $seat): bool => $seat->isConfirmed())->count();
        $reservados = $seats->filter(fn (FounderSeat $seat): bool => ! $seat->isConfirmed() && $seat->isHolding($agora))->count();
        $vencidos = $seats->count() - $confirmados - $reservados;

        $this->components->twoColumnDetail('<fg=gray>Lugares de Membro Fundador confirmados</>', (string) $confirmados);
        $this->components->twoColumnDetail('<fg=gray>… reservados, à espera de pagamento</>', (string) $reservados);
        $this->components->twoColumnDetail('<fg=gray>… com reserva vencida (contam como livres)</>', (string) $vencidos);

        if ($seats->isEmpty()) {
            return true;
        }

        $this->newLine();
        $this->table(
            ['N.º', 'Organização', 'Preço', 'Tomado em', 'Estado'],
            $seats->map(function (FounderSeat $seat) use ($agora): array {
                // `findOrFail`: a chave estrangeira é `restrictOnDelete`, por
                // isso a organização existe sempre. Um lugar órfão seria um
                // problema de integridade, e falhar alto num pré-voo é melhor
                // do que mostrar um travessão a quem está a decidir um deploy.
                $organization = Organization::withoutGlobalScopes()->findOrFail($seat->organization_id);

                return [
                    $seat->seat_number,
                    $organization->name,
                    number_format($seat->price_cents / 100, 2, ',', ' ').' '.$seat->currency,
                    $seat->claimed_at->toDateString(),
                    match (true) {
                        $seat->isConfirmed() => 'confirmado',
                        $seat->isHolding($agora) => 'reservado até '.$seat->reserved_until?->toDateString(),
                        default => 'reserva vencida',
                    },
                ];
            })->all(),
        );

        return true;
    }

    /**
     * O veredicto, e a razão de ele ser um código de saída.
     *
     * Sair diferente de zero quando há contas por classificar é o que permite
     * pendurar isto num procedimento de deploy sem ninguém ter de LER a saída
     * com atenção. Não é um erro — é «alguém tem de olhar para isto antes de
     * seguir».
     */
    protected function verdict(int $semTermos, bool $lugaresLidos): int
    {
        if ($semTermos === 0) {
            $this->components->info(
                $lugaresLidos
                    ? 'Nenhuma subscrição em vigor sem condição registada. Nada a decidir antes do deploy.'
                    : 'Nenhuma subscrição em vigor sem condição registada — que é a pergunta que tinha de ser '
                        .'respondida ANTES de migrar. Os lugares de fundador ficaram por ver: volte a correr este '
                        .'comando depois de `migrate`, e só então esta release está inspeccionada.'
            );

            return self::SUCCESS;
        }

        $this->components->warn(
            $semTermos.' subscrição(ões) em vigor não têm condição comercial registada. Antes do deploy, confirme, '
            .'conta a conta, se alguma é de um cliente real que aderiu enquanto a landing prometia «Gratuito no ano '
            .'letivo 2026/27». Se for, é uma decisão comercial — nada aqui a toma, e nada aqui a aplica '
            .'retroactivamente.'
        );

        // As duas linhas que uma pessoa a ler isto quer ter à mão a seguir.
        $this->line('  · O backoffice filtra as mesmas contas por condição «'.SubscriptionCondition::labelFor(SubscriptionCondition::UNKNOWN).'».');
        $this->line('  · Marcar uma delas é `SetCommercialCondition` ('.CommercialCondition::Promotional->label().', se for o caso), que fica auditado.');

        return self::FAILURE;
    }
}
