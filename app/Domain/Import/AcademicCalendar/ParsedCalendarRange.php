<?php

namespace App\Domain\Import\AcademicCalendar;

use App\Models\AcademicCalendarExceptionType;

/**
 * Um intervalo de dias em que não há aula, lido do documento: uma interrupção
 * letiva ou um feriado.
 *
 * OS DOIS NA MESMA CLASSE, de propósito. Um feriado é `starts_on === ends_on` e
 * uma interrupção dura onze dias, mas isso é uma diferença de duração e não de
 * natureza — as duas vão para a MESMA tabela (`academic_calendar_exceptions`),
 * distinguidas pela coluna `type` que já existe para isso, exatamente como a
 * Fase 5.4 as distingue. Duas classes aqui seriam duas formas de escrever a
 * mesma linha.
 *
 * `rawText` VIAJA SEMPRE. A pré-visualização tem de poder dizer «encontrado no
 * documento: 21 a 31 dez. (Natal)» ao lado das datas que daí saíram — sem isso o
 * professor está a confirmar a interpretação do parser sem nunca ver o que ela
 * interpretou.
 */
final readonly class ParsedCalendarRange
{
    /**
     * @param  string  $startsOn  «Y-m-d»
     * @param  string  $endsOn  «Y-m-d» — igual a `$startsOn` num feriado de um dia
     * @param  string  $rawText  a célula tal como está escrita no ficheiro
     * @param  string|null  $note  o que o documento diz a mais e não cabe no título
     */
    public function __construct(
        public AcademicCalendarExceptionType $type,
        public string $title,
        public string $startsOn,
        public string $endsOn,
        public string $rawText,
        public ?string $note = null,
    ) {}
}
