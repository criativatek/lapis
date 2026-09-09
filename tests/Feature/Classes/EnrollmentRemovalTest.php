<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\EnrollmentHistory;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * REMOVER UM ALUNO DA TURMA — e o que acontece quando já não se pode.
 *
 * Uma inscrição enganada apaga-se. Uma inscrição com história não: todas as
 * chaves estrangeiras que chegam a `enrollments` são RESTRICT de propósito
 * (§13.3), e a base de dados recusa. O que estes testes protegem é o que o
 * professor vê quando isso acontece — uma frase que explica, e não o erro
 * genérico que em produção levou alguém a tentar três vezes seguidas.
 *
 * E, acima de tudo: os dados ficam. Nada é apagado em cascata para que o botão
 * possa funcionar.
 */
class EnrollmentRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function createClass(?User $owner = null): SchoolClass
    {
        $owner ??= $this->user;

        $context = app(CurrentOrganization::class)->runFor($owner->personalOrganization(), fn () => [
            'year' => AcademicYear::factory()->recycle($owner->personalOrganization())->create([
                'starts_on' => '2026-09-14',
                'ends_on' => '2027-06-30',
            ])->id,
            'subject' => Subject::factory()->recycle($owner->personalOrganization())->create()->id,
        ]);

        $this->actingAs($owner)->post('/classes', [
            'label' => '7.º A',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
        ]);

        return SchoolClass::withoutGlobalScope('organization')
            ->where('organization_id', $owner->personalOrganization()->id)
            ->latest('id')
            ->firstOrFail();
    }

    protected function enroll(SchoolClass $class, string $name, ?User $owner = null): Enrollment
    {
        $owner ??= $this->user;

        $this->actingAs($owner)->post("/classes/{$class->ulid}/students", [
            'name' => $name,
            'enrolled_on' => '2026-09-14',
        ]);

        return Enrollment::withoutGlobalScope('organization')
            ->where('class_id', $class->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * A frase que o professor lê. Vive no flash do Inertia, não na sessão
     * comum — a mesma leitura que o resto da suite faz.
     *
     * @return array{type: string, message: string}
     */
    protected function toast(): array
    {
        $flash = session('inertia.flash_data');

        $this->assertIsArray($flash, 'Nenhuma mensagem foi mostrada ao professor.');
        $this->assertArrayHasKey('toast', $flash);

        return $flash['toast'];
    }

    /** Uma avaliação registada — a dependência mais comum, e a do relato de produção. */
    protected function scoreFor(SchoolClass $class, Enrollment $enrollment, ?User $owner = null): StudentItemScore
    {
        $owner ??= $this->user;

        return app(CurrentOrganization::class)->runFor(
            $owner->personalOrganization(),
            function () use ($class, $enrollment, $owner): StudentItemScore {
                $instrument = Instrument::factory()
                    ->recycle($owner->personalOrganization())
                    ->for($class, 'schoolClass')
                    ->create();

                return StudentItemScore::factory()
                    ->recycle($owner->personalOrganization())
                    ->create([
                        'instrument_id' => $instrument->id,
                        'instrument_item_id' => InstrumentItem::factory()->for($instrument)->create()->id,
                        'enrollment_id' => $enrollment->id,
                    ]);
            },
        );
    }

    // ------------------------------------------------- a inscrição enganada

    #[Test]
    public function an_enrollment_with_no_history_is_still_removed(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Enganado');

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}")
            ->assertRedirect();

        $this->assertNull(Enrollment::withoutGlobalScopes()->find($enrollment->id));
    }

    // ------------------------------------------- o aluno que já não está lá

    /**
     * REMOVER DUAS VEZES O MESMO ALUNO NÃO É UM ERRO.
     *
     * `enrollments` não tem soft delete, de propósito: uma inscrição enganada
     * apagada some mesmo. Só que isso torna o ULID irresolúvel no instante
     * seguinte, e o binding implícito respondia a isso com um 404 cru, dentro
     * do modal de erro do Inertia. Foi o que o professor do 7.º B viu ao
     * tentar reconstruir a turma — o botão não se desativava enquanto o pedido
     * estava a caminho, e o segundo clique caía aqui. O estado final é o que
     * ele pediu; a resposta passa a dizê-lo.
     */
    #[Test]
    public function removing_the_same_student_twice_is_answered_in_words_and_never_with_a_404(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Enganado');

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}")
            ->assertRedirect();

        $second = $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}");

        $second->assertRedirect();
        $this->assertNotSame(404, $second->getStatusCode());
        $this->assertNotSame(500, $second->getStatusCode());

        $toast = $this->toast();
        $this->assertSame('success', $toast['type']);
        $this->assertStringContainsString('já não está nesta turma', $toast['message']);
    }

    #[Test]
    public function a_ulid_that_never_existed_removes_nobody_and_says_so(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Que Fica');

        $response = $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/".Str::ulid()->toString());

        $response->assertRedirect();
        $this->assertNotSame(404, $response->getStatusCode());
        $this->assertNotNull(Enrollment::withoutGlobalScopes()->find($enrollment->id));
    }

    /**
     * A recusa por história continua a ser uma recusa, mesmo repetida.
     *
     * Um separador aberto há uma hora mostra o botão como estava. Carregar
     * nele outra vez não pode encontrar nem um 404 nem um 500 — encontra a
     * mesma frase, e o aluno continua na pauta com tudo o que tem.
     */
    #[Test]
    public function a_blocked_removal_stays_blocked_and_readable_when_it_is_retried(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Maria Avaliada');
        $score = $this->scoreFor($class, $enrollment);

        foreach (range(1, 3) as $attempt) {
            $response = $this->actingAs($this->user)
                ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}");

            $response->assertRedirect();
            $this->assertNotSame(404, $response->getStatusCode(), "Tentativa {$attempt}");
            $this->assertNotSame(500, $response->getStatusCode(), "Tentativa {$attempt}");
            $this->assertSame('error', $this->toast()['type'], "Tentativa {$attempt}");
        }

        $this->assertNotNull(Enrollment::withoutGlobalScopes()->find($enrollment->id));
        $this->assertNotNull(StudentItemScore::withoutGlobalScopes()->find($score->id));
    }

    // ----------------------------------------- a inscrição que tem história

    #[Test]
    public function an_enrollment_with_scores_is_refused_in_words_instead_of_a_server_error(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Maria Avaliada');
        $score = $this->scoreFor($class, $enrollment);

        $response = $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}");

        // O QUE ESTA FATIA EXISTE PARA GARANTIR. Não é «não apaga» — isso a
        // base de dados já fazia. É que a recusa chega como uma resposta
        // normal da aplicação, e não como um 500.
        $response->assertRedirect();
        $this->assertNotSame(500, $response->getStatusCode());

        $toast = $this->toast();
        $this->assertSame('error', $toast['type']);
        $this->assertStringContainsString('Maria Avaliada', $toast['message']);
        $this->assertStringContainsString('avaliações registadas', $toast['message']);
        $this->assertStringContainsString('preservados', $toast['message']);

        // A inscrição E a avaliação continuam exactamente onde estavam.
        $this->assertNotNull(Enrollment::withoutGlobalScopes()->find($enrollment->id));
        $this->assertNotNull(StudentItemScore::withoutGlobalScopes()->find($score->id));
    }

    #[Test]
    public function the_refusal_never_suggests_an_action_the_product_does_not_have(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Maria Avaliada');
        $this->scoreFor($class, $enrollment);

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}");

        $message = $this->toast()['message'];

        // NÃO EXISTE, HOJE, NENHUMA AÇÃO MANUAL que passe uma inscrição a
        // «Transferido» ou «Saiu»: esse estado só é escrito ao importar a
        // relação de turma da escola. Uma mensagem que mandasse o professor
        // fazê-lo mandava-o clicar num botão que não está lá — e é
        // precisamente o que este teste impede que alguém acrescente sem
        // primeiro construir a ação.
        foreach (['transferido', 'transferi', 'marque', 'marcar como', 'inative', 'inativar'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                mb_strtolower($message),
                "A mensagem sugere «{$forbidden}», que não corresponde a nenhuma ação existente na aplicação.",
            );
        }
    }

    #[Test]
    public function another_restrict_relation_gives_the_very_same_guarantee(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Rui Registado');

        // «Registos» — e apagado em soft, de propósito: a linha continua lá,
        // a chave estrangeira continua a apontar para a inscrição e o DELETE
        // continuaria a falhar. Uma verificação feita através do modelo (que
        // esconde os soft-deleted) diria que não havia nada.
        $record = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn (): EvidenceRecord => EvidenceRecord::create([
                'class_id' => $class->id,
                'enrollment_id' => $enrollment->id,
                'kind' => EvidenceKind::Difficulty,
                'occurred_at' => '2026-10-01',
                'description' => 'Observação registada em aula.',
                'created_by' => $this->user->id,
            ]),
        );
        $record->delete();

        $response = $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}");

        $response->assertRedirect();
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStringContainsString('registos', $this->toast()['message']);
        $this->assertNotNull(Enrollment::withoutGlobalScopes()->find($enrollment->id));
    }

    #[Test]
    public function several_kinds_of_history_are_all_named_in_one_sentence(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Ana Completa');
        $this->scoreFor($class, $enrollment);

        app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn (): EvidenceRecord => EvidenceRecord::create([
                'class_id' => $class->id,
                'enrollment_id' => $enrollment->id,
                'kind' => EvidenceKind::Difficulty,
                'occurred_at' => '2026-10-01',
                'description' => 'Observação registada em aula.',
                'created_by' => $this->user->id,
            ]),
        );

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}");

        $message = $this->toast()['message'];

        $this->assertStringContainsString('avaliações registadas', $message);
        $this->assertStringContainsString('registos', $message);
        // «a e b», não «a, b» nem «a and b».
        $this->assertStringContainsString(' e ', $message);
    }

    // ---------------------------------------------- a lista tem de ser toda

    #[Test]
    public function every_foreign_key_pointing_at_enrollments_is_accounted_for(): void
    {
        $connection = DB::connection();
        $schema = $connection->getDriverName() === 'mysql' ? $connection->getDatabaseName() : null;

        // AS DUAS LISTAS, E A SOMA TEM DE DAR TUDO. `RELATIONS` é o que
        // BLOQUEIA a remoção; `CLEARED_WITH_ENROLLMENT` é o que SAI com ela.
        // Uma tabela nova com um `enrollment_id` continua a ter de aparecer
        // numa delas — o que este caso guarda é que ninguém a esquece, não
        // que ela tenha de bloquear. Classificá-la é uma decisão, e é para
        // ser tomada de propósito.
        $known = (fn (): array => array_merge(
            array_keys(static::RELATIONS),
            array_keys(static::CLEARED_WITH_ENROLLMENT),
        ))->call(new EnrollmentHistory);

        $pointingAtEnrollments = [];

        foreach (Schema::getTables($schema) as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (strtolower((string) $foreignKey['foreign_table']) === 'enrollments') {
                    $pointingAtEnrollments[] = $table['name'];
                }
            }
        }

        sort($pointingAtEnrollments);
        sort($known);

        // O TESTE QUE IMPORTA DAQUI A UM ANO. Uma tabela nova com um
        // `enrollment_id` — e vão existir, os desdobramentos ainda nem
        // começaram — falha aqui em vez de reabrir o 500 em silêncio.
        $this->assertSame(
            $pointingAtEnrollments,
            array_values(array_unique($known)),
            'Há uma tabela com chave estrangeira para `enrollments` que EnrollmentHistory não conhece.',
        );
    }

    /**
     * O caso que fecha o beco: um aluno acrescentado por engano e metido num
     * grupo do horário continua a poder ser removido.
     *
     * Se as pertenças a grupos estivessem na lista que BLOQUEIA, marcar uma
     * caixa numa secção de arrumação tornaria a inscrição indelével para
     * sempre — e nem tirar o aluno do grupo a desbloquearia, porque a janela
     * fechada continua a ser uma linha.
     */
    #[Test]
    public function a_student_who_only_belongs_to_a_schedule_group_can_still_be_removed(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Rita Enganada');

        app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            function () use ($class, $enrollment): void {
                $group = ClassGroup::create(['class_id' => $class->id, 'label' => 'T1', 'position' => 0]);

                ClassGroupMembership::create([
                    'class_group_id' => $group->id,
                    'enrollment_id' => $enrollment->id,
                    'effective_from' => '2026-09-01',
                    'effective_until' => null,
                ]);
            },
        );

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}")
            ->assertRedirect();

        $this->assertNull(Enrollment::withoutGlobalScopes()->find($enrollment->id));
        $this->assertSame(
            0,
            ClassGroupMembership::withoutGlobalScopes()->where('enrollment_id', $enrollment->id)->count(),
        );
    }

    // ------------------------------------------------------------- tenancy

    #[Test]
    public function a_teacher_from_another_organization_gets_nowhere(): void
    {
        $class = $this->createClass();
        $enrollment = $this->enroll($class, 'Joao Silva');
        $this->scoreFor($class, $enrollment);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}")
            ->assertNotFound();

        $this->assertNotNull(Enrollment::withoutGlobalScopes()->find($enrollment->id));
    }

    #[Test]
    public function an_enrollment_from_another_class_is_not_reachable_through_this_one(): void
    {
        $class = $this->createClass();
        $other = $this->createClass();
        $enrollment = $this->enroll($other, 'Joao Silva');

        $this->actingAs($this->user)
            ->delete("/classes/{$class->ulid}/students/{$enrollment->ulid}")
            ->assertNotFound();

        $this->assertNotNull(Enrollment::withoutGlobalScopes()->find($enrollment->id));
    }

    // ------------------------------------------------- o que a página conta

    #[Test]
    public function the_class_page_says_who_can_still_be_removed(): void
    {
        $class = $this->createClass();
        $removable = $this->enroll($class, 'Aaa Removivel');
        $kept = $this->enroll($class, 'Bbb Avaliada');
        $this->scoreFor($class, $kept);

        $this->actingAs($this->user)->get("/classes/{$class->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('students.0.name', 'Aaa Removivel')
                ->where('students.0.can_be_removed', true)
                ->where('students.1.name', 'Bbb Avaliada')
                ->where('students.1.can_be_removed', false)
                ->etc());

        $this->assertNotNull($removable);
    }

    #[Test]
    public function asking_the_whole_class_costs_the_same_as_asking_one_student(): void
    {
        $class = $this->createClass();

        foreach (range(1, 12) as $number) {
            $this->enroll($class, "Aluno {$number}");
        }

        $history = app(EnrollmentHistory::class);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => $history->idsWithHistoryIn($class),
        );

        // Uma query pelos ids da turma, mais uma por relação. NUNCA uma por
        // aluno: doze alunos ou trinta, o custo é o mesmo — que é a condição
        // sob a qual esta antecipação pode viver no ecrã da turma.
        $this->assertLessThanOrEqual(12, $queries, 'idsWithHistoryIn cresceu com o número de alunos.');
    }
}
