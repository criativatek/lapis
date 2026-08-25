<?php

namespace App\Domain\Import\AcademicCalendar;

/**
 * UMA das datas de fim que o documento oferece para um período — e a razão de
 * esta classe existir de todo.
 *
 * Um calendário escolar real não termina o ano no mesmo dia para toda a gente:
 * o 9.º ano acaba a 4 de junho, o 5.º/6.º/7.º/8.º a 11 e o Pré-escolar/1.º
 * Ciclo a 30. São TRÊS datas, todas verdadeiras, e um AcademicPeriod tem UM
 * `ends_on` e nenhuma noção de coorte.
 *
 * Não há aqui heurística nenhuma que escolha por si — não existe nenhuma que
 * pudesse estar certa. O `cohort` viaja com a data precisamente para que o
 * professor possa ler «9.º ano» ao lado de «4 de junho» e decidir; quando o
 * documento dá uma só data sem coorte nenhuma (o 1.º Semestre), `cohort` é null
 * e não há escolha nenhuma a fazer.
 */
final readonly class ParsedSemesterEnd
{
    /**
     * @param  string|null  $cohort  «9.º ano», «5/6/7/8.º ano», «Pré/ 1.º Ciclo» — ou null quando o documento não distingue
     * @param  string  $endsOn  «Y-m-d»
     * @param  string  $rawText  a célula tal como está escrita no ficheiro
     */
    public function __construct(
        public ?string $cohort,
        public string $endsOn,
        public string $rawText,
    ) {}
}
