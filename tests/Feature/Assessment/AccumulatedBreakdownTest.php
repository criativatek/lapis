<?php

namespace Tests\Feature\Assessment;

use App\Domain\Assessment\Bc;
use App\Domain\Assessment\CalculationOutcome;
use App\Models\AcademicYear;
use App\Models\AssessmentProfileVersion;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\AccumulatedBreakdown;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\ContinuousAssessment;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\RecordScores;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\InstrumentTypesSeeder;
use Database\Seeders\SystemScalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O NÚMERO QUE NÃO SE EXPLICAVA, E A CONTA QUE O EXPLICA.
 *
 * O CASO REAL, fixado com os números escritos porque foi ele que levantou o
 * problema. Um aluno, o domínio «Educação Literária», dois semestres:
 *
 *   1.º semestre      85,00 / 125,00  =  68,000000 %
 *   2.º semestre       7,33 /  29,00  =  25,275862 %
 *
 *   Desempenho acumulado   92,33 / 154,00  =  59,954545 %   →  60 (half_up, 0)
 *   Avaliação contínua     (68,000000 + 25,275862) / 2  =  46,637931 %
 *
 * AS DUAS RESPOSTAS SÃO VERDADEIRAS E RESPONDEM A PERGUNTAS DIFERENTES. Um
 * professor que veja 68 %, 25 % e 60 % lado a lado tem à frente um número que
 * não é a média de dois, e o que faltava não era um tooltip — era a conta. Ela
 * está nos pontos: o 1.º semestre traz 125 das 154 cotações do ano, e por isso
 * ocupa 81,17 % do denominador. Ninguém configurou esse peso; ele é o que as
 * cotações fazem.
 *
 * O QUE ESTE FICHEIRO DEFENDE, por esta ordem:
 *
 *   1. O MOTOR NÃO MUDOU. 59,954545 % é o que `forAccumulated` dá para este
 *      cenário, e uma alteração descuidada a essa semântica parte aqui, alto e
 *      com o número esperado escrito ao lado (§22).
 *
 *   2. A EXPLICAÇÃO RECONSTRÓI O NÚMERO. As parcelas por unidade somam
 *      exatamente o total, e o total dividido reproduz o valor exibido. Uma
 *      decomposição que não fechasse seria pior do que nenhuma: daria ao
 *      professor a impressão de ter percebido uma conta errada.
 *
 *   3. A PROPOSTA FORMAL SAI DA AVALIAÇÃO CONTÍNUA, e nunca do acumulado.
 */
class AccumulatedBreakdownTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();

        $this->seed(SystemScalesSeeder::class);
        $this->seed(InstrumentTypesSeeder::class);

        $this->asTenant(fn () => $this->scenario());
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    /**
     * O CENÁRIO DE REFERÊNCIA, montado pelo caminho real do produto — o mesmo
     * `ProfileBuilder`, o mesmo `InstrumentBuilder`, o mesmo `RecordScores` que
     * um professor usa. Um fixture escrito à mão na base de dados podia gravar
     * uma combinação que a aplicação nunca produziria.
     *
     * UM DOMÍNIO SÓ, com peso 100. Não é uma simplificação do caso: é o que
     * torna o resultado global igual ao do domínio e permite verificar, no
     * mesmo cenário, que a avaliação contínua dá 46,637931 % e o acumulado dá
     * 59,954545 %.
     */
    private function scenario(): void
    {
        $year = AcademicYear::create([
            'label' => '2026/2027',
            'starts_on' => '2026-09-14',
            'ends_on' => '2027-06-30',
            'status' => 'active',
            'country_code' => 'PT',
        ]);

        $year->periods()->createMany([
            ['label' => '1.º Semestre', 'kind' => 'semester', 'sequence' => 1, 'starts_on' => '2026-09-14', 'ends_on' => '2027-01-29', 'status' => 'open'],
            ['label' => '2.º Semestre', 'kind' => 'semester', 'sequence' => 2, 'starts_on' => '2027-02-01', 'ends_on' => '2027-06-16', 'status' => 'open'],
        ]);

        $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Português']);

        $profile = app(ProfileBuilder::class)->create(
            [
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'name' => 'Português – 7.º Ano',
                'description' => 'Cenário de referência da explicabilidade do acumulado.',
            ],
            Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
            [['name' => 'Educação Literária', 'weight' => 100]],
            ['7.º'],
        );

        $version = app(ActivateProfileVersion::class)->activate($profile->draftVersion(), $this->teacher);

        $class = SchoolClass::create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
            'label' => '7.º A',
            'grade_level' => '7.º',
            'status' => 'active',
            'assessment_profile_version_id' => $version->id,
        ]);

        $class->teachers()->attach($this->teacher, ['role' => 'owner']);

        app(StudentEnrollmentService::class)->enrollNew($class, [
            'name' => 'Aluno de Referência',
            'class_number' => 1,
            'enrolled_on' => '2026-09-14',
        ]);

        $domain = Domain::where('name', 'Educação Literária')->firstOrFail();
        $first = $year->periods()->where('sequence', 1)->firstOrFail();
        $second = $year->periods()->where('sequence', 2)->firstOrFail();

        // 1.º semestre — um elemento de 100 pontos, e uma ficha de cinco itens
        // que somam 25. É esta desproporção, e nada mais, que faz o acumulado
        // pender para o primeiro semestre.
        $this->instrument($class, $first->id, 'ED. Lit. 1', '2026-12-16', $domain, [
            ['code' => 'Q1', 'points_possible' => 100, 'earned' => 65],
        ], finish: true);

        // ESTE FICA EM CORREÇÃO, como o do caso real. Um elemento em correção
        // entra no cálculo com as notas que já tem — é o que permite ao
        // professor ver o resultado a formar-se enquanto corrige — e a
        // decomposição tem de o incluir, ou explicaria um número diferente
        // daquele que está no ecrã (§23).
        $this->instrument($class, $first->id, 'Ficha de avaliação — Português', '2026-11-10', $domain, [
            ['code' => 'EL11', 'points_possible' => 5, 'earned' => 5],
            ['code' => 'EL21', 'points_possible' => 6, 'earned' => 3],
            ['code' => 'EL22', 'points_possible' => 5, 'earned' => 4],
            ['code' => 'EL3', 'points_possible' => 4, 'earned' => 4],
            ['code' => 'EL4', 'points_possible' => 5, 'earned' => 4],
        ]);

        // 2.º semestre — um único elemento, de 29 pontos.
        $this->instrument($class, $second->id, 'Teste de Português Intuitivo', '2027-04-14', $domain, [
            ['code' => 'G3', 'points_possible' => 29, 'earned' => 7.33],
        ], finish: true);
    }

    /**
     * @param  list<array{code: string, points_possible: float|int, earned: float|int}>  $items
     */
    private function instrument(
        SchoolClass $class,
        int $periodId,
        string $title,
        string $appliedOn,
        Domain $domain,
        array $items,
        bool $finish = false,
    ): void {
        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $periodId,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => $title,
            'applied_on' => $appliedOn,
            // Nasce em correção porque é o único estado em que as notas se
            // escrevem; o que fica concluído passa a concluído depois de as ter.
            'status' => 'in_correction',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => array_sum(array_column($items, 'points_possible')),
        ], array_map(fn (array $item): array => [
            'code' => $item['code'],
            'label' => $item['code'],
            'points_possible' => $item['points_possible'],
            'domains' => [['domain_id' => $domain->id, 'allocation_percent' => 100]],
        ], $items));

        $byCode = $instrument->items->keyBy('code');
        $enrollment = $class->enrollments()->firstOrFail();

        $cells = [];

        foreach ($items as $item) {
            $cells[] = [
                'enrollment_id' => $enrollment->id,
                'instrument_item_id' => $byCode[$item['code']]->id,
                'result_state' => ResultState::Assessed->value,
                'points_earned' => (float) $item['earned'],
            ];
        }

        app(RecordScores::class)->save($instrument, $cells, $this->teacher);

        if ($finish) {
            $instrument->forceFill(['status' => 'completed'])->save();
        }
    }

    private function class(): SchoolClass
    {
        return SchoolClass::where('label', '7.º A')->firstOrFail();
    }

    private function enrollment(): Enrollment
    {
        return $this->class()->enrollments()->firstOrFail();
    }

    /**
     * A rota da decomposição, montada dentro do inquilino porque é lá que os
     * ulids se leem — e depois pedida de fora, como um browser a pede.
     */
    private function breakdownUrl(?string $domainName = null): string
    {
        return $this->asTenant(function () use ($domainName): string {
            $class = $this->class();
            $period = $class->academicYear->periods()->orderBy('sequence')->get()->last();
            $suffix = $domainName === null
                ? ''
                : '/'.Domain::where('name', $domainName)->firstOrFail()->ulid;

            return "/classes/{$class->ulid}/results/desempenho-acumulado/{$period->ulid}/{$this->enrollment()->ulid}".$suffix;
        });
    }

    private function domainId(): int
    {
        return (int) Domain::where('name', 'Educação Literária')->firstOrFail()->id;
    }

    // ============================================================== o motor

    /**
     * O MOTOR ACUMULADO NÃO MUDOU, e é este teste que o afirma diretamente.
     *
     * Está deliberadamente separado da explicação: se um dia a decomposição e o
     * motor divergirem, quem lê a suite tem de conseguir ver ao primeiro olhar
     * qual dos dois se moveu. Este é o que responde por 59,954545 %.
     */
    #[Test]
    public function the_accumulated_engine_still_pools_the_raw_elements_of_the_year(): void
    {
        $this->asTenant(function (): void {
            $class = $this->class();
            $periods = $class->academicYear->periods()->orderBy('sequence')->get();
            $calculator = app(ClassResultsCalculator::class);
            $domainId = $this->domainId();

            $first = $this->domainOf($calculator->forPeriod($class, $periods[0])[0]['outcome'], $domainId);
            $second = $this->domainOf($calculator->forPeriod($class, $periods[1])[0]['outcome'], $domainId);
            $accumulated = $this->domainOf($calculator->forAccumulated($class, $periods[1])[0]['outcome'], $domainId);

            // 85 / 125
            $this->assertSame('85.0000', $first['points_earned']);
            $this->assertSame('125.0000', $first['points_possible']);
            $this->assertSame('68.000000', $first['normalized_value']);

            // 7,33 / 29
            $this->assertSame('7.3300', $second['points_earned']);
            $this->assertSame('29.0000', $second['points_possible']);
            $this->assertSame('25.275862', $second['normalized_value']);

            // 92,33 / 154 — UMA fração dos elementos do ano, e não a média das
            // duas acima, que daria 46,637931 %.
            $this->assertSame('92.3300', $accumulated['points_earned']);
            $this->assertSame('154.0000', $accumulated['points_possible']);
            $this->assertSame(
                '59.954545',
                $accumulated['normalized_value'],
                'O desempenho acumulado deixou de reprocessar os elementos brutos do ano.',
            );
        });
    }

    // ======================================================= a reconstrução

    #[Test]
    public function the_breakdown_reconstructs_the_accumulated_value_from_its_parts(): void
    {
        $breakdown = $this->asTenant(fn (): array => app(AccumulatedBreakdown::class)->for(
            $this->class(),
            $this->class()->academicYear->periods()->orderBy('sequence')->get()->last(),
            $this->enrollment(),
            $this->domainId(),
        ));

        $this->assertSame('domain', $breakdown['scope']);
        $this->assertSame('Educação Literária', $breakdown['domain']['name']);

        // AS UNIDADES, COM O NOME QUE A ESCOLA LHES DEU — nunca «P1».
        $this->assertSame(['1.º Semestre', '2.º Semestre'], array_column($breakdown['units'], 'label'));

        [$first, $second] = $breakdown['units'];

        $this->assertSame('85.0000', $first['points_earned']);
        $this->assertSame('125.0000', $first['points_possible']);
        $this->assertSame('68.000000', $first['normalized_value']);

        $this->assertSame('7.3300', $second['points_earned']);
        $this->assertSame('29.0000', $second['points_possible']);
        $this->assertSame('25.275862', $second['normalized_value']);

        // O PESO EFETIVO: 125/154 e 29/154. Ninguém o configurou — é o que as
        // cotações fazem, e é a razão de o acumulado não ser a média dos dois.
        $this->assertSame('81.168831', $first['effective_weight_percent']);
        $this->assertSame('18.831168', $second['effective_weight_percent']);

        // A SOMA DAS PARCELAS É O TOTAL, dos dois lados da fração.
        $this->assertSame(
            '92.3300',
            Bc::truncate(Bc::add($first['points_earned'], $second['points_earned']), 4),
        );
        $this->assertSame(
            '154.0000',
            Bc::truncate(Bc::add($first['points_possible'], $second['points_possible']), 4),
        );

        // E A FRAÇÃO REPRODUZ O VALOR EXIBIDO, ao dígito.
        $this->assertSame('92.3300', $breakdown['total']['points_earned']);
        $this->assertSame('154.0000', $breakdown['total']['points_possible']);
        $this->assertSame('59.954545', $breakdown['total']['normalized_value']);
        $this->assertSame(
            $breakdown['total']['normalized_value'],
            Bc::truncate(
                Bc::mul(Bc::div($breakdown['total']['points_earned'], $breakdown['total']['points_possible']), '100'),
                6,
            ),
            'A conta apresentada ao professor não reproduz o valor que o motor deu.',
        );
    }

    #[Test]
    public function the_breakdown_says_what_is_rounded_and_what_is_shown(): void
    {
        $breakdown = $this->asTenant(fn (): array => app(AccumulatedBreakdown::class)->for(
            $this->class(),
            $this->class()->academicYear->periods()->orderBy('sequence')->get()->last(),
            $this->enrollment(),
            $this->domainId(),
        ));

        // 59,954545 % antes; 60 depois. A distância entre os dois números é
        // exatamente o que o professor precisa de ver para não achar que a
        // aplicação lhe está a mostrar outra coisa.
        $this->assertSame('59.954545', $breakdown['total']['normalized_value']);
        $this->assertSame('60', $breakdown['total']['proposed_value']);
        $this->assertSame('half_up', $breakdown['rounding']['mode']);
        $this->assertSame(0, $breakdown['rounding']['scale']);

        // A FASE RELATADA É A APLICADA: o motor arredonda uma vez, na proposta.
        $this->assertSame('final_only', $breakdown['rounding']['stage']);
    }

    // ========================================================= os elementos

    #[Test]
    public function every_element_that_produced_the_number_is_listed_with_its_own_contribution(): void
    {
        $breakdown = $this->asTenant(fn (): array => app(AccumulatedBreakdown::class)->for(
            $this->class(),
            $this->class()->academicYear->periods()->orderBy('sequence')->get()->last(),
            $this->enrollment(),
            $this->domainId(),
        ));

        $byCode = collect($breakdown['elements'])->keyBy('item_code');

        $this->assertEqualsCanonicalizing(
            ['Q1', 'EL11', 'EL21', 'EL22', 'EL3', 'EL4', 'G3'],
            $byCode->keys()->all(),
        );

        // CADA LINHA DIZ DE ONDE VEM: o instrumento, a data e a unidade.
        $this->assertSame('ED. Lit. 1', $byCode['Q1']['instrument_title']);
        $this->assertSame('2026-12-16', $byCode['Q1']['applied_on']);
        $this->assertSame('1.º Semestre', $byCode['Q1']['period_label']);
        $this->assertSame('65.0000', $byCode['Q1']['points_earned']);
        $this->assertSame('100.0000', $byCode['Q1']['points_possible']);

        $this->assertSame('Teste de Português Intuitivo', $byCode['G3']['instrument_title']);
        $this->assertSame('2.º Semestre', $byCode['G3']['period_label']);
        $this->assertSame('7.3300', $byCode['G3']['points_earned']);
        $this->assertSame('29.0000', $byCode['G3']['points_possible']);

        // NADA FICOU DE FORA neste cenário, e a secção diz isso em vez de a
        // omitir: «não considerados» vazio é uma afirmação, não uma ausência.
        $this->assertSame([], $breakdown['excluded']);

        // AS CONTRIBUIÇÕES SOMAM O RESULTADO. Cada uma é truncada às seis casas
        // com que o motor trabalha, por isso a soma bate ao milionésimo e não ao
        // infinito — o que é reconstruível é a fração, e é ela que a linha da
        // fórmula mostra.
        $sum = '0';
        foreach ($breakdown['elements'] as $element) {
            $sum = Bc::add($sum, (string) $element['contribution']);
        }

        $this->assertLessThan(
            0.00001,
            abs((float) $sum - (float) $breakdown['total']['normalized_value']),
            'As contribuições dos elementos não reproduzem o desempenho acumulado.',
        );
    }

    // ================================================ as duas leituras do ano

    #[Test]
    public function the_formal_proposal_comes_from_the_continuous_reading_and_never_from_the_accumulated(): void
    {
        $continuous = $this->asTenant(function (): array {
            $class = $this->class();
            $periods = $class->academicYear->periods()->orderBy('sequence')->get();
            $sheets = app(BuildEvaluationSheet::class);

            $formal = [];
            foreach ($periods as $period) {
                $sheet = $sheets->for($class, $period, ClassificationScope::Period);
                $row = [];
                foreach ($sheet['students'] as $student) {
                    $row[(int) $student['enrollment_id']] = $student['overall']['normalized_value'];
                }
                $formal[(int) $period->getKey()] = $row;
            }

            return app(ContinuousAssessment::class)->for($class, $periods, $formal);
        });

        $student = $continuous['students'][$this->asTenant(fn (): int => (int) $this->enrollment()->id)];

        // (68,000000 + 25,275862) / 2 — os pesos não foram declarados, por isso
        // as duas unidades valem o mesmo, que é o que «média entre o 1.º e o
        // 2.º semestre» quer dizer.
        $this->assertFalse($continuous['weights_declared']);
        $this->assertSame(2, $student['counted_units']);
        $this->assertSame('46.6379310000', $student['normalized_value']);

        // E NÃO SÃO O MESMO NÚMERO. Se um dia coincidirem, alguém trocou uma
        // leitura pela outra.
        $this->assertNotSame(
            '59.954545',
            substr((string) $student['normalized_value'], 0, 9),
            'A avaliação contínua passou a ser o desempenho acumulado.',
        );
    }

    #[Test]
    public function the_interim_moments_never_reach_the_continuous_average(): void
    {
        // A avaliação contínua só conhece `AcademicPeriod`, que é a unidade
        // FORMAL. Um momento intercalar é um momento DENTRO de um período e não
        // tem como entrar nesta média — é a estrutura que o impede, e não uma
        // condição escrita algures que alguém possa remover.
        $continuous = $this->asTenant(function (): array {
            $class = $this->class();
            $periods = $class->academicYear->periods()->orderBy('sequence')->get();

            return app(ContinuousAssessment::class)->for($class, $periods, []);
        });

        $this->assertCount(2, $continuous['units']);
        $this->assertSame(['1.º Semestre', '2.º Semestre'], array_column($continuous['units'], 'label'));
    }

    // ===================================================== o número global

    #[Test]
    public function the_overall_accumulated_is_explained_by_domains_and_not_by_points(): void
    {
        $breakdown = $this->asTenant(fn (): array => app(AccumulatedBreakdown::class)->for(
            $this->class(),
            $this->class()->academicYear->periods()->orderBy('sequence')->get()->last(),
            $this->enrollment(),
            null,
        ));

        $this->assertSame('overall', $breakdown['scope']);
        $this->assertNull($breakdown['domain']);

        // O GLOBAL NÃO É UMA FRAÇÃO DE PONTOS, e por isso não se apresenta como
        // uma: apresentar-lhe um numerador e um denominador seria descrever uma
        // conta que não é a que corre (§13.4).
        $this->assertNull($breakdown['total']['points_earned']);
        $this->assertNull($breakdown['total']['points_possible']);

        $this->assertCount(1, $breakdown['domains']);
        $this->assertSame('Educação Literária', $breakdown['domains'][0]['name']);
        $this->assertSame('100.0000', $breakdown['weight_total_applied']);

        // Com um único domínio a 100 %, o global é o do domínio — e a
        // contribuição dele é o número inteiro.
        $this->assertSame('59.954545', $breakdown['total']['normalized_value']);
        $this->assertSame('59.954545', $breakdown['domains'][0]['contribution']);
    }

    // ============================================================ segurança

    #[Test]
    public function the_breakdown_of_another_organizations_class_is_not_reachable(): void
    {
        $url = $this->breakdownUrl();
        $intruder = User::factory()->create();

        // 404 e não 403: a turma de outra organização não existe para quem
        // pergunta, e dizer «existe mas não podes» já é dizer alguma coisa.
        $this->actingAs($intruder)->getJson($url)->assertNotFound();
    }

    #[Test]
    public function an_enrollment_from_another_class_is_refused(): void
    {
        [$classUlid, $periodUlid] = $this->asTenant(function (): array {
            $class = $this->class();

            return [$class->ulid, $class->academicYear->periods()->orderBy('sequence')->get()->last()->ulid];
        });

        $otherEnrollmentUlid = $this->asTenant(function (): string {
            $year = AcademicYear::firstOrFail();
            $subject = Subject::factory()->recycle($this->organization)->create(['name' => 'Matemática']);

            $other = SchoolClass::create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'label' => '7.º B',
                'grade_level' => '7.º',
                'status' => 'active',
                'assessment_profile_version_id' => AssessmentProfileVersion::firstOrFail()->id,
            ]);

            $other->teachers()->attach($this->teacher, ['role' => 'owner']);

            app(StudentEnrollmentService::class)->enrollNew($other, [
                'name' => 'Aluno de Outra Turma',
                'class_number' => 1,
                'enrolled_on' => '2026-09-14',
            ]);

            return (string) $other->enrollments()->firstOrFail()->ulid;
        });

        // A MATRÍCULA É DA MESMA ESCOLA E DO MESMO PROFESSOR, e ainda assim não
        // é desta turma. O âmbito de organização não faz esta pergunta — é
        // exatamente o IDOR que uma regra `exists:` deixaria passar (ADR-0002).
        $this->actingAs($this->teacher)
            ->getJson("/classes/{$classUlid}/results/desempenho-acumulado/{$periodUlid}/{$otherEnrollmentUlid}")
            ->assertNotFound();
    }

    #[Test]
    public function the_teacher_of_the_class_gets_the_reconstruction_over_http(): void
    {
        $response = $this->actingAs($this->teacher)->getJson($this->breakdownUrl('Educação Literária'));

        $response->assertOk()
            ->assertJsonPath('scope', 'domain')
            ->assertJsonPath('total.normalized_value', '59.954545')
            ->assertJsonPath('total.proposed_value', '60')
            ->assertJsonPath('units.0.label', '1.º Semestre')
            ->assertJsonPath('units.0.points_possible', '125.0000')
            ->assertJsonPath('units.1.points_possible', '29.0000');
    }

    /**
     * @param  CalculationOutcome  $outcome
     * @return array{points_earned: string, points_possible: string, normalized_value: string|null}
     */
    private function domainOf($outcome, int $domainId): array
    {
        foreach ($outcome->domains as $domain) {
            if ($domain->domainId === $domainId) {
                return [
                    'points_earned' => $domain->pointsEarned,
                    'points_possible' => $domain->pointsPossible,
                    'normalized_value' => $domain->normalizedValue,
                ];
            }
        }

        $this->fail("O domínio {$domainId} não está no resultado.");
    }
}
