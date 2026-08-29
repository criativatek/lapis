<?php

namespace App\Support\Commercial;

use App\Models\FounderSeat;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * QUEM OCUPA UM DOS 250 — atribuído, confirmado e libertado num só sítio.
 *
 * A REGRA TÉCNICA, dita uma vez e sem ambiguidade:
 *
 *   **Um lugar de Membro Fundador é atribuído no instante em que uma
 *   organização conclui o checkout do plano Pro e recebe a sua referência de
 *   transferência.** Segura-se durante a janela de transferência
 *   (`billing.bank_transfer.window_days`), torna-se definitivo quando um
 *   pagamento é confirmado, e liberta-se sozinho se a janela passar sem
 *   confirmação. Um lugar por organização, alguma vez. Os números são densos,
 *   de 1 até `billing.founder.seats`.
 *
 * PORQUÊ ESSE INSTANTE, E NÃO A CONFIRMAÇÃO DO PAGAMENTO. Porque a confirmação
 * é manual e chega dias depois: se o lugar só fosse consumido aí, 250 pessoas
 * podiam ver «restam 250 lugares», transferir 29,90 € no mesmo dia, e quase
 * todas descobririam mais tarde que afinal não eram fundadoras. A promessa tem
 * de fechar no momento em que é feita a quem a lê. É também o único momento
 * transacional e auditável que este produto tem — não há gateway, e tudo o que
 * vem depois é uma pessoa a olhar para um extrato.
 *
 * PORQUÊ COM PRAZO. Reservar sem prazo entregaria a promessa a carrinhos
 * abandonados: 250 cliques bastariam para esgotar publicamente uma condição que
 * ninguém pagou. O prazo é o mesmo que o pedido de transferência já anuncia ao
 * comprador, e não é preciso job nenhum para o aplicar — as reservas vencidas
 * são varridas dentro da mesma transação que atribui a seguinte.
 *
 * O 251.º É IMPOSSÍVEL, E NÃO APENAS IMPROVÁVEL. Quatro camadas, da mais forte
 * para a mais legível:
 *
 *  1. `UNIQUE(seat_number)` na base de dados. Duas transações concorrentes que
 *     calculem o mesmo número não podem ambas gravar. É a única garantia que
 *     não depende de o código estar certo.
 *  2. `lockForUpdate()` sobre uma linha que existe SEMPRE — a do plano `pro`,
 *     ver `lockAllocation()` —, que faz as transações concorrentes esperarem em
 *     fila em vez de colidirem, desde a primeira venda. Era aqui que estava o
 *     buraco: bloquear «a última linha de `founder_seats`» não bloqueia coisa
 *     nenhuma enquanto a tabela está vazia, e as duas primeiras compras
 *     simultâneas calculavam ambas o n.º 1.
 *  3. Um ciclo de tentativas que CONVERGE: apanhada a colisão, relê-se a tabela
 *     e pede-se o menor número que continue livre — nunca se insiste no mesmo.
 *  4. O teto verificado em PHP antes de inserir, que é o que devolve «esgotado»
 *     em vez de uma excepção de base de dados.
 *
 * E EM NENHUM DESSES CAMINHOS SAI UM ERRO PARA QUEM COMPRA. Esgotadas as
 * tentativas, `insertNextSeat()` devolve NULL e regista a ocorrência: o
 * checkout segue ao preço de tabela. Vender a 44,90 € quando havia lugar é um
 * erro pequeno, visível no trilho e corrigível por um operador; rebentar um
 * checkout com um erro de chave duplicada não é nenhuma dessas coisas.
 *
 * O QUE ESTA CLASSE NÃO SABE FAZER: distinguir uma conta de teste de uma conta
 * real. O esquema não tem essa marca — não há `is_internal`, `is_demo` nem tipo
 * de organização que o diga — e inventá-la a partir do domínio do email seria
 * criar uma classificação que o produto não tem. O mecanismo existente é
 * `release()`, explícito e auditado: um operador que reconhece uma conta de
 * teste liberta o lugar com a razão registada. Ver o relatório da janela.
 */
class FounderSeats
{
    /**
     * Quantas vezes se tenta de novo depois de uma colisão no `UNIQUE`.
     *
     * Só se lá chega se o lock de `lockAllocation()` não tiver servido — um
     * motor que o ignore, ou um caminho de escrita futuro que não passe por
     * aqui. Cada tentativa relê a tabela e pede o menor número ainda livre, por
     * isso o ciclo CONVERGE em vez de insistir; dez cobre qualquer contenção
     * realista sobre uma tabela cujo máximo é 250 linhas.
     */
    protected const MAX_ATTEMPTS = 10;

