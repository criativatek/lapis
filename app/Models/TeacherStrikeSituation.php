<?php

namespace App\Models;

/**
 * A situação INDIVIDUAL do professor perante uma greve, quando a aula não se
 * realizou por greve (`outcome_cause = strike`). Só existe com essa causa e só
 * o próprio a declara (`outcome_recorded_by`); nunca se infere nem se converte
 * a partir de registos anteriores.
 *
 *  - NotAdhered: não aderiu, mas a escola não funcionou (cenário B);
 *  - NotInformed: a escola não funcionou e a adesão não está confirmada
 *    (cenário C) — o valor por omissão.
 *
 * A adesão do próprio é o cenário A e regista-se como `teacher_absent`, não
 * aqui.
 */
enum TeacherStrikeSituation: string
{
    case NotAdhered = 'not_adhered';
    case NotInformed = 'not_informed';

    public function label(): string
    {
        return match ($this) {
            self::NotAdhered => __('Não aderi'),
            self::NotInformed => __('Adesão não confirmada'),
        };
    }
}
