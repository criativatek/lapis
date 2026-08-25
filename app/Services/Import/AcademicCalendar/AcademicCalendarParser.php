<?php

namespace App\Services\Import\AcademicCalendar;

use App\Domain\Import\AcademicCalendar\ParsedAcademicCalendar;
use App\Domain\Import\AcademicCalendar\ParsedCalendarMarker;
use App\Domain\Import\AcademicCalendar\ParsedCalendarRange;
use App\Domain\Import\AcademicCalendar\ParsedSemester;
use App\Domain\Import\AcademicCalendar\ParsedSemesterEnd;
use App\Models\AcademicCalendarExceptionType;
use App\Support\Import\SpreadsheetZipSafety;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Ler o calendário escolar que a escola publica — a folha com dez mini-calendários
 * lado a lado e uma tabela-resumo por baixo.
 *
 * ESCRITO CONTRA UM DOCUMENTO REAL, e é isso que o torna útil e o que lhe marca
 * os limites. Cada regra aqui saiu de olhar para um calendário mesmo publicado
 * por um agrupamento, célula a célula e cor a cor; nenhuma saiu de imaginar como
 * um calendário «deve» ser. Um formato diferente não é um erro deste parser — é
 * um documento que ainda ninguém viu, e a resposta certa é dizê-lo em vez de
 * adivinhar.
 *
 * NADA AQUI É PROCURADO POR COORDENADA FIXA. A linha dos meses, a tabela-resumo e
 * as suas colunas são todas localizadas pelo CONTEÚDO — a mesma disciplina que
 * RosterFileParser::locateHeaderColumns() já usa —, porque a exportação do ano
 * seguinte vai ter mais uma linha algures e um «B43» escrito à mão passaria a
 * apontar para o sítio errado sem nunca dar erro.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O QUE SE LÊ ONDE, E PORQUÊ
 *
 *   - OS PERÍODOS E AS INTERRUPÇÕES vêm da TABELA-RESUMO e não da grelha. A
 *     tabela di-lo por extenso («11 de setembro», «21 a 31 dez. (Natal)»); a
 *     grelha di-lo pintando dias, e reconstruir um intervalo a partir de células
 *     coloridas é adivinhar onde ele começa sempre que um feriado ou um
 *     fim-de-semana lhe corta o meio ao meio. A grelha serve de confirmação, não
 *     de fonte.
 *
 *   - OS FERIADOS COM NOME vêm da GRELHA e não da tabela, pela razão inversa: a
 *     tabela-resumo não os menciona de todo. Só existem escritos dentro do dia.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * O RÓTULO DECIDE, A COR SÓ DESEMPATA
 *
 * Uma célula da grelha é «5 Implant. República» ou é «5». O que a torna
 * interessante é ter texto para além do número — e é o TEXTO que diz o que ela é:
 * «Início ...»/«Fim ... S» é um marcador de período (ignorado, porque a tabela-
 * resumo já o disse melhor), «Fim <coorte>» é um fim de ano de uma turma, e tudo
 * o resto é um dia com nome.
 *
 * A COR ENTRA UMA VEZ SÓ, para separar dois dias com nome que são coisas
 * diferentes: o amarelo marca um dia DENTRO de uma interrupção que a tabela-resumo
 * já vai propor inteira (é uma nota, não uma proposta nova), e qualquer outra cor
 * marca um feriado. Verificado sobre o documento real: 13 células laranja, todas
 * com nome, todas feriados; 27 amarelas, uma com nome («Carnaval»), toda dentro do
 * intervalo de fevereiro que a tabela já descreve. Sem cor nenhuma, um dia com
 * nome é um feriado — que é o caso comum e o palpite seguro.
 *
 * A cor é lida por MATIZ E SATURAÇÃO e não por uma lista de hexadecimais: o mesmo
 * amarelo sai de um tema diferente com dois dígitos trocados, e uma lista exata
 * falharia silenciosamente. #ED7D31 (laranja) e #F8CBAD (salmão do fim-de-semana)
 * têm quase o mesmo matiz e saturações de 0,79 e 0,30 — é a saturação que os
 * separa, e é por isso que ela conta.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OS FINS-DE-SEMANA NÃO SÃO EXCEÇÕES E NÃO SÃO LIDOS. Um sábado não é uma
 * decisão sobre a forma deste ano — é aritmética que o calendário já sabe fazer.
 * Propô-los seria encher a pré-visualização com oitenta linhas que não dizem nada
 * e afogar as quinze que dizem.
 */
class AcademicCalendarParser
{
    /** Linhas e colunas varridas à procura de um cabeçalho. Generoso, e ainda assim finito. */
    private const MAX_SCAN_ROWS = 200;

    private const MAX_SCAN_COLUMNS = 80;

