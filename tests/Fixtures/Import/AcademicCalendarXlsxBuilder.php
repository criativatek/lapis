<?php

namespace Tests\Fixtures\Import;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Constrói, do nada, um calendário escolar em .xlsx com a forma exata dos que os
 * agrupamentos publicam.
 *
 * DELIBERADAMENTE NÃO É CÓPIA DO CALENDÁRIO DE NINGUÉM. Aquele contra o qual este
 * leitor foi escrito é a proposta real de um agrupamento real — nome da escola
 * real, datas reais — e fica inteiramente fora deste repositório, exatamente como
 * o horário contra o qual TimetablePdfBuilder foi escrito. A fixture é construída
 * à medida, e construída para conter as formas DIFÍCEIS e não as arrumadas:
 *
 *   - a coluna «Fim» do 2.º Semestre com TRÊS datas, uma por coorte, que é a
 *     ambiguidade que este importador existe para não resolver sozinho;
 *   - um feriado escrito com hífen e sem espaço («30-Páscoa»), ao lado dos doze
 *     escritos com espaço;
 *   - um feriado DENTRO de uma interrupção («25 Natal»), que é uma sobreposição
 *     legítima e não um conflito;
 *   - um feriado que calha a um fim-de-semana, que a cor de fim-de-semana não
 *     pode esconder;
 *   - um dia amarelo COM nome («Carnaval») dentro de um intervalo que a
 *     tabela-resumo já descreve — não é uma proposta nova, é uma observação;
 *   - marcadores de período escritos na grelha («12 Início 1º S», «30 - Fim 1.º
 *     S»), que a tabela-resumo já diz melhor e que têm de ser ignorados;
 *   - marcadores de coorte com espaço a dobrar («11 Fim  5/6/7/8.º»), como o
 *     documento real os tem;
 *   - uma coluna decoy muito à direita que repete «1.º Semestre» e «2.º
 *     Semestre», exatamente como a de «Dias letivos/ Aulas previstas» do
 *     documento real, para provar que não abre um segundo par de blocos;
 *   - meses cujos dias caem em linhas diferentes conforme o dia da semana, que é
 *     a geometria real destas folhas e não uma grelha regular.
 *
 * O Agrupamento de Escolas de Exemplo não existe, e 2030/2031 está longe de
 * qualquer ano em que alguém trabalhe.
 */
class AcademicCalendarXlsxBuilder
{
    public const FIRST_YEAR = 2030;

    public const SECOND_YEAR = 2031;

    public const SCHOOL = 'Agrupamento de Escolas de Exemplo';

    public const FIRST_SEMESTER_STARTS_ON = '2030-09-12';

    public const FIRST_SEMESTER_ENDS_ON = '2031-01-30';

    public const SECOND_SEMESTER_STARTS_ON = '2031-02-11';

    /** As TRÊS datas de fim do 2.º Semestre, por coorte — a ambiguidade em pessoa. */
    public const SECOND_SEMESTER_ENDS_ON = [
        '9.º ano' => '2031-06-04',
        '5/6/7/8.º ano' => '2031-06-11',
        'Pré/ 1.º Ciclo' => '2031-06-30',
    ];

    /** @var list<array{0: string, 1: string, 2: string|null}>  início, fim, nome entre parêntesis */
    public const BREAKS = [
        ['2030-11-18', '2030-11-22', null],
        ['2030-12-23', '2030-12-31', 'Natal'],
        // Este atravessa dois fins-de-semana, tal como o de fevereiro do
        // documento real: o intervalo é o que a tabela diz, e não o que a cor das
        // células deixaria reconstruir.
        ['2031-02-01', '2031-02-10', null],
        // E este atravessa a mudança de mês.
        ['2031-03-25', '2031-04-02', 'Páscoa'],
    ];

    /** Como cada intervalo está escrito na coluna «Interrupções» — quatro grafias diferentes. */
    public const BREAK_TEXTS = [
        '18 a 22 novembro',
        '23 a 31 dez. (Natal)',
        '1 a 10 de fev.',
        '25 mar. a 2 abr. (Páscoa)',
    ];

    /** @var array<string, string>  data => nome, tal como fica escrito a seguir ao dia */
    public const HOLIDAYS = [
        '2030-10-05' => 'Implant. República',
        '2030-11-01' => 'Todos os Santos',
        '2030-12-01' => 'R. independência',
        '2030-12-08' => 'Imaculada C.',
        '2030-12-25' => 'Natal',
        '2031-01-01' => 'Ano Novo',
        '2031-03-28' => '6ª Feira Santa',
        '2031-03-30' => 'Páscoa',
        '2031-04-25' => 'Dia da Liberdade',
        '2031-05-01' => 'Dia Trabalhador',
        '2031-05-22' => 'Dia de Leiria',
        '2031-06-05' => 'Corpo Deus',
        '2031-06-10' => 'Portugal',
    ];

