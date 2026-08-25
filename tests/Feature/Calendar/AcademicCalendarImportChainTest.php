<?php

namespace Tests\Feature\Calendar;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionSource;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\AcademicCalendarXlsxBuilder as Fixture;
use Tests\TestCase;

/**
 * A CADEIA INTEIRA, DE PONTA A PONTA — o que as Fases 5.4, 5.5 e 5.6 existem para
 * fazer juntas, e que nenhuma delas prova sozinha.
 *
 * As três já têm os seus testes e todos passam: o modelo existe (5.4), a
 * materialização respeita-o (5.5), o importador lê o ficheiro (5.6). Nada disso
 * garante que uma interrupção letiva que VEIO DE UM FICHEIRO — e não de uma
 * factory num teste — atravesse a importação, a gravação, a leitura do calendário
 * e a materialização e chegue inteira ao fim. Cada elo está provado; a corrente
 * não estava.
 *
 * O elo mais importante é o ÚLTIMO PASSO: a quarta-feira a seguir à interrupção
 * tem aula. Sem ele, esta cadeia passaria com uma implementação que simplesmente
 * deixasse de materializar aulas — e essa é a maneira mais fácil de fazer todos os
 * outros passos passarem por engano.
 *
 * A interrupção de referência é a de 18 a 22 de novembro de 2030 (segunda a
 * sexta). A turma tem Português às quartas-feiras: 13 de novembro (véspera), 20 de
 * novembro (DENTRO) e 27 de novembro (a seguir).
 */
class AcademicCalendarImportChainTest extends TestCase
{
    use RefreshDatabase;

    private const BREAK_STARTS_ON = '2030-11-18';

    private const BREAK_ENDS_ON = '2030-11-22';

    /** As três quartas-feiras à volta da interrupção. */
    private const WEDNESDAY_BEFORE = '2030-11-13';

    private const WEDNESDAY_INSIDE = '2030-11-20';

    private const WEDNESDAY_AFTER = '2030-11-27';

    private User $teacher;

    private Organization $organization;

    private AcademicYear $academicYear;

    private SchoolClass $schoolClass;

    private RecurringLessonSlot $slot;

    private bool $slotBackdated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        [$this->academicYear, $this->schoolClass, $this->slot] = $this->inTenant(function (): array {
            $year = AcademicYear::factory()->recycle($this->organization)->active()->create([
                'label' => '2030/2031',
                'starts_on' => '2030-09-01',
                'ends_on' => '2031-07-31',
            ]);

            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $year->id,
                'label' => '7.º A',
            ]);
            $schoolClass->teachers()->attach($this->teacher, ['role' => 'owner']);

            $slot = RecurringLessonSlot::create([
                'class_id' => $schoolClass->id,
                // Quarta-feira.
                'day_of_week' => 3,
                'starts_at' => '10:30',
                'ends_at' => '11:20',
                'starts_on' => '2030-09-01',
                'ends_on' => '2031-07-31',
            ]);

