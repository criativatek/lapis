<?php

namespace App\Support\Lessons;

use RuntimeException;

/**
 * The compatibility gate ApplyLessonSequence checks before touching any
 * Lesson row — always a readable message for the teacher, never a database
 * error or a 500.
 */
class LessonSequenceApplicationException extends RuntimeException
{
    public static function organizationMismatch(): self
    {
        return new self(__('A turma selecionada não pertence à mesma organização da sequência.'));
    }

    public static function subjectMismatch(): self
    {
        return new self(__('A turma selecionada não é da mesma disciplina desta sequência.'));
    }

    public static function gradeLevelMismatch(): self
    {
        return new self(__('A turma selecionada não é do ano de escolaridade desta sequência.'));
    }
}
