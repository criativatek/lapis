<?php

namespace App\Models;

/**
 * What KIND of thing a catalogue item is, once a legal framework has read it.
 *
 * «Estratégias e Medidas» has always shown four different kinds of thing side
 * by side, and calling all of them "medidas" is a legal error the page has been
 * making by omission: «Apoio à planificação textual» is not a measure under any
 * diploma, and «Instrumentos de apoio à classificação» is an adaptation to the
 * assessment process, not a measure at any level.
 *
 * The family is NOT a property of the pedagogical item. It is a property of the
 * item AS READ BY ONE FRAMEWORK, which is why it is produced by
 * InterventionLegalFramework::familyFor() and never by InterventionType. The
 * same act is a legal measure in Portugal and plain teaching elsewhere; under
 * NullLegalFramework nothing is a legal measure, because there is no law to
 * make it one.
 */
enum CatalogueFamily: string
{
    /**
     * A measure named by the law in force, at a level the law defines. This is
     * the only family that may ever carry a SupportMeasureLevel.
     */
    case LegalMeasure = 'legal_measure';

    /**
     * Ordinary teaching. It may be mobilised in support of a measure, but it is
     * not itself one, and recording it says nothing about a student's formal
     * status.
     */
    case PedagogicalStrategy = 'pedagogical_strategy';

    /**
     * An adaptation to the assessment process. Deliberately never a measure and
     * never a level — §12.3 and the whole reason EvaluationAdaptationCode is a
     * separate enum.
     */
    case EvaluationAdaptation = 'evaluation_adaptation';

    /**
     * A resource or specialised support mobilised for a student (a CRI, a
     * specialised technician). Distinct from a legal measure: a resource may
     * support a measure without being one, and the app must not turn the
     * presence of a resource into a legal classification.
     *
     * No item in today's catalogue carries this family — Lapispro has never
     * offered CRI or specialised-resource items, and inventing them is exactly
     * what the project forbids (§1). The family exists because the distinction
     * must be representable the day such an item is added, and is exercised by
     * the fictitious framework in the tests.
     */
    case SupportResource = 'support_resource';

    public function label(): string
    {
        return match ($this) {
            self::LegalMeasure => __('Medida legal'),
            self::PedagogicalStrategy => __('Estratégia pedagógica'),
            self::EvaluationAdaptation => __('Adaptação ao processo de avaliação'),
            self::SupportResource => __('Apoio ou recurso'),
        };
    }

    /**
     * Whether an item of this family may carry a support-measure level.
     *
     * Only legal measures may. This is the guard that keeps an assessment
     * adaptation from being read as "the student is under selective measures",
     * which is a legal statement the app must never make on its own.
     */
    public function mayCarryMeasureLevel(): bool
    {
        return $this === self::LegalMeasure;
    }
}
