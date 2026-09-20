<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentStatus;
use Illuminate\Support\Collection;

/**
 * O que o aluno disse de si próprio, lido SEMPRE da mesma maneira.
 *
 * Resultados, Quadro Síntese e Pauta de Avaliação mostram a autoavaliação lado
 * a lado com o resultado calculado, e têm de a ler exatamente igual. Escrever a
 * leitura duas vezes é como duas telas acabam a discordar sobre o mesmo aluno
 * no mesmo período — e aqui a divergência seria entre o que o aluno disse e o
 * que a aplicação afirma que ele disse.
 *
 * A RESPOSTA É UMA RESPOSTA, NUNCA UM CÁLCULO. A autoavaliação global é a
 * resposta à pergunta que não pertence a domínio nenhum, identificada pelo seu
 * PAPEL declarado — não pela redação, que alguém há de reescrever, nem pela
 * posição, que muda assim que se insere uma pergunta e reatribuiria em silêncio
 * o significado de tudo o que já foi respondido. E nunca é derivada das
 * respostas por domínio: um aluno que classificou quatro domínios e saltou a
 * pergunta global não fez um juízo global, e fazer-lhe a média seria pôr-lhe
 * palavras na boca (§11).
 *
 * NUNCA ENTRA NA CLASSIFICAÇÃO. É informação de apoio à decisão do professor,
 * comparada com a nota e jamais somada a ela (§15).
 */
class SelfAssessmentReading
{
    /**
     * As autoavaliações desta turma neste período, prontas a ler.
     *
     * UMA CONSULTA, nunca uma por aluno (§24). Um rascunho não é uma submissão:
     * o que o aluno ainda está a escrever não é ainda o que ele disse.
     *
     * @return Collection<int, SelfAssessment> indexadas pela matrícula
     */
    public function forClassPeriod(SchoolClass $class, AcademicPeriod $period): Collection
    {
        // DECISÃO DO PRODUCT OWNER (regra das duas perguntas), aplicada e
        // DOCUMENTADA aqui por não ter data própria para decidir de outra
        // forma: uma `SelfAssessment` não tem uma data DELA — não é «este
        // instrumento aplicado a 12 de outubro», é «o juízo global do aluno
        // sobre TODO o período». Sem uma data de evidência para comparar
        // contra a janela, esta pergunta não é a pergunta (A) — é a mesma
        // pergunta (B) que sempre foi: «este aluno frequentava a disciplina
        // A ESTE PERÍODO, lido no seu momento de referência?». Por isso
        // continua exatamente como estava: ClassCohort::for() — o resolver
        // único de «que alunos entram na análise desta disciplina» (ver o
        // seu docblock) — AttendingOnly, à data de `ClassCohort::asOfPeriod()`
        // (M4): o próprio fim do período quando já passou, ou hoje quando o
        // período ainda está em curso.
        $attendingIds = ClassCohort::for($class, ClassCohort::asOfPeriod($period))
            ->attending()
            ->pluck('id');

        return SelfAssessment::query()
            ->where('academic_period_id', $period->getKey())
            ->whereIn('enrollment_id', $attendingIds)
            ->whereIn('status', [SelfAssessmentStatus::Submitted, SelfAssessmentStatus::Reviewed])
            ->with(['responses.question', 'responses.scaleLevel'])
            ->get()
            ->keyBy('enrollment_id');
    }

    /**
     * O juízo global do aluno — a resposta à pergunta com o papel `global`.
     *
     * @return array<string, mixed>|null
     */
    public function global(?SelfAssessment $selfAssessment): ?array
    {
        if ($selfAssessment === null) {
            return null;
        }

        foreach ($selfAssessment->responses as $response) {
            if ($response->question?->role !== SelfAssessmentQuestionRole::Global) {
                continue;
            }

            return $this->level($response);
        }

        return null;
    }

    /**
     * O que o aluno disse sobre UM domínio.
     *
     * @return array<string, mixed>|null
     */
    public function forDomain(?SelfAssessment $selfAssessment, int $domainId): ?array
    {
        if ($selfAssessment === null) {
            return null;
        }

        foreach ($selfAssessment->responses as $response) {
            $question = $response->question;

            if ($question === null || $question->answer_kind !== 'scale') {
                continue;
            }

            if ($question->domain_id !== $domainId) {
                continue;
            }

            return $this->level($response);
        }

        return null;
    }

    /**
     * O nível escolhido, com a sua identidade completa.
     *
     * O NÚMERO É O JUÍZO — um 4, um 16. A menção qualitativa vem ao lado e
     * nunca no lugar dele; `sequence` e `is_negative` são o que permite a um
     * ecrã dar-lhe cor sem ler o rótulo (§7).
     *
     * @return array<string, mixed>|null
     */
    protected function level(SelfAssessmentResponse $response): ?array
    {
        $level = $response->scaleLevel;

        return $level === null ? null : [
            'code' => (string) $level->code,
            'label' => (string) $level->label,
            'sequence' => (int) $level->sequence,
            'is_negative' => (bool) $level->is_negative,
        ];
    }
}
