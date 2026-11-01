<?php

namespace App\Models;

/**
 * A situação INDIVIDUAL do professor perante uma greve, quando a aula não se
 * realizou por greve (`outcome_cause = strike`). Só existe com essa causa e só
 * o próprio a declara (`outcome_recorded_by`); nunca se infere nem se converte
 * a partir de registos anteriores.
 *
 * É uma ESPECIFICAÇÃO FACULTATIVA: registar só «Greve» é o normal e deixa a
 * coluna a `null` (o professor não quis especificar). Opcionalmente:
 *
 *  - Adhered: aderiu à greve (só com `lapis.strike_adhesion_recording`);
 *  - NotAdhered: não aderiu, mas a escola não funcionou;
 *  - NotInformed: a situação é desconhecida — valor dos registos anteriores
 *    à 'especificação facultativa'; já não se oferece nem é o defeito. É
 *    diferente de `null` (não especificou) e lê-se sempre.
 */
enum TeacherStrikeSituation: string
{
    case Adhered = 'adhered';
    case NotAdhered = 'not_adhered';
    case NotInformed = 'not_informed';

    public function label(): string
    {
        return match ($this) {
            self::Adhered => __('Aderi à greve'),
            self::NotAdhered => __('Não aderi à greve'),
            self::NotInformed => __('Adesão não confirmada'),
        };
    }

    /**
     * O que se oferece e aceita hoje. «Aderi» depende de validação jurídica
     * (`lapis.strike_adhesion_recording`, desligada por defeito); o que já foi
     * guardado lê-se sempre (`cases()`). `not_informed` é só dos registos
     * anteriores: já não se oferece nem é o valor por omissão.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        $adhesion = (bool) config('lapis.strike_adhesion_recording', false);

        return array_values(array_filter(
            self::cases(),
            fn (self $situation): bool => $situation !== self::NotInformed && ($adhesion || $situation !== self::Adhered),
        ));
    }
}
