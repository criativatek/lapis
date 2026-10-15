<?php

namespace App\Services\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentTemplate;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\ImprovementPrompt;
use App\Support\Assessment\SelfAssessmentRenewalException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

            return $this->derive($locked, $existing);
        });
    }

    /**
     * A class has just been moved to another profile version
     * (MigrateClassProfile, inside its transaction, under its lock). A template
     * with no self-assessment saved against it follows the class RIGHT AWAY
     * rather than on the next visit: the migration is the moment the change is
     * decided, and the audit trail should say who decided it — not whichever
     * student happens to open a link first.
     *
     * One with self-assessments stays where it is, exactly as isLeftBehind()
     * has always said. With no template at all there is nothing to follow: the
     * first visit derives one from the class's version.
     */
    public function followMigratedClass(SchoolClass $lockedClass, User $actor, ?User $onBehalfOf = null): void
    {
        $existing = $this->activeTemplateOf($lockedClass);

        if ($existing === null || ! $this->isLeftBehind($existing, $lockedClass)) {
            return;
        }

        $this->retire($existing, $lockedClass, $actor, $onBehalfOf === null ? [] : [
            // Acesso técnico (ADR-0014): o ator é o administrador e o evento diz
            // em nome de quem.
            'acting_as_user_id' => $onBehalfOf->id,
            'technical_access' => true,
        ]);

        $this->derive($lockedClass, $existing);
    }

    /**
     * Start a new self-assessment with the class's CURRENT criteria, when the
     * one in use stayed behind because it already holds answers.
     *
     * Nothing is converted and nothing is deleted: the template is retired, its
     * questions, self-assessments and responses stay as they were, and the new
     * template is derived from the class's version. A student then has one
     * self-assessment per template for the same period, every reading takes
     * the newer one (SelfAssessmentReading), and the older one stays on the
     * record with the criteria it was filled in with.
     */
    public function renew(SchoolClass $class, User $actor): SelfAssessmentTemplate
    {
        return DB::transaction(function () use ($class, $actor): SelfAssessmentTemplate {
            $locked = $this->lockClass($class);

            if ($locked->assessment_profile_version_id === null) {
                throw SelfAssessmentRenewalException::withoutProfile();
            }

            $existing = $this->activeTemplateOf($locked);

            if ($existing === null) {
                throw SelfAssessmentRenewalException::nothingToRenew();
            }

            if ($existing->assessment_profile_version_id === $locked->assessment_profile_version_id) {
                throw SelfAssessmentRenewalException::alreadyCurrent();
            }

            $saved = SelfAssessment::query()->where('self_assessment_template_id', $existing->id)->count();

            if ($saved === 0) {
                throw SelfAssessmentRenewalException::nothingToRenew();
            }

            $this->retire($existing, $locked, $actor, ['reason' => 'renewed', 'saved_count' => $saved]);

            $template = $this->derive($locked, $existing);
            $versionNumber = $locked->profileVersion?->version_number;

            $this->audit->record(
                'self_assessment.renewed',
                $template,
                $actor,
                "Nova autoavaliação de {$locked->label} com os critérios atuais da turma (versão {$versionNumber}). ".($saved === 1
                    ? 'A autoavaliação anterior fica guardada com os critérios em que foi preenchida.'
                    : "As {$saved} autoavaliações anteriores ficam guardadas com os critérios em que foram preenchidas."),
                [
                    'class_id' => $locked->id,
                    'previous_template_id' => $existing->id,
                    'from_profile_version_id' => $existing->assessment_profile_version_id,
                    'to_profile_version_id' => $locked->assessment_profile_version_id,
                    'saved_count' => $saved,
                ],
            );

            return $template;
        });
    }

    /**
     * Rewrite the wording of the reflection questions of the active template.
     *
     * ONLY THE WRITTEN QUESTIONS. The domains, their weights and the scale are
     * the class's criteria and are not edited here; neither is the overall
     * judgement, whose wording is bound to the scale. A reflection that already
     * has an answer keeps its wording, so that what students answered still
     * means what it meant when they answered.
     *
     * @param  array<int|string, mixed>  $prompts  question id => new wording
     */
    public function rewordReflections(SchoolClass $class, array $prompts, User $actor): void
    {
        DB::transaction(function () use ($class, $prompts, $actor): void {
            $locked = $this->lockClass($class);
            $template = $this->activeTemplateOf($locked);

            // A page opened before the template was replaced (a new
            // self-assessment, or the class's criteria followed) names questions
            // that are no longer the class's: said as what it is, never as a
            // refusal to edit a criterion nobody tried to edit.
            $stale = __('As perguntas desta autoavaliação mudaram depois de esta página ter sido aberta. Nada foi guardado: a página foi atualizada — volte a escrever e guarde.');

            if ($template === null) {
                throw ValidationException::withMessages(['prompts' => $stale]);
            }

            /** @var Collection<int, SelfAssessmentQuestion> $questions */
            $questions = $template->questions->keyBy('id');
            $errors = [];
            $wanted = [];

            foreach ($prompts as $questionId => $prompt) {
                $question = $questions->get((int) $questionId);

                if ($question === null) {
                    throw ValidationException::withMessages(['prompts' => $stale]);
                }

                if ($question->role === null || $question->answer_kind !== 'text') {
                    $errors['prompts'] = __('Só as perguntas de reflexão podem ser personalizadas. Os domínios, as ponderações e a escala vêm dos critérios da turma.');

                    continue;
                }

                $text = is_string($prompt) ? trim($prompt) : '';

                if (mb_strlen($text) < 3 || mb_strlen($text) > 500) {
                    $errors["prompts.{$question->id}"] = __('A pergunta deve ter entre 3 e 500 caracteres.');

                    continue;
                }

                $wanted[(int) $question->id] = $text;
            }

            $answered = $wanted === [] ? [] : SelfAssessmentResponse::query()
                ->whereIn('self_assessment_question_id', array_keys($wanted))
                ->whereNotNull('text_value')
                ->distinct()
                ->pluck('self_assessment_question_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $changes = [];

            foreach ($wanted as $questionId => $text) {
                $question = $questions->get($questionId);

                if ($question === null || $text === (string) $question->prompt) {
                    continue;
                }

                if (in_array($questionId, $answered, true)) {
                    $errors["prompts.{$questionId}"] = __('Esta pergunta já tem respostas de alunos. A redação mantém-se, para não mudar o sentido do que já foi respondido.');

                    continue;
                }

                $changes[] = ['question' => $question, 'to' => $text];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            if ($changes === []) {
                return;
            }

            $recorded = [];

            foreach ($changes as $change) {
                $question = $change['question'];

                $recorded[] = [
                    'question_id' => $question->id,
                    'role' => $question->role?->value,
                    'from' => (string) $question->prompt,
                    'to' => $change['to'],
                ];

                $question->forceFill(['prompt' => $change['to']])->save();
            }

            $this->audit->record(
                'self_assessment.questions_reworded',
                $template,
                $actor,
                "Perguntas de reflexão da autoavaliação de {$locked->label} personalizadas.",
                ['class_id' => $locked->id, 'changes' => $recorded],
            );
        });
    }

    /**
     * What changing the class to another profile version would do to its
     * self-assessment — read-only, for the preview a teacher confirms.
     *
     * @return array{state: string, saved_count: int, student_count: int, periods: list<string>}
     */
    public function impactOf(SchoolClass $class, AssessmentProfileVersion $to): array
    {
        $template = $this->activeTemplateOf($class);

        if ($template === null) {
            return ['state' => 'not_started', 'saved_count' => 0, 'student_count' => 0, 'periods' => []];
        }

        $saved = SelfAssessment::query()->where('self_assessment_template_id', $template->id);
        $savedCount = (clone $saved)->count();

        $periods = [];

        $labels = AcademicPeriod::query()
            ->whereIn('id', (clone $saved)->select('academic_period_id'))
            ->orderBy('sequence')
            ->pluck('label');

        foreach ($labels as $label) {
            $periods[] = (string) $label;
        }

        return [
            'state' => match (true) {
                $template->assessment_profile_version_id === $to->id => 'unchanged',
                $savedCount === 0 => 'follows',
                default => 'kept',
            },
            'saved_count' => $savedCount,
            'student_count' => (clone $saved)->distinct()->count('enrollment_id'),
            'periods' => $periods,
        ];
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

    /**
     * The template in use, with its questions — also for the screens that only
     * read, which must not derive anything.
     */
    public function activeTemplateOf(SchoolClass $class): ?SelfAssessmentTemplate
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
     *
     * @param  array<string, mixed>  $extra  properties added to the event
     */
    protected function retire(SelfAssessmentTemplate $template, SchoolClass $class, ?User $causer = null, array $extra = []): void
    {
        $template->forceFill(['is_active' => false])->save();

        $this->audit->record(
            'self_assessment.template_retired',
            $template,
            $causer,
            "Perguntas da autoavaliação de {$class->label} atualizadas para a versão de perfil da turma.",
            [
                'class_id' => $class->id,
                'from_profile_version_id' => $template->assessment_profile_version_id,
                'to_profile_version_id' => $class->assessment_profile_version_id,
                ...$extra,
            ],
        );
    }

    /**
     * @param  SelfAssessmentTemplate|null  $previous  the template this one replaces: the reflection questions a teacher worded keep their wording
     */
    protected function derive(SchoolClass $class, ?SelfAssessmentTemplate $previous = null): SelfAssessmentTemplate
    {
        $template = SelfAssessmentTemplate::create([
            'assessment_profile_version_id' => $class->assessment_profile_version_id,
            'class_id' => $class->id,
            'name' => "Autoavaliação — {$class->subject->name}",
            'is_active' => true,
        ]);

        $scale = $this->scaleFor($class);

        $this->addDomainQuestions($template, $class, $scale);
        $this->addRoleQuestions($template, $scale, $previous);

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

        // The template's OWN scale — the one its domain questions were asked
        // on — and never the class's. A template that answers kept on an
        // earlier version would otherwise gain an overall judgement on the
        // class's new scale: one form asking on two scales.
        $template->loadMissing('profileVersion.scale');
        $scale = $template->profileVersion?->scale;

        DB::transaction(function () use ($template, $scale): void {
            $this->addRoleQuestions($template, $scale);
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
    protected function addRoleQuestions(SelfAssessmentTemplate $template, ?Scale $scale, ?SelfAssessmentTemplate $previous = null): void
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
                'prompt' => $this->wordedByTeacher($previous, $role) ?? $prompt,
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
     * The wording a teacher gave a reflection question of the template being
     * replaced, or null when it is one the app wrote itself — in which case
     * the new template gets the app's wording for ITS scale, since «Porque
     * proponho este nível?» does not follow a class moved to a 0–20.
     *
     * Compared whole against every sentence the app authors for the role, for
     * any scale; a sentence outside that set is the teacher's own.
     */
    protected function wordedByTeacher(?SelfAssessmentTemplate $previous, SelfAssessmentQuestionRole $role): ?string
    {
        if ($previous === null) {
            return null;
        }

        /** @var SelfAssessmentQuestion|null $question */
        $question = $previous->questions->firstWhere('role', $role);

        if ($question === null) {
            return null;
        }

        $stored = (string) $question->prompt;

        return $this->isGeneratedPrompt($role, $stored) ? null : $stored;
    }

    protected function isGeneratedPrompt(SelfAssessmentQuestionRole $role, string $prompt): bool
    {
        if ($role === SelfAssessmentQuestionRole::Improvement && ImprovementPrompt::isAuthored($prompt)) {
            return true;
        }

        foreach ([null, 'level', 'interval'] as $kind) {
            foreach ($this->writtenPromptsFor($kind) as [$generatedRole, $generated]) {
                if ($generatedRole === $role && $generated === $prompt) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{SelfAssessmentQuestionRole, string}>
     */
    protected function writtenPrompts(?Scale $scale): array
    {
        return $this->writtenPromptsFor($scale === null ? null : ($scale->kind === 'level' ? 'level' : 'interval'));
    }

    /**
     * @param  'level'|'interval'|null  $kind  null when the class has no scale
     * @return list<array{SelfAssessmentQuestionRole, string}>
     */
    protected function writtenPromptsFor(?string $kind): array
    {
        $rationale = match ($kind) {
            null => 'Porque me avalio assim?',
            'level' => 'Porque proponho este nível?',
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