    /** Quantas linhas abaixo do cabeçalho dos meses a grelha dos dias pode ocupar (37 no documento real). */
    private const DAY_GRID_ROWS = 60;

    /** Quantas linhas abaixo do seu cabeçalho a tabela-resumo pode ocupar (6 no documento real). */
    private const SUMMARY_ROWS = 40;

    /** Linhas seguidas inteiramente vazias que terminam a tabela-resumo. */
    private const SUMMARY_BLANK_RUN = 3;

    /**
     * Os meses, dobrados sem acentos, para reconhecer «março» tanto como «mar.».
     *
     * O emparelhamento é por PREFIXO ÚNICO e não pelas três primeiras letras: «mai»
     * é prefixo de «maio» e de mais nada, «mar» de «marco» e de mais nada, e «ano»
     * — que aparece em «9.º ano» — não é prefixo de mês nenhum e é por isso
     * recusado sem ter de se saber de antemão que era uma coorte e não um mês.
     *
     * @var array<int, string>
     */
    private const MONTHS = [
        1 => 'janeiro',
        2 => 'fevereiro',
        3 => 'marco',
        4 => 'abril',
        5 => 'maio',
        6 => 'junho',
        7 => 'julho',
        8 => 'agosto',
        9 => 'setembro',
        10 => 'outubro',
        11 => 'novembro',
        12 => 'dezembro',
    ];

    /** Os mesmos meses como se escrevem, para os títulos que este parser deriva. */
    private const MONTH_NAMES = [
        1 => 'janeiro',
        2 => 'fevereiro',
        3 => 'março',
        4 => 'abril',
        5 => 'maio',
        6 => 'junho',
        7 => 'julho',
        8 => 'agosto',
        9 => 'setembro',
        10 => 'outubro',
        11 => 'novembro',
        12 => 'dezembro',
    ];

    public function __construct(private readonly SpreadsheetZipSafety $zipSafety) {}

    /**
     * @param  string  $absolutePath  o caminho real do ficheiro carregado, nunca um nome vindo do cliente
     * @param  int|null  $fallbackFirstYear  o ano civil em que o ano letivo escolhido começa, usado
     *                                       apenas quando o próprio documento não o diz no título
     *
     * @throws AcademicCalendarFileException
     */
    public function parse(string $absolutePath, ?int $fallbackFirstYear = null): ParsedAcademicCalendar
    {
        // ANTES DE O PhpSpreadsheet VER O FICHEIRO, e nunca depois: um .xlsx é um
        // zip, e quando a biblioteca já o abriu a memória já foi gasta. É a mesma
        // classe — e não uma segunda cópia das mesmas verificações — que os
        // leitores de grelhas de correção já usam (§27).
        if (! $this->zipSafety->isSafe($absolutePath)) {
            throw AcademicCalendarFileException::unsafePackage();
        }

        try {
            $book = IOFactory::load($absolutePath);
        } catch (Throwable) {
            // Throwable e não a exceção do PhpSpreadsheet: um .xlsx corrompido pode
            // sair daqui como qualquer coisa — um TypeError vindo de um XML
            // truncado, um erro de leitor. Todos significam a mesma coisa para o
            // professor, e todos têm de sair como a mesma frase.
            throw AcademicCalendarFileException::unreadable();
        }

        foreach ($book->getAllSheets() as $sheet) {
            $months = $this->locateMonthColumns($sheet);

            if ($months !== null) {
                return $this->read($sheet, $months, $fallbackFirstYear);
            }
        }

        throw AcademicCalendarFileException::notACalendar();
    }

    /**
     * @param  array{row: int, columns: array<string, int>}  $months
     *
     * @throws AcademicCalendarFileException
     */
    private function read(Worksheet $sheet, array $months, ?int $fallbackFirstYear): ParsedAcademicCalendar
    {
        $title = $this->titleFacts($sheet, $months['row']);
        $firstYear = $title['first_year'] ?? $fallbackFirstYear;

        if ($firstYear === null) {
            throw AcademicCalendarFileException::unknownAcademicYear();
        }

        $monthYears = $this->monthYears($months['columns'], $firstYear);
        $summary = $this->locateSummary($sheet);

        // A grelha dos dias pára onde a tabela-resumo começa. Sem este limite, uma
        // linha da tabela que por acaso caia debaixo de uma coluna de mês seria
        // lida como se fosse um dia.
        $gridEndsBefore = $summary === null
            ? $months['row'] + self::DAY_GRID_ROWS
            : min($months['row'] + self::DAY_GRID_ROWS, $summary['row'] - 1);

        $grid = $this->readDayGrid($sheet, $months, $monthYears, $gridEndsBefore);

        $semesters = [];
        $schoolBreaks = [];

        if ($summary !== null) {
            $blocks = $this->readSummary($sheet, $summary);
            $semesters = $this->semestersFrom($blocks, $monthYears);
            $schoolBreaks = $this->breaksFrom($blocks, $monthYears, $grid['break_day_labels']);
        }

        return new ParsedAcademicCalendar(
            semesters: $semesters,
            schoolBreaks: $schoolBreaks,
            holidays: $grid['holidays'],
            otherDatedItems: $grid['markers'],
            schoolName: $title['school'],
            academicYearLabel: $title['label'],
            academicYearNormalised: $title['normalised'],
        );
    }

