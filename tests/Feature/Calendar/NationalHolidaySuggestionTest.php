<?php

namespace Tests\Feature\Calendar;

use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Sugerir feriados nacionais» — os dois endereços, pelas rotas reais.
 *
 * AS ASSERÇÕES MAIS IMPORTANTES SÃO AS DE QUE NADA ACONTECE: que ABRIR a lista não
 * escreve uma única linha; que uma linha por marcar nunca é escrita; que uma data
 * que entretanto já foi escrita por outra via é saltada e não duplicada; que um ano
 * letivo que não é português recebe uma frase e nunca o calendário de Portugal; e
 * que nada do que o navegador mande — nem títulos, nem datas fora da lista — chega
 * à base de dados.
 */
class NationalHolidaySuggestionTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Organization $organization;

    private AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();

        // O ANO REAL DE REFERÊNCIA: 2026/2027, de 1 de setembro a 31 de agosto —
        // a forma que este projeto usa em todo o lado. É o ano que apanha treze
        // feriados nacionais, cinco do lado de 2026 e oito do lado de 2027.
        $this->academicYear = $this->yearFor('2026/2027', '2026-09-01', '2027-08-31');
    }

    // ─────────────────────────────────────────────────────── a leitura (GET)

    /**
     * A ASSERÇÃO CENTRAL DO PRIMEIRO PASSO. Abrir a lista é uma leitura, e uma
     * leitura que escreve é a única falha deste ecrã que ninguém notaria até ser
     * tarde.
     */
    #[Test]
    public function opening_the_suggestions_writes_absolutely_nothing(): void
    {
        $before = $this->rowCounts();

        $this->suggestions()->assertOk();

        $this->assertSame($before, $this->rowCounts());
    }

    #[Test]
    public function the_year_gets_the_thirteen_national_holidays_that_fall_inside_it(): void
    {
        $payload = $this->suggestions()->assertOk()->json();

        $this->assertTrue($payload['supported']);
        $this->assertSame('PT', $payload['country_code']);
        $this->assertNull($payload['message']);

        $this->assertSame([
            '2026-10-05', '2026-11-01', '2026-12-01', '2026-12-08', '2026-12-25',
            '2027-01-01', '2027-03-26', '2027-03-28', '2027-04-25', '2027-05-01',
            '2027-05-27', '2027-06-10', '2027-08-15',
        ], array_column($payload['suggestions'], 'date'));
    }

    #[Test]
    public function every_suggestion_is_new_on_a_year_with_an_empty_calendar(): void
    {
        foreach ($this->suggestions()->json('suggestions') as $row) {
            $this->assertSame('new', $row['state'], "{$row['title']} devia estar novo.");
            $this->assertNull($row['current']);
        }
    }

    #[Test]
    public function a_holiday_already_stored_with_the_same_title_reads_as_already_existing(): void
    {
        $this->existingException('Dia do Trabalhador', '2027-05-01');

        $row = $this->suggestionFor('2027-05-01');

        $this->assertSame('exists', $row['state']);
        $this->assertSame('Dia do Trabalhador', $row['current']['title']);
        $this->assertSame('Escrita pelo professor', $row['current']['source_label']);
    }

    #[Test]
    public function a_holiday_already_stored_with_another_title_reads_as_a_correspondence(): void
    {
        $this->existingException('1.º de Maio', '2027-05-01');

        $row = $this->suggestionFor('2027-05-01');

        $this->assertSame('correspondence', $row['state']);
        // AS DUAS DESIGNAÇÕES VIAJAM, que é o ponto todo deste estado.
        $this->assertSame('1.º de Maio', $row['current']['title']);
        $this->assertSame('Dia do Trabalhador', $row['title']);
    }

    /**
     * ESPÉCIES DIFERENTES NÃO SE VEEM. Um feriado nacional dentro de uma interrupção
     * letiva continua a ser proposto — o 25 de dezembro é as duas coisas, e as duas
     * linhas são ambas verdadeiras.
     */
    #[Test]
    public function a_school_break_covering_the_day_never_hides_the_holiday(): void
    {
        $this->existingException(
            'Interrupção de Natal',
            '2026-12-21',
            '2027-01-02',
            AcademicCalendarExceptionType::SchoolBreak,
        );

        $this->assertSame('new', $this->suggestionFor('2026-12-25')['state']);
    }

    #[Test]
    public function an_overlapping_holiday_of_the_same_kind_reads_as_a_conflict(): void
    {
        // Um feriado gravado como intervalo de dois dias a apanhar o 25: a mesma
        // espécie, a tocar sem ser o mesmo intervalo.
        $this->existingException('Natal alargado', '2026-12-24', '2026-12-26');

        $row = $this->suggestionFor('2026-12-25');

        $this->assertSame('conflict', $row['state']);
        $this->assertSame('2026-12-24', $row['current']['starts_on']);
    }

    // ──────────────────────────────────────────────────── a confirmação (POST)

    #[Test]
    public function confirming_creates_only_the_ticked_rows_and_stamps_them_suggested(): void
    {
        $this->confirm(['2026-12-25', '2027-05-01'])->assertRedirect();

        $exceptions = $this->inTenant(
            fn () => AcademicCalendarException::query()->orderBy('starts_on')->get(),
        );

        $this->assertCount(2, $exceptions);
        $this->assertSame(['Natal', 'Dia do Trabalhador'], $exceptions->pluck('title')->all());
        $this->assertSame(['2026-12-25', '2027-05-01'], $exceptions->pluck('starts_on')->map->toDateString()->all());

        foreach ($exceptions as $exception) {
            // UM FERIADO É UM DIA: starts_on === ends_on, a convenção do modelo.
            $this->assertSame($exception->starts_on->toDateString(), $exception->ends_on->toDateString());
            $this->assertSame(AcademicCalendarExceptionType::Holiday, $exception->type);
            // A PROVENIÊNCIA, que é toda a proveniência que o §14 pede.
            $this->assertSame(AcademicCalendarExceptionSource::Suggested, $exception->source);
            $this->assertNull($exception->note);
            $this->assertSame($this->academicYear->id, $exception->academic_year_id);
        }
    }

    #[Test]
    public function the_eleven_that_were_not_ticked_are_never_written(): void
    {
        $this->confirm(['2026-10-05'])->assertRedirect();

        $this->inTenant(function (): void {
            $this->assertSame(1, AcademicCalendarException::query()->count());
            $this->assertSame(
                '2026-10-05',
                AcademicCalendarException::query()->sole()->starts_on->toDateString(),
            );
        });
    }

    /**
     * UM DUPLICADO É ORDINÁRIO E NÃO UMA FALHA. Entre abrir a lista e carregar no
     * botão, outra coisa qualquer pode ter escrito o mesmo dia — e o lote continua a
     * gravar as outras linhas, sem erro nenhum.
     */
    #[Test]
    public function a_date_something_else_wrote_in_the_meantime_is_skipped_without_an_error(): void
    {
        $existing = $this->existingException('Natal', '2026-12-25');

        $this->confirm(['2026-12-25', '2027-05-01'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($existing): void {
            $this->assertSame(2, AcademicCalendarException::query()->count());
            // A linha antiga, intacta e sem companhia no dia 25.
            $this->assertSame(
                1,
                AcademicCalendarException::query()->whereDate('starts_on', '2026-12-25')->count(),
            );
            $this->assertSame($existing->id, AcademicCalendarException::query()->whereDate('starts_on', '2026-12-25')->sole()->id);
        });
    }

    /**
     * CONFIRMAR DUAS VEZES O MESMO LOTE NÃO DUPLICA NADA — a mesma promessa que
     * reimportar o mesmo ficheiro duas vezes já dava.
     */
    #[Test]
    public function confirming_the_same_batch_twice_creates_no_duplicate(): void
    {
        $all = array_column($this->suggestions()->json('suggestions'), 'date');

        $this->confirm($all)->assertRedirect();
        $before = $this->rowCounts();

        $this->confirm($all)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($before, $this->rowCounts());
        $this->inTenant(fn () => $this->assertSame(13, AcademicCalendarException::query()->count()));
    }

    /**
     * MARCAR UMA CORRESPONDÊNCIA MUDA A DESIGNAÇÃO E MAIS NADA. Não nasce uma
     * segunda linha, as datas ficam onde estavam, e — §28 — a proveniência NÃO se
     * reescreve: uma linha escrita à mão continua a dizer que foi escrita à mão
     * depois de lhe mudarem o nome.
     */
    #[Test]
    public function ticking_a_correspondence_renames_the_existing_row_and_keeps_its_provenance(): void
    {
        $existing = $this->existingException('1.º de Maio', '2027-05-01');

        $this->confirm(['2027-05-01'])->assertRedirect();

        $this->inTenant(function () use ($existing): void {
            $this->assertSame(1, AcademicCalendarException::query()->count());

            $row = AcademicCalendarException::query()->sole();

            // A MESMA LINHA — mesmo id, mesmo ulid — com outro nome.
            $this->assertSame($existing->id, $row->id);
            $this->assertSame($existing->ulid, $row->ulid);
            $this->assertSame('Dia do Trabalhador', $row->title);
            $this->assertSame('2027-05-01', $row->starts_on->toDateString());
            $this->assertSame('2027-05-01', $row->ends_on->toDateString());
            // §28: emparelhar nunca é adotar a proveniência do outro lado.
            $this->assertSame(AcademicCalendarExceptionSource::Manual, $row->source);
        });
    }

    /**
     * UM CONFLITO NUNCA É RESOLVIDO POR APROXIMAÇÃO, mesmo que o pedido insista:
     * a caixa desmarcada é uma comodidade, e a verificação que vale é esta.
     */
    #[Test]
    public function a_conflicting_date_is_skipped_even_when_the_payload_insists(): void
    {
        $existing = $this->existingException('Natal alargado', '2026-12-24', '2026-12-26');

        $this->confirm(['2026-12-25'])->assertRedirect()->assertSessionHasNoErrors();

        $this->inTenant(function () use ($existing): void {
            $this->assertSame(1, AcademicCalendarException::query()->count());
            $this->assertSame($existing->id, AcademicCalendarException::query()->sole()->id);
        });
    }

    /**
     * O TÍTULO NUNCA VEM DO PEDIDO, e é por isso que o pedido não tem sítio nenhum
     * para o pôr: `source = suggested` tem de significar «isto veio da lista de
     * feriados nacionais», e um título vindo do cliente esvaziava essa palavra.
     */
    #[Test]
    public function a_title_sent_by_the_client_is_ignored_entirely(): void
    {
        $this->actingAs($this->teacher)
            ->post($this->url(), [
                'dates' => ['2026-12-25'],
                'titles' => ['Aniversário do Manuel'],
                'source' => AcademicCalendarExceptionSource::Manual->value,
            ])
            ->assertRedirect();

        $this->inTenant(function (): void {
            $row = AcademicCalendarException::query()->sole();

            $this->assertSame('Natal', $row->title);
            $this->assertSame(AcademicCalendarExceptionSource::Suggested, $row->source);
        });
    }

    /**
     * UMA DATA QUE NÃO É FERIADO NACIONAL RECUSA O PEDIDO INTEIRO. Um duplicado é
     * ordinário e conta-se; isto é outra coisa — é um pedido que este ecrã não podia
     * ter produzido, e escrever metade dele seria escrever metade de uma coisa que
     * ninguém pediu.
     */
    #[Test]
    public function a_date_that_is_not_a_national_holiday_is_refused_and_nothing_at_all_is_written(): void
    {
        // 15 de fevereiro não é feriado nenhum; o 25 de dezembro é, e cai com ele.
        $this->confirm(['2026-12-25', '2027-02-15'])->assertSessionHasErrors('dates');

        $this->assertWroteNothing();
    }

    #[Test]
    public function carnaval_is_not_on_the_list_and_cannot_be_written_through_this_door(): void
    {
        // A terça-feira de Carnaval de 2027 — Páscoa − 47 — é a 9 de fevereiro.
        $this->assertNotContains(
            '2027-02-09',
            array_column($this->suggestions()->json('suggestions'), 'date'),
        );

        $this->confirm(['2027-02-09'])->assertSessionHasErrors('dates');

        $this->assertWroteNothing();
    }

    #[Test]
    public function an_empty_selection_is_refused_rather_than_silently_doing_nothing(): void
    {
        $this->confirm([])->assertSessionHasErrors('dates');

        $this->assertWroteNothing();
    }

    // ─────────────────────────────────────────────────────────────── o país

    /**
     * O PAÍS SAI DO ANO LETIVO (§16) E A RECUSA É HONESTA. Esta entrega tem um
     * provider; um ano declarado espanhol recebe uma frase que o diz — e nunca, em
     * circunstância nenhuma, o calendário português em silêncio.
     */
    #[Test]
    public function a_year_of_another_country_is_told_honestly_and_never_gets_portugals_calendar(): void
    {
        $spanish = $this->yearFor('2028/2029', '2028-09-01', '2029-08-31', 'ES');

        $payload = $this->actingAs($this->teacher)
            ->getJson("/academic-years/{$spanish->ulid}/holiday-suggestions")
            ->assertOk()
            ->json();

        $this->assertFalse($payload['supported']);
        $this->assertSame('ES', $payload['country_code']);
        $this->assertSame([], $payload['suggestions']);
        $this->assertStringContainsString('ES', (string) $payload['message']);
        $this->assertStringContainsString('Portugal', (string) $payload['message']);
    }

    #[Test]
    public function a_year_of_another_country_refuses_the_confirmation_too(): void
    {
        $spanish = $this->yearFor('2028/2029', '2028-09-01', '2029-08-31', 'ES');

        $this->actingAs($this->teacher)
            ->post("/academic-years/{$spanish->ulid}/holiday-suggestions", ['dates' => ['2028-12-25']])
            ->assertSessionHasErrors('dates');

        $this->assertWroteNothing();
    }

    // ────────────────────────────────────────────── autorização e inquilino

    /**
     * A estrutura do ano é do dono da organização (AcademicYearPolicy) — a mesma
     * política dos períodos, das exceções escritas à mão e da importação. Ler a
     * lista exige poder escrever o que ela propõe: quem não pode gravar não tem
     * nada a fazer a escolher o que gravar.
     */
    #[Test]
    public function a_member_who_does_not_own_the_organization_can_neither_read_nor_confirm(): void
    {
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        $session = ['organization_id' => $this->organization->id];

        $this->actingAs($colleague)->withSession($session)
            ->getJson($this->url())
            ->assertForbidden();

        $this->actingAs($colleague)->withSession($session)
            ->post($this->url(), ['dates' => ['2026-12-25']])
            ->assertForbidden();

        $this->assertWroteNothing();
    }

    /**
     * UM ANO DE OUTRA ORGANIZAÇÃO NÃO RESOLVE — o scope do modelo esconde-o, e a
     * ligação da rota devolve 404. Não há aqui nada a autorizar porque não há ano
     * nenhum a que responder.
     */
    #[Test]
    public function another_organizations_year_cannot_be_reached_at_all(): void
    {
        $stranger = User::factory()->create();
        $strangersYear = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn (): AcademicYear => AcademicYear::factory()
                ->recycle($stranger->personalOrganization())
                ->create(['label' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']),
        );

        $this->actingAs($this->teacher)
            ->getJson("/academic-years/{$strangersYear->ulid}/holiday-suggestions")
            ->assertNotFound();

        $this->actingAs($this->teacher)
            ->post("/academic-years/{$strangersYear->ulid}/holiday-suggestions", ['dates' => ['2026-12-25']])
            ->assertNotFound();

        $this->assertWroteNothing();
    }

    /**
     * O ANO DO ENDEREÇO É O ANO, E NÃO O QUE O PEDIDO GOSTARIA. As datas de
     * 2026/2027 confirmadas contra o endereço de 2027/2028 não caem dentro dele —
     * não estão na lista DAQUELE ano — e por isso não escrevem lá nada. É o mesmo
     * mecanismo que impede um título de vir do cliente: o servidor recalcula a lista
     * a partir do ano que tem à frente.
     */
    #[Test]
    public function dates_of_one_year_cannot_be_confirmed_against_another_year(): void
    {
        $next = $this->yearFor('2027/2028', '2027-09-01', '2028-08-31');

        $this->actingAs($this->teacher)
            ->post("/academic-years/{$next->ulid}/holiday-suggestions", ['dates' => ['2026-12-25']])
            ->assertSessionHasErrors('dates');

        $this->assertWroteNothing();
    }

    /**
     * E UMA LINHA ESCRITA NUM ANO NUNCA APAGA A SUGESTÃO DO ANO VIZINHO: as duas
     * listas são calculadas contra as datas do seu próprio ano e contra as exceções
     * do seu próprio ano.
     */
    #[Test]
    public function a_holiday_written_in_one_year_never_affects_the_neighbouring_years_list(): void
    {
        $next = $this->yearFor('2027/2028', '2027-09-01', '2028-08-31');

        $this->confirm(['2026-12-25'])->assertRedirect();

        $rows = $this->actingAs($this->teacher)
            ->getJson("/academic-years/{$next->ulid}/holiday-suggestions")
            ->json('suggestions');

        foreach ($rows as $row) {
            $this->assertSame('new', $row['state']);
        }
    }

    #[Test]
    public function nothing_here_ever_touches_calendar_events(): void
    {
        $this->confirm(['2026-12-25'])->assertRedirect();

        $this->inTenant(fn () => $this->assertSame(0, CalendarEvent::query()->count()));
    }

    // --------------------------------------------------------------- helpers

    private function url(?AcademicYear $year = null): string
    {
        return '/academic-years/'.($year ?? $this->academicYear)->ulid.'/holiday-suggestions';
    }

    private function suggestions(): TestResponse
    {
        return $this->actingAs($this->teacher)->getJson($this->url());
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestionFor(string $date): array
    {
        foreach ($this->suggestions()->json('suggestions') as $row) {
            if ($row['date'] === $date) {
                return $row;
            }
        }

        $this->fail("Nenhuma sugestão para {$date}.");
    }

    /**
     * @param  list<string>  $dates
     */
    private function confirm(array $dates): TestResponse
    {
        return $this->actingAs($this->teacher)->post($this->url(), ['dates' => $dates]);
    }

    private function yearFor(string $label, string $startsOn, string $endsOn, string $countryCode = 'PT'): AcademicYear
    {
        return $this->inTenant(fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create([
                'label' => $label,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'country_code' => $countryCode,
            ]));
    }

    private function existingException(
        string $title,
        string $startsOn,
        ?string $endsOn = null,
        AcademicCalendarExceptionType $type = AcademicCalendarExceptionType::Holiday,
    ): AcademicCalendarException {
        return $this->inTenant(fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)
            ->for($this->academicYear)
            ->create([
                'type' => $type,
                'title' => $title,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn ?? $startsOn,
                'source' => AcademicCalendarExceptionSource::Manual,
            ]));
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return $this->inTenant(fn (): array => [
            'academic_years' => AcademicYear::query()->count(),
            'academic_calendar_exceptions' => AcademicCalendarException::query()->count(),
            'calendar_events' => CalendarEvent::query()->count(),
        ]);
    }

    /**
     * Nada foi escrito EM ANO NENHUM desta organização — a pergunta certa, porque
     * alguns destes testes criam de propósito um segundo ano para o oferecer ao
     * pedido, e esse ano existir é o ponto de partida e não a falha.
     */
    private function assertWroteNothing(): void
    {
        $this->inTenant(function (): void {
            $this->assertSame(0, AcademicCalendarException::query()->count());
            $this->assertSame(0, CalendarEvent::query()->count());
        });
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
