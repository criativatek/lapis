<?php

namespace Tests\Unit\AcademicCalendar;

use App\Domain\AcademicCalendar\NationalHoliday;
use App\Services\AcademicCalendar\Holidays\PortugalNationalHolidayProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Os treze feriados nacionais portugueses — dez fixos e três presos à Páscoa.
 *
 * O QUE ESTE FICHEIRO PROTEGE não é sobretudo o que a lista TEM: é o que ela NÃO
 * tem. Carnaval, feriados municipais e feriados regionais não são feriados
 * nacionais — dependem de um despacho do ano, do concelho da escola ou da região
 * autónoma —, e propô-los como facto legal seria esta aplicação afirmar com
 * confiança uma coisa que não sabe. O calendário escolar real de referência tem o
 * «Dia de Leiria» e tem o Carnaval; nenhum dos dois pode sair daqui.
 */
class PortugalNationalHolidayProviderTest extends TestCase
{
    private PortugalNationalHolidayProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new PortugalNationalHolidayProvider;
    }

    // ─────────────────────────────────────────────────────────── os dez fixos

    #[Test]
    public function every_one_of_the_ten_fixed_holidays_is_there_for_a_representative_year(): void
    {
        $dates = $this->datesOf(2027);

        foreach ([
            '2027-01-01', '2027-04-25', '2027-05-01', '2027-06-10', '2027-08-15',
            '2027-10-05', '2027-11-01', '2027-12-01', '2027-12-08', '2027-12-25',
        ] as $date) {
            $this->assertContains($date, $dates, "Falta o feriado de {$date}.");
        }
    }

    #[Test]
    public function each_fixed_holiday_carries_the_name_it_is_known_by(): void
    {
        $titles = $this->titlesByDate(2027);

        $this->assertSame('Ano Novo', $titles['2027-01-01']);
        $this->assertSame('Dia da Liberdade', $titles['2027-04-25']);
        $this->assertSame('Dia do Trabalhador', $titles['2027-05-01']);
        $this->assertSame('Dia de Portugal', $titles['2027-06-10']);
        $this->assertSame('Assunção de Nossa Senhora', $titles['2027-08-15']);
        $this->assertSame('Implantação da República', $titles['2027-10-05']);
        $this->assertSame('Dia de Todos os Santos', $titles['2027-11-01']);
        $this->assertSame('Restauração da Independência', $titles['2027-12-01']);
        $this->assertSame('Imaculada Conceição', $titles['2027-12-08']);
        $this->assertSame('Natal', $titles['2027-12-25']);
    }

    #[Test]
    public function a_calendar_year_holds_exactly_thirteen_and_not_one_more(): void
    {
        foreach ([2025, 2026, 2027, 2031] as $year) {
            $this->assertCount(13, $this->provider->forCalendarYear($year), "O ano {$year} não tem treze.");
        }
    }

    // ─────────────────────────────────────────────────── a Páscoa e os três móveis

    /**
     * O ALGORITMO, VERIFICADO CONTRA EFEMÉRIDES E NÃO CONTRA SI PRÓPRIO.
     *
     * A Páscoa de 2027 é a única destas quatro que tem confirmação independente
     * DENTRO deste projeto: a célula «28-Páscoa» do calendário escolar real contra
     * o qual a Fase 5.6 foi escrita. As outras três são efemérides públicas.
     *
     * E NÃO SE USA `easter_date()` PARA COMPARAR, de propósito: além de depender da
     * extensão `calendar`, ela devolve o SÁBADO nestes quatro anos (o timestamp de
     * meia-noite não sobrevive à conversão), o que a torna uma referência pior do
     * que a aritmética que ela devia confirmar. A soma `21 de março + easter_days()`
     * concorda com esta implementação em todos eles.
     */
    #[Test]
    public function easter_sunday_matches_the_published_dates_for_four_different_years(): void
    {
        $this->assertSame('2025-04-20', PortugalNationalHolidayProvider::easterSunday(2025));
        $this->assertSame('2026-04-05', PortugalNationalHolidayProvider::easterSunday(2026));
        $this->assertSame('2027-03-28', PortugalNationalHolidayProvider::easterSunday(2027));
        $this->assertSame('2031-04-13', PortugalNationalHolidayProvider::easterSunday(2031));
    }

    #[Test]
    public function easter_sunday_always_falls_on_a_sunday(): void
    {
        for ($year = 2024; $year <= 2050; $year++) {
            $this->assertSame(
                'Sunday',
                date('l', (int) strtotime(PortugalNationalHolidayProvider::easterSunday($year))),
                "A Páscoa de {$year} não caiu a um domingo.",
            );
        }
    }

    #[Test]
    public function the_three_movable_holidays_hang_off_easter_in_2027(): void
    {
        $titles = $this->titlesByDate(2027);

        // Páscoa − 2, a própria, e Páscoa + 60.
        $this->assertSame('Sexta-Feira Santa', $titles['2027-03-26']);
        $this->assertSame('Domingo de Páscoa', $titles['2027-03-28']);
        $this->assertSame('Corpo de Deus', $titles['2027-05-27']);
    }

    /**
     * O SEGUNDO ANO, e com uma Páscoa noutro mês: 2026 cai a 5 de abril, e o Corpo
     * de Deus sessenta dias depois atravessa abril e maio inteiros para cair a 4 de
     * junho. É este salto entre meses de comprimentos diferentes que uma aritmética
     * de dias escrita à mão erraria.
     */
    #[Test]
    public function the_three_movable_holidays_hang_off_easter_in_2026_too(): void
    {
        $titles = $this->titlesByDate(2026);

        $this->assertSame('Sexta-Feira Santa', $titles['2026-04-03']);
        $this->assertSame('Domingo de Páscoa', $titles['2026-04-05']);
        $this->assertSame('Corpo de Deus', $titles['2026-06-04']);
    }

    // ───────────────────────────────────────────────── o que NUNCA está na lista

    /**
     * A ASSERÇÃO MAIS IMPORTANTE DESTE FICHEIRO. Nenhuma destas datas tem estatuto
     * legal nacional, e todas elas aparecem em calendários escolares reais — o de
     * referência tem duas. Chegam pela mão do professor ou pela importação do
     * .xlsx da escola, que são os dois sítios que as sabem. Nunca por aqui.
     */
    #[Test]
    public function carnaval_and_municipal_and_regional_holidays_never_appear(): void
    {
        $titles = implode(' | ', array_map(
            fn (NationalHoliday $holiday): string => $holiday->title,
            $this->provider->forCalendarYear(2027),
        ));

        foreach (['Carnaval', 'Leiria', 'Lisboa', 'Porto', 'Santo António', 'São João', 'Açores', 'Madeira', 'tolerância'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $titles);
        }

        // A terça-feira de Carnaval de 2027 é a 9 de fevereiro (Páscoa − 47). Não
        // está lá, e é a data que mais facilmente lá entraria por engano: é a única
        // das proibidas que também se calcula a partir da Páscoa.
        $this->assertNotContains('2027-02-09', $this->datesOf(2027));
    }

    // ──────────────────────────────────────────── o recorte do ano letivo

    /**
     * O ANO LETIVO ATRAVESSA DOIS ANOS CIVIS, e é por isso que o provider recebe um
     * intervalo e não um ano. 2026/2027 vai de 1 de setembro de 2026 a 31 de agosto
     * de 2027 — a forma que este projeto usa em todo o lado, do
     * AcademicYearFactory às fixtures escritas à mão — e apanha CINCO feriados do
     * lado de 2026 e OITO do lado de 2027.
     */
    #[Test]
    public function the_real_2026_2027_window_yields_exactly_thirteen_across_two_calendar_years(): void
    {
        $holidays = $this->provider->between('2026-09-01', '2027-08-31');

        $this->assertCount(13, $holidays);

        $this->assertSame([
            '2026-10-05', '2026-11-01', '2026-12-01', '2026-12-08', '2026-12-25',
            '2027-01-01', '2027-03-26', '2027-03-28', '2027-04-25', '2027-05-01',
            '2027-05-27', '2027-06-10', '2027-08-15',
        ], array_map(fn (NationalHoliday $holiday): string => $holiday->date, $holidays));
    }

    /**
     * E O RECORTE É MESMO UM RECORTE. Os cinco feriados de 2026 anteriores a
     * setembro (1 jan, 3 abr, 5 abr, 25 abr, 1 mai, 4 jun, 10 jun, 15 ago) ficam de
     * fora, e os de 2027 posteriores a agosto também — porque não caem dentro deste
     * ano letivo, e não porque o provider não os saiba.
     */
    #[Test]
    public function holidays_outside_the_window_are_left_out_even_though_the_provider_knows_them(): void
    {
        $dates = array_map(
            fn (NationalHoliday $holiday): string => $holiday->date,
            $this->provider->between('2026-09-01', '2027-08-31'),
        );

        foreach (['2026-01-01', '2026-04-25', '2026-05-01', '2026-06-10', '2026-08-15', '2027-10-05', '2027-12-25'] as $outside) {
            $this->assertNotContains($outside, $dates);
        }

        // E o provider sabe-os: os mesmos dois anos civis, sem recorte, têm 26.
        $this->assertCount(13, $this->provider->forCalendarYear(2026));
        $this->assertCount(13, $this->provider->forCalendarYear(2027));
    }

    /**
     * UM ANO LETIVO QUE ACABE EM JULHO PERDE O 15 DE AGOSTO, e passa a ter doze.
     * Está aqui escrito para que o «treze» do teste acima se leia como consequência
     * das datas do ano e não como uma propriedade fixa da lista.
     */
    #[Test]
    public function a_year_that_ends_in_july_has_twelve_and_not_thirteen(): void
    {
        $holidays = $this->provider->between('2026-09-01', '2027-07-31');

        $this->assertCount(12, $holidays);
        $this->assertNotContains(
            '2027-08-15',
            array_map(fn (NationalHoliday $holiday): string => $holiday->date, $holidays),
        );
    }

    #[Test]
    public function the_list_always_comes_back_in_date_order(): void
    {
        $dates = array_map(
            fn (NationalHoliday $holiday): string => $holiday->date,
            $this->provider->between('2026-09-01', '2027-08-31'),
        );

        $sorted = $dates;
        sort($sorted);

        $this->assertSame($sorted, $dates);
    }

    #[Test]
    public function an_upside_down_window_yields_nothing_rather_than_guessing(): void
    {
        $this->assertSame([], $this->provider->between('2027-08-31', '2026-09-01'));
    }

    #[Test]
    public function a_single_day_window_yields_only_that_day(): void
    {
        $holidays = $this->provider->between('2026-12-25', '2026-12-25');

        $this->assertCount(1, $holidays);
        $this->assertSame('Natal', $holidays[0]->title);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return list<string>
     */
    private function datesOf(int $year): array
    {
        return array_map(
            fn (NationalHoliday $holiday): string => $holiday->date,
            $this->provider->forCalendarYear($year),
        );
    }

    /**
     * @return array<string, string>
     */
    private function titlesByDate(int $year): array
    {
        $titles = [];

        foreach ($this->provider->forCalendarYear($year) as $holiday) {
            $titles[$holiday->date] = $holiday->title;
        }

        return $titles;
    }
}