    /**
     * O único feriado escrito com hífen e sem espaço a separar o dia do nome.
     * O documento real tem exatamente um assim, e é o que quebra um leitor que
     * assuma um espaço.
     */
    public const HYPHENATED_HOLIDAY = '2031-03-30';

    /** @var array<string, string>  data => rótulo, com o espaço a dobrar do documento real */
    public const COHORT_MARKERS = [
        '2031-06-04' => 'Fim 9.º ano',
        '2031-06-11' => 'Fim  5/6/7/8.º',
        '2031-06-30' => 'Fim  Pré/1.ºC',
    ];

    /**
     * Um dia AMARELO COM NOME, dentro de um intervalo que a tabela-resumo já
     * descreve inteiro — a terça-feira de Carnaval no meio da interrupção de
     * fevereiro. Não é uma proposta nova; é o nome que o documento dá a um dia, e
     * vai como observação da interrupção que o contém.
     *
     * @var array<string, string> data => rótulo
     */
    public const NAMED_BREAK_DAYS = [
        '2031-02-04' => 'Carnaval',
    ];

    /**
     * O QUE UM CALENDÁRIO ESCOLAR TEM E NÃO É FERIADO NENHUM — e que, até esta
     * correção, era escrito como feriado só por estar escrito numa célula.
     *
     * Cada um destes está aqui por responder a uma pergunta diferente:
     *
     *   «Apresentação dos alunos» — a palavra é FRÁGIL de propósito. Tanto pode
     *     ser o primeiro dia de aulas como um sarau, e a resposta certa a isso é
     *     não adivinhar: fica «Data relevante». O que NUNCA pode ser é feriado.
     *   «Reunião de avaliação» — a palavra é segura e o tipo sai dela.
     *   «Almoço-convívio» — a palavra é segura, e é o exemplo de que um dia com
     *     nome pode ser uma atividade da escola e não um dia sem aulas.
     *   «Visita de estudo a Belém» — «visita de estudo» inteira, e nunca a
     *     palavra «visita» sozinha.
     *   «Dia não letivo (concedido)» — a escola a dizer por extenso que não há
     *     aula sem ser por ser feriado. É uma exceção letiva, mas não é Holiday.
     *   «Feriado municipal» — a palavra «feriado» escrita pela escola, numa
     *     célula SEM realce de cor nenhum: prova que a palavra do documento
     *     chega sozinha, sem depender do laranja.
     *
     * Todos caem em dias de semana, dentro de um semestre e fora de qualquer
     * interrupção — ou seja, em células que o documento pinta com o tom pálido
     * do semestre e nunca com o laranja dos feriados.
     *
     * @var array<string, string> data => rótulo, tal como fica escrito a seguir ao dia
     */
    public const SCHOOL_EVENTS = [
        '2030-09-17' => 'Apresentação dos alunos',
        '2030-10-16' => 'Reunião de avaliação',
        '2030-11-07' => 'Almoço-convívio',
        '2031-01-15' => 'Visita de estudo a Belém',
        '2031-04-15' => 'Dia não letivo (concedido)',
        '2031-05-13' => 'Feriado municipal',
    ];

    /** @var array<string, string>  marcadores de período na grelha, que têm de ser IGNORADOS */
    public const PERIOD_MARKERS = [
        '2030-09-12' => 'Início 1º S',
        '2031-01-30' => '- Fim 1.º S',
        '2031-02-11' => '- Início 2.º S',
    ];

    private const GREEN = 'C5E0B4';

    private const BLUE = 'BDD7EE';

    private const SALMON = 'F8CBAD';

    private const YELLOW = 'FFFF00';

    private const ORANGE = 'ED7D31';

    private const GREY = 'D9D9D9';

    /** @var list<string> */
    private const WEEKDAY_LABELS = ['S', 'D', '2.ª', '3.ª', '4.ª', '5.ª', '6.ª'];

    private const MONTH_NAMES = [
        9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril',
        5 => 'maio', 6 => 'junho',
    ];

    private ?string $academicYear = '2030/2031';

    private bool $withSummary = true;

    private bool $withMonths = true;

    public static function make(): self
    {
        return new self;
    }

