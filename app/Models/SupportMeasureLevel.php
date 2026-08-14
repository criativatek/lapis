<?php

namespace App\Models;

/**
 * The level of a support measure under the Portuguese inclusive-education
 * framework (Decreto-Lei 54/2018): universal, selective, additional.
 *
 * Recording this on an intervention says "this action fits pedagogically at
 * this level" — it does NOT say the student is formally covered by measures at
 * that level (§11 of the module brief). Those are different facts: the first is
 * about the teacher's action, the second about the student's formal status, and
 * only the second is a legal determination the app must never infer on its own.
 */
enum SupportMeasureLevel: string
{
    case Universal = 'universal';
    case Selective = 'selective';
    case Additional = 'additional';

    public function label(): string
    {
        return match ($this) {
            self::Universal => __('Medida universal'),
            self::Selective => __('Medida seletiva'),
            self::Additional => __('Medida adicional'),
        };
    }
}
