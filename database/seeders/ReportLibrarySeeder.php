<?php

namespace Database\Seeders;

use App\Models\ReportLibraryEntry;
use Illuminate\Database\Seeder;

/**
 * The starter pedagogical library (§16).
 *
 * DIFFICULTY → STRATEGY → OBJECTIVE, and nothing loose. Every strategy below
 * answers a named difficulty and states what it is FOR. That relation is the
 * whole reason the library exists: a flat list of measures produces reports
 * where «diferenciação pedagógica; reforço positivo; trabalho de pares» appears
 * under every difficulty ever recorded, which is what makes a generated report
 * read like a form letter (§14).
 *
 * DELIBERATELY SMALL. This is a floor, not a curriculum: a dozen difficulties
 * with two strategies each, covering the ground the brief's own examples walk
 * over. A school under Pro adds its own beside them without any of this moving.
 * Growing it is content work, not engineering, and doing it here at volume
 * would be inventing pedagogy nobody approved (§1).
 *
 * NO LAW IN IT (§17). These are pedagogical formulations. They cite no statute
 * and claim no legal effect — legal framing lives in InterventionLegalFramework,
 * where it can change when the law does without touching a single sentence a
 * teacher ever printed.
 *
 * SUBJECT-NEUTRAL WHERE POSSIBLE. «Planificação da escrita» belongs to more
 * subjects than Português, so nothing here is pinned to one; `subject_id`
 * exists for entries a school later adds that genuinely are.
 *
 * Idempotent — matched on (organization_id, kind, code), which is unique.
 */
class ReportLibrarySeeder extends Seeder
{
    /**
     * code => label.
     *
     * @var array<string, string>
     */
    protected const DIFFICULTIES = [
        'implicit_information' => 'Interpretação de informação implícita',
        'statement_interpretation' => 'Interpretação de enunciados',
        'writing_planning' => 'Planificação da escrita',
        'text_organisation' => 'Organização e coerência textual',
        'answer_justification' => 'Fundamentação das respostas',
        'content_consolidation' => 'Consolidação de conteúdos anteriores',
        'study_methods' => 'Métodos de estudo e organização do trabalho',
        'attention_span' => 'Atenção e concentração nas tarefas',
        'autonomy' => 'Autonomia na realização das tarefas',
        'task_persistence' => 'Persistência perante tarefas de maior exigência',
        'oral_expression' => 'Expressão oral estruturada',
        'error_review' => 'Revisão e correção do trabalho produzido',
    ];

    /**
     * difficulty code => list of [label, objective].
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    protected const STRATEGIES = [
        'implicit_information' => [
            ['Leitura orientada com identificação de pistas textuais', 'melhorar a compreensão e a fundamentação das respostas'],
            ['Questionamento progressivo sobre o texto, do explícito ao implícito', 'desenvolver a inferência a partir de evidência do próprio texto'],
        ],
        'statement_interpretation' => [
            ['Análise conjunta do enunciado antes da resolução, com identificação do que é pedido', 'reduzir erros que decorrem da leitura do enunciado e não do conteúdo'],
            ['Glossário de verbos de instrução — indica, justifica, compara, explica', 'tornar explícito o tipo de resposta que cada instrução exige'],
        ],
        'writing_planning' => [
            ['Guiões de planificação prévia e revisão orientada', 'melhorar a organização, a coerência e a clareza textual'],
            ['Escrita por etapas, com planificação, textualização e revisão separadas', 'tornar visível o processo de escrita e não apenas o produto final'],
        ],
        'text_organisation' => [
            ['Trabalho explícito sobre parágrafo, conectores e progressão temática', 'melhorar a coesão e a sequência das ideias'],
            ['Reescrita orientada de textos próprios a partir de critérios conhecidos', 'consolidar a estrutura textual através da revisão do próprio trabalho'],
        ],
        'answer_justification' => [
            ['Modelação de respostas fundamentadas, com transcrição de evidência', 'associar sistematicamente a afirmação à prova que a sustenta'],
            ['Análise comparada de respostas com e sem fundamentação', 'tornar reconhecível a diferença entre afirmar e justificar'],
        ],
        'content_consolidation' => [
            ['Revisão sistemática e distribuída dos conteúdos anteriores', 'consolidar aprendizagens que servem de base às seguintes'],
            ['Tarefas curtas de recuperação no início das aulas', 'reativar conhecimento prévio antes de introduzir conteúdo novo'],
        ],
        'study_methods' => [
            ['Apoio à organização do caderno, dos materiais e do tempo de estudo', 'criar rotinas de trabalho que sustentem a autonomia'],
            ['Construção conjunta de sínteses, esquemas e mapas de conteúdo', 'desenvolver instrumentos de estudo próprios'],
        ],
        'attention_span' => [
            ['Segmentação das tarefas em etapas curtas com verificação intermédia', 'sustentar a atenção ao longo da tarefa'],
            ['Redução de distratores e clarificação prévia do objetivo de cada tarefa', 'tornar explícito o que se espera em cada momento'],
        ],
        'autonomy' => [
            ['Redução gradual do apoio prestado ao longo da tarefa', 'transferir progressivamente a responsabilidade para o aluno'],
            ['Listas de verificação para uso antes de solicitar ajuda', 'promover a autorregulação na resolução de dificuldades'],
        ],
        'task_persistence' => [
            ['Sequenciação das tarefas por grau de exigência crescente', 'permitir experiências de sucesso que sustentem o esforço seguinte'],
            ['Definição conjunta de metas curtas e verificáveis', 'tornar o progresso visível ao próprio aluno'],
        ],
        'oral_expression' => [
            ['Preparação prévia das intervenções orais com guião de apoio', 'estruturar o discurso antes da exposição'],
            ['Apresentações curtas em pequeno grupo antes do grande grupo', 'reduzir a exposição e aumentar a frequência de prática'],
        ],
        'error_review' => [
            ['Correção comentada dos instrumentos, com registo do que rever', 'transformar o erro em informação utilizável pelo aluno'],
            ['Rotina de revisão antes da entrega, a partir de critérios conhecidos', 'consolidar o hábito de rever o trabalho produzido'],
        ],
    ];

    public function run(): void
    {
        $order = 0;

        foreach (self::DIFFICULTIES as $code => $label) {
            ReportLibraryEntry::withoutGlobalScope('organization')->updateOrCreate(
                ['organization_id' => null, 'kind' => ReportLibraryEntry::KIND_DIFFICULTY, 'code' => $code],
                ['label' => $label, 'sort_order' => $order += 10, 'active' => true],
            );
        }

        foreach (self::STRATEGIES as $difficulty => $strategies) {
            foreach ($strategies as $index => [$label, $objective]) {
                ReportLibraryEntry::withoutGlobalScope('organization')->updateOrCreate(
                    [
                        'organization_id' => null,
                        'kind' => ReportLibraryEntry::KIND_STRATEGY,
                        // A strategy's code has to be stable and unique across
                        // the whole library, so it carries the difficulty it
                        // answers — the same reason `related_code` exists.
                        'code' => $difficulty.'_'.($index + 1),
                    ],
                    [
                        'label' => $label,
                        'objective' => $objective,
                        'related_code' => $difficulty,
                        'sort_order' => ($index + 1) * 10,
                        'active' => true,
                    ],
                );
            }
        }
    }
}
