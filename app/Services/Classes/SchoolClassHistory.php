<?php

namespace App\Services\Classes;

use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Services\EnrollmentHistory;
use Illuminate\Database\Query\Builder;
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
 * QUATRO TABELAS SÃO `cascadeOnDelete()` e ficam DE FORA desta lista, de
 * propósito: `class_teachers`, `calendar_event_school_class`,
 * `correction_imports` e `cancelled_lesson_occurrences` são arrumação
 * organizativa/pivô sem conteúdo pedagógico próprio — a base de dados já as
 * limpa sozinha, e não há nada aqui para o professor decidir. A última é a
 * marca de «esta ocorrência do horário foi eliminada de propósito»: diz à
 * materialização para não criar uma aula, e a ausência de uma aula não é
 * história que se proteja.
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
        'class_characterisations' => 'caracterização da turma',
        // A proveniência de uma importação confirmada. Bloqueia por ser o
        // registo de que aquilo entrou, de onde e por quem — apagá-la deixaria
        // caracterizações cuja origem já ninguém consegue explicar.
        'characterisation_import_batches' => 'importações de caracterização',
    ];

    /**
     * O que, numa turma em preparação, é configuração e não história — e sai
     * com ela (§ blockingInPreparation). As aulas estão aqui porque a vista
     * semanal as materializa a partir do horário; as que têm conteúdo são
     * apanhadas à parte, por `lessonsWithContent()`.
     *
     * @var list<string>
     */
    protected const PREPARATORY_TABLES = [
        'enrollments',
        'class_groups',
        'recurring_lesson_slots',
        'lessons',
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
     * O que impede uma turma EM PREPARAÇÃO de ser eliminada já — sem arquivar
     * e sem esperar pelos três anos de retenção.
     *
     * UMA TURMA CRIADA POR ENGANO não tem história nenhuma a proteger, mas
     * raramente está vazia: o professor já lhe pôs alunos, dividiu-a em T1/T2,
     * configurou o horário, e a vista semanal já materializou as aulas desse
     * horário. Nada disso é história pedagógica — é preparação — e está em
     * PREPARATORY_TABLES. Mandar arquivar e esperar três anos por uma turma
     * que nunca existiu seria a irreversibilidade que a aplicação evita.
     *
     * O QUE CONTINUA A BLOQUEAR é tudo o resto de RELATIONS (elementos de
     * avaliação, registos, medidas, intercalares, autoavaliações, relatórios,
     * exportações da pauta, migrações de perfil) e ainda:
     *   - uma inscrição com história (EnrollmentHistory::RELATIONS);
     *   - uma aula que já não é só uma ocorrência do horário — lecionada,
     *     preparada, ou com sumário ou planificação escritos.
     *
     * «Nunca ativada» é o próprio estado: não existe caminho de «Ativa» de
     * volta a «Em preparação» (ClassController::activate só avança, e o
     * ClassRequest não aceita `status`). Uma turma arquivada segue a regra de
     * retenção de `blocking()`, nunca esta.
     *
     * @return list<string>
     */
    public function blockingInPreparation(SchoolClass $class): array
    {
        $found = [];

        foreach (self::RELATIONS as $table => $label) {
            if (in_array($table, self::PREPARATORY_TABLES, true) || in_array($label, $found, true)) {
                continue;
            }

            if (DB::table($table)->where('class_id', $class->getKey())->exists()) {
                $found[] = $label;
            }
        }

        if (app(EnrollmentHistory::class)->idsWithHistoryIn($class) !== []) {
            $found[] = 'alunos com registos pedagógicos';
        }

        if ($this->lessonsWithContent($class)->exists()) {
            $found[] = 'aulas lecionadas, preparadas ou com sumário';
        }

        return $found;
    }

    /**
     * Apaga a preparação de uma turma, imediatamente antes de a apagar.
     *
     * SÓ É CHAMADO DEPOIS DE `blockingInPreparation()` TER DEVOLVIDO VAZIO, e
     * dentro da mesma transação que apaga a turma. Apaga apenas linhas que
     * pertencem a ESTA turma: nunca um `Student`, uma identidade, uma
     * fotografia, nem a inscrição do mesmo aluno noutra turma — o aluno de uma
     * turma de apoio continua inteiro na sua turma de origem.
     *
     * A ordem é a das chaves estrangeiras RESTRICT: aulas → pertenças a grupos
     * → janelas de frequência da disciplina → inscrições → tempos do horário →
     * grupos. As ocorrências canceladas, os professores da turma e o pivô dos
     * eventos saem sozinhos (cascade).
     *
     * ESTA LISTA TEM DE ACOMPANHAR `EnrollmentHistory::CLEARED_WITH_ENROLLMENT`.
     * As duas dizem a mesma coisa por caminhos diferentes — «isto sai com a
     * inscrição, não a impede de sair» — mas aquela é percorrida em ciclo e
     * esta está escrita à mão, pelo que uma entrada nova ali não aparece aqui
     * sozinha. Quando não aparece, `blockingInPreparation()` não vê nada a
     * bloquear (só consulta `RELATIONS`), o DELETE às inscrições bate na
     * RESTRICT e o professor recebe uma recusa sem causa nomeada, para sempre:
     * exatamente o beco que pôr estas tabelas fora de `RELATIONS` existe para
     * evitar.
     */
    public function clearPreparation(SchoolClass $class): void
    {
        $classId = $class->getKey();
        $enrollmentIds = DB::table('enrollments')->where('class_id', $classId)->pluck('id');

        // Só as aulas ainda por preparar. Uma que passe a lecionada entre a
        // verificação e este DELETE fica, e é a chave estrangeira da turma que
        // recusa — a transação desfaz tudo. Sumários e planificações têm a
        // sua própria RESTRICT.
        DB::table('lessons')->where('class_id', $classId)->where('status', LessonStatus::Preparation->value)->delete();
        DB::table('class_group_memberships')->whereIn('enrollment_id', $enrollmentIds)->delete();
        DB::table('subject_participations')->whereIn('enrollment_id', $enrollmentIds)->delete();
        DB::table('enrollments')->where('class_id', $classId)->delete();
        DB::table('recurring_lesson_slots')->where('class_id', $classId)->delete();
        DB::table('class_groups')->where('class_id', $classId)->delete();
    }

    /**
     * @param  list<string>  $blocking
     */
    public function explainInPreparation(string $className, array $blocking): string
    {
        return "Não é possível eliminar {$className}: a turma já tem "
            .$this->enumerate($blocking)
            .'. Estes dados são preservados — pode arquivar a turma em vez de a eliminar.';
    }

    /**
     * Aulas que são mais do que uma ocorrência do horário. `DB::table()` pela
     * mesma razão que `blocking()`.
     */
    protected function lessonsWithContent(SchoolClass $class): Builder
    {
        return DB::table('lessons')
            ->where('class_id', $class->getKey())
            ->where(fn (Builder $query) => $query
                ->where('status', '!=', LessonStatus::Preparation->value)
                ->orWhereExists(fn (Builder $summaries) => $summaries->from('lesson_summaries')->whereColumn('lesson_summaries.lesson_id', 'lessons.id'))
                ->orWhereExists(fn (Builder $plans) => $plans->from('lesson_plans')->whereColumn('lesson_plans.lesson_id', 'lessons.id')));
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
