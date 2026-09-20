<?php

namespace App\Services\Characterisation\Import;

use App\Support\Characterisation\CharacterisationSection;

/**
 * What a column of the imported table is for.
 *
 * A column whose header nobody recognises is Unknown, not "probably
 * characterisation". Guessing would quietly pour a column of who-knows-what
 * into a child's record, which is the failure this whole feature is shaped to
 * avoid — so Unknown columns reach the preview switched off, named, and waiting
 * for a person to say what they are.
 */
enum ColumnRole: string
{
    case StudentName = 'student_name';
    case SchoolNumber = 'school_number';
    case ClassNumber = 'class_number';
    case Measures = 'measures';
    case Resources = 'resources';
    case Characterisation = 'characterisation';
    case Strengths = 'strengths';
    case Interests = 'interests';
    case Needs = 'needs';
    case Barriers = 'barriers';
    case Participation = 'participation';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::StudentName => __('Nome do aluno'),
            self::SchoolNumber => __('N.º de processo'),
            self::ClassNumber => __('N.º'),
            self::Measures => __('Medidas'),
            self::Resources => __('Apoios e recursos'),
            self::Characterisation => __('Caracterização'),
            self::Strengths => __('Potencialidades'),
            self::Interests => __('Interesses'),
            self::Needs => __('Necessidades'),
            self::Barriers => __('Barreiras'),
            self::Participation => __('Participação'),
            self::Unknown => __('Por classificar'),
        };
    }

    /**
     * Which characterisation section this column's text belongs in, if any.
     *
     * Resources deliberately map to NOTHING. «Uma necessidade do aluno» and «um
     * apoio mobilizado para ele» are different facts, and writing the second
     * into the first would be the application asserting that a Centro de
     * Recursos para a Inclusão IS a child's need. That there is no structured
     * home for a resource yet is a reason to say so in the preview, not a
     * licence to degrade it into the nearest column that happens to exist.
     */
    public function section(): ?CharacterisationSection
    {
        return match ($this) {
            self::Characterisation => CharacterisationSection::Summary,
            self::Strengths => CharacterisationSection::Strengths,
            self::Interests => CharacterisationSection::Interests,
            self::Needs => CharacterisationSection::Needs,
            self::Barriers => CharacterisationSection::Barriers,
            self::Participation => CharacterisationSection::Participation,
            default => null,
        };
    }

    /** Columns used to find the student rather than to describe them. */
    public function isIdentifying(): bool
    {
        return in_array($this, [self::StudentName, self::SchoolNumber, self::ClassNumber], true);
    }
}
