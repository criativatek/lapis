<?php

namespace App\Models;

enum AcademicYearStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Rascunho'),
            self::Active => __('Ativo'),
            self::Closed => __('Encerrado'),
            self::Archived => __('Arquivado'),
        };
    }

    /**
     * A closed or archived year is read-only for the teacher: its periods and
     * results are frozen. Editing structure is only allowed while draft or active.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::Active => true,
            self::Closed, self::Archived => false,
        };
    }
}
