<?php

namespace App\Actions\Timetable;

/**
 * O que o professor pede ao guardar o registo de uma sessão.
 *
 *  - `save`     guarda o texto e mantém o estado (uma realizada continua realizada);
 *  - `done`     guarda o texto e marca como realizada;
 *  - `not_held` marca como não realizada e PRESERVA o texto;
 *  - `reopen`   volta a «por realizar» e preserva o texto.
 */
enum ActivitySessionAction: string
{
    case Save = 'save';
    case Done = 'done';
    case NotHeld = 'not_held';
    case Reopen = 'reopen';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $action): string => $action->value, self::cases());
    }
}
