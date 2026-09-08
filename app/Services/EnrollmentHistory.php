<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\DB;

/**
 * O QUE JÁ ESTÁ ESCRITO SOBRE UMA INSCRIÇÃO — e que, por isso, a impede de ser
 * apagada.
 *
 * Uma inscrição é o eixo de tudo o que se avalia: nenhum resultado aponta para
 * um aluno, aponta para o par (aluno, turma) (§11.4). Todas as chaves
 * estrangeiras que lhe chegam são RESTRICT de propósito — apagar a inscrição
 * apagaria história pedagógica, e não é isso que «remover da turma» quer dizer.
 * A base de dados recusa; o que faltava era a aplicação SABER disso antes de
 * tentar, em vez de deixar a recusa subir como erro 500 (§13.3, §30).
 *
 * UM SÓ SÍTIO. As dependências estavam espalhadas por nove migrations e nenhum
 * ecrã as conhecia. Passam a estar aqui, uma vez, com o nome pelo qual o
 * professor as conhece no menu — porque a mensagem que ele lê tem de dizer o
 * que ele reconhece, não o nome de uma tabela.
 *
 * NÃO APAGA NADA EM CASCATA. `domain_appreciation_decisions` é a única relação
 * com `cascadeOnDelete` e está nesta lista na mesma: uma apreciação por domínio
 * é uma decisão do professor, e desaparecer em silêncio atrás de um botão
 * «Remover» seria exatamente o que o resto desta lista existe para evitar.
 */
class EnrollmentHistory
{
    /**
     * Tabela → o nome por que o professor conhece aquilo, em minúscula porque
     * entra a meio de uma frase.
     *
     * DUAS TABELAS PODEM PARTILHAR UM RÓTULO (as medidas têm a sua e o seu
     * pivô): a lista devolvida é deduplicada, para que a mensagem não diga
     * duas vezes a mesma coisa.
     *
     * @var array<string, string>
     */
    protected const RELATIONS = [
        'student_item_scores' => 'avaliações registadas',
        'enrollment_instrument_applicability' => 'elementos de avaliação marcados como não aplicáveis',
        'calculation_snapshots' => 'cálculos guardados',
        'classifications' => 'classificações',
        'domain_appreciation_decisions' => 'apreciações por domínio',
        'self_assessments' => 'autoavaliações',
        'evidence_records' => 'registos',
        'interventions' => 'estratégias e medidas',
        'intervention_enrollment' => 'estratégias e medidas',
        'reports' => 'relatórios',
    ];

    /**
     * O QUE VAI COM A INSCRIÇÃO, em vez de a impedir de sair.
     *
     * A lista acima existe porque apagar aquelas linhas apagaria história
     * pedagógica. Uma pertença a um grupo do horário não é isso: é uma
     * arrumação organizativa, e não sobrevive a si própria — não existe «o T1
     * do aluno que já não está na turma».
     *
     * E PÔ-LA NA LISTA DE CIMA CRIARIA UM BECO. Um aluno acrescentado por
     * engano, marcado para T1 e ainda sem uma única avaliação deixaria de
     * poder ser removido, para sempre, por causa de uma caixa que o professor
     * marcou — e nem sequer tirá-lo do grupo o desbloquearia, porque a janela
     * fechada continua a ser uma linha. É exatamente a irreversibilidade que
     * esta aplicação tem por regra não introduzir.
     *
     * O QUE NÃO SE PERDE: o grupo com que cada AULA nasceu está guardado em
     * `lessons.class_group_id`, na própria aula, e não aqui. Apagar estas
     * linhas não altera uma única aula nem um único sumário.
     *
     * A chave estrangeira continua RESTRICT como todas as outras — a base de
     * dados nunca apaga isto sozinha, às escondidas. Quem o apaga é
     * EnrollmentController::destroy(), de propósito, na mesma transação, e só
     * depois de `blocking()` ter respondido que não há história nenhuma.
     *
     * @var array<string, string>
     */
    protected const CLEARED_WITH_ENROLLMENT = [
        'class_group_memberships' => 'pertenças a grupos da turma',
    ];

