<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A DECISÃO DO PROFESSOR SOBRE UM DOMÍNIO — e nada mais.
 *
 * A pauta já distinguia proposta de decisão no juízo GLOBAL: `classifications`
 * guarda a proposta determinística ao lado da classificação que o professor
 * escreveu, e nenhuma das duas se sobrepõe à outra (§3.3). Por domínio essa
 * distinção não existia: a apreciação de «Oralidade» era sempre a banda em que
 * o quantitativo calculado caía, sem sítio nenhum onde o professor pudesse
 * dizer outra coisa.
 *
 * É isso, e só isso, que esta tabela guarda. NÃO É UMA SEGUNDA FONTE DE VERDADE:
 *
 *  - o quantitativo continua a ser calculado pelo motor e nunca é escrito aqui;
 *  - a proposta continua a ser derivada da escala (`ScaleProposalResolver`) e
 *    nunca é copiada para aqui — uma cópia seria uma segunda resposta à mesma
 *    pergunta, e as duas divergiriam no dia em que a escala fosse reconfigurada;
 *  - o que fica escrito é UM nível de escala: o que o professor decidiu.
 *
 * APAGAR A LINHA É VOLTAR À PROPOSTA. Não há aqui um estado «decidiu que é a
 * proposta»: a ausência de linha significa exatamente «o professor não se
 * pronunciou» — e isso NÃO é uma pendência. A proposta vigora enquanto ninguém
 * a alterar, e é por isso que a pauta a escreve em texto normal e marca apenas
 * o caso contrário. Uma versão anterior desta frase dizia «itálico»: era o
 * mesmo dado com a leitura errada.
 *
 * O ÂMBITO VIAJA porque a mesma matrícula tem leituras diferentes no período e
 * no acumulado (§6), e uma decisão tomada sobre uma delas não é uma decisão
 * sobre a outra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_appreciation_decisions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Cascade: apagada a matrícula, a decisão sobre um domínio dessa
            // matrícula deixa de ter sujeito. Não é história a preservar — a
            // história das pautas vive nas fotografias, que copiam valores.
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->string('scope', 16);
            $table->foreignId('domain_id')->constrained()->restrictOnDelete();
            // A decisão. NOT NULL de propósito: uma linha sem nível não diz
            // nada que a ausência de linha não diga melhor.
            $table->foreignId('scale_level_id')->constrained('scale_levels')->restrictOnDelete();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // UMA decisão viva por (matrícula, período, âmbito, domínio). Alterar
            // é reescrever esta linha; o que ficou para trás está no rasto de
            // auditoria e nas fotografias já guardadas, não em linhas paralelas.
            $table->unique(
                ['enrollment_id', 'academic_period_id', 'scope', 'domain_id'],
                'domain_decision_unique',
            );
            // A leitura da pauta: uma turma inteira, um período, um âmbito.
            $table->index(
                ['organization_id', 'academic_period_id', 'scope'],
                'domain_decision_org_period_idx',
            );
        });

        $this->addCheck(
            'domain_appreciation_decisions',
            'domain_decision_scope_check',
            "scope IN ('period','accumulated')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_appreciation_decisions');
    }

    /**
     * SQLite (os testes) e MySQL (a produção) não declaram CHECK da mesma
     * maneira, e o SQLite não o adiciona depois de a tabela existir. O mesmo
     * padrão que `create_classifications_tables` já usa.
     */
    protected function addCheck(string $table, string $name, string $expression): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }
};