    public function __construct(
        protected FounderAvailability $availability,
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    /**
     * Dá um lugar a esta organização, se ainda houver.
     *
     * IDEMPOTENTE. Uma organização que já tem lugar recebe o MESMO — um segundo
     * clique no checkout não gasta dois dos 250, e não é o `UNIQUE` sobre
     * `organization_id` que trata disso, é esta leitura: a restrição é a rede,
     * não o comportamento.
     *
     * Devolve NULL quando a condição está fechada — por prazo ou por lotação —
     * e é isso, e não uma excepção, porque «esgotou» não é um erro do
     * comprador: o checkout continua, ao preço de tabela.
     */
    public function claim(
        Organization $organization,
        ?SubscriptionPayment $payment = null,
        ?User $actor = null,
    ): ?FounderSeat {
        return DB::transaction(function () use ($organization, $payment, $actor): ?FounderSeat {
            $existing = $this->seatOf($organization);

            if ($existing !== null) {
                return $existing;
            }

            $this->releaseExpired();

            if (! $this->availability->isWithinDeadline()) {
                return null;
            }

            $seat = $this->insertNextSeat($organization, $payment, $actor);

            if ($seat === null) {
                return null;
            }

            $this->record('commercial.founder_seat_claimed', $organization, $actor, sprintf(
                'Lugar de Membro Fundador n.º %d reservado a %s %s até %s.',
                $seat->seat_number,
                number_format($seat->price_cents / 100, 2, ',', ' '),
                $seat->currency,
                $seat->reserved_until?->toDateTimeString() ?? '—',
            ), [
                'seat_number' => $seat->seat_number,
                'price_cents' => $seat->price_cents,
                'currency' => $seat->currency,
                'reserved_until' => $seat->reserved_until?->toDateTimeString(),
                // A origem do benefício (§19): por onde entrou este fundador.
                'source' => $payment === null ? 'admin' : 'checkout',
                'payment_ulid' => $payment?->ulid,
            ]);

            return $seat;
        });
    }

    /**
     * O dinheiro entrou: o lugar deixa de poder expirar.
     *
     * `reserved_until` passa a NULL — não é uma data que se prolonga, é uma
     * condição que deixa de se aplicar — e `confirmed_at` fica a dizer quando.
     * Chamar isto num lugar já confirmado não faz nada: confirmar duas vezes o
     * mesmo pagamento não pode reescrever a data do primeiro.
     */
    public function confirm(Organization $organization, ?User $actor = null, ?CarbonInterface $at = null): ?FounderSeat
    {
        return DB::transaction(function () use ($organization, $actor, $at): ?FounderSeat {
            $seat = $this->seatOf($organization);

            if ($seat === null || $seat->isConfirmed()) {
                return $seat;
            }

            $seat->forceFill([
                'confirmed_at' => $at ?? Carbon::now(),
                'reserved_until' => null,
            ])->save();

            $this->record('commercial.founder_seat_confirmed', $organization, $actor, sprintf(
                'Lugar de Membro Fundador n.º %d confirmado.',
                $seat->seat_number,
            ), ['seat_number' => $seat->seat_number]);

            return $seat;
        });
    }

    /**
     * Liga o lugar à subscrição que acabou por o materializar.
     *
     * Puramente informativo — é o que permite a um operador saltar do lugar
     * para o contrato sem SQL. Não altera o lugar em nada que conte para os
     * 250.
     */
    public function attachSubscription(Organization $organization, OrganizationSubscription $subscription): ?FounderSeat
    {
        $seat = $this->seatOf($organization);

        if ($seat === null || $seat->organization_subscription_id !== null) {
            return $seat;
        }

        $seat->forceFill(['organization_subscription_id' => $subscription->getKey()])->save();

        return $seat;
    }

    /**
     * Devolve o lugar ao bolo, com uma razão registada.
     *
     * APAGA A LINHA, e é deliberado: esta tabela significa «os Membros
     * Fundadores», e uma linha libertada descreve alguém que não é um. Deixá-la
     * com uma marca de anulada obrigaria todas as contagens futuras a lembrar-se
     * de a excluir — que é exactamente o género de detalhe que uma delas há-de
     * esquecer. O que aconteceu não se perde: fica em `audit_events`, com o
     * número do lugar, a razão e quem a deu.
     *
     * O ordinal volta a ficar livre, e isso está certo: quem nunca chegou a ser
     * fundador não gasta um dos 250, e os números continuam densos — que é o que
     * torna «restam N» aritmética simples em vez de uma consulta com buracos.
     */
    public function release(Organization $organization, string $reason, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($organization, $reason, $actor): bool {
            $seat = $this->seatOf($organization);

            if ($seat === null) {
                return false;
            }

            $number = $seat->seat_number;
            $wasConfirmed = $seat->isConfirmed();
            $seat->delete();

            $this->record('commercial.founder_seat_released', $organization, $actor, sprintf(
                'Lugar de Membro Fundador n.º %d libertado: %s',
                $number,
                $reason,
            ), [
                'seat_number' => $number,
                'reason' => $reason,
                'was_confirmed' => $wasConfirmed,
            ]);

            return true;
        });
    }

