<?php

namespace App\Models;

/**
 * O motivo de uma ausência do professor — SÓ CATEGORIA, nunca texto livre.
 * Um motivo escrito à mão sobre a vida de uma pessoa é um dado que a
 * aplicação não precisa de guardar.
 */
enum TeacherAbsenceReason: string
{
    case Training = 'training';
    case OfficialDuty = 'official_duty';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Training => __('Formação'),
            self::OfficialDuty => __('Serviço oficial'),
            self::Other => __('Outro'),
        };
    }
}