    /** O calendário completo — dois semestres, quatro interrupções, treze feriados, três coortes. */
    public static function example(): self
    {
        return self::make();
    }

    /** Uma folha de cálculo perfeitamente válida que não é um calendário nenhum. */
    public static function withoutACalendar(): self
    {
        $builder = self::make();
        $builder->withMonths = false;
        $builder->withSummary = false;

        return $builder;
    }

    /** A grelha sem a tabela-resumo: só os feriados e os marcadores existem. */
    public function withoutSummaryTable(): self
    {
        $this->withSummary = false;

        return $this;
    }

    /** Para provar que um ficheiro de outro ano avisa e nunca bloqueia. */
    public function academicYear(?string $label): self
    {
        $this->academicYear = $label;

        return $this;
    }

    public function bytes(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        // O nome da folha do documento real está DESATUALIZADO em relação ao ano
        // que lá dentro está escrito. É um lembrete de que o ano nunca se lê daqui.
        $sheet->setTitle('Calendário_29_30');

        $sheet->setCellValue('L1', self::SCHOOL);

        if ($this->academicYear !== null) {
            $sheet->setCellValue('L2', "Calendário Escolar {$this->academicYear} - Organização Semestral");
        }

        $lastGridRow = 3;

        if ($this->withMonths) {
            $lastGridRow = $this->writeDayGrid($sheet);
        }

        if ($this->withSummary) {
            $this->writeSummary($sheet, $lastGridRow + 2);
        }

        if (! $this->withMonths && ! $this->withSummary) {
            $sheet->setCellValue('A5', 'Uma folha de cálculo qualquer');
            $sheet->setCellValue('A6', 'sem calendário nenhum lá dentro.');
        }

        $path = tempnam(sys_get_temp_dir(), 'calendario').'.xlsx';
        (new Xlsx($book))->save($path);

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * A grelha: dez mini-calendários lado a lado, quatro colunas cada, e uma
     * coluna de dias da semana à esquerda que vale para todos.
     *
     * A GEOMETRIA É A REAL e não uma grelha regular: cada dia cai na LINHA do seu
     * dia da semana, pelo que dois meses diferentes começam em linhas diferentes e
     * a mesma linha é sempre a mesma quarta-feira em todos os meses.
     *
     * @return int a última linha ocupada
     */
    private function writeDayGrid($sheet): int
    {
        $sheet->setCellValue('B3', 'Dia');
        $this->fill($sheet, 'B3', self::GREY);

        $column = 4; // D
        $lastRow = 3;

        foreach ($this->months() as [$year, $month]) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $sheet->setCellValue($letter.'3', self::MONTH_NAMES[$month]);
            $this->fill($sheet, $letter.'3', self::GREY);

            $week = 0;
            $previousOffset = null;
            $days = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

            for ($day = 1; $day <= $days; $day++) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $offset = $this->weekdayOffset($date);

                if ($previousOffset !== null && $offset < $previousOffset) {
                    $week++;
                }

                $previousOffset = $offset;
                $row = 4 + ($week * 7) + $offset;
                $lastRow = max($lastRow, $row);

                $sheet->setCellValue($letter.$row, $this->dayText($day, $date));
                $this->fill($sheet, $letter.$row, $this->fillFor($date, $offset));
            }

            // Quatro colunas por mês: três da célula unida mais a dos números de
            // semana que vive entre eles e que o leitor nunca pode confundir com um dia.
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($column + 3).'3', 'S');
            $column += 4;
        }

        for ($row = 4; $row <= $lastRow; $row++) {
            $sheet->setCellValue('B'.$row, self::WEEKDAY_LABELS[($row - 4) % 7]);
            $this->fill($sheet, 'B'.$row, self::GREY);
        }

        return $lastRow;
    }

    /**
     * O texto de uma célula do dia: o número, e o nome quando o dia tem um.
     */
    private function dayText(int $day, string $date): string
    {
        if (isset(self::PERIOD_MARKERS[$date])) {
            return $day.' '.self::PERIOD_MARKERS[$date];
        }

        if (isset(self::COHORT_MARKERS[$date])) {
            return $day.' '.self::COHORT_MARKERS[$date];
        }

        if (isset(self::HOLIDAYS[$date])) {
            return $date === self::HYPHENATED_HOLIDAY
                ? $day.'-'.self::HOLIDAYS[$date]
                : $day.' '.self::HOLIDAYS[$date];
        }

        if (isset(self::NAMED_BREAK_DAYS[$date])) {
            return $day.' '.self::NAMED_BREAK_DAYS[$date];
        }

        if (isset(self::SCHOOL_EVENTS[$date])) {
            return $day.' '.self::SCHOOL_EVENTS[$date];
        }

        return (string) $day;
    }

    /**
     * A PRECEDÊNCIA DAS CORES É A DO DOCUMENTO REAL, e importa: um feriado ao
     * sábado é laranja e não salmão (o 1 de maio, no documento real), mas um
     * sábado dentro de uma interrupção é salmão e não amarelo (o 26 de dezembro).
     * Feriado > fim-de-semana > interrupção > semestre.
     */
    private function fillFor(string $date, int $offset): ?string
    {
        if (isset(self::HOLIDAYS[$date])) {
            return self::ORANGE;
        }

        if ($offset <= 1) {
            return self::SALMON;
        }

        foreach (self::BREAKS as [$from, $to]) {
            if ($date >= $from && $date <= $to) {
                return self::YELLOW;
            }
        }

        if ($date >= self::FIRST_SEMESTER_STARTS_ON && $date <= self::FIRST_SEMESTER_ENDS_ON) {
            return self::GREEN;
        }

        if ($date >= self::SECOND_SEMESTER_STARTS_ON && $date <= '2031-06-30') {
            return self::BLUE;
        }

        return null;
    }

    private function writeSummary($sheet, int $row): void
    {
        foreach (['E' => 'Início', 'I' => 'Fim', 'N' => 'Interrupções', 'W' => 'Dias letivos/ Aulas previstas'] as $letter => $header) {
            $sheet->setCellValue($letter.$row, $header);
            $this->fill($sheet, $letter.$row, self::GREY);
        }

        $first = $row + 1;

        $sheet->setCellValue('B'.$first, '1.º Semestre');
        $sheet->setCellValue('E'.$first, $this->longDate(self::FIRST_SEMESTER_STARTS_ON));
        $sheet->setCellValue('I'.$first, $this->longDate(self::FIRST_SEMESTER_ENDS_ON));

        foreach ([self::BREAK_TEXTS[0], self::BREAK_TEXTS[1], self::BREAK_TEXTS[2]] as $index => $text) {
            $sheet->setCellValue('N'.($first + $index), $text);
        }

        // A COLUNA DECOY, longe à direita, a repetir os mesmos rótulos: no
        // documento real é a de «Dias letivos/ Aulas previstas», e um leitor que
        // procurasse rótulos de período em toda a largura da folha abriria aqui um
        // segundo par de blocos com datas nenhumas lá dentro.
        $sheet->setCellValue('W'.$first, '1.º Semestre');
        $sheet->setCellValue('W'.($first + 1), '2.º Semestre');

        $second = $first + 3;

        $sheet->setCellValue('B'.$second, '2.º Semestre');
        $sheet->setCellValue('E'.$second, $this->longDate(self::SECOND_SEMESTER_STARTS_ON));

        $offset = 0;
        foreach (self::SECOND_SEMESTER_ENDS_ON as $cohort => $date) {
            $sheet->setCellValue('I'.($second + $offset), $cohort.' - '.$this->shortDate($date));
            $offset++;
        }

        $sheet->setCellValue('N'.($second + 1), self::BREAK_TEXTS[3]);
    }

    /** «12 de setembro» — como a coluna «Início» escreve uma data. */
    private function longDate(string $date): string
    {
        $moment = new DateTimeImmutable($date);

        return (int) $moment->format('j').' de '.self::MONTH_NAMES[(int) $moment->format('n')];
    }

    /** «4 junho» — como a coluna «Fim» a escreve, sem o «de». */
    private function shortDate(string $date): string
    {
        $moment = new DateTimeImmutable($date);

        return (int) $moment->format('j').' '.self::MONTH_NAMES[(int) $moment->format('n')];
    }

    /** Sábado = 0, domingo = 1, segunda = 2 … sexta = 6, que é a ordem das linhas. */
    private function weekdayOffset(string $date): int
    {
        return ((int) (new DateTimeImmutable($date))->format('N') + 1) % 7;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function months(): array
    {
        $months = [];

        foreach ([9, 10, 11, 12] as $month) {
            $months[] = [self::FIRST_YEAR, $month];
        }

        foreach ([1, 2, 3, 4, 5, 6] as $month) {
            $months[] = [self::SECOND_YEAR, $month];
        }

        return $months;
    }

    private function fill($sheet, string $coordinate, ?string $rgb): void
    {
        if ($rgb === null) {
            return;
        }

        $sheet->getStyle($coordinate)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->setStartColor(new Color('FF'.$rgb));
    }
}
