<?php

namespace App\Services\AcademicCalendar\Holidays;

use App\Domain\AcademicCalendar\NationalHoliday;

/**
 * Os TREZE feriados nacionais portugueses: dez em data fixa, três presos à Páscoa.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OS DEZ FIXOS
 *   1 jan  Ano Novo                   10 jun  Dia de Portugal
 *  25 abr  Dia da Liberdade           15 ago  Assunção de Nossa Senhora
 *   1 mai  Dia do Trabalhador          5 out  Implantação da República
 *   1 nov  Dia de Todos os Santos      1 dez  Restauração da Independência
 *   8 dez  Imaculada Conceição        25 dez  Natal
 *
 * OS TRÊS MÓVEIS, todos contados a partir do Domingo de Páscoa
 *   Sexta-Feira Santa   Páscoa − 2 dias
 *   Domingo de Páscoa   a própria
 *   Corpo de Deus       Páscoa + 60 dias
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O QUE NÃO ESTÁ AQUI, E NÃO É ESQUECIMENTO:
 *
 *   CARNAVAL não é feriado nacional. É tolerância de ponto, concedida (ou não)
 *   por despacho, ano a ano — e as escolas fecham-no ou não conforme o calendário
 *   que publicam. Propô-lo como facto legal seria dizer uma coisa falsa com toda a
 *   confiança do mundo.
 *
 *   FERIADOS MUNICIPAIS (o «Dia de Leiria» que o calendário real de referência tem)
 *   dependem do concelho da escola, que esta aplicação não conhece — e não vai
 *   passar a conhecer para poder adivinhar aqui.
 *
 *   FERIADOS REGIONAIS dos Açores e da Madeira dependem da região, pela mesma
 *   razão e com a mesma resposta.
 *
 *   DIAS ESCOLARES (interrupções letivas, dias não letivos) não são feriados de
 *   todo: são decisões da escola e chegam pela importação do calendário dela.
 *
 * Todos estes têm um caminho: a criação manual e a importação do .xlsx da escola.
 * Nenhum tem ESTE caminho.
 */
class PortugalNationalHolidayProvider implements NationalHolidayProvider
{
    /**
     * @var array<string, string> «mm-dd» => designação
     */
    private const FIXED = [
        '01-01' => 'Ano Novo',
        '04-25' => 'Dia da Liberdade',
        '05-01' => 'Dia do Trabalhador',
        '06-10' => 'Dia de Portugal',
        '08-15' => 'Assunção de Nossa Senhora',
        '10-05' => 'Implantação da República',
        '11-01' => 'Dia de Todos os Santos',
        '12-01' => 'Restauração da Independência',
        '12-08' => 'Imaculada Conceição',
        '12-25' => 'Natal',
    ];

    public function between(string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }

        $holidays = [];

        // OS DOIS ANOS CIVIS, e não um. Um ano letivo atravessa a passagem de ano:
        // pedir só os feriados de 2026 a um calendário de 2026/2027 perdia o Natal
        // ou perdia a Páscoa, conforme a metade que se escolhesse.
        for ($year = (int) substr($from, 0, 4); $year <= (int) substr($to, 0, 4); $year++) {
            foreach ($this->forCalendarYear($year) as $holiday) {
                if ($holiday->date >= $from && $holiday->date <= $to) {
                    $holidays[] = $holiday;
                }
            }
        }

        usort($holidays, fn (NationalHoliday $a, NationalHoliday $b): int => $a->date <=> $b->date);

        return $holidays;
    }

    /**
     * Os treze de um ano civil, sem filtro nenhum.
     *
     * @return list<NationalHoliday>
     */
    public function forCalendarYear(int $year): array
    {
        $holidays = [];

        foreach (self::FIXED as $monthDay => $title) {
            $holidays[] = new NationalHoliday(sprintf('%04d-%s', $year, $monthDay), $title);
        }

        $easter = self::easterSunday($year);

        $holidays[] = new NationalHoliday(self::shift($easter, -2), 'Sexta-Feira Santa');
        $holidays[] = new NationalHoliday($easter, 'Domingo de Páscoa');
        $holidays[] = new NationalHoliday(self::shift($easter, 60), 'Corpo de Deus');

        usort($holidays, fn (NationalHoliday $a, NationalHoliday $b): int => $a->date <=> $b->date);

        return $holidays;
    }

    /**
     * O DOMINGO DE PÁSCOA de um ano do calendário gregoriano, «Y-m-d».
     *
     * ESCRITO À MÃO, E NÃO `easter_date()`. A função do PHP existe, mas vive na
     * extensão `calendar`, que não está garantida em lado nenhum onde isto corra —
     * e uma aplicação que não soubesse dizer quando é a Páscoa por causa de um
     * módulo em falta seria uma aplicação avariada por uma razão que ninguém ia
     * adivinhar. Isto são vinte linhas de aritmética inteira sem dependência
     * nenhuma, e é uma função pura: dá-se-lhe um ano, devolve uma data, e é
     * verificável contra qualquer efeméride publicada.
     *
     * O algoritmo é o Gregoriano Anónimo (Meeus/Jones/Butcher), o mesmo que as
     * efemérides usam. Verificado nos testes contra 2026 (5 de abril), 2027 (28 de
     * março — a data que o calendário escolar real de referência também traz), 2025
     * (20 de abril) e 2031 (13 de abril).
     */
    public static function easterSunday(int $year): string
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);

        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * `strtotime` e não aritmética de dias própria: somar sessenta dias a 28 de
     * março atravessa dois meses de comprimentos diferentes, e escrever esse
     * calendário à mão era escrever um segundo calendário para poder discordar do
     * primeiro. Não há fuso horário envolvido — são datas civis puras.
     */
    private static function shift(string $date, int $days): string
    {
        return date('Y-m-d', (int) strtotime("{$date} {$days} days"));
    }
}
