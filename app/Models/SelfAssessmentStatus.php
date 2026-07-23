<?php

namespace App\Models;

enum SelfAssessmentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Reviewed = 'reviewed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Rascunho'),
            self::Submitted => __('Submetida'),
            self::Reviewed => __('Revista'),
        };
    }
}
