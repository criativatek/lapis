<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\DomainAppreciationDecision;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildClassSynopsis;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Services\Assessment\ContinuousAssessment;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A CONCLUSÃO DO ANO NUM DOMÍNIO, decidida pelo professor.
 *
 * O QUE ESTA FATIA FECHA. A avaliação contínua final de cada domínio já se lia,
 * e a leitura já sabia respeitar um override — mas nenhum ecrã o sabia escrever.
 * A decisão final existia como forma e não como acto.
 *
 * O CASO, com os números do cenário de demonstração:
 *
 *   Escrita · média final  90,0 %  ->  proposta 5 — Muito Bom
 *   O professor conclui     3 — Suficiente
 *
 *   vigente     3     (a decisão)
 *   proposta    5     (intacta)
 *   média    90,0 %   (intacta)
 *
 * O QUE ESTE FICHEIRO DEFENDE:
 *
 *   1. A DECISÃO ESCREVE-SE NO ÂMBITO DO ANO — `scope = accumulated`, na
 *      unidade que o fecha — e nunca reaproveita nem produz uma decisão de
 *      período.
 *   2. NADA MAIS SE MOVE: nem a média, nem a proposta, nem os resultados de
 *      cada unidade, nem as fotografias já guardadas.
 *   3. É REEDITÁVEL E LIMPÁVEL, e cada passo deixa o rasto que já existia para
 *      as decisões por domínio.
 */
