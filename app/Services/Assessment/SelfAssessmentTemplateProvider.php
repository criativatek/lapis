<?php

namespace App\Services\Assessment;

use App\Models\Domain;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Provides the self-assessment template for a class (§15). It is derived from the
 * class's own configuration — one scale question per domain of the profile
 * version, on that version's scale — so each answer is comparable, on read, with
 * that domain's calculated result. Nothing here is fixed: the domains, their
 * number and the scale all come from the class.
 *
 * A manual template builder can replace this later without touching the fill flow.
 */
class SelfAssessmentTemplateProvider
{
    public function forClass(SchoolClass $class): SelfAssessmentTemplate
    {
        $existing = SelfAssessmentTemplate::query()
            ->where('class_id', $class->id)
            ->where('is_active', true)
            ->with('questions')
            ->first();

        if ($existing !== null) {
            return $this->completeTemplateThatPredatesRoles($existing, $class);
        }

        return DB::transaction(function () use ($class): SelfAssessmentTemplate {
            $template = SelfAssessmentTemplate::create([
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'class_id' => $class->id,
                'name' => "Autoavaliação — {$class->subject->name}",
                'is_active' => true,
            ]);

            $scale = $this->scaleFor($class);

            $this->addDomainQuestions($template, $class, $scale);
            $this->addRoleQuestions($template, $scale);

            return $template->load('questions');
        });
    }

    /**
     * A template authored before roles existed carries only its per-domain
     * questions, and would otherwise stay without an overall judgement or a
     * reflection for as long as the class exists.
     *
     * Completing it is ADDITIVE: the missing questions are created, with new
     * ids of their own, and no existing question is given a role. Reading a
     * role into a question already answered would be deciding today what those
     * answers meant — which is the one thing the role column exists to avoid.
     * Every answer already stored keeps pointing at the same question it did.
     *
     * Done once. A template that carries any role at all is left alone.
     */
    protected function completeTemplateThatPredatesRoles(SelfAssessmentTemplate $template, SchoolClass $class): SelfAssessmentTemplate
    {
        if ($template->questions->whereNotNull('role')->isNotEmpty()) {
            return $template;
        }

        DB::transaction(function () use ($template, $class): void {
            $this->addRoleQuestions($template, $this->scaleFor($class));
        });

        return $template->load('questions');
    }

    /**
     * BLOCO A — o meu desempenho: um por domínio real do perfil, identificado
     * pelo domínio e nunca por um papel.
     */
    protected function addDomainQuestions(SelfAssessmentTemplate $template, SchoolClass $class, ?Scale $scale): void
    {
        $version = $class->profileVersion;

        if ($version === null || $scale === null) {
            return;
        }

        $domainIds = $version->domains()->orderBy('id')->pluck('domain_id');
        $domains = Domain::whereIn('id', $domainIds)->orderBy('name')->get();

        $sequence = (int) $template->questions()->max('sequence');

        foreach ($domains as $domain) {
            $template->questions()->create([
                'domain_id' => $domain->id,
                // First person throughout: it is the student's own reading of
                // the period, and it should read like one.
                'prompt' => "Como avalio o meu desempenho em {$domain->name}?",
                'answer_kind' => 'scale',
                'scale_id' => $scale->id,
                'sequence' => ++$sequence,
            ]);
        }
    }

    /**
     * The questions that belong to the period rather than to a domain: the
     * student's own overall judgement, then the two reflections, then the two
     * about the work itself.
     */
    protected function addRoleQuestions(SelfAssessmentTemplate $template, ?Scale $scale): void
    {
        $sequence = (int) $template->questions()->max('sequence');

        // …a global, que é uma RESPOSTA do aluno e nunca a média das anteriores.
        // Identificada pelo papel, na escala do contexto. Sem escala não há
        // nível para propor, e a pergunta não é feita.
        if ($scale !== null) {
            $template->questions()->create([
                'role' => SelfAssessmentQuestionRole::Global,
                'prompt' => $this->globalPrompt($scale),
                'answer_kind' => SelfAssessmentQuestionRole::Global->answerKind(),
                'scale_id' => $scale->id,
                'sequence' => ++$sequence,
            ]);
        }

        // BLOCOS B e C — a reflexão, e o trabalho realizado. Escritas, fora de
        // qualquer cálculo, e criadas mesmo quando o perfil não tem escala:
        // pensar sobre o período não depende de haver domínios.
        foreach ($this->writtenPrompts($scale) as [$role, $prompt]) {
            $template->questions()->create([
                'role' => $role,
                'prompt' => $prompt,
                'answer_kind' => $role->answerKind(),
                'sequence' => ++$sequence,
            ]);
        }
    }

    /**
     * What the student is asked to propose depends on the scale the class is
     * assessed on: a cycle graded in levels asks for a LEVEL, a numeric one for
     * a CLASSIFICAÇÃO.
     *
     * Authored once, into the template, and from then on the stored text is the
     * one that shows — a teacher who rewrites it is not overruled by anything
     * here, and the role never supplies a word of it.
     */
    protected function globalPrompt(Scale $scale): string
    {
        return $scale->kind === 'level'
            ? 'Nível que proponho para a minha avaliação neste período'
            : 'Classificação que proponho para a minha avaliação neste período';
    }

    /**
     * @return list<array{SelfAssessmentQuestionRole, string}>
     */
    protected function writtenPrompts(?Scale $scale): array
    {
        $rationale = match (true) {
            $scale === null => 'Porque me avalio assim?',
            $scale->kind === 'level' => 'Porque proponho este nível?',
            default => 'Porque proponho esta classificação?',
        };

        return [
            [SelfAssessmentQuestionRole::Rationale, $rationale],
            [SelfAssessmentQuestionRole::Improvement, 'O que preciso de melhorar no próximo período?'],
            [SelfAssessmentQuestionRole::Liked, 'Atividade de que mais gostei'],
            [SelfAssessmentQuestionRole::Struggled, 'Atividade em que senti mais dificuldades'],
        ];
    }

    /**
     * The scale the class is actually assessed on — the profile version's own,
     * never a scale looked up by name. A secondary class graded 0–20 asks its
     * self-assessment in 0–20.
     */
    protected function scaleFor(SchoolClass $class): ?Scale
    {
        return $class->profileVersion?->scale;
    }
}
