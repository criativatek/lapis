<?php

namespace App\Domain\Reporting;

use App\Models\ReportType;

/**
 * THE STRUCTURE OF EACH REPORT TYPE, IN ONE PLACE.
 *
 * The order here is the order on the page, in the editor, in the preview, in
 * the PDF and in the Word file. There is no second list anywhere: a section
 * added here appears everywhere, and one removed here disappears from new
 * reports without touching a single finalized document — those carry their own
 * copy of the structure they were written with (§38).
 *
 * WHAT IS BASE AND WHAT IS NOT (§4). A section is Base when the sentences it
 * produces are descriptions of data that already exists: how many students,
 * which domain averaged highest, what the teacher stated about the planning.
 * A section requires `report_pedagogical_analysis` when producing it means
 * INTERPRETING — naming a difficulty, characterising an attitude, proposing a
 * measure. The line is §13's: a low result in Escrita supports «resultados
 * menos consistentes no domínio da Escrita» and does not support «falta de
 * estudo», and no plan changes that.
 *
 * The class structure follows the reference document's four blocks (§5) and
 * expands them, keeping their order: what the class is, how it did, why, and
 * what comes next.
 */
class SectionCatalogue
{
    /** The capability that separates a descriptive report from an analytical one. */
    public const PEDAGOGICAL_MODULE = 'report_pedagogical_analysis';

    /**
     * @return list<SectionDefinition>
     */
    public static function for(ReportType $type): array
    {
        return match ($type) {
            ReportType::SchoolClass => self::classSections(),
            ReportType::Student => self::studentSections(),
            ReportType::Records => self::recordsSections(),
            ReportType::School => self::schoolSections(),
        };
    }

    /**
     * @return list<SectionDefinition>
     */
    protected static function classSections(): array
    {
        return [
            new SectionDefinition(
                SectionKey::ClassIdentification,
                'Identificação e caracterização da turma',
            ),
            new SectionDefinition(
                SectionKey::OverallAssessment,
                'Síntese da avaliação global',
            ),
            new SectionDefinition(
                SectionKey::ClassDistribution,
                'Distribuição das classificações atribuídas',
            ),
            new SectionDefinition(
                SectionKey::DomainResults,
                'Análise dos resultados por domínio',
            ),
            new SectionDefinition(
                SectionKey::ClassEvolution,
                'Evolução da turma',
            ),
            // Off by default: the reference structure does not ask for it, and
            // a student's own reading of themselves is not something a class
            // report needs unless the teacher wants it there (§45).
            new SectionDefinition(
                SectionKey::ClassSelfAssessment,
                'Autoavaliação dos alunos',
                defaultIncluded: false,
            ),
            new SectionDefinition(
                SectionKey::ClassRecords,
                'Registos do período',
            ),
            // The teacher's own statement about the planning. Descriptive, not
            // inferred — the system knows nothing about it until they say so,
            // and then it only writes the sentence (§18).
            new SectionDefinition(
                SectionKey::PlanningCompliance,
                'Cumprimento da planificação',
                needsTeacherInput: true,
            ),
            // What was actually registered in Intervenções. Listing real
            // records is description; proposing new ones is not (§19).
            new SectionDefinition(
                SectionKey::InterventionsSummary,
                'Estratégias e medidas implementadas',
            ),
            new SectionDefinition(
                SectionKey::BehaviourAttitude,
                'Comportamento e atitude face às aprendizagens',
                module: self::PEDAGOGICAL_MODULE,
                needsTeacherInput: true,
            ),
            new SectionDefinition(
                SectionKey::Difficulties,
                'Principais dificuldades identificadas',
                module: self::PEDAGOGICAL_MODULE,
                needsTeacherInput: true,
            ),
            new SectionDefinition(
                SectionKey::ImprovementProposals,
                'Propostas de superação das dificuldades',
                module: self::PEDAGOGICAL_MODULE,
                needsTeacherInput: true,
            ),
            // OFF BY DEFAULT AND NAMES PEOPLE. A class report is aggregate
            // until a teacher decides otherwise; this is the one section that
            // can put a minor's name on it, and it is never ticked for them
            // (§28, §57).
            new SectionDefinition(
                SectionKey::StudentsRequiringAttention,
                'Alunos que requerem acompanhamento particular',
                module: self::PEDAGOGICAL_MODULE,
                defaultIncluded: false,
                needsTeacherInput: true,
                mayNameStudents: true,
            ),
            new SectionDefinition(
                SectionKey::FinalSynthesis,
                'Síntese final e perspetivas',
            ),
        ];
    }

