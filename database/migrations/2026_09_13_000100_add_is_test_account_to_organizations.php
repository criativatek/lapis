<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «ISTO É UMA CONTA REAL?» — a pergunta que o pré-voo comercial fez e que o
 * esquema não sabia responder.
 *
 * O `lapis:commercial-preflight` bloqueia o deploy quando há subscrições em
 * vigor sem condição comercial registada, e faz bem: uma conta de um cliente a
 * pagar sem termos gravados é uma dívida por esclarecer. Mas o comando contava
 * TODAS as subscrições em vigor, e em produção todas as contas existentes são
 * de teste — incluindo as de dois professores parceiros convidados para
 * experimentar o produto. O portão disparava sobre uma população onde não há
 * contrato nenhum para registar, e as únicas saídas eram fabricar contratos que
 * ninguém acordou ou desligar o portão. Ambas mentem.
 *
 * A LACUNA ERA ESTRUTURAL: a aplicação não tinha forma de dizer «esta conta
 * existe para testar». Passa a ter, e é isto — um booleano, não um enum. Não há
 * hoje um terceiro estado que alguém consiga nomear sem inventar: «demo»,
 * «interna» e «parceiro» são todas a mesma coisa para efeitos do único
 * consumidor que existe (o pré-voo), e um enum com casos que nada distingue é
 * um convite a que passem a distinguir por acidente.
 *
 * `DEFAULT false`, E É O CERNE DA COLUNA. Um registo público novo nasce real,
 * sempre, sem exceção e sem herdar coisa nenhuma das contas que já cá estão. A
 * coluna também NÃO entra no `#[Fillable]` da `Organization`: não há
 * `Organization::create(['is_test_account' => true])` possível, nem por engano
 * nem por um `$request->all()` distraído. Escreve-se por um único sítio,
 * `SetTestAccount`, que exige um operador e deixa rasto.
 *
 * O QUE ESTA COLUNA NÃO FAZ, e a lista importa mais do que o que faz: não é uma
 * condição comercial, não entra no `Entitlements`, não entra nos `Limits`, não
 * toca na `PlanVersion`, não decide acesso, não altera retenção e não liga nem
 * desliga IA. Marcar uma conta como de teste não lhe dá nem tira absolutamente
 * nada — só diz, para quem lê, que ninguém lhe prometeu nada. Nenhum resolver
 * lê esta coluna; o único leitor é o pré-voo, que a usa para saber de quem NÃO
 * tem de perguntar.
 *
 * ADITIVA E REVERSÍVEL. Uma coluna nova com default, sem backfill e sem tocar
 * em linha nenhuma existente — as contas atuais ficam `false` até que um
 * operador diga o contrário, uma a uma e com rasto, que é exatamente a decisão
 * que o pré-voo estava a pedir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('is_test_account')->default(false)->after('jurisdiction');

            // O pré-voo pergunta «quais das que estão em vigor são reais?» a
            // cada deploy, e o backoffice filtra pela mesma coluna. São poucas
            // linhas hoje; o índice é barato e a pergunta é permanente.
            $table->index('is_test_account');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex(['is_test_account']);
            $table->dropColumn('is_test_account');
        });
    }
};
