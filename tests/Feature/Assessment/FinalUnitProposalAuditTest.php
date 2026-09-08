<?php

namespace Tests\Feature\Assessment;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildClassSynopsis;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\ScaleProposalResolver;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AUDIT — DE ONDE VEM A PROPOSTA NA ÚLTIMA UNIDADE FORMAL, E ONDE VIVE A
 * DECISÃO DO ANO.
 *
 * Este ficheiro NÃO afirma o que o produto quer. Afirma o que o código faz
 * hoje, para que a diferença entre as duas coisas fique escrita e verificável
 * antes de alguém lhe tocar. Cada asserção aqui é uma frase sobre o estado
 * atual: se uma delas passar a falhar depois de uma correção, é porque a
 * correção mudou exatamente aquilo que se propunha mudar.
 */
class FinalUnitProposalAuditTest extends TestCase
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

    private function schoolClass(): SchoolClass
    {
        return SchoolClass::where('label', '7.º A')->firstOrFail();
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

    // ------------------------------------------- (A) o motor das bandas está certo

    /**
     * O CASO DOS 50,3 %: a banda que o motor devolve é a que a escala declara.
     *
     * O relato de produção — «uma média à volta dos 50 % a propor 2» — não é
     * reproduzível pelo resolver: a Escala 1 a 5 põe o nível 3 em
     * [49,5 ; 69,499999], e é isso que ele responde. Se em produção aparece um
     * 2 ao lado de um valor destes, a divergência NÃO está aqui.
     */
    #[Test]
    public function the_band_resolver_places_a_value_just_over_fifty_on_level_three(): void
    {
        $codes = $this->asTenant(function (): array {
            $scale = $this->schoolClass()->profileVersion->scale()->with('levels')->first();
            $resolver = app(ScaleProposalResolver::class);

            $read = static fn (string $value): ?string => $resolver->bandFor($scale, $value)?->code;

            return [
                '49.499999' => $read('49.499999'),
                '49.500000' => $read('49.500000'),
                '50.300000' => $read('50.300000'),
                '51.000000' => $read('51.000000'),
                '69.499999' => $read('69.499999'),
                '69.500000' => $read('69.500000'),
            ];
        });

        $this->assertSame('2', $codes['49.499999'], 'O topo da banda 2 é 49,499999.');
        $this->assertSame('3', $codes['49.500000'], 'A banda 3 abre exatamente em 49,5.');
        $this->assertSame('3', $codes['50.300000'], '50,3 % é nível 3, nunca 2.');
        $this->assertSame('3', $codes['51.000000'], '51,0 % é nível 3, nunca 2.');
        $this->assertSame('3', $codes['69.499999']);
        $this->assertSame('4', $codes['69.500000']);
    }

    // ------------------------- (B) a proposta persistida pode ficar desatualizada

    /**
     * A PROPOSTA QUE A PAUTA MOSTRA É UMA LINHA GUARDADA, e o quantitativo ao
     * lado dela é calculado ao vivo. Nada os obriga a ser da mesma altura.
     *
     * `BuildEvaluationSheet` lê `classification.proposed_scale_level_id` da
     * tabela `classifications`, escrita por `ProposeClassifications` quando
     * alguém correu «propor»; e lê `overall.scale_level_id` do motor, agora
     * mesmo. Mexer numa cotação depois de propor deixa as duas metades do mesmo
     * ecrã a dizer coisas diferentes — sem erro, sem aviso, e sem nada no ecrã
     * que diga qual delas é a de hoje.
     *
     * É a explicação mais provável para «proposta 2 ao lado de um global de
     * nível 3» sem que o motor esteja errado (ver o caso acima).
     */
    #[Test]
    public function a_stored_proposal_can_disagree_with_the_live_result_beside_it(): void
    {
        [$liveLevelId, $storedLevelId] = $this->asTenant(function (): array {
            $class = $this->schoolClass();
            $period = $class->academicYear->periods->first();

            app(ProposeClassifications::class)->forPeriod($class, $period);

            $classification = Classification::query()
                ->where('academic_period_id', $period->getKey())
                ->where('scope', ClassificationScope::Period)
                ->whereNotNull('proposed_scale_level_id')
                ->firstOrFail();

            // A LINHA GUARDADA É REESCRITA À MÃO, e não por um caminho da
            // aplicação: o que este caso demonstra é que NADA no ecrã volta a
            // compará-la com o motor. Como ela ficou diferente — uma cotação
            // corrigida, um elemento excluído — é outra pergunta.
            $band = $class->profileVersion->scale->levels
                ->firstWhere('id', '!=', $classification->proposed_scale_level_id);
            $classification->forceFill(['proposed_scale_level_id' => $band->id])->save();

            $sheet = app(BuildEvaluationSheet::class)->for($class, $period);

            foreach ($sheet['students'] as $student) {
                if ((int) $student['enrollment_id'] !== (int) $classification->enrollment_id) {
                    continue;
                }

                return [$student['overall']['scale_level_id'], $student['classification']['proposed_scale_level_id']];
            }

            $this->fail('O aluno da classificação não está na pauta.');
        });

        // As duas metades do mesmo ecrã, em desacordo, sem nada que o assinale.
        $this->assertNotSame(
            $liveLevelId,
            $storedLevelId,
            'A pauta serve lado a lado um nível calculado agora e uma proposta guardada antes.',
        );
    }

    // ------------- (C) a proposta da última unidade é a estanque, não a contínua

    /**
     * NA ÚLTIMA UNIDADE FORMAL EXISTEM HOJE DUAS PROPOSTAS, e elas respondem a
     * perguntas diferentes:
     *
     *  - a da `Classification` daquela unidade — `ProposeClassifications` →
     *    `ClassResultsCalculator::forScope(Period)` —, que é o resultado
     *    ESTANQUE do 2.º semestre;
     *  - a do bloco «Avaliação Contínua Final» do Quadro Síntese, que é a banda
     *    da MÉDIA das unidades formais do ano.
     *
     * São dois números, dois níveis possíveis, e nenhum dos dois sabe do outro.
     */
    #[Test]
    public function the_last_unit_proposal_comes_from_its_own_result_and_not_from_the_continuous_average(): void
    {
        $reading = $this->asTenant(function (): array {
            $class = $this->schoolClass();
            $last = $class->academicYear->periods->last();

            app(ProposeClassifications::class)->forPeriod($class, $last);

            $sheet = app(BuildEvaluationSheet::class)->for($class, $last);
            $synopsis = app(BuildClassSynopsis::class)->for($class);

            return [$sheet, $synopsis];
        });

        [$sheet, $synopsis] = $reading;

        $divergences = 0;

        foreach ($sheet['students'] as $row) {
            $continuous = $this->student($synopsis, $row['name'])['continuous'];
            $standalone = $row['overall']['normalized_value'];

            if ($standalone === null || $continuous['normalized_value'] === null) {
                continue;
            }

            // O número da unidade e o número do ano são mesmo diferentes.
            if ((string) $standalone !== (string) $continuous['normalized_value']) {
                $divergences++;
            }

            // E a proposta guardada na Classification é a da UNIDADE: nasce do
            // `overall` estanque desta pauta, nunca da média contínua.
            $this->assertSame(
                $row['overall']['scale_level_id'],
                $row['classification']['proposed_scale_level_id'] ?? null,
                "A proposta guardada de «{$row['name']}» segue o resultado estanque da unidade.",
            );
        }

        $this->assertGreaterThan(
            0,
            $divergences,
            'O cenário tem de conter pelo menos um aluno em que as duas leituras dão números diferentes.',
        );
    }

    // --------------------- (D) a decisão do ano é uma SEGUNDA linha, nunca escrita

    /**
     * O QUADRO SÍNTESE LÊ UMA DECISÃO QUE NENHUM ECRÃ ESCREVE.
     *
     * `ContinuousAssessment::accumulatedDecisions()` procura uma `Classification`
     * na última unidade com `scope = accumulated`. O endpoint canónico
     * (`classifications.decide`) escreve `scope = period` — é o seu valor por
     * omissão, e nem a Pauta nem Resultados nem Classificações enviam outro.
     *
     * Resultado: o professor atribui o nível na Pauta, a linha do 2.º semestre
     * fica decidida, e o bloco «Avaliação Contínua Final → Global → Nível»
     * continua a mostrar «—», porque a linha que ele lê nunca chegou a existir.
     * Duas linhas para a mesma conclusão do ano, uma delas por preencher.
     */
    #[Test]
    public function the_canonical_decision_writes_the_period_row_while_the_synopsis_reads_the_accumulated_one(): void
    {
        $names = $this->asTenant(function (): array {
            $class = $this->schoolClass();
            $last = $class->academicYear->periods->last();

            app(ProposeClassifications::class)->forPeriod($class, $last);

            $classification = Classification::query()
                ->where('academic_period_id', $last->getKey())
                ->where('scope', ClassificationScope::Period)
                ->whereNotNull('proposed_scale_level_id')
                ->firstOrFail();

            return [
                $class->ulid,
                $last->ulid,
                $classification->enrollment->ulid,
                (int) $classification->enrollment_id,
                (int) $last->getKey(),
                (int) $classification->proposed_scale_level_id,
                (string) $classification->enrollment->student->identity->display_name,
            ];
        });

        [$classUlid, $periodUlid, $enrollmentUlid, $enrollmentId, $periodId, $levelId, $name] = $names;

        // O professor decide pelo caminho canónico, exatamente como a Pauta faz.
        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->teacher->personalOrganization()->id])
            ->post("/classes/{$classUlid}/classifications/{$periodUlid}/{$enrollmentUlid}/decide", [
                'final_scale_level_id' => $levelId,
                'final_value' => null,
                'override_reason' => '',
            ])
            ->assertSessionHasNoErrors();

        [$periodRow, $accumulatedRow] = $this->asTenant(fn (): array => [
            Classification::query()
                ->where('enrollment_id', $enrollmentId)
                ->where('academic_period_id', $periodId)
                ->where('scope', ClassificationScope::Period)
                ->first(),
            Classification::query()
                ->where('enrollment_id', $enrollmentId)
                ->where('academic_period_id', $periodId)
                ->where('scope', ClassificationScope::Accumulated)
                ->first(),
        ]);

        // A decisão existe, e está na linha da UNIDADE.
        $this->assertNotNull($periodRow);
        $this->assertSame(ClassificationStatus::Confirmed, $periodRow->status);
        $this->assertSame($levelId, (int) $periodRow->final_scale_level_id);

        // E a linha que o Quadro Síntese lê como «decisão do ano» não existe.
        $this->assertNull(
            $accumulatedRow,
            'Decidir pelo caminho canónico não escreve nenhuma linha de âmbito acumulado.',
        );

        // Por isso o bloco final do Quadro mostra a proposta e um travessão no
        // lugar do nível — apesar de o professor ter acabado de o atribuir.
        $continuous = $this->student(
            $this->asTenant(fn (): array => app(BuildClassSynopsis::class)->for($this->schoolClass())),
            $name,
        )['continuous'];

        $this->assertNotNull($continuous['proposal'], 'O bloco final tem proposta.');
        $this->assertNull(
            $continuous['decision'],
            'O bloco final não vê a decisão que o professor tomou na Pauta.',
        );
    }
}
