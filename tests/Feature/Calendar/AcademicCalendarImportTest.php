<?php

namespace Tests\Feature\Calendar;

use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicPeriodStatus;
use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CalendarEventType;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Import\AcademicCalendar\BuildAcademicCalendarImportPreview as Preview;
use App\Support\Entitlements\Entitlements;
use App\Support\Import\SpreadsheetZipSafety;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\AcademicCalendarXlsxBuilder as Fixture;
use Tests\TestCase;

/**
 * Importar o calendário da escola, de ponta a ponta e pelas rotas reais.
 *
 * O ficheiro em teste é um .xlsx real, gerado à medida — ver
 * AcademicCalendarXlsxBuilder para o que lá está e porque é que cada forma
 * incómoda lá está. O calendário contra o qual esta funcionalidade foi desenhada é
 * a proposta real de um agrupamento real, e fica inteiramente fora deste
 * repositório.
 *
 * AS ASSERÇÕES MAIS IMPORTANTES SÃO AS DE QUE NADA ACONTECE: que carregar e
 * pré-visualizar não escreve uma única linha em tabela nenhuma, que uma linha por
 * marcar nunca é escrita, que reimportar o mesmo ficheiro duas vezes não duplica
 * nada, e que a linha cujo fim o documento diz de três maneiras não se pode
 * confirmar enquanto ninguém escolher uma.
 */
