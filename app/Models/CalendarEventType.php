<?php

namespace App\Models;

/**
 * The four kinds of acontecimento the calendar owns (Fase 5.3) — and only
 * these four.
 *
 * A CLOSED SET, chosen because each one is a dated thing with NO other home in
 * the application: uma avaliação is an Instrument, a estrutura do ano is an
 * AcademicPeriod, and o horário is a RecurringLessonSlot. Anything that already
 * has a home does not belong here, and the set does not grow to accommodate it.
 *
 * The internal names are English, like every other enum here; the labels are
 * the only thing the teacher ever reads, and they are pt-PT.
 *
 * `Other` É A ÚNICA CUJO VALOR E RÓTULO NÃO SE PARECEM, e é de propósito. O
 * valor guardado é `other` desde o primeiro dia e continua a sê-lo — está
 * escrito em linhas que já existem —, mas o que o professor lê é «Data
 * relevante»: uma data que importa e que não é uma reunião, uma atividade nem
 * uma visita. Não diz — e nunca pode passar a dizer — que naquele dia não há
 * aula; isso é uma AcademicCalendarException, que é outra tabela e outra
 * decisão. Renomear o valor para casar com o rótulo daria uma migração inteira
 * a troco de nada, e por isso a divergência fica, escrita aqui.
 */
enum CalendarEventType: string
{
    case Meeting = 'meeting';
    case Activity = 'activity';
    case FieldTrip = 'field_trip';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Meeting => __('Reunião'),
            self::Activity => __('Atividade'),
            self::FieldTrip => __('Visita de estudo'),
            self::Other => __('Data relevante'),
        };
    }

    /**
     * The compact form the calendar grid puts in front of the title, where a
     * cell has room for a word and not for a sentence. It is a WORD and not a
     * colour: paired with the type's own icon, it is what keeps the four kinds
     * — and an avaliação among them — apart on a monochrome screen and for a
     * reader who does not see the difference in hue.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Meeting => __('REUNIÃO'),
            self::Activity => __('ATIVIDADE'),
            self::FieldTrip => __('VISITA'),
            self::Other => __('DATA RELEVANTE'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