    /**
     * O que impede esta inscrição de ser removida, por palavras.
     *
     * Lista vazia significa «pode ser removida»: uma inscrição enganada, criada
     * há um minuto e sobre a qual ainda ninguém escreveu nada, continua a
     * apagar-se como sempre se apagou.
     *
     * `DB::table()` E NÃO OS MODELOS, por duas razões e nenhuma é conveniência.
     * Metade destas tabelas não tem modelo — são pivôs. E, sobretudo,
     * `evidence_records` tem `softDeletes`: um registo «apagado» continua a ser
     * uma linha com esta chave estrangeira e continua a fazer o DELETE falhar,
     * pelo que um `EvidenceRecord::where(...)->exists()` responderia que não há
     * nada e devolveria o professor ao erro genérico. A pergunta feita aqui é
     * a mesma que a base de dados faz antes de recusar: existe alguma linha.
     *
     * Não há fuga de tenancy: a inscrição chega já resolvida dentro da
     * organização, e o id é único em toda a tabela.
     *
     * @return list<string>
     */
    public function blocking(Enrollment $enrollment): array
    {
        $found = [];

        foreach (self::RELATIONS as $table => $label) {
            if (in_array($label, $found, true)) {
                continue;
            }

            if (DB::table($table)->where('enrollment_id', $enrollment->getKey())->exists()) {
                $found[] = $label;
            }
        }

        return array_values($found);
    }

    /**
     * Apaga o que acompanha a inscrição, imediatamente antes de a apagar.
     *
     * SÓ É CHAMADO DEPOIS DE `blocking()` TER DEVOLVIDO VAZIO — é essa a
     * pré-condição, e é o que faz disto uma limpeza e não uma perda: uma
     * inscrição sem uma única avaliação, evidência, classificação ou
     * relatório não tem história que estas linhas possam estar a guardar.
     *
     * `DB::table()` pela mesma razão que `blocking()`: a pergunta feita aqui
     * é a mesma que a base de dados faria antes de recusar o DELETE.
     */
    public function clearAccompanying(Enrollment $enrollment): void
    {
        foreach (array_keys(self::CLEARED_WITH_ENROLLMENT) as $table) {
            DB::table($table)->where('enrollment_id', $enrollment->getKey())->delete();
        }
    }

    /**
     * Quais das inscrições desta turma já têm história — para o ecrã da turma
     * poder dizê-lo ANTES de o professor clicar.
     *
     * UMA QUERY POR RELAÇÃO, NUNCA UMA POR ALUNO. Dez queries com um `IN` de
     * ids, seja a turma de oito alunos ou de trinta: o custo é o mesmo e não
     * cresce com a pauta. Uma verificação por aluno seria dez vezes o número de
     * alunos, e é precisamente o que não se faz para esconder um botão.
     *
     * @return list<int> ids de inscrição, os que NÃO podem ser removidos
     */
    public function idsWithHistoryIn(SchoolClass $class): array
    {
        $enrollmentIds = $class->enrollments()->pluck('id')->all();

        if ($enrollmentIds === []) {
            return [];
        }

        $withHistory = [];

        foreach (array_keys(self::RELATIONS) as $table) {
            $remaining = array_values(array_diff($enrollmentIds, $withHistory));

            // Nada por perguntar: se já todos têm história, as tabelas
            // seguintes não mudam a resposta.
            if ($remaining === []) {
                break;
            }

            $withHistory = array_merge($withHistory, DB::table($table)
                ->whereIn('enrollment_id', $remaining)
                ->distinct()
                ->pluck('enrollment_id')
                ->map(fn ($id): int => (int) $id)
                ->all());
        }

        return array_values(array_unique($withHistory));
    }

    /**
     * A frase que o professor lê. Diz o que existe e o que acontece aos dados —
     * nada mais.
     *
     * NÃO MANDA O PROFESSOR FAZER OUTRA COISA. Não existe hoje, em lado nenhum
     * da aplicação, uma ação manual que passe uma inscrição a «Transferido» ou
     * «Saiu»: esse estado só é escrito ao importar a relação de turma da escola
     * (RosterImportController + StudentEnrollmentService::fillFromRoster). Uma
     * mensagem a mandar «marcar como transferido» mandaria clicar num botão que
     * não existe, e isso é pior do que não sugerir nada.
     *
     * @param  list<string>  $blocking
     */
    public function explain(string $studentName, array $blocking): string
    {
        return "Não é possível remover {$studentName} da turma: já tem "
            .$this->enumerate($blocking)
            .' nesta turma. Estes dados são preservados.';
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