    // ───────────────────────────────────────────────────────── a linha dos meses

    /**
     * A linha que põe «setembro outubro novembro …» lado a lado, e a coluna em que
     * cada mês começa.
     *
     * TRÊS MESES É O MÍNIMO, de propósito: uma folha qualquer pode ter a palavra
     * «março» escrita numa célula por mil razões, mas três nomes de mês distintos
     * na mesma linha é uma grelha de calendário e não uma coincidência.
     *
     * @return array{row: int, columns: array<string, int>}|null colunas por ordem da esquerda para a direita
     */
    private function locateMonthColumns(Worksheet $sheet): ?array
    {
        $lastRow = min($sheet->getHighestRow(), self::MAX_SCAN_ROWS);
        $lastColumn = min(
            Coordinate::columnIndexFromString($sheet->getHighestColumn()),
            self::MAX_SCAN_COLUMNS,
        );

        for ($row = 1; $row <= $lastRow; $row++) {
            $columns = [];

            for ($index = 1; $index <= $lastColumn; $index++) {
                $letter = Coordinate::stringFromColumnIndex($index);
                $month = $this->monthFromText($this->cell($sheet, $letter.$row));

                if ($month !== null) {
                    $columns[$letter] = $month;
                }
            }

            if (count(array_unique($columns)) >= 3) {
                return ['row' => $row, 'columns' => $columns];
            }
        }

        return null;
    }

    /**
     * A que ANO CIVIL pertence cada mês da grelha.
     *
     * DERIVADO DA PRÓPRIA ORDEM DOS MESES, e nunca da regra «setembro a dezembro é
     * o primeiro ano»: os meses estão escritos por ordem cronológica do ano letivo,
     * pelo que o ponto em que o número do mês DESCE (dezembro → janeiro) é o ponto
     * em que o ano civil sobe. Um ano letivo que comece noutro mês continua a ser
     * lido corretamente, e nenhum «2026» fica escrito neste ficheiro.
     *
     * @param  array<string, int>  $columns
     * @return array<int, int> número do mês => ano civil
     */
    private function monthYears(array $columns, int $firstYear): array
    {
        $years = [];
        $offset = 0;
        $previous = null;

        foreach ($columns as $month) {
            if ($previous !== null && $month < $previous) {
                $offset = 1;
            }

            $previous = $month;
            $years[$month] ??= $firstYear + $offset;
        }

        // Um mês que a grelha não mostra — julho e agosto, no documento real —
        // ainda pode aparecer escrito na tabela-resumo. Cai do lado certo pela
        // mesma regra: antes do primeiro mês do ano letivo é já o ano seguinte.
        $firstMonth = $columns === [] ? 1 : (int) reset($columns);

        for ($month = 1; $month <= 12; $month++) {
            $years[$month] ??= $month >= $firstMonth ? $firstYear : $firstYear + 1;
        }

        return $years;
    }

    // ─────────────────────────────────────────────────────────────── o cabeçalho

    /**
     * O nome da escola e o ano letivo, lidos do que estiver escrito por cima da
     * grelha.
     *
     * MELHOR ESFORÇO E NUNCA UM BLOQUEIO — a mesma disciplina que
     * ParsedTimetable::academicYearLabel já tem. Um título que não se consegue ler
     * custa ao professor um aviso que não vê; recusar por causa dele um calendário
     * cujas datas estão todas lá seria trocar uma importação inteira por um
     * detalhe decorativo.
     *
     * @return array{school: string|null, label: string|null, normalised: string|null, first_year: int|null}
     */
    private function titleFacts(Worksheet $sheet, int $headerRow): array
    {
        $school = null;
        $label = null;
        $normalised = null;
        $firstYear = null;

        $lastColumn = min(
            Coordinate::columnIndexFromString($sheet->getHighestColumn()),
            self::MAX_SCAN_COLUMNS,
        );

        for ($row = 1; $row < $headerRow; $row++) {
            for ($index = 1; $index <= $lastColumn; $index++) {
                $value = $this->cell($sheet, Coordinate::stringFromColumnIndex($index).$row);

                if ($value === '') {
                    continue;
                }

                if ($label === null && preg_match('/(20\d{2})\s*[\/\x{2013}\x{2014}-]\s*(\d{2,4})/u', $value, $match) === 1) {
                    $first = (int) $match[1];
                    $second = strlen($match[2]) === 2
                        ? intdiv($first, 100) * 100 + (int) $match[2]
                        : (int) $match[2];

                    $label = $match[0];
                    $normalised = $first.'/'.$second;
                    $firstYear = $first;

                    continue;
                }

                // O nome da escola é a primeira linha de texto que NÃO traz um ano
                // lá dentro — no documento real, «Agrupamento de Escolas …» por
                // cima de «Calendário Escolar 2026/2027 …».
                if ($school === null && mb_strlen($value) >= 5 && preg_match('/\d{4}/', $value) !== 1) {
                    $school = $value;
                }
            }
        }

        return ['school' => $school, 'label' => $label, 'normalised' => $normalised, 'first_year' => $firstYear];
    }

