<?php

namespace App\Support\Assessment;

/**
 * Whether the self-assessment may be written to at all.
 *
 * ONE QUESTION, ONE PLACE. The recorder, the template provider, the renewal,
 * the restore of a backup and the screens that decide whether to offer a
 * button all ask this, so that a containment switched on for a recovery cannot
 * be honoured by half of them.
 *
 * A RESTORE IS A WRITE TOO. A backup carries self-assessments, templates and
 * answers, and restoring one during a recovery would write exactly what the
 * mode exists to stop — so while it is on, no backup is restored at all
 * (ExecuteDataImport), never half of one.
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

    /**
     * What the person restoring a backup is told. The upload is kept: it can
     * be confirmed once restores are back.
     */
    public static function restoreRefusal(): string
    {
        return __('O restauro de cópias de segurança está temporariamente suspenso, enquanto a autoavaliação está só de leitura. Nada foi importado; a cópia carregada fica guardada e pode ser confirmada mais tarde.');
    }
}
