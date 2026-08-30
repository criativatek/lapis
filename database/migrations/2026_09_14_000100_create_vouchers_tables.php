<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UM VOUCHER PASSA A SER UMA LINHA, E UM RESGATE PASSA A SER OUTRA.
 *
 * Até aqui não era nenhuma das duas coisas. `subscription_payments.voucher_code`
 * guardava texto livre que ninguém validava — a coluna existe desde a
 * 2026_09_08_000200 e o seu papel era, literalmente, «registar que alguém
 * escreveu isto». A landing pedia um código e dizia-o com todas as letras:
 * «esta página não valida códigos». O backoffice mostrava o texto com a etiqueta
 * «não validada nem resgatada pelo sistema». Tudo isso era honesto e nada disso
 * era um motor.
 *
 * ESTAS TABELAS NÃO TOCAM NO QUE JÁ LÁ ESTÁ. Nenhuma coluna existente é
 * alterada, nenhum texto histórico é convertido, nenhum resgate é inferido a
 * partir de um `voucher_code` antigo: um texto que ninguém validou não prova que
 * existiu um voucher, e fabricar-lhe um resgate retroactivo seria inventar um
 * contrato.
 *
 * O QUE UM VOUCHER É, E O QUE NÃO É:
 *
 *  - **NÃO é um plano.** Não há `plan_version_id` no resgate a decidir módulos.
 *    O que um voucher move é a CONDIÇÃO e o PREÇO — as quatro colunas de prova
 *    comercial de `organization_subscriptions` (ADR-0008 §8) — e nada mais.
 *    `App\Support\Entitlements\Entitlements` nunca lê nenhuma destas tabelas,
 *    pela mesma razão que nunca lê `commercial_condition` nem `founder_seats`.
 *  - **NÃO acumula com Membro Fundador.** São duas condições comerciais
 *    distintas sobre o mesmo contrato, e o contrato tem uma só. A regra vive em
 *    `App\Support\Commercial\Vouchers`, não aqui: uma restrição de base de dados
 *    não sabe distinguir «esta organização é fundadora» de «era, e o lugar foi
 *    libertado».
 *  - **NÃO é editável depois de nascer.** O benefício é imutável — ver o guard
 *    de `App\Models\Voucher` — e por isso um resgate não pode ser reescrito por
 *    alguém que mude o voucher no ano seguinte. Desactivar é a única alteração
 *    que existe, e é aditiva.
 *
 * O RESGATE TEM UM CICLO DE VIDA, E ELE ESTÁ NO ESQUEMA. Uma linha de
 * `voucher_redemptions` ou é uma RESERVA com prazo (`reserved_until`, o mesmo
 * padrão dos `founder_seats`) ou é uma CONFIRMAÇÃO (`confirmed_at`) — a CHECK
 * constraint recusa o limbo em que não é nenhuma das duas. Uma reserva caducada
 * é APAGADA quando a capacidade volta a ser decidida (com o rasto no registo de
 * auditoria); uma confirmação nunca é apagada nem reescrita. É essa eliminação
 * que torna o `UNIQUE(voucher_id, organization_id)` compatível com «expirar e
 * voltar a tentar»: no máximo uma linha viva por par, e o par volta a caber
 * quando a reserva morta sai.
 */
