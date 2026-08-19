<?php

namespace App\Domain\Reporting;

use App\Models\ActivityEvaluation;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\HomeworkStatus;
use App\Models\ParticipationLevel;

/**
 * Which way a logbook entry points — WHERE THAT SEMANTICS ACTUALLY EXISTS (§21).
 *
 * THE HONEST PART OF THIS FILTER IS THE FOURTH CASE. Some entries genuinely
 * carry a direction: a disciplinary occurrence is negative, «trabalho de casa
 * realizado» is positive, «comportamento meritório» is positive by definition.
 * Others carry none at all — a Contacto, an Observação, an Apoio are records of
 * something happening, not judgements of it — and forcing them onto a positive/
 * negative axis would invent a meaning the teacher never recorded.
 *
 * So `Undetermined` is not a leftover bucket. It is the answer for every entry
 * whose kind has no direction, and it is reported under its own name rather
 * than being counted as neutral-therefore-fine.
 *
 * NOTHING HERE IS A GRADE. A record never enters the calculation (§14.3), and
 * a count of negative records is a count of records — never a statement about
 * a student, and never a rate over people (§70, §71).
 */
enum RecordValence: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Neutral = 'neutral';
    case Undetermined = 'undetermined';

    public function label(): string
    {
        return match ($this) {
            self::Positive => __('Positivo'),
            self::Negative => __('Negativo'),
            self::Neutral => __('Neutro'),
            self::Undetermined => __('Sem sentido definido'),
        };
    }

    /**
     * The predicate this valence takes inside a sentence: «cinco têm sentido
     * negativo», «três não têm sentido definido».
     *
     * Separate from label(), which is a column heading. The fourth case takes a
     * negative predicate because that is what it means — nobody recorded a
     * direction — and «têm sentido sem sentido definido» is not a sentence.
     */
    public function clause(int $count): string
    {
        $verb = $count === 1 ? 'tem' : 'têm';

        return match ($this) {
            self::Positive => $verb.' sentido positivo',
            self::Negative => $verb.' sentido negativo',
            self::Neutral => $verb.' sentido neutro',
            self::Undetermined => ($count === 1 ? 'não tem' : 'não têm').' sentido definido',
        };
    }

    /**
     * The direction of one record, read from what was actually recorded.
     *
     * The type-specific field wins over the kind, because it is the more
     * specific statement: a Trabalho de casa entry says nothing until its
     * status does.
     */
    public static function of(EvidenceRecord $record): self
    {
        // A disciplinary occurrence is negative by what it is; a merit record
        // is positive by what it is. Neither needs a second field.
        if ($record->kind === EvidenceKind::Incident) {
            return self::Negative;
        }

        if ($record->kind === EvidenceKind::PositiveBehaviour) {
            return self::Positive;
        }

        if ($record->homework_status !== null) {
            return match ($record->homework_status) {
                HomeworkStatus::Done => self::Positive,
                HomeworkStatus::NotDone => self::Negative,
                HomeworkStatus::PartiallyDone => self::Neutral,
            };
        }

        if ($record->participation_level !== null) {
            return match ($record->participation_level) {
                ParticipationLevel::Positive => self::Positive,
                ParticipationLevel::Reduced => self::Negative,
                ParticipationLevel::Adequate => self::Neutral,
            };
        }

        if ($record->activity_evaluation !== null) {
            return match ($record->activity_evaluation) {
                ActivityEvaluation::VeryPositive, ActivityEvaluation::Positive => self::Positive,
                ActivityEvaluation::NotVeryPositive => self::Negative,
                ActivityEvaluation::Satisfactory => self::Neutral,
            };
        }

        // Progresso and Dificuldade describe what was observed about learning;
        // they are the closest thing to a direction the remaining kinds have.
        return match ($record->kind) {
            EvidenceKind::Progress => self::Positive,
            EvidenceKind::Difficulty => self::Negative,
            // Apoio, Contacto, Atividade sem avaliação, Observação: things that
            // happened. Nothing about them is good or bad, and saying otherwise
            // would be inventing.
            default => self::Undetermined,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
