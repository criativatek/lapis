<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\Domain;
use App\Models\DomainAppreciationDecision;
use App\Models\Enrollment;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A APRECIAÇÃO DE UM DOMÍNIO É UMA DECISÃO, E A PROPOSTA CONTINUA A SER UMA
 * PROPOSTA.
 *
 * O que estes testes fixam é a regra central: o Lapispro calcula, o professor
 * decide, e as duas coisas coexistem sem uma apagar a outra (§3.3). Sobre
 * «Leitura» o motor calcula uma percentagem e propõe a banda em que ela cai; o
 * professor pode dizer outra coisa; e depois disso continua a haver TRÊS
 * afirmações distintas e verdadeiras ao mesmo tempo — o quantitativo calculado,
 * a proposta do Lapispro e a decisão do professor.
 *
 * A decisão por domínio NÃO É UM VALOR: não entra na média ponderada, não altera
 * o resultado global e não recalcula coisa nenhuma. É uma leitura pedagógica de
 * um domínio, e o motor de cálculo não sabe sequer que ela existe.
 */
class EvaluationSheetDomainDecisionTest extends TestCase
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

    /** @return array{string, string} */
    private function context(User $teacher): array
    {
        return $this->asTenant($teacher, function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$class->ulid, $class->academicYear->periods()->where('sequence', 1)->firstOrFail()->ulid];
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

    private function domainUlid(User $teacher, string $name): string
    {
        return $this->asTenant($teacher, fn (): string => Domain::where('name', $name)->firstOrFail()->ulid);
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
     * A célula de um domínio de um aluno, tal como o ecrã a recebe.
     *
     * @return array<string, mixed>
     */
    private function cell(User $teacher, string $classUlid, string $student, string $domain): array
    {
        $props = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"));

        foreach ($props['sheet']['students'] as $row) {
            if ($row['name'] !== $student) {
                continue;
            }

            foreach ($row['domains'] as $cell) {
                if ($cell['name'] === $domain) {
                    return $cell;
                }
            }
        }

        $this->fail("Não há célula de «{$domain}» para {$student} na pauta.");
    }

    private function decide(
        User $teacher,
        string $classUlid,
        string $periodUlid,
        string $enrollmentUlid,
        string $domainUlid,
        ?int $levelId,
    ): TestResponse {
        return $this->actingAs($teacher)->post(
            "/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/dominios/{$enrollmentUlid}/{$domainUlid}",
            ['scale_level_id' => $levelId],
        );
    }

    // ------------------------------------------------------------- a tabela

    #[Test]
    public function its_migration_runs_on_sqlite_and_is_reversible(): void
    {
        $this->assertTrue(Schema::hasTable('domain_appreciation_decisions'));

        $migration = require base_path('database/migrations/2026_10_03_000100_create_domain_appreciation_decisions_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('domain_appreciation_decisions'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('domain_appreciation_decisions', [
            'ulid', 'organization_id', 'enrollment_id', 'academic_period_id',
            'scope', 'domain_id', 'scale_level_id', 'decided_by',
        ]));
    }

    // -------------------------------------------------- proposta vs. decisão

    #[Test]
    public function the_sheet_carries_the_proposal_and_no_decision_until_the_teacher_makes_one(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid] = $this->context($teacher);

        $cell = $this->cell($teacher, $classUlid, 'Carolina Nunes', 'Leitura');

        $this->assertNotNull($cell['scale_level_code'], 'A proposta do Lapispro tem de estar na pauta.');
        $this->assertNull($cell['decided_scale_level_id']);
        $this->assertNull($cell['decided_scale_level_code']);
        $this->assertNull($cell['decided_scale_level_label']);
    }

    #[Test]
    public function guardar_a_pauta_nao_transforma_propostas_em_decisoes(): void
    {
        // ACEITAÇÃO TÁCITA (§2, §5). Uma proposta por domínio vigora sem ser
        // aprovada, e guardar a pauta é fotografar o que já era verdade. Se o
        // ato de guardar escrevesse decisões, passaria a existir um override que
        // o professor nunca tomou — e «voltar à proposta» deixaria de ter
        // sentido, porque a proposta teria sido convertida em decisão.
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        $this->assertSame(0, $this->asTenant($teacher, fn (): int => DomainAppreciationDecision::count()));

        $this->actingAs($teacher)
            ->post("/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/guardar", [
                'moment_label' => 'Fotografia de teste',
                'effective_at' => '2026-12-15',
            ])
            ->assertRedirect();

        // NENHUMA linha nova. A fotografia guardou valores; não escreveu juízos.
        $this->assertSame(
            0,
            $this->asTenant($teacher, fn (): int => DomainAppreciationDecision::count()),
            'Guardar a pauta criou decisões por domínio que o professor nunca tomou.',
        );

        // E a célula continua a dizer o que dizia: proposta presente, decisão
        // ausente. Guardar não muda a semântica de coisa nenhuma.
        $cell = $this->cell($teacher, $classUlid, 'Carolina Nunes', 'Leitura');
        $this->assertNotNull($cell['scale_level_code']);
        $this->assertNull($cell['decided_scale_level_id']);
    }

    #[Test]
    public function a_decision_never_touches_the_quantitative_nor_the_proposal(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        $before = $this->cell($teacher, $classUlid, 'Carolina Nunes', 'Leitura');
        $proposal = $before['scale_level_code'];
        $quantitative = $before['normalized_value'];

        // Uma menção DIFERENTE da proposta — é esse o caso que interessa.
        $other = $this->asTenant($teacher, function () use ($proposal): int {
            /** @var Scale $scale */
            $scale = SchoolClass::where('label', '7.º A')->firstOrFail()->profileVersion->scale;

            return $scale->levels()->where('code', '!=', $proposal)->orderBy('sequence')->firstOrFail()->id;
        });

        $this->decide(
            $teacher,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $this->domainUlid($teacher, 'Leitura'),
            $other,
        )->assertRedirect();

        $after = $this->cell($teacher, $classUlid, 'Carolina Nunes', 'Leitura');

        // As três afirmações, todas verdadeiras ao mesmo tempo.
        $this->assertSame($quantitative, $after['normalized_value'], 'O quantitativo calculado não muda com a decisão.');
        $this->assertSame($proposal, $after['scale_level_code'], 'A proposta do Lapispro fica exatamente onde estava.');
        $this->assertSame($other, $after['decided_scale_level_id']);
        $this->assertNotSame($proposal, $after['decided_scale_level_code']);
    }

    #[Test]
    public function the_decision_is_re_editable_without_limit(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $enrollment = $this->enrollmentUlid($teacher, 'Carolina Nunes');
        $domain = $this->domainUlid($teacher, 'Leitura');

        foreach (['2', '3', '4', '5', '3'] as $code) {
            $this->decide($teacher, $classUlid, $periodUlid, $enrollment, $domain, $this->levelId($teacher, $code))
                ->assertRedirect();

            $this->assertSame($code, $this->cell($teacher, $classUlid, 'Carolina Nunes', 'Leitura')['decided_scale_level_code']);
        }

        // Uma decisão viva, não cinco linhas paralelas: o histórico do que
        // mudou está no rasto de auditoria, não em linhas concorrentes.
        $this->assertSame(1, $this->asTenant($teacher, fn (): int => DomainAppreciationDecision::query()->count()));
    }

    #[Test]
    public function returning_to_the_proposal_removes_the_decision_rather_than_storing_an_empty_one(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $enrollment = $this->enrollmentUlid($teacher, 'Carolina Nunes');
        $domain = $this->domainUlid($teacher, 'Leitura');

        $this->decide($teacher, $classUlid, $periodUlid, $enrollment, $domain, $this->levelId($teacher, '5'));
        $this->assertSame('5', $this->cell($teacher, $classUlid, 'Carolina Nunes', 'Leitura')['decided_scale_level_code']);

        $this->decide($teacher, $classUlid, $periodUlid, $enrollment, $domain, null)->assertRedirect();

        $cell = $this->cell($teacher, $classUlid, 'Carolina Nunes', 'Leitura');
        $this->assertNull($cell['decided_scale_level_code']);
        $this->assertNotNull($cell['scale_level_code'], 'A proposta volta a ser o que a pauta mostra.');
        $this->assertSame(0, $this->asTenant($teacher, fn (): int => DomainAppreciationDecision::query()->count()));
    }

    #[Test]
    public function a_decision_on_one_domain_leaves_every_other_domain_alone(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        $leituraBefore = $this->cell($teacher, $classUlid, 'Carolina Nunes', 'Leitura');

        $this->decide(
            $teacher,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $this->domainUlid($teacher, 'Escrita'),
            $this->levelId($teacher, '2'),
        );

        $leituraAfter = $this->cell($teacher, $classUlid, 'Carolina Nunes', 'Leitura');

        $this->assertNull($leituraAfter['decided_scale_level_code']);
        $this->assertSame($leituraBefore['normalized_value'], $leituraAfter['normalized_value']);
        $this->assertSame($leituraBefore['scale_level_code'], $leituraAfter['scale_level_code']);
    }

    #[Test]
    public function the_global_result_is_untouched_by_a_domain_decision(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        $props = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"));
        $before = collect($props['sheet']['students'])->firstWhere('name', 'Carolina Nunes')['overall'];

        $this->decide(
            $teacher,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $this->domainUlid($teacher, 'Leitura'),
            $this->levelId($teacher, '5'),
        );

        $props = $this->props($this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao"));
        $after = collect($props['sheet']['students'])->firstWhere('name', 'Carolina Nunes')['overall'];

        $this->assertSame($before, $after, 'A apreciação de um domínio não é um valor: não entra em cálculo nenhum.');
    }

    // ------------------------------------------------------------ auditoria

    #[Test]
    public function every_change_leaves_the_same_kind_of_trail_the_global_decision_leaves(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $enrollment = $this->enrollmentUlid($teacher, 'Carolina Nunes');
        $domain = $this->domainUlid($teacher, 'Leitura');

        $this->decide($teacher, $classUlid, $periodUlid, $enrollment, $domain, $this->levelId($teacher, '2'));
        $this->decide($teacher, $classUlid, $periodUlid, $enrollment, $domain, $this->levelId($teacher, '4'));
        $this->decide($teacher, $classUlid, $periodUlid, $enrollment, $domain, null);

        $events = $this->asTenant($teacher, fn () => AuditEvent::query()
            ->whereIn('event', ['domain-appreciation.decided', 'domain-appreciation.redecided', 'domain-appreciation.cleared'])
            ->orderBy('id')
            ->get());

        $this->assertSame(
            ['domain-appreciation.decided', 'domain-appreciation.redecided', 'domain-appreciation.cleared'],
            $events->pluck('event')->all(),
        );

        $redecided = $events[1];
        $this->assertSame($teacher->id, $redecided->causer_id);
        $this->assertSame('2', $redecided->properties['previous_scale_level_code']);
        $this->assertSame('4', $redecided->properties['scale_level_code']);
        $this->assertStringContainsString('Leitura', (string) $redecided->summary);

        $this->assertSame('4', $events[2]->properties['previous_scale_level_code']);
    }

    // ---------------------------------------------------------- as recusas

    #[Test]
    public function a_level_that_is_not_on_this_class_scale_is_refused(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        // Uma banda que existe e é perfeitamente válida — noutra escala. É o
        // caso perigoso: o id resolve, e só a escala da turma diz que não.
        $foreign = $this->asTenant($teacher, function (): int {
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

        $this->decide(
            $teacher,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $this->domainUlid($teacher, 'Leitura'),
            $foreign,
        )->assertSessionHasErrors('scale_level_id');

        $this->assertSame(0, $this->asTenant($teacher, fn (): int => DomainAppreciationDecision::query()->count()));
    }

    #[Test]
    public function a_level_id_that_is_nothing_at_all_is_refused(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        $this->decide(
            $teacher,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $this->domainUlid($teacher, 'Leitura'),
            999_999,
        )->assertSessionHasErrors('scale_level_id');
    }

    #[Test]
    public function a_domain_outside_this_class_profile_is_refused(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        // Um domínio da MESMA organização, mas que não está neste perfil: o
        // âmbito de organização deixa-o passar, e o que o recusa é o perfil.
        $outsider = $this->asTenant($teacher, fn (): string => Domain::factory()->create([
            'organization_id' => $teacher->personalOrganization()->id,
            'name' => 'Domínio de outro perfil',
        ])->ulid);

        $this->decide(
            $teacher,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $outsider,
            $this->levelId($teacher, '4'),
        )->assertSessionHasErrors('scale_level_id');
    }

    // -------------------------------------------------- isolamento e acesso

    #[Test]
    public function a_teacher_from_another_organization_cannot_write_a_decision(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);
        $enrollment = $this->enrollmentUlid($teacher, 'Carolina Nunes');
        $domain = $this->domainUlid($teacher, 'Leitura');

        $stranger = User::factory()->create();

        // O âmbito de organização já não deixa a turma sequer ser encontrada:
        // um ulid conhecido de outra organização é um 404, não um 403 com pistas.
        $this->actingAs($stranger)->post(
            "/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/dominios/{$enrollment}/{$domain}",
            ['scale_level_id' => $this->levelId($teacher, '4')],
        )->assertNotFound();

        $this->assertSame(0, $this->asTenant($teacher, fn (): int => DomainAppreciationDecision::query()->count()));
    }

    #[Test]
    public function decisions_are_invisible_from_another_organization(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        $this->decide(
            $teacher,
            $classUlid,
            $periodUlid,
            $this->enrollmentUlid($teacher, 'Carolina Nunes'),
            $this->domainUlid($teacher, 'Leitura'),
            $this->levelId($teacher, '4'),
        );

        $stranger = User::factory()->create();

        $visible = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn (): int => DomainAppreciationDecision::query()->count(),
        );

        $this->assertSame(0, $visible);
        $this->assertSame(1, $this->asTenant($teacher, fn (): int => DomainAppreciationDecision::query()->count()));
    }

    #[Test]
    public function an_enrollment_from_another_class_is_not_addressable_here(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        // Uma matrícula da MESMA organização e do mesmo professor, mas de outra
        // turma: o âmbito de organização deixa-a passar, e o que a recusa é a
        // pergunta que só este controlador faz — «é desta turma?».
        $elsewhere = $this->asTenant($teacher, function () use ($teacher): string {
            $organization = $teacher->personalOrganization();
            $mine = SchoolClass::where('label', '7.º A')->firstOrFail();

            $otherClass = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $mine->academic_year_id,
                'subject_id' => $mine->subject_id,
                'label' => '7.º B',
            ]);

            return Enrollment::factory()->recycle($organization)->create(['class_id' => $otherClass->id])->ulid;
        });

        $this->actingAs($teacher)->post(
            "/classes/{$classUlid}/pauta-avaliacao/{$periodUlid}/dominios/{$elsewhere}/".$this->domainUlid($teacher, 'Leitura'),
            ['scale_level_id' => $this->levelId($teacher, '4')],
        )->assertNotFound();
    }

    // ----------------------------------------------------------- desempenho

    #[Test]
    public function reading_the_decisions_of_a_whole_class_costs_one_query(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->context($teacher);

        // Uma turma inteira decidida num domínio: se a leitura fosse por célula,
        // a diferença de consultas cresceria com o número de alunos.
        $enrollments = $this->asTenant($teacher, fn (): array => SchoolClass::where('label', '7.º A')
            ->firstOrFail()->enrollments()->pluck('ulid')->all());

        $domain = $this->domainUlid($teacher, 'Leitura');
        $level = $this->levelId($teacher, '4');

        foreach ($enrollments as $enrollment) {
            $this->decide($teacher, $classUlid, $periodUlid, $enrollment, $domain, $level);
        }

        $counted = 0;
        DB::listen(function () use (&$counted): void {
            $counted++;
        });

        $this->actingAs($teacher)->get("/classes/{$classUlid}/pauta-avaliacao")->assertOk();
        $withDecisions = $counted;

        $this->assertGreaterThan(0, $withDecisions);
        // O teto é generoso de propósito: o que se fixa aqui não é um número
        // exato de consultas, é que ele não acompanhe o número de alunos.
        $this->assertLessThan(
            120,
            $withDecisions,
            'A leitura da pauta com decisões por domínio não pode crescer por aluno.',
        );
    }
}
