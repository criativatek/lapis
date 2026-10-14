<?php

namespace App\Services\Assessment;

use App\Models\Domain;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentTemplate;
use App\Services\Audit\AuditLog;
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
    public function __construct(protected AuditLog $audit) {}

    /**
     * The template the form is built from: derived on first use, and derived
     * again when the class has moved to another profile version while nobody
     * had saved a self-assessment against the old one.
     */
    public function forClass(SchoolClass $class): SelfAssessmentTemplate
    {
        $existing = $this->activeTemplateOf($class);

        // The common case reads and goes: the template already follows the class.
        if ($existing !== null && ! $this->isLeftBehind($existing, $class)) {
            return $this->completeTemplateThatPredatesRoles($existing, $class);
        }

        // Deriving, and retiring, one request at a time per class — under the
        // lock MigrateClassProfile also takes. A whole class opening projected
        // links at once must not derive one template each, and a save (which
        // takes the same lock) must not land on a template between the check
        // that it is unanswered and its retiring.
        return DB::transaction(function () use ($class): SelfAssessmentTemplate {
            $locked = $this->lockClass($class);
            $existing = $this->activeTemplateOf($locked);

            if ($existing !== null && ! $this->isLeftBehind($existing, $locked)) {
                return $this->completeTemplateThatPredatesRoles($existing, $locked);
            }

            if ($existing !== null) {
                $this->retire($existing, $locked);
            }

            return $this->derive($locked);
        });
    }

    /**
     * The template a submitted form may be saved against: the active one, as
     * long as it still follows the class or has already been answered. Null
     * when there is none, or when the next visit would replace it — the form
     * that was submitted then asked questions that are no longer the class's.
     *
     * Never derives nor retires anything: a save records what was asked, it
     * does not change what is asked. Call it inside a transaction that holds
     * lockClass(), so the answer cannot change before the write.
     */
    public function answerableFor(SchoolClass $class): ?SelfAssessmentTemplate
    {
        $template = $this->activeTemplateOf($class);

        return $template === null || $this->isLeftBehind($template, $class) ? null : $template;
    }

    /**
     * The class row, locked, read again — its profile version as it is now.
     */
    public function lockClass(SchoolClass $class): SchoolClass
    {
        return SchoolClass::query()->whereKey($class->getKey())->lockForUpdate()->firstOrFail();
    }

    protected function activeTemplateOf(SchoolClass $class): ?SelfAssessmentTemplate
    {
        return SelfAssessmentTemplate::query()
            ->where('class_id', $class->id)
            ->where('is_active', true)
            ->with('questions')
            ->first();
    }

    /**
     * A template is derived from the profile version the class had when it was
     * first opened — its domains and its scale. Activating a newer version
     * never moves a class (§10.2), but migrating the class does, and a template
     * left on the old version keeps asking on the old scale: a class moved
     * from a percentage to 1–5 would go on offering a percentage's levels,
     * which is to say none.
     *
     * So a template against which no self-assessment was ever saved follows the
     * class. One that has any — even a saved one with every question left
     * blank — stays exactly where it is: what a student already said was said
     * to those questions on that scale, and a second template for the same
     * period would leave two self-assessments for one student, of which every
     * screen reads one.
     */
    protected function isLeftBehind(SelfAssessmentTemplate $template, SchoolClass $class): bool
    {
        return $template->assessment_profile_version_id !== $class->assessment_profile_version_id
            && ! SelfAssessment::query()->where('self_assessment_template_id', $template->id)->exists();
    }

    /**
     * Retired, never deleted: the row and its questions stay as they were, and
     * the change is on the record — it happens on a visit, possibly the
     * student's, and nobody would otherwise know the questions had changed.
     */
    protected function retire(SelfAssessmentTemplate $template, SchoolClass $class): void
    {
        $template->forceFill(['is_active' => false])->save();

        $this->audit->record(
            'self_assessment.template_retired',
            $template,
            null,
            "Perguntas da autoavaliação de {$class->label} atualizadas para a versão de perfil da turma.",
            [
                'class_id' => $class->id,
                'from_profile_version_id' => $template->assessment_profile_version_id,
                'to_profile_version_id' => $class->assessment_profile_version_id,
            ],
        );
    }

    protected function derive(SchoolClass $class): SelfAssessmentTemplate
    {
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