    // ──────────────────────────────────────────────────────────── a grelha dos dias

    /**
     * Os dias com nome: os feriados, os fins de ano de cada coorte, e os nomes que
     * o documento dá a dias que já estão dentro de uma interrupção.
     *
     * SÓ A COLUNA-ÂNCORA DE CADA MÊS é lida. Cada mini-calendário ocupa três
     * colunas unidas e só a primeira tem o valor — as outras duas estão vazias por
     * fazerem parte da mesma célula. Lendo apenas a âncora, as colunas de números
     * de semana que vivem entre os meses («S», 1, 2, 3 …) nunca chegam sequer a
     * ser consideradas: não são âncora de mês nenhum.
     *
     * @param  array{row: int, columns: array<string, int>}  $months
     * @param  array<int, int>  $monthYears
     * @return array{holidays: list<ParsedCalendarRange>, markers: list<ParsedCalendarMarker>, break_day_labels: array<string, string>}
     */
    private function readDayGrid(Worksheet $sheet, array $months, array $monthYears, int $lastRow): array
    {
        $holidays = [];
        $markers = [];
        $breakDayLabels = [];

        foreach ($months['columns'] as $letter => $month) {
            for ($row = $months['row'] + 1; $row <= $lastRow; $row++) {
                $coordinate = $letter.$row;
                $value = $this->cell($sheet, $coordinate);

                if ($value === '' || str_starts_with($value, '=')) {
                    continue;
                }

                // `(?![\d])` — sem isto, «2026» numa célula solta seria lido como o
                // dia 20 de qualquer coisa.
                if (preg_match('/^(\d{1,2})(?![\d])\s*(.*)$/su', $value, $match) !== 1) {
                    continue;
                }

                $day = (int) $match[1];
                $year = $monthYears[$month] ?? null;

                if ($year === null || ! checkdate($month, $day, $year)) {
                    continue;
                }

                $label = $this->cleanLabel($match[2]);

                if ($label === '') {
                    continue;
                }

                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

                // «Início 1º S», «Fim 1.º S», «Início 2.º S»: o documento repete na
                // grelha o que a tabela-resumo já diz por extenso, e a tabela di-lo
                // melhor — traz o par início/fim junto e, no 2.º Semestre, as três
                // datas em disputa. Lê-lo duas vezes era propor a mesma coisa duas
                // vezes, uma delas sem a ambiguidade à vista.
                if ($this->isPeriodMarker($label)) {
                    continue;
                }

                if ($this->isCohortMarker($label)) {
                    // O TÍTULO É NORMALIZADO AQUI E EM MAIS SÍTIO NENHUM. «Fim
                    // 5/6/7/8.º» é uma abreviatura escrita para caber num
                    // quadradinho, e daqui para a frente ela deixa de estar num
                    // quadradinho: vai ser o título de um acontecimento do
                    // calendário do professor, lido meses depois e fora da
                    // coluna de junho que lhe dava o contexto. Escrevê-la por
                    // extenso na pré-visualização deixaria a linha gravada por
                    // abreviar; escrevê-la aqui arruma as duas de uma vez.
                    $markers[] = new ParsedCalendarMarker(
                        CohortMarkerTitle::normalise($label),
                        $date,
                        $value,
                    );

                    continue;
                }

                if ($this->isSchoolBreakFill($this->fillOf($sheet, $coordinate))) {
                    // Dentro de uma interrupção que a tabela-resumo já propõe
                    // inteira. Não é uma proposta nova — é o nome que o documento
                    // lhe dá, e vai como observação da interrupção que o contém.
                    $breakDayLabels[$date] = $label;

                    continue;
                }

                $holidays[] = new ParsedCalendarRange(
                    type: AcademicCalendarExceptionType::Holiday,
                    title: $label,
                    startsOn: $date,
                    endsOn: $date,
                    rawText: $value,
                );
            }
        }

        usort($holidays, fn (ParsedCalendarRange $a, ParsedCalendarRange $b): int => $a->startsOn <=> $b->startsOn);
        usort($markers, fn (ParsedCalendarMarker $a, ParsedCalendarMarker $b): int => $a->date <=> $b->date);

        return ['holidays' => $holidays, 'markers' => $markers, 'break_day_labels' => $breakDayLabels];
    }