class AcademicCalendarImportTest extends TestCase
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
        $this->subscribeToPro($this->organization);

        // Um ano explícito e fixo, que contém inteiramente o calendário da
        // fixture: as datas do documento são o assunto de metade destes testes, e
        // um ano aleatório tornava metade deles um lançamento de moeda.
        $this->academicYear = $this->inTenant(fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create([
                'label' => '2030/2031',
                'starts_on' => '2030-09-01',
                'ends_on' => '2031-07-31',
            ]));
    }

    // ---------------------------------------------------------------- o acesso

    #[Test]
    public function the_upload_page_is_reachable_and_names_the_selected_year(): void
    {
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/academic-calendar-imports/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('academic-calendar-imports/Create')
                ->where('academicYear.label', '2030/2031'));
    }

    // ---------------------------------------------------------------- o upload

    #[Test]
    public function a_file_that_is_not_an_xlsx_is_refused_with_a_validation_error(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('calendario.txt', 'não é uma folha'))
            ->assertRedirect()
            ->assertSessionHasErrors('calendar');

        $this->assertWroteNothing();
    }

    #[Test]
    public function a_pdf_is_refused_rather_than_read_badly(): void
    {
        $this->upload(UploadedFile::fake()->create('calendario.pdf', 10, 'application/pdf'))
            ->assertRedirect()
            ->assertSessionHasErrors('calendar');

        $this->assertWroteNothing();
    }

    #[Test]
    public function a_spreadsheet_that_is_not_a_calendar_fails_with_a_sentence_and_not_a_stack_trace(): void
    {
        $this->upload($this->file(Fixture::withoutACalendar()))
            ->assertRedirect()
            ->assertSessionHasErrors('calendar');

        $this->assertStringContainsString(
            'calendário escolar',
            (string) session('errors')?->first('calendar'),
        );
        $this->assertWroteNothing();
    }

    /**
     * A defesa contra pacotes .xlsx hostis é a que já existe —
     * SpreadsheetZipSafety, a mesma dos leitores de grelhas de correção —, e é
     * chamada antes de o PhpSpreadsheet ver o ficheiro. Aqui recusa tudo, o que
     * prova que está mesmo no caminho; o professor recebe uma frase e não uma
     * exceção.
     */
    #[Test]
    public function a_package_that_fails_the_zip_safety_check_is_refused_cleanly(): void
    {
        $this->swap(SpreadsheetZipSafety::class, new class extends SpreadsheetZipSafety
        {
            public function isSafe(string $absolutePath): bool
            {
                return false;
            }
        });

        $this->upload($this->file())
            ->assertRedirect()
            ->assertSessionHasErrors('calendar');

        $this->assertStringContainsString('segurança', (string) session('errors')?->first('calendar'));
        $this->assertWroteNothing();
    }

    // ------------------------------------------------------- a pré-visualização

    /**
     * A ASSERÇÃO CENTRAL DO PASSO DO MEIO. Carregar e pré-visualizar são leituras,
     * e uma leitura que escreve é a única falha desta página que ninguém notaria
     * até ser tarde.
     */
    #[Test]
    public function previewing_an_import_writes_absolutely_nothing(): void
    {
        $before = $this->rowCounts();

        $this->preview()->assertOk();

        $this->assertSame($before, $this->rowCounts());
    }

    #[Test]
    public function the_preview_groups_everything_the_document_holds(): void
    {
        $this->preview()->assertInertia(function (AssertableInertia $page): void {
            $props = $page->component('academic-calendar-imports/Preview')->toArray()['props'];

            $this->assertCount(2, $props['semesters']);
            $this->assertCount(4, $props['schoolBreaks']);
            // «Datas e eventos escolares» É UMA LISTA SÓ — quinze dias sem aula
            // (treze feriados realçados, o feriado municipal escrito por extenso e
            // o dia não letivo) e sete acontecimentos (quatro do documento e os
            // três fins de ano por coorte), misturados por data.
            $this->assertCount(22, $props['datedItems']);
            $this->assertCount(15, $this->exceptionRows($props));
            $this->assertCount(7, $this->eventRows($props));

            // POR DATA, e não por tabela de destino: é assim que se confere uma
            // importação contra um calendário impresso.
            $dates = array_column($props['datedItems'], 'starts_on');
            $sorted = $dates;
            sort($sorted);
            $this->assertSame($sorted, $dates);

            $this->assertSame(Fixture::SCHOOL, $props['schoolName']);
            $this->assertFalse($props['yearMismatch']);
        });
    }

    /**
     * A PRÉ-VISUALIZAÇÃO NUNCA IMPRIME «FERIADO» POR OMISSÃO.
     *
     * Era o que fazia: o professor lia «Reunião de avaliação · Feriado» e não
     * tinha como saber que a aplicação estava a afirmar que naquele dia não havia
     * aula. Cada linha diz agora o que É, e o rótulo vem do enum — que é a única
     * fonte deste texto no servidor — e nunca de uma palavra escrita na página.
     */
    #[Test]
    public function every_row_carries_the_kind_it_really_is(): void
    {
        $this->preview()->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            $byDate = [];
            foreach ($props['datedItems'] as $row) {
                $byDate[$row['starts_on']] = $row;
            }

            $expected = [
                // O documento pinta-o de laranja: é a classificação dele.
                '2030-12-25' => ['Feriado', 'academic_calendar_exception'],
                // O documento escreve-o por extenso, sem cor nenhuma.
                '2031-05-13' => ['Feriado', 'academic_calendar_exception'],
                '2031-04-15' => ['Dia não letivo', 'academic_calendar_exception'],
                // E estes NÃO retiram aula nenhuma a ninguém.
                '2030-10-16' => ['Reunião', 'calendar_event'],
                '2030-11-07' => ['Atividade', 'calendar_event'],
                '2031-01-15' => ['Visita de estudo', 'calendar_event'],
                '2030-09-17' => ['Data relevante', 'calendar_event'],
            ];

            foreach ($expected as $date => [$label, $destination]) {
                $this->assertArrayHasKey($date, $byDate);
                $this->assertSame($label, $byDate[$date]['type_label'], "A linha de {$date}.");
                $this->assertSame($destination, $byDate[$date]['destination'], "A linha de {$date}.");
            }
        });
    }

    /**
     * UM ACONTECIMENTO NUNCA VEM PRÉ-MARCADO (§29), seja ele um fim de coorte ou
     * uma reunião que o documento nomeia. É pessoal — fica com o nome de quem
     * importa — e traz sempre a explicação de porque é que não é um dia sem aula.
     */
    #[Test]
    public function no_school_event_is_ever_pre_ticked(): void
    {
        $this->preview()->assertInertia(function (AssertableInertia $page): void {
            foreach ($this->eventRows($page->toArray()['props']) as $item) {
                $this->assertFalse($item['include'], "«{$item['title']}» veio pré-marcado.");
                $this->assertNotSame('', (string) $item['explanation']);
            }
        });
    }

    /**
     * A ESPÉCIE CLASSIFICADA CHEGA À BASE DE DADOS, e não «outro» para toda a
     * gente: uma reunião lida do documento entra no calendário do professor como
     * reunião.
     */
    #[Test]
    public function a_confirmed_meeting_is_stored_as_a_meeting(): void
    {
        $payload = $this->payload();

        foreach ($payload['events'] as $index => $row) {
            $payload['events'][$index]['include'] = $row['starts_on'] === '2030-10-16';
        }

        $this->confirm($payload)->assertRedirect();

        $meeting = $this->inTenant(fn () => CalendarEvent::query()
            ->where('title', 'Reunião de avaliação')
            ->first());

        $this->assertNotNull($meeting);
        $this->assertSame(CalendarEventType::Meeting, $meeting->type);
    }

    #[Test]
    public function a_brand_new_year_shows_the_first_semester_as_new_and_pre_ticked(): void
    {
        $first = $this->previewProps()['semesters'][0];

        $this->assertSame(Preview::STATE_NEW, $first['state']);
        $this->assertTrue($first['include']);
        $this->assertSame(Fixture::FIRST_SEMESTER_STARTS_ON, $first['starts_on']);
        $this->assertSame(Fixture::FIRST_SEMESTER_ENDS_ON, $first['ends_on']);
        $this->assertNull($first['current']);
    }

    /**
     * «JÁ EXISTENTE» — está lá, igualzinho, e não há nada a fazer. Não se recria e
     * não se pode marcar.
     */
    #[Test]
    public function a_semester_already_stored_with_the_same_dates_shows_as_already_existing(): void
    {
        $this->existingPeriod('1.º Semestre', Fixture::FIRST_SEMESTER_STARTS_ON, Fixture::FIRST_SEMESTER_ENDS_ON);

        $first = $this->previewProps()['semesters'][0];

        $this->assertSame(Preview::STATE_EXISTS, $first['state']);
        $this->assertFalse($first['include']);
        $this->assertNotNull($first['current']);
    }

    /**
     * «ALTERADO» — o mesmo nome, datas diferentes. As DUAS versões viajam, porque
     * aceitar isto é sobrescrever a estrutura de um ano e isso não se faz às
     * cegas; e nunca vem pré-marcado.
     */
    #[Test]
    public function a_semester_stored_with_different_dates_shows_as_changed_with_both_versions(): void
    {
        $this->existingPeriod('1.º Semestre', '2030-09-01', '2031-01-15');

        $first = $this->previewProps()['semesters'][0];

        $this->assertSame(Preview::STATE_CHANGED, $first['state']);
        $this->assertFalse($first['include']);
        $this->assertSame('2030-09-01', $first['current']['starts_on']);
        $this->assertSame('2031-01-15', $first['current']['ends_on']);
        $this->assertSame(Fixture::FIRST_SEMESTER_STARTS_ON, $first['starts_on']);
        $this->assertSame(Fixture::FIRST_SEMESTER_ENDS_ON, $first['ends_on']);
    }

    /**
     * «REQUER ESCOLHA» (§32). Três datas de fim para um campo que guarda uma.
     * NENHUMA vem escolhida, `ends_on` chega vazio, e a linha não pode ser
     * confirmada assim — ver o teste da confirmação mais abaixo.
     */
    #[Test]
    public function the_second_semester_requires_an_explicit_choice_and_defaults_to_none(): void
    {
        $second = $this->previewProps()['semesters'][1];

        $this->assertSame(Preview::STATE_NEEDS_CHOICE, $second['state']);
        $this->assertFalse($second['include']);
        $this->assertNull($second['ends_on']);
        $this->assertCount(3, $second['end_candidates']);

        $this->assertSame(
            array_values(Fixture::SECOND_SEMESTER_ENDS_ON),
            array_column($second['end_candidates'], 'value'),
        );
        $this->assertSame(
            array_keys(Fixture::SECOND_SEMESTER_ENDS_ON),
            array_column($second['end_candidates'], 'cohort'),
        );
        // Nenhuma delas é «manter a data atual», porque não há data atual nenhuma.
        $this->assertSame([false, false, false], array_column($second['end_candidates'], 'keep_current'));
    }

    /**
     * A QUARTA OPÇÃO, e só quando existe: manter o que o período já tem. Sem ela,
     * «não gosto de nenhuma das três» e «ainda não decidi» eram a mesma resposta.
     */
    #[Test]
    public function an_existing_second_semester_adds_keeping_its_current_date_as_a_fourth_option(): void
    {
        $this->existingPeriod('2.º Semestre', Fixture::SECOND_SEMESTER_STARTS_ON, '2031-06-20');

        $second = $this->previewProps()['semesters'][1];

        $this->assertSame(Preview::STATE_NEEDS_CHOICE, $second['state']);
        $this->assertCount(4, $second['end_candidates']);
        $this->assertSame('2031-06-20', $second['end_candidates'][3]['value']);
        $this->assertTrue($second['end_candidates'][3]['keep_current']);
        $this->assertNull($second['ends_on']);
    }

    #[Test]
    public function every_exception_is_new_and_pre_ticked_on_an_empty_year(): void
    {
        $props = $this->previewProps();

        foreach ([...$props['schoolBreaks'], ...$this->exceptionRows($props)] as $row) {
            $this->assertSame(Preview::STATE_NEW, $row['state']);
            $this->assertTrue($row['include']);
            $this->assertNull($row['current']);
        }
    }

    #[Test]
    public function an_exception_already_stored_with_the_same_type_and_dates_shows_as_already_existing(): void
    {
        $this->existingException(AcademicCalendarExceptionType::SchoolBreak, 'Natal', '2030-12-23', '2030-12-31');

        $natal = $this->rowFor($this->previewProps()['schoolBreaks'], '2030-12-23');

        $this->assertSame(Preview::STATE_EXISTS, $natal['state']);
        $this->assertFalse($natal['include']);
        $this->assertSame('Natal', $natal['current']['title']);
    }

    /**
     * «CONFLITO» — a mesma espécie, datas que se tocam sem serem as mesmas. Pode
     * ser a versão nova do calendário, pode ser a antiga que ficou a mais um dia;
     * não há regra que saiba qual, e por isso não há regra nenhuma a decidir.
     */
    #[Test]
    public function an_overlapping_exception_of_the_same_kind_shows_as_a_conflict_with_both_versions(): void
    {
        $this->existingException(
            AcademicCalendarExceptionType::SchoolBreak,
            'Interrupção do Natal (versão antiga)',
            '2030-12-20',
            '2031-01-02',
        );

        $natal = $this->rowFor($this->previewProps()['schoolBreaks'], '2030-12-23');

        $this->assertSame(Preview::STATE_CONFLICT, $natal['state']);
        $this->assertFalse($natal['include']);
        $this->assertSame('2030-12-20', $natal['current']['starts_on']);
        $this->assertSame('2031-01-02', $natal['current']['ends_on']);
    }

    /**
     * ESPÉCIES DIFERENTES NÃO CONFLITUAM. Um feriado no meio de uma interrupção é
     * exatamente o que o dia 25 de dezembro é, e as duas linhas são ambas
     * verdadeiras — o modelo da Fase 5.4 permite a sobreposição de propósito.
     */
    #[Test]
    public function an_interruption_already_stored_never_turns_a_holiday_inside_it_into_a_conflict(): void
    {
        $this->existingException(AcademicCalendarExceptionType::SchoolBreak, 'Natal', '2030-12-23', '2030-12-31');

        $christmas = $this->rowFor($this->exceptionRows($this->previewProps()), '2030-12-25');

        $this->assertSame(Preview::STATE_NEW, $christmas['state']);
        $this->assertTrue($christmas['include']);
    }

    /**
     * O ficheiro de outro ano AVISA E NUNCA BLOQUEIA — a mesma disciplina do
     * importador de horários — mas as linhas que caem fora do ano selecionado são
     * marcadas como tal, porque gravá-las era impossível e falhar no fim seria
     * pior do que explicá-lo aqui.
     */
    #[Test]
    public function a_calendar_from_another_year_is_warned_about_and_its_rows_are_marked_out_of_year(): void
    {
        $otherYear = $this->inTenant(fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create(['label' => '2029/2030', 'starts_on' => '2029-09-01', 'ends_on' => '2030-07-31']));

        $this->actingAs($this->teacher)
            ->withSession([...$this->tenantSession(), 'academic_year_id' => $otherYear->id])
            ->post('/academic-calendar-imports', ['calendar' => $this->file()])
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                $this->assertTrue($props['yearMismatch']);
                $this->assertSame('2030/2031', $props['fileAcademicYear']);

                foreach ($this->exceptionRows($props) as $row) {
                    $this->assertSame(Preview::STATE_OUT_OF_YEAR, $row['state']);
                    $this->assertFalse($row['include']);
                }
            });

        // E o ano selecionado nunca muda nas costas do professor.
        $this->assertSame($otherYear->id, session('academic_year_id'));
    }

    // ------------------------------------------------------------- a confirmação

    #[Test]
    public function confirming_writes_the_periods_the_exceptions_and_nothing_else(): void
    {
        $payload = $this->payload(['2.º Semestre' => Fixture::SECOND_SEMESTER_ENDS_ON['9.º ano']]);

        $this->confirm($payload)->assertRedirect('/calendar');

        $this->inTenant(function (): void {
            $periods = AcademicPeriod::query()->orderBy('sequence')->get();

            $this->assertSame(['1.º Semestre', '2.º Semestre'], $periods->pluck('label')->all());
            $this->assertSame(
                [AcademicPeriodKind::Semester, AcademicPeriodKind::Semester],
                $periods->pluck('kind')->all(),
            );
            // Um período nasce sempre em rascunho — abrir e fechar é outra ação,
            // e nunca um efeito secundário de gravar a estrutura.
            $this->assertSame(
                [AcademicPeriodStatus::Draft, AcademicPeriodStatus::Draft],
                $periods->pluck('status')->all(),
            );
            $this->assertSame(Fixture::FIRST_SEMESTER_STARTS_ON, $periods[0]->starts_on->toDateString());
            $this->assertSame(Fixture::FIRST_SEMESTER_ENDS_ON, $periods[0]->ends_on->toDateString());
            $this->assertSame('2031-06-04', $periods[1]->ends_on->toDateString());

            // Quatro interrupções e treze feriados; nenhum acontecimento, porque
            // nenhum vinha marcado.
            $this->assertSame(19, AcademicCalendarException::query()->count());
            $this->assertSame(0, CalendarEvent::query()->count());
        });
    }

    /**
     * A PROVENIÊNCIA, QUE É TODA A PROVENIÊNCIA QUE O §14 PEDE, e que não precisou
     * de tabela nenhuma para existir: uma palavra na coluna que já lá estava.
     */
    #[Test]
    public function every_exception_this_import_creates_is_stamped_as_imported(): void
    {
        $this->confirm($this->payload())->assertRedirect();

        $this->inTenant(function (): void {
            $this->assertSame(19, AcademicCalendarException::query()->count());
            $this->assertSame(
                0,
                AcademicCalendarException::query()
                    ->where('source', '!=', AcademicCalendarExceptionSource::Imported->value)
                    ->count(),
            );
        });
    }

    /**
     * O «Carnaval» amarelo chega à base de dados como OBSERVAÇÃO da interrupção que
     * o contém — e não como título dela, nem como um feriado à parte.
     */
    #[Test]
    public function a_day_the_document_names_inside_an_interruption_arrives_as_a_note(): void
    {
        $this->confirm($this->payload())->assertRedirect();

        $this->inTenant(function (): void {
            $february = AcademicCalendarException::query()
                ->whereDate('starts_on', '2031-02-01')
                ->sole();

            $this->assertStringContainsString('Carnaval', (string) $february->note);
            $this->assertStringNotContainsString('Carnaval', $february->title);
        });
    }

    #[Test]
    public function a_row_the_teacher_unticked_is_never_written(): void
    {
        $payload = $this->payload();

        foreach ($payload['exceptions'] as $index => $row) {
            $payload['exceptions'][$index]['include'] = $row['starts_on'] === '2030-10-05';
        }

        $payload['semesters'] = array_map(
            fn (array $row): array => [...$row, 'include' => false],
            $payload['semesters'],
        );

        $this->confirm($payload)->assertRedirect();

        $this->inTenant(function (): void {
            $this->assertSame(0, AcademicPeriod::query()->count());
            $this->assertSame(1, AcademicCalendarException::query()->count());
            $this->assertSame('2030-10-05', AcademicCalendarException::query()->sole()->starts_on->toDateString());
        });
    }

    /**
     * A ESCOLHA DAS TRÊS DATAS É EXIGIDA PELO SERVIDOR, e não apenas desenhada na
     * página: uma linha de período marcada sem data de fim é recusada com uma frase
     * que o diz, e o lote inteiro fica por gravar.
     */
    #[Test]
    public function a_semester_cannot_be_confirmed_without_an_explicit_end_date(): void
    {
        $payload = $this->payload();

        // O 2.º Semestre marcado, mas sem escolha nenhuma feita — exatamente o
        // estado em que a pré-visualização o entrega.
        $payload['semesters'][1]['include'] = true;
        $payload['semesters'][1]['ends_on'] = null;

        $this->confirm($payload)->assertSessionHasErrors('semesters.1.ends_on');

        $this->inTenant(fn () => $this->assertSame(0, AcademicPeriod::query()->count()));
        $this->inTenant(fn () => $this->assertSame(0, AcademicCalendarException::query()->count()));
    }

    #[Test]
    public function an_unchosen_second_semester_left_unticked_never_blocks_the_rest(): void
    {
        // O 2.º Semestre por marcar e sem data — e as outras dezoito linhas gravam
        // na mesma. Uma linha em aberto não é um lote inválido.
        $this->confirm($this->payload())->assertRedirect();

        $this->inTenant(function (): void {
            $this->assertSame(['1.º Semestre'], AcademicPeriod::query()->pluck('label')->all());
            $this->assertSame(19, AcademicCalendarException::query()->count());
        });
    }

    /**
     * «ALTERADO» ACEITE ATUALIZA A LINHA QUE JÁ LÁ ESTAVA, e não cria um segundo
     * «1.º Semestre» ao lado do primeiro. É o ulid que faz a diferença, e é por isso
     * que ele viaja — e é por isso que a confirmação o volta a verificar.
     */
    #[Test]
    public function accepting_a_changed_semester_updates_the_period_in_place(): void
    {
        $existing = $this->existingPeriod('1.º Semestre', '2030-09-01', '2031-01-15');

        $payload = $this->payload();
        $payload['semesters'][0]['include'] = true;

        $this->assertSame($existing->ulid, $payload['semesters'][0]['ulid']);

        $this->confirm($payload)->assertRedirect();

        $this->inTenant(function () use ($existing): void {
            $period = AcademicPeriod::query()->sole();

            // A MESMA LINHA — mesmo id e mesmo ulid —, e não uma nova. Tudo o que
            // um dia venha a apontar para este período sobrevive.
            $this->assertSame($existing->id, $period->id);
            $this->assertSame($existing->ulid, $period->ulid);
            $this->assertSame(Fixture::FIRST_SEMESTER_STARTS_ON, $period->starts_on->toDateString());
            $this->assertSame(Fixture::FIRST_SEMESTER_ENDS_ON, $period->ends_on->toDateString());
        });
    }

    /**
     * UM CONFLITO FORÇADO CONTINUA A SER SALTADO. A caixa desmarcada é uma
     * comodidade; a verificação que vale é a que a confirmação volta a fazer contra
     * a base de dados — e ela nunca escreve por cima de uma sobreposição só porque
     * o navegador insistiu.
     */
    #[Test]
    public function a_conflicting_exception_is_skipped_even_when_the_payload_insists(): void
    {
        $existing = $this->existingException(
            AcademicCalendarExceptionType::SchoolBreak,
            'Interrupção do Natal (versão antiga)',
            '2030-12-20',
            '2031-01-02',
        );

        $payload = $this->payload();

        foreach ($payload['exceptions'] as $index => $row) {
            $payload['exceptions'][$index]['include'] = $row['starts_on'] === '2030-12-23';
        }
        $payload['semesters'] = array_map(
            fn (array $row): array => [...$row, 'include' => false],
            $payload['semesters'],
        );

        $this->confirm($payload)->assertRedirect();

        $this->inTenant(function () use ($existing): void {
            // A linha antiga continua lá, intacta, e não ganhou companhia.
            $this->assertSame(1, AcademicCalendarException::query()->count());
            $this->assertSame($existing->id, AcademicCalendarException::query()->sole()->id);
            $this->assertSame(
                AcademicCalendarExceptionSource::Manual,
                AcademicCalendarException::query()->sole()->source,
            );
        });
    }

    /**
     * A ideia inteira da deduplicação, provada como o professor a viveria: dois
     * ciclos completos — carregar, pré-visualizar, confirmar — e não duas
     * confirmações da mesma pré-visualização. A segunda passagem tem de reler o que
     * está lá e encontrar o seu próprio trabalho já feito.
     */
    #[Test]
    public function importing_the_very_same_file_twice_creates_no_duplicate(): void
    {
        $this->confirm($this->payload(['2.º Semestre' => '2031-06-04']))->assertRedirect();

        $before = $this->rowCounts();

        // Tudo forçado a marcado à segunda: mesmo um professor que volte a picar
        // todas as caixas não pode acabar com duplicados.
        $second = $this->payload(['2.º Semestre' => '2031-06-04'], forceInclude: true);
        $this->confirm($second)->assertRedirect();

        $this->assertSame($before, $this->rowCounts());
    }

    #[Test]
    public function the_second_preview_shows_everything_as_already_existing(): void
    {
        $this->confirm($this->payload(['2.º Semestre' => '2031-06-04']))->assertRedirect();

        $props = $this->previewProps();

        foreach ([...$props['schoolBreaks'], ...$this->exceptionRows($props)] as $row) {
            $this->assertSame(Preview::STATE_EXISTS, $row['state'], "{$row['title']} devia estar já existente.");
        }

        $this->assertSame(Preview::STATE_EXISTS, $props['semesters'][0]['state']);
    }

    /**
     * O TÍTULO CHEGA JÁ POR EXTENSO. «Fim 9.º ano» é uma abreviatura escrita
     * para caber num quadradinho de junho; a partir daqui é o título de um
     * acontecimento do calendário deste professor, e vai ser lido em janeiro sem
     * a coluna ao lado a explicá-lo. Quem o escreve por extenso é o parser
     * (CohortMarkerTitle) — não a página, não este controlador.
     */
    #[Test]
    public function a_confirmed_marker_becomes_an_ordinary_calendar_event_of_the_importing_teacher(): void
    {
        $payload = $this->payload();

        // PELA DATA E NÃO PELA POSIÇÃO. A lista dos acontecimentos deixou de ser
        // só dos três fins de coorte no momento em que as reuniões e as atividades
        // pararam de ser escritas como feriados, e vem ordenada por data — «o
        // primeiro da lista» já não quer dizer nada.
        foreach ($payload['events'] as $index => $row) {
            $payload['events'][$index]['include'] = $row['starts_on'] === '2031-06-04';
        }

        $this->confirm($payload)->assertRedirect();

        $this->inTenant(function (): void {
            $event = CalendarEvent::query()->sole();

            $this->assertSame('Fim das atividades letivas — 9.º ano', $event->title);
            $this->assertSame(CalendarEventType::Other, $event->type);
            $this->assertSame('Data relevante', $event->type->label());
            $this->assertSame('2031-06-04', $event->starts_on->toDateString());
            $this->assertSame('2031-06-04', $event->ends_on->toDateString());
            $this->assertSame($this->teacher->id, $event->user_id);
        });

        // E É UM CalendarEvent, NUNCA UMA EXCEÇÃO LETIVA. Uma «Data relevante»
        // não diz que naquele dia não há aula — dizê-lo era uma
        // AcademicCalendarException, e uma linha destas nunca vira uma dessas.
        $this->assertDatabaseHas('calendar_events', [
            'type' => 'other',
            'title' => 'Fim das atividades letivas — 9.º ano',
        ]);
        $this->inTenant(function (): void {
            $this->assertSame(0, AcademicCalendarException::query()
                ->whereDate('starts_on', '2031-06-04')
                ->count());
        });
    }

    /**
     * CONFIRMAR OS TRÊS MARCADORES NÃO TOCA NAS AULAS NEM NO HORÁRIO. Uma «Data
     * relevante» é uma nota no calendário e não uma decisão sobre a forma do ano:
     * não materializa aulas, não as apaga, não mexe num tempo do horário nem num
     * período. As contagens antes e depois são a prova.
     */
    #[Test]
    public function confirming_the_markers_never_touches_lessons_periods_or_the_timetable(): void
    {
        $payload = $this->payload();

        foreach (array_keys($payload['events']) as $index) {
            $payload['events'][$index]['include'] = true;
        }

        // Só os acontecimentos: os períodos e as exceções ficam de fora para que
        // as contagens meçam esta confirmação e mais nada.
        $payload['semesters'] = array_map(fn (array $row): array => [...$row, 'include' => false], $payload['semesters']);
        $payload['exceptions'] = array_map(fn (array $row): array => [...$row, 'include' => false], $payload['exceptions']);

        $before = $this->inTenant(fn (): array => [
            'academic_periods' => AcademicPeriod::query()->count(),
            'academic_calendar_exceptions' => AcademicCalendarException::query()->count(),
            'lessons' => Lesson::withoutGlobalScope('organization')->count(),
            'recurring_lesson_slots' => RecurringLessonSlot::withoutGlobalScope('organization')->count(),
        ]);

        $this->confirm($payload)->assertRedirect();

        $after = $this->inTenant(fn (): array => [
            'academic_periods' => AcademicPeriod::query()->count(),
            'academic_calendar_exceptions' => AcademicCalendarException::query()->count(),
            'lessons' => Lesson::withoutGlobalScope('organization')->count(),
            'recurring_lesson_slots' => RecurringLessonSlot::withoutGlobalScope('organization')->count(),
        ]);

        $this->assertSame($before, $after);
        // Sete: os três fins de coorte e os quatro acontecimentos que o documento
        // nomeia (a apresentação, a reunião, o convívio e a visita de estudo).
        $this->assertSame(7, $this->inTenant(fn (): int => CalendarEvent::query()->count()));
    }

    /**
     * «Importação concluída» sozinho escondia as linhas que ficaram por fazer, que
     * são exatamente aquelas sobre as quais ainda há alguma coisa a decidir.
     */
    #[Test]
    public function the_closing_message_counts_what_actually_happened(): void
    {
        $this->confirm($this->payload(['2.º Semestre' => '2031-06-04']))
            ->assertSessionHas('inertia.flash_data', function (array $flash): bool {
                $message = (string) ($flash['toast']['message'] ?? '');

                return str_contains($message, '2 período(s) criado(s).')
                    // «DIAS SEM AULA» E JÁ NÃO «FERIADOS/INTERRUPÇÕES»: a mesma
                    // tabela recebe agora também os dias não letivos, e a frase
                    // tinha de deixar de enumerar duas das três espécies.
                    && str_contains($message, '19 dia(s) sem aula criado(s) na estrutura do ano.')
                    // Os sete acontecimentos, que ninguém marcou — nenhum deles
                    // vem pré-selecionado, e é essa a razão de estarem aqui.
                    && str_contains($message, '7 linha(s) não foram selecionadas.');
            });
    }

    // ------------------------------------------------------------------ tenancy

    #[Test]
    public function a_confirmation_naming_another_organizations_year_is_refused_and_writes_nothing(): void
    {
        $stranger = User::factory()->create();
        $strangersYear = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn (): AcademicYear => AcademicYear::factory()
                ->recycle($stranger->personalOrganization())
                ->create(['label' => '2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-07-31']),
        );

        $payload = $this->payload();
        $payload['academic_year_ulid'] = $strangersYear->ulid;

        // 403 e não 404: a resposta não pode dizer a ninguém se aquele ulid é real.
        $this->confirm($payload)->assertForbidden();

        $this->assertWroteNothing();
    }

    #[Test]
    public function a_confirmation_naming_another_organizations_period_is_refused_and_writes_nothing(): void
    {
        $stranger = User::factory()->create();
        $strangersPeriod = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            function () use ($stranger): AcademicPeriod {
                $year = AcademicYear::factory()->recycle($stranger->personalOrganization())->create();

                return AcademicPeriod::factory()->recycle($stranger->personalOrganization())->create([
                    'academic_year_id' => $year->id,
                    'label' => '1.º Semestre',
                ]);
            },
        );

        $payload = $this->payload();
        $payload['semesters'][0]['ulid'] = $strangersPeriod->ulid;

        $this->confirm($payload)->assertForbidden();

        $this->assertWroteNothing();
    }

    /**
     * Pertencer à organização não é pertencer a ESTE ANO — o par de verificações
     * que AcademicYearRequest já faz aos seus próprios ulids. Sem a segunda
     * metade, uma importação podia adotar (e reescrever) o período de um ano
     * vizinho.
     */
    #[Test]
    public function a_period_of_another_year_of_the_same_organization_is_refused(): void
    {
        $otherYear = $this->inTenant(fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create(['label' => '2029/2030', 'starts_on' => '2029-09-01', 'ends_on' => '2030-07-31']));

        $neighbour = $this->inTenant(fn (): AcademicPeriod => AcademicPeriod::factory()
            ->recycle($this->organization)
            ->create(['academic_year_id' => $otherYear->id, 'label' => '1.º Semestre']));

        $payload = $this->payload();
        $payload['semesters'][0]['ulid'] = $neighbour->ulid;

        $this->confirm($payload)->assertSessionHasErrors('semesters.0.ulid');

        $this->assertWroteNothing();
    }

    /**
     * A estrutura do ano é do dono da organização (AcademicYearPolicy): todo o
     * calendário, todas as turmas e todos os resultados dela dependem, e deixar
     * qualquer membro reescrevê-la era deixar a edição de um professor mexer o
     * chão debaixo das classificações de um colega. Importar é escrever, e por
     * isso segue a mesma política — incluindo o ecrã de escolher o ficheiro, que
     * não tem sentido nenhum para quem não pode gravar o que ele propõe.
     */
    #[Test]
    public function a_member_who_does_not_own_the_organization_cannot_import(): void
    {
        // Montado como o dono e ANTES de trocar de utilizador: os argumentos são
        // avaliados antes do pedido, e um payload construído a meio da cadeia
        // trocaria o utilizador de volta sem se dar por isso.
        $payload = $this->payload();
        $file = $this->file();

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->actingAs($colleague)->withSession($this->tenantSession())
            ->get('/academic-calendar-imports/create')
            ->assertForbidden();

        $this->actingAs($colleague)->withSession($this->tenantSession())
            ->post('/academic-calendar-imports', ['calendar' => $file])
            ->assertForbidden();

        $this->actingAs($colleague)->withSession($this->tenantSession())
            ->post('/academic-calendar-imports/confirm', $payload)
            ->assertForbidden();

        $this->assertWroteNothing();
    }

    /**
     * O suporte pode OLHAR para um calendário — as duas vistas não recusam nada,
     * porque olhar não muda nada — mas não pode importar um por outra pessoa. A
     * mesma linha que TimetableImportController e CalendarEventController já
     * traçam.
     */
    #[Test]
    public function impersonation_blocks_the_upload_and_the_confirmation(): void
    {
        // Montado ANTES de a sessão de suporte começar: construir o payload passa
        // por uma pré-visualização real, e essa também é recusada.
        $payload = $this->payload();

        $session = [...$this->tenantSession(), 'impersonator_id' => 999];

        $this->actingAs($this->teacher)->withSession($session)
            ->post('/academic-calendar-imports', ['calendar' => $this->file()])
            ->assertForbidden();

        $this->actingAs($this->teacher)->withSession($session)
            ->post('/academic-calendar-imports/confirm', $payload)
            ->assertForbidden();

        $this->assertWroteNothing();
    }

    // --------------------------------------------------------------- helpers

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->post('/academic-calendar-imports', ['calendar' => $file]);
    }

    private function preview(): TestResponse
    {
        return $this->upload($this->file());
    }

    /**
     * @return array<string, mixed>
     */
    private function previewProps(): array
    {
        $props = [];

        $this->preview()->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    /**
     * O payload que a página de pré-visualização construiria — montado a partir de
     * uma pré-visualização REAL e não escrito à mão, para que o que se confirma
     * seja o que o professor teria visto.
     *
     * @param  array<string, string>  $endDates  a escolha da data de fim, por rótulo de período
     * @return array<string, mixed>
     */
    private function payload(array $endDates = [], bool $forceInclude = false): array
    {
        $props = $this->previewProps();

        $semesters = array_map(function (array $row) use ($endDates, $forceInclude): array {
            $endsOn = $endDates[$row['label']] ?? $row['ends_on'];

            return [
                'include' => $endsOn !== null && ($forceInclude || $row['include'] || isset($endDates[$row['label']])),
                'ulid' => $row['current']['ulid'] ?? null,
                'label' => $row['label'],
                'kind' => $row['kind'],
                'sequence' => $row['sequence'],
                'starts_on' => $row['starts_on'],
                'ends_on' => $endsOn,
            ];
        }, $props['semesters']);

        $exceptions = array_map(fn (array $row): array => [
            'include' => $forceInclude ? $row['state'] !== Preview::STATE_OUT_OF_YEAR : $row['include'],
            'type' => $row['type'],
            'title' => $row['title'],
            'starts_on' => $row['starts_on'],
            'ends_on' => $row['ends_on'],
            'note' => $row['note'],
        ], [...$props['schoolBreaks'], ...$this->exceptionRows($props)]);

        $events = array_map(fn (array $row): array => [
            'include' => $row['include'],
            'type' => $row['type'],
            'title' => $row['title'],
            'starts_on' => $row['starts_on'],
            'ends_on' => $row['ends_on'],
        ], $this->eventRows($props));

        return [
            'academic_year_ulid' => $props['academicYear']['ulid'],
            'semesters' => $semesters,
            'exceptions' => $exceptions,
            'events' => $events,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirm(array $payload): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->post('/academic-calendar-imports/confirm', $payload);
    }

    private function file(?Fixture $builder = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'calendario.xlsx',
            ($builder ?? Fixture::example())->bytes(),
        );
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

    private function existingPeriod(string $label, string $startsOn, string $endsOn): AcademicPeriod
    {
        return $this->inTenant(fn (): AcademicPeriod => AcademicPeriod::factory()
            ->recycle($this->organization)
            ->create([
                'academic_year_id' => $this->academicYear->id,
                'label' => $label,
                'kind' => AcademicPeriodKind::Semester,
                'sequence' => AcademicPeriod::query()->where('academic_year_id', $this->academicYear->id)->count() + 1,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ]));
    }

    private function existingException(
        AcademicCalendarExceptionType $type,
        string $title,
        string $startsOn,
        string $endsOn,
    ): AcademicCalendarException {
        return $this->inTenant(fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)
            ->for($this->academicYear)
            ->create([
                'type' => $type,
                'title' => $title,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
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
            'academic_periods' => AcademicPeriod::query()->count(),
            'academic_calendar_exceptions' => AcademicCalendarException::query()->count(),
            'calendar_events' => CalendarEvent::query()->count(),
        ]);
    }

    /**
     * Nada foi escrito NO ANO PARA ONDE SE ESTAVA A IMPORTAR — que é a pergunta
     * certa, e não «a tabela está vazia»: alguns destes testes criam de propósito
     * um período de outro ano, ou de outra organização, para o oferecer ao payload,
     * e essas linhas existirem é o ponto de partida e não a falha.
     */
    private function assertWroteNothing(): void
    {
        $this->inTenant(function (): void {
            $this->assertSame(0, AcademicPeriod::query()->where('academic_year_id', $this->academicYear->id)->count());
            $this->assertSame(0, AcademicCalendarException::query()->where('academic_year_id', $this->academicYear->id)->count());
            $this->assertSame(0, CalendarEvent::query()->count());
        });
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

    /**
     * As linhas de «Datas e eventos escolares» que vão para a estrutura do ano —
     * os dias em que NÃO há aula.
     *
     * A SECÇÃO É UMA E OS DESTINOS SÃO DOIS, e é `destination` que os separa. Era
     * `$props['holidays']` enquanto tudo o que o documento marcasse era escrito
     * como feriado; hoje a lista traz feriados, dias não letivos, reuniões e
     * atividades misturados por data, que é a ordem por que um calendário se lê.
     *
     * @param  array<string, mixed>  $props
     * @return list<array<string, mixed>>
     */
    private function exceptionRows(array $props): array
    {
        return $this->rowsGoingTo($props, 'academic_calendar_exception');
    }

    /**
     * As que vão para o calendário do professor e não retiram aula nenhuma.
     *
     * @param  array<string, mixed>  $props
     * @return list<array<string, mixed>>
     */
    private function eventRows(array $props): array
    {
        return $this->rowsGoingTo($props, 'calendar_event');
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<array<string, mixed>>
     */
    private function rowsGoingTo(array $props, string $destination): array
    {
        return array_values(array_filter(
            $props['datedItems'],
            fn (array $row): bool => $row['destination'] === $destination,
        ));
    }
}
