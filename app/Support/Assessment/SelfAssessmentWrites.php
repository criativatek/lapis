<?php

namespace App\Support\Assessment;

/**
 * Whether the self-assessment may be written to at all.
 *
 * ONE QUESTION, ONE PLACE. The recorder, the template provider, the renewal and
 * the screens that decide whether to offer a button all ask this, so that a
 * containment switched on for a recovery cannot be honoured by half of them.
 */
final class SelfAssessmentWrites
{
    public static function readOnly(): bool
    {
        return (bool) config('lapis.self_assessment.read_only', false);
    }

    /**
     * What is said, to the teacher and to the student alike, when a write is
     * refused.
     */
    public static function refusal(): string
    {
        return __('A autoavaliação está temporariamente só de leitura. As respostas guardadas continuam visíveis, mas por agora não é possível guardar alterações.');
    }
}
