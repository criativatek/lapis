<?php

namespace App\Support\Lessons;

/**
 * Contenção da numeração das lições (0.169.0): com
 * `LAPIS_LESSON_NUMBERING_FROZEN=true` NENHUM número de lição que já exista
 * muda, por nenhum caminho.
 *
 * Existe para a recuperação (docs/lesson-numbering-recovery.md): se há números
 * indicados pelo professor e é preciso corrigir para a frente, o pior que o
 * sistema pode fazer entretanto é reescrevê-los. Ligada:
 *  - o professor não pode indicar nem repor um número (continua a poder guardar
 *    o sumário);
 *  - `LessonNumbering` só escreve aulas cujo número gravado é NULL — as aulas
 *    novas recebem a sugestão, as que já têm número ficam como estão;
 *  - a passagem do número numa ausência do professor não acontece (a aula
 *    ausente continua a ficar sem número, como sempre);
 *  - restauros continuam permitidos: copiam tal como está, não derivam.
 *
 * UMA PERGUNTA, UM SÍTIO, como `SelfAssessmentWrites`.
 */
final class LessonNumberingFreeze
{
    public static function frozen(): bool
    {
        return (bool) config('lapis.lesson_numbering_frozen', false);
    }

    /**
     * O que se diz ao professor quando uma alteração do número é recusada, e
     * debaixo do campo só de leitura.
     */
    public static function refusal(): string
    {
        return __('A numeração das lições está temporariamente bloqueada. Pode guardar o sumário sem alterar o número.');
    }
}
