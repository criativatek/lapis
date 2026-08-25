<?php

namespace Tests\Feature\Calendar;

use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Import\AcademicCalendar\BuildAcademicCalendarImportPreview as Preview;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\AcademicCalendarXlsxBuilder as Fixture;
use Tests\TestCase;

/**
 * AS TRÊS PORTAS PARA A MESMA TABELA, POSTAS UMAS CONTRA AS OUTRAS — que é o ponto
 * inteiro desta fase (§29-30, §36 D-G).
 *
 * Um feriado pode chegar a `academic_calendar_exceptions` por três caminhos: o
 * professor escreve-o à mão em «Estrutura do Ano Letivo»; a importação do .xlsx da
 * escola escreve-o; a sugestão de feriados nacionais escreve-o. Cada um deles tem os
 * seus próprios testes e todos passam. Nada disso garante o que este ficheiro
 * garante: que os três, seja qual for a ORDEM por que aconteçam, reconhecem o
 * trabalho um do outro e nunca deixam duas linhas para o mesmo 1 de maio.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PORQUE É QUE OS TÍTULOS DIVERGEM, E PORQUE É QUE ISSO É O ASSUNTO
 *
 * O calendário da escola chama «Dia Trabalhador» ao 1 de maio; a lista de feriados
 * nacionais chama-lhe «Dia do Trabalhador». Normalizados, são textos DIFERENTES — e
 * é assim que tem de ser: aproximá-los seria adivinhar. O que a chave natural (a
 * espécie e as datas) garante é que nenhum dos dois cria uma segunda linha; o que o
 * estado «designação diferente» acrescenta é que o professor CHEGA A SABER que os
 * dois lados lhe chamam coisas diferentes, em vez de ver um «já existe» que lho
 * escondia.
 *
 * E há o caso em que coincidem: «Natal» é «Natal» dos dois lados, e aí o estado é
 * «já existente» — que é igualmente correto e igualmente não-duplicante. Os dois
 * desfechos estão afirmados aqui, porque os dois acontecem no documento real.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * A PROVENIÊNCIA NUNCA SE REESCREVE (§28). Emparelhar quer dizer «não cries um
 * duplicado» e nunca «adota a proveniência do outro». Uma linha escrita à mão que
 * mais tarde coincida com uma sugestão E com uma importação continua a dizer
 * «escrita pelo professor» — porque foi.
 */
class CalendarExceptionCrossSourceTest extends TestCase
{
    use RefreshDatabase;

    /** O 1 de maio: o dia em que os dois lados lhe chamam coisas diferentes. */
    private const LABOUR_DAY = '2031-05-01';

    private const LABOUR_DAY_IN_DOCUMENT = 'Dia Trabalhador';

    private const LABOUR_DAY_NATIONAL = 'Dia do Trabalhador';

    /** E o Natal: o dia em que lhe chamam a mesma coisa. */
    private const CHRISTMAS = '2030-12-25';

    private User $teacher;

    private Organization $organization;

