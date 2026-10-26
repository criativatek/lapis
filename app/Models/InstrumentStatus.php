<?php

namespace App\Models;

enum InstrumentStatus: string
{
    case Draft = 'draft';
    case Prepared = 'prepared';
    case InCorrection = 'in_correction';
    case Completed = 'completed';
    case Published = 'published';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    /**
     * Os estados que o Painel do Professor nunca mostra como elemento do
     * período: um rascunho ainda não é um elemento, um anulado deixou de o
     * ser, um arquivado já não é deste ano. Uma regra, lida pela semana, pelo
     * que se aproxima e pela régua do período.
     *
     * @return list<string>
     */
    public static function hiddenFromDashboard(): array
    {
        return [self::Draft->value, self::Cancelled->value, self::Archived->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Em preparação'),
            self::Prepared => __('Preparado'),
            self::InCorrection => __('Em correção'),
            self::Completed => __('Concluído'),
            self::Published => __('Publicado'),
            self::Cancelled => __('Anulado'),
            self::Archived => __('Arquivado'),
        };
    }

    /**
     * Whether the calculation engine may read this instrument's scores.
     *
     * `in_correction` DOES enter, with whatever scores exist — that is what lets
     * a teacher watch results build up mid-marking. Students not yet corrected
     * stay `pending` and count for nothing (not even zero).
     */
    public function entersCalculation(): bool
    {
        return match ($this) {
            self::Draft, self::Cancelled => false,
            self::Prepared, self::InCorrection, self::Completed, self::Published, self::Archived => true,
        };
    }

    /**
     * Whether this instrument's correction is DONE — the gate for official
     * statistics in the Results tab: only a finished correction gets official
     * numbers, everything else is provisional or absent.
     *
     * `Completed` is the normal way to get here. `Published` is only reachable
     * through a backup restore and is itself post-completion, so it counts too.
     * `Archived` is deliberately NOT treated as concluded — archiving is a
     * retention/visibility state, not a statement that the correction was ever
     * finished, and nothing guarantees an archived instrument was ever
     * completed. Conservative: when in doubt, no official statistics.
     */
    public function isConcluded(): bool
    {
        return match ($this) {
            self::Completed, self::Published => true,
            self::Draft, self::Prepared, self::InCorrection, self::Cancelled, self::Archived => false,
        };
    }
}
