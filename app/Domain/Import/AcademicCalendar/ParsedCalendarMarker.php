<?php

namespace App\Domain\Import\AcademicCalendar;

use App\Models\CalendarEventType;

/**
 * Uma data com nome que o documento marca e que NÃO É UM DIA SEM AULA — e é isso,
 * e só isso, que a separa de um ParsedCalendarRange.
 *
 * ISTO DEIXOU DE SER A GAVETA DO QUE SOBRA. Nasceu para os «Fim 9.º ano» que a
 * aplicação não sabe arrumar, e hoje é também o destino NORMAL de tudo o que um
 * calendário escolar traz e não é feriado nem interrupção: reuniões,
 * apresentações, atividades, convívios. A mudança é de fundo — antes essas datas
 * eram escritas como feriados, e um feriado apaga a aula do dia.
 *
 * O TIPO VIAJA COM A DATA. `CalendarEventType::Other` («Data relevante») é o
 * neutro honesto de quando o documento não diz que espécie de acontecimento é;
 * quando diz — «Reunião de avaliação», «Visita de estudo a Belém» —, é o que ele
 * diz que fica. Nunca se adivinha a partir de uma palavra frágil (ver
 * ClassifyCalendarDay).
 *
 * `$kind` explica PORQUÊ isto não é uma exceção letiva, que é uma pergunta
 * diferente de «que espécie de acontecimento é», e a pré-visualização escreve
 * uma frase diferente para cada uma.
 */
final readonly class ParsedCalendarMarker
{
    /**
     * @param  string  $title  o nome já em condições de ser lido fora da grelha —
     *                         num `CohortEnd`, a coorte escrita por extenso pelo
     *                         CohortMarkerTitle a partir da abreviatura da célula,
     *                         que fica guardada em `$rawText`
     * @param  string  $date  «Y-m-d»
     * @param  string  $rawText  a célula tal como está escrita no ficheiro
     */
    public function __construct(
        public string $title,
        public string $date,
        public string $rawText,
        public CalendarEventType $type = CalendarEventType::Other,
        public ParsedCalendarMarkerKind $kind = ParsedCalendarMarkerKind::SchoolEvent,
    ) {}
}
