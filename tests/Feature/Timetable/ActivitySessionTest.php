<?php

namespace Tests\Feature\Timetable;

use App\Actions\Accounts\AnonymiseClosedAccount;
use App\Models\AcademicCalendarException;
use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\TimetableActivity;
use App\Models\TimetableActivitySession;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O registo por sessão das «outras atividades» do horário (0.170.0).
 *
 * As garantias deste ficheiro: uma sessão só nasce quando o professor guarda
 * (ver a semana nunca escreve), é idempotente por (atividade, data), nunca
 * toca em aulas nem na numeração das lições, e é só do professor que a
 * escreveu — os coprofessores da turma associada recebem 404.
 *
 * O relógio é fixado ANTES de qualquer dado (quarta-feira, 14/10/2026): a
 * atividade de teste é à terça, por isso as ocorrências são 13/10, 20/10, 27/10.
 */
class ActivitySessionTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'Europe/Lisbon';

    private User $teacher;

    private Organization $organization;

    private AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', self::TIMEZONE));

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        $this->academicYear = $this->inTenant(
            $this->organization,
            fn (): AcademicYear => AcademicYear::factory()
                ->recycle($this->organization)
                ->create(['label' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']),
        );
    }

    // ------------------------------------------------------- o ciclo de vida

    #[Test]
    public function an_activity_without_a_turma_is_prepared_saved_done_and_reopened(): void
    {
        $activity = $this->activity(['title' => 'Apoio 5.º', 'kind' => 'student_support']);

        $this->saveOccurrence($activity, '2026-10-13', 'Fichas de revisão.', 'save')->assertSessionHasNoErrors();

        $session = $this->only();
        $this->assertSame('planned', $session->status->value);
        $this->assertSame('Fichas de revisão.', $session->summary);
        $this->assertSame('Apoio 5.º', $session->activity_title);
        $this->assertSame('student_support', $session->activity_kind);
        $this->assertNull($session->class_id);
        $this->assertSame($this->academicYear->id, $session->academic_year_id);
        $this->assertSame('prepared', $session->status->presentation($session->hasSummary()));

        // «Guardar» mantém o estado e escreve o texto novo.
        $this->saveOccurrence($activity, '2026-10-13', 'Fichas de revisão e jogo.', 'save');
        $this->assertSame('planned', $this->only()->status->value);

        $this->saveOccurrence($activity, '2026-10-13', 'Fichas de revisão e jogo.', 'done');
        $this->assertSame('done', $this->only()->status->value);

        // Guardar o texto numa realizada mantém-na realizada.
        $this->saveOccurrence($activity, '2026-10-13', 'Texto final.', 'save');
        $this->assertSame('done', $this->only()->status->value);
        $this->assertSame('Texto final.', $this->only()->summary);

        $this->saveOccurrence($activity, '2026-10-13', 'Texto final.', 'reopen');
        $this->assertSame('planned', $this->only()->status->value);
        $this->assertSame('Texto final.', $this->only()->summary);
        $this->assertSame(1, $this->sessionCount());
    }

    #[Test]
    public function the_activity_with_a_turma_never_touches_the_lessons_or_their_numbers(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '6.º D');
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::create([
            'class_id' => $schoolClass->id,
            'day_of_week' => 3,
            'starts_at' => '08:30',
            'ends_at' => '09:20',
        ]));

        // Abrir a semana materializa as aulas da turma.
        $this->actingAs($this->teacher)->withSession($this->tenantSession())->get('/lessons?week=2026-10-12')->assertOk();

        $before = $this->lessonSnapshot();
        $this->assertNotEmpty($before);

        $activity = $this->activity(['title' => 'DT 6.º D', 'kind' => 'class_direction', 'class_id' => $schoolClass->id]);
        $this->saveOccurrence($activity, '2026-10-13', 'Reunião com os encarregados de educação.', 'done');
        $this->saveOccurrence($activity, '2026-10-20', 'Preparar a visita de estudo.', 'save');

        $this->assertSame($before, $this->lessonSnapshot());
        $this->assertSame($schoolClass->id, TimetableActivitySession::withoutGlobalScopes()->orderBy('id')->first()->class_id);
    }

    #[Test]
    public function two_occurrences_keep_their_own_summaries(): void
    {
        $activity = $this->activity();

        $this->saveOccurrence($activity, '2026-10-13', 'Primeira.', 'save');
        $this->saveOccurrence($activity, '2026-10-20', 'Segunda.', 'save');
        $this->saveOccurrence($activity, '2026-10-13', 'Primeira, revista.', 'save');

        $this->assertSame(2, $this->sessionCount());
        $this->assertSame('Primeira, revista.', $this->sessionOn($activity, '2026-10-13')->summary);
        $this->assertSame('Segunda.', $this->sessionOn($activity, '2026-10-20')->summary);
    }

    #[Test]
    public function not_held_preserves_the_text(): void
    {
        $activity = $this->activity();
        $this->saveOccurrence($activity, '2026-10-13', 'Tinha preparado isto.', 'save');

        $this->saveOccurrence($activity, '2026-10-13', 'Tinha preparado isto.', 'not_held');

        $session = $this->only();
        $this->assertSame('not_held', $session->status->value);
        $this->assertSame('Tinha preparado isto.', $session->summary);
        $this->assertTrue($session->hasRecords());
    }

    #[Test]
    public function not_held_can_create_a_session_without_text(): void
    {
        $activity = $this->activity();

        $this->saveOccurrence($activity, '2026-10-13', null, 'not_held')->assertSessionHasNoErrors();

        $this->assertSame('not_held', $this->only()->status->value);
        $this->assertNull($this->only()->summary);
    }

    #[Test]
    public function saving_an_empty_text_creates_nothing(): void
    {
        $activity = $this->activity();

        $this->saveOccurrence($activity, '2026-10-13', '   ', 'save')->assertSessionHasErrors('summary');

        $this->assertSame(0, $this->sessionCount());
    }

    #[Test]
    public function a_repeated_request_never_duplicates_the_session(): void
    {
        $activity = $this->activity();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->saveOccurrence($activity, '2026-10-13', 'Mesmo texto.', 'save')->assertSessionHasNoErrors();
        }

        $this->assertSame(1, $this->sessionCount());
    }

    #[Test]
    public function the_database_refuses_a_second_session_for_the_same_activity_and_date(): void
    {
        $activity = $this->activity();
        $this->saveOccurrence($activity, '2026-10-13', 'Texto.', 'save');

        $this->expectException(QueryException::class);

        $this->makeSession($activity, $this->teacher, '2026-10-13', 'Duplicada.');
    }

    #[Test]
    public function viewing_the_timetable_and_the_lessons_week_never_writes_a_session(): void
    {
        $this->activity();

        foreach (['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26'] as $week) {
            $this->actingAs($this->teacher)->withSession($this->tenantSession())->get("/timetable?week={$week}")->assertOk();
            $this->actingAs($this->teacher)->withSession($this->tenantSession())->get("/lessons?week={$week}")->assertOk();
        }

        $this->actingAs($this->teacher)->withSession($this->tenantSession())->get('/activity-sessions')->assertOk();

        $this->assertSame(0, $this->sessionCount());
    }

    #[Test]
    public function the_date_must_be_an_occurrence_of_the_block(): void
    {
        $activity = $this->activity(['starts_on' => '2026-10-13', 'ends_on' => '2026-10-27']);

        // Quarta-feira: não é o dia do bloco.
        $this->saveOccurrence($activity, '2026-10-14', 'Texto.', 'save')->assertSessionHasErrors('occurs_on');
        // Terça fora da vigência.
        $this->saveOccurrence($activity, '2026-10-06', 'Texto.', 'save')->assertSessionHasErrors('occurs_on');
        $this->saveOccurrence($activity, '2026-11-03', 'Texto.', 'save')->assertSessionHasErrors('occurs_on');

        $this->assertSame(0, $this->sessionCount());

        $this->saveOccurrence($activity, '2026-10-27', 'Texto.', 'save')->assertSessionHasNoErrors();
        $this->assertSame(1, $this->sessionCount());
    }

    #[Test]
    public function a_non_teaching_day_cannot_be_registered(): void
    {
        $activity = $this->activity();
        $this->inTenant($this->organization, fn () => AcademicCalendarException::factory()
            ->recycle($this->organization)
            ->for($this->academicYear)
            ->create(['starts_on' => '2026-10-20', 'ends_on' => '2026-10-20']));

        $this->saveOccurrence($activity, '2026-10-20', 'Texto.', 'save')->assertSessionHasErrors('occurs_on');
        $this->assertSame(0, $this->sessionCount());

        // O dia anterior ao feriado funciona; a semana do feriado não mostra a ocorrência virtual.
        $this->saveOccurrence($activity, '2026-10-13', 'Texto.', 'save')->assertSessionHasNoErrors();

        $this->actingAs($this->teacher)->withSession($this->tenantSession())->get('/lessons?week=2026-10-19')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('activitySessions', 0)->etc());
    }

    #[Test]
    public function an_existing_session_is_always_editable_by_ulid_even_when_it_left_the_schedule(): void
    {
        $activity = $this->activity();
        $this->saveOccurrence($activity, '2026-10-13', 'Texto.', 'save');
        $session = $this->only();

        // O bloco muda de dia: a sessão com registos fica onde estava.
        $this->putAsTeacher("/timetable-activities/{$activity->ulid}", $this->activityPayload(['day_of_week' => 4]));

        $this->putAsTeacher("/activity-sessions/{$session->ulid}", ['summary' => 'Corrigido depois.', 'action' => 'done'])
            ->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame('Corrigido depois.', $session->summary);
        $this->assertSame('done', $session->status->value);
        $this->assertSame('2026-10-13', $session->occurs_on->toDateString());
    }

    #[Test]
    public function the_summary_is_limited_to_sixteen_thousand_characters(): void
    {
        $activity = $this->activity();

        $this->saveOccurrence($activity, '2026-10-13', str_repeat('a', 16001), 'save')->assertSessionHasErrors('summary');
        $this->assertSame(0, $this->sessionCount());

        $this->saveOccurrence($activity, '2026-10-13', str_repeat('a', 16000), 'save')->assertSessionHasNoErrors();
        $this->assertSame(16000, mb_strlen((string) $this->only()->summary));
    }

    #[Test]
    public function an_unknown_action_is_refused(): void
    {
        $activity = $this->activity();

        $this->saveOccurrence($activity, '2026-10-13', 'Texto.', 'delete')->assertSessionHasErrors('action');
    }

    // ------------------------------------------------------------ numeração

    #[Test]
    public function numbering_is_optional_and_never_numbers_without_it(): void
    {
        $activity = $this->activity();

        $this->saveOccurrence($activity, '2026-10-13', 'Texto.', 'done');

        $this->assertNull($this->only()->session_number);
    }

    #[Test]
    public function numbering_counts_done_sessions_of_the_activity_from_the_start_number(): void
    {
        $activity = $this->activity(['numbering_enabled' => true, 'numbering_start' => 5]);

        $this->saveOccurrence($activity, '2026-10-13', 'Um.', 'done');
        $this->saveOccurrence($activity, '2026-10-20', 'Dois.', 'done');
        $this->saveOccurrence($activity, '2026-10-27', 'Três.', 'save');

        $this->assertSame(5, $this->sessionOn($activity, '2026-10-13')->session_number);
        $this->assertSame(6, $this->sessionOn($activity, '2026-10-20')->session_number);
        $this->assertNull($this->sessionOn($activity, '2026-10-27')->session_number);

        // Guardar de novo uma realizada nunca lhe muda o número.
        $this->saveOccurrence($activity, '2026-10-13', 'Um, revisto.', 'save');
        $this->assertSame(5, $this->sessionOn($activity, '2026-10-13')->session_number);
    }

    #[Test]
    public function leaving_done_clears_the_number_without_renumbering_the_others(): void
    {
        $activity = $this->activity(['numbering_enabled' => true]);
        $this->saveOccurrence($activity, '2026-10-13', 'Um.', 'done');
        $this->saveOccurrence($activity, '2026-10-20', 'Dois.', 'done');
        $this->saveOccurrence($activity, '2026-10-27', 'Três.', 'done');

        $this->saveOccurrence($activity, '2026-10-20', 'Dois.', 'not_held');

        $this->assertSame(1, $this->sessionOn($activity, '2026-10-13')->session_number);
        $this->assertNull($this->sessionOn($activity, '2026-10-20')->session_number);
        $this->assertSame(3, $this->sessionOn($activity, '2026-10-27')->session_number);

        // Voltar a realizada recebe o seguinte ao maior: 4, e não o 2 que ficou livre.
        $this->saveOccurrence($activity, '2026-10-20', 'Dois.', 'done');
        $this->assertSame(4, $this->sessionOn($activity, '2026-10-20')->session_number);

        $this->saveOccurrence($activity, '2026-10-27', 'Três.', 'reopen');
        $this->assertNull($this->sessionOn($activity, '2026-10-27')->session_number);
    }

    #[Test]
    public function changing_the_start_number_only_affects_the_following_sessions(): void
    {
        $activity = $this->activity(['numbering_enabled' => true]);
        $this->saveOccurrence($activity, '2026-10-13', 'Um.', 'done');

        $this->putAsTeacher("/timetable-activities/{$activity->ulid}", $this->activityPayload(['numbering_enabled' => true, 'numbering_start' => 10]))
            ->assertSessionHasNoErrors();

        $this->saveOccurrence($activity, '2026-10-20', 'Dois.', 'done');

        $this->assertSame(1, $this->sessionOn($activity, '2026-10-13')->session_number);
        $this->assertSame(10, $this->sessionOn($activity, '2026-10-20')->session_number);
    }

    #[Test]
    public function the_numbering_is_independent_per_activity_and_per_academic_year(): void
    {
        $first = $this->activity(['title' => 'Clube A', 'kind' => 'club', 'numbering_enabled' => true]);
        $second = $this->activity(['title' => 'Clube B', 'kind' => 'club', 'numbering_enabled' => true, 'day_of_week' => 4]);

        $this->saveOccurrence($first, '2026-10-13', 'A1.', 'done');
        $this->saveOccurrence($first, '2026-10-20', 'A2.', 'done');
        $this->saveOccurrence($second, '2026-10-15', 'B1.', 'done');

        $this->assertSame(2, $this->sessionOn($first, '2026-10-20')->session_number);
        $this->assertSame(1, $this->sessionOn($second, '2026-10-15')->session_number);

        // Um segundo ano letivo recomeça no número inicial.
        $this->inTenant($this->organization, fn () => AcademicYear::factory()->recycle($this->organization)
            ->create(['label' => '2027/2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30']));
        $this->saveOccurrence($first, '2027-09-07', 'Novo ano.', 'done');

        $this->assertSame(1, $this->sessionOn($first, '2027-09-07')->session_number);
        $this->assertSame(2, $this->sessionOn($first, '2026-10-20')->session_number);
    }

    #[Test]
    public function the_academic_year_of_a_session_is_the_one_containing_the_date(): void
    {
        $activity = $this->activity();

        // Sem nenhum ano a conter 2028: a sessão fica sem ano e continua a registar-se.
        $this->saveOccurrence($activity, '2028-01-04', 'Sem ano.', 'save')->assertSessionHasNoErrors();

        $this->assertNull($this->sessionOn($activity, '2028-01-04')->academic_year_id);
    }

    // --------------------------------------------------------- isolamento

    #[Test]
    public function a_co_teacher_of_the_same_turma_can_neither_see_nor_edit_the_session(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '6.º D');
        $activity = $this->activity(['class_id' => $schoolClass->id, 'kind' => 'class_direction', 'title' => 'DT 6.º D']);
        $this->saveOccurrence($activity, '2026-10-13', 'Texto privado do professor.', 'save');
        $session = $this->only();

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $schoolClass->teachers()->attach($colleague, ['role' => 'co_teacher']);

        $this->actingAs($colleague)->withSession($this->tenantSession())
            ->put("/activity-sessions/{$session->ulid}", ['summary' => 'Roubado.', 'action' => 'done'])
            ->assertNotFound();

        $this->actingAs($colleague)->withSession($this->tenantSession())
            ->post('/activity-sessions', ['activity_ulid' => $activity->ulid, 'occurs_on' => '2026-10-20', 'summary' => 'X', 'action' => 'save'])
            ->assertNotFound();

        $this->assertSame('Texto privado do professor.', $session->refresh()->summary);
        $this->assertSame(1, $this->sessionCount());

        $this->actingAs($colleague)->withSession($this->tenantSession())->get('/activity-sessions')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('sessions', 0)->has('activityOptions', 0)->etc());

        $this->actingAs($colleague)->withSession($this->tenantSession())->get('/lessons?week=2026-10-12')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('activitySessions', 0)->etc());

        $this->actingAs($colleague)->withSession($this->tenantSession())->get('/timetable?week=2026-10-12')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('activities', 0)->has('detachedSessions', 0)->etc());
    }

    #[Test]
    public function a_session_of_another_organization_is_untouchable(): void
    {
        $outsider = User::factory()->create();
        $outsiderOrganization = $outsider->personalOrganization();
        $this->subscribeToPro($outsiderOrganization);

        $foreignActivity = $this->inTenant($outsiderOrganization, fn (): TimetableActivity => TimetableActivity::create([
            'user_id' => $outsider->id,
            'kind' => 'other',
            'title' => 'Alheia',
            'day_of_week' => 2,
            'starts_at' => '10:00',
            'ends_at' => '10:50',
        ]));
        $foreignSession = $this->inTenant($outsiderOrganization, fn (): TimetableActivitySession => $this->makeSession($foreignActivity, $outsider, '2026-10-13', 'Alheio.'));

        $this->putAsTeacher("/activity-sessions/{$foreignSession->ulid}", ['summary' => 'X', 'action' => 'done'])->assertNotFound();
        $this->post('/activity-sessions', ['activity_ulid' => $foreignActivity->ulid, 'occurs_on' => '2026-10-13', 'summary' => 'X', 'action' => 'save'])
            ->assertSessionHasErrors('activity_ulid');

        $this->assertSame('Alheio.', $foreignSession->refresh()->summary);
    }

    #[Test]
    public function impersonation_refuses_every_write_but_allows_reading(): void
    {
        $activity = $this->activity();
        $this->saveOccurrence($activity, '2026-10-13', 'Texto.', 'save');
        $session = $this->only();
        $impersonating = [...$this->tenantSession(), 'impersonator_id' => 999];

        $this->actingAs($this->teacher)->withSession($impersonating)
            ->post('/activity-sessions', ['activity_ulid' => $activity->ulid, 'occurs_on' => '2026-10-20', 'summary' => 'X', 'action' => 'save'])
            ->assertForbidden();
        $this->actingAs($this->teacher)->withSession($impersonating)
            ->put("/activity-sessions/{$session->ulid}", ['summary' => 'X', 'action' => 'done'])
            ->assertForbidden();

        $this->assertSame('Texto.', $session->refresh()->summary);
        $this->assertSame(1, $this->sessionCount());

        $this->actingAs($this->teacher)->withSession($impersonating)->get('/activity-sessions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('sessions', 1)->etc());
        $this->actingAs($this->teacher)->withSession($impersonating)->get('/activity-sessions/export')->assertOk();
        $this->actingAs($this->teacher)->withSession($impersonating)->get('/lessons?week=2026-10-12')->assertOk();
    }

    #[Test]
    public function without_the_lessons_module_nothing_is_written_or_read(): void
    {
        $activity = $this->activity();
        $base = User::factory()->create();
        $session = ['organization_id' => $base->personalOrganization()->id];

        $this->actingAs($base)->withSession($session)
            ->post('/activity-sessions', ['activity_ulid' => $activity->ulid, 'occurs_on' => '2026-10-13', 'summary' => 'X', 'action' => 'save'])
            ->assertForbidden();
        $this->actingAs($base)->withSession($session)->get('/activity-sessions')->assertForbidden();

        $this->assertSame(0, $this->sessionCount());
    }

    // -------------------------------------------------------- editar o bloco

    #[Test]
    public function editing_the_block_never_moves_or_loses_a_session_with_records_and_warns(): void
    {
        $activity = $this->activity(['title' => 'DT 6D']);
        $this->saveOccurrence($activity, '2026-10-13', 'Texto de 13.', 'save');
        $this->saveOccurrence($activity, '2026-10-20', 'Texto de 20.', 'done');
        $this->saveOccurrence($activity, '2026-10-27', null, 'not_held');

        $this->putAsTeacher("/timetable-activities/{$activity->ulid}", $this->activityPayload([
            'title' => 'DT 6D novo',
            'day_of_week' => 4,
            'starts_at' => '15:00',
            'ends_at' => '15:50',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(3, $this->sessionCount());

        foreach (['2026-10-13', '2026-10-20', '2026-10-27'] as $date) {
            $session = $this->sessionOn($activity, $date);
            $this->assertSame('DT 6D', $session->activity_title);
            $this->assertSame('10:30', substr($session->starts_at, 0, 5));
        }

        $this->assertSame('Texto de 13.', $this->sessionOn($activity, '2026-10-13')->summary);
        $this->assertSame('done', $this->sessionOn($activity, '2026-10-20')->status->value);

        // O aviso persistente diz quais ficaram e porquê.
        $response = $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->from('/timetable')
            ->put("/timetable-activities/{$activity->ulid}", $this->activityPayload(['title' => 'DT 6D novo', 'day_of_week' => 4, 'starts_at' => '15:00', 'ends_at' => '15:50']));
        $this->assertStringContainsString(
            '3 sessões com registos de «DT 6D» ficaram na data e hora anteriores — 13/10, 20/10 e 27/10 — para não se perder o que lá está.',
            $this->scheduleNotice($response),
        );
    }

    #[Test]
    public function editing_the_block_removes_empty_sessions_that_no_longer_match_and_updates_the_ones_that_do(): void
    {
        $activity = $this->activity(['title' => 'Clube']);
        $this->saveOccurrence($activity, '2026-10-13', 'Texto.', 'save');

        // Duas sessões vazias criadas diretamente (o ecrã nunca as cria vazias).
        $matching = $this->makeSession($activity, $this->teacher, '2026-10-20', null);
        $leaving = $this->makeSession($activity, $this->teacher, '2026-10-27', null);

        $this->putAsTeacher("/timetable-activities/{$activity->ulid}", $this->activityPayload([
            'title' => 'Clube de Xadrez',
            'kind' => 'club',
            'starts_at' => '10:30',
            'ends_at' => '11:20',
            'ends_on' => '2026-10-20',
        ]))->assertSessionHasNoErrors();

        // 27/10 saiu da vigência: a vazia foi-se; a de 20/10 acompanhou o título e o tipo.
        $this->assertNull(TimetableActivitySession::query()->find($leaving->id));
        $this->assertSame('Clube de Xadrez', $matching->refresh()->activity_title);
        $this->assertSame('club', $matching->activity_kind);

        // A com registos manteve o título antigo.
        $this->assertSame('Clube', $this->sessionOn($activity, '2026-10-13')->activity_title);
    }

    #[Test]
    public function editing_without_preserved_sessions_adds_no_notice(): void
    {
        $activity = $this->activity();

        $response = $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->put("/timetable-activities/{$activity->ulid}", $this->activityPayload(['title' => 'Outro nome']));

        $response->assertSessionHasNoErrors();
        $this->assertNull($this->scheduleNotice($response, false));
    }

    // ---------------------------------------------------- eliminar o bloco

    #[Test]
    public function deleting_a_block_with_records_closes_it_and_keeps_the_history(): void
    {
        $activity = $this->activity(['title' => 'Apoio']);
        $this->saveOccurrence($activity, '2026-10-13', 'Já feito.', 'done');
        $future = $this->makeSession($activity, $this->teacher, '2026-10-20', null);
        $futureWithText = $this->makeSession($activity, $this->teacher, '2026-10-27', 'Preparada.');

        $response = $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->delete("/timetable-activities/{$activity->ulid}", ['effective_from' => '2026-10-20']);

        $response->assertRedirect()->assertSessionHasNoErrors();

        $activity->refresh();
        $this->assertSame('2026-10-19', $activity->ends_on->toDateString());
        $this->assertSame('Já feito.', $this->sessionOn($activity, '2026-10-13')->summary);
        // A vazia futura sai; a que já tem texto fica.
        $this->assertNull(TimetableActivitySession::query()->find($future->id));
        $this->assertNotNull(TimetableActivitySession::query()->find($futureWithText->id));
        $this->assertStringContainsString('deixa de vigorar a partir de 20/10/2026', $this->scheduleNotice($response));
    }

    #[Test]
    public function deleting_a_block_with_records_defaults_to_closing_today(): void
    {
        $activity = $this->activity();
        $this->saveOccurrence($activity, '2026-10-13', 'Feito.', 'done');

        $this->deleteAsTeacher("/timetable-activities/{$activity->ulid}")->assertRedirect();

        $this->assertSame('2026-10-13', $activity->refresh()->ends_on->toDateString());
    }

    #[Test]
    public function a_closed_block_stops_showing_but_its_sessions_stay_in_the_week_and_in_the_history(): void
    {
        $activity = $this->activity(['title' => 'Apoio']);
        $this->saveOccurrence($activity, '2026-10-13', 'Feito.', 'done');
        $this->deleteAsTeacher("/timetable-activities/{$activity->ulid}", ['effective_from' => '2026-10-14']);

        // 20/10 já não tem bloco; 13/10 continua a ter a sessão, identificada pela cópia.
        $this->actingAs($this->teacher)->withSession($this->tenantSession())->get('/lessons?week=2026-10-12')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activitySessions', 1)
                ->where('activitySessions.0.title', 'Apoio')
                ->where('activitySessions.0.occurs_on', '2026-10-13')
                ->etc());
        $this->actingAs($this->teacher)->withSession($this->tenantSession())->get('/lessons?week=2026-10-19')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('activitySessions', 0)->etc());
        $this->actingAs($this->teacher)->withSession($this->tenantSession())->get('/activity-sessions')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('sessions', 1)->etc());
    }

    #[Test]
    public function deleting_a_block_without_records_deletes_it_and_its_empty_sessions(): void
    {
        $activity = $this->activity();
        $this->makeSession($activity, $this->teacher, '2026-10-20', null);

        $this->deleteAsTeacher("/timetable-activities/{$activity->ulid}")->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('timetable_activities', 0);
        $this->assertSame(0, $this->sessionCount());
    }

    #[Test]
    public function the_closing_date_cannot_be_in_the_past(): void
    {
        $activity = $this->activity();
        $this->saveOccurrence($activity, '2026-10-13', 'Feito.', 'done');

        $this->deleteAsTeacher("/timetable-activities/{$activity->ulid}", ['effective_from' => '2026-10-01'])
            ->assertSessionHasErrors('effective_from');

        $this->assertNull($activity->refresh()->ends_on);
    }

    #[Test]
    public function deleting_the_referenced_turma_keeps_the_session_and_its_text(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '6.º D');
        $activity = $this->activity(['class_id' => $schoolClass->id]);
        $this->saveOccurrence($activity, '2026-10-13', 'Texto.', 'save');

        DB::table('classes')->where('id', $schoolClass->id)->delete();

        $session = $this->only();
        $this->assertNull($session->class_id);
        $this->assertSame('Texto.', $session->summary);
    }

    // ---------------------------------------------------------- anonimização

    #[Test]
    public function anonymising_a_closed_account_removes_its_sessions_and_only_its_own(): void
    {
        $activity = $this->activity();
        $this->saveOccurrence($activity, '2026-10-13', 'Minha.', 'save');

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $theirActivity = $this->inTenant($this->organization, fn (): TimetableActivity => TimetableActivity::create([
            'user_id' => $colleague->id,
            'kind' => 'other',
            'title' => 'Do colega',
            'day_of_week' => 3,
            'starts_at' => '10:00',
            'ends_at' => '10:50',
        ]));
        $theirSession = $this->inTenant($this->organization, fn (): TimetableActivitySession => $this->makeSession($theirActivity, $colleague, '2026-10-14', 'Dele.'));

        $removed = app(AnonymiseClosedAccount::class)->execute($this->teacher);

        $this->assertSame(1, $removed['timetable_activity_sessions']);
        $this->assertSame(1, $removed['timetable_activities']);
        $this->assertDatabaseCount('timetable_activity_sessions', 1);
        $this->assertDatabaseHas('timetable_activity_sessions', ['id' => $theirSession->id]);
    }

    // -------------------------------------------------- Aulas e Sumários

    #[Test]
    public function the_lessons_week_sends_the_sessions_in_their_own_prop_ordered_and_apart_from_the_lessons(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::create([
            'class_id' => $schoolClass->id,
            'day_of_week' => 2,
            'starts_at' => '08:30',
            'ends_at' => '09:20',
        ]));
        $late = $this->activity(['title' => 'Reunião', 'kind' => 'meeting', 'starts_at' => '17:00', 'ends_at' => '18:00']);
        $early = $this->activity(['title' => 'Apoio', 'kind' => 'student_support', 'starts_at' => '07:30', 'ends_at' => '08:20']);
        $this->saveOccurrence($early, '2026-10-13', 'Texto do apoio.', 'done');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())->get('/lessons?week=2026-10-12')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('lessons', 1)
                ->has('activitySessions', 2)
                ->where('activitySessions.0.title', 'Apoio')
                ->where('activitySessions.0.presentation', 'done')
                ->where('activitySessions.0.summary', 'Texto do apoio.')
                ->where('activitySessions.0.start_time', '07:30')
                ->where('activitySessions.0.starts_at', '2026-10-13T07:30:00+01:00')
                ->where('activitySessions.1.title', 'Reunião')
                ->where('activitySessions.1.session_ulid', null)
                ->where('activitySessions.1.activity_ulid', $late->ulid)
                ->where('activitySessions.1.registrable', true)
                ->etc());

        // O que não é aula nunca vira aula, nem mesmo ao abrir a semana.
        $this->inTenant($this->organization, fn () => $this->assertSame(1, Lesson::query()->count() - Lesson::query()->where('starts_at', '>', '2026-10-14')->count()));
        $this->assertSame(1, $this->sessionCount());
    }

    #[Test]
    public function the_timetable_sends_the_occurrence_state_of_each_block_for_the_week_shown(): void
    {
        $activity = $this->activity(['numbering_enabled' => true]);
        $this->saveOccurrence($activity, '2026-10-13', 'Texto.', 'done');

        $this->actingAs($this->teacher)->withSession($this->tenantSession())->get('/timetable?week=2026-10-12')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activities', 1)
                ->where('activities.0.occurrence.occurs_on', '2026-10-13')
                ->where('activities.0.occurrence.presentation', 'done')
                ->where('activities.0.occurrence.session_number', 1)
                ->where('activities.0.records_count', 1)
                ->where('activities.0.numbering_enabled', true)
                ->has('detachedSessions', 0)
                ->etc());

        $this->actingAs($this->teacher)->withSession($this->tenantSession())->get('/timetable?week=2026-10-19')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('activities.0.occurrence.occurs_on', '2026-10-20')
                ->where('activities.0.occurrence.session_ulid', null)
                ->where('activities.0.occurrence.presentation', 'planned')
                ->etc());
    }

    #[Test]
    public function a_session_that_left_the_schedule_is_a_detached_block_in_the_timetable(): void
    {
        $activity = $this->activity(['title' => 'DT']);
        $this->saveOccurrence($activity, '2026-10-13', 'Texto.', 'save');
        $this->putAsTeacher("/timetable-activities/{$activity->ulid}", $this->activityPayload(['title' => 'DT', 'day_of_week' => 4]));

        $this->actingAs($this->teacher)->withSession($this->tenantSession())->get('/timetable?week=2026-10-12')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('detachedSessions', 1)
                ->where('detachedSessions.0.occurs_on', '2026-10-13')
                ->where('detachedSessions.0.activity_ulid', $activity->ulid)
                ->where('detachedSessions.0.follows_schedule', false)
                ->etc());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function activityPayload(array $overrides = []): array
    {
        return [
            'kind' => 'other',
            'title' => 'RTC',
            'class_ulid' => null,
            'day_of_week' => 2,
            'starts_at' => '10:30',
            'ends_at' => '11:20',
            'starts_on' => null,
            'ends_on' => null,
            ...$overrides,
        ];
    }

    private function saveOccurrence(TimetableActivity $activity, string $date, ?string $summary, string $action): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->post('/activity-sessions', [
                'activity_ulid' => $activity->ulid,
                'occurs_on' => $date,
                'summary' => $summary,
                'action' => $action,
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function putAsTeacher(string $url, array $data): TestResponse
    {
        return $this->actingAs($this->teacher)->withSession($this->tenantSession())->put($url, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deleteAsTeacher(string $url, array $data = []): TestResponse
    {
        return $this->actingAs($this->teacher)->withSession($this->tenantSession())->delete($url, $data);
    }

    /**
     * A mensagem do aviso persistente (`scheduleNotice`) que a resposta fez
     * seguir: o Inertia guarda a flash na sessão até ao pedido seguinte.
     */
    private function scheduleNotice(TestResponse $response, bool $required = true): ?string
    {
        $flash = session('inertia.flash_data', []);
        $message = $flash['scheduleNotice']['message'] ?? null;

        if ($required) {
            $this->assertNotNull($message, 'Esperava um aviso persistente na flash.');
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonSnapshot(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->orderBy('id')
            ->get(['id', 'class_id', 'lesson_number', 'lesson_number_end', 'lesson_number_manual', 'status'])
            ->toArray());
    }

    private function sessionCount(): int
    {
        return TimetableActivitySession::withoutGlobalScopes()->count();
    }

    private function only(): TimetableActivitySession
    {
        return TimetableActivitySession::withoutGlobalScopes()->sole();
    }

    private function sessionOn(TimetableActivity $activity, string $date): TimetableActivitySession
    {
        return TimetableActivitySession::withoutGlobalScopes()
            ->where('timetable_activity_id', $activity->id)
            ->whereDate('occurs_on', $date)
            ->sole();
    }

    private function makeSession(TimetableActivity $activity, User $owner, string $date, ?string $summary): TimetableActivitySession
    {
        return $this->inTenant($activity->organization, function () use ($activity, $owner, $date, $summary): TimetableActivitySession {
            $session = new TimetableActivitySession;
            $session->forceFill([
                'user_id' => $owner->id,
                'timetable_activity_id' => $activity->id,
                'activity_kind' => $activity->kind->value,
                'activity_title' => $activity->title,
                'class_id' => $activity->class_id,
                'occurs_on' => $date,
                'starts_at' => $activity->starts_at,
                'ends_at' => $activity->ends_at,
                'summary' => $summary,
                'status' => 'planned',
            ])->save();

            return $session;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function activity(array $attributes = []): TimetableActivity
    {
        return $this->inTenant($this->organization, fn (): TimetableActivity => TimetableActivity::create([
            'user_id' => $this->teacher->id,
            'kind' => 'other',
            'title' => 'RTC',
            'day_of_week' => 2,
            'starts_at' => '10:30',
            'ends_at' => '11:20',
            ...$attributes,
        ]));
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

    private function schoolClassFor(User $teacher, string $label): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($teacher, $label): SchoolClass {
            $subject = Subject::query()->firstOrCreate(['code' => 'MAT'], ['name' => 'Matemática']);

            $schoolClass = SchoolClass::factory()
                ->recycle($this->organization)
                ->create([
                    'label' => $label,
                    'academic_year_id' => $this->academicYear->getKey(),
                    'subject_id' => $subject->getKey(),
                ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
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
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
