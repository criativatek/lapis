<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Lição 1, Lição 2, Lição 3» — a numeração sequencial das aulas, garantida no
 * servidor e em mais lado nenhum.
 *
 * UMA SÓ FUNÇÃO, CHAMADA DEPOIS DE TUDO. Em vez de cada caminho de escrita
 * calcular o seu próximo número — a materialização, a inserção intermédia, a
 * eliminação, o deslocamento —, todos eles mexem no que têm de mexer e depois
 * pedem aqui uma renumeração da sequência afetada. É mais barato de raciocinar
 * e, sobretudo, é impossível de dessincronizar: a ordem numérica é recalculada
 * da ordem cronológica real, e não mantida em paralelo com ela.
 *
 * A ORDEM É `starts_at`, E NUNCA `id` NEM `created_at`. Uma aula inserida hoje
 * para a próxima terça-feira tem id maior e data menor do que a de quinta; a
 * ordem pela qual as linhas nasceram não diz nada sobre a ordem pela qual as
 * aulas acontecem. O `id` entra apenas como desempate estável entre duas aulas
 * do mesmo instante (grupos diferentes à mesma hora), para que duas passagens
 * seguidas nunca produzam numerações diferentes.
 *
 * O ÂMBITO É (turma, grupo). T1 e T2 são duas sequências pedagógicas distintas
 * que avançam a ritmos diferentes — é a mesma leitura que
 * LessonController::previousSummary() já faz —, e a turma inteira
 * (`class_group_id` NULL) é ela própria uma sequência. Uma aula de T1 nunca
 * conta para o número da aula seguinte de T2.
 *
 * O HISTÓRICO LECIONADO É INTOCÁVEL (§14). Uma aula já lecionada tem o seu
 * número escrito em cadernos que não estão nesta base de dados. Se uma operação
 * exigisse mudar-lho, a operação inteira é recusada antes de escrever seja o
 * que for — nunca executada até meio.
 */
final class LessonNumbering
{
    /**
     * Renumera a sequência de (turma, grupo) pela ordem cronológica real.
     *
     * Devolve o mapa `lesson_id => numero_novo` apenas das aulas que mudaram,
     * para que quem chama possa mostrar o impacto antes de confirmar (§20).
     *
     * SEMPRE DENTRO DE UMA TRANSAÇÃO DE QUEM CHAMA. Não abre uma sua: a
     * renumeração é a última metade de operações como «inserir e deslocar», e
     * uma transação própria aqui dentro tornaria possível a inserção ficar
     * gravada com a numeração por fazer.
     *
     * @return array<int, int>
     *
     * @throws ValidationException quando fechar a sequência exigiria mudar o
     *                             número de uma aula já lecionada.
     */
    public function resequence(int $classId, ?int $classGroupId): array
    {
        $lessons = $this->sequence($classId, $classGroupId);
        $changes = [];
        $number = 0;

        foreach ($lessons as $lesson) {
            $number++;

            if ($lesson->lesson_number === $number) {
                continue;
            }

            // Uma aula lecionada que ainda não tem número nenhum não está a
            // "mudar" de número: está a receber o primeiro. É o caso de
            // qualquer instalação anterior a esta funcionalidade cuja
            // materialização tenha corrido antes do backfill.
            if ($lesson->status === LessonStatus::Taught && $lesson->lesson_number !== null) {
                throw ValidationException::withMessages([
                    'lesson_number' => __(
                        'Esta operação mudaria o número da aula de :date, que já foi lecionada (Lição :from → Lição :to). O histórico não é renumerado.',
                        [
                            'date' => $lesson->starts_at->setTimezone('Europe/Lisbon')->format('d/m/Y'),
                            'from' => (string) $lesson->lesson_number,
                            'to' => (string) $number,
                        ],
                    ),
                ]);
            }

            $changes[(int) $lesson->getKey()] = $number;
        }

        foreach ($changes as $lessonId => $assigned) {
            DB::table('lessons')->where('id', $lessonId)->update(['lesson_number' => $assigned]);
        }

        return $changes;
    }

    /**
     * A numeração no caminho automático: a materialização, que corre sozinha
     * sempre que alguém abre a semana.
     *
     * A DIFERENÇA PARA `resequence()` É DELIBERADA. Abrir uma semana é uma
     * leitura do ponto de vista do professor, e não pode falhar por causa de um
     * ano letivo mal ordenado: se materializar uma semana ANTIGA criasse aulas
     * anteriores a aulas já lecionadas, a renumeração completa teria de ser
     * recusada (§14) — e recusá-la aqui deixaria a página de aulas inacessível
     * até alguém perceber porquê.
     *
     * Então tenta-se primeiro o caso normal, que é o esmagador: aulas novas no
     * FIM da sequência, onde renumerar não toca em nada já lecionado. Só quando
     * isso colidiria com o histórico é que se cai no plano B — dar às aulas
     * ainda sem número os números seguintes ao maior já atribuído, pela sua
     * ordem cronológica entre si. Ficam com números fora de ordem face às
     * lecionadas, mas ficam com número, são únicas, e nenhum número escrito num
     * caderno mudou. A alternativa seria a página não abrir.
     *
     * @return array<int, int>
     */
    public function numberMaterializedLessons(int $classId, ?int $classGroupId): array
    {
        try {
            return $this->resequence($classId, $classGroupId);
        } catch (ValidationException) {
            // Plano B, descrito acima.
        }

        $sequence = $this->sequence($classId, $classGroupId);
        $next = (int) $sequence->max('lesson_number');
        $changes = [];

        foreach ($sequence as $lesson) {
            if ($lesson->lesson_number !== null) {
                continue;
            }

            $changes[(int) $lesson->getKey()] = ++$next;
        }

        foreach ($changes as $lessonId => $assigned) {
            DB::table('lessons')->where('id', $lessonId)->update(['lesson_number' => $assigned]);
        }

        return $changes;
    }

    /**
     * O que a renumeração FARIA, sem escrever nada — a matéria-prima do
     * «Lição 8 → Lição 9» que a pré-visualização mostra (§20).
     *
     * @return array<int, array{lesson: Lesson, from: int|null, to: int}>
     */
    public function previewResequence(int $classId, ?int $classGroupId): array
    {
        $preview = [];
        $number = 0;

        foreach ($this->sequence($classId, $classGroupId) as $lesson) {
            $number++;

            if ($lesson->lesson_number === $number) {
                continue;
            }

            $preview[(int) $lesson->getKey()] = [
                'lesson' => $lesson,
                'from' => $lesson->lesson_number,
                'to' => $number,
            ];
        }

        return $preview;
    }

    /**
     * Todas as sequências que uma turma tem — a da turma inteira e a de cada
     * grupo que alguma vez teve aula. Lida das próprias aulas, e não da lista
     * de grupos configurados, porque é das aulas que a numeração fala: um grupo
     * criado ontem e ainda sem aulas não tem sequência para renumerar.
     *
     * @return list<int|null>
     */
    public function sequencesOf(int $classId): array
    {
        return array_values(Lesson::query()
            ->where('class_id', $classId)
            ->distinct()
            ->pluck('class_group_id')
            ->map(fn ($value): ?int => $value === null ? null : (int) $value)
            ->unique(strict: true)
            ->all());
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function sequence(int $classId, ?int $classGroupId): Collection
    {
        return Lesson::query()
            ->where('class_id', $classId)
            ->where(fn ($query) => $classGroupId === null
                ? $query->whereNull('class_group_id')
                : $query->where('class_group_id', $classGroupId))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }
}
