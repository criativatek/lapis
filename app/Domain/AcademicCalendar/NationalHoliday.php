<?php

namespace App\Domain\AcademicCalendar;

/**
 * Um feriado NACIONAL — uma data e o nome por que é conhecida, e mais nada.
 *
 * Não é uma exceção letiva e nunca vem de uma tabela: é o que um
 * NationalHolidayProvider sabe de cor sobre um país. Só se torna uma linha em
 * `academic_calendar_exceptions` quando o professor a confirma, e é aí — e só aí
 * — que ganha ulid, proveniência e um ano letivo a que pertencer.
 */
final readonly class NationalHoliday
{
    /**
     * @param  string  $date  «Y-m-d»
     */
    public function __construct(
        public string $date,
        public string $title,
    ) {}
}
