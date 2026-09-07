<?php

namespace Tests\Feature\Assessment;

use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Models\User;
use App\Services\Assessment\BuildClassSynopsis;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\ScaleProposalResolver;
use App\Support\Assessment\ReadingVocabulary;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AS DUAS LEITURAS DO ANO COEXISTEM, E NÃO SÃO A MESMA COISA.
 *
 * A decisão de produto, dita por inteiro porque é ela que este ficheiro
 * defende:
 *
 *  AVALIAÇÃO CONTÍNUA — o indicador FORMAL. Média (ou média ponderada, conforme
 *  a configuração) dos RESULTADOS FORMAIS de cada unidade temporal. É daqui que
 *  sai a proposta formal de nível. As intercalares nunca entram.
 *
 *  DESEMPENHO ACUMULADO — o indicador ANALÍTICO. O motor reprocessa os
 *  elementos de avaliação acumulados até ao momento. Mantém-se INTOCADO.
 *
 * O CASO QUE OBRIGA A DISTINÇÃO A EXISTIR está no próprio cenário de
 * demonstração, e é aqui fixado com os dois números escritos:
 *
 *   Carolina, desempenho acumulado   89,73 %
 *   Carolina, avaliação contínua     89,40 %
 *
 * Se um dia estes dois números coincidirem, alguém trocou uma leitura pela
 * outra — e é exatamente isso que este ficheiro impede em silêncio.
 */
class TwoReadingsOfTheYearTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));
        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    /** @return array<string, mixed> */
    private function synopsis(): array
    {
        return $this->asTenant(fn (): array => app(BuildClassSynopsis::class)->for(
            SchoolClass::where('label', '7.º A')->firstOrFail(),
        ));
    }

    /**
     * @param  array<string, mixed>  $synopsis
     * @return array<string, mixed>
     */
    private function student(array $synopsis, string $name): array
    {
        foreach ($synopsis['students'] as $student) {
            if ($student['name'] === $name) {
                return $student;
            }
        }

        $this->fail("O aluno «{$name}» não está no Quadro Síntese.");
    }

    /** O desempenho acumulado do ano, pelo motor, tal como ele sempre o calculou. */
    private function accumulatedOf(string $name): ?string
    {
        return $this->asTenant(function () use ($name): ?string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            // A ÚLTIMA UNIDADE DO ANO, tirada da coleção já ordenada. Um
            // `orderByDesc('sequence')` sobre esta relação NÃO a inverteria: ela
            // traz um `orderBy('sequence')` próprio, e a cláusula acrescentada
            // fica em segundo lugar — o resultado sairia pela ordem original e
            // este teste estaria a medir o primeiro semestre a chamar-lhe o ano.
            $last = $class->academicYear->periods->last();

            foreach (app(ClassResultsCalculator::class)->forAccumulated($class, $last) as $row) {
                if (optional($row['enrollment']->student->identity)->display_name === $name) {
                    return $row['outcome']->normalizedValue;
                }
            }

            $this->fail("Sem resultado acumulado para «{$name}».");
        });
    }

    // -------------------------------------------------------- os dois números

    #[Test]
    public function the_two_readings_give_different_numbers_and_both_survive(): void
    {
        $accumulated = $this->accumulatedOf('Carolina Nunes');
        $continuous = $this->student($this->synopsis(), 'Carolina Nunes')['continuous'];

        // O DESEMPENHO ACUMULADO, do motor, exatamente como estava.
        $this->assertSame('89.726562', $accumulated);

        // A AVALIAÇÃO CONTÍNUA, a média dos dois semestres formais.
        $this->assertSame('89.4010415000', $continuous['normalized_value']);

        // E NÃO SÃO O MESMO NÚMERO. Esta é a linha que impede que uma das
        // leituras seja silenciosamente substituída pela outra.
        $this->assertNotSame(
            round((float) $accumulated, 2),
            round((float) $continuous['normalized_value'], 2),
            'As duas leituras respondem a perguntas diferentes e não podem coincidir por acidente.',
        );
    }

    #[Test]
    public function the_engine_that_computes_the_accumulated_was_not_touched(): void
    {
        // O ACUMULADO CONTINUA A NÃO SER UMA MÉDIA DE MÉDIAS. É a propriedade
        // que o distingue, e é a que uma alteração descuidada destruiria
        // primeiro — reprocessar os elementos brutos do ano dá outro número que
        // a média dos períodos.
        $class = $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());

        [$first, $second] = $this->asTenant(function () use ($class): array {
            $periods = $class->academicYear->periods()->orderBy('sequence')->get();
            $calculator = app(ClassResultsCalculator::class);

            $of = function (array $rows): ?string {
                foreach ($rows as $row) {
                    if (optional($row['enrollment']->student->identity)->display_name === 'Carolina Nunes') {
                        return $row['outcome']->normalizedValue;
                    }
                }

                return null;
            };

            return [
                $of($calculator->forPeriod($class, $periods[0])),
                $of($calculator->forPeriod($class, $periods[1])),
            ];
        });

        $meanOfPeriods = bcdiv(bcadd((string) $first, (string) $second, 6), '2', 6);

        $this->assertSame('91.302083', $first);
        $this->assertSame('87.500000', $second);
        $this->assertSame('89.401041', $meanOfPeriods);
        $this->assertNotSame($meanOfPeriods, $this->accumulatedOf('Carolina Nunes'));
    }

    // ---------------------------------------------- a proposta formal é a contínua

    #[Test]
    public function the_formal_proposal_comes_from_the_continuous_assessment_and_not_from_the_accumulated(): void
    {
        $continuous = $this->student($this->synopsis(), 'Carolina Nunes')['continuous'];

        // A proposta que a coluna formal mostra é a banda da MÉDIA CONTÍNUA —
        // 89,40 % —, e não a banda do desempenho acumulado. Nesta escala as
        // duas caem na mesma banda, e por isso o que se afirma aqui é a FONTE,
        // não o resultado: o valor de que a proposta é lida.
        $this->assertNotNull($continuous['level']);
        $this->assertSame('89.4010415000', $continuous['normalized_value']);

        $band = $this->asTenant(function () use ($continuous): ?int {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $scale = $class->profileVersion->scale()->with('levels')->first();

            return app(ScaleProposalResolver::class)
                ->bandFor($scale, $continuous['normalized_value'])?->id;
        });

        $this->assertSame($band, $continuous['level']['scale_level_id']);
    }

    #[Test]
    public function the_continuous_reading_never_reads_a_classification_of_accumulated_scope_as_its_own_value(): void
    {
        // A DECISÃO DO ANO continua a viver onde sempre viveu — numa
        // `Classification` de âmbito acumulado — e a avaliação contínua LÊ-A
        // sem nunca a confundir com o seu próprio valor calculado. A decisão do
        // professor e a proposta da média são duas colunas, não uma.
        $continuous = $this->student($this->synopsis(), 'Carolina Nunes')['continuous'];

        $this->assertArrayHasKey('decision', $continuous);
        $this->assertArrayHasKey('proposal', $continuous);
        $this->assertNotSame('accumulated', $continuous['proposal']['state']);
    }

    // -------------------------------------------- as intercalares ficam de fora

    #[Test]
    public function an_interim_moment_changes_neither_reading(): void
    {
        $accumulatedBefore = $this->accumulatedOf('Carolina Nunes');
        $continuousBefore = $this->student($this->synopsis(), 'Carolina Nunes')['continuous']['normalized_value'];

        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(CaptureEvaluationSheet::class)->capture(
                $class,
                $period,
                ClassificationScope::Period,
                'Intercalar 1.º Semestre',
                Carbon::parse('2026-11-20'),
                $this->teacher,
                moment: SheetMomentKind::Interim,
            );
        });

        $this->assertSame($accumulatedBefore, $this->accumulatedOf('Carolina Nunes'));
        $this->assertSame(
            $continuousBefore,
            $this->student($this->synopsis(), 'Carolina Nunes')['continuous']['normalized_value'],
        );
    }

    // ------------------------------------------------------- os dois nomes

    #[Test]
    public function the_two_readings_are_named_apart_and_the_formal_one_comes_first(): void
    {
        $legend = ReadingVocabulary::legend();

        // A ORDEM É A HIERARQUIA: o indicador formal primeiro, o analítico
        // depois. Uma legenda ao contrário diria o oposto ao leitor.
        $this->assertSame(ReadingVocabulary::CONTINUOUS, $legend[0]['name']);
        $this->assertSame(ReadingVocabulary::ACCUMULATED_LONG, $legend[1]['name']);

        // E NÃO SE CHAMAM O MESMO. «Acumulado» sozinho deixou de bastar a
        // partir do momento em que as duas leituras aparecem lado a lado.
        $this->assertNotSame(ReadingVocabulary::CONTINUOUS, ReadingVocabulary::ACCUMULATED);
        $this->assertStringContainsString('Desempenho', ReadingVocabulary::ACCUMULATED);
        $this->assertStringContainsString('elementos de avaliação', ReadingVocabulary::ACCUMULATED_LONG);

        // A frase da contínua diz que as intercalares não entram; a do
        // acumulado diz que não é o indicador formal.
        $this->assertStringContainsString('intercalares não entram', ReadingVocabulary::CONTINUOUS_EXPLANATION);
        $this->assertStringContainsString('complementar', ReadingVocabulary::ACCUMULATED_EXPLANATION);
    }

    #[Test]
    public function the_browser_calls_them_exactly_what_the_server_calls_them(): void
    {
        // DUAS CÓPIAS, UM VOCABULÁRIO. O browser tem a sua para não precisar de
        // seis strings em cada `Inertia::render`; este teste é o que impede as
        // duas metades do produto de chamarem nomes diferentes à mesma coluna.
        $typescript = (string) file_get_contents(resource_path('js/lib/readings.ts'));

        foreach ([
            ReadingVocabulary::CONTINUOUS,
            ReadingVocabulary::ACCUMULATED,
            ReadingVocabulary::ACCUMULATED_LONG,
            ReadingVocabulary::CONTINUOUS_EXPLANATION,
            ReadingVocabulary::ACCUMULATED_EXPLANATION,
        ] as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $typescript,
                "«{$phrase}» está em ReadingVocabulary mas não em resources/js/lib/readings.ts.",
            );
        }
    }
}
