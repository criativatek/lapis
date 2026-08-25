<?php

namespace Tests\Feature\AcademicYears;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Exceções letivas» — feriados, interrupções letivas e dias não letivos: as
 * datas em que a aula NÃO acontece.
 *
 * CADA UMA GRAVA-SE POR SI, E É ISSO QUE ESTE FICHEIRO AFIRMA AGORA. Na Fase 5.4
 * nasciam, mudavam e morriam dentro do MESMO pedido que gravava o ano e os seus
 * períodos, e este ficheiro afirmava exatamente isso. A verificação em uso real
 * mostrou o que esse desenho fazia à página: todas as linhas sempre abertas em
 * campos (e por isso nenhum «Editar» à vista), a linha nova a cair no fundo de
 * uma lista comprida, e um só «Guardar ano letivo» lá em baixo a prometer, sem
 * querer, um botão por linha que não existia.
 *
 * TRÊS ENDEREÇOS PRÓPRIOS, aninhados no ano:
 *
 *   POST   /academic-years/{ano}/exceptions
 *   PUT    /academic-years/{ano}/exceptions/{exceção}
 *   DELETE /academic-years/{ano}/exceptions/{exceção}
 *
 * O QUE NÃO MUDOU É METADE DO ASSUNTO, e está afirmado aqui em baixo tal como
 * estava: são estrutura da organização inteira e não de uma pessoa; a
 * autorização é a MESMA dos períodos (AcademicYearPolicy, e não uma política
 * nova); não são acontecimentos e `calendar_events` continua a não ser tocada
 * por nenhum caminho de código destes; e o calendário e a materialização de
 * aulas continuam a lê-las exatamente na mesma, porque nunca lhes interessou por
 * que porta a linha foi escrita.
 *
 * E OS PERÍODOS CONTINUAM ONDE ESTAVAM: no formulário do ano, gravados em bloco
 * pelo botão do ano. Há aqui um teste que o afirma nos dois sentidos.
 */
class AcademicCalendarExceptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O ano e os seus períodos — o que o formulário do ano submete, e desde esta
     * fase TUDO o que ele submete.
     *
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
     * UMA exceção — a forma exata que a secção «Feriados e interrupções» submete,
     * e sem `ulid` nenhum lá dentro: a identidade está no endereço, e não num
     * campo do corpo que qualquer pedido podia trocar.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function exceptionPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'holiday',
            'title' => 'Implantação da República',
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-05',
            'note' => null,
        ], $overrides);
    }

    protected function storeUrl(AcademicYear $year): string
    {
        return "/academic-years/{$year->ulid}/exceptions";
    }

    protected function exceptionUrl(AcademicYear $year, AcademicCalendarException $exception): string
    {
        return "/academic-years/{$year->ulid}/exceptions/{$exception->ulid}";
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

    /**
     * Uma exceção criada pela porta verdadeira — o POST — e devolvida já
     * relida, para os testes poderem falar dela sem a inventarem à mão.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createException(User $user, AcademicYear $year, array $overrides = []): AcademicCalendarException
    {
        $this->actingAs($user)
            ->post($this->storeUrl($year), $this->exceptionPayload($overrides))
            ->assertSessionHasNoErrors();

        // `reorder()` porque a relação traz a sua própria ordem (data, título):
        // sem ele, «a última criada» seria «a primeira do calendário», e duas
        // chamadas seguidas podiam devolver a mesma linha.
        return $year->exceptions()->reorder()->orderByDesc('id')->firstOrFail();
    }

    // ------------------------------------------------------------- criar

    #[Test]
    public function a_single_day_feriado_is_created_through_its_own_endpoint(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)
            ->post($this->storeUrl($year), $this->exceptionPayload())
            ->assertSessionHasNoErrors()
            // `back()`, e não uma página nova: o resultado vê-se onde o
            // professor está, e as props da própria página voltam refrescadas.
            ->assertRedirect();

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

        $this->actingAs($user)->post($this->storeUrl($year), $this->exceptionPayload([
            'type' => 'school_break',
            'title' => 'Interrupção de Natal',
            'starts_on' => '2026-12-21',
            'ends_on' => '2027-01-02',
            'note' => 'Regresso às aulas a 5 de janeiro.',
        ]))->assertSessionHasNoErrors();

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

        $this->actingAs($user)->post($this->storeUrl($year), $this->exceptionPayload([
            'type' => 'non_teaching_day',
            'title' => 'Dia do agrupamento',
            'starts_on' => '2027-03-15',
            'ends_on' => '2027-03-15',
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

    /**
     * O QUE SUBSTITUIU «um ano pode ser criado com as suas exceções no mesmo
     * pedido»: já não pode, e é essa a mudança. Um ano que ainda não existe não
     * tem a que agarrar um feriado — a exceção pertence a um ano letivo — e o
     * formulário de criação deixou de a oferecer. Um pedido antigo que ainda
     * mande `exceptions` cria o ano na mesma e não escreve exceção nenhuma, em
     * silêncio e sem rebentar: a chave simplesmente já não é validada nem lida.
     */
    #[Test]
    public function creating_a_year_no_longer_writes_exceptions_from_the_same_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/academic-years', $this->validPayload([
            'exceptions' => [
                ['type' => 'holiday', 'title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01', 'note' => null],
            ],
        ]))->assertSessionHasNoErrors()->assertRedirect('/academic-years');

        $this->assertDatabaseCount('academic_years', 1);
        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
    }

    // ------------------------------------------------------------ editar

    #[Test]
    public function editing_an_exception_keeps_its_row_and_persists_every_field(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $exception = $this->createException($user, $year, [
            'title' => 'Feriado municipal',
            'starts_on' => '2027-06-13',
            'ends_on' => '2027-06-13',
        ]);
        $exceptionId = $exception->id;

        $this->actingAs($user)->put($this->exceptionUrl($year, $exception), $this->exceptionPayload([
            'type' => 'non_teaching_day',
            'title' => 'Feriado municipal (renomeado)',
            'starts_on' => '2027-06-13',
            'ends_on' => '2027-06-14',
            'note' => 'Confirmado pela câmara.',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        // A MESMA LINHA, e não uma nova: o endereço carrega a identidade através
        // das gravações, exatamente como o ulid de um período o faz no seu.
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

    /**
     * A VOLTA INTEIRA DO MODO DE EDIÇÃO. Gravar não é escrever na base de dados e
     * esperar que a página adivinhe: o que a página recarrega a seguir tem de
     * mostrar o que ficou gravado, e é isso — e não o UPDATE — que o professor vê.
     */
    #[Test]
    public function the_edit_page_shows_the_edited_values_on_the_very_next_load(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $exception = $this->createException($user, $year, ['title' => 'Antes']);

        $this->actingAs($user)->put($this->exceptionUrl($year, $exception), $this->exceptionPayload([
            'type' => 'school_break',
            'title' => 'Depois',
            'starts_on' => '2026-12-21',
            'ends_on' => '2027-01-02',
            'note' => 'Uma observação nova.',
        ]))->assertSessionHasNoErrors();

        $this->actingAs($user)->get("/academic-years/{$year->ulid}/edit")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($exception) {
                $exceptions = $page->toArray()['props']['academicYear']['exceptions'];

                $this->assertCount(1, $exceptions);
                $this->assertSame($exception->ulid, $exceptions[0]['ulid']);
                $this->assertSame('school_break', $exceptions[0]['type']);
                $this->assertSame('Depois', $exceptions[0]['title']);
                $this->assertSame('2026-12-21', $exceptions[0]['starts_on']);
                $this->assertSame('2027-01-02', $exceptions[0]['ends_on']);
                $this->assertSame('Uma observação nova.', $exceptions[0]['note']);
            });
    }

    #[Test]
    public function saving_with_no_changes_at_all_is_idempotent(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $exception = $this->createException($user, $year, ['title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01']);

        $this->actingAs($user)->put($this->exceptionUrl($year, $exception), $this->exceptionPayload([
            'title' => 'Todos os Santos',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('academic_calendar_exceptions', 1);
        $this->assertSame($exception->id, $year->exceptions()->firstOrFail()->id);
    }

    /**
     * UMA OBSERVAÇÃO EM BRANCO É A AUSÊNCIA DE OBSERVAÇÃO, e escreve-se null —
     * não uma string vazia que o leitor depois teria de saber tratar como se
     * fosse null. Era o que o serviço fazia; passou a fazer-se no pedido.
     */
    #[Test]
    public function an_empty_note_is_stored_as_nothing_at_all(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $exception = $this->createException($user, $year, ['note' => '']);

        $this->assertNull($exception->note);
    }

    /**
     * O FORMULÁRIO DO ANO DEIXOU DE FALAR DISTO, nos dois sentidos: gravar o ano
     * e os seus períodos não apaga, não altera e não cria exceção nenhuma — nem
     * quando o pedido teima em mandar uma chave `exceptions` que já ninguém lê.
     */
    #[Test]
    public function saving_the_year_form_leaves_the_exceptions_untouched(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $exception = $this->createException($user, $year, ['title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01']);
        $before = $exception->getAttributes();

        // Sem falar de exceções...
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload(['label' => 'Ano renomeado']))
            ->assertSessionHasNoErrors();

        // ...e a falar delas, que é o pedido antigo: nenhum dos dois lhes toca.
        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $this->validPayload([
            'label' => 'Ano renomeado',
            'exceptions' => [],
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('academic_calendar_exceptions', 1);
        $this->assertSame($before, $exception->fresh()?->getAttributes());
        // E o ano gravou-se, que é o que aquele botão faz: os períodos e mais nada.
        $this->assertSame('Ano renomeado', $year->fresh()?->label);
        $this->assertCount(2, $year->fresh()?->periods ?? []);
    }

    /**
     * O OUTRO SENTIDO DA MESMA FRONTEIRA: mexer numa exceção não mexe nos
     * períodos. São duas gravações independentes desde que deixaram de ser uma.
     */
    #[Test]
    public function writing_an_exception_never_touches_the_periods(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $periodsBefore = $year->periods()->get()->map->getAttributes()->all();

        $exception = $this->createException($user, $year);
        $this->actingAs($user)->put($this->exceptionUrl($year, $exception), $this->exceptionPayload(['title' => 'Outro nome']))
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->delete($this->exceptionUrl($year, $exception))
            ->assertSessionHasNoErrors();

        $this->assertSame($periodsBefore, $year->periods()->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('academic_periods', 2);
    }

    // ------------------------------------------------------------ remover

    #[Test]
    public function deleting_an_exception_removes_that_one_and_only_that_one(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $removed = $this->createException($user, $year, ['title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01']);
        $kept = $this->createException($user, $year, ['title' => 'Natal', 'starts_on' => '2026-12-25', 'ends_on' => '2026-12-25']);

        $this->actingAs($user)->delete($this->exceptionUrl($year, $removed))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertNull(AcademicCalendarException::find($removed->id));
        $this->assertDatabaseCount('academic_calendar_exceptions', 1);
        $this->assertSame($kept->id, $year->exceptions()->firstOrFail()->id);
    }

    #[Test]
    public function deleting_a_draft_year_takes_its_exceptions_with_it(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $this->createException($user, $year, ['title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01']);

        // A FK é RESTRICT: sem a remoção explícita no controlador, isto rebentava
        // com um erro cru de base de dados em vez de apagar o ano.
        $this->actingAs($user)->delete("/academic-years/{$year->ulid}")
            ->assertRedirect('/academic-years');

        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
        $this->assertDatabaseCount('academic_years', 0);
    }

    // ------------------------------------------------------------- a ordem

    /**
     * POR DATA, E DECIDIDO NO SERVIDOR. A linha nova aparece em cima ENQUANTO não
     * está gravada — é o único sítio onde ela pode estar, por não fazer parte de
     * lista ordenada nenhuma. Assim que fica gravada perde esse privilégio e vai
     * para onde a sua data manda, que é o que este teste prova: as três são
     * escritas fora de ordem e a página recebe-as em ordem.
     */
    #[Test]
    public function the_edit_page_lists_the_exceptions_by_date(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->createException($user, $year, ['title' => 'Natal', 'starts_on' => '2026-12-25', 'ends_on' => '2026-12-25']);
        $this->createException($user, $year, ['title' => 'Implantação da República', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05']);
        $this->createException($user, $year, ['title' => 'Dia do Trabalhador', 'starts_on' => '2027-05-01', 'ends_on' => '2027-05-01']);

        $this->actingAs($user)->get("/academic-years/{$year->ulid}/edit")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertSame(
                    ['Implantação da República', 'Natal', 'Dia do Trabalhador'],
                    array_column($page->toArray()['props']['academicYear']['exceptions'], 'title'),
                );
            });
    }

    // ---------------------------------------------------------- validação

    /**
     * AS MENSAGENS CHEGAM COM O NOME DO CAMPO, e não com um caminho de array.
     * Enquanto uma exceção era uma linha de `exceptions[]`, os erros chegavam
     * como `exceptions.4.ends_on` — uma chave que o formulário tinha de saber
     * decifrar e que nenhuma pessoa alguma vez escreveria.
     */
    #[Test]
    public function an_end_date_before_the_start_date_is_rejected_with_a_readable_message(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)->post($this->storeUrl($year), $this->exceptionPayload([
            'title' => 'Invertido',
            'starts_on' => '2026-11-05',
            'ends_on' => '2026-11-01',
        ]))->assertSessionHasErrors([
            'ends_on' => 'Indique uma data de fim igual ou posterior à data de início.',
        ]);

        $this->assertSame(['ends_on'], $this->errorKeys());
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

        $this->actingAs($user)->post($this->storeUrl($year), $this->exceptionPayload([
            'title' => 'Um dia só',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
        ]))->assertSessionHasNoErrors();

        $payload = $this->validPayload();
        $payload['periods'][0]['ends_on'] = $payload['periods'][0]['starts_on'];

        $this->actingAs($user)->put("/academic-years/{$year->ulid}", $payload)
            ->assertSessionHasErrors('periods.0.ends_on');
    }

    /**
     * DENTRO DO ANO LETIVO, verificado no servidor — o `min`/`max` dos campos de
     * data é uma gentileza da página e não a guarda, e qualquer pedido o ignora
     * de graça.
     */
    #[Test]
    public function an_exception_outside_the_academic_year_is_rejected_on_both_ends(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        // Depois do fim do ano.
        $this->actingAs($user)->post($this->storeUrl($year), $this->exceptionPayload([
            'title' => 'Fora do ano',
            'starts_on' => '2027-09-05',
            'ends_on' => '2027-09-05',
        ]))->assertSessionHasErrors([
            'ends_on' => 'A data de fim tem de estar dentro do ano letivo. O ano letivo vai de 01/09/2026 a 31/08/2027.',
        ]);

        // E antes do início.
        $this->actingAs($user)->post($this->storeUrl($year), $this->exceptionPayload([
            'type' => 'school_break',
            'title' => 'Antes do ano',
            'starts_on' => '2026-08-20',
            'ends_on' => '2026-09-05',
        ]))->assertSessionHasErrors([
            'starts_on' => 'A data de início tem de estar dentro do ano letivo. O ano letivo vai de 01/09/2026 a 31/08/2027.',
        ]);

        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
    }

    #[Test]
    public function the_same_bounds_apply_when_editing_an_existing_exception(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $exception = $this->createException($user, $year);
        $before = $exception->getAttributes();

        $this->actingAs($user)->put($this->exceptionUrl($year, $exception), $this->exceptionPayload([
            'starts_on' => '2027-09-05',
            'ends_on' => '2027-09-05',
        ]))->assertSessionHasErrors('ends_on');

        // UM PEDIDO RECUSADO NÃO ESCREVE METADE: a linha fica exatamente como
        // estava, e é isso que a página mostra quando o professor cancela.
        $this->assertSame($before, $exception->fresh()?->getAttributes());
    }

    #[Test]
    public function an_unknown_type_is_rejected(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)->post($this->storeUrl($year), $this->exceptionPayload([
            'type' => 'meeting',
            'title' => 'Uma reunião',
        ]))->assertSessionHasErrors([
            'type' => 'Escolha o tipo: feriado, interrupção letiva ou dia não letivo.',
        ]);

        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
    }

    #[Test]
    public function a_title_is_required(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)->post($this->storeUrl($year), $this->exceptionPayload(['title' => '']))
            ->assertSessionHasErrors(['title' => 'A designação é obrigatória.']);

        $this->assertSame(['title'], $this->errorKeys());
    }

    /**
     * UM PEDIDO RECUSADO NÃO ESCREVE NADA. Substitui o antigo «uma exceção
     * recusada deixa o resto do mesmo pedido por aplicar»: já não há «o resto do
     * mesmo pedido» — o ano e a exceção viajam em pedidos separados — e o que
     * sobra por afirmar é que a recusa é total.
     */
    #[Test]
    public function a_rejected_exception_writes_nothing_at_all(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $this->actingAs($user)->post($this->storeUrl($year), $this->exceptionPayload([
            'title' => 'Invertido',
            'starts_on' => '2026-11-05',
            'ends_on' => '2026-11-01',
        ]))->assertSessionHasErrors('ends_on');

        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
    }

    /**
     * ABRIR A PÁGINA NÃO ESCREVE NADA. É a outra metade do «Cancelar»: o gesto
     * que não manda pedido nenhum não pode ter mudado nada, e o que a página
     * volta a mostrar são os valores gravados.
     */
    #[Test]
    public function loading_the_edit_page_writes_nothing(): void
    {
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $exception = $this->createException($user, $year);
        $before = $exception->getAttributes();

        $this->actingAs($user)->get("/academic-years/{$year->ulid}/edit")->assertOk();
        $this->actingAs($user)->get("/academic-years/{$year->ulid}/edit")->assertOk();

        $this->assertDatabaseCount('academic_calendar_exceptions', 1);
        $this->assertSame($before, $exception->fresh()?->getAttributes());
    }

    // ------------------------------------------------------- autorização

    #[Test]
    public function a_member_who_does_not_own_the_organization_cannot_touch_exceptions(): void
    {
        // A MESMA AUTORIZAÇÃO DOS PERÍODOS, E NÃO UMA SEGUNDA. Não há
        // AcademicCalendarExceptionPolicy nenhuma: quem pode reformar a
        // estrutura do ano é quem AcademicYearPolicy diz, e é essa que decide
        // isto também — agora que as três ações têm endereço próprio, tanto
        // quanto quando eram um campo do formulário do ano.
        $owner = User::factory()->create();
        $year = $this->yearFor($owner);
        $exception = $this->createException($owner, $year);

        $intruder = User::factory()->create();
        $organization = $owner->personalOrganization();
        $organization->members()->attach($intruder, ['joined_at' => now()]);
        $session = ['organization_id' => $organization->id];

        $this->actingAs($intruder)->withSession($session)
            ->post($this->storeUrl($year), $this->exceptionPayload(['title' => 'Introduzido por um membro']))
            ->assertForbidden();

        $this->actingAs($intruder)->withSession($session)
            ->put($this->exceptionUrl($year, $exception), $this->exceptionPayload(['title' => 'Renomeado por um membro']))
            ->assertForbidden();

        $this->actingAs($intruder)->withSession($session)
            ->delete($this->exceptionUrl($year, $exception))
            ->assertForbidden();

        $this->assertDatabaseCount('academic_calendar_exceptions', 1);
        $this->assertSame('Implantação da República', $exception->fresh()?->title);
    }

    #[Test]
    public function a_closed_year_accepts_no_exception_at_all(): void
    {
        // A camada pedagógica fica POR CIMA da de propriedade, aqui como nos
        // períodos: um ano encerrado é só de leitura, mesmo para o dono.
        $user = User::factory()->create();
        $year = $this->yearFor($user);
        $exception = $this->createException($user, $year);
        $year->update(['status' => 'closed']);

        $this->actingAs($user)->post($this->storeUrl($year), $this->exceptionPayload(['title' => 'Tarde de mais']))
            ->assertForbidden();
        $this->actingAs($user)->put($this->exceptionUrl($year, $exception), $this->exceptionPayload(['title' => 'Tarde de mais']))
            ->assertForbidden();
        $this->actingAs($user)->delete($this->exceptionUrl($year, $exception))
            ->assertForbidden();

        $this->assertDatabaseCount('academic_calendar_exceptions', 1);
    }

    // ---------------------------------------------------------- tenancy

    #[Test]
    public function another_organizations_exception_never_appears_on_the_edit_page(): void
    {
        $owner = User::factory()->create();
        $year = $this->yearFor($owner);
        $this->createException($owner, $year, ['title' => 'Só desta organização', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01']);

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->get("/academic-years/{$year->ulid}/edit")->assertNotFound();

        // E o dono continua a ver a sua, escrita no payload da própria página.
        $this->actingAs($owner)->get("/academic-years/{$year->ulid}/edit")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $exceptions = $page->toArray()['props']['academicYear']['exceptions'];

                $this->assertCount(1, $exceptions);
                $this->assertSame('Só desta organização', $exceptions[0]['title']);
                // `source` não vai no payload: não é do professor e a página
                // nunca a oferece.
                $this->assertArrayNotHasKey('source', $exceptions[0]);
            });
    }

    #[Test]
    public function an_exception_of_another_organization_cannot_be_reached_at_all(): void
    {
        $owner = User::factory()->create();
        $year = $this->yearFor($owner);

        $other = User::factory()->create();
        $otherYear = $this->yearFor($other);
        $foreign = $this->createException($other, $otherYear, ['title' => 'De outra organização']);

        // O endereço é do MEU ano, e a exceção é de outra organização: o global
        // scope do modelo não a resolve sequer, e um 404 é a resposta honesta.
        $this->actingAs($owner)->put("/academic-years/{$year->ulid}/exceptions/{$foreign->ulid}", $this->exceptionPayload(['title' => 'Roubada']))
            ->assertNotFound();
        $this->actingAs($owner)->delete("/academic-years/{$year->ulid}/exceptions/{$foreign->ulid}")
            ->assertNotFound();

        $this->assertSame('De outra organização', AcademicCalendarException::withoutGlobalScope('organization')
            ->findOrFail($foreign->id)->title);
    }

    #[Test]
    public function an_exception_belonging_to_a_different_year_of_mine_is_rejected(): void
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
        ]))->assertSessionHasNoErrors();

        $yearB = AcademicYear::where('label', '2027/2028')->firstOrFail();
        $foreign = $this->createException($user, $yearB, [
            'title' => 'Do ano seguinte',
            'starts_on' => '2027-10-05',
            'ends_on' => '2027-10-05',
        ]);

        // MESMA ORGANIZAÇÃO, OUTRO ANO: sem a guarda no controlador, a página de
        // um ano podia adotar, renomear ou apagar a interrupção do ano vizinho.
        $this->actingAs($user)->put("/academic-years/{$yearA->ulid}/exceptions/{$foreign->ulid}", $this->exceptionPayload([
            'type' => 'school_break',
            'title' => 'Adotada à força',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-01',
        ]))->assertNotFound();

        $this->actingAs($user)->delete("/academic-years/{$yearA->ulid}/exceptions/{$foreign->ulid}")
            ->assertNotFound();

        $foreign->refresh();
        $this->assertSame('Do ano seguinte', $foreign->title);
        $this->assertSame($yearB->getKey(), $foreign->academic_year_id);
    }

    // ----------------------------------------------- não é um acontecimento

    #[Test]
    public function nothing_here_writes_to_the_calendar_events_table(): void
    {
        // UM FERIADO NÃO É UMA REUNIÃO COM OUTRO NOME. As duas tabelas não se
        // tocam em nenhum caminho de código deste controlador, e é isto que o
        // afirma — na criação, na edição E na remoção.
        $user = User::factory()->create();
        $year = $this->yearFor($user);

        $first = $this->createException($user, $year, ['title' => 'Todos os Santos', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01']);
        $this->createException($user, $year, ['type' => 'school_break', 'title' => 'Interrupção de Natal', 'starts_on' => '2026-12-21', 'ends_on' => '2027-01-02']);
        $this->actingAs($user)->put($this->exceptionUrl($year, $first), $this->exceptionPayload(['title' => 'Todos os Santos (confirmado)', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-01']))
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->delete($this->exceptionUrl($year, $first))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, CalendarEvent::withoutGlobalScope('organization')->count());
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('academic_calendar_exceptions', 1);
    }

    // --------------------------------------------------- no calendário

    /**
     * O LADO DA LEITURA NÃO SABE — NEM PODE SABER — POR QUE PORTA A LINHA FOI
     * ESCRITA. AcademicYearCalendarQuery lê `academicYear->exceptions()`, e é
     * indiferente a esta fase ter trocado o caminho de escrita: uma exceção
     * criada pelo endereço novo aparece no calendário exatamente como as que
     * a fábrica escreve nos testes daqui para baixo.
     */
    #[Test]
    public function an_exception_created_through_the_new_endpoint_shows_up_in_the_calendar(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $this->subscribeToPro($organization);
        $year = $this->yearFor($teacher);

        $this->createException($teacher, $year, [
            'type' => 'school_break',
            'title' => 'Interrupção de Natal',
            'starts_on' => '2026-12-21',
            'ends_on' => '2026-12-31',
        ]);

        $this->actingAs($teacher)
            ->withSession(['organization_id' => $organization->id, 'academic_year_id' => $year->id])
            ->get('/calendar?month=2026-12')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertSame(['Interrupção de Natal'], array_column($props['exceptions'], 'title'));

                $days = collect($props['days'])->keyBy('date');
                $this->assertSame('Interrupção de Natal', $days['2026-12-23']['exception']['title']);
                $this->assertNull($days['2026-12-15']['exception']);
            });
    }

    /**
     * E A GUARDA DA FASE 5.5 TAMBÉM NÃO SABE. «Num feriado não há aula» é lido
     * por datas, do próprio ano, em MaterializeLessonsForRange — e continua a
     * valer para uma exceção escrita pelo caminho novo. As duas segundas-feiras
     * do intervalo dizem a coisa toda: a excecionada não recebe aula, e a
     * seguinte recebe.
     */
    #[Test]
    public function an_exception_created_through_the_new_endpoint_still_blocks_materialization(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();
        $this->subscribeToPro($organization);
        $year = $this->yearFor($teacher);

        $schoolClass = app(CurrentOrganization::class)->runFor($organization, function () use ($teacher, $organization, $year): SchoolClass {
            $schoolClass = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->getKey(),
                'label' => '7.º A',
            ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            RecurringLessonSlot::create([
                'class_id' => $schoolClass->id,
                'day_of_week' => 1,
                'starts_at' => '09:30',
                'ends_at' => '10:20',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-06-30',
            ]);

            return $schoolClass;
        });

        // 7 de setembro de 2026 é uma segunda-feira; a seguinte é o dia 14.
        $this->createException($teacher, $year, [
            'title' => 'Feriado municipal',
            'starts_on' => '2026-09-07',
            'ends_on' => '2026-09-07',
        ]);

        app(CurrentOrganization::class)->runFor($organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-20'),
            $teacher,
        ));

        $this->assertSame(
            ['2026-09-14 09:30:00'],
            app(CurrentOrganization::class)->runFor($organization, fn (): array => Lesson::query()
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Lesson $lesson): string => $lesson->starts_at->format('Y-m-d H:i:s'))
                ->all()),
        );
    }

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
     * As chaves do saco de erros, tal e qual. Uma exceção fala agora de `title`
     * e de `ends_on` — e nunca de `exceptions.4.title`, que era o que uma linha
     * de array produzia e o que nenhuma pessoa reconheceria.
     *
     * @return list<string>
     */
    private function errorKeys(): array
    {
        $errors = session('errors');

        return $errors === null ? [] : array_keys($errors->getBag('default')->messages());
    }

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
