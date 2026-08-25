<?php

namespace Tests\Unit\Import;

use App\Domain\Import\AcademicCalendar\ParsedAcademicCalendar;
use App\Models\AcademicCalendarExceptionType;
use App\Services\Import\AcademicCalendar\AcademicCalendarFileException;
use App\Services\Import\AcademicCalendar\AcademicCalendarParser;
use App\Support\Import\SpreadsheetZipSafety;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\AcademicCalendarXlsxBuilder;
use Tests\TestCase;

/**
 * O leitor do calendário escolar, contra um .xlsx com exatamente a forma de um
 * calendário publicado por um agrupamento — e sem uma base de dados à vista.
 *
 * A fixture não é decoração e nenhuma das suas esquisitices é inventada para o
 * efeito: cada uma saiu de olhar para a proposta real de um agrupamento (o mesmo
 * que fica de fora deste repositório) e cada uma já produziu, ou produziria, uma
 * resposta confiantemente errada. Ver AcademicCalendarXlsxBuilder para a lista e
 * para o porquê de cada uma.
 */
class AcademicCalendarParserTest extends TestCase
{
    #[Test]
    public function it_reads_the_school_and_the_academic_year_from_the_title(): void
    {
        $parsed = $this->parse();

        $this->assertSame(AcademicCalendarXlsxBuilder::SCHOOL, $parsed->schoolName);
        $this->assertSame('2030/2031', $parsed->academicYearLabel);
        $this->assertSame('2030/2031', $parsed->academicYearNormalised);
    }

    /**
     * O ano civil de cada data é INFERIDO da ordem dos meses no cabeçalho — o
     * ponto em que o número do mês desce é o ponto em que o ano sobe — e nunca de
     * uma regra «setembro a dezembro é o primeiro ano» escrita à mão.
     */
    #[Test]
    public function it_infers_the_calendar_year_of_every_date_from_where_the_months_wrap(): void
    {
        $parsed = $this->parse();

        // setembro pertence ao primeiro ano civil...
        $this->assertSame('2030-09-12', $parsed->semesters[0]->startsOn);
        // ...e janeiro, do outro lado da viragem, ao segundo.
        $this->assertSame('2031-01-30', $parsed->semesters[0]->endCandidates[0]->endsOn);
    }

    #[Test]
    public function it_reads_both_semesters_from_the_summary_table(): void
    {
        $parsed = $this->parse();

        $this->assertCount(2, $parsed->semesters);
        $this->assertSame(['1.º Semestre', '2.º Semestre'], array_map(
            fn ($semester): string => $semester->label,
            $parsed->semesters,
        ));
        $this->assertSame([1, 2], array_map(
            fn ($semester): int => $semester->sequence,
            $parsed->semesters,
        ));
    }

    /**
     * A COLUNA DECOY. O documento real repete «1.º Semestre» e «2.º Semestre» numa
     * tabela de contagens muito à direita. Um leitor que procurasse rótulos de
     * período em toda a largura da folha abriria aqui dois blocos fantasma sem
     * datas nenhumas — e o professor via quatro semestres num ano de dois.
     */
    #[Test]
    public function the_counting_table_further_right_never_opens_phantom_semesters(): void
    {
        $this->assertCount(2, $this->parse()->semesters);
    }

    #[Test]
    public function the_first_semester_has_exactly_one_end_date_and_no_ambiguity(): void
    {
        $first = $this->parse()->semesters[0];

        $this->assertFalse($first->isAmbiguous());
        $this->assertCount(1, $first->endCandidates);
        $this->assertNull($first->endCandidates[0]->cohort);
        $this->assertSame(AcademicCalendarXlsxBuilder::FIRST_SEMESTER_ENDS_ON, $first->unambiguousEnd());
    }

    /**
     * A AMBIGUIDADE DE PROPÓSITO. Três datas de fim, uma por coorte, para um campo
     * que guarda uma. O parser trá-las TODAS e não escolhe nenhuma — nem a
     * primeira, nem a última, nem a mais comum (§32).
     */
    #[Test]
    public function the_second_semester_keeps_all_three_cohort_end_dates_and_chooses_none(): void
    {
        $second = $this->parse()->semesters[1];

        $this->assertTrue($second->isAmbiguous());
        $this->assertNull($second->unambiguousEnd());
        $this->assertCount(3, $second->endCandidates);

        $this->assertSame(
            array_values(AcademicCalendarXlsxBuilder::SECOND_SEMESTER_ENDS_ON),
            array_map(fn ($end): string => $end->endsOn, $second->endCandidates),
        );
        $this->assertSame(
            array_keys(AcademicCalendarXlsxBuilder::SECOND_SEMESTER_ENDS_ON),
            array_map(fn ($end): ?string => $end->cohort, $second->endCandidates),
        );
    }

