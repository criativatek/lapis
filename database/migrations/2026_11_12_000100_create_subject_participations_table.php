<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UM PERÍODO EM QUE O ALUNO NÃO FREQUENTA ESTA DISCIPLINA.
 *
 * SEM LINHA = A FREQUENTAR. É esta a decisão que torna a migração puramente
 * aditiva: TODAS as inscrições existentes continuam, sem escrever um único
 * registo, a significar «este aluno frequenta esta disciplina» — que é
 * exactamente o que já significavam antes desta tabela existir. Não há aqui um
 * estado «attending» a povoar; há apenas a ausência disso, que já é verdade.
 *
 * PRENDE-SE À INSCRIÇÃO, NÃO A UMA COLUNA NOVA EM `classes`. Uma linha de
 * `classes` já É o par (turma, disciplina) — «8.º F · Português» e «8.º F ·
 * PLNM» são duas linhas que partilham o `label` «8.º F» mas têm `subject_id`
 * diferente — e por isso uma `Enrollment` já É o triplo (aluno, turma,
 * disciplina). Um aluno que não frequenta Português continua inscrito nessa
 * `Enrollment` (o `enrollments.status` continua `active`): o que muda não é
 * onde ele está, é se o que ali se passa entra ou não na avaliação.
 *
 * `reason` É `alternative_subject` + `reason_detail` LIVRE, NUNCA UM ENUM COM
 * UM CASO `plnm`. O conjunto dos percursos alternativos a uma disciplina
 * (PLNM, mas também qualquer outro que o currículo de uma escola preveja) é
 * currículo de cada escola, não desta aplicação — um caso de enum por
 * currículo significaria uma migração por escola. `reason_detail` guarda o
 * texto («PLNM») sem que o domínio tenha de conhecer PLNM, ou coisa nenhuma
 * parecida, à partida.
 *
 * A NÃO-SOBREPOSIÇÃO NÃO CABE NUMA CONSTRAINT, pela mesma razão que
 * `class_group_memberships` já documenta: nem o MySQL nem o SQLite têm
 * exclusão por intervalos. A invariante «uma inscrição não tem duas janelas de
 * não-frequência abertas ao mesmo tempo» é imposta pelas ações
 * (App\Actions\SubjectParticipation\*), todas com lockForUpdate e
 * re-verificação dentro da transação. O que a tabela garante é o que uma
 * CHECK consegue garantir: um intervalo bem formado, um estado do vocabulário,
 * e um motivo do vocabulário.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_participations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // RESTRICT como todas as outras chaves que apontam para uma
            // inscrição (§ App\Services\EnrollmentHistory): uma janela de
            // não-frequência é uma arrumação sobre a inscrição, e apagar a
            // inscrição não pode fazer desaparecer em silêncio o registo de
            // que o aluno esteve, durante um período, fora da disciplina.
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->string('state', 16);
            $table->string('reason', 32);
            $table->string('reason_detail', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'enrollment_id', 'effective_from', 'effective_until'],
                'subject_participations_enrollment_window_idx',
            );
        });

        $this->addCheck(
            'subject_participations',
            'subject_participations_window_check',
            'effective_until IS NULL OR effective_until >= effective_from',
        );
        $this->addCheck(
            'subject_participations',
            'subject_participations_state_check',
            "state IN ('not_attending')",
        );
        $this->addCheck(
            'subject_participations',
            'subject_participations_reason_check',
            "reason IN ('alternative_subject','other')",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_participations');
    }

    /**
     * SQLite (os testes) não declara CHECK constraints por ALTER TABLE — só o
     * MySQL/MariaDB de produção o fazem aqui. O mesmo padrão que
     * `class_group_memberships` e `domain_appreciation_decisions` já usam.
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
