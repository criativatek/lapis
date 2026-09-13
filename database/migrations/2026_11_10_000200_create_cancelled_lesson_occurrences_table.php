<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Esta ocorrência do horário foi eliminada de propósito.»
 *
 * PORQUE É QUE ISTO PRECISA DE EXISTIR. Abrir a semana materializa-a: as aulas
 * nascem sozinhas a partir do horário recorrente, e é isso que faz a
 * funcionalidade ser cómoda. Mas significa também que eliminar a aula de uma
 * quinta-feira e voltar à semana a fazia renascer no instante seguinte —
 * validado no browser antes de esta tabela existir. Um «eliminar» que se desfaz
 * sozinho ao recarregar a página não é um eliminar.
 *
 * A ALTERNATIVA ERA APAGAR O TEMPO DO HORÁRIO, e é exatamente o que §4 proíbe:
 * a rotina («Matemática às quintas») tem de sobreviver intacta à eliminação de
 * uma das suas ocorrências. Esta tabela é a forma de dizer as duas coisas ao
 * mesmo tempo — a rotina continua, aquela quinta-feira não.
 *
 * GUARDA UMA DATA E UM TEMPO, E NÃO UMA AULA. A aula foi-se; o que fica
 * registado é a coordenada (turma, tempo do horário, instante) que a
 * materialização tem de saltar. É por isso que não há aqui nenhuma chave
 * estrangeira para `lessons`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancelled_lesson_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // EM CASCATA, e não RESTRICT como quase tudo o que aponta para
            // `classes`. Uma marca de cancelamento não é história pedagógica: é
            // a ausência de uma aula, e diz apenas à materialização para não a
            // criar. Bloquear a eliminação definitiva de uma turma arquivada há
            // três anos porque alguém uma vez eliminou uma aula seria pedir ao
            // professor uma decisão sobre nada — e listá-la entre «o que fica»
            // seria chamar-lhe história, que não é. Fica ao lado de
            // `class_teachers` e `calendar_event_school_class` em
            // SchoolClassHistory, que a base de dados limpa sozinha.
            //
            // O mesmo raciocínio vale para o grupo e para o tempo do horário:
            // desaparecendo o horário que a gerou, a marca deixa de ter a quem
            // se referir.
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('class_group_id')->nullable()
                ->constrained('class_groups')->cascadeOnDelete();
            $table->foreignId('recurring_lesson_slot_id')
                ->constrained('recurring_lesson_slots')->cascadeOnDelete();
            $table->dateTime('occurs_at');
            $table->foreignId('cancelled_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // A mesma identidade que `lessons_class_slot_start_unique` dá a uma
            // aula — (turma, tempo do horário, início) —, para que a exclusão
            // case exatamente com a linha que impede de nascer, e para que
            // eliminar duas vezes a mesma ocorrência não deixe duas marcas.
            $table->unique(
                ['class_id', 'recurring_lesson_slot_id', 'occurs_at'],
                'cancelled_occurrences_class_slot_start_unique',
            );
            $table->index(
                ['organization_id', 'class_id', 'occurs_at'],
                'cancelled_occurrences_org_class_start_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancelled_lesson_occurrences');
    }
};
