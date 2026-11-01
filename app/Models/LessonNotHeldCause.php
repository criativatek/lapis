<?php

namespace App\Models;

/**
 * Porque é que uma aula prevista NÃO se realizou (`outcome = not_held`) — SÓ
 * CATEGORIA fechada. A descrição de «Outro» é um campo próprio, curto e
 * obrigatório (`outcome_cause_note`), nunca a nota da atividade da turma.
 *
 * Distinta de `TeacherAbsenceReason`: aqui o professor não faltou por motivo
 * seu; a aula não aconteceu por uma causa que a escola ou o dia impôs.
 */
enum LessonNotHeldCause: string
{
    case Strike = 'strike';
    case SchoolNotOperating = 'school_not_operating';
    case WeatherClosure = 'weather_closure';
    case FacilitiesProblem = 'facilities_problem';
    case Organizational = 'organizational';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Strike => __('Greve'),
            self::SchoolNotOperating => __('Escola sem condições de funcionamento'),
            self::WeatherClosure => __('Encerramento por mau tempo'),
            self::FacilitiesProblem => __('Problema nas instalações'),
            self::Organizational => __('Razão organizacional da escola'),
            self::Other => __('Outra causa'),
        };
    }

    /** Resolvidas pelo próprio dia: um aviso de interrupção do calendário não as volta a pedir para rever. */
    public function settlesCalendarInterruption(): bool
    {
        return $this === self::SchoolNotOperating || $this === self::WeatherClosure;
    }
}
