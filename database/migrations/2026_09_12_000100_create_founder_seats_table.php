<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «OS PRIMEIROS 250» — a promessa contada, e não estimada.
 *
 * A landing promete um número exato («Faça parte dos primeiros 250») e o
 * checkout mostra «Restam N lugares». Até aqui, N vinha de
 * `FounderAvailability::taken()`, que contava as subscrições com
 * `commercial_condition = founder` — uma população que **nenhum fluxo escrevia**.
 * O checkout marcava a condição no PAGAMENTO; a subscrição só passava a
 * fundadora se, dias depois, um operador o dissesse à mão. O contador lia,
 * portanto, uma tabela que ninguém enchia: mostrava «restam 250» para sempre e
 * o 251.º comprador via o preço de fundador sem forma de saber que era o 251.º.
 *
 * PORQUÊ UMA TABELA E NÃO O ESQUEMA QUE JÁ EXISTE. Três coisas que nenhuma
 * coluna atual consegue guardar:
 *
 *  1. **O lugar tem de estar ocupado durante a transferência.** Entre o pedido
 *     e a confirmação passam até 14 dias (`billing.bank_transfer.window_days`).
 *     Contar só as subscrições já aprovisionadas deixaria 250 pessoas a
 *     transferir 29,90 € ao mesmo tempo, e a maioria a descobrir no fim que
 *     afinal não era fundadora. Contar os pagamentos pendentes também não
 *     serve: o `ConfirmBankTransferRequest` **anula** a linha pendente e cria
 *     outra, de modo que o mesmo comprador aparece ora duas vezes ora nenhuma.
 *  2. **A ordem tem de ser um facto, não o `id`.** §9 do enunciado proíbe
 *     depender da ordem arbitrária de ids se ela não representar contratação.
 *     `seat_number` é o ordinal atribuído no momento da adesão e nunca mais
 *     muda — é ele que responde «quem foi o 250.º», e é o `UNIQUE` sobre ele
 *     que torna o 251.º **impossível na base de dados**, e não apenas
 *     desaconselhado no código.
 *  3. **O preço tem de congelar no momento da adesão.** `config/billing.php`
 *     diz o que se pede hoje; `price_cents` aqui diz o que ESTA pessoa
 *     contratou. Mudar a config no ano que vem não pode reescrever o contrato
 *     do ano passado (§11).
 *
 * O LUGAR É RESERVADO, NÃO CONSUMIDO NO FIM. É reservado quando o comprador
 * conclui o checkout e recebe a referência — o único momento transacional e
 * auditável que existe — e liberta-se sozinho se a janela de transferência
 * passar sem confirmação (`reserved_until`). Reservar para sempre a quem
 * abandonou o carrinho esgotaria a promessa com pedidos que nunca existiram;
 * não reservar de todo prometeria o mesmo lugar a toda a gente durante 14 dias.
 *
 * NÃO É UM DIREITO. Nada em `App\Support\Entitlements\Entitlements` lê esta
 * tabela. Um Membro Fundador tem exatamente os módulos de um Pro normal — é a
 * regra que o `CommercialCondition` foi escrito para proteger, e uma tabela de
 * lugares não é sítio para a quebrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('founder_seats', function (Blueprint $table): void {
            $table->id();

            /**
             * O ordinal público da promessa. 1..250.
             *
             * `UNIQUE` é a garantia inteira do §9: duas transações concorrentes
             * que calculem o mesmo próximo número não podem ambas gravar, e
             * como os números são densos e o teto é verificado antes de
             * inserir, 251 lugares ocupados é um estado que a base de dados
             * recusa. O código pode ter bugs; o índice não cede.
             */
            $table->unsignedInteger('seat_number')->unique();

            /**
             * Uma organização ocupa no máximo um lugar, alguma vez.
             *
             * `UNIQUE` também aqui: um segundo clique no checkout, ou um pedido
             * repetido depois de uma anulação, não pode gastar dois dos 250.
             * Um lugar libertado é reatribuível porque a linha é apagada — ver
             * `FounderSeats::release()` — e não porque esta restrição afrouxe.
             */
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();

            /**
             * O preço acordado quando o lugar foi tomado, congelado aqui.
             * Cêntimos inteiros, nunca float, como em toda a parte que toca em
             * dinheiro.
             */
            $table->unsignedInteger('price_cents');
            $table->char('currency', 3);

            /** Quando foi tomado. É esta data — e não `id` — que ordena a promessa. */
            $table->timestamp('claimed_at');

            /**
             * Até quando a reserva se aguenta sem confirmação de pagamento.
             *
             * NULL depois de confirmada: um lugar pago não expira. Enquanto não
             * for NULL, uma reserva vencida deixa de contar para os 250 e o
             * lugar volta ao bolo — sem job, sem scheduler, porque
             * `FounderSeats` limpa as vencidas na mesma transação em que
             * atribui a seguinte.
             */
            $table->timestamp('reserved_until')->nullable();

            /** Preenchida quando o dinheiro entrou. É o que torna o lugar definitivo. */
            $table->timestamp('confirmed_at')->nullable();

            /**
             * O pedido de transferência que reservou o lugar, e a subscrição
             * que acabou por o materializar. Ambos nullable e ambos
             * `nullOnDelete`: o lugar é da ORGANIZAÇÃO e sobrevive a qualquer
             * linha que tenha passado por ele.
             */
            $table->foreignId('subscription_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_subscription_id')->nullable()->constrained()->nullOnDelete();

            /** Quem o atribuiu, quando não foi o próprio checkout. */
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // A pergunta que o checkout faz a cada visita: quantos lugares
            // continuam de pé agora?
            $table->index(['confirmed_at', 'reserved_until']);
        });

        // O teto, dito também em SQL onde o motor o suporta. Duplica de
        // propósito o que `FounderSeats` já verifica: a mesma disciplina do
        // `assertPairAgrees()` de `OrganizationSubscription` — a base de dados
        // é a garantia, o código é a mensagem de erro legível.
        $this->addCheck(
            'founder_seats_seat_number_check',
            'seat_number >= 1 AND seat_number <= '.self::HARD_CAP,
        );
    }

    /**
     * O maior número de lugares que esta tabela aceita, alguma vez.
     *
     * DELIBERADAMENTE MAIOR DO QUE OS 250 DA PROMESSA. O teto comercial vive em
     * `billing.founder.seats`, onde uma decisão comercial o pode mover; este é
     * um travão de sanidade contra um erro de configuração que pedisse dez mil
     * fundadores, e mudá-lo exige uma migração — que é o peso certo para uma
     * decisão dessas.
     */
    protected const HARD_CAP = 1000;

    public function down(): void
    {
        $this->refuseIfSeatsWouldBeLost();

        Schema::dropIfExists('founder_seats');
    }

    /**
     * REVERSÍVEL SÓ ENQUANTO NINGUÉM FOR FUNDADOR.
     *
     * Um lugar ocupado é prova de uma condição comercial acordada com uma
     * pessoa, e nada a reconstrói depois de a tabela desaparecer — a mesma
     * razão pela qual a migração do snapshot comercial se recusa a recuar
     * depois de haver preços gravados.
     *
     * @throws RuntimeException quando recuar destruiria lugares atribuídos
     */
    protected function refuseIfSeatsWouldBeLost(): void
    {
        if (! Schema::hasTable('founder_seats')) {
            return;
        }

        $taken = DB::table('founder_seats')->count();

        if ($taken === 0) {
            return;
        }

        throw new RuntimeException(
            "Refusing to roll back: {$taken} Membro Fundador seat(s) are recorded. Each one is the proof of a "
            .'commercial condition agreed with a person — the ordinal they were promised and the price they were '
            .'given — and dropping the table destroys it with nothing able to reconstruct it. This migration is '
            .'reversible only while no seat has been claimed.'
        );
    }

    /**
     * CHECK constraints apenas onde o motor as tem, exatamente como as
     * migrações comerciais anteriores já estabeleceram. O SQLite — o motor dos
     * testes — não as adiciona a uma tabela existente; lá, o `UNIQUE` sobre
     * `seat_number` e os guardas de `FounderSeats` são o que sustenta a regra.
     */
    protected function addCheck(string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE founder_seats ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
