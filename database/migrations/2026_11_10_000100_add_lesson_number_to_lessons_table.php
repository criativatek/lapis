<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Lição 12» — o número por que o professor e o aluno conhecem a aula.
 *
 * NÃO EXISTIA DE TODO antes desta migração: nem coluna, nem número visual, nem
 * qualquer dependência em relatórios ou exportações. É por isso que a coluna
 * nasce nullable e é preenchida aqui mesmo, em vez de deixar todo o histórico
 * sem número até alguém mexer em cada aula.
 *
 * O ÂMBITO DA SEQUÊNCIA É (turma, grupo), e não a turma sozinha. Numa turma
 * desdobrada, T1 e T2 são duas sequências pedagógicas distintas que avançam a
 * ritmos diferentes — é a mesma leitura que LessonController::previousSummary()
 * já faz ao recusar oferecer a T1 o sumário de T2. A turma inteira
 * (`class_group_id` NULL) é ela própria uma sequência.
 *
 * SEM UNIQUE NA BASE DE DADOS, de propósito: em MySQL dois NULL nunca colidem
 * num índice único, pelo que uma chave `(class_id, class_group_id,
 * lesson_number)` deixaria passar precisamente o caso mais comum — a turma
 * inteira, onde `class_group_id` é NULL. A unicidade é garantida onde é
 * verificável: LessonNumbering, sob o mesmo bloqueio de turma que a
 * materialização já usa. O índice aqui é de leitura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->unsignedInteger('lesson_number')->nullable()->after('ends_at');
            $table->index(
                ['class_id', 'class_group_id', 'lesson_number'],
                'lessons_class_group_number_idx',
            );
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex('lessons_class_group_number_idx');
            $table->dropColumn('lesson_number');
        });
    }

    /**
     * A numeração inicial de tudo o que já existe, pela MESMA regra que
     * LessonNumbering aplica daqui para a frente: ordem cronológica dentro de
     * cada (turma, grupo), começando em 1.
     *
     * Em SQL cru e não por Eloquent: uma migração corre fora de qualquer
     * tenant, e o global scope de organização de `Lesson` lançaria
     * TenantNotResolvedException à primeira consulta. `organization_id` não
     * precisa de entrar na ordenação porque `class_id` já pertence a uma só
     * organização.
     *
     * Por lotes, e nunca uma consulta por aula: escolas com anos inteiros
     * materializados têm dezenas de milhares de linhas.
     */
    private function backfill(): void
    {
        $number = 0;
        $previousKey = null;

        DB::table('lessons')
            ->select(['id', 'class_id', 'class_group_id'])
            ->orderBy('class_id')
            ->orderByRaw('class_group_id IS NULL DESC')
            ->orderBy('class_group_id')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->chunk(1000, function ($lessons) use (&$number, &$previousKey): void {
                $updates = [];

                foreach ($lessons as $lesson) {
                    $key = $lesson->class_id.':'.($lesson->class_group_id ?? 'all');

                    if ($key !== $previousKey) {
                        $previousKey = $key;
                        $number = 0;
                    }

                    $updates[$lesson->id] = ++$number;
                }

                foreach ($updates as $id => $assigned) {
                    DB::table('lessons')->where('id', $id)->update(['lesson_number' => $assigned]);
                }
            });
    }
};
