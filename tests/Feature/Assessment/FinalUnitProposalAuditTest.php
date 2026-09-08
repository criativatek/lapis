<?php

namespace Tests\Feature\Assessment;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\DomainAppreciationDecision;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildClassSynopsis;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\DecideDomainAppreciation;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\ScaleProposalResolver;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DE ONDE VEM A PROPOSTA NA ÚLTIMA UNIDADE FORMAL, E ONDE VIVE A DECISÃO DO ANO.
 *
 * A REGRA QUE ESTE FICHEIRO PROTEGE, em quatro frases:
 *
 *  (A) o motor das bandas põe cada percentagem onde a escala manda, e uma média
 *      à volta dos 50 % é nível 3 — nunca 2;
 *  (B) a proposta guardada e o resultado calculado ao vivo são duas coisas, e
 *      podem deixar de coincidir sem que nada o assinale;
 *  (C) a meio do ano uma unidade propõe a partir de si própria; a unidade que
 *      FECHA o ano propõe a partir da avaliação contínua final;
 *  (D) o nível atribuído nessa última unidade É o nível final do ano — uma
 *      decisão, uma linha, dois sítios onde se lê.
 *
 * A distinção entre âmbito de PERÍODO e âmbito ACUMULADO continua inteira para
 * as APRECIAÇÕES POR DOMÍNIO, que são outra pergunta (§17), e há aqui um caso a
 * afirmá-lo.
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

    // ----------- (C) a proposta da última unidade é a avaliação contínua final

    /**
     * NA ÚLTIMA UNIDADE FORMAL A PROPOSTA É A AVALIAÇÃO CONTÍNUA FINAL.
     *
     * Fechar o ano não é classificar o último semestre: o que se propõe ao
     * professor quando ele fecha a última unidade é a conclusão do ANO — a
     * média ponderada dos resultados formais de todas as unidades.
     *
     * O RESULTADO ESTANQUE DESSA UNIDADE NÃO DESAPARECE, e este caso afirma as
     * duas coisas ao mesmo tempo: a coluna «Quant.» continua a mostrar o
     * retrato isolado do semestre — é com ele que se lê evolução —, e a
     * proposta ao lado já não nasce dele.
     */
    #[Test]
    public function the_last_unit_proposal_comes_from_the_continuous_average_and_not_from_its_own_result(): void
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

            // O retrato do semestre continua a ser o retrato do semestre.
            if ((string) $standalone !== (string) $continuous['normalized_value']) {
                $divergences++;
            }

            // E a proposta guardada segue a MÉDIA DO ANO, que é a mesma que o
            // bloco «Avaliação Contínua Final» do Quadro mostra.
            $this->assertSame(
                $continuous['level']['scale_level_id'] ?? null,
                $row['classification']['proposed_scale_level_id'] ?? null,
                "A proposta guardada de «{$row['name']}» segue a avaliação contínua final.",
            );

            // E o valor guardado de que ela nasce é a própria média do ano —
            // lido da linha, porque o payload da pauta só carrega a proposta
            // já lida na escala.
            $stored = $this->asTenant(fn (): ?string => Classification::query()
                ->where('enrollment_id', (int) $row['enrollment_id'])
                ->where('scope', ClassificationScope::Period)
                ->orderByDesc('academic_period_id')
                ->value('proposed_normalized_value'));

            // Comparado às 6 casas da própria coluna: a média é calculada com
            // mais precisão do que `decimal(9,6)` guarda, e exigir aqui a
            // igualdade literal seria afirmar uma coisa sobre o tipo da coluna
            // em vez de sobre a origem do número.
            $this->assertSame(
                number_format((float) $continuous['normalized_value'], 6, '.', ''),
                number_format((float) $stored, 6, '.', ''),
                "O valor de que a proposta de «{$row['name']}» nasce é a média do ano.",
            );
        }

        $this->assertGreaterThan(
            0,
            $divergences,
            'O cenário tem de conter pelo menos um aluno em que as duas leituras dão números diferentes.',
        );
    }

    /**
     * NUMA UNIDADE INTERMÉDIA A PROPOSTA CONTINUA A SER A DELA.
     *
     * A outra metade da regra, e a que impede que a correção acima se espalhe
     * para onde não devia: o 1.º semestre propõe a partir do 1.º semestre. Só a
     * unidade que FECHA o ano muda de fundamento.
     */
    #[Test]
    public function an_intermediate_unit_still_proposes_from_its_own_result(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $first = $class->academicYear->periods->first();

            app(ProposeClassifications::class)->forPeriod($class, $first);

            $sheet = app(BuildEvaluationSheet::class)->for($class, $first);
            $checked = 0;

            foreach ($sheet['students'] as $row) {
                if ($row['classification'] === null || $row['overall']['normalized_value'] === null) {
                    continue;
                }

                $this->assertSame(
                    $row['overall']['scale_level_id'],
                    $row['classification']['proposed_scale_level_id'],
                    "A proposta de «{$row['name']}» no 1.º semestre é a do próprio semestre.",
                );
                $checked++;
            }

            $this->assertGreaterThan(0, $checked, 'O cenário tem de ter propostas no 1.º semestre.');
        });
    }

    // ------------------- (D) uma decisão global, mostrada em dois contextos

    /**
     * O NÍVEL ATRIBUÍDO NA ÚLTIMA UNIDADE É O NÍVEL FINAL DO ANO.
     *
     * Uma decisão, uma linha, dois sítios onde se lê. O professor atribui o
     * nível na Pauta ao fechar o 2.º semestre — `classifications.decide`, âmbito
     * PERÍODO — e o bloco «Avaliação Contínua Final → Global» mostra esse mesmo
     * nível, porque é o mesmo juízo. Não há um segundo ato formal para «o ano»,
     * e por isso não há nenhuma linha de âmbito acumulado a criar.
     *
     * ISTO É SÓ SOBRE A CLASSIFICAÇÃO GLOBAL. A distinção entre a apreciação de
     * um domínio numa unidade e a apreciação final desse domínio no ano fica
     * inteira — são perguntas pedagógicas diferentes, e vivem em linhas
     * separadas de `DomainAppreciationDecision` (§17).
     */
    #[Test]
    public function the_level_assigned_on_the_last_unit_is_the_level_the_synopsis_shows_for_the_year(): void
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

        // E NENHUMA LINHA DE ÂMBITO ACUMULADO É CRIADA para o global: não há
        // segundo ato formal, e por isso não há segunda linha.
        $this->assertNull(
            $accumulatedRow,
            'A decisão global do ano não abre nenhuma linha de âmbito acumulado.',
        );

        // O bloco final do Quadro mostra a decisão que o professor acabou de
        // tomar na Pauta — a mesma, não uma cópia nem uma segunda.
        $continuous = $this->student(
            $this->asTenant(fn (): array => app(BuildClassSynopsis::class)->for($this->schoolClass())),
            $name,
        )['continuous'];

        $this->assertNotNull($continuous['proposal'], 'O bloco final tem proposta.');
        $this->assertNotNull(
            $continuous['decision'],
            'O bloco final vê a decisão que o professor tomou na Pauta.',
        );
        $this->assertSame(
            $levelId,
            (int) $continuous['decision']['final']['scale_level_id'],
            'É o mesmo nível, não outro.',
        );
        $this->assertSame('confirmed', $continuous['decision']['status']);
    }

    /**
     * A DISTINÇÃO ENTRE OS DOIS ÂMBITOS FICA INTEIRA PARA OS DOMÍNIOS.
     *
     * A correção acima é sobre a classificação GLOBAL e só sobre ela. Decidir a
     * apreciação de «Oralidade» no 2.º semestre não é dizer nada sobre
     * «Oralidade» no ano, e as duas continuam a viver em linhas próprias — é o
     * que impede que uma leitura de meio de caminho se leia como uma conclusão
     * que ninguém tirou (§17).
     */
    #[Test]
    public function a_domain_decision_on_the_unit_is_still_not_a_decision_about_the_year(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $last = $class->academicYear->periods->last();
            $enrollment = $class->enrollments()->firstOrFail();
            $domain = $class->profileVersion->domains()->firstOrFail();
            $level = $class->profileVersion->scale->levels->first();

            app(DecideDomainAppreciation::class)->decide(
                $class,
                $last,
                ClassificationScope::Period,
                $enrollment,
                $domain->domain,
                $level->id,
                $this->teacher,
            );

            $rows = DomainAppreciationDecision::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('academic_period_id', $last->getKey())
                ->get()
                ->keyBy(fn ($row): string => $row->scope->value);

            $this->assertTrue($rows->has('period'), 'A apreciação da unidade foi escrita.');
            $this->assertFalse(
                $rows->has('accumulated'),
                'E não se converteu numa conclusão sobre o ano.',
            );
        });
    }
}
