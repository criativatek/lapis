<?php

namespace App\Domain\Reporting;

/**
 * Whether the planning was carried out (§18).
 *
 * PURELY THE TEACHER'S STATEMENT. Lapispro does not hold a planning — it holds
 * instruments, scores and grades — so nothing here is derived from anything.
 * That is also why the section is available on the Base plan: writing
 * «a planificação foi parcialmente cumprida» from an answer the teacher gave is
 * transcription, not pedagogical analysis.
 *
 * `NotApplicable` is a real answer and produces its own sentence. Silence is a
 * different thing: a teacher who answers nothing gets no section, never a
 * default of «cumprida».
 */
enum PlanningCompliance: string
{
    case Complied = 'complied';
    case PartiallyComplied = 'partially_complied';
    case NotComplied = 'not_complied';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Complied => __('Cumprida'),
            self::PartiallyComplied => __('Parcialmente cumprida'),
            self::NotComplied => __('Não cumprida'),
            self::NotApplicable => __('Não aplicável'),
        };
    }

    /** The main clause of the section's first sentence. */
    public function sentence(): string
    {
        return match ($this) {
            self::Complied => __('A planificação prevista para o período foi cumprida.'),
            self::PartiallyComplied => __('A planificação prevista para o período foi parcialmente cumprida.'),
            self::NotComplied => __('A planificação prevista para o período não foi cumprida.'),
            self::NotApplicable => __('O cumprimento da planificação não se aplica ao âmbito deste relatório.'),
        };
    }

    /**
     * Whether it makes sense to ask what was left out. Nothing is pending when
     * everything was done, and nothing is pending in a scope where the question
     * does not apply.
     */
    public function admitsPendingContent(): bool
    {
        return $this === self::PartiallyComplied || $this === self::NotComplied;
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