    /**
     * «Início 1º S», «29 - Fim 1.º S», «Início 2.º S» — um marcador do próprio
     * período, que a tabela-resumo já descreve.
     *
     * O que o distingue de «Fim 9.º ano» é referir um PERÍODO e não uma coorte: a
     * palavra «semestre», ou a sua abreviatura «1.º S» — um algarismo seguido de um
     * S isolado. «9.º ano» não tem S nenhum a seguir ao algarismo, e é por isso que
     * passa por aqui sem ser apanhado.
     */
    private function isPeriodMarker(string $label): bool
    {
        if (preg_match('/^in[íi]cio\b/iu', $label) === 1) {
            return true;
        }

        return preg_match('/(semestre|per[íi]odo|trimestre)/iu', $label) === 1
            || preg_match('/\d\s*\.?\s*[ºo]?\s*S\b/u', $label) === 1;
    }

    /**
     * «Fim 9.º ano», «Fim 5/6/7/8.º», «Fim Pré/1.ºC» — o fim do ano letivo de uma
     * coorte, que esta aplicação não modela e por isso não classifica.
     */
    private function isCohortMarker(string $label): bool
    {
        return preg_match('/^fim\b/iu', $label) === 1;
    }

    // ────────────────────────────────────────────────────────── a tabela-resumo

    /**
     * A linha que traz «Início», «Fim» e «Interrupções», e a coluna de cada uma.
     *
     * @return array{row: int, label_until: int, starts: string, ends: string, breaks: string}|null
     */
    private function locateSummary(Worksheet $sheet): ?array
    {
        $lastRow = min($sheet->getHighestRow(), self::MAX_SCAN_ROWS);
        $lastColumn = min(
            Coordinate::columnIndexFromString($sheet->getHighestColumn()),
            self::MAX_SCAN_COLUMNS,
        );

        for ($row = 1; $row <= $lastRow; $row++) {
            $starts = null;
            $ends = null;
            $breaks = null;

            for ($index = 1; $index <= $lastColumn; $index++) {
                $letter = Coordinate::stringFromColumnIndex($index);
                $folded = $this->fold($this->cell($sheet, $letter.$row));

                if ($starts === null && $folded === 'inicio') {
                    $starts = $letter;
                } elseif ($ends === null && $folded === 'fim') {
                    $ends = $letter;
                } elseif ($breaks === null && str_starts_with($folded, 'interrup')) {
                    $breaks = $letter;
                }
            }

            if ($starts !== null && $ends !== null && $breaks !== null) {
                return [
                    'row' => $row,
                    // O rótulo do período vive à ESQUERDA de «Início». É este
                    // limite que impede a coluna «Dias letivos/ Aulas previstas»,
                    // que repete «1.º Semestre» e «2.º Semestre» muito mais à
                    // direita, de abrir um segundo par de blocos fantasma.
                    'label_until' => Coordinate::columnIndexFromString($starts) - 1,
                    'starts' => $starts,
                    'ends' => $ends,
                    'breaks' => $breaks,
                ];
            }
        }

        return null;
    }

