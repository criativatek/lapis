<?php

namespace App\Models;

/**
 * O estado de uma janela em `subject_participations`.
 *
 * UM SÓ CASO, DE PROPÓSITO. Não existe `attending`: a ausência de linha já
 * significa isso (ver o docblock da migração), e um caso que nunca é escrito
 * seria vocabulário morto. O único facto que esta tabela regista é o
 * contrário do normal — que o aluno, durante um período, não frequenta a
 * disciplina.
 */
enum SubjectParticipationState: string
{
    case NotAttending = 'not_attending';

    public function label(): string
    {
        return match ($this) {
            self::NotAttending => __('Não frequenta esta disciplina'),
        };
    }
}
