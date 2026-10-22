<?php

namespace App\Services\Assessment\Advanced\Document;

/**
 * The words and numbers of the Pro analysis document, formatted ONCE.
 *
 * THE DOCUMENT IS TEXT BY THE TIME IT LEAVES THE SERVER. The preview and the PDF draw one structure,
 * and that structure carries ready-made strings — «62,0%», «+8,5 p.p.», «8 de 27 alunos» — so neither
 * renderer formats, rounds or rewords anything: what is on the screen is what is on paper, by
 * construction. These helpers say the figures the same way the page's own (`assessmentComparison.ts`,
 * `trajectories.ts`) does; a test holds the two to the same sentences.
 *
 * A missing value is a dash, never a zero.
 */
final class DocumentFormat
{
    public const DASH = '—';

    /** «62,0%» — one decimal, comma. */
    public static function percent(?string $value): string
    {
        if ($value === null || $value === '') {
            return self::DASH;
        }

        return str_replace('.', ',', number_format((float) $value, 1, '.', '')).'%';
    }

    /** «+8,5 p.p.» — percentage POINTS: the difference of two percentages is not a percentage. */
    public static function points(?string $value): string
    {
        if ($value === null || $value === '') {
            return self::DASH;
        }

        $number = (float) $value;

        return ($number > 0 ? '+' : '').str_replace('.', ',', number_format($number, 1, '.', '')).' p.p.';
    }

    public static function students(int $count): string
    {
        return $count === 1 ? '1 aluno' : "{$count} alunos";
    }

    /** «8 de 27 alunos» — a count never travels without the total it rests on. */
    public static function countOf(int $count, int $of): string
    {
        return "{$count} de {$of} ".($of === 1 ? 'aluno' : 'alunos');
    }

    /**
     * «Insuficiente → Suficiente — passou a nível positivo», or the sentence that says why there is no change.
     *
     * @param  array<string, mixed>  $change  as `TimelineMovement::level()` / `classification()` writes it
     */
    public static function levelChange(array $change): string
    {
        if (($change['state'] ?? 'unavailable') === 'unavailable') {
            return match ($change['reason'] ?? null) {
                'missing' => 'Sem nível num dos momentos.',
                'not_comparable' => 'A escala não é demonstravelmente a mesma nos dois momentos.',
                'no_sequence' => 'O nível não tem lugar na escala.',
                'decided_on_another_scale' => 'Decidido noutra escala.',
                default => self::DASH,
            };
        }

        $from = $change['from']['label'] ?? $change['from']['code'] ?? self::DASH;
        $to = $change['to']['label'] ?? $change['to']['code'] ?? self::DASH;

        if ($change['state'] === 'same') {
            return "Manteve «{$to}»";
        }

        $line = match ($change['crossed_line'] ?? null) {
            'to_positive' => ' — passou a nível positivo',
            'to_negative' => ' — passou a nível negativo',
            default => '',
        };

        return "{$from} → {$to}{$line}";
    }

    public static function movementWord(string $state): string
    {
        return ['up' => 'Progrediu', 'down' => 'Regrediu', 'flat' => 'Manteve-se', 'unavailable' => 'Sem comparação'][$state] ?? self::DASH;
    }

    public static function movementReason(?string $reason): string
    {
        return match ($reason) {
            'missing_from' => 'Sem resultado no primeiro momento.',
            'missing_to' => 'Sem resultado no segundo momento.',
            'missing_both' => 'Sem resultado em nenhum dos momentos.',
            'not_comparable' => 'Os critérios mudaram entre os dois momentos.',
            default => '',
        };
    }

    public static function trendWord(string $state): string
    {
        return [
            'consistent_up' => 'Progressão consistente',
            'consistent_down' => 'Regressão consistente',
            'stable' => 'Estável',
            'variable' => 'Evolução variável',
            'insufficient' => 'Sem momentos suficientes',
        ][$state] ?? self::DASH;
    }

    /**
     * «Subiu em 3 de 3 passos» — the evidence for a trend, in numbers.
     *
     * @param  array<string, mixed>  $trend
     */
    public static function trendSteps(array $trend): string
    {
        $total = count($trend['steps']);

        if ($total === 0) {
            return $trend['with_value'] > 0
                ? $trend['with_value'].' '.($trend['with_value'] === 1 ? 'momento' : 'momentos').' com valor: uma tendência precisa de 3'
                : 'Sem valores';
        }

        $parts = [];

        if ($trend['counts']['up']) {
            $parts[] = 'subiu em '.$trend['counts']['up'];
        }

        if ($trend['counts']['flat']) {
            $parts[] = 'ficou igual em '.$trend['counts']['flat'];
        }

        if ($trend['counts']['down']) {
            $parts[] = 'desceu em '.$trend['counts']['down'];
        }

        return ucfirst(implode(', ', $parts)." de {$total} passos");
    }

    /**
     * @param  array<string, mixed>  $persistent
     */
    public static function persistent(array $persistent): ?string
    {
        return match ($persistent['state']) {
            'yes' => "Abaixo do nível positivo em {$persistent['run']} momentos seguidos, incluindo o mais recente.",
            'no_recent' => 'Sem valor no momento mais recente: não se pode dizer que continua abaixo do nível positivo.',
            'unknown' => 'Sem linha entre positivo e negativo no momento mais recente: não se sabe se está abaixo do nível positivo.',
            default => null,
        };
    }

    /**
     * The values along the moments, with a dash for a moment without one.
     *
     * @param  list<string|null>  $values
     */
    public static function values(array $values): string
    {
        return implode(' → ', array_map(fn (?string $value): string => self::percent($value), $values));
    }
}