    /**
     * A tabela-resumo em blocos: um por período, cada um com o que quer que esteja
     * escrito nas suas três colunas.
     *
     * UM BLOCO É MAIS DO QUE UMA LINHA, e é aí que está o trabalho. O 2.º Semestre
     * ocupa três linhas — uma por coorte — e a coluna «Fim» tem uma data em cada
     * uma; a coluna «Interrupções» do 1.º Semestre tem três entradas empilhadas.
     * Ler linha a linha perdia as segundas e as terceiras sem dar erro nenhum.
     *
     * @param  array{row: int, label_until: int, starts: string, ends: string, breaks: string}  $summary
     * @return list<array{label: string, starts: list<string>, ends: list<string>, breaks: list<string>}>
     */
    private function readSummary(Worksheet $sheet, array $summary): array
    {
        $blocks = [];
        $current = null;
        $blank = 0;

        for ($row = $summary['row'] + 1; $row <= $summary['row'] + self::SUMMARY_ROWS; $row++) {
            $starts = $this->cell($sheet, $summary['starts'].$row);
            $ends = $this->cell($sheet, $summary['ends'].$row);
            $breaks = $this->cell($sheet, $summary['breaks'].$row);
            $label = $this->periodLabelIn($sheet, $row, $summary['label_until']);

            if ($label === null && $starts === '' && $ends === '' && $breaks === '') {
                if (++$blank >= self::SUMMARY_BLANK_RUN) {
                    break;
                }

                continue;
            }

            $blank = 0;

            if ($label !== null) {
                if ($current !== null) {
                    $blocks[] = $current;
                }

                $current = ['label' => $label, 'starts' => [], 'ends' => [], 'breaks' => []];
            }

            if ($current === null) {
                // Linhas antes do primeiro rótulo — uma tabela que começasse por
                // uma linha de notas. Nada a que as agarrar, e por isso ignoradas.
                continue;
            }

            foreach (['starts' => $starts, 'ends' => $ends, 'breaks' => $breaks] as $key => $value) {
                if ($value !== '') {
                    $current[$key][] = $value;
                }
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * O rótulo de período desta linha, se ela abrir um bloco novo.
     */
    private function periodLabelIn(Worksheet $sheet, int $row, int $lastColumn): ?string
    {
        for ($index = 1; $index <= $lastColumn; $index++) {
            $value = $this->cell($sheet, Coordinate::stringFromColumnIndex($index).$row);

            if ($value !== '' && preg_match('/(semestre|per[íi]odo|trimestre|m[óo]dulo)/iu', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<array{label: string, starts: list<string>, ends: list<string>, breaks: list<string>}>  $blocks
     * @param  array<int, int>  $monthYears
     * @return list<ParsedSemester>
     */
    private function semestersFrom(array $blocks, array $monthYears): array
    {
        $semesters = [];
        $sequence = 0;

        foreach ($blocks as $block) {
            $sequence++;

            $startsOn = null;
            $rawStart = $block['starts'][0] ?? '';

            foreach ($block['starts'] as $text) {
                $start = $this->dateIn($text, $monthYears);

                if ($start !== null) {
                    $startsOn = $start['date'];
                    $rawStart = $text;

                    break;
                }
            }

            // CADA CÉLULA DA COLUNA «FIM» É UM CANDIDATO, e nunca se escolhe um.
            // Uma só data e não há ambiguidade nenhuma; três e o período tem três
            // fins verdadeiros e um campo para os guardar — o que é uma pergunta
            // para o professor e não um problema para este parser resolver.
            $ends = [];

            foreach ($block['ends'] as $text) {
                $end = $this->dateIn($text, $monthYears);

                if ($end !== null) {
                    $ends[] = new ParsedSemesterEnd($end['prefix'], $end['date'], $text);
                }
            }

            $semesters[] = new ParsedSemester(
                label: $block['label'],
                startsOn: $startsOn,
                endCandidates: $ends,
                rawStart: $rawStart,
                sequence: $sequence,
            );
        }

        return $semesters;
    }

    /**
     * @param  list<array{label: string, starts: list<string>, ends: list<string>, breaks: list<string>}>  $blocks
     * @param  array<int, int>  $monthYears
     * @param  array<string, string>  $breakDayLabels
     * @return list<ParsedCalendarRange>
     */
    private function breaksFrom(array $blocks, array $monthYears, array $breakDayLabels): array
    {
        $breaks = [];
        $seen = [];

        foreach ($blocks as $block) {
            foreach ($block['breaks'] as $text) {
                $range = $this->parseBreak($text, $monthYears, $breakDayLabels);

                if ($range === null) {
                    continue;
                }

                // UMA INTERRUPÇÃO PERTENCE AO ANO E NÃO AO PERÍODO em cuja linha
                // está escrita — a de «1 a 10 de fev.» está na caixa do 1.º
                // Semestre e cai depois de ele acabar. Juntam-se todas numa lista
                // só, e um intervalo repetido nas duas caixas escreve-se uma vez.
                $key = $range->startsOn.'|'.$range->endsOn;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $breaks[] = $range;
            }
        }

        usort($breaks, fn (ParsedCalendarRange $a, ParsedCalendarRange $b): int => $a->startsOn <=> $b->startsOn);

        return $breaks;
    }

    /**
     * «16 a 20 novembro», «21 a 31 dez. (Natal)», «1 a 10 de fev.», «25 mar. a 2
     * abr. (Páscoa)» — quatro maneiras de escrever a mesma coisa, todas no mesmo
     * documento.
     *
     * O MÊS DO PRIMEIRO DIA É OPCIONAL porque o documento o omite quando é o mesmo
     * do último («16 a 20 novembro»). O do último nunca é omitido, e é por isso
     * que é ele o obrigatório e ele que serve de omissão ao outro.
     *
     * @param  array<int, int>  $monthYears
     * @param  array<string, string>  $breakDayLabels
     */
    private function parseBreak(string $text, array $monthYears, array $breakDayLabels): ?ParsedCalendarRange
    {
        $name = null;

        if (preg_match('/\(([^)]+)\)/u', $text, $match) === 1) {
            $name = trim($match[1]);
        }

        $body = (string) preg_replace('/\([^)]*\)/u', ' ', $text);

        $pattern = '/(\d{1,2})(?![\d])\s*(?:de\s+)?([\p{L}]{3,}\.?)?\s*\ba\b\s*(\d{1,2})(?![\d])\s*(?:de\s+)?([\p{L}]{3,}\.?)/u';

        if (preg_match($pattern, $body, $match) !== 1) {
            return null;
        }

        $endMonth = $this->monthFromText($match[4]);

        if ($endMonth === null) {
            return null;
        }

        // O grupo do mês inicial é opcional; quando não participou, o PHP entrega-o
        // como string vazia (há um grupo capturado depois dele), e é a ausência que
        // significa «o mesmo mês do fim» — «16 a 20 novembro».
        $startMonth = $match[2] === '' ? $endMonth : $this->monthFromText($match[2]);

        if ($startMonth === null) {
            return null;
        }

        $startDay = (int) $match[1];
        $endDay = (int) $match[3];
        $startYear = $monthYears[$startMonth] ?? null;
        $endYear = $monthYears[$endMonth] ?? null;

        if ($startYear === null || $endYear === null
            || ! checkdate($startMonth, $startDay, $startYear)
            || ! checkdate($endMonth, $endDay, $endYear)) {
            return null;
        }

        $startsOn = sprintf('%04d-%02d-%02d', $startYear, $startMonth, $startDay);
        $endsOn = sprintf('%04d-%02d-%02d', $endYear, $endMonth, $endDay);

        // Um intervalo ao contrário é uma leitura errada, e uma leitura errada não
        // se propõe: a tabela nunca escreve o fim antes do início.
        if ($endsOn < $startsOn) {
            return null;
        }

        return new ParsedCalendarRange(
            type: AcademicCalendarExceptionType::SchoolBreak,
            title: $name ?? $this->derivedBreakTitle($startDay, $startMonth, $endDay, $endMonth),
            startsOn: $startsOn,
            endsOn: $endsOn,
            rawText: $text,
            note: $this->breakNote($startsOn, $endsOn, $breakDayLabels),
        );
    }

    /**
     * Quando o documento não dá nome à interrupção, o nome é o que ela é: as suas
     * datas por extenso. Nunca um «Interrupção 3» que não diga nada a ninguém.
     */
    private function derivedBreakTitle(int $startDay, int $startMonth, int $endDay, int $endMonth): string
    {
        if ($startMonth === $endMonth) {
            return __('Interrupção letiva — :from a :to de :month', [
                'from' => $startDay,
                'to' => $endDay,
                'month' => self::MONTH_NAMES[$startMonth],
            ]);
        }

        return __('Interrupção letiva — :from de :fromMonth a :to de :toMonth', [
            'from' => $startDay,
            'fromMonth' => self::MONTH_NAMES[$startMonth],
            'to' => $endDay,
            'toMonth' => self::MONTH_NAMES[$endMonth],
        ]);
    }

    /**
     * O nome que a grelha dá a um dia DENTRO desta interrupção — «Carnaval», no
     * documento real, no dia 9 de um intervalo que vai de 1 a 10 de fevereiro.
     *
     * VAI COMO OBSERVAÇÃO E NÃO COMO TÍTULO, deliberadamente. O documento nomeia o
     * DIA e não o intervalo; chamar «Carnaval» a dez dias seria pôr na boca do
     * calendário uma afirmação que ele não faz. Assim o professor lê o que lá está
     * e decide — inclusive renomear a interrupção, se for isso que quiser.
     *
     * @param  array<string, string>  $breakDayLabels
     */
    private function breakNote(string $startsOn, string $endsOn, array $breakDayLabels): ?string
    {
        $notes = [];

        foreach ($breakDayLabels as $date => $label) {
            if ($date >= $startsOn && $date <= $endsOn) {
                $notes[] = __('O documento nomeia o dia :day como «:label».', [
                    'day' => $this->longDate($date),
                    'label' => $label,
                ]);
            }
        }

        return $notes === [] ? null : implode(' ', $notes);
    }

    // ─────────────────────────────────────────────────────────────────── datas

    /**
     * A primeira data escrita neste texto, e tudo o que vem antes dela.
     *
     * O «tudo o que vem antes» é o que resolve «9.º ano - 4 junho»: a data é «4
     * junho» e o resto é a coorte. Procurar a data ANTES de tentar perceber o
     * rótulo — e não ao contrário — é o que evita ler «9 de ano» em «9.º ano»,
     * porque «ano» não é prefixo de mês nenhum e o par simplesmente não resolve.
     *
     * @param  array<int, int>  $monthYears
     * @return array{date: string, prefix: string|null}|null
     */
    private function dateIn(string $text, array $monthYears): ?array
    {
        $pattern = '/(\d{1,2})(?![\d])\s*(?:de\s+)?([\p{L}]{3,}\.?)/u';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        foreach ($matches as $match) {
            $month = $this->monthFromText($match[2][0]);

            if ($month === null) {
                continue;
            }

            $day = (int) $match[1][0];
            $year = $monthYears[$month] ?? null;

            if ($year === null || ! checkdate($month, $day, $year)) {
                continue;
            }

            $prefix = $this->cleanLabel(substr($text, 0, (int) $match[0][1]));

            return [
                'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                'prefix' => $prefix === '' ? null : $prefix,
            ];
        }

        return null;
    }

    /**
     * «2027-02-09» dito como um calendário o diz: «9 de fevereiro».
     */
    private function longDate(string $date): string
    {
        [, $month, $day] = array_map(intval(...), explode('-', $date));

        return __(':day de :month', ['day' => $day, 'month' => self::MONTH_NAMES[$month] ?? (string) $month]);
    }

    /**
     * «março», «mar.», «Março» — o mesmo mês; «ano» — mês nenhum.
     */
    private function monthFromText(string $text): ?int
    {
        $folded = preg_replace('/[^a-z]/', '', $this->fold($text)) ?? '';

        if (strlen($folded) < 3) {
            return null;
        }

        $found = null;

        foreach (self::MONTHS as $number => $name) {
            if (str_starts_with($name, $folded)) {
                if ($found !== null) {
                    // Prefixo ambíguo: preferir não saber a escolher à sorte.
                    return null;
                }

                $found = $number;
            }
        }

        return $found;
    }

    // ─────────────────────────────────────────────────────────────────── as cores

    /**
     * O amarelo das interrupções, reconhecido por MATIZ E SATURAÇÃO.
     *
     * Ver o cabeçalho da classe: uma lista de hexadecimais exatos falha em silêncio
     * assim que o tema do documento mudar dois dígitos, e o matiz sozinho não chega
     * porque o laranja dos feriados (#ED7D31, matiz 24°) e o salmão dos
     * fins-de-semana (#F8CBAD, matiz 24°) são o mesmo matiz com saturações
     * completamente diferentes.
     */
    private function isSchoolBreakFill(?string $rgb): bool
    {
        $hsv = $rgb === null ? null : $this->hsv($rgb);

        if ($hsv === null) {
            return false;
        }

        [$hue, $saturation, $value] = $hsv;

        return $hue >= 45.0 && $hue <= 70.0 && $saturation >= 0.5 && $value >= 0.5;
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null matiz em graus, saturação e valor em 0–1
     */
    private function hsv(string $rgb): ?array
    {
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $rgb) !== 1) {
            return null;
        }

        $red = hexdec(substr($rgb, 0, 2)) / 255;
        $green = hexdec(substr($rgb, 2, 2)) / 255;
        $blue = hexdec(substr($rgb, 4, 2)) / 255;

        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $delta = $max - $min;

        if ($delta <= 0.0) {
            return [0.0, 0.0, $max];
        }

        $hue = match (true) {
            $max === $red => 60 * fmod(($green - $blue) / $delta, 6),
            $max === $green => 60 * ((($blue - $red) / $delta) + 2),
            default => 60 * ((($red - $green) / $delta) + 4),
        };

        return [fmod($hue + 360, 360), $max <= 0.0 ? 0.0 : $delta / $max, $max];
    }

    private function fillOf(Worksheet $sheet, string $coordinate): ?string
    {
        try {
            $fill = $sheet->getStyle($coordinate)->getFill();

            if ($fill->getFillType() === Fill::FILL_NONE || $fill->getFillType() === null) {
                return null;
            }

            return $fill->getStartColor()->getRGB();
        } catch (Throwable) {
            return null;
        }
    }

    // ───────────────────────────────────────────────────────────────── utilitários

    private function cell(Worksheet $sheet, string $coordinate): string
    {
        if (! $sheet->cellExists($coordinate)) {
            return '';
        }

        $value = $sheet->getCell($coordinate)->getValue();

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * O que sobra de uma célula depois do número do dia: sem o traço ou os dois
     * pontos que às vezes o separam do nome, e com os espaços a dobrar do documento
     * real («Fim  5/6/7/8.º») reduzidos a um.
     */
    private function cleanLabel(string $text): string
    {
        $text = (string) preg_replace('/^[\s\-\x{2013}\x{2014}.:·]+/u', '', $text);
        $text = (string) preg_replace('/[\s\-\x{2013}\x{2014}:·]+$/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function fold(string $text): string
    {
        return strtr(mb_strtolower(trim($text)), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
    }
}