    /**
     * @return list<SectionDefinition>
     */
    protected static function studentSections(): array
    {
        return [
            new SectionDefinition(
                SectionKey::StudentIdentification,
                'Identificação',
            ),
            new SectionDefinition(
                SectionKey::StudentSynthesis,
                'Síntese global',
            ),
            new SectionDefinition(
                SectionKey::StudentDomainPerformance,
                'Desempenho por domínio',
            ),
            new SectionDefinition(
                SectionKey::StudentEvolution,
                'Evolução',
            ),
            new SectionDefinition(
                SectionKey::StudentClassification,
                'Classificação atribuída',
            ),
            new SectionDefinition(
                SectionKey::StudentSelfAssessment,
                'Autoavaliação',
            ),
            new SectionDefinition(
                SectionKey::StudentRecords,
                'Registos e intervenções',
            ),
            new SectionDefinition(
                SectionKey::BehaviourAttitude,
                'Comportamento e atitude face às aprendizagens',
                module: self::PEDAGOGICAL_MODULE,
                needsTeacherInput: true,
            ),
            new SectionDefinition(
                SectionKey::Difficulties,
                'Dificuldades identificadas',
                module: self::PEDAGOGICAL_MODULE,
                needsTeacherInput: true,
            ),
            new SectionDefinition(
                SectionKey::ImprovementProposals,
                'Estratégias e propostas de superação',
                module: self::PEDAGOGICAL_MODULE,
                needsTeacherInput: true,
            ),
            new SectionDefinition(
                SectionKey::FinalSynthesis,
                'Síntese final',
            ),
        ];
    }

    /**
     * @return list<SectionDefinition>
     */
    protected static function recordsSections(): array
    {
        return [
            new SectionDefinition(
                SectionKey::RecordsScope,
                'Âmbito do relatório',
            ),
            new SectionDefinition(
                SectionKey::RecordsSummary,
                'Síntese',
            ),
            new SectionDefinition(
                SectionKey::RecordsDistribution,
                'Distribuição por categoria',
            ),
            // The «detalhado» mode of §23. Off by default because a
            // chronological listing is long and, when it names students, is the
            // most identifying thing this module produces.
            new SectionDefinition(
                SectionKey::RecordsTimeline,
                'Cronologia detalhada',
                defaultIncluded: false,
                mayNameStudents: true,
            ),
            new SectionDefinition(
                SectionKey::FinalSynthesis,
                'Síntese final',
                defaultIncluded: false,
            ),
        ];
    }

    /**
     * @return list<SectionDefinition>
     */
    protected static function schoolSections(): array
    {
        return [
            new SectionDefinition(SectionKey::SchoolScope, 'Identificação e âmbito'),
            // Before any figure: how much of the school this actually describes.
            // A distribution over 3 of 40 classes is not a school's results, and
            // saying so first is not a caveat, it is the finding (§27).
            new SectionDefinition(SectionKey::SchoolCoverage, 'Cobertura dos dados'),
            new SectionDefinition(SectionKey::SchoolOverview, 'Turmas, alunos e disciplinas'),
            new SectionDefinition(SectionKey::SchoolResults, 'Classificações atribuídas e taxa de sucesso'),
            new SectionDefinition(SectionKey::SchoolByYear, 'Resultados por ano de escolaridade'),
            new SectionDefinition(SectionKey::SchoolBySubject, 'Resultados por disciplina'),
            new SectionDefinition(
                SectionKey::SchoolCharacterization,
                'Comportamento e atitude — síntese das caracterizações',
            ),
            // Never optional. Whatever else a school-wide report says, it says
            // what could not be compared and why (§26).
            new SectionDefinition(SectionKey::SchoolComparability, 'Notas de comparabilidade'),
            new SectionDefinition(SectionKey::FinalSynthesis, 'Síntese final', defaultIncluded: false),
        ];
    }

    /**
     * The definition of one section within one type, or null when that type
     * does not have it.
     */
    public static function find(ReportType $type, SectionKey $key): ?SectionDefinition
    {
        foreach (self::for($type) as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }
}
