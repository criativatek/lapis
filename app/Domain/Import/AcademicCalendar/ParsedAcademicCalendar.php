<?php

namespace App\Domain\Import\AcademicCalendar;

/**
 * Tudo o que a leitura conseguiu tirar de UM calendário escolar — e nada mais do
 * que isso.
 *
 * O QUE NÃO ESTÁ CÁ É TÃO DELIBERADO COMO O QUE ESTÁ. Não há aqui reuniões, nem
 * atividades, nem visitas de estudo, porque o documento de referência não tem
 * nenhuma: um parser que devolvesse listas vazias «para o caso» convidava a
 * página seguinte a inventar secções para as mostrar. Se um dia um calendário
 * real as trouxer, é aqui que aparecem — depois de alguém ter visto o ficheiro.
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
     * @param  list<ParsedCalendarRange>  $schoolBreaks  interrupções letivas
     * @param  list<ParsedCalendarRange>  $holidays  feriados com nome
     * @param  list<ParsedCalendarMarker>  $otherDatedItems  o que o documento marca e a aplicação não classifica
     * @param  string|null  $academicYearLabel  como está impresso, «2026/2027»
     * @param  string|null  $academicYearNormalised  no formato desta aplicação, «2026/2027»
     */
    public function __construct(
        public array $semesters,
        public array $schoolBreaks,
        public array $holidays,
        public array $otherDatedItems,
        public ?string $schoolName = null,
        public ?string $academicYearLabel = null,
        public ?string $academicYearNormalised = null,
    ) {}

    /**
     * O documento não deu absolutamente nada de aproveitável?
     *
     * Contam-se as QUATRO listas e não só uma: um calendário sem tabela-resumo
     * mas com uma dúzia de feriados na grelha é uma importação perfeitamente
     * útil, e recusá-lo por lhe faltar a parte que faltava seria deitar fora a
     * parte que lá estava.
     */
    public function isEmpty(): bool
    {
        return $this->semesters === []
            && $this->schoolBreaks === []
            && $this->holidays === []
            && $this->otherDatedItems === [];
    }
}
