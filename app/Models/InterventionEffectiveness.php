<?php

namespace App\Models;

/**
 * What the TEACHER observed, in their own words for the record (§25, §26).
 *
 * NOTHING HERE IS COMPUTED. An intervention in March and a result that rose in
 * April are two facts on a timeline; no rule in this application turns them into
 * an appraisal, and the only way a value below is ever set is somebody choosing
 * it. «Ainda não avaliado» is the absence of a value, not a value — a follow-up
 * may record an observation without judging its effect, and most early ones do.
 *
 * THE WORDS ARE ABOUT EVOLUTION, NOT ABOUT SUCCESS. «Eficaz» and «sem efeito»
 * grade the teacher's work; «evolução favorável observada» and «sem evolução
 * observável» describe what was seen in a child, which is the only thing anybody
 * actually observed. The stored values are unchanged, so no historical
 * appraisal was recategorised — only how they read.
 */
enum InterventionEffectiveness: string
{
    case Effective = 'effective';
    case PartiallyEffective = 'partially_effective';
    case NotEffective = 'not_effective';
    case Inconclusive = 'inconclusive';
    case NeedsReformulation = 'needs_reformulation';

    public function label(): string
    {
        return match ($this) {
            self::Effective => __('Evolução favorável observada'),
            self::PartiallyEffective => __('Evolução parcial observada'),
            self::NotEffective => __('Sem evolução observável'),
            self::Inconclusive => __('Ainda não é possível avaliar'),
            self::NeedsReformulation => __('Necessita de reformulação'),
        };
    }

    /**
     * A short form for a list, where the full sentence would not fit.
     *
     * Still a description of what was seen — «favorável», not «bom».
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Effective => __('Evolução favorável'),
            self::PartiallyEffective => __('Evolução parcial'),
            self::NotEffective => __('Sem evolução'),
            self::Inconclusive => __('Por avaliar'),
            self::NeedsReformulation => __('A reformular'),
        };
    }

    /**
     * @return list<array{value: string, label: string, short_label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'short_label' => $case->shortLabel(),
        ], self::cases());
    }
}
