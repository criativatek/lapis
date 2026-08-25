<?php

namespace App\Services\AcademicCalendar\Holidays;

use App\Domain\AcademicCalendar\NationalHoliday;

/**
 * Os feriados NACIONAIS de um país, dentro de um intervalo de datas.
 *
 * UM INTERVALO E NÃO UM ANO CIVIL, porque um ano letivo atravessa dois: 2026/2027
 * vai de setembro de 2026 a agosto de 2027, e nenhum dos dois conjuntos anuais
 * sozinho responde à pergunta. Quem implementa isto calcula os anos civis que o
 * intervalo toca e devolve só o que lá cai dentro.
 *
 * NACIONAIS, E SÓ NACIONAIS. Feriados municipais, feriados regionais, Carnaval e
 * tolerâncias de ponto não têm estatuto legal nacional — variam com o concelho, com
 * a região autónoma ou com um despacho do ano — e por isso NUNCA saem daqui. Uma
 * lista de feriados oficiais que trouxesse o feriado de Leiria a um professor do
 * Porto seria pior do que não existir. Essas datas entram pela mão do professor ou
 * pelo calendário que a escola publica, que são os dois sítios que as sabem.
 *
 * NENHUM PEDIDO À REDE. Um provider sabe de cor: são leis, não são dados. Uma
 * aplicação que precisasse de internet para saber que o Natal é a 25 de dezembro
 * seria uma aplicação que deixava de funcionar por uma razão absurda.
 */
interface NationalHolidayProvider
{
    /**
     * Os feriados cuja data cai em `[$from, $to]`, por ordem cronológica.
     *
     * @param  string  $from  «Y-m-d», inclusive
     * @param  string  $to  «Y-m-d», inclusive
     * @return list<NationalHoliday>
     */
    public function between(string $from, string $to): array;
}
