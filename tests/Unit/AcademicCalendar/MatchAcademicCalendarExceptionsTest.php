<?php

namespace Tests\Unit\AcademicCalendar;

use App\Domain\AcademicCalendar\AcademicCalendarExceptionMatch;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicCalendarExceptionType;
use App\Services\AcademicCalendar\MatchAcademicCalendarExceptions;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A regra que decide se uma exceção proposta já lá está — e, mais importante, o
 * que ela recusa decidir.
 *
 * TODO O RISCO DA DEDUPLICAÇÃO VIVE AQUI. Um emparelhamento demasiado largo faz
 * desaparecer em silêncio um feriado que o professor queria; um demasiado estreito
 * dá-lhe dois «1 de maio» no mesmo dia. É por isso que a única forma de igualdade
 * aqui é a exata: datas iguais ao dia, designações iguais depois de normalizadas, e
 * nada de aproximações de texto em ponto nenhum.
 *
 * Nada disto toca na base de dados: os modelos são construídos em memória, porque a
 * regra é sobre valores e não sobre linhas gravadas.
 */
class MatchAcademicCalendarExceptionsTest extends TestCase
{
    private MatchAcademicCalendarExceptions $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = new MatchAcademicCalendarExceptions;
    }

    // ────────────────────────────────────────────────── os quatro desfechos

    #[Test]
    public function nothing_alike_stored_matches_nothing_at_all(): void
    {
        $match = $this->match('2027-05-01', '2027-05-01', 'Dia do Trabalhador', [
            $this->exception('Natal', '2026-12-25', '2026-12-25'),
        ]);

        $this->assertFalse($match->matched());
        $this->assertNull($match->state);
        $this->assertNull($match->current);
    }

    #[Test]
    public function the_same_type_dates_and_normalised_title_is_already_there(): void
    {
        $match = $this->match('2027-05-01', '2027-05-01', 'Dia do Trabalhador', [
            $this->exception('Dia do Trabalhador', '2027-05-01', '2027-05-01'),
        ]);

        $this->assertTrue($match->is(AcademicCalendarExceptionMatch::STATE_EXISTS));
        $this->assertSame('Dia do Trabalhador', $match->current['title']);
    }

    /**
     * O ESTADO NOVO, e a razão de esta fase existir. «Dia do Trabalhador» e «1.º de
     * Maio» são o mesmo dia com dois nomes: não nasce uma segunda linha — isso nunca
     * esteve em causa — mas deixa de se dizer ao professor que «já está lá» sem lhe
     * mostrar que o outro lado lhe chama outra coisa.
     */
    #[Test]
    public function the_same_type_and_dates_with_another_title_is_a_correspondence(): void
    {
        $match = $this->match('2027-05-01', '2027-05-01', '1.º de Maio', [
            $this->exception('Dia do Trabalhador', '2027-05-01', '2027-05-01'),
        ]);

        $this->assertTrue($match->is(AcademicCalendarExceptionMatch::STATE_CORRESPONDENCE));
        // E o que já lá está viaja inteiro, para as duas designações poderem ir
        // lado a lado no ecrã.
        $this->assertSame('Dia do Trabalhador', $match->current['title']);
        $this->assertSame('2027-05-01', $match->current['starts_on']);
    }

    #[Test]
    public function the_same_type_overlapping_without_being_identical_is_a_conflict(): void
    {
        $match = $this->match('2026-12-23', '2026-12-31', 'Natal', [
            $this->exception('Natal (versão antiga)', '2026-12-20', '2027-01-02', AcademicCalendarExceptionType::SchoolBreak),
        ], AcademicCalendarExceptionType::SchoolBreak);

        $this->assertTrue($match->is(AcademicCalendarExceptionMatch::STATE_CONFLICT));
        $this->assertSame('2026-12-20', $match->current['starts_on']);
    }

    /**
     * UM CONFLITO É UM CONFLITO MESMO QUANDO O TÍTULO É O MESMO: o que está em
     * causa são as datas, e um título igual não torna dois intervalos diferentes no
     * mesmo intervalo. É a metade da regra que o estado novo não mexeu.
     */
    #[Test]
    public function an_identical_title_never_turns_an_overlap_into_a_match(): void
    {
        $match = $this->match('2026-12-23', '2026-12-31', 'Natal', [
            $this->exception('Natal', '2026-12-20', '2027-01-02', AcademicCalendarExceptionType::SchoolBreak),
        ], AcademicCalendarExceptionType::SchoolBreak);

        $this->assertTrue($match->is(AcademicCalendarExceptionMatch::STATE_CONFLICT));
    }

    // ───────────────────────────────────── espécies diferentes nunca conflituam

    /**
     * O 25 DE DEZEMBRO É AS DUAS COISAS. Um feriado dentro de uma interrupção
     * letiva é exatamente o que todos os calendários escolares têm, e as duas linhas
     * são ambas verdadeiras — o modelo permite a sobreposição de propósito.
     */
    #[Test]
    public function a_different_type_never_matches_however_much_the_dates_overlap(): void
    {
        $christmasBreak = $this->exception(
            'Interrupção de Natal',
            '2026-12-21',
            '2027-01-02',
            AcademicCalendarExceptionType::SchoolBreak,
        );

        $match = $this->match('2026-12-25', '2026-12-25', 'Natal', [$christmasBreak]);

        $this->assertFalse($match->matched());
    }

    #[Test]
    public function a_different_type_does_not_match_even_on_the_very_same_dates_and_title(): void
    {
        $match = $this->match('2026-12-25', '2026-12-25', 'Natal', [
            $this->exception('Natal', '2026-12-25', '2026-12-25', AcademicCalendarExceptionType::NonTeachingDay),
        ]);

        $this->assertFalse($match->matched());
    }

    // ──────────────────────────────────────────────── a normalização, e só ela

    /**
     * A NORMALIZAÇÃO É A QUE JÁ CÁ ESTAVA — a mesma que emparelha os rótulos dos
     * períodos desde a Fase 5.6, e não uma segunda escrita para as designações.
     * Maiúsculas, espaços a dobrar e pontas por aparar; e MAIS NADA.
     */
    #[Test]
    public function case_and_whitespace_are_the_only_differences_that_are_forgiven(): void
    {
        foreach (['dia do trabalhador', 'DIA DO TRABALHADOR', '  Dia do Trabalhador  ', "Dia  do\tTrabalhador"] as $variant) {
            $match = $this->match('2027-05-01', '2027-05-01', $variant, [
                $this->exception('Dia do Trabalhador', '2027-05-01', '2027-05-01'),
            ]);

            $this->assertTrue(
                $match->is(AcademicCalendarExceptionMatch::STATE_EXISTS),
                "«{$variant}» devia normalizar para a mesma designação.",
            );
        }
    }

    /**
     * E NADA MAIS DO QUE ISSO. Acentos, pontuação e abreviaturas ficam DIFERENTES
     * de propósito: adivinhar que «Implant. República» e «Implantação da República»
     * são a mesma coisa é exatamente a aproximação que esta classe existe para não
     * fazer. O que sai daí não é um duplicado — é uma correspondência que o
     * professor vê e resolve.
     */
    #[Test]
    public function nothing_fuzzier_than_that_is_ever_forgiven(): void
    {
        foreach ([
            'Implant. República',
            'Implantacao da Republica',
            'Implantação da Republica',
            'Implantação',
            'Implantação da República!',
        ] as $variant) {
            $match = $this->match('2026-10-05', '2026-10-05', $variant, [
                $this->exception('Implantação da República', '2026-10-05', '2026-10-05'),
            ]);

            $this->assertTrue(
                $match->is(AcademicCalendarExceptionMatch::STATE_CORRESPONDENCE),
                "«{$variant}» não podia contar como a mesma designação.",
            );
        }
    }

    #[Test]
    public function normalise_is_exactly_the_definition_the_preview_already_used(): void
    {
        $this->assertSame('1.º semestre', MatchAcademicCalendarExceptions::normalise('  1.º   Semestre '));
        $this->assertSame('dia do trabalhador', MatchAcademicCalendarExceptions::normalise("Dia\n do  Trabalhador"));
        // Acentos preservados, pontuação preservada: normalizar não é apagar.
        $this->assertSame('páscoa', MatchAcademicCalendarExceptions::normalise('Páscoa'));
        $this->assertNotSame(
            MatchAcademicCalendarExceptions::normalise('Páscoa'),
            MatchAcademicCalendarExceptions::normalise('Pascoa'),
        );
    }

    // ──────────────────────────────────────────────────────────── o retrato

    #[Test]
    public function the_payload_carries_what_the_teacher_needs_to_decide(): void
    {
        $existing = $this->exception('Dia do Trabalhador', '2027-05-01', '2027-05-01');
        $existing->ulid = '01JQ0000000000000000000000';
        $existing->source = AcademicCalendarExceptionSource::Imported;

        $match = $this->match('2027-05-01', '2027-05-01', '1.º de Maio', [$existing]);

        $this->assertSame([
            'ulid' => '01JQ0000000000000000000000',
            'type_label' => 'Feriado',
            'title' => 'Dia do Trabalhador',
            'starts_on' => '2027-05-01',
            'ends_on' => '2027-05-01',
            // A PROVENIÊNCIA VIAJA: «já lá está, e foi o senhor que a escreveu» e
            // «já lá está, e veio do ficheiro» são duas frases muito diferentes
            // para quem está a decidir.
            'source_label' => 'Importada',
        ], $match->current);
    }

    #[Test]
    public function an_empty_calendar_matches_nothing(): void
    {
        $this->assertFalse($this->match('2027-05-01', '2027-05-01', 'Dia do Trabalhador', [])->matched());
    }

    /**
     * A LINHA EXATA GANHA À QUE SE SOBREPÕE, e não a primeira que aparecer: com as
     * duas no calendário, propor exatamente uma delas é encontrá-la a ela.
     */
    #[Test]
    public function an_exact_row_wins_over_an_overlapping_one(): void
    {
        $match = $this->match('2026-12-23', '2026-12-31', 'Natal', [
            $this->exception('A que se sobrepõe', '2026-12-20', '2027-01-02', AcademicCalendarExceptionType::SchoolBreak),
            $this->exception('A exata', '2026-12-23', '2026-12-31', AcademicCalendarExceptionType::SchoolBreak),
        ], AcademicCalendarExceptionType::SchoolBreak);

        $this->assertTrue($match->is(AcademicCalendarExceptionMatch::STATE_CORRESPONDENCE));
        $this->assertSame('A exata', $match->current['title']);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  list<AcademicCalendarException>  $existing
     */
    private function match(
        string $startsOn,
        string $endsOn,
        string $title,
        array $existing,
        AcademicCalendarExceptionType $type = AcademicCalendarExceptionType::Holiday,
    ): AcademicCalendarExceptionMatch {
        return $this->matcher->match($type, $startsOn, $endsOn, $title, new Collection($existing));
    }

    /**
     * Um modelo EM MEMÓRIA e nunca gravado: a regra é sobre valores, e uma base de
     * dados aqui só acrescentava lentidão e uma organização a que responder.
     */
    private function exception(
        string $title,
        string $startsOn,
        string $endsOn,
        AcademicCalendarExceptionType $type = AcademicCalendarExceptionType::Holiday,
    ): AcademicCalendarException {
        $exception = new AcademicCalendarException;

        $exception->ulid = 'ulid-'.$startsOn;
        $exception->type = $type;
        $exception->title = $title;
        $exception->starts_on = $startsOn;
        $exception->ends_on = $endsOn;
        $exception->source = AcademicCalendarExceptionSource::Manual;

        return $exception;
    }
}