    /**
     * «9.º ano - 4 junho»: o «9» de «9.º ano» está antes da data e não é um dia, e
     * «ano» não é prefixo de mês nenhum. Ler a primeira data VÁLIDA — em vez do
     * primeiro número seguido de letras — é o que impede «9 de ano».
     */
    #[Test]
    public function a_cohort_written_before_the_date_is_never_read_as_a_date(): void
    {
        $second = $this->parse()->semesters[1];

        $this->assertSame('9.º ano', $second->endCandidates[0]->cohort);
        $this->assertSame('2031-06-04', $second->endCandidates[0]->endsOn);
    }

    #[Test]
    public function it_reads_every_interruption_however_it_is_written(): void
    {
        $breaks = $this->parse()->schoolBreaks;

        $this->assertCount(4, $breaks);

        $this->assertSame(
            array_map(fn (array $break): array => [$break[0], $break[1]], AcademicCalendarXlsxBuilder::BREAKS),
            array_map(fn ($break): array => [$break->startsOn, $break->endsOn], $breaks),
        );

        foreach ($breaks as $break) {
            $this->assertSame(AcademicCalendarExceptionType::SchoolBreak, $break->type);
        }
    }

    /**
     * O nome entre parêntesis é o título quando existe; quando não existe, o
     * título são as datas por extenso — e nunca um «Interrupção 3» que não diga
     * nada a ninguém.
     */
    #[Test]
    public function an_interruption_is_named_by_the_document_or_by_its_own_dates(): void
    {
        $breaks = $this->parse()->schoolBreaks;

        $this->assertSame('Interrupção letiva — 18 a 22 de novembro', $breaks[0]->title);
        $this->assertSame('Natal', $breaks[1]->title);
        $this->assertSame('Interrupção letiva — 1 a 10 de fevereiro', $breaks[2]->title);
        $this->assertSame('Páscoa', $breaks[3]->title);
    }

    #[Test]
    public function an_interruption_that_crosses_a_month_boundary_keeps_both_months(): void
    {
        $easter = $this->parse()->schoolBreaks[3];

        $this->assertSame('2031-03-25', $easter->startsOn);
        $this->assertSame('2031-04-02', $easter->endsOn);
    }

    /**
     * O «Carnaval» amarelo é o NOME DE UM DIA dentro de um intervalo de dez, e não
     * um feriado nem o nome do intervalo inteiro. Vai como observação, para que o
     * professor leia o que lá está sem que o calendário passe a afirmar que dez
     * dias são Carnaval.
     */
    #[Test]
    public function a_named_day_inside_an_interruption_becomes_a_note_and_never_a_holiday(): void
    {
        $parsed = $this->parse();

        $this->assertStringContainsString('Carnaval', (string) $parsed->schoolBreaks[2]->note);
        $this->assertStringContainsString('4 de fevereiro', (string) $parsed->schoolBreaks[2]->note);

        $this->assertNotContains('Carnaval', array_map(
            fn ($holiday): string => $holiday->title,
            $parsed->holidays,
        ));
    }

    #[Test]
    public function it_reads_every_named_holiday_from_the_day_grid(): void
    {
        $holidays = $this->parse()->holidays;

        $this->assertCount(13, $holidays);
        $this->assertSame(
            array_keys(AcademicCalendarXlsxBuilder::HOLIDAYS),
            array_map(fn ($holiday): string => $holiday->startsOn, $holidays),
        );
        $this->assertSame(
            array_values(AcademicCalendarXlsxBuilder::HOLIDAYS),
            array_map(fn ($holiday): string => $holiday->title, $holidays),
        );

        foreach ($holidays as $holiday) {
            $this->assertSame(AcademicCalendarExceptionType::Holiday, $holiday->type);
            // Um feriado é um dia, e um dia é `starts_on === ends_on` — a mesma
            // convenção que a Fase 5.4 já fixou.
            $this->assertSame($holiday->startsOn, $holiday->endsOn);
        }
    }

    #[Test]
    public function a_holiday_written_with_a_hyphen_and_no_space_is_read_like_the_others(): void
    {
        $parsed = $this->parse();

        $hyphenated = collect($parsed->holidays)
            ->firstWhere('startsOn', AcademicCalendarXlsxBuilder::HYPHENATED_HOLIDAY);

        $this->assertNotNull($hyphenated);
        $this->assertSame('Páscoa', $hyphenated->title);
    }

