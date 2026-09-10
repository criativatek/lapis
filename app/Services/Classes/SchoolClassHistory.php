<?php

namespace App\Services\Classes;

use App\Models\SchoolClass;
use Illuminate\Support\Facades\DB;

/**
 * O QUE JÁ ESTÁ ESCRITO SOBRE UMA TURMA — e que, por isso, a impede de ser
 * eliminada em definitivo, mesmo depois de arquivada e depois de decorridos os
 * três anos de retenção.
 *
 * Quase todas as chaves estrangeiras que chegam a `classes.id` são RESTRICT de
 * propósito: `enrollments`, `class_groups`, `instruments`, `evidence_records`,
 * `interventions`, `interim_assessments`, `self_assessment_templates`, `reports`,
 * `lessons`, `recurring_lesson_slots`, `evaluation_sheet_exports` e
 * `class_profile_migrations`. Uma turma arquivada há três anos que já teve
 * alunos, avaliações ou relatórios QUASE SEMPRE ainda tem linhas nestas
 * tabelas — é exactamente a história que esta aplicação existe para proteger
 * (ver a filosofia de EnrollmentHistory: "NÃO APAGA NADA EM CASCATA"). Eliminar
 * definitivamente recusa nesse caso, com uma frase que diz o que fica.
 *
 * TRÊS TABELAS SÃO `cascadeOnDelete()` e ficam DE FORA desta lista, de
 * propósito: `class_teachers`, `calendar_event_school_class` e
 * `correction_imports` são arrumação organizativa/pivô sem conteúdo
 * pedagógico próprio — a base de dados já as limpa sozinha, e não há nada
 * aqui para o professor decidir.
 */
class SchoolClassHistory
{
    /**
     * Tabela → o nome por que o professor conhece aquilo, em minúscula porque
     * entra a meio de uma frase.
     *
     * @var array<string, string>
     */
    protected const RELATIONS = [
        'enrollments' => 'alunos inscritos',
        'class_groups' => 'grupos da turma',
        'instruments' => 'elementos de avaliação',
        'evidence_records' => 'registos',
        'interventions' => 'estratégias e medidas',
        'interim_assessments' => 'avaliações intercalares',
        // A FK para `classes` vive em `self_assessment_templates` — a tabela
        // `self_assessments` (as respostas) só liga a `enrollment_id` e a
        // `self_assessment_template_id`, nunca diretamente à turma.
        'self_assessment_templates' => 'autoavaliações',
        'reports' => 'relatórios',
        'lessons' => 'aulas',
        'recurring_lesson_slots' => 'tempos do horário',
        'evaluation_sheet_exports' => 'exportações da pauta',
        'class_profile_migrations' => 'migrações de perfil',
    ];

    /**
     * O que impede esta turma de ser eliminada em definitivo, por palavras.
     *
     * Lista vazia significa «pode ser eliminada»: uma turma arquivada, sem uma
     * única linha nas tabelas acima, não tem história nenhuma a proteger.
     *
     * `DB::table()` E NÃO OS MODELOS, pela mesma razão que EnrollmentHistory
     * (linhas ~92-98 desse ficheiro): `instruments`, `evidence_records` e
     * `interventions` usam `SoftDeletes`, e uma linha «apagada» continua a ser
     * uma linha com esta chave estrangeira — continua a fazer um DELETE real
     * falhar na base de dados. A pergunta feita aqui é a mesma que a base de
     * dados faz antes de recusar: existe alguma linha, apagada ou não.
     *
     * @return list<string>
     */
    public function blocking(SchoolClass $class): array
    {
        $found = [];

        foreach (self::RELATIONS as $table => $label) {
            if (in_array($label, $found, true)) {
                continue;
            }

            if (DB::table($table)->where('class_id', $class->getKey())->exists()) {
                $found[] = $label;
            }
        }

        // Já é uma lista — só se acrescenta a `$found`, nunca se atribui por
        // chave — pelo que `array_values()` seria redundante.
        return $found;
    }

    /**
     * A frase que o professor lê.
     *
     * @param  list<string>  $blocking
     */
    public function explain(string $className, array $blocking): string
    {
        return "Não é possível eliminar definitivamente {$className}: a turma ainda tem "
            .$this->enumerate($blocking)
            .'. Estes dados são preservados — a turma permanece arquivada.';
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