class FinalDomainDecisionTest extends TestCase
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

    /** A rota da decisão final, montada dentro do inquilino onde os ulids vivem. */
    private function url(string $student = 'Carolina Nunes', string $domain = 'Escrita'): string
    {
        return $this->asTenant(function () use ($student, $domain): string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $enrollment = $class->enrollments()->with('student.identity')->get()
                ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === $student);

            return "/classes/{$class->ulid}/results/quadro-sintese/dominios/{$enrollment->ulid}/"
                .Domain::where('name', $domain)->firstOrFail()->ulid;
        });
    }

    private function levelId(string $code): int
    {
        return $this->asTenant(fn (): int => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);
    }

    /**
     * A leitura final de um domínio, tal como o Quadro Síntese a recebe.
     *
     * @return array<string, mixed>
     */
    private function reading(string $student = 'Carolina Nunes', string $domain = 'Escrita'): array
    {
        return $this->asTenant(function () use ($student, $domain): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $synopsis = app(BuildClassSynopsis::class)->for($class);
            $domainId = (int) Domain::where('name', $domain)->firstOrFail()->id;

            foreach ($synopsis['students'] as $row) {
                if ($row['name'] === $student) {
                    return $row['continuous_domains'][$domainId];
                }
            }

            $this->fail("Sem leitura final de «{$domain}» para «{$student}».");
        });
    }

    // ------------------------------------------------ 1 e 2: a proposta vigora

    #[Test]
    public function without_a_decision_the_proposal_is_what_is_in_force(): void
    {
        $reading = $this->reading();

        $this->assertSame('90.0000000000', $reading['normalized_value']);
        $this->assertSame('5', $reading['level']['code']);
        $this->assertNull($reading['decision'], 'Não devia existir decisão nenhuma antes de o professor se pronunciar.');

        $this->assertSame(0, $this->asTenant(fn (): int => DomainAppreciationDecision::count()));
    }

    // ------------------------------------------- 3 a 6: o professor decide

    #[Test]
    public function the_teacher_decides_and_the_decision_prevails_without_moving_anything_else(): void
    {
        $this->actingAs($this->teacher)
            ->post($this->url(), ['scale_level_id' => $this->levelId('3')])
            ->assertRedirect();

        $reading = $this->reading();

        // A DECISÃO PREVALECE…
        $this->assertNotNull($reading['decision']);
        $this->assertSame('3', $reading['decision']['final']['code']);
        $this->assertSame('Suficiente', $reading['decision']['final']['label']);

        // …E NADA MAIS SE MOVE. A média é a mesma, a proposta é a mesma.
        $this->assertSame('90.0000000000', $reading['normalized_value']);
        $this->assertSame('5', $reading['level']['code']);

        // As parcelas de que a média é feita também não se mexem.
        $this->assertSame('90.000000', $reading['units'][0]['normalized_value']);
        $this->assertSame('90.000000', $reading['units'][1]['normalized_value']);
    }

    #[Test]
    public function the_decision_is_written_on_the_year_and_never_on_a_period(): void
    {
        $this->actingAs($this->teacher)
            ->post($this->url(), ['scale_level_id' => $this->levelId('3')])
            ->assertRedirect();

        $this->asTenant(function (): void {
            $decisions = DomainAppreciationDecision::all();

            $this->assertCount(1, $decisions);
            $this->assertSame(ClassificationScope::Accumulated, $decisions[0]->scope);

            // NA UNIDADE QUE FECHA O ANO, e é a mesma que a leitura procura —
            // uma decisão escrita noutra unidade não daria erro, ficaria
            // simplesmente invisível.
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $this->assertSame(
                (int) ContinuousAssessment::finalUnitOf($class)->getKey(),
                (int) $decisions[0]->academic_period_id,
            );
        });
    }

    // ---------------------------------------------------------- 7: reeditar

    #[Test]
    public function the_final_decision_is_re_editable_without_limit(): void
    {
        $this->actingAs($this->teacher)->post($this->url(), ['scale_level_id' => $this->levelId('4')]);
        $this->assertSame('4', $this->reading()['decision']['final']['code']);

        $this->actingAs($this->teacher)->post($this->url(), ['scale_level_id' => $this->levelId('2')]);
        $this->assertSame('2', $this->reading()['decision']['final']['code']);

        // UMA linha viva por (matrícula, unidade, âmbito, domínio) — alterar é
        // reescrever, não acumular linhas paralelas.
        $this->assertSame(1, $this->asTenant(fn (): int => DomainAppreciationDecision::count()));

        // E a média continua onde estava, seja qual for a decisão.
        $this->assertSame('90.0000000000', $this->reading()['normalized_value']);
    }

    // -------------------------------------------------------- 8: limpar

    #[Test]
    public function clearing_the_override_returns_to_the_proposal(): void
    {
        $this->actingAs($this->teacher)->post($this->url(), ['scale_level_id' => $this->levelId('3')]);
        $this->assertNotNull($this->reading()['decision']);

        $this->actingAs($this->teacher)
            ->post($this->url(), ['scale_level_id' => null])
            ->assertRedirect();

        // APAGA A LINHA em vez de guardar uma decisão vazia: a ausência de
        // decisão é o estado natural, e é ela que faz a proposta vigorar.
        $this->assertSame(0, $this->asTenant(fn (): int => DomainAppreciationDecision::count()));

        $reading = $this->reading();
        $this->assertNull($reading['decision']);
        $this->assertSame('5', $reading['level']['code']);
    }

    // ------------------------------------------ 9: a decisão de um período

    #[Test]
    public function a_period_decision_and_a_final_decision_never_interfere(): void
    {
        // Uma decisão sobre o 1.º semestre…
        $periodUrl = $this->asTenant(function (): string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->orderBy('sequence')->get()->first();
            $enrollment = $class->enrollments()->with('student.identity')->get()
                ->first(fn (Enrollment $candidate): bool => $candidate->student->identity->display_name === 'Carolina Nunes');

            return "/classes/{$class->ulid}/pauta-avaliacao/{$period->ulid}/dominios/{$enrollment->ulid}/"
                .Domain::where('name', 'Escrita')->firstOrFail()->ulid;
        });

        $this->actingAs($this->teacher)->post($periodUrl, ['scale_level_id' => $this->levelId('2')]);

        // …não é uma decisão sobre o ano.
        $this->assertNull(
            $this->reading()['decision'],
            'Uma decisão de um semestre foi lida como conclusão do ano.',
        );

        // E a conclusão do ano não toca na decisão do semestre.
        $this->actingAs($this->teacher)->post($this->url(), ['scale_level_id' => $this->levelId('4')]);

        $this->asTenant(function (): void {
            $byScope = DomainAppreciationDecision::all()->groupBy(fn ($row): string => $row->scope->value);

            $this->assertCount(1, $byScope['period']);
            $this->assertCount(1, $byScope['accumulated']);
            $this->assertSame('2', $byScope['period'][0]->scaleLevel->code);
            $this->assertSame('4', $byScope['accumulated'][0]->scaleLevel->code);
        });
    }

    // ------------------------------------------------- 10: as fotografias

    #[Test]
    public function a_snapshot_taken_before_the_decision_does_not_change(): void
    {
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $before = $this->asTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->orderBy('sequence')->get()->first();

            $export = app(CaptureEvaluationSheet::class)->capture(
                $class,
                $period,
                ClassificationScope::Period,
                'Fotografia anterior à decisão',
                Carbon::parse('2026-12-10'),
                $this->teacher,
            );

            return ['id' => $export->getKey(), 'hash' => $export->payload_hash, 'payload' => $export->payload];
        });

        $this->actingAs($this->teacher)->post($this->url(), ['scale_level_id' => $this->levelId('3')]);

        $after = $this->asTenant(function () use ($before): array {
            $export = EvaluationSheetExport::findOrFail($before['id']);

            return ['id' => $export->getKey(), 'hash' => $export->payload_hash, 'payload' => $export->payload];
        });

        // O PASSADO NÃO SE REESCREVE. O HASH É A PROVA: é sobre o payload que
        // ele é calculado, e é ele que o produto usa para recusar uma fotografia
        // adulterada.
        $this->assertSame($before['hash'], $after['hash']);

        // E o conteúdo é o mesmo, comparado como ESTRUTURA e não como texto: o
        // MySQL reordena as chaves de uma coluna JSON ao guardá-la, e um
        // `json_encode` dos dois lados compararia a ordem de serialização em vez
        // do que lá está — falharia em MySQL e passaria em SQLite, que é a pior
        // combinação possível para um teste.
        $this->assertEquals($before['payload'], $after['payload']);
    }

    // ------------------------------------------------------ a auditoria

    #[Test]
    public function every_step_leaves_the_trail_the_domain_decisions_already_left(): void
    {
        $this->actingAs($this->teacher)->post($this->url(), ['scale_level_id' => $this->levelId('3')]);
        $this->actingAs($this->teacher)->post($this->url(), ['scale_level_id' => $this->levelId('4')]);
        $this->actingAs($this->teacher)->post($this->url(), ['scale_level_id' => null]);

        $this->asTenant(function (): void {
            $events = AuditEvent::query()
                ->whereIn('event', [
                    'domain-appreciation.decided',
                    'domain-appreciation.redecided',
                    'domain-appreciation.cleared',
                ])
                ->orderBy('id')
                ->get();

            $this->assertSame(
                ['domain-appreciation.decided', 'domain-appreciation.redecided', 'domain-appreciation.cleared'],
                $events->pluck('event')->all(),
            );

            // O ÂMBITO VIAJA NO RASTO, e é o que distingue esta decisão da do
            // período no dia em que alguém for ler o registo.
            foreach ($events as $event) {
                $this->assertSame('accumulated', $event->properties['scope']);
            }

            // E o rasto diz o que mudou, não só que mudou.
            $this->assertSame('3', $events[1]->properties['previous_scale_level_code']);
            $this->assertSame('4', $events[1]->properties['scale_level_code']);
            $this->assertSame('4', $events[2]->properties['previous_scale_level_code']);
            $this->assertSame($this->teacher->id, $events[0]->causer_id);
        });
    }

    // ------------------------------------------------------- a autorização

    #[Test]
    public function a_teacher_from_another_organization_cannot_write_a_final_decision(): void
    {
        $url = $this->url();

        // 404 e não 403: a turma de outra organização não existe para quem
        // pergunta, e dizer «existe mas não podes» já é dizer alguma coisa.
        $this->actingAs(User::factory()->create())->post($url, ['scale_level_id' => 1])->assertNotFound();

        $this->assertSame(0, $this->asTenant(fn (): int => DomainAppreciationDecision::count()));
    }

    #[Test]
    public function an_enrollment_from_another_class_is_not_addressable_here(): void
    {
        [$classUlid, $otherEnrollmentUlid, $domainUlid] = $this->asTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            $other = SchoolClass::factory()->recycle($this->teacher->personalOrganization())->create([
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
                'label' => '7.º Z',
            ]);

            $enrollment = Enrollment::factory()->recycle($this->teacher->personalOrganization())->create([
                'class_id' => $other->getKey(),
            ]);

            return [$class->ulid, $enrollment->ulid, Domain::where('name', 'Escrita')->firstOrFail()->ulid];
        });

        // A MATRÍCULA É DA MESMA ESCOLA E DO MESMO PROFESSOR, e ainda assim não
        // é desta turma — o IDOR que o âmbito de organização não apanha.
        $this->actingAs($this->teacher)
            ->post("/classes/{$classUlid}/results/quadro-sintese/dominios/{$otherEnrollmentUlid}/{$domainUlid}", [
                'scale_level_id' => $this->levelId('3'),
            ])
            ->assertNotFound();

        $this->assertSame(0, $this->asTenant(fn (): int => DomainAppreciationDecision::count()));
    }

    #[Test]
    public function a_domain_outside_this_profile_is_refused(): void
    {
        $stranger = $this->asTenant(fn (): Domain => Domain::factory()
            ->recycle($this->teacher->personalOrganization())
            ->create(['name' => 'Domínio de outra disciplina']));

        $this->actingAs($this->teacher)
            ->post($this->url(domain: 'Domínio de outra disciplina'), ['scale_level_id' => $this->levelId('3')])
            ->assertSessionHasErrors('scale_level_id');

        $this->assertSame(0, $this->asTenant(fn (): int => DomainAppreciationDecision::count()));
        $this->assertNotNull($stranger);
    }

    #[Test]
    public function a_level_from_another_scale_is_refused(): void
    {
        // Uma banda que existe e é perfeitamente válida — noutra escala. É o
        // caso perigoso: o id resolve, e só a escala da turma diz que não.
        $foreign = $this->asTenant(function (): int {
            $other = Scale::factory()->create(['name' => 'Escala de outra turma']);

            $level = new ScaleLevel;
            $level->forceFill([
                'scale_id' => $other->id,
                'code' => 'X',
                'label' => 'Menção de outra escala',
                'sequence' => 1,
            ])->save();

            return (int) $level->id;
        });

        $this->actingAs($this->teacher)
            ->post($this->url(), ['scale_level_id' => $foreign])
            ->assertSessionHasErrors('scale_level_id');

        $this->assertSame(0, $this->asTenant(fn (): int => DomainAppreciationDecision::count()));
    }
}
