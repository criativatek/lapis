<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\ClassificationScope;
use App\Models\ProfileVersionPeriod;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\ContinuousAssessment;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A AVALIAÇÃO CONTÍNUA É A MÉDIA DOS RESULTADOS FORMAIS, e este ficheiro
 * existe para que ela não possa deixar de o ser em silêncio.
 *
 * O CASO QUE INTERESSA está no próprio cenário de demonstração e é o que separa
 * as duas leituras do ano que o produto tem:
 *
 *   Carolina, 1.º semestre   91.302083 %
 *   Carolina, 2.º semestre   87.500000 %
 *
 *   avaliação contínua       89.401042 %   (a média dos dois)
 *   acumulado do motor       89.726562 %   (os elementos do ano reprocessados)
 *
 * Os dois números são verdadeiros e respondem a perguntas diferentes. O que
 * este ficheiro garante é que o primeiro é o primeiro — e que nenhuma alteração
 * futura o troca pelo segundo sem partir um teste.
 */
class ContinuousAssessmentTest extends TestCase
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

    /**
     * O resultado formal de cada unidade, lido da pauta de cada uma — que é
     * exatamente o que a página faz antes de chamar o serviço.
     *
     * @return array{0: array<string, mixed>, 1: array<int, string>} a leitura, e o mapa nome => enrollment id
     */
    private function continuous(?callable $configure = null): array
    {
        return $this->asTenant(function () use ($configure): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            if ($configure !== null) {
                $configure($class);
                $class = $class->fresh();
            }

            $periods = $class->academicYear->periods()->orderBy('sequence')->get();
            $sheets = app(BuildEvaluationSheet::class);

            $formal = [];
            $names = [];

            foreach ($periods as $period) {
                $sheet = $sheets->for($class, $period, ClassificationScope::Period);
                $row = [];

                foreach ($sheet['students'] as $student) {
                    $row[(int) $student['enrollment_id']] = $student['overall']['normalized_value'];
                    $names[(string) $student['name']] = (int) $student['enrollment_id'];
                }

                $formal[(int) $period->getKey()] = $row;
            }

            return [app(ContinuousAssessment::class)->for($class, $periods, $formal), $names];
        });
    }

    // ------------------------------------------------------------- 2 semestres

    #[Test]
    public function two_semesters_average_the_two_formal_results_and_nothing_else(): void
    {
        [$continuous, $names] = $this->continuous();

        // Duas unidades formais, porque duas foram configuradas. Nada aqui sabe
        // o que é um semestre (§6).
        $this->assertCount(2, $continuous['units']);
        $this->assertSame(['1.º Semestre', '2.º Semestre'], array_column($continuous['units'], 'label'));

        $carolina = $continuous['students'][$names['Carolina Nunes']];

        // (91.302083 + 87.5) / 2
        $this->assertSame(
            '89.4010415000',
            $carolina['normalized_value'],
            'A avaliação contínua é a média dos resultados formais.',
        );
        $this->assertSame(2, $carolina['counted_units']);

        // E NUNCA o acumulado do motor, que é 89.726562 sobre os mesmos alunos.
        // Se um dia estes dois números coincidirem, alguém trocou uma leitura
        // pela outra — e é isso que esta linha impede.
        $this->assertNotSame('89.726562', substr((string) $carolina['normalized_value'], 0, 9));
    }

    #[Test]
    public function every_formal_unit_travels_with_the_result_that_fed_the_average(): void
    {
        [$continuous, $names] = $this->continuous();

        $units = $continuous['students'][$names['Carolina Nunes']]['units'];

        $this->assertCount(2, $units);
        $this->assertSame('91.302083', $units[0]['normalized_value']);
        $this->assertSame('87.500000', $units[1]['normalized_value']);
        $this->assertTrue($units[0]['counted']);
        $this->assertTrue($units[1]['counted']);

        // A parcela traz a sua própria menção na escala do perfil, lida pelo
        // mesmo resolvedor que o resto do produto usa.
        $this->assertNotNull($units[0]['level']);
        $this->assertArrayHasKey('code', $units[0]['level']);
    }

    // ----------------------------------------------- unidades sem resultado

    #[Test]
    public function a_unit_without_a_result_is_left_out_of_the_denominator_and_is_never_a_zero(): void
    {
        [$continuous, $names] = $this->continuous();

        // Filipe entrou a 03/11, depois do único elemento do 1.º semestre: esse
        // semestre não tem resultado para ele. A média dele é a do semestre que
        // viveu, e não uma média puxada para baixo por um zero que ninguém lhe
        // deu (§11.4, §13.3).
        $filipe = $continuous['students'][$names['Filipe Andrade']];

        $this->assertNull($filipe['units'][0]['normalized_value']);
        $this->assertFalse($filipe['units'][0]['counted']);
        $this->assertSame(1, $filipe['counted_units']);
        $this->assertSame('62.5000000000', $filipe['normalized_value']);
    }

    #[Test]
    public function a_student_with_no_formal_result_at_all_has_no_average_rather_than_zero(): void
    {
        $continuous = $this->asTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $periods = $class->academicYear->periods()->orderBy('sequence')->get();

            // Um aluno sem resultado em nenhuma das unidades. «—» é a resposta
            // verdadeira; 0 seria uma classificação que ninguém atribuiu (§13.3).
            $formal = [
                (int) $periods[0]->getKey() => [7 => null],
                (int) $periods[1]->getKey() => [7 => null],
            ];

            return app(ContinuousAssessment::class)->for($class, $periods, $formal);
        });

        $student = $continuous['students'][7];

        $this->assertSame(0, $student['counted_units']);
        $this->assertNull($student['normalized_value']);
        $this->assertNull($student['level']);
        $this->assertSame('no_result', $student['proposal']['state']);
    }

    // ------------------------------------------------------------ os pesos

    #[Test]
    public function configured_period_weights_are_respected_instead_of_an_equal_share(): void
    {
        [$continuous, $names] = $this->continuous(function (SchoolClass $class): void {
            $periods = $class->academicYear->periods()->orderBy('sequence')->get();

            ProfileVersionPeriod::create([
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'academic_period_id' => $periods[0]->getKey(),
                'period_weight_percent' => '40',
                'contributes_to_accumulated' => true,
            ]);

            ProfileVersionPeriod::create([
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'academic_period_id' => $periods[1]->getKey(),
                'period_weight_percent' => '60',
                'contributes_to_accumulated' => true,
            ]);
        });

        $this->assertSame('40.0000', $continuous['units'][0]['weight_percent']);
        $this->assertSame('60.0000', $continuous['units'][1]['weight_percent']);

        // (91.302083 × 40 + 87.5 × 60) / 100 = 89.0208332
        $carolina = $continuous['students'][$names['Carolina Nunes']];
        $this->assertSame('89.0208332000', $carolina['normalized_value']);
    }

    #[Test]
    public function a_unit_the_profile_excludes_never_enters_the_continuous_average(): void
    {
        [$continuous, $names] = $this->continuous(function (SchoolClass $class): void {
            $periods = $class->academicYear->periods()->orderBy('sequence')->get();

            ProfileVersionPeriod::create([
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'academic_period_id' => $periods[0]->getKey(),
                'contributes_to_accumulated' => false,
            ]);
        });

        // A mesma coluna que decide o que entra no acumulado decide o que entra
        // aqui: uma unidade excluída de um está excluída do outro (§7).
        $this->assertCount(1, $continuous['units']);
        $this->assertSame('2.º Semestre', $continuous['units'][0]['label']);

        $carolina = $continuous['students'][$names['Carolina Nunes']];
        $this->assertSame(1, $carolina['counted_units']);
        $this->assertSame('87.5000000000', $carolina['normalized_value']);
    }

    // -------------------------------------------------------------- 3 períodos

    #[Test]
    public function three_periods_average_three_formal_results(): void
    {
        $continuous = $this->asTenant(function (): array {
            $year = AcademicYear::factory()->recycle($this->teacher->personalOrganization())->create();

            $periods = collect();
            for ($sequence = 1; $sequence <= 3; $sequence++) {
                $periods->push(AcademicPeriod::factory()
                    ->recycle($this->teacher->personalOrganization())
                    ->for($year)
                    ->create(['label' => $sequence.'.º Período', 'sequence' => $sequence]));
            }

            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            // Resultados formais fabricados: o serviço recebe-os já calculados,
            // que é exatamente o contrato dele. O que se está a testar é a média,
            // não o motor — esse tem os seus próprios testes.
            $formal = [
                (int) $periods[0]->getKey() => [1 => '60.000000'],
                (int) $periods[1]->getKey() => [1 => '70.000000'],
                (int) $periods[2]->getKey() => [1 => '80.000000'],
            ];

            return app(ContinuousAssessment::class)->for($class, $periods, $formal);
        });

        // Três unidades porque três foram configuradas — não «dois semestres»
        // com uma terceira ignorada.
        $this->assertCount(3, $continuous['units']);
        $this->assertSame('70.0000000000', $continuous['students'][1]['normalized_value']);
        $this->assertSame(3, $continuous['students'][1]['counted_units']);
    }

    #[Test]
    public function the_service_only_ever_sees_formal_units_and_has_no_way_to_average_an_interim_moment(): void
    {
        // A GARANTIA ESTRUTURAL, e não apenas uma verificação de valores. O
        // serviço recebe `AcademicPeriod` — a unidade formal — e um resultado
        // por unidade. Um momento intercalar não é um `AcademicPeriod`: é um
        // momento DENTRO de um (ver `SheetMomentKind`), e por isso não há forma
        // de o passar a esta assinatura sequer por engano (§7).
        $reflection = new \ReflectionMethod(ContinuousAssessment::class, 'for');
        $parameters = $reflection->getParameters();

        $this->assertSame('periods', $parameters[1]->getName());
        $this->assertSame('formalByPeriod', $parameters[2]->getName());

        // E o serviço não conhece o motor: não pode ir buscar mais nenhum
        // resultado do que os que lhe foram entregues.
        $constructor = new \ReflectionMethod(ContinuousAssessment::class, '__construct');
        $dependencies = array_map(
            fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );

        $this->assertSame(['App\Services\Assessment\ScaleProposalResolver'], $dependencies);
    }
}
