<?php

namespace App\Domain\Import\AcademicCalendar;

/**
 * Tudo o que a leitura conseguiu tirar de UM calendário escolar — e nada mais do
 * que isso.
 *
 * AS DATAS COM NOME SAEM EM DUAS LISTAS, E A LINHA QUE AS SEPARA É UMA SÓ:
 * «isto impede a aula de acontecer?». `$dayExceptions` responde que sim —
 * feriados, dias não letivos — e vai para `academic_calendar_exceptions`, ao
 * lado das interrupções. `$datedEvents` responde que não — reuniões,
 * apresentações, atividades, convívios, fins de ano de uma coorte — e vai para o
 * calendário do professor, sem apagar aula nenhuma.
 *
 * A SEGUNDA LISTA DEIXOU DE SER A EXCEÇÃO. Chamava-se `otherDatedItems` e existia
 * só para os «Fim 9.º ano»; a primeira chamava-se `holidays` e recebia TUDO o
 * resto, porque «feriado» era o que se escrevia quando não se sabia. Um
 * calendário escolar traz muito mais do que feriados, e escrever uma reunião como
 * feriado não era um rótulo infeliz — era apagar as aulas desse dia. Hoje a
 * classificação é feita e justificada (ClassifyCalendarDay) e o neutro é um
 * acontecimento, nunca uma exceção.
 *
 * `schoolName` e `academicYearLabel` são o mesmo «melhor esforço, nunca
 * bloqueia» que ParsedTimetable::academicYearLabel já é: um título que não se
 * consegue ler custa ao professor um aviso que não vê, e nunca é razão para
 * recusar um ficheiro perfeitamente legível.
 */
final readonly class ParsedAcademicCalendar
{
    /**
     * @param  list<ParsedSemester>  $semesters
     * @param  list<ParsedCalendarRange>  $schoolBreaks  interrupções letivas, lidas da tabela-resumo
     * @param  list<ParsedCalendarRange>  $dayExceptions  dias com nome em que NÃO há aula: feriados e dias não letivos
     * @param  list<ParsedCalendarMarker>  $datedEvents  datas com nome que não impedem aula nenhuma
     * @param  string|null  $academicYearLabel  como está impresso, «2026/2027»
     * @param  string|null  $academicYearNormalised  no formato desta aplicação, «2026/2027»
     */
    public function __construct(
        public array $semesters,
        public array $schoolBreaks,
        public array $dayExceptions,
        public array $datedEvents,
        public ?string $schoolName = null,
        public ?string $academicYearLabel = null,
        public ?string $academicYearNormalised = null,
    ) {}

    /**
     * O documento não deu absolutamente nada de aproveitável?
     *
     * Contam-se as QUATRO listas e não só uma: um calendário sem tabela-resumo
     * mas com uma dúzia de datas na grelha é uma importação perfeitamente
     * útil, e recusá-lo por lhe faltar a parte que faltava seria deitar fora a
     * parte que lá estava.
     */
    public function isEmpty(): bool
    {
        return $this->semesters === []
            && $this->schoolBreaks === []
            && $this->dayExceptions === []
            && $this->datedEvents === [];
    }
}
