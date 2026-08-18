<?php

namespace App\Domain\Reporting;

/**
 * Every section any report type can contain.
 *
 * ONE CATALOGUE, NOT FOUR. A class report and an individual report share
 * `difficulties`, `improvement_proposals` and `final_synthesis` because they
 * are the same section asked about a different subject — and sharing the key
 * means sharing the generator, the capability gate and the editor.
 *
 * THE VALUES ARE HISTORY. A key is written into `report_sections.key` and into
 * every finalized document, so rewording a heading is free and changing a value
 * is not: it would orphan sections in drafts and rename them in documents that
 * were supposed to be frozen. Headings live in SectionCatalogue and may move;
 * these strings may not.
 */
enum SectionKey: string
{
    // ---------------------------------------------------------------- turma
    case ClassIdentification = 'class_identification';
    case OverallAssessment = 'overall_assessment';
    case DomainResults = 'domain_results';
    case ClassEvolution = 'class_evolution';
    case ClassDistribution = 'class_distribution';
    case ClassSelfAssessment = 'class_self_assessment';
    case ClassRecords = 'class_records';
    case StudentsRequiringAttention = 'students_requiring_attention';

    // ------------------------------------------------------------- indivíduo
    case StudentIdentification = 'student_identification';
    case StudentSynthesis = 'student_synthesis';
    case StudentDomainPerformance = 'student_domain_performance';
    case StudentEvolution = 'student_evolution';
    case StudentClassification = 'student_classification';
    case StudentSelfAssessment = 'student_self_assessment';
    case StudentRecords = 'student_records';

    // --------------------------------------------------------------- registos
    case RecordsScope = 'records_scope';
    case RecordsSummary = 'records_summary';
    case RecordsDistribution = 'records_distribution';
    case RecordsTimeline = 'records_timeline';

    // ----------------------------------------------------------------- escola
    case SchoolScope = 'school_scope';
    case SchoolCoverage = 'school_coverage';
    case SchoolOverview = 'school_overview';
    case SchoolResults = 'school_results';
    case SchoolByYear = 'school_by_year';
    case SchoolBySubject = 'school_by_subject';
    case SchoolCharacterization = 'school_characterization';
    case SchoolComparability = 'school_comparability';

    // ---------------------------------------------------------------- comuns
    case PlanningCompliance = 'planning_compliance';
    case InterventionsSummary = 'interventions_summary';
    case BehaviourAttitude = 'behaviour_attitude';
    case Difficulties = 'difficulties';
    case ImprovementProposals = 'improvement_proposals';
    case FinalSynthesis = 'final_synthesis';
}
