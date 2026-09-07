<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\DomainAppreciationDecision;
use App\Models\ProfileVersionPeriod;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Models\User;
use App\Services\Assessment\BuildClassSynopsis;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\ContinuousAssessment;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A AVALIAÇÃO CONTÍNUA FINAL DE CADA DOMÍNIO — a mesma regra, uma escala abaixo.
 *
 * O Quadro Síntese fechava o ano num número global e deixava cada domínio sem
 * conclusão: via-se «Oralidade 77,3 %» num semestre e «47,5 %» no outro, e
 * nenhuma linha dizia em que é que aquilo tinha dado. O desempenho acumulado
 * respondia a outra pergunta — reprocessa elementos brutos — e não servia.
 *
 * O CASO DE REFERÊNCIA, com os números escritos:
 *
 *   Oralidade
 *     1.º semestre   77,346938 %
 *     2.º semestre   47,500000 %
 *     ---------------------------------
 *     Avaliação Contínua Final  62,423469 %
 *
 * O QUE ESTE FICHEIRO DEFENDE:
 *
 *   1. A MÉDIA É A DOS RESULTADOS FORMAIS DE CADA UNIDADE, e de mais nada. Não
 *      o acumulado, não pontos brutos do ano, não fotografias intercalares.
 *   2. OS PESOS SÃO OS CONFIGURADOS quando existem, e a igualdade quando não.
 *   3. NÃO HÁ SEGUNDA FÓRMULA: a leitura por domínio passa pela mesma função
 *      que produz a global, e um teste compara as duas no mesmo cenário.
 */
class ContinuousAssessmentByDomainTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

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
    private function synopsis(?callable $configure = null): array
    {
        return $this->asTenant(function () use ($configure): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            if ($configure !== null) {
                $configure($class);
                $class = $class->fresh();
            }

            return app(BuildClassSynopsis::class)->for($class);
        });
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

    /**
     * @param  array<string, mixed>  $synopsis
     * @return array<string, mixed>
     */
    private function domainReading(array $synopsis, string $name, string $domain): array
    {
        $domainId = (int) $this->asTenant(fn (): int => Domain::where('name', $domain)->firstOrFail()->id);
        $reading = $this->student($synopsis, $name)['continuous_domains'][$domainId] ?? null;

        $this->assertNotNull($reading, "Sem avaliação contínua final para «{$domain}».");

        return $reading;
    }

    /**
     * O id de um domínio pelo nome — um id fixo num teste é uma afirmação sobre
     * a ordem em que o seeder correu, e não sobre o produto.
     */
    private function domainId(string $name): int
    {
        return (int) $this->asTenant(fn (): int => Domain::where('name', $name)->firstOrFail()->id);
    }

    /**
     * Um ano com N unidades formais e resultados por domínio FABRICADOS.
     *
     * O serviço recebe os resultados formais já calculados — é esse o contrato
     * dele — e o que estes casos exercitam é a média, não o motor. Recalcular
     * pelo motor aqui tornaria os números reféns do cenário de demonstração,
     * onde nem todos os domínios têm resultado nos dois semestres.
     *
     * @param  list<array<int, string|null>>  $byUnit  uma entrada por unidade: domain id => valor
     * @param  list<string|null>  $weights  peso de cada unidade, ou nada para a igualdade por omissão
     * @return array<string, mixed>
     */
    private function fabricated(array $byUnit, array $weights = []): array
    {
        return $this->asTenant(function () use ($byUnit, $weights): array {
            $organization = $this->teacher->personalOrganization();
            $year = AcademicYear::factory()->recycle($organization)->create();
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            $formal = [];

            foreach ($byUnit as $index => $values) {
                $period = AcademicPeriod::factory()
                    ->recycle($organization)
                    ->for($year)
                    ->create(['label' => ($index + 1).'.º Semestre', 'sequence' => $index + 1]);

                if (($weights[$index] ?? null) !== null) {
                    ProfileVersionPeriod::create([
                        'assessment_profile_version_id' => $class->assessment_profile_version_id,
                        'academic_period_id' => $period->getKey(),
                        'period_weight_percent' => $weights[$index],
                        'contributes_to_accumulated' => true,
                    ]);
                }

                $row = [];
                foreach ($values as $domainId => $value) {
                    $row[$domainId] = [1 => $value];
                }

                $formal[(int) $period->getKey()] = $row;
            }

            return app(ContinuousAssessment::class)->forDomains(
                $class->fresh(),
                $year->periods()->orderBy('sequence')->get(),
                $formal,
            );
        });
    }

    // ------------------------------------------------- o caso de referência

    #[Test]
    public function the_final_average_of_a_domain_is_the_mean_of_its_formal_results(): void
    {
        $oralidade = $this->domainId('Oralidade');

        $continuous = $this->fabricated([
            [$oralidade => '77.346938'],
            [$oralidade => '47.500000'],
        ]);

        $reading = $continuous['students'][1][$oralidade];

        // AS PARCELAS VIAJAM COM A MÉDIA, para que ela seja reconstruível.
        $this->assertCount(2, $reading['units']);
        $this->assertSame('1.º Semestre', $reading['units'][0]['label']);
        $this->assertSame('2.º Semestre', $reading['units'][1]['label']);
        $this->assertSame('77.346938', $reading['units'][0]['normalized_value']);
        $this->assertSame('47.500000', $reading['units'][1]['normalized_value']);

        // (77,346938 + 47,5) / 2 = 62,423469
        $this->assertSame('62.4234690000', $reading['normalized_value']);
        $this->assertSame(2, $reading['counted_units']);

        // E a proposta sai desta média, lida na escala do próprio perfil —
        // nenhum limiar é escrito aqui.
        $this->assertSame('3', $reading['level']['code']);
        $this->assertSame('Suficiente', $reading['level']['label']);
    }

    #[Test]
    public function configured_period_weights_apply_to_a_domain_too(): void
    {
        $oralidade = $this->domainId('Oralidade');

        $continuous = $this->fabricated(
            [[$oralidade => '60.000000'], [$oralidade => '80.000000']],
            ['40', '60'],
        );

        $this->assertSame('40.0000', $continuous['units'][0]['weight_percent']);
        $this->assertSame('60.0000', $continuous['units'][1]['weight_percent']);

        // (60 × 40 + 80 × 60) / 100 = 72 — e NÃO 70, que é o que a média simples
        // daria. É essa a diferença que os pesos configurados fazem.
        $reading = $continuous['students'][1][$oralidade];
        $this->assertSame('72.0000000000', $reading['normalized_value']);
        $this->assertNotSame('70.0000000000', $reading['normalized_value']);
    }

    #[Test]
    public function three_periods_average_three_formal_results_of_a_domain(): void
    {
        $oralidade = $this->domainId('Oralidade');
        $leitura = $this->domainId('Leitura');

        $continuous = $this->fabricated([
            [$oralidade => '60.000000', $leitura => '90.000000'],
            [$oralidade => '70.000000', $leitura => '90.000000'],
            [$oralidade => '80.000000', $leitura => '90.000000'],
        ]);

        // Três unidades porque três existem, e cada domínio com a SUA média.
        $this->assertCount(3, $continuous['units']);
        $this->assertSame('70.0000000000', $continuous['students'][1][$oralidade]['normalized_value']);
        $this->assertSame(3, $continuous['students'][1][$oralidade]['counted_units']);
        $this->assertSame('90.0000000000', $continuous['students'][1][$leitura]['normalized_value']);
    }

    #[Test]
    public function three_periods_respect_configured_weights_too(): void
    {
        $oralidade = $this->domainId('Oralidade');

        $continuous = $this->fabricated(
            [[$oralidade => '60.000000'], [$oralidade => '70.000000'], [$oralidade => '80.000000']],
            ['20', '30', '50'],
        );

        // (60 × 20 + 70 × 30 + 80 × 50) / 100 = 73
        $this->assertSame('73.0000000000', $continuous['students'][1][$oralidade]['normalized_value']);
    }

    #[Test]
    public function a_unit_without_a_result_leaves_the_domain_denominator_rather_than_counting_as_zero(): void
    {
        $oralidade = $this->domainId('Oralidade');

        $continuous = $this->fabricated([
            [$oralidade => '60.000000'],
            [$oralidade => null],
        ]);

        // 60, e não 30: uma unidade sem resultado não é um zero (§13.3).
        $reading = $continuous['students'][1][$oralidade];
        $this->assertSame('60.0000000000', $reading['normalized_value']);
        $this->assertSame(1, $reading['counted_units']);
    }

    // ------------------------------------------------------ uma só fórmula

    #[Test]
    public function the_domain_reading_uses_the_very_same_average_as_the_global_one(): void
    {
        // NÃO HÁ SEGUNDA FÓRMULA. Se as duas leituras partilham a operação, os
        // mesmos números de entrada dão exactamente o mesmo resultado. Uma cópia
        // da média escrita à parte passaria neste teste no dia em que fosse
        // escrita, e divergiria na primeira alteração feita a só uma delas.
        [$global, $byDomain] = $this->asTenant(function (): array {
            $organization = $this->teacher->personalOrganization();
            $year = AcademicYear::factory()->recycle($organization)->create();

            for ($sequence = 1; $sequence <= 2; $sequence++) {
                AcademicPeriod::factory()->recycle($organization)->for($year)
                    ->create(['label' => $sequence.'.º Semestre', 'sequence' => $sequence]);
            }

            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $periods = $year->periods()->orderBy('sequence')->get();
            $service = app(ContinuousAssessment::class);
            $domainId = (int) Domain::where('name', 'Oralidade')->firstOrFail()->id;

            $values = [
                (int) $periods[0]->getKey() => '77.346938',
                (int) $periods[1]->getKey() => '47.500000',
            ];

            $flat = [];
            $nested = [];
            foreach ($values as $periodId => $value) {
                $flat[$periodId] = [1 => $value];
                $nested[$periodId] = [$domainId => [1 => $value]];
            }

            return [
                $service->for($class, $periods, $flat)['students'][1],
                $service->forDomains($class, $periods, $nested)['students'][1][$domainId],
            ];
        });

        $this->assertSame($global['normalized_value'], $byDomain['normalized_value']);
        $this->assertSame($global['counted_units'], $byDomain['counted_units']);
        $this->assertSame($global['level'], $byDomain['level']);
    }

    // -------------------------------------------------- fim a fim, na turma

    #[Test]
    public function the_synopsis_carries_a_final_average_for_every_domain(): void
    {
        $synopsis = $this->synopsis();

        // Escrita é o domínio do cenário com resultado formal nos DOIS
        // semestres — 90 e 90 —, e é por isso o que fecha a conta inteira.
        $escrita = $this->domainReading($synopsis, 'Carolina Nunes', 'Escrita');

        $this->assertSame('90.000000', $escrita['units'][0]['normalized_value']);
        $this->assertSame('90.000000', $escrita['units'][1]['normalized_value']);
        $this->assertSame('90.0000000000', $escrita['normalized_value']);
        $this->assertSame(2, $escrita['counted_units']);
        $this->assertSame('5', $escrita['level']['code']);

        // Leitura só tem o 1.º semestre: a média é a dele, sobre UMA unidade —
        // o semestre em falta não entra no denominador nem vale zero.
        $leitura = $this->domainReading($synopsis, 'Carolina Nunes', 'Leitura');
        $this->assertSame('93.1250000000', $leitura['normalized_value']);
        $this->assertSame(1, $leitura['counted_units']);

        // E um domínio sem resultado nenhum não tem média — «—», nunca zero.
        $literaria = $this->domainReading($synopsis, 'Carolina Nunes', 'Educação Literária');
        $this->assertNull($literaria['normalized_value']);
        $this->assertNull($literaria['level']);
    }

    #[Test]
    public function the_final_average_of_a_domain_is_not_its_accumulated_performance(): void
    {
        // AS DUAS LEITURAS DO ANO, uma escala abaixo — e o caso onde elas se
        // separam, que é o único que prova alguma coisa.
        //
        // NO CENÁRIO DE DEMONSTRAÇÃO OS DOIS NÚMEROS COINCIDEM, domínio a
        // domínio, porque a evidência de cada um está equilibrada entre os
        // semestres. Coincidirem ali é um acidente do cenário e não uma
        // propriedade do produto — um teste construído sobre essa coincidência
        // não distinguiria as duas leituras coisa nenhuma.
        //
        // Os números abaixo são os da auditoria da 0.135.0 (ver
        // `AccumulatedBreakdownTest`): um domínio com 85/125 num semestre e
        // 7,33/29 no outro dá 59,954545 % de desempenho ACUMULADO — a fração
        // dos elementos todos do ano — e 46,637931 % de avaliação CONTÍNUA, que
        // é a média dos dois resultados formais. É a mesma evidência lida de
        // duas maneiras, e a distância entre os dois é a razão de existirem.
        $oralidade = $this->domainId('Oralidade');

        $continuous = $this->fabricated([
            [$oralidade => '68.000000'],
            [$oralidade => '25.275862'],
        ]);

        $this->assertSame(
            '46.6379310000',
            $continuous['students'][1][$oralidade]['normalized_value'],
        );

        // E NUNCA o acumulado da mesma evidência. Se um dia este número for
        // 59,954545, alguém trocou a fonte da média.
        $this->assertNotSame(
            '59.954545',
            substr((string) $continuous['students'][1][$oralidade]['normalized_value'], 0, 9),
            'A avaliação contínua final do domínio passou a ser o desempenho acumulado.',
        );
    }

    #[Test]
    public function the_two_readings_of_a_domain_coincide_only_when_the_evidence_is_balanced(): void
    {
        // A OUTRA METADE DA MESMA VERDADE, dita para que ninguém leia o teste
        // acima como «têm sempre de ser diferentes». No cenário de demonstração
        // são iguais, e isso está certo: com a evidência distribuída como está,
        // a fração do ano e a média dos semestres dão no mesmo.
        $synopsis = $this->synopsis();

        $accumulated = $this->asTenant(function (): ?string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $last = $class->academicYear->periods->last();
            $domainId = (int) Domain::where('name', 'Escrita')->firstOrFail()->id;

            foreach (app(ClassResultsCalculator::class)->forAccumulated($class, $last) as $row) {
                if (optional($row['enrollment']->student->identity)->display_name !== 'Carolina Nunes') {
                    continue;
                }

                foreach ($row['outcome']->domains as $domain) {
                    if ($domain->domainId === $domainId) {
                        return $domain->normalizedValue;
                    }
                }
            }

            $this->fail('Sem acumulado de Escrita para a Carolina.');
        });

        $this->assertSame('90.000000', $accumulated);
        $this->assertSame(
            '90.0000000000',
            $this->domainReading($synopsis, 'Carolina Nunes', 'Escrita')['normalized_value'],
        );
    }

    // -------------------------------------------------------------- override

    #[Test]
    public function a_final_domain_decision_prevails_without_erasing_the_average(): void
    {
        // O OVERRIDE FINAL DE UM DOMÍNIO vive no mesmo sítio canónico que o
        // global: âmbito ACUMULADO na última unidade do ano. Aqui é escrito à
        // mão de propósito — nenhum ecrã o escreve ainda —, para fixar que,
        // QUANDO existir, prevalece sem apagar nada.
        $synopsis = $this->synopsis(function (SchoolClass $class): void {
            $last = $class->academicYear->periods()->orderBy('sequence')->get()->last();
            $enrollment = $class->enrollments()->with('student.identity')->get()
                ->first(fn ($candidate): bool => $candidate->student->identity->display_name === 'Carolina Nunes');

            DomainAppreciationDecision::create([
                'enrollment_id' => $enrollment->getKey(),
                'academic_period_id' => $last->getKey(),
                'scope' => ClassificationScope::Accumulated,
                'domain_id' => Domain::where('name', 'Escrita')->firstOrFail()->id,
                'scale_level_id' => $class->profileVersion->scale->levels()->where('code', '3')->firstOrFail()->id,
                'decided_by' => $this->teacher->getKey(),
            ]);
        });

        $escrita = $this->domainReading($synopsis, 'Carolina Nunes', 'Escrita');

        // A DECISÃO PREVALECE…
        $this->assertNotNull($escrita['decision']);
        $this->assertSame('3', $escrita['decision']['final']['code']);

        // …E NADA DO RESTO É APAGADO: a média continua a ser a média, e a
        // proposta continua a ser a que a escala dá para ela.
        $this->assertSame('90.0000000000', $escrita['normalized_value']);
        $this->assertSame('5', $escrita['level']['code']);
    }

    #[Test]
    public function a_decision_about_one_semester_is_not_a_decision_about_the_year(): void
    {
        // §17. Uma decisão de âmbito PERÍODO é uma leitura daquele semestre, e
        // não uma conclusão do ano. Reaproveitá-la como override final seria
        // inventar uma associação que o professor nunca fez.
        $synopsis = $this->synopsis(function (SchoolClass $class): void {
            $first = $class->academicYear->periods()->orderBy('sequence')->get()->first();
            $enrollment = $class->enrollments()->with('student.identity')->get()
                ->first(fn ($candidate): bool => $candidate->student->identity->display_name === 'Carolina Nunes');

            DomainAppreciationDecision::create([
                'enrollment_id' => $enrollment->getKey(),
                'academic_period_id' => $first->getKey(),
                'scope' => ClassificationScope::Period,
                'domain_id' => Domain::where('name', 'Escrita')->firstOrFail()->id,
                'scale_level_id' => $class->profileVersion->scale->levels()->where('code', '3')->firstOrFail()->id,
                'decided_by' => $this->teacher->getKey(),
            ]);
        });

        $this->assertNull(
            $this->domainReading($synopsis, 'Carolina Nunes', 'Escrita')['decision'],
            'Uma decisão de um semestre foi tratada como decisão do ano.',
        );
    }

    // --------------------------------------------------------- intercalares

    #[Test]
    public function an_interim_snapshot_never_reaches_the_domain_average(): void
    {
        // A GARANTIA É ESTRUTURAL, como na leitura global: `forDomains` recebe
        // `AcademicPeriod` — a unidade formal — e resultados por unidade. Um
        // momento intercalar não é um `AcademicPeriod`: é um momento DENTRO de
        // um, e não há por onde o passar a esta assinatura nem por engano (§10).
        $reflection = new ReflectionMethod(ContinuousAssessment::class, 'forDomains');
        $parameters = $reflection->getParameters();

        $this->assertSame('periods', $parameters[1]->getName());
        $this->assertSame('formalByPeriod', $parameters[2]->getName());

        // E os valores vêm da pauta VIVA de cada unidade formal, não de uma
        // fotografia: guardar uma intercalar não move a média final.
        $before = $this->domainReading($this->synopsis(), 'Carolina Nunes', 'Escrita')['normalized_value'];

        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $first = $class->academicYear->periods()->orderBy('sequence')->get()->first();

            app(CaptureEvaluationSheet::class)->capture(
                $class,
                $first,
                ClassificationScope::Period,
                'Intercalar 1.º Semestre',
                Carbon::parse('2026-11-20'),
                $this->teacher,
                moment: SheetMomentKind::Interim,
            );
        });

        $this->assertSame(
            $before,
            $this->domainReading($this->synopsis(), 'Carolina Nunes', 'Escrita')['normalized_value'],
        );
    }
}
