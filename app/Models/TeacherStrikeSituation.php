<?php

namespace App\Models;

/**
 * A especificação de uma aula que não se realizou por greve (`outcome_cause =
 * strike`). Só existe com essa causa. São TRÊS opções, a primeira por defeito:
 *
 *  1. «Greve» — registo genérico, sem especificação: a coluna fica a `null`
 *     (o professor não quis especificar). Nunca depende de nenhuma chave.
 *  2. «Greve do professor» (`Adhered`) — o próprio aderiu. Declaração
 *     VOLUNTÁRIA e INDIVIDUAL, reservada a quem a registou, nunca inferida.
 *     É a única opção controlada por `lapis.strike_adhesion_recording`.
 *  3. «Greve — escola encerrada» (`SchoolClosed`) — a aula não se realizou
 *     porque a escola estava encerrada devido à greve. É uma CIRCUNSTÂNCIA DE
 *     FUNCIONAMENTO DA ESCOLA, não uma declaração de ninguém: lê-se como a
 *     causa, por toda a turma, e não depende da chave da adesão.
 *
 * `NotAdhered` e `NotInformed` são valores dos registos anteriores: já não se
 * oferecem, mas lêem-se sempre e nunca se convertem em nenhum dos outros.
 * `SchoolClosed` NÃO é `NotAdhered`: descreve a escola, não o professor.
 */
enum TeacherStrikeSituation: string
{
    case Adhered = 'adhered';
    case NotAdhered = 'not_adhered';
    case NotInformed = 'not_informed';
    case SchoolClosed = 'school_closed';

    public function label(): string
    {
        return match ($this) {
            self::Adhered => __('Greve do professor'),
            self::NotAdhered => __('Não aderi à greve'),
            self::NotInformed => __('Adesão não confirmada'),
            self::SchoolClosed => __('Greve — escola encerrada'),
        };
    }

    /**
     * É uma declaração INDIVIDUAL do professor (reservada a quem a registou)? A escola
     * encerrada não: descreve a escola e lê-se como a causa.
     */
    public function isIndividual(): bool
    {
        return $this !== self::SchoolClosed;
    }

    /**
     * O que se oferece e aceita hoje, pela ordem do ecrã (depois de «Greve», que é `null`).
     * «Greve do professor» depende de validação jurídica (`lapis.strike_adhesion_recording`,
     * desligada por defeito); «Greve — escola encerrada» não. O que já foi guardado lê-se
     * sempre (`cases()`): `not_adhered` e `not_informed` já não se oferecem.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        $options = [];

        if ((bool) config('lapis.strike_adhesion_recording', false)) {
            $options[] = self::Adhered;
        }

        $options[] = self::SchoolClosed;

        return $options;
    }
}
