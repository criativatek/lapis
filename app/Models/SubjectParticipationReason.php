<?php

namespace App\Models;

/**
 * Porque razão um aluno não frequenta esta disciplina.
 *
 * `AlternativeSubject` cobre o caso mais comum — PLNM em vez de Português,
 * ou qualquer outro percurso curricular que uma escola preveja — sem que o
 * domínio conheça o nome de um único deles: o que a escola realmente
 * frequenta em substituição vai em `subject_participations.reason_detail`,
 * texto livre. `Other` é a válvula de escape para o que não é uma disciplina
 * alternativa nenhuma.
 */
enum SubjectParticipationReason: string
{
    case AlternativeSubject = 'alternative_subject';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AlternativeSubject => __('Frequenta disciplina alternativa'),
            self::Other => __('Outro motivo'),
        };
    }
}
