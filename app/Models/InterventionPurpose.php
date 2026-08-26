<?php

namespace App\Models;

/**
 * The finalidade of an intervention, in the teacher's own terms: is this
 * chasing a student back up to where the class is, holding what has already
 * been gained, or pushing someone who is already there further (§8 of the
 * Acompanhamento do Aluno brief).
 *
 * NULL IS THE ANSWER FOR EVERY ROW RECORDED BEFORE THIS COLUMN EXISTED, and it
 * stays that way. Nothing here defaults an old intervention into one of the
 * three — "não especificada" is shown, never guessed (§8).
 *
 * MELHORIA IS NOT AN AFTERTHOUGHT. A student with no difficulty at all can
 * still be the subject of a deliberate intervention — enrichment, more
 * complex tasks, a faster pace — and the catalogue names that as plainly as
 * it names recovering lost ground.
 */
enum InterventionPurpose: string
{
    case Recovery = 'recovery';
    case Consolidation = 'consolidation';
    case Improvement = 'improvement';

    public function label(): string
    {
        return match ($this) {
            self::Recovery => __('Recuperação'),
            self::Consolidation => __('Consolidação'),
            self::Improvement => __('Melhoria'),
        };
    }

    /**
     * A short, factual gloss of what each finalidade means — shown once,
     * beside the choice, so picking between them does not require guessing
     * the vocabulary.
     */
    public function description(): string
    {
        return match ($this) {
            self::Recovery => __('Repor uma aprendizagem em falta ou colmatar uma dificuldade identificada.'),
            self::Consolidation => __('Manter e reforçar uma aprendizagem já alcançada.'),
            self::Improvement => __('Elevar um desempenho já positivo — maior complexidade, maior autonomia.'),
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
        ], self::cases());
    }
}
