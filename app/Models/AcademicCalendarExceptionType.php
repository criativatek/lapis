<?php

namespace App\Models;

/**
 * As três espécies de «exceção letiva» — os dias em que NÃO há aula (Fase 5.4).
 *
 * A CLOSED SET, and it is closed around one single question: «isto impede a
 * aula de acontecer?». Um feriado, uma interrupção letiva e um dia não letivo
 * respondem que sim; uma reunião, uma atividade, uma visita de estudo e um
 * «outro» respondem que não, e por isso são CalendarEventType e não isto.
 *
 * A DISTINÇÃO NÃO É DE FEITIO, É DE CONSEQUÊNCIA. Um acontecimento é pessoal e
 * apenas datado; uma exceção é estrutura da organização inteira, ao lado do
 * AcademicPeriod, e é o que — numa fase seguinte — dirá que naquele dia não se
 * materializa aula nenhuma. Foi por isso que ganhou tabela própria em vez de
 * um quinto valor no enum ao lado.
 *
 * The internal names are English, like every other enum here; the labels are
 * the only thing the teacher ever reads, and they are pt-PT.
 */
enum AcademicCalendarExceptionType: string
{
    case Holiday = 'holiday';
    case SchoolBreak = 'school_break';
    case NonTeachingDay = 'non_teaching_day';

    public function label(): string
    {
        return match ($this) {
            self::Holiday => __('Feriado'),
            self::SchoolBreak => __('Interrupção letiva'),
            self::NonTeachingDay => __('Dia não letivo'),
        };
    }

    /**
     * A forma compacta que a grelha do calendário põe à frente do título, onde
     * a célula tem espaço para uma palavra e não para uma frase. É uma PALAVRA
     * e não uma cor: emparelhada com o ícone próprio da espécie, é o que mantém
     * as três distinguíveis — e distinguíveis de um período e de um
     * acontecimento — num ecrã monocromático e para quem não vê a diferença de
     * matiz. Exatamente a mesma disciplina de CalendarEventType::shortLabel().
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Holiday => __('FERIADO'),
            self::SchoolBreak => __('INTERRUPÇÃO'),
            self::NonTeachingDay => __('NÃO LETIVO'),
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
