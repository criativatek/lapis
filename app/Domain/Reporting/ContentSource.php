<?php

namespace App\Domain\Reporting;

/**
 * WHERE A SENTENCE CAME FROM (§40).
 *
 * Every generated section records the sources it drew on, so that any claim in
 * a finished report can be traced back to something real. This is not decoration:
 * it is the mechanism that keeps the module honest. A section whose only source
 * is `TeacherInput` is the teacher's own statement and the system must never
 * present it as a finding; a section sourced from `Statistics` is arithmetic
 * over canonical numbers and must never acquire an interpretation the data does
 * not carry (§13).
 *
 * The UI does not have to show all of this. It has to be recorded.
 */
enum ContentSource: string
{
    /** BuildClassStatistics — counts, averages, distributions. */
    case Statistics = 'statistics';

    /** BuildResultsProgression — a student's or class's canonical results. */
    case Results = 'results';

    /** Classifications the teacher decided. The official grade (§7). */
    case Classification = 'classification';

    /** The student's own reading of themselves. Never summed into a result. */
    case SelfAssessment = 'self_assessment';

    /** Logbook entries — evidence records (§12). */
    case Records = 'records';

    /** Interventions actually registered. Never an invented measure (§19). */
    case Interventions = 'interventions';

    /** A kept photograph, read instead of live data (§30). */
    case InterimSnapshot = 'interim_snapshot';

    /** What the teacher stated because the system could not know it (§8). */
    case TeacherInput = 'teacher_input';

    /** The pedagogical library a strategy or objective was chosen from (§16). */
    case Library = 'library';

    /** Deterministic sentence assembly over the sources above (§42). */
    case GeneratedText = 'generated_text';

    /** The teacher wrote or rewrote this themselves. */
    case TeacherText = 'teacher_text';

    /** The school's letterhead (§39). */
    case Identity = 'identity';

    /** An institutional template supplied the wording (§52). */
    case InstitutionalTemplate = 'institutional_template';

    public function label(): string
    {
        return match ($this) {
            self::Statistics => __('Estatística'),
            self::Results => __('Resultados'),
            self::Classification => __('Classificações atribuídas'),
            self::SelfAssessment => __('Autoavaliação'),
            self::Records => __('Registos'),
            self::Interventions => __('Intervenções'),
            self::InterimSnapshot => __('Avaliação intercalar'),
            self::TeacherInput => __('Caracterização do professor'),
            self::Library => __('Biblioteca pedagógica'),
            self::GeneratedText => __('Texto gerado'),
            self::TeacherText => __('Texto do professor'),
            self::Identity => __('Identidade da escola'),
            self::InstitutionalTemplate => __('Modelo institucional'),
        };
    }

    /**
     * @param  list<self>  $sources
     * @return list<string>
     */
    public static function values(array $sources): array
    {
        return array_values(array_unique(array_map(fn (self $source) => $source->value, $sources)));
    }
}
