<?php

namespace Tests\Feature\AcademicYears;

use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Exceções letivas» (Fase 5.4) — feriados, interrupções letivas e dias não
 * letivos: as datas em que a aula NÃO acontece.
 *
 * ELAS SÃO ESTRUTURA, E É ISSO QUE ESTE FICHEIRO AFIRMA. Não têm menu próprio,
 * não têm controlador próprio e não têm política própria: nascem, mudam e
 * morrem no MESMO pedido que já criava os períodos de um ano, sob a mesma
 * AcademicYearPolicy e no mesmo endereço. Um teste que precisasse de um segundo
 * endereço para as gravar seria a prova de que este desenho não foi seguido.
 *
 * E NÃO SÃO ACONTECIMENTOS. Vários testes aqui em baixo afirmam explicitamente
 * que `calendar_events` fica exatamente onde estava: um feriado não é uma
 * reunião com outro nome, e as duas tabelas não se tocam em nenhum caminho de
 * código desta fase.
 *
 * NADA AQUI MATERIALIZA AULAS. Esta fase é o modelo, o CRUD e a leitura no
 * calendário — e mais nada. `Lesson` e `RecurringLessonSlot` não são importados,
 * lidos nem escritos por coisa nenhuma daqui.
 */
class AcademicCalendarExceptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'label' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'status' => 'draft',
            'country_code' => 'PT',
            'region_code' => null,
            'periods' => [
                ['label' => '1.º Semestre', 'kind' => 'semester', 'sequence' => 1, 'starts_on' => '2026-09-14', 'ends_on' => '2027-01-29'],
                ['label' => '2.º Semestre', 'kind' => 'semester', 'sequence' => 2, 'starts_on' => '2027-02-01', 'ends_on' => '2027-06-16'],
            ],
        ], $overrides);
    }

    /**
     * The exact shape the real edit page submits for an existing exception —
     * every editable field plus the ulid that identifies it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function exceptionPayload(AcademicCalendarException $exception, array $overrides = []): array
    {
        return array_merge([
            'ulid' => $exception->ulid,
            'type' => $exception->type->value,
            'title' => $exception->title,
            'starts_on' => $exception->starts_on->toDateString(),
            'ends_on' => $exception->ends_on->toDateString(),
            'note' => $exception->note,
        ], $overrides);
    }

    /**
     * A year with its two semesters, created through the real endpoint — the
     * same starting point every test below shares.
     */
    protected function yearFor(User $user): AcademicYear
    {
        $this->actingAs($user)->post('/academic-years', $this->validPayload())
            ->assertSessionHasNoErrors();

        return AcademicYear::firstOrFail();
    }

    // ------------------------------------------------------------- criar

    #[Test]
    public function a_single_day_feriado_is_created_through_the_year_endpoint(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $payload = $this->validPayload([
            'exceptions' => [
                [
                    'type' => 'holiday',
                    'title' => 'Implantação da República',
                    'starts_on' => '2026-10-05',
                    'ends_on' => '2026-10-05',
                    'note' => null,
                ],
            ],
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect('/academic-years');

        $exception = $year->exceptions()->firstOrFail();
        $this->assertSame(AcademicCalendarExceptionType::Holiday, $exception->type);
        $this->assertSame('Implantação da República', $exception->title);
        // UM DIA SÓ É starts_on === ends_on, e não uma coluna booleana a dizer
        // duas vezes o que as duas datas já dizem uma vez.
        $this->assertSame('2026-10-05', $exception->starts_on->toDateString());
        $this->assertSame('2026-10-05', $exception->ends_on->toDateString());
        $this->assertSame($user->personalOrganization()->getKey(), $exception->organization_id);
        // A proveniência é escrita pelo servidor e nunca vem do formulário.
        $this->assertSame(AcademicCalendarExceptionSource::Manual, $exception->source);
    }

    #[Test]
    public function a_multi_day_interrupcao_is_created_as_one_row_and_not_as_one_per_day(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $payload = $this->validPayload([
            'exceptions' => [
                [
                    'type' => 'school_break',
                    'title' => 'Interrupção de Natal',
                    'starts_on' => '2026-12-21',
                    'ends_on' => '2027-01-02',
                    'note' => 'Regresso às aulas a 5 de janeiro.',
                ],
            ],
        ]);

        $this->actingAs($user)
            ->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasNoErrors();

        // UMA LINHA, E NÃO TREZE. Uma interrupção é um intervalo com um nome, e
        // guardá-la dia a dia seria treze coisas onde há uma.
        $this->assertDatabaseCount('academic_calendar_exceptions', 1);

        $exception = $year->exceptions()->firstOrFail();
        $this->assertSame(AcademicCalendarExceptionType::SchoolBreak, $exception->type);
        $this->assertSame('2026-12-21', $exception->starts_on->toDateString());
        $this->assertSame('2027-01-02', $exception->ends_on->toDateString());
        $this->assertSame('Regresso às aulas a 5 de janeiro.', $exception->note);
    }

    #[Test]
    public function a_dia_nao_letivo_is_created_with_its_own_type(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'non_teaching_day', 'title' => 'Dia do agrupamento', 'starts_on' => '2027-03-15', 'ends_on' => '2027-03-15', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(
            AcademicCalendarExceptionType::NonTeachingDay,
            $year->exceptions()->firstOrFail()->type,
        );
    }

    #[Test]
    public function the_three_types_carry_their_own_portuguese_labels(): void
    {
        $this->assertSame('Feriado', AcademicCalendarExceptionType::Holiday->label());
        $this->assertSame('Interrupção letiva', AcademicCalendarExceptionType::SchoolBreak->label());
        $this->assertSame('Dia não letivo', AcademicCalendarExceptionType::NonTeachingDay->label());

        // A palavra curta é o que — com o ícone — mantém as três distinguíveis
        // num ecrã monocromático.
        $this->assertSame('FERIADO', AcademicCalendarExceptionType::Holiday->shortLabel());
        $this->assertSame('INTERRUPÇÃO', AcademicCalendarExceptionType::SchoolBreak->shortLabel());
        $this->assertSame('NÃO LETIVO', AcademicCalendarExceptionType::NonTeachingDay->shortLabel());
    }

    #[Test]
    public function a_year_can_be_created_with_its_exceptions_in_the_same_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/academic-years', $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors()->assertRedirect('/academic-years');

        $this->assertCount(1, AcademicYear::firstOrFail()->exceptions);
    }

    // ------------------------------------------------------------ editar

    #[Test]
    public function editing_an_exception_through_the_same_endpoint_keeps_its_row(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Feriado municipal', 'starts_on' => '2027-06-13', 'ends_on' => '2027-06-13', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $exception = $year->exceptions()->firstOrFail();
        $exceptionId = $exception->id;

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                $this->exceptionPayload($exception, [
                    'type' => 'non_teaching_day',
                    'title' => 'Feriado municipal (renomeado)',
                    'ends_on' => '2027-06-14',
                    'note' => 'Confirmado pela câmara.',
                ]),
            ],
        ]))->assertSessionHasNoErrors()->assertRedirect('/academic-years');

        // A MESMA LINHA, e não uma nova: o ulid carrega a identidade através
        // das gravações, exatamente como o de um período.
        $this->assertDatabaseCount('academic_calendar_exceptions', 1);
        $reloaded = AcademicCalendarException::find($exceptionId);
        $this->assertNotNull($reloaded);
        $this->assertSame($exception->ulid, $reloaded->ulid);
        $this->assertSame(AcademicCalendarExceptionType::NonTeachingDay, $reloaded->type);
        $this->assertSame('Feriado municipal (renomeado)', $reloaded->title);
        $this->assertSame('2027-06-14', $reloaded->ends_on->toDateString());
        $this->assertSame('Confirmado pela câmara.', $reloaded->note);
        // Editar não muda a proveniência: continua a ser o que o professor
        // escreveu, e não passa a ser outra coisa por ter sido gravada.
        $this->assertSame(AcademicCalendarExceptionSource::Manual, $reloaded->source);
    }

    #[Test]
    public function saving_with_no_changes_at_all_is_idempotent(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
                ['type' => 'school_break', 'title' => 'Interrupção de Carnaval', 'starts_on' => '2027-02-15', 'ends_on' => '2027-02-17', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $before = $year->exceptions()->get();
        $idsBefore = $before->pluck('id')->all();

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => $before->map(fn (AcademicCalendarException $e) => $this->exceptionPayload($e))->all(),
        ]))->assertSessionHasNoErrors();

        $this->assertSame($idsBefore, $year->exceptions()->pluck('id')->all());
    }

    #[Test]
    public function a_request_that_does_not_mention_exceptions_leaves_them_alone(): void
    {
        // A REGRA QUE PROTEGE OS PEDIDOS QUE NÃO SABEM DESTA FASE. `exceptions`
        // é `sometimes` e não `required`: a chave ausente significa «este pedido
        // não falou disto», e não «apaga tudo». Um array vazio, esse, remove-as
        // — e é o que o formulário manda quando o professor as tira todas.
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload())
            ->assertSessionHasNoErrors();

        $this->assertCount(1, $year->fresh()->exceptions);
    }

    // ------------------------------------------------------------ remover

    #[Test]
    public function omitting_an_exception_from_the_submitted_array_removes_it(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
                ['type' => 'holiday', 'title' => 'Natal', 'starts_on' => '2026-12-25', 'ends_on' => '2026-12-25', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $exceptions = $year->exceptions()->get();
        $kept = $exceptions->firstWhere('title', 'Natal');
        $removedId = $exceptions->firstWhere('title', 'Todos os Santos')->id;

        // O gesto real do cliente: a exceção simplesmente deixa de ser enviada.
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [$this->exceptionPayload($kept)],
        ]))->assertSessionHasNoErrors()->assertRedirect('/academic-years');

        $this->assertNull(AcademicCalendarException::find($removedId));
        $this->assertDatabaseCount('academic_calendar_exceptions', 1);
    }

    #[Test]
    public function submitting_an_empty_array_removes_every_exception(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload(['exceptions' => []]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
    }

    #[Test]
    public function deleting_a_draft_year_takes_its_exceptions_with_it(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        // A FK é RESTRICT: sem a remoção explícita no controlador, isto rebentava
        // com um erro cru de base de dados em vez de apagar o ano.
        $this->actingAs($user)->delete("/academic-years/{$year->ulid}")
            ->assertRedirect('/academic-years');

        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
        $this->assertDatabaseCount('academic_years', 0);
    }

    // ---------------------------------------------------------- validação

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Invertido', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasErrors('exceptions.0.ends_on');

        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
    }

    #[Test]
    public function a_single_day_exception_is_accepted_where_a_period_of_one_day_would_not_be(): void
    {
        // A DIFERENÇA REAL FACE AOS PERÍODOS, afirmada nos dois sentidos: um
        // período exige `after:` (mais do que um dia), uma exceção aceita
        // `after_or_equal:` — porque um feriado de um dia é o caso mais comum
        // que esta tabela tem.
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Um dia só', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $payload = $this->validPayload();
        $payload['periods'][0]['ends_on'] = $payload['periods'][0]['starts_on'];

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasErrors('periods.0.ends_on');
    }

    #[Test]
    public function an_exception_outside_the_academic_year_is_rejected(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        // Depois do fim do ano.
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Fora do ano', 'starts_on' => '2027-09-05', 'ends_on' => '2027-09-05', 'note' => null],
            ],
        ]))->assertSessionHasErrors('exceptions.0.starts_on');

        // E antes do início.
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'school_break', 'title' => 'Antes do ano', 'starts_on' => '2026-08-20', 'ends_on' => '2026-09-05', 'note' => null],
            ],
        ]))->assertSessionHasErrors('exceptions.0.starts_on');

        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
    }

    #[Test]
    public function an_unknown_type_is_rejected(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'meeting', 'title' => 'Uma reunião', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasErrors('exceptions.0.type');
    }

    #[Test]
    public function a_title_is_required(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => '', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasErrors('exceptions.0.title');
    }

    #[Test]
    public function a_rejected_exception_leaves_the_rest_of_the_same_request_unapplied(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $originalLabel = $year->label;

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'label' => 'Ano renomeado',
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Invertido', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasErrors('exceptions.0.ends_on');

        $this->assertSame($originalLabel, $year->fresh()->label);
    }

    // ------------------------------------------------------- autorização

    #[Test]
    public function a_member_who_does_not_own_the_organization_cannot_create_or_edit_exceptions(): void
    {
        // A MESMA AUTORIZAÇÃO DOS PERÍODOS, E NÃO UMA SEGUNDA. Não há
        // AcademicCalendarExceptionPolicy nenhuma: quem pode reformar a
        // estrutura do ano é quem AcademicYearPolicy diz, e é essa que decide
        // isto também.
        $owner = User::factory()->create();
        $year = $this->yearFor($owner);

        $intruder = User::factory()->create();
        $organization = $owner->personalOrganization();
        $organization->members()->attach($intruder, ['joined_at' => now()]);

        $this->actingAs($intruder)
            ->withSession(['organization_id' => $organization->id])
            ->put("/academic-years/{$year->ulid}", $this->validPayload([
                'exceptions' => [
                    ['type' => 'holiday', 'title' => 'Introduzido por um membro', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
                ],
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
    }

    #[Test]
    public function a_closed_year_accepts_no_exception_at_all(): void
    {
        // A camada pedagógica fica POR CIMA da de propriedade, aqui como nos
        // períodos: um ano encerrado é só de leitura, mesmo para o dono.
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $year->update(['status' => 'closed']);

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Tarde de mais', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertForbidden();

        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
    }

    // ---------------------------------------------------------- tenancy

    #[Test]
    public function another_organizations_exception_never_appears_on_the_edit_page(): void
    {
        $owner = User::factory()->create();
        $year = $this->yearFor($owner);
        $this->actingAs($owner)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Só desta organização', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->get("/academic-years/{$year->ulid}/edit")->assertNotFound();

        // E o dono continua a ver a sua, escrita no payload da própria página.
        $this->actingAs($owner)->get("/academic-years/{$year->ulid}/edit")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $exceptions = $page->toArray()['props']['academicYear']['exceptions'];

                $this->assertCount(1, $exceptions);
                $this->assertSame('Só desta organização', $exceptions[0]['title']);
                // `source` não vai no payload: não é do professor e o formulário
                // nunca a oferece.
                $this->assertArrayNotHasKey('source', $exceptions[0]);
            });
    }

    #[Test]
    public function an_exception_ulid_from_another_organization_is_rejected(): void
    {
        $owner = User::factory()->create();
        $year = $this->yearFor($owner);

        $other = User::factory()->create();
        $this->actingAs($other)->post('/academic-years', $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'De outra organização', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $foreign = AcademicCalendarException::withoutGlobalScope('organization')
            ->where('organization_id', $other->personalOrganization()->getKey())
            ->firstOrFail();
        $originalTitle = $foreign->title;

        $this->actingAs($owner)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['ulid' => $foreign->ulid, 'type' => 'school_break', 'title' => 'Roubada', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasErrors('exceptions.0.ulid');

        $this->assertSame($originalTitle, $foreign->fresh()->title);
    }

    #[Test]
    public function an_exception_ulid_belonging_to_a_different_year_is_rejected(): void
    {
        $user = User::factory()->create();
        $yearA = $this->yearFor($user);

        $this->actingAs($user)->post('/academic-years', $this->validPayload([
            'label' => '2027/2028',
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-08-31',
            'periods' => [
                ['label' => '1.º Semestre', 'kind' => 'semester', 'sequence' => 1, 'starts_on' => '2027-09-14', 'ends_on' => '2028-01-29'],
            ],
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Do ano seguinte', 'starts_on' => '2027-10-05', 'ends_on' => '2027-10-05', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $yearB = AcademicYear::where('label', '2027/2028')->firstOrFail();
        $foreign = $yearB->exceptions()->firstOrFail();
        $originalTitle = $foreign->title;

        $this->actingAs($user)->put("/academic-years/{$yearA->ulid}", $this->validPayload([
            'exceptions' => [
                ['ulid' => $foreign->ulid, 'type' => 'school_break', 'title' => 'Adotada à força', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasErrors('exceptions.0.ulid');

        $foreign->refresh();
        $this->assertSame($originalTitle, $foreign->title);
        $this->assertSame($yearB->getKey(), $foreign->academic_year_id);
    }

    // ----------------------------------------------- não é um acontecimento

    #[Test]
    public function nothing_here_writes_to_the_calendar_events_table(): void
    {
        // UM FERIADO NÃO É UMA REUNIÃO COM OUTRO NOME. As duas tabelas não se
        // tocam em nenhum caminho de código desta fase, e é isto que o afirma.
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
                ['type' => 'school_break', 'title' => 'Interrupção de Natal', 'starts_on' => '2026-12-21', 'ends_on' => '2027-01-02', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, CalendarEvent::withoutGlobalScope('organization')->count());
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('academic_calendar_exceptions', 2);
    }

    // --------------------------------------------------- no calendário

    #[Test]
    public function the_month_view_carries_the_exceptions_and_marks_every_day_they_cover(): void
    {
        [$teacher, $organization, $year] = $this->calendarYear();

        $this->exception($organization, $year, 'school_break', 'Interrupção de Natal', '2026-12-21', '2026-12-31');
        $this->exception($organization, $year, 'holiday', 'Natal', '2026-12-25', '2026-12-25');

        $this->actingAs($teacher)
            ->withSession(['organization_id' => $organization->id, 'academic_year_id' => $year->id])
            ->get('/calendar?month=2026-12')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                // Nomeadas uma vez, por cima da grelha.
                $titles = array_column($props['exceptions'], 'title');
                $this->assertContains('Interrupção de Natal', $titles);
                $this->assertContains('Natal', $titles);

                $days = collect($props['days'])->keyBy('date');

                // Um dia a MEIO da interrupção conhece-a, e não apenas o
                // primeiro: cobrir e não «começar em».
                $this->assertSame('Interrupção de Natal', $days['2026-12-23']['exception']['title']);
                $this->assertSame('INTERRUPÇÃO', $days['2026-12-23']['exception']['type_short_label']);
                // O último dia da interrupção também — a armadilha do
                // whereDate, que já mordeu os períodos desta mesma consulta.
                $this->assertSame('Interrupção de Natal', $days['2026-12-31']['exception']['title']);
                // E um dia fora dela não inventa nenhuma.
                $this->assertNull($days['2026-12-15']['exception']);
            });
    }

    #[Test]
    public function an_exception_crossing_a_month_boundary_shows_in_both_months(): void
    {
        [$teacher, $organization, $year] = $this->calendarYear();

        $this->exception($organization, $year, 'school_break', 'Interrupção de Natal', '2026-12-21', '2027-01-04');

        foreach (['2026-12' => '2026-12-22', '2027-01' => '2027-01-04'] as $month => $probe) {
            $this->actingAs($teacher)
                ->withSession(['organization_id' => $organization->id, 'academic_year_id' => $year->id])
                ->get("/calendar?month={$month}")
                ->assertOk()
                ->assertInertia(function (AssertableInertia $page) use ($probe) {
                    $props = $page->toArray()['props'];

                    $this->assertSame(
                        ['Interrupção de Natal'],
                        array_column($props['exceptions'], 'title'),
                    );

                    $day = collect($props['days'])->firstWhere('date', $probe);
                    $this->assertSame('Interrupção de Natal', $day['exception']['title']);
                });
        }
    }

    #[Test]
    public function the_year_view_counts_non_teaching_days_per_month_and_names_the_exceptions(): void
    {
        [$teacher, $organization, $year] = $this->calendarYear();

        // 21 a 31 de dezembro são onze dias; o Natal cai DENTRO deles, e é o
        // mesmo dia — logo, onze e não doze.
        $this->exception($organization, $year, 'school_break', 'Interrupção de Natal', '2026-12-21', '2026-12-31');
        $this->exception($organization, $year, 'holiday', 'Natal', '2026-12-25', '2026-12-25');
        $this->exception($organization, $year, 'holiday', 'Implantação da República', '2026-10-05', '2026-10-05');

        $this->actingAs($teacher)
            ->withSession(['organization_id' => $organization->id, 'academic_year_id' => $year->id])
            ->get('/calendar/ano')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];
                $months = collect($props['months'])->keyBy('value');

                $this->assertSame(11, $months['2026-12']['non_teaching_days_count']);
                $this->assertCount(2, $months['2026-12']['exception_ulids']);

                $this->assertSame(1, $months['2026-10']['non_teaching_days_count']);
                $this->assertSame(0, $months['2026-11']['non_teaching_days_count']);
                $this->assertSame([], $months['2026-11']['exception_ulids']);

                // 11 + 1, e não 13: um feriado dentro de uma interrupção é UM
                // dia não letivo, não dois.
                $this->assertSame(12, $props['nonTeachingDaysTotal']);

                // E as exceções inteiras vão uma vez, para os cartões dos meses
                // as poderem nomear.
                $this->assertCount(3, $props['exceptions']);
            });
    }

    #[Test]
    public function an_exception_crossing_a_month_boundary_counts_its_own_days_in_each_month(): void
    {
        [$teacher, $organization, $year] = $this->calendarYear();

        // 21 a 31 de dezembro = 11 dias; 1 a 4 de janeiro = 4 dias.
        $this->exception($organization, $year, 'school_break', 'Interrupção de Natal', '2026-12-21', '2027-01-04');

        $this->actingAs($teacher)
            ->withSession(['organization_id' => $organization->id, 'academic_year_id' => $year->id])
            ->get('/calendar/ano')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $months = collect($page->toArray()['props']['months'])->keyBy('value');

                $this->assertSame(11, $months['2026-12']['non_teaching_days_count']);
                $this->assertSame(4, $months['2027-01']['non_teaching_days_count']);
                // Cruzar um mês é aparecer nos dois, e não «cair» num só.
                $this->assertCount(1, $months['2026-12']['exception_ulids']);
                $this->assertCount(1, $months['2027-01']['exception_ulids']);
            });
    }

    #[Test]
    public function another_years_exceptions_never_leak_into_this_years_calendar(): void
    {
        [$teacher, $organization, $year] = $this->calendarYear();

        $otherYear = app(CurrentOrganization::class)->runFor($organization, fn (): AcademicYear => AcademicYear::factory()
            ->recycle($organization)
            ->create(['label' => 'Outro ano', 'starts_on' => '2027-09-01', 'ends_on' => '2028-07-31']));

        // MESMAS DATAS, ANO DIFERENTE: sem o filtro por ano, esta aparecia.
        $this->exception($organization, $year, 'holiday', 'Deste ano', '2026-12-25', '2026-12-25');
        $this->exception($organization, $otherYear, 'holiday', 'Do outro ano', '2026-12-25', '2026-12-25');

        $this->actingAs($teacher)
            ->withSession(['organization_id' => $organization->id, 'academic_year_id' => $year->id])
            ->get('/calendar?month=2026-12')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertSame(['Deste ano'], array_column($props['exceptions'], 'title'));

                $day = collect($props['days'])->firstWhere('date', '2026-12-25');
                $this->assertSame('Deste ano', $day['exception']['title']);
            });
    }

    #[Test]
    public function another_organizations_exceptions_never_leak_into_this_calendar(): void
    {
        [$teacher, $organization, $year] = $this->calendarYear();
        $this->exception($organization, $year, 'holiday', 'Desta organização', '2026-12-25', '2026-12-25');

        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $strangerYear = app(CurrentOrganization::class)->runFor($strangerOrganization, fn (): AcademicYear => AcademicYear::factory()
            ->recycle($strangerOrganization)
            ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-07-31']));
        $this->exception($strangerOrganization, $strangerYear, 'holiday', 'De outra organização', '2026-12-25', '2026-12-25');

        $this->actingAs($teacher)
            ->withSession(['organization_id' => $organization->id, 'academic_year_id' => $year->id])
            ->get('/calendar?month=2026-12')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertSame(
                    ['Desta organização'],
                    array_column($page->toArray()['props']['exceptions'], 'title'),
                );
            });
    }

    #[Test]
    public function reading_the_calendar_writes_no_exception_at_all(): void
    {
        [$teacher, $organization, $year] = $this->calendarYear();
        $this->exception($organization, $year, 'holiday', 'Natal', '2026-12-25', '2026-12-25');

        $session = ['organization_id' => $organization->id, 'academic_year_id' => $year->id];

        $this->actingAs($teacher)->withSession($session)->get('/calendar?month=2026-12')->assertOk();
        $this->actingAs($teacher)->withSession($session)->get('/calendar?month=2027-01')->assertOk();
        $this->actingAs($teacher)->withSession($session)->get('/calendar/ano')->assertOk();

        // A página é uma LEITURA: nem cria exceções, nem as apaga, nem inventa
        // feriados por ninguém lhos ter pedido (§17 — não há mecanismo de
        // sugestão nesta fase, e a ausência dele é deliberada).
        $this->assertDatabaseCount('academic_calendar_exceptions', 1);
    }

    // --------------------------------------------------------------- helpers

    /**
     * A teacher, their organization and a fixed year, subscribed to the plan the
     * calendar's own entitlement requires.
     *
     * @return array{0: User, 1: Organization, 2: AcademicYear}
     */
    private function calendarYear(): array
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $this->subscribeToPro($organization);

        $year = app(CurrentOrganization::class)->runFor($organization, fn (): AcademicYear => AcademicYear::factory()
            ->recycle($organization)
            ->create([
                'label' => 'Calendário 2026/2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-07-31',
            ]));

        // Um período qualquer, só para a página ter estrutura para mostrar —
        // nenhum teste deste ficheiro afirma nada sobre ele.
        app(CurrentOrganization::class)->runFor($organization, fn (): AcademicPeriod => AcademicPeriod::factory()
            ->recycle($organization)
            ->create([
                'academic_year_id' => $year->getKey(),
                'label' => '1.º Semestre',
                'sequence' => 1,
                'starts_on' => '2026-09-14',
                'ends_on' => '2027-01-29',
            ]));

        return [$teacher, $organization, $year];
    }

    private function exception(
        Organization $organization,
        AcademicYear $year,
        string $type,
        string $title,
        string $startsOn,
        string $endsOn,
    ): AcademicCalendarException {
        return app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): AcademicCalendarException => AcademicCalendarException::factory()
                ->recycle($organization)
                ->create([
                    'academic_year_id' => $year->getKey(),
                    'type' => AcademicCalendarExceptionType::from($type),
                    'title' => $title,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                ]),
        );
    }

    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now()->subDay(),
        ]);
        app(Entitlements::class)->flush();
    }
}
