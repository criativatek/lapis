<?php

namespace App\Models;

enum LessonStatus: string
{
    case Preparation = 'preparation';
    case Prepared = 'prepared';
    case Taught = 'taught';

    public function label(): string
    {
        return match ($this) {
            self::Preparation => __('Por preparar'),
            self::Prepared => __('Preparada'),
            self::Taught => __('Lecionada'),
        };
    }
}
