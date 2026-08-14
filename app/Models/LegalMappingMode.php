<?php

namespace App\Models;

/**
 * How confidently a type of intervention maps onto the legal framework
 * (§12 of the module brief). This is a property of the CATALOGUE, not of a
 * saved intervention — it decides what the app is allowed to do on its own.
 */
enum LegalMappingMode: string
{
    /**
     * The type is itself a named measure: no interpretation is involved, so the
     * app fills the framing in and the teacher may still change or clear it.
     */
    case Direct = 'direct';

    /**
     * The everyday meaning of the type overlaps with a named measure, but is
     * also plain good teaching. "Apoio individualizado" may or may not be a
     * formally mobilised measure — only the teacher knows. The app may suggest;
     * it must never decide.
     */
    case Contextual = 'contextual';

    /**
     * An adaptation to the assessment process. Carries its own code and never
     * a measure level: using it says nothing about the student's formal status.
     */
    case EvaluationOnly = 'evaluation_only';

    /** Ordinary pedagogical practice with no legal framing attached. */
    case None = 'none';
}
