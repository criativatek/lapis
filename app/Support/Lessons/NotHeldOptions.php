<?php

namespace App\Support\Lessons;

use App\Models\LessonNotHeldCause;
use App\Models\TeacherStrikeSituation;

/**
 * O que o diálogo «O que aconteceu nesta aula?» oferece para «Aula não
 * realizada» — UMA FONTE, para a página da aula e para a semana.
 *
 * `null` com `lapis.lesson_not_held_enabled` desligada: a opção nem existe no
 * ecrã (e o servidor recusa o pedido na mesma). As especificações perante a
 * greve seguem `TeacherStrikeSituation::available()`: «Aderi» só com a chave
 * `lapis.strike_adhesion_recording`.
 */
final class NotHeldOptions
{
    /**
     * @return array{causes: list<array{value: string, label: string}>, specifications: list<array{value: string, label: string}>}|null
     */
    public static function forPage(): ?array
    {
        if (! config('lapis.lesson_not_held_enabled', false)) {
            return null;
        }

        return [
            'causes' => array_map(
                fn (LessonNotHeldCause $cause): array => ['value' => $cause->value, 'label' => $cause->label()],
                LessonNotHeldCause::cases(),
            ),
            'specifications' => array_map(
                fn (TeacherStrikeSituation $situation): array => ['value' => $situation->value, 'label' => $situation->label()],
                TeacherStrikeSituation::available(),
            ),
        ];
    }
}
