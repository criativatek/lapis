<?php

namespace App\Models;

/**
 * Who filled the self-assessment (§15): the student, or the teacher in an
 * interview — the spec allows both.
 */
enum SelfAssessmentFilledBy: string
{
    case Student = 'student';
    case TeacherInterview = 'teacher_interview';

    public function label(): string
    {
        return match ($this) {
            self::Student => __('Aluno'),
            self::TeacherInterview => __('Entrevista com o professor'),
        };
    }
}