    /**
     * Um feriado DENTRO de uma interrupção é as duas coisas ao mesmo tempo, e o
     * documento pinta-o de laranja precisamente para o dizer. Continua a ser
     * proposto — o modelo da Fase 5.4 permite a sobreposição de propósito.
     */
    #[Test]
    public function a_holiday_that_falls_inside_an_interruption_is_still_read(): void
    {
        $christmas = collect($this->parse()->holidays)->firstWhere('startsOn', '2030-12-25');

        $this->assertNotNull($christmas);
        $this->assertSame('Natal', $christmas->title);
    }

    /**
     * Um feriado ao fim-de-semana é laranja e não salmão no documento real, e é
     * lido na mesma: a cor do fim-de-semana não pode esconder um dia com nome.
     */
    #[Test]
    public function a_holiday_that_falls_on_a_weekend_is_still_read(): void
    {
        // 2031-03-30 é um domingo.
        $this->assertNotNull(collect($this->parse()->holidays)->firstWhere('startsOn', '2031-03-30'));
    }

    /**
     * A GRELHA REPETE O QUE A TABELA JÁ DIZ MELHOR. «Início 1º S» e «Fim 1.º S»
     * estão lá escritos, e lê-los era propor os semestres duas vezes — uma delas
     * sem as três datas em disputa à vista.
     */
    #[Test]
    public function the_period_markers_drawn_inside_the_grid_are_never_read_as_holidays(): void
    {
        $parsed = $this->parse();

        foreach (array_keys(AcademicCalendarXlsxBuilder::PERIOD_MARKERS) as $date) {
            $this->assertNull(
                collect($parsed->holidays)->firstWhere('startsOn', $date),
                "O marcador de período de {$date} foi lido como feriado.",
            );
            $this->assertNull(
                collect($parsed->otherDatedItems)->firstWhere('date', $date),
                "O marcador de período de {$date} foi lido como acontecimento.",
            );
        }
    }

    /**
     * OS FINS DE ANO POR COORTE. Não são exceções letivas (não dizem que naquele
     * dia não há aula) e não são o `ends_on` de um período (são três para um campo
     * só). Ficam o que são: datas com nome, para o professor decidir.
     */
    #[Test]
    public function the_cohort_year_end_markers_are_kept_as_unclassified_dated_items(): void
    {
        $markers = $this->parse()->otherDatedItems;

        $this->assertCount(3, $markers);
        $this->assertSame(
            array_keys(AcademicCalendarXlsxBuilder::COHORT_MARKERS),
            array_map(fn ($marker): string => $marker->date, $markers),
        );
        // A ABREVIATURA DA CÉLULA É ESCRITA POR EXTENSO (CohortMarkerTitle), e
        // é-o aqui e não na página: daqui para a frente este texto é o título de
        // um acontecimento do calendário do professor, lido meses depois e longe
        // da coluna de junho que lhe dava o contexto. A célula tal como está
        // escrita — espaços a dobrar incluídos — fica em `rawText`.
        $this->assertSame([
            'Fim das atividades letivas — 9.º ano',
            'Fim das atividades letivas — 5.º/6.º/7.º/8.º anos',
            'Fim das atividades letivas — Pré-escolar e 1.º Ciclo',
        ], array_map(fn ($marker): string => $marker->title, $markers));

        $this->assertSame(
            ['4 Fim 9.º ano', '11 Fim  5/6/7/8.º', '30 Fim  Pré/1.ºC'],
            array_map(fn ($marker): string => $marker->rawText, $markers),
        );
    }

    /**
     * OITENTA E UM SÁBADOS E DOMINGOS QUE NÃO SÃO NOTÍCIA NENHUMA. Um fim-de-semana
     * não é uma decisão sobre a forma deste ano — é aritmética que o calendário já
     * sabe fazer —, e propô-los afogaria as quinze linhas que dizem alguma coisa.
     */
    #[Test]
    public function weekends_are_never_proposed_as_anything(): void
    {
        $parsed = $this->parse();

        $dates = [
            ...array_map(fn ($holiday): string => $holiday->startsOn, $parsed->holidays),
            ...array_map(fn ($marker): string => $marker->date, $parsed->otherDatedItems),
        ];

        // O 6 de outubro de 2030 é um domingo comum, sem nome nenhum.
        $this->assertNotContains('2030-10-06', $dates);
        $this->assertLessThan(20, count($dates));
    }

