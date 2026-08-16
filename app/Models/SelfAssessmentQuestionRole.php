<?php

namespace App\Models;

/**
 * What a self-assessment question is FOR, stated structurally.
 *
 * The per-domain questions never need this: a question about Leitura is the one
 * carrying Leitura's `domain_id`, which is identity the model already had. What
 * had no identity at all were the questions belonging to no domain — the overall
 * judgement and the written reflections — because they were all «domain_id null,
 * answer_kind text» and therefore indistinguishable from each other.
 *
 * The alternatives were both unsafe. Reading the wording ties meaning to a
 * sentence somebody will one day rephrase for a younger class or another
 * language. Reading the position ties it to an ordering that changes the moment
 * a question is inserted — silently reassigning what every stored answer meant.
 *
 * NEVER a source of user-facing text. The prompt is authored and editable; this
 * only says which question it is.
 */
enum SelfAssessmentQuestionRole: string
{
    /** «Como avalias globalmente o teu desempenho neste período?» */
    case Global = 'global';

    /** Why the student judged themselves that way. */
    case Rationale = 'rationale';

    /** What they intend to improve. */
    case Improvement = 'improvement';

    /** The activity they most enjoyed. Optional. */
    case Liked = 'liked';

    /** The activity they found hardest. Optional — and asked this way round
     * because «onde senti mais dificuldades» produces something a teacher can
     * act on, where «a que menos gostei» produces a preference. */
    case Struggled = 'struggled';

    public function label(): string
    {
        return match ($this) {
            self::Global => __('Autoavaliação global'),
            self::Rationale => __('Fundamentação'),
            self::Improvement => __('Melhoria'),
            self::Liked => __('Atividade preferida'),
            self::Struggled => __('Maior dificuldade'),
        };
    }

    /**
     * The one role that carries a scale answer. The rest are written.
     */
    public function answerKind(): string
    {
        return $this === self::Global ? 'scale' : 'text';
    }

    /**
     * Whether a template may leave this question out. The overall judgement and
     * the two reflections are the point of the exercise; the two about
     * activities are welcome and never required.
     */
    public function isOptional(): bool
    {
        return in_array($this, [self::Liked, self::Struggled], true);
    }

    /**
     * A role always belongs to the whole period, never to one domain — the
     * per-domain questions are identified by their domain and carry no role.
     */
    public function appliesToWholePeriod(): bool
    {
        return true;
    }

    /**
     * The three blocks the student's form is organised into.
     */
    public function block(): string
    {
        return match ($this) {
            self::Global => 'performance',
            self::Rationale, self::Improvement => 'reflection',
            self::Liked, self::Struggled => 'work',
        };
    }

    /**
     * Where the question sits inside its block.
     *
     * «O meu desempenho» opens with the per-domain questions, which carry no
     * role and therefore sort before every answer here — the overall judgement
     * closes the block, after the parts it is a judgement about.
     */
    public function positionInBlock(): int
    {
        return match ($this) {
            self::Global, self::Rationale, self::Liked => 1,
            self::Improvement, self::Struggled => 2,
        };
    }

    /**
     * @return list<self>
     */
    public static function required(): array
    {
        return array_values(array_filter(self::cases(), fn (self $role): bool => ! $role->isOptional()));
    }
}