    /** O lugar desta organização, confirmado ou ainda reservado. NULL se não tem. */
    public function seatOf(Organization $organization): ?FounderSeat
    {
        return FounderSeat::query()
            ->where('organization_id', $organization->getKey())
            ->first();
    }

    /**
     * Varre as reservas vencidas.
     *
     * Sem job e sem scheduler, pela mesma razão que a subscrição dormente de um
     * trial não precisa de um: a limpeza corre dentro da transação que precisa
     * do resultado, no único momento em que ele importa — quando alguém pede um
     * lugar. Uma reserva vencida que ninguém foi buscar não faz mal a ninguém
     * enquanto lá está; o que faria mal era contá-la.
     *
     * @return int quantas foram libertadas
     */
    public function releaseExpired(?CarbonInterface $at = null): int
    {
        $now = $at ?? Carbon::now();

        $expired = FounderSeat::query()
            ->whereNull('confirmed_at')
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', $now)
            ->get();

        foreach ($expired as $seat) {
            $organization = Organization::withoutGlobalScopes()->find($seat->organization_id);
            $number = $seat->seat_number;
            $seat->delete();

            if ($organization !== null) {
                $this->record('commercial.founder_seat_released', $organization, null, sprintf(
                    'Lugar de Membro Fundador n.º %d libertado: reserva expirou sem pagamento confirmado.',
                    $number,
                ), ['seat_number' => $number, 'reason' => 'reservation_expired', 'was_confirmed' => false]);
            }
        }

        return $expired->count();
    }

