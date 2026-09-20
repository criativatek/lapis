<?php

namespace App\Models;

/**
 * An adaptation to the assessment process (§12.3 of the module brief).
 *
 * Deliberately separate from SupportMeasureLevel: using one of these says
 * nothing about whether the student is under universal, selective or additional
 * measures. A teacher may read a statement aloud to a whole class as ordinary
 * practice. The app records the adaptation and leaves the level unspecified —
 * it never infers one from the other.
 *
 * These are operational adaptations, not legal categories. Adding one to an
 * intervention never implies a formal measure of any level.
 */
enum EvaluationAdaptationCode: string
{
    case StatementReading = 'statement_reading';
    case TestReading = 'test_reading';
    case InstructionComprehensionSupport = 'instruction_comprehension_support';
    case SimplifiedWording = 'simplified_wording';
    case ResponseFormatAdaptation = 'response_format_adaptation';
    case InstrumentAdaptation = 'instrument_adaptation';
    case ExtraTime = 'extra_time';
    case SeparateRoom = 'separate_room';
    case DirectAnswerQuestions = 'direct_answer_questions';

    /**
     * Instruments that support the classification of a student with dyslexia or
     * a language disorder — the specific grids and criteria a teacher applies
     * when classifying, so that the disorder is not itself what is graded.
     *
     * It is an adaptation to the assessment PROCESS and nothing else. It is not
     * universal, not selective and not additional, and using it says nothing
     * about the student's formal status: a teacher may apply it to a student
     * under no measure at all. Its existence does not depend on any future
     * diploma — it describes something teachers do under the regime in force.
     */
    case ClassificationSupportInstruments = 'classification_support_instruments';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::StatementReading => __('Leitura de enunciados'),
            self::TestReading => __('Leitura da prova'),
            self::InstructionComprehensionSupport => __('Apoio na compreensão das instruções'),
            self::SimplifiedWording => __('Simplificação da formulação dos enunciados'),
            self::ResponseFormatAdaptation => __('Adaptação do formato da resposta'),
            self::InstrumentAdaptation => __('Adaptação do elemento de avaliação'),
            self::ExtraTime => __('Tempo suplementar'),
            self::SeparateRoom => __('Realização da prova em sala à parte'),
            self::DirectAnswerQuestions => __('Questões de resposta direta'),
            self::ClassificationSupportInstruments => __('Instrumentos de apoio à classificação — dislexia e perturbação da linguagem'),
            self::Other => __('Outra'),
        };
    }
}
