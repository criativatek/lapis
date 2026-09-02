<?php

namespace Tests\Feature\Calendar;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicPeriodStatus;
use App\Models\AcademicYear;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Calendar\AcademicYearCalendarQuery;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Calendário do Ano Letivo» (calendar.index, calendar.year) — the year's own
 * structure and its avaliações, read together, in a Mês view and an Ano view.
 *
 * THE PAGE IS A READING AND NOTHING ELSE, and it owns no table: everything it
 * shows already exists as AcademicPeriod rows and Instrument.applied_on dates.
 * The hardest assertions here are the ones that nothing appears in the database
 * when it is opened or navigated — over lessons AND recurring_lesson_slots AND
 * instruments — because the failure being guarded against is precisely what
 * «Aulas e Sumários» deliberately does on its own weekly view.
 *
 * AULAS ARE ABSENT BY DECISION, not by accident. «Horário do Professor» (Fase
 * 5.1) already answers «que aulas tenho»; this calendar answers «o que é
 * relevante no meu ano», and repeating the horário here would make them one
 * page. Nothing below expects a Lesson, and one test proves none is created.
 *
 * Every assertion about «whose avaliações» is really an assertion about
 * SchoolClass::scopeTaughtBy — the same scoping «Horário do Professor» and
 * Turmas already use, called here rather than reproduced.
 */
class AcademicYearCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Organization $organization;

    private AcademicYear $academicYear;

    /** @var array<int, AcademicPeriod> */
    private array $anchorPeriods = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        // A fixed, explicit year: the initial-month rule below is about a real
        // relationship between «today» and the year's own bounds, and a random
        // factory year would make that relationship a coin toss.
        $this->academicYear = $this->inTenant(fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create([
                'label' => 'Calendário 2026/2027',
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-07-31',
            ]));
    }

    // ------------------------------------------------------------ o acesso

    #[Test]
    public function an_authorized_teacher_reaches_both_views(): void
    {
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('calendar/Month'));

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar/ano')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('calendar/Year'));
    }

    /**
     * A BASE TEACHER REACHES BOTH VIEWS. Matriz Mestre §2 ticks «Calendário
     * mensal/anual» for Base, Pro and Institucional alike, and the Base/Pro
     * realignment moved the `calendar` capability to match. This test used to
     * assert the opposite; it is inverted deliberately, and it is the
     * assertion that would catch the key drifting back into PRO_MODULES.
     */
    #[Test]
    public function a_base_teacher_reaches_both_views(): void
    {
        $base = User::factory()->create();
        $baseOrganization = $base->personalOrganization();

        $this->actingAs($base)->withSession(['organization_id' => $baseOrganization->id])
            ->get('/calendar')->assertOk();
        $this->actingAs($base)->withSession(['organization_id' => $baseOrganization->id])
            ->get('/calendar/ano')->assertOk();
    }

    /**
     * The one row of §2 that stays Pro: «Importação avançada de calendário».
     * The views above are open; the wizard that reads the agrupamento's .xlsx
     * is not, and it is gated on its own `calendar_import` key rather than on
     * the one the views use.
     */
    #[Test]
    public function a_base_teacher_does_not_reach_the_calendar_import(): void
    {
        $base = User::factory()->create();
        $baseOrganization = $base->personalOrganization();

        $this->actingAs($base)->withSession(['organization_id' => $baseOrganization->id])
            ->get('/academic-calendar-imports/create')->assertForbidden();
        $this->actingAs($base)->withSession(['organization_id' => $baseOrganization->id])
            ->post('/academic-calendar-imports')->assertForbidden();
        $this->actingAs($base)->withSession(['organization_id' => $baseOrganization->id])
            ->post('/academic-calendar-imports/confirm')->assertForbidden();
    }

    #[Test]
    public function a_guest_is_sent_to_log_in(): void
    {
        $this->get('/calendar')->assertRedirect('/login');
        $this->get('/calendar/ano')->assertRedirect('/login');
    }

    /**
     * Both views are ADDRESSES, not a toggle inside one page: each answers on
     * its own URL, so a bookmark and the back button both work on either.
     */
    #[Test]
    public function each_view_is_reachable_directly_at_its_own_address(): void
    {
        $this->assertSame('/calendar', route('calendar.index', [], false));
        $this->assertSame('/calendar/ano', route('calendar.year', [], false));
    }

    // ------------------------------------------------- o que aparece, e de quem

    #[Test]
    public function an_assessment_of_the_teachers_own_turma_appears_on_its_day(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->period('1.º Período', 1, '2026-09-01', '2026-12-18');
        $this->instrument($schoolClass, 'Teste de Frações', '2026-10-15');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $day = $this->dayOf($page, '2026-10-15');

                $this->assertCount(1, $day['assessments']);
                $this->assertSame('Teste de Frações', $day['assessments'][0]['title']);
                $this->assertSame('7.º C', $day['assessments'][0]['class_label']);
                $this->assertSame('Matemática', $day['assessments'][0]['subject']);
                $this->assertSame('Teste global', $day['assessments'][0]['type']);
            });
    }

    /**
     * The entry is a way in, never a dead end: it carries the real, existing
     * route to the element's own page, and that page really answers there.
     *
     * E CARREGA TAMBÉM O CAMINHO DE VOLTA, no próprio endereço: quem entrar por
     * aqui volta ao MÊS de onde partiu, e não ao mês de hoje nem à turma. É a
     * mesma mecânica de query string que «Avaliações» já usava
     * (?from=assessments) — o cliente lê-a, o servidor não precisa de saber de
     * nada — e o «month» é sempre o «Y-m» que o próprio servidor resolveu.
     */
    #[Test]
    public function an_assessment_links_to_its_own_real_page_and_carries_the_way_back(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $instrument = $this->instrument($schoolClass, 'Teste de Frações', '2026-10-15');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($instrument) {
                $day = $this->dayOf($page, '2026-10-15');
                $href = $day['assessments'][0]['href'];

                $this->assertSame(
                    "/instruments/{$instrument->ulid}?from=calendar&month=2026-10",
                    $href,
                );
                // A página do elemento é mesmo a que está lá: o link continua a
                // ser a rota real, e não um endereço inventado a seu lado.
                $this->assertStringStartsWith(
                    route('instruments.show', $instrument, false),
                    $href,
                );
            });
    }

    /**
     * O mês que viaja é o mês QUE SE ESTÁ A VER, e não um constante: navegar
     * para outro mês muda o caminho de volta com ele.
     */
    #[Test]
    public function the_way_back_names_the_month_actually_being_read(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');

        // 1 de novembro de 2026 é um domingo, e por isso é visível NAS DUAS
        // grelhas: na última linha de outubro e na primeira de novembro. O mesmo
        // elemento, lido de dois meses diferentes, volta para o mês certo.
        $this->instrument($schoolClass, 'Ficha de novembro', '2026-11-01');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertStringEndsWith(
                    '?from=calendar&month=2026-10',
                    $this->dayOf($page, '2026-11-01')['assessments'][0]['href'],
                );
            });

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-11')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertStringEndsWith(
                    '?from=calendar&month=2026-11',
                    $this->dayOf($page, '2026-11-01')['assessments'][0]['href'],
                );
            });
    }

    /**
     * E QUEM NÃO PERGUNTA POR UM MÊS NÃO RECEBE UM: a leitura sem contexto de
     * mês — a mesma que a vista de Ano faz, e que não desenha link nenhum —
     * continua a dar exatamente o endereço simples que sempre deu.
     */
    #[Test]
    public function a_reading_with_no_month_of_its_own_produces_the_plain_href(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $instrument = $this->instrument($schoolClass, 'Teste de Frações', '2026-10-15');

        $reading = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn (): array => app(AcademicYearCalendarQuery::class)->for(
                $this->teacher,
                $this->academicYear,
                CarbonImmutable::parse('2026-10-01'),
                CarbonImmutable::parse('2026-10-31'),
            ),
        );

        $this->assertSame(
            route('instruments.show', $instrument, false),
            $reading['assessments'][0]['href'],
        );
        $this->assertStringNotContainsString('from=calendar', $reading['assessments'][0]['href']);
    }

    #[Test]
    public function an_assessment_of_a_turma_the_teacher_does_not_teach_never_appears(): void
    {
        $own = $this->schoolClassFor($this->teacher, 'Minha turma');
        $this->instrument($own, 'A minha', '2026-10-15');

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $colleagueClass = $this->schoolClassFor($colleague, 'Turma do colega');
        $this->instrument($colleagueClass, 'A do colega', '2026-10-15');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $day = $this->dayOf($page, '2026-10-15');

                $this->assertSame(['A minha'], array_column($day['assessments'], 'title'));
            });
    }

    #[Test]
    public function an_assessment_from_another_organization_never_appears(): void
    {
        $own = $this->schoolClassFor($this->teacher, 'Minha turma');
        $this->instrument($own, 'A minha', '2026-10-15');

        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrganization);
        $strangerClass = $this->schoolClassFor($stranger, 'Turma de outra organização', $strangerOrganization);
        $this->instrument($strangerClass, 'A do estranho', '2026-10-15', $strangerOrganization);

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $day = $this->dayOf($page, '2026-10-15');

                $this->assertSame(['A minha'], array_column($day['assessments'], 'title'));
            });
    }

    // ------------------------------------------------------------ os limites

    /**
     * The grid's first row reaches back into the previous month and its last
     * row into the next, so «visible» is the GRID's range and not the month's:
     * October 2026 begins on a Thursday, so the grid opens on 28 September.
     * An avaliação on a day the teacher can SEE belongs in the cell it is shown
     * in; one on a day outside the grid entirely is absent.
     */
    #[Test]
    public function the_boundaries_of_the_visible_grid_are_read_exactly(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->instrument($schoolClass, 'Primeiro dia visível', '2026-09-28');
        $this->instrument($schoolClass, 'Primeiro do mês', '2026-10-01');
        $this->instrument($schoolClass, 'Último do mês', '2026-10-31');
        $this->instrument($schoolClass, 'Último dia visível', '2026-11-01');
        $this->instrument($schoolClass, 'Fora, antes', '2026-09-27');
        $this->instrument($schoolClass, 'Fora, depois', '2026-11-02');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $days = $page->toArray()['props']['days'];

                $this->assertSame('2026-09-28', $days[0]['date']);
                $this->assertSame('2026-11-01', $days[count($days) - 1]['date']);

                $titles = [];
                foreach ($days as $day) {
                    $titles = [...$titles, ...array_column($day['assessments'], 'title')];
                }

                $this->assertContains('Primeiro dia visível', $titles);
                $this->assertContains('Primeiro do mês', $titles);
                $this->assertContains('Último do mês', $titles);
                $this->assertContains('Último dia visível', $titles);
                $this->assertNotContains('Fora, antes', $titles);
                $this->assertNotContains('Fora, depois', $titles);
            });
    }

    #[Test]
    public function the_month_grid_is_whole_weeks_from_monday_to_sunday(): void
    {
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $days = $page->toArray()['props']['days'];

                $this->assertSame(0, count($days) % 7);
                $this->assertSame(1, Carbon::parse($days[0]['date'])->dayOfWeekIso);
                $this->assertSame(7, Carbon::parse($days[count($days) - 1]['date'])->dayOfWeekIso);
                // The leading and trailing days are marked as not belonging to
                // the month, so the page can show them as context.
                $this->assertFalse($days[0]['in_month']);
                $this->assertTrue($this->dayOf($page, '2026-10-01')['in_month']);
            });
    }

    // ---------------------------------------------------- os períodos do ano

    #[Test]
    public function a_day_inside_a_period_carries_it_and_a_day_in_a_gap_carries_none(): void
    {
        // A year is not required to be covered end to end — nothing validates
        // contiguity — so a gap is a REAL state, and a day in one is honestly
        // left without a período rather than attached to the nearest.
        $this->period('1.º Período', 1, '2026-09-01', '2026-10-10');
        $this->period('2.º Período', 2, '2026-10-20', '2026-12-18');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertSame('1.º Período', $this->dayOf($page, '2026-10-10')['period']['label']);
                $this->assertSame('2.º Período', $this->dayOf($page, '2026-10-20')['period']['label']);
                // Inside the gap: nothing, and nothing invented.
                $this->assertNull($this->dayOf($page, '2026-10-15')['period']);
                $this->assertNull($this->dayOf($page, '2026-10-11')['period']);
                $this->assertNull($this->dayOf($page, '2026-10-19')['period']);

                // Both bands cross this month, so both are offered as legend.
                $this->assertSame(
                    ['1.º Período', '2.º Período'],
                    array_column($page->toArray()['props']['periods'], 'label'),
                );
            });
    }

    /**
     * The período boundaries, exactly. A período beginning on the LAST visible
     * day of the grid, or ending on its FIRST, still crosses it — and both are
     * regressions waiting to happen, because these are date columns Eloquent
     * stores as «Y-m-d 00:00:00»: compared as raw strings against «Y-m-d»
     * bounds, «2026-11-01 00:00:00» is not <= «2026-11-01», and the band on the
     * last day disappears without any error at all.
     */
    #[Test]
    public function a_period_touching_the_grid_on_its_very_first_or_last_day_still_crosses_it(): void
    {
        // October 2026 opens on a Thursday, so the grid runs 28 Sep – 1 Nov.
        $this->period('Acaba no primeiro dia', 1, '2026-09-10', '2026-09-28');
        $this->period('Começa no último dia', 2, '2026-11-01', '2026-11-30');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $this->assertSame(
                    ['Acaba no primeiro dia', 'Começa no último dia'],
                    array_column($page->toArray()['props']['periods'], 'label'),
                );

                $this->assertSame('Acaba no primeiro dia', $this->dayOf($page, '2026-09-28')['period']['label']);
                $this->assertSame('Começa no último dia', $this->dayOf($page, '2026-11-01')['period']['label']);
            });
    }

    #[Test]
    public function a_period_that_does_not_cross_the_month_is_not_offered_for_it(): void
    {
        $this->period('1.º Período', 1, '2026-09-01', '2026-12-18');
        $this->period('2.º Período', 2, '2027-01-05', '2027-04-02');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('periods', 1)
                ->where('periods.0.label', '1.º Período')
                ->where('periods.0.kind_label', 'Período')
                ->etc());
    }

    /**
     * O SEMESTRE CERTO PARA CADA MÊS, mês a mês, num ano de dois semestres com
     * um intervalo real entre eles (30/01 a 10/02).
     *
     * A vista de Mês passou a escrever a estrutura do ano com o «desde»/«até» da
     * vista de Ano, e essa frase só é honesta se o período que lhe chega for o
     * que realmente toca o mês. O que se afirma aqui é só isso — a sobreposição,
     * que é a parte do servidor. Como ela é DITA («até 29/01» em janeiro) é
     * decisão da página, e é lá que está afirmada.
     */
    #[Test]
    public function each_month_is_offered_the_semester_that_really_touches_it(): void
    {
        $this->period('1.º Semestre', 1, '2026-09-11', '2027-01-29', AcademicPeriodKind::Semester);
        $this->period('2.º Semestre', 2, '2027-02-11', '2027-07-31', AcademicPeriodKind::Semester);

        $expected = [
            '2026-10' => ['1.º Semestre'],
            '2026-12' => ['1.º Semestre'],
            // Janeiro: o semestre fecha a 29, e continua a ser o semestre de
            // janeiro — a sobreposição é o que o servidor responde, e responde-a
            // sem se deixar apanhar pelo «Y-m-d 00:00:00» das colunas de data.
            '2027-01' => ['1.º Semestre'],
            // Fevereiro: o 1.º Semestre já fechou, e o que toca o mês é o 2.º —
            // nunca um período «pegajoso» do mês anterior.
            '2027-02' => ['2.º Semestre'],
            '2027-03' => ['2.º Semestre'],
        ];

        foreach ($expected as $month => $labels) {
            $this->actingAs($this->teacher)->withSession($this->tenantSession())
                ->get("/calendar?month={$month}")->assertOk()
                ->assertInertia(function (AssertableInertia $page) use ($month, $labels) {
                    $this->assertSame(
                        $labels,
                        array_column($page->toArray()['props']['periods'], 'label'),
                        "O mês {$month} recebeu os períodos errados.",
                    );
                });
        }
    }

    /**
     * UM MÊS QUE NENHUM PERÍODO TOCA NÃO RECEBE NENHUM — nem o último visto, nem
     * o primeiro do ano por omissão. A lista vem vazia, e é da página a decisão
     * de não escrever faixa nenhuma.
     */
    #[Test]
    public function a_month_no_period_touches_at_all_is_offered_an_empty_list(): void
    {
        $this->period('1.º Período', 1, '2026-09-01', '2026-12-18');

        // Maio de 2027: a grelha inteira (26 de abril a 6 de junho) fica muito
        // depois do único período do ano.
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2027-05')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertSame([], $props['periods']);
                $this->assertSame('2027-05', $props['month']['value']);
                // E a grelha continua a ser uma grelha de dias verdadeiros.
                $this->assertSame(31, count(array_filter($props['days'], fn (array $day): bool => $day['in_month'])));

                foreach ($props['days'] as $day) {
                    $this->assertNull($day['period']);
                }
            });
    }

    /**
     * DOIS PERÍODOS A CRUZAREM O MESMO MÊS VÊM OS DOIS, e não só o primeiro: um
     * mês em que um período fecha no dia 5 e o seguinte abre no dia 15 é as duas
     * coisas ao mesmo tempo, e esconder um deles deixaria metade do mês por
     * explicar.
     */
    #[Test]
    public function two_periods_crossing_the_same_month_are_both_offered(): void
    {
        $this->period('1.º Período', 1, '2026-09-01', '2026-11-05');
        $this->period('2.º Período', 2, '2026-11-15', '2027-01-31');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-11')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertSame(
                    ['1.º Período', '2.º Período'],
                    array_column($props['periods'], 'label'),
                );
                // Com os dois extremos verdadeiros de cada um, que é o que a
                // página precisa para dizer «até 5/11» e «desde 15/11».
                $this->assertSame('2026-11-05', $props['periods'][0]['ends_on']);
                $this->assertSame('2026-11-15', $props['periods'][1]['starts_on']);

                // E o intervalo entre eles fica honestamente sem período nenhum.
                $this->assertSame('1.º Período', $this->dayOf($page, '2026-11-05')['period']['label']);
                $this->assertNull($this->dayOf($page, '2026-11-10')['period']);
                $this->assertSame('2.º Período', $this->dayOf($page, '2026-11-15')['period']['label']);
            });
    }

    /**
     * A year with neither períodos nor avaliações is still a year, and its
     * calendar is still a correct calendar of real days — never a blank page,
     * and never invented content to fill it.
     */
    #[Test]
    public function an_empty_year_still_renders_a_real_and_correct_grid(): void
    {
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertSame([], $props['periods']);
                $this->assertNotEmpty($props['days']);
                $this->assertSame('2026-10', $props['month']['value']);
                $this->assertSame(31, count(array_filter($props['days'], fn (array $day): bool => $day['in_month'])));

                foreach ($props['days'] as $day) {
                    $this->assertSame([], $day['assessments']);
                    $this->assertSame([], $day['events']);
                    $this->assertNull($day['period']);
                }
            });
    }

    // ------------------------------------------------------- o excesso num dia

    #[Test]
    public function every_assessment_of_a_crowded_day_is_sent_and_the_cap_is_declared(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');

        foreach (['Ficha A', 'Ficha B', 'Ficha C', 'Ficha D', 'Ficha E'] as $title) {
            $this->instrument($schoolClass, $title, '2026-10-15');
        }

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-10')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                // Nothing is dropped on the server: the cap is a presentation
                // decision the page makes, and «+N mais» must be able to show
                // what it promises.
                $this->assertSame(
                    ['Ficha A', 'Ficha B', 'Ficha C', 'Ficha D', 'Ficha E'],
                    array_column($this->dayOf($page, '2026-10-15')['assessments'], 'title'),
                );
                // O limite passou a contar TODOS os itens de um dia — avaliações
                // e acontecimentos juntos (Fase 5.3) — e por isso deixou de se
                // chamar «assessmentsPerDay». O valor, e o que se afirma sobre
                // ele, são exatamente os mesmos.
                $this->assertSame(3, $props['itemsPerDay']);
            });
    }

    // -------------------------------------------------- o mês em que se abre

    /**
     * THE RULE, HALF ONE: today inside the year opens on today's month.
     */
    #[Test]
    public function with_no_month_asked_for_a_running_year_opens_on_todays_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00', 'Europe/Lisbon'));

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('month.value', '2026-11')
                ->where('navigation.home', '2026-11')
                ->where('navigation.home_is_today', true)
                ->etc());

        Carbon::setTestNow();
    }

    /**
     * THE RULE, HALF TWO: today outside the year opens on the year's own first
     * month — never on today's, which for a finished or not-yet-started year
     * would be a correct calendar of nothing at all.
     */
    #[Test]
    public function with_no_month_asked_for_a_year_that_is_not_running_opens_on_its_first_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2029-03-04 09:00:00', 'Europe/Lisbon'));

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('month.value', '2026-09')
                ->where('navigation.home', '2026-09')
                ->where('navigation.home_is_today', false)
                ->etc());

        Carbon::setTestNow();
    }

    #[Test]
    public function the_first_and_last_day_of_the_year_are_both_inside_it_for_the_opening_rule(): void
    {
        foreach (['2026-09-01' => '2026-09', '2027-07-31' => '2027-07'] as $today => $expected) {
            Carbon::setTestNow(Carbon::parse($today.' 09:00:00', 'Europe/Lisbon'));

            $this->actingAs($this->teacher)->withSession($this->tenantSession())
                ->get('/calendar')->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('month.value', $expected)
                    ->etc());
        }

        Carbon::setTestNow();
    }

    // ------------------------------------------------------- a navegação

    #[Test]
    public function month_navigation_offers_the_real_neighbouring_months_across_a_year_boundary(): void
    {
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2026-12')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('month.value', '2026-12')
                ->where('navigation.previous', '2026-11')
                ->where('navigation.next', '2027-01')
                ->etc());

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2027-01')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.previous', '2026-12')
                ->where('navigation.next', '2027-02')
                ->etc());
    }

    /**
     * Navigating months never changes which year is being read: the year comes
     * from the session, through the same ResolveSelectedAcademicYear «Aulas e
     * Sumários» uses, and the month is only a window onto it.
     */
    #[Test]
    public function navigating_month_to_month_preserves_the_academic_year_context(): void
    {
        $other = $this->inTenant(fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create(['label' => 'Outro 2030/2031', 'starts_on' => '2030-09-01', 'ends_on' => '2031-07-31']));

        $session = $this->tenantSession();

        foreach (['2026-09', '2026-10', '2026-11'] as $month) {
            $this->actingAs($this->teacher)->withSession($session)
                ->get("/calendar?month={$month}")->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('academicYear.label', 'Calendário 2026/2027')
                    ->where('month.value', $month)
                    ->etc());
        }

        // And selecting the other year really does change it — proving the
        // assertion above is about the session and not about a constant.
        $this->actingAs($this->teacher)
            ->withSession([...$session, 'academic_year_id' => $other->id])
            ->get('/calendar')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('academicYear.label', 'Outro 2030/2031')
                ->etc());
    }

    #[Test]
    public function a_malformed_month_is_rejected_rather_than_quietly_reinterpreted(): void
    {
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=outubro')
            ->assertSessionHasErrors('month');
    }

    /**
     * A brand-new organization has no year at all. That is an honest empty
     * state — the page renders and says so — never a 500 and never a
     * fabricated year to have something to draw.
     */
    #[Test]
    public function an_organization_with_no_academic_year_gets_an_honest_empty_state(): void
    {
        $newcomer = User::factory()->create();
        $newcomerOrganization = $newcomer->personalOrganization();
        $this->subscribeToPro($newcomerOrganization);

        $this->actingAs($newcomer)->withSession(['organization_id' => $newcomerOrganization->id])
            ->get('/calendar')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('calendar/Month')
                ->where('academicYear', null)
                ->where('month', null)
                ->where('navigation', null)
                ->where('days', [])
                ->where('periods', [])
                ->etc());

        $this->actingAs($newcomer)->withSession(['organization_id' => $newcomerOrganization->id])
            ->get('/calendar/ano')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('calendar/Year')
                ->where('academicYear', null)
                ->where('months', [])
                ->where('periods', [])
                // Zero períodos continua a ser dito exatamente como a página o
                // dizia antes: não há espécie nenhuma de onde tirar um nome, e
                // «período» é o nome do próprio modelo.
                ->where('periodsCountLabel', '0 períodos')
                ->where('assessmentsTotal', 0)
                ->where('eventsTotal', 0));
    }

    // ------------------------------------------------------------- a vista Ano

    #[Test]
    public function the_year_view_bands_the_whole_year_and_counts_its_assessments(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->period('1.º Período', 1, '2026-09-01', '2026-12-18');
        $this->period('2.º Período', 2, '2027-01-05', '2027-04-02');
        $this->period('3.º Período', 3, '2027-04-12', '2027-06-30');

        $this->instrument($schoolClass, 'Teste 1', '2026-10-15');
        $this->instrument($schoolClass, 'Teste 2', '2026-11-20');
        $this->instrument($schoolClass, 'Teste 3', '2026-12-02');
        $this->instrument($schoolClass, 'Teste 4', '2027-02-10');
        $this->instrument($schoolClass, 'Teste 5', '2027-05-18');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar/ano')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertSame(5, $props['assessmentsTotal']);

                $periods = collect($props['periods'])->keyBy('label');
                $this->assertSame(['1.º Período', '2.º Período', '3.º Período'], array_column($props['periods'], 'label'));
                $this->assertSame(3, $periods['1.º Período']['assessments_count']);
                $this->assertSame(1, $periods['2.º Período']['assessments_count']);
                $this->assertSame(1, $periods['3.º Período']['assessments_count']);

                // Every month the year spans, from its first to its last.
                $this->assertSame([
                    '2026-09', '2026-10', '2026-11', '2026-12',
                    '2027-01', '2027-02', '2027-03', '2027-04',
                    '2027-05', '2027-06', '2027-07',
                ], array_column($props['months'], 'value'));

                $months = collect($props['months'])->keyBy('value');
                $this->assertSame(1, $months['2026-10']['assessments_count']);
                $this->assertSame(1, $months['2026-11']['assessments_count']);
                $this->assertSame(1, $months['2026-12']['assessments_count']);
                $this->assertSame(1, $months['2027-02']['assessments_count']);
                $this->assertSame(1, $months['2027-05']['assessments_count']);
                $this->assertSame(0, $months['2026-09']['assessments_count']);
                $this->assertSame(0, $months['2027-03']['assessments_count']);

                // A month may sit in two bands when one ends partway through
                // it — and in none when it falls in a gap between two.
                $this->assertCount(1, $months['2026-10']['period_ulids']);
                $this->assertCount(0, $months['2027-07']['period_ulids']);
                $this->assertCount(2, $months['2027-04']['period_ulids']);
            });
    }

    /**
     * «2 SEMESTRES», E NÃO «2 PERÍODOS». Quantos períodos tem o ano, e de que
     * espécie são, é uma pergunta cuja resposta já está escrita no `kind` de
     * cada um — e é lida de lá, e não adivinhada pelo texto de uma etiqueta nem
     * derivada de um número na página.
     */
    #[Test]
    public function a_year_of_semesters_is_counted_in_semesters(): void
    {
        $this->period('1.º Semestre', 1, '2026-09-11', '2027-01-29', AcademicPeriodKind::Semester);
        $this->period('2.º Semestre', 2, '2027-02-11', '2027-07-31', AcademicPeriodKind::Semester);

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar/ano')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('periodsCountLabel', '2 semestres')
                ->etc());
    }

    #[Test]
    public function a_year_of_periods_is_still_counted_in_periods(): void
    {
        $this->period('1.º Período', 1, '2026-09-01', '2026-12-18');
        $this->period('2.º Período', 2, '2027-01-05', '2027-04-02');
        $this->period('3.º Período', 3, '2027-04-12', '2027-06-30');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar/ano')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('periodsCountLabel', '3 períodos')
                ->etc());
    }

    #[Test]
    public function a_single_period_is_counted_in_the_singular_of_its_own_kind(): void
    {
        $this->period('Trimestre único', 1, '2026-09-01', '2026-12-18', AcademicPeriodKind::Trimester);

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar/ano')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('periodsCountLabel', '1 trimestre')
                ->etc());
    }

    /**
     * E QUANDO AS ESPÉCIES DIFEREM, NÃO SE INVENTA NENHUMA. Um ano que mistura
     * um semestre com dois períodos não é «3 semestres» nem «3 períodos de»
     * espécie nenhuma em particular: é dito pela palavra que é estruturalmente
     * verdadeira sobre todos eles — «período», que é o nome do próprio modelo —
     * em vez de ser dito pela espécie que calhou vir primeiro.
     */
    #[Test]
    public function a_year_mixing_kinds_falls_back_to_the_neutral_word(): void
    {
        $this->period('1.º Semestre', 1, '2026-09-01', '2027-01-29', AcademicPeriodKind::Semester);
        $this->period('2.º Período', 2, '2027-02-11', '2027-04-02');
        $this->period('3.º Período', 3, '2027-04-12', '2027-06-30');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar/ano')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('periodsCountLabel', '3 períodos')
                ->etc());
    }

    /**
     * OS DOIS EXTREMOS DE CADA MÊS vão escritos, e não só o primeiro: sem o
     * último dia a vista não consegue distinguir um mês INTEIRAMENTE dentro de
     * um período de um mês que o período apenas toca — e pintar setembro
     * inteiro com a cor de um semestre que só abre no dia 11 é dizer uma coisa
     * falsa sobre os dez primeiros dias do mês.
     */
    #[Test]
    public function every_month_of_the_year_view_carries_both_of_its_own_ends(): void
    {
        $this->period('1.º Semestre', 1, '2026-09-11', '2027-01-29', AcademicPeriodKind::Semester);
        $this->period('2.º Semestre', 2, '2027-02-11', '2027-07-31', AcademicPeriodKind::Semester);

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar/ano')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $months = collect($page->toArray()['props']['months'])->keyBy('value');

                $this->assertSame('2026-09-01', $months['2026-09']['starts_on']);
                $this->assertSame('2026-09-30', $months['2026-09']['ends_on']);
                $this->assertSame('2027-02-28', $months['2027-02']['ends_on']);
                $this->assertSame('2026-10-31', $months['2026-10']['ends_on']);

                // O intervalo real entre os dois semestres (30/01 a 10/02)
                // continua a ser dito como é: janeiro e fevereiro são tocados
                // por um semestre cada, e nenhum deles os cobre inteiros.
                $this->assertCount(1, $months['2027-01']['period_ulids']);
                $this->assertCount(1, $months['2027-02']['period_ulids']);
                $this->assertCount(1, $months['2026-10']['period_ulids']);
            });
    }

    /**
     * A SYNOPSIS AND NOT A LIST: the Ano view sends counts, and never the
     * avaliações themselves. The itemized list already exists in «Elementos de
     * Avaliação», and a hundred rows here would bury the shape of the year,
     * which is the only thing this view is for. There is no day grid either.
     */
    #[Test]
    public function the_year_view_sends_counts_and_never_an_itemized_list_or_a_day_grid(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->period('1.º Período', 1, '2026-09-01', '2026-12-18');
        $this->instrument($schoolClass, 'Teste de Frações', '2026-10-15');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar/ano')->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $props = $page->toArray()['props'];

                $this->assertArrayNotHasKey('days', $props);
                $this->assertArrayNotHasKey('assessments', $props);
                $this->assertStringNotContainsString('Teste de Frações', json_encode($props, JSON_THROW_ON_ERROR));

                foreach ($props['months'] as $month) {
                    $this->assertArrayNotHasKey('assessments', $month);
                    $this->assertIsInt($month['assessments_count']);
                }
            });
    }

    #[Test]
    public function the_year_view_never_counts_another_teachers_or_another_organizations_assessments(): void
    {
        $own = $this->schoolClassFor($this->teacher, 'Minha turma');
        $this->instrument($own, 'A minha', '2026-10-15');

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $this->instrument($this->schoolClassFor($colleague, 'Turma do colega'), 'A do colega', '2026-10-16');

        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrganization);
        $this->instrument(
            $this->schoolClassFor($stranger, 'Turma de outra organização', $strangerOrganization),
            'A do estranho',
            '2026-10-17',
            $strangerOrganization,
        );

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar/ano')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assessmentsTotal', 1)
                ->etc());
    }

    // --------------------------------------- A PROVA DE QUE NADA SE ESCREVE

    /**
     * THE HARD REQUIREMENT. Opening either view, and walking month to month,
     * writes NOTHING — not an aula, not uma aula recorrente, not um elemento de
     * avaliação. Counted before and after every request rather than inferred
     * from the code being read-only, because the failure this guards against —
     * a reading that quietly materializes aulas — is exactly what the weekly
     * view of «Aulas e Sumários» deliberately does, and this page must not.
     */
    #[Test]
    public function opening_and_navigating_the_calendar_writes_absolutely_nothing(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->period('1.º Período', 1, '2026-09-01', '2026-12-18');
        $this->instrument($schoolClass, 'Teste de Frações', '2026-10-15');

        $assertNothingWasWritten = function (): void {
            $this->assertDatabaseCount('lessons', 0);
            $this->assertDatabaseCount('recurring_lesson_slots', 0);
            $this->assertDatabaseCount('instruments', 1);
            // O calendário passou a ter tabela própria (Fase 5.3), e por isso
            // passou a ser possível LÊ-LA e escrever nela sem querer. Um
            // acontecimento só nasce de um pedido explícito do professor, nunca
            // de se olhar para a página.
            $this->assertDatabaseCount('calendar_events', 0);
        };

        $assertNothingWasWritten();

        $session = $this->tenantSession();

        foreach ([
            '/calendar',
            '/calendar?month=2026-09',
            '/calendar?month=2026-10',
            '/calendar?month=2026-11',
            '/calendar?month=2027-06',
            '/calendar/ano',
        ] as $url) {
            $this->actingAs($this->teacher)->withSession($session)->get($url)->assertOk();
            $assertNothingWasWritten();
        }
    }

    /**
     * E MUDAR DE MÊS TAMBÉM NÃO ESCREVE NADA — nem sequer na ESTRUTURA DO ANO.
     *
     * A vista de Mês passou a filtrar os períodos pelo mês que está a ver, e a
     * pergunta óbvia a seguir é se alguém, algures, resolveu «arrumar» a
     * estrutura para lhe responder. Não: `academic_periods` é contada antes e
     * depois de se andar pelos meses dos dois semestres, ao lado das mesmas
     * quatro tabelas que o teste acima já protege.
     */
    #[Test]
    public function walking_month_to_month_never_writes_to_the_years_structure_either(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->period('1.º Semestre', 1, '2026-09-11', '2027-01-29', AcademicPeriodKind::Semester);
        $this->period('2.º Semestre', 2, '2027-02-11', '2027-07-31', AcademicPeriodKind::Semester);
        $this->instrument($schoolClass, 'Teste de Frações', '2026-10-15');

        // Os dois semestres, mais o período técnico que a avaliação exige.
        $assertNothingWasWritten = function (): void {
            $this->assertDatabaseCount('lessons', 0);
            $this->assertDatabaseCount('recurring_lesson_slots', 0);
            $this->assertDatabaseCount('instruments', 1);
            $this->assertDatabaseCount('calendar_events', 0);
            $this->assertDatabaseCount('academic_periods', 3);
        };

        $assertNothingWasWritten();

        $session = $this->tenantSession();

        foreach ([
            '2026-09', '2026-10', '2026-12', '2027-01',
            '2027-02', '2027-03', '2027-05',
        ] as $month) {
            $this->actingAs($this->teacher)->withSession($session)
                ->get("/calendar?month={$month}")->assertOk();
            $assertNothingWasWritten();
        }

        // E voltar atrás, pelo mesmo caminho, continua a não escrever nada.
        $this->actingAs($this->teacher)->withSession($session)
            ->get('/calendar?month=2026-10')->assertOk();
        $assertNothingWasWritten();
    }

    /**
     * A support session may LOOK at the calendar it is being asked about,
     * because looking changes nothing — and the counts after the request are
     * the proof of it, not the absence of a refusal. The same reasoning
     * «Horário do Professor» already applies to its own reading.
     */
    #[Test]
    public function reading_the_calendar_during_impersonation_writes_nothing_either(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->instrument($schoolClass, 'Teste de Frações', '2026-10-15');

        foreach (['/calendar?month=2026-10', '/calendar/ano'] as $url) {
            $this->actingAs($this->teacher)
                ->withSession([...$this->tenantSession(), 'impersonator_id' => 999])
                ->get($url)->assertOk();
        }

        $this->assertDatabaseCount('lessons', 0);
        $this->assertDatabaseCount('recurring_lesson_slots', 0);
        $this->assertDatabaseCount('instruments', 1);
        $this->assertDatabaseCount('calendar_events', 0);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function dayOf(AssertableInertia $page, string $date): array
    {
        $day = collect($page->toArray()['props']['days'])->firstWhere('date', $date);

        $this->assertNotNull($day, "The grid has no cell for {$date}.");

        return $day;
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

    private function period(
        string $label,
        int $sequence,
        string $startsOn,
        string $endsOn,
        AcademicPeriodKind $kind = AcademicPeriodKind::Term,
    ): AcademicPeriod {
        return $this->inTenant(fn (): AcademicPeriod => AcademicPeriod::factory()
            ->recycle($this->organization)
            ->create([
                'academic_year_id' => $this->academicYear->getKey(),
                'label' => $label,
                'kind' => $kind,
                'sequence' => $sequence,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => AcademicPeriodStatus::Open,
            ]));
    }

    private function instrument(
        SchoolClass $schoolClass,
        string $title,
        string $appliedOn,
        ?Organization $organization = null,
    ): Instrument {
        $organization ??= $this->organization;

        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $schoolClass, $title, $appliedOn): Instrument {
            return Instrument::factory()->recycle($organization)->create([
                'class_id' => $schoolClass->getKey(),
                'academic_period_id' => $this->anchorPeriodFor($schoolClass, $organization)->getKey(),
                'instrument_type_id' => InstrumentType::withoutGlobalScope('typeVisibility')
                    ->firstOrCreate(['organization_id' => null, 'code' => 'TEST'], ['name' => 'Teste global', 'default_purpose' => 'summative'])->id,
                'title' => $title,
                'applied_on' => $appliedOn,
            ]);
        });
    }

    /**
     * Instrument.academic_period_id is NOT NULL, so every avaliação in these
     * tests needs SOME período — but the calendar never reads that column: it
     * bands a day by which of the YEAR's own períodos covers the date, and
     * counts an avaliação into a band by its applied_on. So each turma's year
     * gets one shared, deliberately irrelevant período, parked well outside
     * every range under test and outside the year itself.
     *
     * Parking it there does real work rather than merely avoiding a collision:
     * it means every période these tests DO assert about is one created
     * explicitly by period(), and an avaliação can never appear banded merely
     * because it points at a période row.
     *
     * One per year, because (academic_year_id, sequence) is unique; sequence
     * 100 stays clear of the small sequences period() uses; and the dates span
     * a real interval because `ends_on > starts_on` is a CHECK constraint on
     * MySQL, where CI runs, even though SQLite would let it pass.
     */
    private function anchorPeriodFor(SchoolClass $schoolClass, Organization $organization): AcademicPeriod
    {
        $yearId = $schoolClass->academic_year_id;

        return $this->anchorPeriods[$yearId] ??= AcademicPeriod::factory()
            ->recycle($organization)
            ->create([
                'academic_year_id' => $yearId,
                'label' => 'Período técnico (fora do calendário)',
                'sequence' => 100,
                'starts_on' => '2000-01-01',
                'ends_on' => '2000-06-30',
            ]);
    }

    private function schoolClassFor(User $teacher, string $label, ?Organization $organization = null): SchoolClass
    {
        $organization ??= $this->organization;

        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $teacher, $label): SchoolClass {
            $academicYear = $organization->is($this->organization)
                ? $this->academicYear
                : AcademicYear::factory()->recycle($organization)->create([
                    'starts_on' => '2026-09-01',
                    'ends_on' => '2027-07-31',
                ]);

            $subject = Subject::query()->firstOrCreate(['code' => 'MAT'], ['name' => 'Matemática']);

            $schoolClass = SchoolClass::factory()
                ->recycle($organization)
                ->create([
                    'label' => $label,
                    'academic_year_id' => $academicYear->getKey(),
                    'subject_id' => $subject->getKey(),
                ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    /**
     * A subscrição começa ANTES DO ANO LECTIVO, e não «ontem».
     *
     * Este ficheiro viaja no tempo — `Carbon::setTestNow()` leva-o a
     * `2026-09-01`, a `2027-07-31` e a `2029-03-04` — e uma subscrição presa ao
     * relógio real deixa de estar em vigor assim que o destino da viagem fica
     * ANTES dela. Com `now()->subDay()` isso acontecia consoante a hora a que a
     * suite corresse: verde de madrugada, 403 a partir das nove da manhã, com a
     * falha a aparecer como «esperava 200, recebeu 403», que não diz nada sobre
     * a causa.
     *
     * Uma data fixa antes de tudo o que este ficheiro visita resolve-o de vez.
     */
    private function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-08-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