return new class extends Migration
{
    /**
     * O maior desconto percentual que a tabela aceita.
     *
     * 100 é «gratuito», e é um benefício legítimo — mas repare-se que a família
     * `free_until` existe precisamente para o exprimir COM UMA DATA. Um desconto
     * de 100 % sem data é um preço contratado de zero para sempre, o que é uma
     * decisão comercial diferente e igualmente exprimível. O teto está aqui só
     * para impedir o absurdo aritmético de 101.
     */
    protected const MAX_PERCENT = 100;

    /**
     * As TRÊS famílias comerciais desta V1, ditas também em SQL.
     *
     * A lista está fechada de propósito: um valor fora dela — venha de um bug,
     * de SQL à mão ou de uma família futura ainda sem ADR — é recusado pela
     * base de dados antes de existir. Quando uma família nova for decidida,
     * alargar esta CHECK é uma linha de migração; até lá, a tabela só aceita o
     * que o produto vende. A mesma disciplina da lista de
     * `commercial_condition`.
     *
     * @var list<string>
     */
    protected const BENEFIT_TYPES = ['fixed_price', 'percent_discount', 'free_until'];

    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();

            /**
             * O código como foi EMITIDO, com os hífens que o tornam legível ao
             * telefone. É isto que se mostra a um operador e o que um cliente
             * copia de um email.
             *
             * NÃO É POR AQUI QUE SE PROCURA. Ver `normalized_code`.
             */
            $table->string('code', 64);

            /**
             * O código como se COMPARA: maiúsculas, sem hífens, sem espaços.
             *
             * `UNIQUE` aqui e não em `code`, e a diferença é o ponto todo. Se a
             * unicidade vivesse na forma de apresentação, `lapis-abcd-1234` e
             * `LAPISABCD1234` seriam dois vouchers distintos — e quem escrevesse
             * o código à mão acertaria ou falharia consoante tivesse posto os
             * hífens. Guardar as duas formas é deliberado: uma responde «que
             * código é este?», a outra responde «é este o mesmo código?», e
             * guardar só uma delas obrigaria a inventar a outra em cada leitura.
             *
             * Ver `App\Support\Commercial\VoucherCode` para o alfabeto e para a
             * regra de normalização, que existe num só sítio.
             */
            $table->string('normalized_code', 64)->unique();

            /**
             * Uma etiqueta interna para quem gere: «Campanha ANPRI 2026»,
             * «Piloto Agrupamento X». Nunca é mostrada a quem resgata.
             */
            $table->string('label', 120);

            /**
             * Que FAMÍLIA de benefício este código carrega. Uma das três de
             * `BENEFIT_TYPES`, imposta por CHECK onde o motor as tem e por
             * `App\Models\VoucherBenefitType` na fronteira.
             */
            $table->string('benefit_type', 40);

            /**
             * O preço final contratado, para `fixed_price`. Cêntimos inteiros,
             * nunca float, como em toda a parte que toca em dinheiro.
             *
             * Zero é um valor válido e significa «acordou-se que não custa
             * nada»; NULL significa «esta família não usa este campo». Nunca são
             * a mesma coisa — a mesma distinção que `ContractedTerms` já faz.
             */
            $table->unsignedInteger('benefit_amount_cents')->nullable();
            $table->char('benefit_currency', 3)->nullable();

            /** A percentagem, para `percent_discount`. 1..100. */
            $table->unsignedTinyInteger('benefit_percent')->nullable();

            /**
             * Até quando é gratuito, para `free_until`.
             *
             * ISTO É `commercial_term_ends_at`, E NÃO `ends_at`. É a data até à
             * qual a CONDIÇÃO se aguenta, não a data em que o acesso acaba —
             * ADR-0008 §8, e a razão pela qual `isInForce()` continua cego a
             * ela. Um voucher que expire em agosto não corta o acesso em agosto;
             * acaba o preço que a conta tinha.
             */
            $table->timestamp('benefit_free_until')->nullable();

            /**
             * A que plano este código se aplica. NULL = a qualquer plano que se
             * possa contratar.
             *
             * É UMA RESTRIÇÃO SOBRE O ALVO, NUNCA UMA INSTRUÇÃO. O plano-alvo
             * vem sempre do fluxo de contratação; este campo apenas recusa o
             * resgate quando o alvo não coincide. Um voucher nunca decide
             * sozinho para que plano uma conta vai.
             *
             * DELIBERADAMENTE `plan_id` E NÃO `plan_version_id`. Um voucher é
             * uma condição COMERCIAL, e o ADR-0008 reserva a versão contratada
             * para o «o quê» — os módulos e os limites. Prender um código a uma
             * versão fá-lo-ia participar numa decisão funcional que não é sua, e
             * um voucher emitido em setembro deixaria de valer no dia em que uma
             * v2 fosse publicada, sem que nada de comercial tivesse mudado.
             *
             * `restrictOnDelete`: um plano com vouchers emitidos não desaparece
             * por baixo deles.
             */
            $table->foreignId('plan_id')->nullable()->constrained()->restrictOnDelete();

            /**
             * A janela de validade. Ambas nullable e ambas explícitas:
             * NULL em `valid_from` é «vale desde que existe», NULL em
             * `valid_until` é «não expira por data» — e não «expirou».
             */
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();

            /**
             * Quantas vezes pode ser resgatado ao todo. NULL = ILIMITADO, dito
             * explicitamente e não por omissão.
             *
             * NÃO HÁ CONTADOR AO LADO, e a ausência é a decisão. A capacidade é
             * decidida contando as linhas vivas de `voucher_redemptions` dentro
             * da transação de resgate, sob `lockForUpdate` nesta linha — ver
             * `App\Actions\Commercial\RedeemVoucher`. Um contador desnormalizado
             * teria de descer quando uma reserva caduca, e cada decremento seria
             * uma segunda corrida e uma segunda forma de divergir da verdade;
             * uma contagem derivada não tem estado para errar.
             */
            $table->unsignedInteger('max_redemptions')->nullable();

            /**
             * Desactivado por alguém, e por quem. Um voucher desactivado deixa
             * de poder ser resgatado e continua a explicar todos os resgates que
             * já teve — que é a razão pela qual se desactiva em vez de se
             * apagar.
             */
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();

            /** Quem o emitiu. Sem operador não há voucher. */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // A pergunta da listagem do backoffice: o que está de pé, por ordem
            // de emissão.
            $table->index(['disabled_at', 'valid_until']);
        });

        Schema::create('voucher_redemptions', function (Blueprint $table): void {
            $table->id();

            /** O identificador público, como em `subscription_payments`. */
            $table->ulid('ulid')->unique();

            /**
             * `restrictOnDelete`: um voucher com resgates não pode ser apagado.
             * A tabela `vouchers` não tem sequer um caminho de código que apague
             * — desactiva-se — e isto garante-o mesmo contra SQL à mão.
             */
            $table->foreignId('voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            /**
             * A CHAVE DE IDEMPOTÊNCIA, e é esta restrição — não uma coluna
             * chamada `idempotency_key` — que a implementa.
             *
             * Um duplo clique, um refresh, um retry de rede: as três coisas
             * chegam como «esta organização resgata este código», e a base de
             * dados só deixa passar a primeira. A identidade do resgate é o
             * par, e o par é o que é único.
             *
             * COMPATÍVEL COM O CICLO DE VIDA porque uma reserva caducada é
             * apagada, não marcada: no máximo uma linha VIVA por par, e quando
             * ela morre o par volta a caber. Consequência deliberada que fica:
             * uma organização resgata cada código UMA vez, alguma vez. Um
             * voucher de utilizações múltiplas serve muitas organizações, não a
             * mesma muitas vezes.
             */
            $table->unique(['voucher_id', 'organization_id']);

            /**
             * O CICLO DE VIDA, nas mesmas duas colunas dos `founder_seats`:
             *
             *  - `reserved_until` preenchido, `confirmed_at` NULL — uma RESERVA:
             *    o checkout foi concluído e a transferência ainda não chegou.
             *    Consome capacidade enquanto o prazo não passa; caducada, é
             *    apagada (com rasto na auditoria) e a capacidade volta.
             *  - `confirmed_at` preenchido — DEFINITIVO. Nunca se apaga nem se
             *    reescreve; o guard de `App\Models\VoucherRedemption` impõe-no
             *    em código e o `down()` desta migração conta-o antes de recuar.
             *
             * A CHECK abaixo recusa a linha que não é nenhuma das duas.
             */
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            /**
             * O contrato e o pagamento que dele resultaram, quando existem.
             *
             * Ambos nullable e ambos `nullOnDelete`, pela mesma razão que em
             * `founder_seats`: o resgate é da ORGANIZAÇÃO e sobrevive a qualquer
             * linha que tenha passado por ele. Um `free_until` produz subscrição
             * e nenhum pagamento; um `percent_discount` produz um pedido de
             * pagamento e só mais tarde uma subscrição.
             */
            $table->foreignId('organization_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_payment_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('redeemed_at');

            /**
             * O BENEFÍCIO COMO ERA NO INSTANTE DO RESGATE, copiado.
             *
             * Não é desnormalização por conveniência: é a mesma disciplina de
             * `founder_seats.price_cents` e de `subscription_payments.amount_cents`.
             * O voucher pode ser desactivado amanhã e a campanha pode ser
             * encerrada; o que esta organização recebeu não muda por isso, e
             * ninguém tem de reconstruir a partir de uma linha que entretanto
             * mudou de estado.
             */
            $table->string('benefit_type', 40);
            $table->unsignedInteger('benefit_amount_cents')->nullable();
            $table->char('benefit_currency', 3)->nullable();
            $table->unsignedTinyInteger('benefit_percent')->nullable();
            $table->timestamp('benefit_free_until')->nullable();

            /**
             * O RESULTADO COMERCIAL, também congelado.
             *
             * A distinção entre isto e o bloco acima é a que separa «o que o
             * código dizia» de «o que saiu dele». Um desconto de 30 % sobre
             * 44,90 € é o benefício; 31,43 € foi o contrato, e ninguém tem de
             * repetir a aritmética — nem o arredondamento — sobre um preço de
             * tabela que entretanto mudou.
             *
             * NÃO DUPLICA O SNAPSHOT DA SUBSCRIÇÃO SEM MOTIVO: a subscrição
             * pode nem existir ainda quando o resgate acontece, e quando existir
             * pode ser superseded. Isto é o que o RESGATE decidiu; a subscrição
             * guarda o que o CONTRATO ficou a ser.
             */
            $table->unsignedInteger('result_price_cents')->nullable();
            $table->char('result_currency', 3)->nullable();
            $table->timestamp('result_term_ends_at')->nullable();

            $table->timestamps();

            // «Que resgates teve esta organização?», na ficha comercial.
            $table->index('organization_id');
        });

        // Ditas também em SQL onde o motor as tem, exactamente como as migrações
        // comerciais anteriores estabeleceram: a base de dados é a garantia, o
        // código é a mensagem de erro legível.
        $benefitTypes = "'".implode("','", self::BENEFIT_TYPES)."'";

        $this->addChecks('vouchers', [
            // A lista fechada das famílias V1. Ver o comentário em BENEFIT_TYPES.
            'vouchers_benefit_type_check' => "benefit_type IN ({$benefitTypes})",
            // Cada família exige exactamente os seus campos — a mesma recusa que
            // `Voucher::assertBenefitIsCoherent()` faz em PHP, dita em SQL.
            'vouchers_fixed_price_shape_check' => "benefit_type <> 'fixed_price' OR (benefit_amount_cents IS NOT NULL AND benefit_currency IS NOT NULL AND benefit_percent IS NULL AND benefit_free_until IS NULL)",
            'vouchers_percent_shape_check' => "benefit_type <> 'percent_discount' OR (benefit_percent IS NOT NULL AND benefit_amount_cents IS NULL AND benefit_currency IS NULL AND benefit_free_until IS NULL)",
            'vouchers_free_until_shape_check' => "benefit_type <> 'free_until' OR (benefit_free_until IS NOT NULL AND benefit_amount_cents IS NULL AND benefit_currency IS NULL AND benefit_percent IS NULL)",
            'vouchers_percent_check' => 'benefit_percent IS NULL OR (benefit_percent >= 1 AND benefit_percent <= '.self::MAX_PERCENT.')',
            // Um preço sem moeda é «tanto de quê?» — a mesma recusa que
            // `ContractedTerms` faz em PHP e que `organization_subscriptions` já
            // faz em SQL.
            'vouchers_price_currency_check' => '(benefit_amount_cents IS NULL) = (benefit_currency IS NULL)',
            // Zero utilizações não é «ilimitado», é um voucher que nasce morto.
            // Ilimitado é NULL, e é dito assim.
            'vouchers_max_redemptions_check' => 'max_redemptions IS NULL OR max_redemptions >= 1',
            'vouchers_window_check' => 'valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from',
        ]);

        $this->addChecks('voucher_redemptions', [
            // Ou reserva com prazo, ou confirmação. Nunca o limbo sem nenhuma.
            'voucher_redemptions_lifecycle_check' => 'confirmed_at IS NOT NULL OR reserved_until IS NOT NULL',
            'voucher_redemptions_benefit_type_check' => "benefit_type IN ({$benefitTypes})",
            'voucher_redemptions_percent_check' => 'benefit_percent IS NULL OR (benefit_percent >= 1 AND benefit_percent <= '.self::MAX_PERCENT.')',
            'voucher_redemptions_result_currency_check' => '(result_price_cents IS NULL) = (result_currency IS NULL)',
        ]);
    }

    public function down(): void
    {
        $this->refuseIfCommercialStateWouldBeLost();

        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('vouchers');
    }

    /**
     * REVERSÍVEL SÓ ENQUANTO AS TABELAS ESTIVEREM VAZIAS.
     *
     * Mais conservador do que «só com resgates», e de propósito: um voucher
     * EMITIDO já é uma promessa em mãos alheias — o código seguiu por email, foi
     * ditado numa formação — e apagar a linha silenciosamente transforma essa
     * promessa num código que «nunca existiu». Se um código emitido estava
     * errado, o mecanismo é `disabled_at`, que a própria tabela define. Um
     * RESGATE é ainda mais: a prova de uma condição comercial acordada com uma
     * organização, e nada o reconstrói depois de a tabela desaparecer.
     *
     * @throws RuntimeException quando recuar destruiria estado comercial
     */
    protected function refuseIfCommercialStateWouldBeLost(): void
    {
        $redeemed = Schema::hasTable('voucher_redemptions')
            ? DB::table('voucher_redemptions')->count()
            : 0;

        if ($redeemed > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$redeemed} voucher redemption(s) are recorded. Each one is the proof of a "
                .'commercial condition granted to an organization — the benefit it was given and the price that came '
                .'out of it — and dropping the table destroys it with nothing able to reconstruct it.'
            );
        }

        $issued = Schema::hasTable('vouchers') ? DB::table('vouchers')->count() : 0;

        if ($issued > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$issued} voucher(s) have been issued. An issued code is a promise already "
                .'in somebody’s hands, and dropping it silently turns that promise into a code that never existed. '
                .'Disable wrong codes instead (`disabled_at`); roll back only while both tables are empty.'
            );
        }
    }

    /**
     * CHECK constraints apenas onde o motor as tem.
     *
     * O SQLite — o motor dos testes — não as adiciona a uma tabela existente;
     * lá, os guards de `App\Models\Voucher` e a validação da fronteira são o que
     * sustenta as mesmas regras. É a mesma divisão que a migração dos lugares de
     * fundador já documenta, e é por isso que existe um teste de MySQL de raiz a
     * provar a metade que o SQLite não prova.
     *
     * @param  array<string, string>  $checks
     */
    protected function addChecks(string $table, array $checks): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
