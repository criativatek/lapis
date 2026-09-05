<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Decidir a partir da PAUTA DE AVALIAÇÃO.
 *
 * A pauta é o ecrã onde a informação avaliativa toda já está reunida, e por isso
 * é onde o professor atribui. O que estes testes fixam é que isso NÃO criou uma
 * segunda fonte de verdade: a decisão continua a ser escrita pelo endpoint
 * canónico das classificações, na mesma linha, com a mesma validação e com
 * exatamente o mesmo rasto de auditoria — a pauta acrescenta o sítio de onde se
 * decide, nunca uma segunda maneira de guardar uma nota.
 *
 * E fixam a regra de produto que não pode cair: a decisão da pauta ATUAL é
 * sempre reeditável enquanto o professor tiver autorização. Guardar um momento
 * não fecha nada.
 */
class EvaluationSheetDecisionTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): User
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return $teacher;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(User $teacher, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), $callback);
    }

    /**
     * A turma e o seu primeiro período — o contexto de todos os testes daqui.
     *
     * @return array{string, string}
     */
    private function context(User $teacher): array
    {
        return $this->asTenant($teacher, function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            return [$class->ulid, $period->ulid];
        });
    }

    private function propose(User $teacher): void
    {
        $this->asTenant($teacher, function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);
        });
    }

    private function levelId(User $teacher, string $code): int
    {
        return $this->asTenant($teacher, fn (): int => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);
    }

    private function enrollmentUlid(User $teacher, string $name): string
    {
        return $this->asTenant($teacher, function () use ($name): string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return $class->enrollments()->with('student.identity')->get()
                ->first(fn (Enrollment $enrollment): bool => $enrollment->student->identity->display_name === $name)
                ->ulid;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page['props'];
    }

    /**
     * @return array<string, mixed>
     */
    private function student(User $teacher, string $classUlid, string $name): array
    {
        $props = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"));

        foreach ($props['sheet']['students'] as $student) {
            if ($student['name'] === $name) {
                return $student;
            }
        }

        $this->fail("Não há linha para {$name} na pauta.");
    }

    private function decide(User $teacher, string $classUlid, string $periodUlid, string $enrollmentUlid, ?int $levelId): TestResponse
    {
        return $this->actingAs($teacher)->post(
            "/classes/{$classUlid}/classifications/{$periodUlid}/{$enrollmentUlid}/decide",
            ['final_scale_level_id' => $levelId],
        );
    }

    // ------------------------------------------------------------ o endereço

    #[Test]
    public function the_sheet_says_where_each_decision_is_written_and_whether_it_still_may_be(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid] = $this->context($teacher);

        $carolina = $this->student($teacher, $classUlid, 'Carolina Nunes');

        $this->assertNotNull($carolina['enrollment_ulid']);
        $this->assertSame($this->enrollmentUlid($teacher, 'Carolina Nunes'), $carolina['enrollment_ulid']);
        $this->assertTrue($carolina['can_decide']);
    }

    #[Test]
    public function a_student_with_no_classification_row_may_still_be_classified(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid] = $this->context($teacher);

        // Sem propostas geradas não há linha nenhuma — e a ausência de uma
        // proposta nunca foi a ausência de uma decisão por tomar (§7.1).
        $diogo = $this->student($teacher, $classUlid, 'Diogo Ferreira');

        $this->assertNull($diogo['classification']);
        $this->assertTrue($diogo['can_decide']);
        $this->assertFalse($diogo['can_use_proposal']);
    }

    #[Test]
    public function the_decision_scale_reaches_the_screen_with_its_own_words_and_its_closed_list(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid] = $this->context($teacher);

        $props = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"));

        $this->assertSame('Nível atribuído', $props['decision']['label']);
        $this->assertTrue($props['decision']['classifies_by_level']);
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($props['decision']['levels'], 'code'));
        // A menção viaja ao lado do código, para a lista de escolha — nunca no
        // lugar dele.
        $this->assertContains('Suficiente', array_column($props['decision']['levels'], 'label'));
    }

    // ------------------------------------------------------------- a decisão

    #[Test]
    public function the_decision_taken_on_the_sheet_is_written_by_the_canonical_path(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $this->propose($teacher);

        $enrollmentUlid = $this->enrollmentUlid($teacher, 'Carolina Nunes');
        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, $this->levelId($teacher, '3'))
            ->assertRedirect();

        $carolina = $this->student($teacher, $classUlid, 'Carolina Nunes');

        $this->assertSame('confirmed', $carolina['classification']['status']);
        $this->assertSame('3', $carolina['classification']['final_scale_level_code']);
        $this->assertSame('Suficiente', $carolina['classification']['final_scale_level_label']);

        // A LINHA É A MESMA. Uma segunda fonte de verdade seria uma segunda
        // linha viva para o mesmo (aluno, período, âmbito).
        $this->asTenant($teacher, function (): void {
            $live = Classification::query()
                ->whereNot('status', ClassificationStatus::Superseded)
                ->get()
                ->groupBy(fn (Classification $row): string => $row->enrollment_id.':'.$row->academic_period_id.':'.$row->scope->value);

            foreach ($live as $rows) {
                $this->assertCount(1, $rows);
            }
        });
    }

    #[Test]
    public function the_proposal_survives_the_decision_untouched(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $this->propose($teacher);

        $before = $this->student($teacher, $classUlid, 'Carolina Nunes')['classification'];

        $this->decide(
            $teacher,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $this->levelId($teacher, '3'),
        )->assertRedirect();

        $after = $this->student($teacher, $classUlid, 'Carolina Nunes')['classification'];

        $this->assertSame($before['proposed_value'], $after['proposed_value']);
        $this->assertSame($before['proposed_scale_level_id'], $after['proposed_scale_level_id']);
        $this->assertSame($before['proposed_scale_level_code'], $after['proposed_scale_level_code']);
    }

    #[Test]
    public function a_decision_may_be_changed_again_and_the_sheet_follows(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $this->propose($teacher);

        $enrollmentUlid = $this->enrollmentUlid($teacher, 'Carolina Nunes');

        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, $this->levelId($teacher, '3'))->assertRedirect();
        $this->assertSame('3', $this->student($teacher, $classUlid, 'Carolina Nunes')['classification']['final_scale_level_code']);

        // 3 → 4. Ainda pode, e continuará a poder.
        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, $this->levelId($teacher, '4'))->assertRedirect();

        $carolina = $this->student($teacher, $classUlid, 'Carolina Nunes');
        $this->assertSame('4', $carolina['classification']['final_scale_level_code']);
        $this->assertTrue($carolina['can_decide']);
    }

    #[Test]
    public function the_trail_is_the_same_whichever_screen_decided(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $this->propose($teacher);

        $this->decide(
            $teacher,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $this->levelId($teacher, '3'),
        )->assertRedirect();

        $this->asTenant($teacher, function (): void {
            $events = AuditEvent::query()->whereIn('event', [
                'classification.confirmed',
                'classification.overridden',
                'classification.redecided',
            ])->get();

            $this->assertCount(1, $events);
            // A pauta não inventa uma semântica de auditoria própria: o evento é
            // o mesmo que o ecrã de Classificações deixaria — aqui uma decisão
            // diferente da proposta, que é o que «overridden» significa.
            $this->assertSame('classification.overridden', $events->first()->event);
        });
    }

    #[Test]
    public function preparing_the_close_reflects_the_decision_immediately(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $this->propose($teacher);

        $before = $this->decisionsItem($teacher, $classUlid);
        $this->assertStringStartsWith('0 de ', $before['label']);

        $this->decide(
            $teacher,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $this->levelId($teacher, '3'),
        )->assertRedirect();

        // «Preparar fecho» LÊ A PAUTA a cada pedido: não há um segundo checklist
        // a manter em dia, e por isso não há nada por sincronizar.
        $after = $this->decisionsItem($teacher, $classUlid);
        $this->assertStringStartsWith('1 de ', $after['label']);
        $this->assertStringContainsString('níveis atribuídos', $after['label']);
    }

    /**
     * A linha «níveis atribuídos» de «Preparar fecho».
     *
     * @return array<string, mixed>
     */
    private function decisionsItem(User $teacher, string $classUlid): array
    {
        $props = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"));

        foreach ($props['readiness']['items'] as $item) {
            if ($item['key'] === 'decisions') {
                return $item;
            }
        }

        $this->fail('«Preparar fecho» não traz a linha das decisões.');
    }

    // ---------------------------------------------------------- desempenho

    #[Test]
    public function the_sheet_costs_the_same_number_of_queries_whatever_the_class_size(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid] = $this->context($teacher);
        $this->propose($teacher);

        $small = $this->queriesToOpen($teacher, $classUlid);

        // A turma cresce para trinta e tal. Se alguma coisa nesta página fosse
        // por aluno — a morada da decisão, a autoavaliação, a classificação —
        // o número subiria com ela.
        $this->asTenant($teacher, function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            for ($number = 0; $number < 25; $number++) {
                Enrollment::factory()->recycle($class->organization)->create(['class_id' => $class->id]);
            }
        });

        // NÃO CRESCE. Consultas planas — os resultados, as classificações, as
        // autoavaliações, as moradas — pedidas uma vez para a turma inteira.
        // Uma delas que passasse a ser por aluno somaria vinte e cinco aqui.
        $this->assertLessThanOrEqual($small, $this->queriesToOpen($teacher, $classUlid));
    }

    private function queriesToOpen(User $teacher, string $classUlid): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // --------------------------------------------------------- autorização

    #[Test]
    public function a_teacher_of_another_class_cannot_decide_from_this_sheet(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $this->propose($teacher);

        $stranger = User::factory()->create();

        // O mesmo endereço, outro professor. A pauta não abre e a decisão não
        // escreve — e o 404, não o 403, porque a turma não é dele para existir.
        $this->actingAs($stranger)->get("/classes/{$classUlid}/pauta-avaliacao")->assertNotFound();

        $this->decide(
            $stranger,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $this->levelId($teacher, '3'),
        )->assertNotFound();

        $this->asTenant($teacher, function (): void {
            $this->assertSame(
                0,
                Classification::query()->where('status', ClassificationStatus::Confirmed)->count(),
            );
        });
    }

    #[Test]
    public function a_students_ulid_from_another_class_is_not_a_key_to_this_one(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        // Um ulid de matrícula de OUTRA turma da mesma organização. A rota
        // procura a matrícula ATRAVÉS da turma, por isso não há aqui uma porta
        // entre turmas do mesmo professor.
        [$foreign, $foreignId] = $this->asTenant($teacher, function (): array {
            $organization = SchoolClass::where('label', '7.º A')->firstOrFail()->organization;
            $other = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => SchoolClass::where('label', '7.º A')->firstOrFail()->academic_year_id,
            ]);
            $enrollment = Enrollment::factory()->recycle($organization)->create(['class_id' => $other->id]);

            return [$enrollment->ulid, $enrollment->id];
        });

        $this->decide($teacher, $classUlid, $periodUlid, $foreign, $this->levelId($teacher, '3'))
            ->assertNotFound();

        // E nada foi escrito na matrícula que não é desta turma.
        $this->asTenant($teacher, function () use ($foreignId): void {
            $this->assertSame(0, Classification::query()->where('enrollment_id', $foreignId)->count());
        });
    }

    #[Test]
    public function saving_the_sheet_does_not_close_the_decision(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $this->propose($teacher);

        $enrollmentUlid = $this->enrollmentUlid($teacher, 'Carolina Nunes');
        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, $this->levelId($teacher, '3'))->assertRedirect();

        // Dentro do 1.º Semestre do cenário demo (14/09/2026 a 29/01/2027): a
        // data de referência tem de ser uma data que o período contenha, e uma
        // fotografia de um momento que ainda não chegou é recusada.
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->actingAs($teacher)->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
            'moment_label' => 'Momento intercalar',
            'effective_at' => '2026-12-15',
            'scope' => ClassificationScope::Period->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->asTenant($teacher, fn () => $this->assertSame(1, EvaluationSheetExport::query()->count()));

        // GUARDAR UM MOMENTO NÃO É UM FECHO PEDAGÓGICO. A pauta atual continua
        // a ser do professor.
        $carolina = $this->student($teacher, $classUlid, 'Carolina Nunes');
        $this->assertTrue($carolina['can_decide']);

        $this->decide($teacher, $classUlid, $periodUlid, $enrollmentUlid, $this->levelId($teacher, '4'))->assertRedirect();
        $this->assertSame('4', $this->student($teacher, $classUlid, 'Carolina Nunes')['classification']['final_scale_level_code']);
    }
}
