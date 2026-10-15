<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AssessmentProfileVersion;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentStatus;
use App\Support\Assessment\SelfAssessmentAnswerScale;
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

        $selfAssessments = SelfAssessment::query()
            ->where('academic_period_id', $period->getKey())
            ->whereIn('enrollment_id', $attendingIds)
            ->whereIn('status', [SelfAssessmentStatus::Submitted, SelfAssessmentStatus::Reviewed])
            ->with(self::relations())
            ->get();

        return $this->newestPerStudentPeriod($selfAssessments)->keyBy('enrollment_id');
    }

    /**
     * What a read of self-assessments loads: the answers, and the criteria they
     * were given under — the template's profile version, its scale, and the
     * class's version to compare it with. One query each, never one per student.
     *
     * The profile is read `withTrashed()`: an archived profile still names the
     * criteria a self-assessment was answered with.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return [
            'responses.question.scale',
            'responses.scaleLevel.scale',
            'template.profileVersion.scale',
            'template.profileVersion.profile' => fn ($query) => $query->withTrashed(),
            'enrollment.schoolClass',
        ];
    }

    /**
     * ONE self-assessment per student and period: the newest template's.
     *
     * A student may have a self-assessment for the period under earlier
     * criteria and another under the criteria the class uses now. The one
     * presented is the active template's (then the newest retired one) — its answers per domain
     * are the only ones read, because two templates are never combined. The
     * older ones travel with it, newest first, as the `earlierRounds` relation,
     * and only `global()` ever looks at them: a new, empty self-assessment must
     * not make the overall judgement the student already gave disappear in
     * silence.
     *
     * @param  Collection<int, SelfAssessment>  $selfAssessments
     * @return Collection<int, SelfAssessment> the current ones, in no particular order
     */
    public function newestPerStudentPeriod(Collection $selfAssessments): Collection
    {
        $current = [];

        // The ACTIVE template's self-assessment is the current one, whatever its
        // id: a backup restored, or cloned into another account, recreates the
        // templates in the order the file lists them, and an id alone could then
        // make the earlier round look like the newest. Among retired templates,
        // the later one is the newer.
        $groups = $selfAssessments
            ->sortBy(fn (SelfAssessment $selfAssessment): array => [
                $selfAssessment->template?->is_active === true ? 1 : 0,
                (int) $selfAssessment->self_assessment_template_id,
                (int) $selfAssessment->id,
            ])
            ->groupBy(fn (SelfAssessment $selfAssessment): string => $selfAssessment->enrollment_id.':'.$selfAssessment->academic_period_id);

        foreach ($groups as $group) {
            $ordered = $group->values();
            $newest = $ordered->pop();

            if ($newest === null) {
                continue;
            }

            $newest->setRelation('earlierRounds', $ordered->reverse()->values());
            $current[] = $newest;
        }

        return new Collection($current);
    }

    /**
     * O juízo global do aluno — a resposta à pergunta com o papel `global`.
     *
     * Se a autoavaliação apresentada não tem resposta global, mas uma anterior
     * do mesmo aluno e período tem, devolve-se essa, marcada `earlier`: uma
     * referência identificada como anterior, na escala em que foi dada.
     *
     * @return array<string, mixed>|null
     */
    public function global(?SelfAssessment $selfAssessment): ?array
    {
        if ($selfAssessment === null) {
            return null;
        }

        $own = $this->ownGlobal($selfAssessment);

        if ($own !== null) {
            return [...$own, 'earlier' => false];
        }

        if (! $selfAssessment->relationLoaded('earlierRounds')) {
            return null;
        }

        foreach ($selfAssessment->getRelation('earlierRounds') as $earlier) {
            $reading = $this->ownGlobal($earlier);

            if ($reading !== null) {
                return [...$reading, 'earlier' => true];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function ownGlobal(SelfAssessment $selfAssessment): ?array
    {
        foreach ($selfAssessment->responses as $response) {
            if ($response->question?->role !== SelfAssessmentQuestionRole::Global) {
                continue;
            }

            return $this->level($response, $selfAssessment);
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

            return $this->level($response, $selfAssessment);
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
     * Uma resposta numérica (escala 0 a 20, percentagem, escala própria) lê-se
     * pelo mesmo caminho, mas SEM `sequence`: um intervalo não tem banda, e os
     * ecrãs que comparam só o fazem quando os dois lados têm `sequence`.
     *
     * @return array<string, mixed>|null
     */
    protected function level(SelfAssessmentResponse $response, SelfAssessment $selfAssessment): ?array
    {
        $level = $response->scaleLevel;

        if ($level !== null) {
            $reading = [
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'display' => (string) $level->code,
                'sequence' => (int) $level->sequence,
                'is_negative' => (bool) $level->is_negative,
                'scale' => $level->scale?->name,
                // Which scale the answer was given on — an answer kept from
                // earlier criteria may be on another scale than the class's
                // decision, and two sequences only compare on the same one.
                'scale_id' => (int) $level->scale_id,
            ];
        } elseif ($response->numeric_value !== null) {
            $reading = [
                ...SelfAssessmentAnswerScale::for($response->question?->scale, 0)->readable((string) $response->numeric_value),
                'scale_id' => $response->question?->scale_id,
            ];
        } else {
            return null;
        }

        return [
            ...$reading,
            // Whether the criteria the student answered with are not the
            // class's today. A change of domains or weights makes a new version
            // as much as a change of scale does, so this covers all of them
            // even when the scale looks the same. Never converted.
            'previous_criteria' => $this->hasPreviousCriteria($selfAssessment),
            'criteria' => $this->criteriaOf($selfAssessment),
        ];
    }

    protected function hasPreviousCriteria(SelfAssessment $selfAssessment): bool
    {
        $template = $selfAssessment->template;

        if ($template === null) {
            return false;
        }

        return $template->assessment_profile_version_id !== $selfAssessment->enrollment?->schoolClass?->assessment_profile_version_id;
    }

    /**
     * «Avaliação final · versão 1 · Percentagem (0 a 100)» — the criteria of the
     * template the self-assessment was answered under; null when it has none.
     */
    protected function criteriaOf(SelfAssessment $selfAssessment): ?string
    {
        $version = $selfAssessment->template?->profileVersion;

        return $version === null ? null : self::criteriaLabel($version);
    }

    public static function criteriaLabel(AssessmentProfileVersion $version): string
    {
        return ($version->profile->name ?? __('Sem perfil'))
            .' · '.__('versão :number', ['number' => $version->version_number])
            .' · '.($version->scale->name ?? __('Sem escala'));
    }

    /**
     * A reading as one line of text for a CSV or a spreadsheet: what a cell
     * shows, plus — when it is not on the class's current criteria — where it
     * came from, so that a «70%» is never read as being on today's scale.
     *
     * @param  array<string, mixed>|null  $reading
     */
    public static function exportText(?array $reading): string
    {
        if ($reading === null) {
            return '';
        }

        $display = (string) ($reading['display'] ?? $reading['code'] ?? '');
        $criteria = (string) ($reading['criteria'] ?? '');
        $previous = ($reading['previous_criteria'] ?? false) === true;

        if (($reading['earlier'] ?? false) === true) {
            return $previous
                ? "anterior: {$display} (critérios anteriores: {$criteria})"
                : "anterior: {$display} (critérios: {$criteria})";
        }

        return $previous ? "{$display} (critérios anteriores: {$criteria})" : $display;
    }
}