    private AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        // O mesmo ano fixo do teste da importação, e pela mesma razão: contém
        // inteiramente o calendário da fixture, e as datas são metade do assunto.
        $this->academicYear = $this->inTenant(fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create([
                'label' => '2030/2031',
                'starts_on' => '2030-09-01',
                'ends_on' => '2031-07-31',
                'country_code' => 'PT',
            ]));
    }

    // ══════════════════════════════════════════ 1. sugerir, depois importar

    /**
     * SUGERIR PRIMEIRO, IMPORTAR DEPOIS.
     *
     * O 1 de maio é sugerido e confirmado como «Dia do Trabalhador» (proveniência:
     * sugerida). Aberto a seguir o .xlsx da escola, que lhe chama «Dia Trabalhador»,
     * o mesmo dia NÃO PODE aparecer como novo — e confirmar aquele ecrã não pode
     * criar uma segunda linha para o 1 de maio.
     */
    #[Test]
    public function suggesting_first_makes_the_excel_import_see_the_same_day_as_already_matched(): void
    {
        $this->confirmSuggestions([self::LABOUR_DAY, self::CHRISTMAS]);

        $this->assertStoredTitle(self::LABOUR_DAY, self::LABOUR_DAY_NATIONAL);

        $holidays = $this->previewProps()['holidays'];

        // O 1 DE MAIO: designações diferentes, e por isso «designação diferente» —
        // e NUNCA «novo», que é a asserção que importa.
        $labourDay = $this->rowFor($holidays, self::LABOUR_DAY);
        $this->assertNotSame(Preview::STATE_NEW, $labourDay['state']);
        $this->assertSame(Preview::STATE_CORRESPONDENCE, $labourDay['state']);
        $this->assertFalse($labourDay['include']);
        // As duas designações lado a lado, que é o que este estado existe para dar.
        $this->assertSame(self::LABOUR_DAY_NATIONAL, $labourDay['current']['title']);
        $this->assertSame(self::LABOUR_DAY_IN_DOCUMENT, $labourDay['title']);
        $this->assertSame('Sugerida', $labourDay['current']['source_label']);

        // O NATAL: os dois lados chamam-lhe a mesma coisa, e por isso «já
        // existente». Igualmente correto, e igualmente não-duplicante.
        $christmas = $this->rowFor($holidays, self::CHRISTMAS);
        $this->assertSame(Preview::STATE_EXISTS, $christmas['state']);
        $this->assertFalse($christmas['include']);
    }

    #[Test]
    public function confirming_that_import_never_creates_a_second_row_for_the_first_of_may(): void
    {
        $this->confirmSuggestions([self::LABOUR_DAY, self::CHRISTMAS]);

        // TUDO FORÇADO A MARCADO: mesmo um professor que pique todas as caixas não
        // pode acabar com dois 1 de maio.
        $this->confirmImport($this->importPayload(forceInclude: true))->assertRedirect();

        $this->assertSame(1, $this->countOn(self::LABOUR_DAY));
        $this->assertSame(1, $this->countOn(self::CHRISTMAS));

        // §28: a linha sugerida continua sugerida. Foi ela que chegou primeiro, e
        // emparelhar com um documento não a torna importada.
        $this->assertSame(AcademicCalendarExceptionSource::Suggested, $this->sourceOn(self::LABOUR_DAY));
        $this->assertSame(AcademicCalendarExceptionSource::Suggested, $this->sourceOn(self::CHRISTMAS));
    }

    /**
     * E A ESCOLHA QUE O ESTADO NOVO ABRE, EXERCIDA: marcar a linha «designação
     * diferente» adota o nome do documento na linha que já existe. UMA linha, com
     * outro nome — e com a proveniência intacta, porque mudar o nome de uma coisa
     * não muda de onde ela veio.
     */
    #[Test]
    public function adopting_the_documents_title_renames_the_suggested_row_instead_of_duplicating_it(): void
    {
        $this->confirmSuggestions([self::LABOUR_DAY]);

        $this->confirmImport($this->importPayload(forceInclude: true))->assertRedirect();

        $this->assertSame(1, $this->countOn(self::LABOUR_DAY));
        $this->assertSame(self::LABOUR_DAY_IN_DOCUMENT, $this->storedOn(self::LABOUR_DAY)->title);
        $this->assertSame(AcademicCalendarExceptionSource::Suggested, $this->sourceOn(self::LABOUR_DAY));
    }

    /**
     * E DEIXAR A CAIXA POR MARCAR NÃO MUDA NADA — que é o desfecho por omissão, já
     * que uma linha «designação diferente» nunca vem pré-marcada.
     */
    #[Test]
    public function leaving_the_correspondence_unticked_leaves_the_existing_title_alone(): void
    {
        $this->confirmSuggestions([self::LABOUR_DAY]);

        $this->confirmImport($this->importPayload())->assertRedirect();

        $this->assertSame(1, $this->countOn(self::LABOUR_DAY));
        $this->assertSame(self::LABOUR_DAY_NATIONAL, $this->storedOn(self::LABOUR_DAY)->title);
    }

    // ══════════════════════════════════════════ 2. importar, depois sugerir

    /**
     * IMPORTAR PRIMEIRO, SUGERIR DEPOIS — a ordem inversa, e a mesma exigência: o
     * mesmo dia não pode voltar como novo.
     */
    #[Test]
    public function importing_first_makes_the_suggestions_see_the_same_days_as_already_matched(): void
    {
        $this->confirmImport($this->importPayload(forceInclude: true))->assertRedirect();

        $this->assertSame(AcademicCalendarExceptionSource::Imported, $this->sourceOn(self::LABOUR_DAY));

        $suggestions = $this->suggestionsByDate();

        $this->assertNotSame('new', $suggestions[self::LABOUR_DAY]['state']);
        $this->assertSame('correspondence', $suggestions[self::LABOUR_DAY]['state']);
        $this->assertSame(self::LABOUR_DAY_IN_DOCUMENT, $suggestions[self::LABOUR_DAY]['current']['title']);
        $this->assertSame('Importada', $suggestions[self::LABOUR_DAY]['current']['source_label']);

        $this->assertSame('exists', $suggestions[self::CHRISTMAS]['state']);

        // E o 5 de outubro, que o documento abrevia («Implant. República») e a lista
        // nacional escreve por extenso: outra correspondência real, e não um
        // duplicado.
        $this->assertSame('correspondence', $suggestions['2030-10-05']['state']);
        $this->assertSame('Implant. República', $suggestions['2030-10-05']['current']['title']);
        $this->assertSame('Implantação da República', $suggestions['2030-10-05']['title']);
    }

    #[Test]
    public function confirming_those_suggestions_never_creates_a_second_row_for_a_day_the_import_wrote(): void
    {
        $this->confirmImport($this->importPayload(forceInclude: true))->assertRedirect();

        $before = $this->countOn(self::LABOUR_DAY);
        $this->confirmSuggestions(array_keys($this->suggestionsByDate()));

        $this->assertSame(1, $before);
        $this->assertSame(1, $this->countOn(self::LABOUR_DAY));
        $this->assertSame(1, $this->countOn(self::CHRISTMAS));
        $this->assertSame(1, $this->countOn('2030-10-05'));

        // §28 outra vez, na direção contrária: as linhas importadas continuam
        // importadas.
        $this->assertSame(AcademicCalendarExceptionSource::Imported, $this->sourceOn(self::LABOUR_DAY));
        $this->assertSame(AcademicCalendarExceptionSource::Imported, $this->sourceOn('2030-10-05'));
    }

    // ══════════════════════════════════════════ 3. escrever à mão, depois sugerir

    /**
     * ESCRITO À MÃO PRIMEIRO, SUGERIDO DEPOIS. O professor escreve o seu «1.º de
     * Maio» pela porta de sempre — «+ Adicionar», em «Estrutura do Ano Letivo» — e
     * a sugestão de feriados nacionais reconhece-o pela MESMA regra que reconhece
     * uma linha importada. Não há aqui um terceiro emparelhador.
     */
    #[Test]
    public function a_manually_written_holiday_is_never_offered_again_as_new(): void
    {
        $this->createManually('1.º de Maio', self::LABOUR_DAY);

        $suggestions = $this->suggestionsByDate();

        $this->assertNotSame('new', $suggestions[self::LABOUR_DAY]['state']);
        $this->assertSame('correspondence', $suggestions[self::LABOUR_DAY]['state']);
        $this->assertSame('1.º de Maio', $suggestions[self::LABOUR_DAY]['current']['title']);
        $this->assertSame('Escrita pelo professor', $suggestions[self::LABOUR_DAY]['current']['source_label']);
    }

    #[Test]
    public function a_manually_written_holiday_with_the_national_name_reads_as_already_existing(): void
    {
        $this->createManually(self::LABOUR_DAY_NATIONAL, self::LABOUR_DAY);

        $this->assertSame('exists', $this->suggestionsByDate()[self::LABOUR_DAY]['state']);
    }

    #[Test]
    public function confirming_the_suggestions_never_duplicates_a_manually_written_day(): void
    {
        $this->createManually('1.º de Maio', self::LABOUR_DAY);

        $this->confirmSuggestions(array_keys($this->suggestionsByDate()));

        $this->assertSame(1, $this->countOn(self::LABOUR_DAY));
    }

    // ══════════════════════════════════════════ 4. a proveniência, nas três direções

    /**
     * A PROVENIÊNCIA NUNCA SE REESCREVE EM SILÊNCIO (§28), verificada NA COLUNA e
     * não através de um acessor: uma linha escrita à mão atravessa uma sugestão E
     * uma importação inteiras — as duas a reconhecê-la e a não lhe tocar — e
     * continua a dizer «manual» no fim.
     *
     * É a asserção que impede a implementação preguiçosa que «resolveria» um
     * emparelhamento adotando a proveniência do lado que chegou depois. Um feriado
     * que o professor escreveu é um feriado que o professor escreveu, e nada nesta
     * fase o desfaz.
     */
    #[Test]
    public function a_manual_row_stays_manual_through_a_suggestion_and_an_import(): void
    {
        $manual = $this->createManually('1.º de Maio', self::LABOUR_DAY);

        $this->confirmSuggestions(array_keys($this->suggestionsByDate()));
        $this->confirmImport($this->importPayload(forceInclude: true))->assertRedirect();

        $this->assertSame(1, $this->countOn(self::LABOUR_DAY));

        // A MESMA LINHA — e a coluna lida em cru, sem o cast do modelo pelo meio.
        $row = $this->inTenant(fn () => DB::table('academic_calendar_exceptions')->where('id', $manual->id)->first());

        $this->assertNotNull($row);
        $this->assertSame(AcademicCalendarExceptionSource::Manual->value, $row->source);
        // A coluna de data vem como o driver a guarda (o SQLite dos testes junta-lhe
        // a hora); o que interessa afirmar é o DIA, e não o formato do motor.
        $this->assertSame(self::LABOUR_DAY, mb_substr((string) $row->starts_on, 0, 10));
        $this->assertSame(self::LABOUR_DAY, mb_substr((string) $row->ends_on, 0, 10));
    }

    #[Test]
    public function no_row_anywhere_ever_changes_the_source_it_was_born_with(): void
    {
        $manual = $this->createManually('1.º de Maio', self::LABOUR_DAY);

        $this->confirmSuggestions([self::CHRISTMAS]);
        $christmas = $this->storedOn(self::CHRISTMAS);

        $this->confirmImport($this->importPayload(forceInclude: true))->assertRedirect();

        $this->assertSame(AcademicCalendarExceptionSource::Manual, $this->freshSourceOf($manual->id));
        $this->assertSame(AcademicCalendarExceptionSource::Suggested, $this->freshSourceOf($christmas->id));

        // E as que a importação criou de raiz nasceram importadas — os três estados
        // convivem na mesma tabela, cada um a dizer a verdade sobre a sua linha.
        $this->assertSame(AcademicCalendarExceptionSource::Imported, $this->sourceOn('2031-04-25'));
    }

    // ══════════════════════════════════════════ 5. reimportar continua idempotente

    /**
     * O AFINAMENTO NÃO AFROUXOU NEM APERTOU A REIMPORTAÇÃO. Um ficheiro reimportado
     * contra si próprio tem títulos byte a byte iguais, e por isso continua a dar
     * «já existente» em todas as linhas — a normalização de designações nunca chega
     * a ter nada para fazer neste caso. Dois ciclos completos, e não duas
     * confirmações da mesma pré-visualização.
     */
    #[Test]
    public function reimporting_the_very_same_file_twice_creates_no_duplicate(): void
    {
        $this->confirmImport($this->importPayload(forceInclude: true))->assertRedirect();

        $before = $this->rowCounts();

        $this->confirmImport($this->importPayload(forceInclude: true))->assertRedirect();

        $this->assertSame($before, $this->rowCounts());
    }

    #[Test]
    public function the_second_preview_of_the_same_file_shows_every_exception_as_already_existing(): void
    {
        $this->confirmImport($this->importPayload(forceInclude: true))->assertRedirect();

        $props = $this->previewProps();

        foreach ([...$props['schoolBreaks'], ...$props['holidays']] as $row) {
            $this->assertSame(
                Preview::STATE_EXISTS,
                $row['state'],
                "{$row['title']} devia estar já existente, e não «{$row['state']}».",
            );
        }
    }

    /**
     * E SUGERIR DUAS VEZES TAMBÉM NÃO DUPLICA NADA — a mesma promessa pela terceira
     * porta.
     */
    #[Test]
    public function suggesting_twice_creates_no_duplicate_either(): void
    {
        $dates = array_keys($this->suggestionsByDate());

        $this->confirmSuggestions($dates);
        $before = $this->rowCounts();

        $this->confirmSuggestions($dates);

        $this->assertSame($before, $this->rowCounts());
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  list<string>  $dates
     */
    private function confirmSuggestions(array $dates): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->post("/academic-years/{$this->academicYear->ulid}/holiday-suggestions", ['dates' => $dates])
            ->assertSessionHasNoErrors();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function suggestionsByDate(): array
    {
        $rows = $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->getJson("/academic-years/{$this->academicYear->ulid}/holiday-suggestions")
            ->assertOk()
            ->json('suggestions');

        return array_column($rows, null, 'date');
    }

    private function createManually(string $title, string $date): AcademicCalendarException
    {
        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->post("/academic-years/{$this->academicYear->ulid}/exceptions", [
                'type' => 'holiday',
                'title' => $title,
                'starts_on' => $date,
                'ends_on' => $date,
                'note' => null,
            ])
            ->assertSessionHasNoErrors();

        return $this->storedOn($date);
    }

    /**
     * @return array<string, mixed>
     */
    private function previewProps(): array
    {
        $props = [];

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->post('/academic-calendar-imports', [
                'calendar' => UploadedFile::fake()->createWithContent('calendario.xlsx', Fixture::example()->bytes()),
            ])
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /**
     * O payload que a página de pré-visualização construiria — montado a partir de
     * uma pré-visualização REAL, para que o que se confirma seja o que o professor
     * teria visto.
     *
     * @return array<string, mixed>
     */
    private function importPayload(bool $forceInclude = false): array
    {
        $props = $this->previewProps();

        $exceptions = array_map(fn (array $row): array => [
            'include' => $forceInclude ? $row['state'] !== Preview::STATE_OUT_OF_YEAR : $row['include'],
            'type' => $row['type'],
            'title' => $row['title'],
            'starts_on' => $row['starts_on'],
            'ends_on' => $row['ends_on'],
            'note' => $row['note'],
        ], [...$props['schoolBreaks'], ...$props['holidays']]);

        return [
            'academic_year_ulid' => $props['academicYear']['ulid'],
            // OS PERÍODOS FICAM DE FORA: este ficheiro é sobre feriados e
            // interrupções, e uma lista de períodos por confirmar só acrescentaria
            // ruído às contagens.
            'semesters' => [],
            'exceptions' => $exceptions,
            'events' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirmImport(array $payload): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->post('/academic-calendar-imports/confirm', $payload);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowFor(array $rows, string $startsOn): array
    {
        foreach ($rows as $row) {
            if ($row['starts_on'] === $startsOn) {
                return $row;
            }
        }

        $this->fail("Nenhuma linha a começar em {$startsOn}.");
    }

    private function countOn(string $date): int
    {
        return $this->inTenant(fn (): int => AcademicCalendarException::query()
            ->where('academic_year_id', $this->academicYear->id)
            ->whereDate('starts_on', $date)
            ->whereDate('ends_on', $date)
            ->count());
    }

    private function storedOn(string $date): AcademicCalendarException
    {
        return $this->inTenant(fn (): AcademicCalendarException => AcademicCalendarException::query()
            ->where('academic_year_id', $this->academicYear->id)
            ->whereDate('starts_on', $date)
            ->whereDate('ends_on', $date)
            ->sole());
    }

    private function sourceOn(string $date): AcademicCalendarExceptionSource
    {
        return $this->storedOn($date)->source;
    }

    private function freshSourceOf(int $id): AcademicCalendarExceptionSource
    {
        return $this->inTenant(fn (): AcademicCalendarExceptionSource => AcademicCalendarException::query()
            ->whereKey($id)
            ->sole()
            ->source);
    }

    private function assertStoredTitle(string $date, string $title): void
    {
        $this->assertSame($title, $this->storedOn($date)->title);
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return $this->inTenant(fn (): array => [
            'academic_calendar_exceptions' => AcademicCalendarException::query()->count(),
            'calendar_events' => CalendarEvent::query()->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantSession(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'academic_year_id' => $this->academicYear->id,
        ];
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

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
