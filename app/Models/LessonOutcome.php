<?php

namespace App\Models;

/**
 * Como uma ocorrência de aula fechou (0.146.0). NULL em `lessons.outcome`
 * significa «ainda não fechada» — nunca um destes valores por defeito.
 */
enum LessonOutcome: string
{
    case Taught = 'taught';
    case TeacherAbsent = 'teacher_absent';
    case ClassExternalActivity = 'class_external_activity';

    public function label(): string
    {
        return match ($this) {
            self::Taught => __('Lecionada'),
            self::TeacherAbsent => __('Professor ausente'),
            self::ClassExternalActivity => __('Turma em outras atividades letivas'),
        };
    }

    /** Recebe número de lição. */
    public function isNumbered(): bool
    {
        return $this !== self::TeacherAbsent;
    }

    /** Conta como aula lecionada para o serviço docente. */
    public function countsAsTaught(): bool
    {
        return $this !== self::TeacherAbsent;
    }

    /** Conta como desenvolvimento efetivo da disciplina. */
    public function developsSubject(): bool
    {
        return $this === self::Taught;
    }

    /** A assiduidade dos alunos aplica-se. */
    public function takesAttendance(): bool
    {
        return $this === self::Taught;
    }

    /** O planeamento desta ocorrência passa para a seguinte. */
    public function shiftsPlanning(): bool
    {
        return $this !== self::Taught;
    }
}
