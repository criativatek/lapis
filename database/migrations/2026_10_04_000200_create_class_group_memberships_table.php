<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quem pertence um grupo, E DESDE QUANDO.
 *
 * NÃO É UMA COLUNA EM `enrollments`. Uma coluna diria só onde o aluno está
 * hoje, e a pergunta que o horário faz é sempre datada: «quem estava em T1 na
 * aula de 12 de novembro?». Guardada como intervalo, a resposta de novembro
 * continua verdadeira depois de o aluno mudar para T2 em janeiro.
 *
 * `effective_until` NULL = ainda em vigor. Uma mudança fecha a pertença atual
 * no dia anterior e abre outra na data — nunca reescreve a linha antiga
 * (§4 e §10 do briefing), exatamente como ReviseRecurringLessonSlot faz aos
 * tempos do horário.
 *
 * A NÃO-SOBREPOSIÇÃO NÃO CABE NUMA CONSTRAINT. Nem o MySQL nem o SQLite têm
 * exclusão por intervalos; a invariante «uma inscrição não tem duas pertenças
 * abertas ao mesmo tempo na mesma turma» é imposta pelas ações
 * (App\Actions\ClassGroups\*), todas com lockForUpdate e re-verificação dentro
 * da transação. O que a tabela garante é o que uma CHECK consegue garantir:
 * um intervalo bem formado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_group_memberships', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_group_id')->constrained('class_groups')->restrictOnDelete();
            // RESTRICT como todas as outras chaves que apontam para uma
            // inscrição (§ App\Services\EnrollmentHistory): remover um aluno da
            // turma não pode apagar em silêncio o registo de que ele esteve em
            // T1 durante um período.
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'enrollment_id', 'effective_from', 'effective_until'],
                'class_group_memberships_enrollment_window_idx',
            );
            $table->index(
                ['class_group_id', 'effective_from', 'effective_until'],
                'class_group_memberships_group_window_idx',
            );
        });

        $this->addCheck(
            'class_group_memberships',
            'class_group_memberships_window_check',
            'effective_until IS NULL OR effective_until >= effective_from',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('class_group_memberships');
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
};
