<?php

namespace App\Models;

/**
 * A que universo de alunos uma leitura da turma se refere.
 *
 * `ClassCohort` resolve os dois com a mesma matrícula (§ver docblock de
 * ClassCohort): a avaliação propriamente dita quer só quem frequenta a
 * disciplina (`AttendingOnly`); uma listagem, um roteiro de importação ou um
 * export de dados quer toda a turma (`AllClassStudents`), porque omitir um
 * aluno seria dizer algo falso sobre ele.
 */
enum CohortUniverse: string
{
    case AttendingOnly = 'attending_only';
    case AllClassStudents = 'all_class_students';

    public function label(): string
    {
        return match ($this) {
            self::AttendingOnly => __('Apenas alunos que frequentam a disciplina'),
            self::AllClassStudents => __('Todos os alunos da turma'),
        };
    }
}