    /**
     * Insere o próximo lugar livre, ou devolve NULL se não houver.
     *
     * O PRIMEIRO LUGAR ERA O CASO POR COBRIR. A serialização era um
     * `lockForUpdate()` sobre «a última linha» — que não bloqueia nada numa
     * tabela VAZIA, porque não há linha para bloquear. Duas primeiras compras
     * simultâneas calculavam ambas `seat_number = 1`, o `UNIQUE` recusava a
     * segunda, e a segunda compradora levava com uma excepção técnica a meio de
     * um checkout. «Nunca há 251 lugares» estava garantido; «ninguém vê um
     * erro» não estava.
     *
     * A ÂNCORA RESOLVE-O. `lockForUpdate()` corre agora sobre uma linha que
     * existe SEMPRE — o plano a que a condição pertence — em vez de sobre uma
     * que só existe depois da primeira venda. Duas transações concorrentes
     * fazem fila desde a primeira, tabela vazia incluída, e nenhuma chega a
     * calcular o mesmo número que a outra.
     *
     * O CICLO DE TENTATIVAS FICA, como rede e não como mecanismo. Um lock de
     * linha é uma promessa do motor: o SQLite dos testes ignora
     * `lockForUpdate()` por completo, e num MySQL futuro basta alguém
     * introduzir um caminho de escrita que não passe por aqui. Se a colisão
     * acontecer mesmo assim, resolve-se — recalcula-se o menor número livre e
     * tenta-se esse — em vez de rebentar.
     *
     * E SE NEM ASSIM: devolve-se NULL, que é «esgotado», e regista-se o
     * sucedido. Nunca uma excepção para quem está a comprar. Vender ao preço de
     * tabela quando havia lugar é um erro pequeno e visível no trilho;
     * rebentar o checkout com um erro de base de dados é um erro grande.
     */
    protected function insertNextSeat(Organization $organization, ?SubscriptionPayment $payment, ?User $actor): ?FounderSeat
    {
        $this->lockAllocation();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $number = $this->nextSeatNumber();

            if ($number === null) {
                return null;
            }

            try {
                return FounderSeat::create([
                    'seat_number' => $number,
                    'organization_id' => $organization->getKey(),
                    // CONGELADO AQUI. Depois disto, mexer em
                    // `config/billing.php` não altera o que esta pessoa
                    // contratou — que é a diferença entre uma tabela de preços
                    // e um contrato.
                    'price_cents' => $this->availability->priceCents(),
                    'currency' => (string) config('billing.currency'),
                    'claimed_at' => Carbon::now(),
                    'reserved_until' => Carbon::now()->addDays($this->reservationDays()),
                    'subscription_payment_id' => $payment?->getKey(),
                    'claimed_by' => $actor?->getKey(),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                // Alguém levou este número entre o cálculo e a inserção. Nunca
                // se insiste no mesmo: `nextSeatNumber()` relê a tabela e a
                // tentativa seguinte pede o menor que continue livre.
                $collision = $exception;
            }
        }

        // Esgotadas as tentativas. `report()` e não `throw`: quem está a
        // comprar segue para o preço de tabela, e fica registado que houve
        // contenção a sério — que é o que alguém precisa de ver depois.
        report(new RuntimeException(sprintf(
            'Could not allocate a Membro Fundador seat for organization %d after %d attempts; '
            .'the buyer was served the list price instead. Sustained contention on `founder_seats.seat_number`.',
            $organization->getKey(),
            self::MAX_ATTEMPTS,
        ), previous: $collision));

        return null;
    }

    /**
     * A âncora sobre a qual as atribuições fazem fila.
     *
     * PRECISA DE EXISTIR SEMPRE, e é esse o requisito inteiro. A linha do plano
     * `pro` serve porque a condição Membro Fundador é uma condição SOBRE o
     * Pro — não é um bloqueio arbitrário num sítio conveniente — e porque essa
     * linha é semeada com a instalação e nunca é apagada.
     *
     * O que ela bloqueia, na prática, é outra atribuição de lugar. Nenhum
     * caminho de checkout escreve em `plans`; só o faria um operador a editar o
     * catálogo, que é raro, é administrativo, e esperar por um `INSERT` numa
     * tabela de 250 linhas não lhe custa nada.
     *
     * Sem plano `pro` não há condição de lançamento nenhuma, e o `firstOrNull`
     * devolve simplesmente «não bloqueei» em vez de rebentar — o `UNIQUE` e o
     * ciclo de tentativas continuam a valer, e uma instalação sem Pro não tem
     * fundadores para contar.
     */
    protected function lockAllocation(): void
    {
        Plan::query()->where('key', 'pro')->lockForUpdate()->first();
    }

    /**
     * O MENOR número livre, e não o maior mais um.
     *
     * A diferença conta assim que um lugar é libertado: se o n.º 100 volta ao
     * bolo enquanto o 250 está ocupado, `max + 1` daria 251 — esgotado — com 249
     * lugares realmente tomados. Procurar o primeiro buraco mantém os números
     * densos, e é isso que faz de «restam N» uma subtracção em vez de uma
     * consulta.
     *
     * Ler os 250 inteiros para memória é deliberadamente simples: é o tamanho
     * máximo da promessa inteira, e uma consulta esperta aqui custaria
     * legibilidade para poupar microssegundos numa operação que acontece 250
     * vezes na vida do produto.
     */
    protected function nextSeatNumber(): ?int
    {
        $capacity = $this->availability->capacity();
        $taken = FounderSeat::query()->orderBy('seat_number')->pluck('seat_number')->all();
        $takenSet = array_flip(array_map('intval', $taken));

        for ($number = 1; $number <= $capacity; $number++) {
            if (! isset($takenSet[$number])) {
                return $number;
            }
        }

        return null;
    }

    /** Quanto tempo a reserva se aguenta: a mesma janela que o comprador vê. */
    protected function reservationDays(): int
    {
        return max(1, (int) config('billing.bank_transfer.window_days'));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function record(string $event, Organization $organization, ?User $actor, string $summary, array $properties): void
    {
        $this->currentOrganization->runFor($organization, fn () => $this->audit->record(
            $event,
            $organization,
            $actor,
            summary: $summary,
            properties: $properties,
        ));
    }
}