            return [$year, $schoolClass, $slot];
        });
    }

    #[Test]
    public function an_imported_school_break_travels_from_the_file_all_the_way_to_the_lessons_that_are_not_created(): void
    {
        $slotBefore = $this->slotSnapshot();

        // ── 1. O ficheiro propõe a interrupção ──────────────────────────────
        $proposal = $this->proposedBreak();

        $this->assertSame(AcademicCalendarExceptionType::SchoolBreak->value, $proposal['type']);
        $this->assertSame(self::BREAK_STARTS_ON, $proposal['starts_on']);
        $this->assertSame(self::BREAK_ENDS_ON, $proposal['ends_on']);
        $this->assertSame('new', $proposal['state']);

        // ...e propô-la não escreveu nada.
        $this->assertDatabaseCount('academic_calendar_exceptions', 0);
        $this->assertDatabaseCount('lessons', 0);

        // ── 2. Confirmá-la grava-a, com a proveniência certa ────────────────
        $this->confirmOnly($proposal)->assertRedirect('/calendar');

        $exception = $this->inTenant(fn (): AcademicCalendarException => AcademicCalendarException::query()->sole());

        $this->assertSame(AcademicCalendarExceptionType::SchoolBreak, $exception->type);
        $this->assertSame(self::BREAK_STARTS_ON, $exception->starts_on->toDateString());
        $this->assertSame(self::BREAK_ENDS_ON, $exception->ends_on->toDateString());
        // A PROVENIÊNCIA, que é o que distingue esta linha de uma escrita à mão —
        // e que não precisou de tabela nenhuma para existir.
        $this->assertSame(AcademicCalendarExceptionSource::Imported, $exception->source);
        $this->assertSame($this->academicYear->id, $exception->academic_year_id);

        // ── 3. Aparece nas duas vistas do «Calendário do Ano Letivo» ────────
        $this->assertAppearsInTheMonthView($exception->ulid);
        $this->assertAppearsInTheYearView($exception->ulid);

        // ── 4. O horário da turma sobrevive intacto ────────────────────────
        // A ROTINA SOBREVIVE AO FERIADO. O que não acontece é aquela ocorrência,
        // e não «Português às quartas» — nada nesta cadeia toca num
        // RecurringLessonSlot, e o `updated_at` recuado provaria o contrário.
        $this->assertSame($slotBefore, $this->slotSnapshot());
        $this->assertDatabaseCount('recurring_lesson_slots', 1);

        // ── 5. e 6. Materializar salta a quarta-feira de dentro e mais nenhuma ─
        $lessons = $this->materialize('2030-11-01', '2030-11-30');

        $dates = $lessons->map(fn (Lesson $lesson): string => $lesson->starts_at->toDateString())->all();

        // A véspera e a quarta-feira SEGUINTE têm aula; a de dentro não. É o
        // segundo destes três factos que impede esta cadeia de passar com uma
        // implementação que simplesmente deixasse de materializar.
        $this->assertContains(self::WEDNESDAY_BEFORE, $dates);
        $this->assertNotContains(self::WEDNESDAY_INSIDE, $dates);
        $this->assertContains(self::WEDNESDAY_AFTER, $dates);

        // E o mês inteiro, escrito por extenso: 6, 13, 27 — as quatro
        // quartas-feiras de novembro menos a que caiu dentro da interrupção.
        $this->assertSame(['2030-11-06', self::WEDNESDAY_BEFORE, self::WEDNESDAY_AFTER], $dates);
    }

    /**
     * O MESMO CAMINHO, PARA UM FERIADO DE UM DIA. A guarda não pergunta que
     * espécie de dia não letivo é, só se é um — e uma interrupção de cinco dias e
     * um feriado de um têm de sair da importação igualmente eficazes.
     *
     * O 25 de dezembro de 2030 é uma quarta-feira, e por isso é um dia em que esta
     * turma teria mesmo aula.
     */
    #[Test]
    public function an_imported_one_day_holiday_stops_that_days_lesson_too(): void
    {
        $christmas = $this->rowFor($this->previewProps()['holidays'], '2030-12-25');

        $this->assertSame('2030-12-25', $christmas['ends_on']);

        $this->confirmOnly($christmas)->assertRedirect();

        $this->inTenant(function (): void {
            $this->assertSame(
                AcademicCalendarExceptionSource::Imported,
                AcademicCalendarException::query()->sole()->source,
            );
        });

        $dates = $this->materialize('2030-12-15', '2030-12-31')
            ->map(fn (Lesson $lesson): string => $lesson->starts_at->toDateString())
            ->all();

        $this->assertNotContains('2030-12-25', $dates);
        // As outras quartas-feiras do intervalo continuam lá.
        $this->assertSame(['2030-12-18'], $dates);
    }

    /**
     * MATERIALIZAR DUAS VEZES NÃO RESSUSCITA A AULA QUE NÃO NASCEU, nem duplica as
     * que nasceram. A guarda não é um estado que se gaste na primeira passagem.
     */
    #[Test]
    public function materialising_twice_over_an_imported_break_stays_idempotent(): void
    {
        $this->confirmOnly($this->proposedBreak())->assertRedirect();

        $first = $this->materialize('2030-11-01', '2030-11-30')->count();
        $second = $this->materialize('2030-11-01', '2030-11-30')->count();

        $this->assertSame(3, $first);
        $this->assertSame(3, $second);
        $this->assertDatabaseCount('lessons', 3);
    }

    // ---------------------------------------------------------------- helpers

    private function assertAppearsInTheMonthView(string $ulid): void
    {
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar?month=2030-11')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($ulid): void {
                $props = $page->component('calendar/Month')->toArray()['props'];

                $this->assertContains($ulid, array_column($props['exceptions'], 'ulid'));

                // E cada um dos cinco dias que ela cobre traz a exceção consigo —
                // não só o primeiro. Uma interrupção é o que está a acontecer em
                // cada um dos seus dias.
                $covered = array_filter(
                    $props['days'],
                    fn (array $day): bool => $day['date'] >= self::BREAK_STARTS_ON && $day['date'] <= self::BREAK_ENDS_ON,
                );

                $this->assertCount(5, $covered);

                foreach ($covered as $day) {
                    $this->assertSame($ulid, $day['exception']['ulid'] ?? null, "O dia {$day['date']} não traz a exceção.");
                }
            });
    }

    private function assertAppearsInTheYearView(string $ulid): void
    {
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/calendar/ano')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($ulid): void {
                $props = $page->component('calendar/Year')->toArray()['props'];

                $this->assertContains($ulid, array_column($props['exceptions'], 'ulid'));

                $november = collect($props['months'])->firstWhere('value', '2030-11');

                $this->assertNotNull($november);
                $this->assertContains($ulid, $november['exception_ulids']);
                // Contam-se DIAS e não exceções: cinco dias não letivos em
                // novembro, que é a única contagem que significa alguma coisa a
                // esta escala.
                $this->assertSame(5, $november['non_teaching_days_count']);
                $this->assertSame(5, $props['nonTeachingDaysTotal']);
            });
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function materialize(string $from, string $to): Collection
    {
        return $this->inTenant(fn () => app(MaterializeLessonsForRange::class)->execute(
            $this->schoolClass,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
            $this->teacher,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function proposedBreak(): array
    {
        return $this->rowFor($this->previewProps()['schoolBreaks'], self::BREAK_STARTS_ON);
    }

    /**
     * Confirma UMA linha e mais nenhuma — todas as outras vão explicitamente por
     * marcar, para que o que ficar na base de dados só possa ter vindo desta.
     *
     * @param  array<string, mixed>  $row
     */
    private function confirmOnly(array $row): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->post('/academic-calendar-imports/confirm', [
                'academic_year_ulid' => $this->academicYear->ulid,
                'semesters' => [],
                'exceptions' => [[
                    'include' => true,
                    'type' => $row['type'],
                    'title' => $row['title'],
                    'starts_on' => $row['starts_on'],
                    'ends_on' => $row['ends_on'],
                    'note' => $row['note'],
                ]],
                'events' => [],
            ]);
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

    /**
     * Os atributos do slot por nome de coluna.
     *
     * POR NOME DE COLUNA e não por ordem, porque um modelo acabado de criar traz as
     * chaves por ordem de escrita e um relido traz-nas por ordem da tabela: são a
     * mesma linha, e a asserção tem de o dizer. É a mesma técnica do teste da Fase
     * 5.5.
     *
     * @return array<string, mixed>
     */
    private function slotSnapshot(): array
    {
        return $this->inTenant(function (): array {
            // Recuado no tempo UMA VEZ, na primeira leitura: se alguma coisa nesta
            // cadeia tocasse no slot, o `updated_at` denunciava-a mesmo que nada
            // mais mudasse. Recuá-lo outra vez na segunda leitura apagava
            // precisamente a prova.
            if (! $this->slotBackdated) {
                DB::table('recurring_lesson_slots')
                    ->where('id', $this->slot->id)
                    ->update(['updated_at' => '2020-01-01 00:00:00']);

                $this->slotBackdated = true;
            }

            $attributes = RecurringLessonSlot::query()->whereKey($this->slot->id)->sole()->getAttributes();
            ksort($attributes);

            return $attributes;
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
}