    /**
     * NÃO SE INVENTAM REUNIÕES, ATIVIDADES NEM VISITAS. O documento de referência
     * não tem nenhuma, e uma lista vazia devolvida «para o caso» convidava a página
     * seguinte a arranjar secções para as mostrar.
     */
    #[Test]
    public function nothing_that_is_not_in_the_document_is_invented(): void
    {
        $parsed = $this->parse();

        // Os únicos acontecimentos propostos são os três marcadores de coorte, e
        // todos os itens datados vêm de uma célula que existe mesmo no ficheiro.
        foreach ($parsed->otherDatedItems as $marker) {
            $this->assertNotSame('', $marker->rawText);
        }

        foreach ([...$parsed->holidays, ...$parsed->schoolBreaks] as $range) {
            $this->assertNotSame('', $range->rawText);
        }
    }

    #[Test]
    public function a_calendar_without_a_summary_table_still_yields_its_holidays(): void
    {
        $parsed = $this->parse(AcademicCalendarXlsxBuilder::example()->withoutSummaryTable());

        $this->assertSame([], $parsed->semesters);
        $this->assertSame([], $parsed->schoolBreaks);
        $this->assertCount(13, $parsed->holidays);
        $this->assertFalse($parsed->isEmpty());
    }

    #[Test]
    public function a_spreadsheet_that_is_not_a_calendar_fails_with_a_sentence(): void
    {
        $this->expectException(AcademicCalendarFileException::class);
        $this->expectExceptionMessageMatches('/calendário escolar/');

        $this->parse(AcademicCalendarXlsxBuilder::withoutACalendar());
    }

    #[Test]
    public function a_file_that_is_not_a_spreadsheet_at_all_fails_with_a_sentence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nope').'.xlsx';
        file_put_contents($path, 'isto não é uma folha de cálculo');

        try {
            $this->expectException(AcademicCalendarFileException::class);

            app(AcademicCalendarParser::class)->parse($path, 2030);
        } finally {
            @unlink($path);
        }
    }

    /**
     * O ficheiro passa por SpreadsheetZipSafety ANTES de o PhpSpreadsheet o ver, e
     * não depois: quando a biblioteca já o abriu, a memória já foi gasta. Aqui a
     * verificação é substituída por uma que recusa tudo, o que prova que ela está
     * mesmo no caminho — e não apenas escrita algures.
     */
    #[Test]
    public function an_unsafe_package_is_refused_before_the_workbook_is_ever_opened(): void
    {
        $this->swap(SpreadsheetZipSafety::class, new class extends SpreadsheetZipSafety
        {
            public function isSafe(string $absolutePath): bool
            {
                return false;
            }
        });

        $path = tempnam(sys_get_temp_dir(), 'unsafe').'.xlsx';
        file_put_contents($path, AcademicCalendarXlsxBuilder::example()->bytes());

        try {
            $this->expectException(AcademicCalendarFileException::class);
            $this->expectExceptionMessageMatches('/segurança/');

            app(AcademicCalendarParser::class)->parse($path, 2030);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Quando o documento não diz a que ano letivo se refere, o ano civil de recurso
     * — o do ano letivo selecionado — é que manda; sem nenhum dos dois, o parser diz
     * que não sabe em vez de escolher um.
     */
    #[Test]
    public function without_a_year_in_the_title_and_without_a_fallback_it_says_so(): void
    {
        $path = $this->write(AcademicCalendarXlsxBuilder::example()->academicYear(null));

        try {
            $withFallback = app(AcademicCalendarParser::class)->parse($path, 2030);
            $this->assertSame('2030-09-12', $withFallback->semesters[0]->startsOn);
            $this->assertNull($withFallback->academicYearNormalised);

            $this->expectException(AcademicCalendarFileException::class);
            $this->expectExceptionMessageMatches('/ano letivo/');

            app(AcademicCalendarParser::class)->parse($path, null);
        } finally {
            @unlink($path);
        }
    }

    // --------------------------------------------------------------- helpers

    private function parse(?AcademicCalendarXlsxBuilder $builder = null): ParsedAcademicCalendar
    {
        $path = $this->write($builder ?? AcademicCalendarXlsxBuilder::example());

        try {
            return app(AcademicCalendarParser::class)->parse($path, AcademicCalendarXlsxBuilder::FIRST_YEAR);
        } finally {
            @unlink($path);
        }
    }

    private function write(AcademicCalendarXlsxBuilder $builder): string
    {
        $path = tempnam(sys_get_temp_dir(), 'calendario').'.xlsx';
        file_put_contents($path, $builder->bytes());

        return $path;
    }
}
