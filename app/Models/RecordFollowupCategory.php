<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * The three things the follow-up counts (spec 2026-10-12): never a sum of
 * them — each category has its own count, its own threshold and its own
 * message.
 *
 * Derived from the logbook entry, never stored on it: a record is a
 * `homework_not_done` only while it is a homework entry explicitly marked
 * «Não realizado». «Parcialmente realizado» and «Realizado» are not
 * incumprimento, and homework the teacher never checked has no row at all.
 */
enum RecordFollowupCategory: string
{
    case HomeworkNotDone = 'homework_not_done';
    case Lateness = 'lateness';
    case MissingMaterial = 'missing_material';

    public const int ALERT_THRESHOLD = 3;

    public function label(): string
    {
        return match ($this) {
            self::HomeworkNotDone => __('TPC não realizados'),
            self::Lateness => __('Atrasos'),
            self::MissingMaterial => __('Faltas de material'),
        };
    }

    /**
     * The noun for one occurrence, lower case, for a sentence — «um atraso»,
     * «três faltas de material».
     */
    public function occurrenceNoun(int $count): string
    {
        return match ($this) {
            self::HomeworkNotDone => $count === 1 ? __('TPC não realizado') : __('TPC não realizados'),
            self::Lateness => $count === 1 ? __('atraso') : __('atrasos'),
            self::MissingMaterial => $count === 1 ? __('falta de material') : __('faltas de material'),
        };
    }

    public static function fromRecord(EvidenceKind $kind, ?HomeworkStatus $homeworkStatus): ?self
    {
        return match ($kind) {
            EvidenceKind::Lateness => self::Lateness,
            EvidenceKind::MissingMaterial => self::MissingMaterial,
            EvidenceKind::Homework => $homeworkStatus === HomeworkStatus::NotDone ? self::HomeworkNotDone : null,
            default => null,
        };
    }

    /**
     * The WHERE that makes a logbook row an occurrence of this category.
     *
     * @param  Builder<EvidenceRecord>  $query
     */
    public function constrain(Builder $query): void
    {
        match ($this) {
            self::HomeworkNotDone => $query
                ->where('evidence_records.kind', EvidenceKind::Homework->value)
                ->where('evidence_records.homework_status', HomeworkStatus::NotDone->value),
            self::Lateness => $query->where('evidence_records.kind', EvidenceKind::Lateness->value),
            self::MissingMaterial => $query->where('evidence_records.kind', EvidenceKind::MissingMaterial->value),
        };
    }

    /**
     * Any of the three, in one WHERE — for the grouped counts.
     *
     * @param  Builder<EvidenceRecord>  $query
     */
    public static function constrainAny(Builder $query): void
    {
        $query->where(function (Builder $any): void {
            foreach (self::cases() as $category) {
                $any->orWhere(fn (Builder $one) => $category->constrain($one));
            }
        });
    }

    /**
     * The SQL expression that names the category of a row already known to be
     * one of the three (constrainAny) — portable between SQLite and MySQL.
     * Spelled out, not assembled, so it stays a literal SQL string; a unit test
     * (AssessmentPeriodTimelineTest) keeps it in step with the cases.
     */
    public const string SQL_EXPRESSION = "CASE WHEN evidence_records.kind = 'lateness' THEN 'lateness' WHEN evidence_records.kind = 'missing_material' THEN 'missing_material' ELSE 'homework_not_done' END";
}
