<?php

namespace App\Models;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';

    public function label(): string
    {
        return match ($this) {
            self::Present => __('Presente'),
            self::Absent => __('Falta'),
        };
    }
}
