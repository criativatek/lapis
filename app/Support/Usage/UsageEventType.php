<?php

namespace App\Support\Usage;

/**
 * O catálogo fechado de eventos de utilização. Só se grava o que está aqui:
 * `usage_events.event` é um destes valores, nunca texto livre.
 *
 * `FeatureViewed` e `OperationFailed` não têm funcionalidade própria — vem do
 * contexto (o parâmetro do middleware `usage:<feature>`).
 */
enum UsageEventType: string
{
    case FeatureViewed = 'feature.viewed';
    case OperationFailed = 'operation.failed';
    case ClassCreated = 'class.created';
    case StudentsAdded = 'students.added';
    case LessonSummarySaved = 'lesson.summary_saved';
    case LessonAttendanceRecorded = 'lesson.attendance_recorded';
    case LessonTaught = 'lesson.taught';
    case LessonOutcomeRecorded = 'lesson.outcome_recorded';
    case LessonSequenceApplied = 'lesson_sequence.applied';
    case InstrumentCreated = 'instrument.created';
    case ScoresRecorded = 'scores.recorded';
    case ClassificationDecided = 'classification.decided';
    case ClassificationsPublished = 'classifications.published';
    case EvaluationSheetSaved = 'evaluation_sheet.saved';
    case InterimAssessmentSaved = 'interim_assessment.saved';
    case RecordCreated = 'record.created';
    case HomeworkBatchSaved = 'record.homework_batch_saved';
    case InterventionCreated = 'intervention.created';
    case InterventionFollowupAdded = 'intervention.followup_added';
    case CharacterisationUpdated = 'characterisation.updated';
    case CharacterisationImported = 'characterisation.imported';
    case NotebookEntryCreated = 'class_notebook.entry_created';
    case NotebookEntryUpdated = 'class_notebook.entry_updated';
    case ReportCreated = 'report.created';
    case ReportFinalized = 'report.finalized';
    case ReportExported = 'report.exported';
    case StudentsImported = 'students.imported';
    case TimetableImported = 'timetable.imported';
    case CalendarImported = 'calendar.imported';
    case BackupExported = 'backup.exported';
    case BackupRestored = 'backup.restored';
    case InovarExported = 'inovar.exported';
    case CalendarEventCreated = 'calendar_event.created';

    public function kind(): UsageEventKind
    {
        return match ($this) {
            self::FeatureViewed => UsageEventKind::View,
            self::OperationFailed => UsageEventKind::Failure,
            default => UsageEventKind::Action,
        };
    }

    /** Nulo só para os dois eventos cuja funcionalidade vem do contexto. */
    public function feature(): ?UsageFeature
    {
        return match ($this) {
            self::FeatureViewed, self::OperationFailed => null,
            self::ClassCreated, self::StudentsAdded => UsageFeature::Classes,
            self::LessonSummarySaved,
            self::LessonAttendanceRecorded,
            self::LessonTaught,
            self::LessonOutcomeRecorded,
            self::LessonSequenceApplied => UsageFeature::Lessons,
            self::InstrumentCreated,
            self::ScoresRecorded,
            self::ClassificationDecided,
            self::ClassificationsPublished,
            self::EvaluationSheetSaved,
            self::InterimAssessmentSaved => UsageFeature::Assessment,
            self::RecordCreated, self::HomeworkBatchSaved => UsageFeature::Records,
            self::InterventionCreated, self::InterventionFollowupAdded => UsageFeature::Interventions,
            self::CharacterisationUpdated, self::CharacterisationImported => UsageFeature::Characterisation,
            self::NotebookEntryCreated, self::NotebookEntryUpdated => UsageFeature::ClassNotebook,
            self::ReportCreated, self::ReportFinalized, self::ReportExported => UsageFeature::Reports,
            self::StudentsImported,
            self::TimetableImported,
            self::CalendarImported,
            self::BackupExported,
            self::BackupRestored,
            self::InovarExported => UsageFeature::ImportExport,
            self::CalendarEventCreated => UsageFeature::Calendar,
        };
    }

    /** «Registo útil»: prova que a pessoa trabalhou na aplicação, não que a configurou. */
    public function isUsefulRecord(): bool
    {
        return match ($this) {
            self::LessonSummarySaved,
            self::LessonAttendanceRecorded,
            self::InstrumentCreated,
            self::ScoresRecorded,
            self::ClassificationDecided,
            self::EvaluationSheetSaved,
            self::RecordCreated,
            self::HomeworkBatchSaved,
            self::InterventionCreated,
            self::InterventionFollowupAdded,
            self::CharacterisationUpdated,
            self::NotebookEntryCreated,
            self::ReportCreated => true,
            default => false,
        };
    }

    public function milestone(): ?UsageMilestone
    {
        if ($this->isUsefulRecord()) {
            return UsageMilestone::FirstUsefulRecord;
        }

        return match ($this) {
            self::ClassCreated => UsageMilestone::FirstClassCreated,
            self::StudentsAdded, self::StudentsImported => UsageMilestone::FirstStudentsAdded,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FeatureViewed => 'Página consultada',
            self::OperationFailed => 'Falha técnica',
            self::ClassCreated => 'Turma criada',
            self::StudentsAdded => 'Aluno adicionado',
            self::LessonSummarySaved => 'Sumário guardado',
            self::LessonAttendanceRecorded => 'Assiduidade registada',
            self::LessonTaught => 'Aula marcada como lecionada',
            self::LessonOutcomeRecorded => 'Resultado da aula registado',
            self::LessonSequenceApplied => 'Sequência aplicada',
            self::InstrumentCreated => 'Instrumento criado',
            self::ScoresRecorded => 'Classificações lançadas',
            self::ClassificationDecided => 'Classificação decidida',
            self::ClassificationsPublished => 'Classificações publicadas',
            self::EvaluationSheetSaved => 'Pauta guardada no histórico',
            self::InterimAssessmentSaved => 'Avaliação intercalar guardada',
            self::RecordCreated => 'Registo criado',
            self::HomeworkBatchSaved => 'Trabalhos de casa registados em lote',
            self::InterventionCreated => 'Medida criada',
            self::InterventionFollowupAdded => 'Acompanhamento registado',
            self::CharacterisationUpdated => 'Caracterização atualizada',
            self::CharacterisationImported => 'Importação de caracterização confirmada',
            self::NotebookEntryCreated => 'Entrada do caderno criada',
            self::NotebookEntryUpdated => 'Entrada do caderno editada',
            self::ReportCreated => 'Relatório criado',
            self::ReportFinalized => 'Relatório finalizado',
            self::ReportExported => 'Relatório exportado',
            self::StudentsImported => 'Lista de alunos importada',
            self::TimetableImported => 'Horário importado',
            self::CalendarImported => 'Calendário da escola importado',
            self::BackupExported => 'Cópia de segurança exportada',
            self::BackupRestored => 'Cópia de segurança restaurada',
            self::InovarExported => 'Exportação INOVAR',
            self::CalendarEventCreated => 'Acontecimento criado',
        };
    }
}
