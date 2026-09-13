<?php

namespace App\Services;

use App\Models\Subject;
use Illuminate\Support\Facades\DB;

/**
 * ONDE UMA DISCIPLINA ESTÁ A SER USADA — e que, por isso, a impede de ser
 * eliminada.
 *
 * Eliminar uma disciplina em uso dava 500: `classes`, `assessment_profiles`,
 * `domains` e `lesson_sequences` chegam a `subjects.id` com chaves estrangeiras
 * RESTRICT, e o controller apagava sem perguntar. A base de dados recusava; a
 * aplicação não sabia. É a mesma lição de EnrollmentHistory e
 * SchoolClassHistory, aplicada à disciplina.
 *
 * NÃO APAGA NADA EM CASCATA. `report_library_entries.subject_id` é
 * `nullOnDelete` e está na lista na mesma: apagar a disciplina tiraria em
 * silêncio a restrição «esta estratégia é para Português» a entradas da
 * biblioteca que alguém escreveu. Uma disciplina em uso não se elimina — ponto.
 *
 * `DB::table()` e não os modelos, pela mesma razão das outras duas classes:
 * a pergunta é a da base de dados — existe alguma linha, apagada ou não.
 */
class SubjectUsage
{
    /**
     * Tabela → o nome por que o utilizador conhece aquilo, em minúscula porque
     * entra a meio de uma frase.
     *
     * @var array<string, string>
     */
    protected const RELATIONS = [
        'classes' => 'turmas',
        'assessment_profiles' => 'perfis de avaliação',
        'domains' => 'domínios',
        'lesson_sequences' => 'sequências de aulas',
        'report_library_entries' => 'estratégias da biblioteca',
    ];

    /**
     * @return list<string>
     */
    public function blocking(Subject $subject): array
    {
        $found = [];

        foreach (self::RELATIONS as $table => $label) {
            if (DB::table($table)->where('subject_id', $subject->getKey())->exists()) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /**
     * Quais das disciplinas da lista estão em uso — uma query por relação,
     * nunca uma por disciplina. Apresentação: `blocking()` é a autoridade.
     *
     * @param  list<int>  $subjectIds
     * @return list<int>
     */
    public function idsInUse(array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $inUse = [];

        foreach (array_keys(self::RELATIONS) as $table) {
            $inUse = array_merge($inUse, DB::table($table)
                ->whereIn('subject_id', $subjectIds)
                ->distinct()
                ->pluck('subject_id')
                ->map(fn ($id): int => (int) $id)
                ->all());
        }

        return array_values(array_unique($inUse));
    }

    /**
     * @param  list<string>  $blocking
     */
    public function explain(string $subjectName, array $blocking): string
    {
        return "Não é possível eliminar a disciplina {$subjectName} porque está a ser utilizada por "
            .$this->enumerate($blocking)
            .'. Nenhum dado foi alterado.';
    }

    /**
     * «a, b e c» — a vírgula de série não existe em português.
     *
     * @param  list<string>  $items
     */
    protected function enumerate(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items)." e {$last}";
    }
}
