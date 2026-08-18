<?php

namespace App\Domain\Reporting;

use App\Models\EvidenceKind;

/**
 * The aspects a teacher can flag alongside the behaviour and attitude
 * characterisation (§11).
 *
 * A CHECKLIST, NOT A GRADE. Selecting «autonomia» says the teacher considers it
 * relevant enough to mention — it carries no value, positive or negative, on
 * its own. Which is why each one is stated in a sentence together with the
 * direction the teacher chose, and never printed as a bare list of nouns that
 * the reader has to interpret.
 *
 * Nothing is compulsory. A teacher who selects none gets no sentence, which is
 * the correct output for «nada a assinalar» — not a paragraph saying so.
 */
enum ComplementaryIndicator: string
{
    case Participation = 'participation';
    case TaskCompletion = 'task_completion';
    case Autonomy = 'autonomy';
    case Organisation = 'organisation';
    case WorkMethods = 'work_methods';
    case Attention = 'attention';
    case Interest = 'interest';
    case Collaboration = 'collaboration';
    case Persistence = 'persistence';
    case Homework = 'homework';

    public function label(): string
    {
        return match ($this) {
            self::Participation => __('Participação'),
            self::TaskCompletion => __('Cumprimento de tarefas'),
            self::Autonomy => __('Autonomia'),
            self::Organisation => __('Organização'),
            self::WorkMethods => __('Métodos de trabalho'),
            self::Attention => __('Atenção e concentração'),
            self::Interest => __('Interesse e motivação'),
            self::Collaboration => __('Colaboração'),
            self::Persistence => __('Persistência perante as dificuldades'),
            self::Homework => __('Cumprimento dos trabalhos de casa'),
        };
    }

    /** The noun as it appears inside a clause. */
    public function noun(): string
    {
        return match ($this) {
            self::Participation => __('a participação'),
            self::TaskCompletion => __('o cumprimento de tarefas'),
            self::Autonomy => __('a autonomia'),
            self::Organisation => __('a organização'),
            self::WorkMethods => __('os métodos de trabalho'),
            self::Attention => __('a atenção e a concentração'),
            self::Interest => __('o interesse e a motivação'),
            self::Collaboration => __('a colaboração'),
            self::Persistence => __('a persistência perante as dificuldades'),
            self::Homework => __('o cumprimento dos trabalhos de casa'),
        };
    }

    /**
     * The kinds of logbook entry that make evidence for this indicator, so the
     * characterisation screen can show the teacher what is already recorded
     * (§12) — «existem 8 registos associados a atenção/concentração».
     *
     * SUGGESTION, NEVER CONCLUSION. The count is shown beside the question; it
     * does not tick a box, and it does not enter a sentence on its own.
     *
     * @return list<EvidenceKind>
     */
    public function evidenceKinds(): array
    {
        return match ($this) {
            self::Homework => [EvidenceKind::Homework],
            self::Participation => [EvidenceKind::Participation],
            self::Interest, self::Collaboration => [EvidenceKind::PositiveBehaviour],
            self::Attention, self::Persistence, self::WorkMethods,
            self::Organisation, self::Autonomy, self::TaskCompletion => [EvidenceKind::Difficulty, EvidenceKind::Progress],
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
