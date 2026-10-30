<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Models\AttendanceStatus;
use App\Models\AuditEvent;
use App\Models\CancelledLessonOccurrence;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * A camada HTTP da reconciliação com o calendário (parte B): a pré-visualização
 * do responsável, a gravação com `reconcile`, os três pedidos do professor e a
 * sinalização na aula individual.
 *
 * O RELÓGIO ESTÁ FIXO (setUp) e as datas são absolutas. Mundo de referência —
 * uma turma de quintas-feiras (01, 08, 15, 22, 29 de outubro; 05, 12, 19, 26 de
 * novembro de 2026) e, já gravados:
 *
 *  - 08/10: feriado «Pré-existente», aula lecionada com sumário, notas e plano
 *    (classe C — nunca se toca);
 *  - 22/10 e 29/10: o dia 22 vazio (A) e o dia 29 com sumário de preparação (B),
 *    cobertos por `$this->midTermBreak()` quando o teste a cria.
 *
 * Os textos «SENTINELA-…» servem para afirmar que NENHUMA resposta nova devolve
 * conteúdo pedagógico.
 */
class CalendarConflictsHttpTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    private const SENTINELS = ['SENTINELA-SUMARIO', 'SENTINELA-NOTAS', 'SENTINELA-PLANO', 'SENTINELA-B'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    // ------------------------------------------------------------------
    // Autenticação e autorização
    // ------------------------------------------------------------------

    #[Test]
    public function guests_are_redirected_from_every_new_endpoint(): void
    {
        $year = $this->year();

        $this->get('/lessons/calendar-conflicts')->assertRedirect('/login');
        $this->postJson('/lessons/calendar-conflicts/preview')->assertUnauthorized();
        $this->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => str_repeat('a', 64)])->assertUnauthorized();
        $this->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'create'])->assertUnauthorized();
    }

    #[Test]
    public function a_year_of_another_organization_is_a_404_on_every_endpoint(): void
    {
        $this->world();
        $year = $this->year();
        $stranger = User::factory()->create();
        $strangerOrg = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrg);
        $acting = fn () => $this->actingAs($stranger)->withSession(['organization_id' => $strangerOrg->id]);

        $acting()->getJson("/lessons/calendar-conflicts?academic_year={$year->ulid}")->assertNotFound();
        $acting()->postJson('/lessons/calendar-conflicts/preview', ['academic_year' => $year->ulid])->assertNotFound();
        $acting()->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'delete', 'exception' => 'x'])->assertNotFound();
        $acting()->postJson("/academic-years/{$year->ulid}/exceptions", $this->payload() + ['reconcile' => true, 'plan_token' => str_repeat('a', 64)])->assertNotFound();
    }

    #[Test]
    public function a_non_owner_teacher_cannot_use_the_owner_endpoints(): void
    {
        $this->world();
        $year = $this->year();
        $exception = $this->midTermBreak();
        $colleague = $this->colleague(true);
        $acting = fn () => $this->actingAs($colleague)->withSession(['organization_id' => $this->organization->id]);

        $acting()->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'create'] + $this->payload())->assertForbidden();
        $acting()->postJson("/academic-years/{$year->ulid}/exceptions", $this->payload() + ['reconcile' => true, 'plan_token' => str_repeat('a', 64)])->assertForbidden();
        $acting()->deleteJson("/academic-years/{$year->ulid}/exceptions/{$exception->ulid}", ['reconcile' => true, 'plan_token' => str_repeat('a', 64)])->assertForbidden();
        $this->assertSame(2, AcademicCalendarException::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_closed_or_archived_year_refuses_the_owner_and_answers_422_to_the_teacher(): void
    {
        $this->world();
        $year = $this->year();
        $this->midTermBreak();

        foreach ([AcademicYearStatus::Closed, AcademicYearStatus::Archived] as $status) {
            AcademicYear::withoutGlobalScopes()->whereKey($year->id)->update(['status' => $status->value]);
            $before = $this->state();

            $this->asOwner()->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'create'] + $this->payload())->assertForbidden();

            $this->asOwner()->getJson('/lessons/calendar-conflicts')->assertOk()->assertJsonPath('can_regularize', false);
            $this->asOwner()->postJson('/lessons/calendar-conflicts/preview')->assertUnprocessable()->assertJsonValidationErrors('academic_year');
            $this->asOwner()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => str_repeat('a', 64)])
                ->assertUnprocessable()->assertJsonValidationErrors('academic_year');
            $this->assertSame($before, $this->state());
        }
    }

    #[Test]
    public function a_colleague_of_another_class_sees_nothing_and_cannot_regularize(): void
    {
        $this->world();
        $this->midTermBreak();
        $stranger = $this->colleague(false);
        $acting = fn () => $this->actingAs($stranger)->withSession(['organization_id' => $this->organization->id]);

        $acting()->getJson('/lessons/calendar-conflicts')->assertOk()
            ->assertJsonPath('counts', ['A' => 0, 'B' => 0, 'C' => 0])
            ->assertJsonPath('review_lessons', []);
        $acting()->postJson('/lessons/calendar-conflicts/preview')->assertOk()->assertJsonPath('classes', []);

        // O token do dono da turma não serve a quem não a leciona.
        $token = $this->teacherPreview()->json('plan_token');
        $before = $this->state();
        $acting()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($before, $this->state());
    }

    // ------------------------------------------------------------------
    // Professor — GET leve
    // ------------------------------------------------------------------

    #[Test]
    public function the_light_list_counts_a_b_c_and_lists_only_the_review_lessons_without_pedagogical_content(): void
    {
        $this->world();
        $this->midTermBreak();
        $before = $this->state();

        $response = $this->asTeacher()->getJson('/lessons/calendar-conflicts');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('counts', ['A' => 1, 'B' => 1, 'C' => 1])
            ->assertJsonPath('can_regularize', true)
            ->assertJsonCount(1, 'review_lessons')
            ->assertJsonPath('review_lessons.0.date', '2026-10-08')
            ->assertJsonPath('review_lessons.0.class_label', $this->schoolClass->label)
            ->assertJsonPath('review_lessons.0.exception_titles', ['Pré-existente'])
            ->assertJsonPath('review_lessons.0.ulid', $this->lessonAt('2026-10-08')->ulid);
        $codes = array_column($response->json('review_lessons.0.reasons'), 'code');
        $this->assertContains('taught', $codes);
        $this->assertNotSame('', $response->json('review_lessons.0.reasons.0.label'));
        $this->assertNoSentinel($response);
        $this->assertSame($before, $this->state());
    }

    #[Test]
    public function the_light_list_accepts_an_explicit_year(): void
    {
        $this->world();
        $this->midTermBreak();
        $other = $this->inTenant($this->organization, fn (): AcademicYear => AcademicYear::factory()->recycle($this->organization)
            ->create(['starts_on' => '2027-09-01', 'ends_on' => '2028-06-30']));

        $this->asTeacher()->getJson("/lessons/calendar-conflicts?academic_year={$other->ulid}")->assertOk()
            ->assertJsonPath('academic_year.ulid', $other->ulid)
            ->assertJsonPath('counts', ['A' => 0, 'B' => 0, 'C' => 0]);
    }

    // ------------------------------------------------------------------
    // Professor — preview e regularize
    // ------------------------------------------------------------------

    #[Test]
    public function the_teacher_preview_writes_nothing_and_never_returns_pedagogical_content(): void
    {
        $this->world();
        $this->midTermBreak();
        $before = $this->state();

        $response = $this->teacherPreview();

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(64, strlen($response->json('plan_token')));
        $this->assertSame(['A' => 1, 'B' => 1, 'C' => 1], $response->json('counts'));
        $this->assertSame($before, $this->state());
        $this->assertNoSentinel($response);

        $lessons = collect($response->json('classes.0.lessons'))->keyBy('date');
        $this->assertTrue($lessons['2026-10-22']['retired']);
        $this->assertSame('A', $lessons['2026-10-22']['kind']);
        $this->assertSame('B', $lessons['2026-10-29']['kind']);
        $this->assertTrue($lessons['2026-10-29']['retired']);
        $this->assertSame('2026-11-05', $lessons['2026-10-29']['moves'][0]['to_date']);
        $this->assertFalse($lessons['2026-10-29']['moves'][0]['new_lesson']);
        $this->assertFalse($lessons['2026-10-29']['pending']);
        $this->assertSame(['Interrupção'], $lessons['2026-10-29']['exception_titles']);
        $this->assertSame('C', $lessons['2026-10-08']['kind']);
        $this->assertFalse($lessons['2026-10-08']['retired']);
        $this->assertSame('taught', $lessons['2026-10-08']['reasons'][0]['code']);
        $this->assertSame(0, CancelledLessonOccurrence::withoutGlobalScopes()->count());
    }

    #[Test]
    public function regularize_runs_a_and_b_only_on_the_classes_the_teacher_teaches(): void
    {
        $this->world();
        $theirs = $this->otherClass();
        $this->midTermBreak();
        $theirsBefore = $this->classLessons($theirs);
        $this->assertCount(8, $theirsBefore);

        $token = $this->teacherPreview()->json('plan_token');
        $response = $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $token]);

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['A' => 1, 'B' => 1, 'C' => 1], $response->json('counts'));
        $this->assertStringContainsString('1 aula vazia', $response->json('message'));
        // O planeamento passou para a aula seguinte — e não «aulas com planeamento foram retiradas» sozinho.
        $this->assertStringContainsString('O planeamento de 1 aula em dia sem aulas passou para a aula seguinte, sem se perder nada, e essa aula foi retirada.', $response->json('message'));
        $this->assertStringContainsString('1 aula fica como está, para a reveres.', $response->json('message'));
        $this->assertNoSentinel($response);

        // As suas: 22 (A) e 29 (B) saíram; o planeamento da B desceu para 05/11; a C ficou.
        $this->assertSame([], $this->classDays($this->schoolClass, ['2026-10-22', '2026-10-29']));
        $this->assertSame(['2026-10-08'], $this->classDays($this->schoolClass, ['2026-10-08']));
        $this->assertSame('SENTINELA-B', $this->summaryOf('2026-11-05')?->content);
        $this->assertSame('SENTINELA-SUMARIO', $this->summaryOf('2026-10-08')?->content);
        // As do colega: intactas.
        $this->assertSame($theirsBefore, $this->classLessons($theirs));
        $this->assertSame(0, CancelledLessonOccurrence::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event', 'lesson.calendar_reconciled')->count());
    }

    #[Test]
    public function regularize_without_a_token_is_a_validation_error(): void
    {
        $this->world();
        $this->midTermBreak();
        $before = $this->state();

        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize')->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => 'curto'])->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($before, $this->state());
    }

    #[Test]
    public function regularize_is_refused_when_a_summary_is_saved_between_preview_and_confirmation(): void
    {
        $this->world();
        $this->midTermBreak();
        $token = $this->teacherPreview()->json('plan_token');

        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $this->lessonAt('2026-10-22')->id, 'content' => 'Escrevi agora']));
        $before = $this->state();

        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($before, $this->state());
    }

    #[Test]
    public function regularize_is_refused_when_another_teacher_records_a_result_in_between(): void
    {
        $this->world();
        $this->midTermBreak();
        $token = $this->teacherPreview()->json('plan_token');

        $this->inTenant($this->organization, fn () => Lesson::query()->where('starts_at', '2026-10-22 09:30:00')
            ->update(['status' => LessonStatus::Taught->value, 'outcome' => LessonOutcome::Taught->value]));
        $before = $this->state();

        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($before, $this->state());
    }

    #[Test]
    public function regularize_is_refused_when_the_exception_is_edited_in_between(): void
    {
        $this->world();
        $exception = $this->midTermBreak();
        $token = $this->teacherPreview()->json('plan_token');

        $this->inTenant($this->organization, fn () => $exception->update(['ends_on' => '2026-10-22']));
        $before = $this->state();

        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($before, $this->state());
    }

    #[Test]
    public function the_teacher_endpoints_never_change_the_global_calendar(): void
    {
        $this->world();
        $this->midTermBreak();
        $exceptions = AcademicCalendarException::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all();

        $this->teacherPreview();
        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $this->teacherPreview()->json('plan_token')])->assertOk();

        $this->assertSame($exceptions, AcademicCalendarException::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all());
    }

    // ------------------------------------------------------------------
    // Responsável — pré-visualização do impacto
    // ------------------------------------------------------------------

    #[Test]
    public function the_owner_impact_preview_summarises_the_whole_organization_and_writes_nothing(): void
    {
        $this->world();
        $this->otherClass();
        $year = $this->year();
        $before = $this->state();

        $response = $this->asOwner()->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'create'] + $this->payload());

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(64, strlen($response->json('plan_token')));
        $this->assertSame('create', $response->json('operation'));
        $this->assertSame('organization_owner', $response->json('scope'));
        // A: 22/10 nas duas turmas e a de 29/10 do colega; B: 29/10 da primeira. A C de 08/10 (feriado
        // «Pré-existente») NÃO conta: o responsável só vê os dias da exceção que está a gravar.
        $this->assertSame(['A' => 3, 'B' => 1, 'C' => 0], $response->json('counts'));
        $this->assertCount(2, $response->json('classes'));
        $this->assertArrayNotHasKey('lessons', $response->json('classes.0'));
        $this->assertSame([], $response->json('freed_days'));
        $this->assertNoSentinel($response);
        $this->assertStringNotContainsString('"id"', (string) $response->getContent());
        $this->assertSame($before, $this->state());
    }

    #[Test]
    public function the_impact_preview_reports_the_days_a_shortened_or_deleted_exception_frees(): void
    {
        $this->world();
        $year = $this->year();
        $exception = $this->midTermBreak();

        $shorten = $this->asOwner()->postJson("/academic-years/{$year->ulid}/exceptions/impact", [
            'action' => 'update', 'exception' => $exception->ulid,
        ] + $this->payload(['starts_on' => '2026-10-22', 'ends_on' => '2026-10-25']));
        $shorten->assertOk();
        $this->assertSame(['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29'], $shorten->json('freed_days'));
        $this->assertSame(1, $shorten->json('existing_on_freed_days.count'));

        $delete = $this->asOwner()->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'delete', 'exception' => $exception->ulid]);
        $delete->assertOk();
        $this->assertSame('delete', $delete->json('operation'));
        $this->assertCount(8, $delete->json('freed_days'));
        $this->assertSame(0, $delete->json('counts.A'));
        $this->assertDatabaseHas('academic_calendar_exceptions', ['id' => $exception->id]);
        $this->assertSame(9, Lesson::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_impact_preview_validates_like_the_store_and_update(): void
    {
        $this->world();
        $year = $this->year();
        $exception = $this->midTermBreak();
        $url = "/academic-years/{$year->ulid}/exceptions/impact";

        $this->asOwner()->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->asOwner()->postJson($url, ['action' => 'explode'])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->asOwner()->postJson($url, ['action' => 'create'])->assertUnprocessable()->assertJsonValidationErrors(['type', 'title', 'starts_on', 'ends_on']);
        $this->asOwner()->postJson($url, ['action' => 'create'] + $this->payload(['starts_on' => '2026-08-01']))->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->asOwner()->postJson($url, ['action' => 'create'] + $this->payload(['ends_on' => '2027-07-01']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->asOwner()->postJson($url, ['action' => 'update'] + $this->payload())->assertUnprocessable()->assertJsonValidationErrors('exception');
        $this->asOwner()->postJson($url, ['action' => 'delete'])->assertUnprocessable()->assertJsonValidationErrors('exception');
        $this->asOwner()->postJson($url, ['action' => 'delete', 'exception' => 'inexistente'])->assertNotFound();

        // Uma exceção de OUTRO ano da mesma organização não se adota.
        $otherYear = $this->inTenant($this->organization, fn (): AcademicYear => AcademicYear::factory()->recycle($this->organization)
            ->create(['starts_on' => '2027-09-01', 'ends_on' => '2028-06-30']));
        $foreign = $this->inTenant($this->organization, fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)->for($otherYear)->create(['starts_on' => '2027-10-05', 'ends_on' => '2027-10-05']));
        $this->asOwner()->postJson($url, ['action' => 'delete', 'exception' => $foreign->ulid])->assertNotFound();
        $this->asOwner()->postJson($url, ['action' => 'update', 'exception' => $foreign->ulid] + $this->payload())->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Responsável — store/update/destroy
    // ------------------------------------------------------------------

    #[Test]
    public function without_reconcile_store_update_and_destroy_behave_exactly_as_before_and_touch_no_lesson(): void
    {
        $this->world();
        $year = $this->year();
        $lessons = $this->state()['lessons'];

        $this->asOwner()->postJson("/academic-years/{$year->ulid}/exceptions", $this->payload())->assertRedirect();
        $created = AcademicCalendarException::withoutGlobalScopes()->where('title', 'Interrupção')->firstOrFail();
        $this->assertSame('manual', $created->source->value);
        $this->assertSame($lessons, $this->state()['lessons']);

        $this->asOwner()->putJson("/academic-years/{$year->ulid}/exceptions/{$created->ulid}", $this->payload(['ends_on' => '2026-10-23']))->assertRedirect();
        $this->assertSame('2026-10-23', $created->fresh()->ends_on->toDateString());
        $this->assertSame($lessons, $this->state()['lessons']);

        $this->asOwner()->deleteJson("/academic-years/{$year->ulid}/exceptions/{$created->ulid}")->assertRedirect();
        $this->assertDatabaseMissing('academic_calendar_exceptions', ['id' => $created->id]);
        $this->assertSame($lessons, $this->state()['lessons']);
        $this->assertSame(0, AuditEvent::withoutGlobalScopes()->where('event', 'lesson.calendar_reconciled')->count());
    }

    #[Test]
    public function store_with_reconcile_and_a_valid_token_creates_the_exception_and_retires_only_the_empty_lessons(): void
    {
        $this->world();
        $this->otherClass();
        $year = $this->year();
        $token = $this->ownerToken($year, ['action' => 'create'] + $this->payload());

        $response = $this->asOwner()->postJson("/academic-years/{$year->ulid}/exceptions", $this->payload() + ['reconcile' => true, 'plan_token' => $token]);

        $response->assertRedirect();
        $created = AcademicCalendarException::withoutGlobalScopes()->where('title', 'Interrupção')->firstOrFail();
        $this->assertSame('manual', $created->source->value);
        // A: 22/10 nas duas turmas e a vazia do colega a 29/10 saíram. B e C da primeira turma intactas.
        $this->assertSame([], Lesson::withoutGlobalScopes()->where('starts_at', '2026-10-22 09:30:00')->pluck('id')->all());
        $this->assertSame(1, Lesson::withoutGlobalScopes()->where('starts_at', '2026-10-29 09:30:00')->count());
        $this->assertSame(1, Lesson::withoutGlobalScopes()->where('starts_at', '2026-10-08 09:30:00')->count());
        $this->assertSame('SENTINELA-B', $this->summaryOf('2026-10-29')?->content);
        $this->assertSame(0, CancelledLessonOccurrence::withoutGlobalScopes()->count());
        $response->assertSessionHas('inertia.flash_data', function ($flash): bool {
            $toast = $flash['toast'] ?? [];

            return ($toast['type'] ?? null) === 'success'
                && str_contains($toast['message'], 'Adicionado ao calendário do ano letivo.')
                && str_contains($toast['message'], 'Foram retiradas 3 aulas vazias.')
                // Contagens exatas de cada tipo: a B de 29/10 espera pelo professor; a C de 08/10
                // é de outra exceção e não entra nesta gravação.
                && str_contains($toast['message'], '1 aula com planeamento ficou como está: o planeamento não se apaga, e o professor da turma passa-o para a aula seguinte em «Aulas e Sumários».')
                && ! str_contains($toast['message'], 'para revisão');
        });
    }

    #[Test]
    public function reconcile_without_a_token_or_with_a_stale_one_is_a_422_and_writes_nothing_not_even_the_exception(): void
    {
        $this->world();
        $year = $this->year();
        $url = "/academic-years/{$year->ulid}/exceptions";
        $before = $this->state();

        $this->asOwner()->postJson($url, $this->payload() + ['reconcile' => true])->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->asOwner()->postJson($url, $this->payload() + ['reconcile' => true, 'plan_token' => str_repeat('0', 64)])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($before, $this->state());

        // Obsoleto: a pré-visualização foi pedida, e depois um sumário mudou.
        $token = $this->ownerToken($year, ['action' => 'create'] + $this->payload());
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $this->lessonAt('2026-10-22')->id, 'content' => 'Novo']));
        $before = $this->state();

        $this->asOwner()->postJson($url, $this->payload() + ['reconcile' => true, 'plan_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($before, $this->state());
        $this->assertSame(0, AcademicCalendarException::withoutGlobalScopes()->where('title', 'Interrupção')->count());
    }

    #[Test]
    public function a_token_for_one_exception_does_not_authorise_a_different_one(): void
    {
        $this->world();
        $year = $this->year();
        $token = $this->ownerToken($year, ['action' => 'create'] + $this->payload());
        $before = $this->state();

        $this->asOwner()->postJson("/academic-years/{$year->ulid}/exceptions", $this->payload(['ends_on' => '2026-11-05']) + ['reconcile' => true, 'plan_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($before, $this->state());
    }

    #[Test]
    public function update_with_reconcile_extends_the_exception_and_retires_the_newly_covered_empty_lessons(): void
    {
        $this->world();
        $year = $this->year();
        $exception = $this->midTermBreak();
        $extend = $this->payload(['starts_on' => '2026-10-22', 'ends_on' => '2026-11-12']);
        $token = $this->ownerToken($year, ['action' => 'update', 'exception' => $exception->ulid] + $extend);

        $this->asOwner()->putJson("/academic-years/{$year->ulid}/exceptions/{$exception->ulid}", $extend + ['reconcile' => true, 'plan_token' => $token])->assertRedirect();

        $this->assertSame('2026-11-12', $exception->fresh()->ends_on->toDateString());
        // 22/10 (A) e 05/11 e 12/11 (A) saem; 29/10 (B) fica para o professor.
        $this->assertSame([], Lesson::withoutGlobalScopes()->whereIn('starts_at', ['2026-10-22 09:30:00', '2026-11-05 09:30:00', '2026-11-12 09:30:00'])->pluck('id')->all());
        $this->assertSame(1, Lesson::withoutGlobalScopes()->where('starts_at', '2026-10-29 09:30:00')->count());
        $this->assertSame(0, CancelledLessonOccurrence::withoutGlobalScopes()->count());
    }

    #[Test]
    public function shortening_and_deleting_with_reconcile_free_the_days_and_delete_no_lesson(): void
    {
        $this->world();
        $year = $this->year();
        $exception = $this->midTermBreak();
        $lessons = Lesson::withoutGlobalScopes()->count();

        $short = $this->payload(['starts_on' => '2026-10-22', 'ends_on' => '2026-10-25']);
        $token = $this->ownerToken($year, ['action' => 'update', 'exception' => $exception->ulid] + $short);
        $this->asOwner()->putJson("/academic-years/{$year->ulid}/exceptions/{$exception->ulid}", $short + ['reconcile' => true, 'plan_token' => $token])->assertRedirect();

        $this->assertSame('2026-10-25', $exception->fresh()->ends_on->toDateString());
        // 22/10 (A) sai ao continuar coberto; a de 29/10 já não está num dia não letivo: nenhuma aula apagada além dela.
        $this->assertSame($lessons - 1, Lesson::withoutGlobalScopes()->count());
        $this->assertSame(1, Lesson::withoutGlobalScopes()->where('starts_at', '2026-10-29 09:30:00')->count());

        $remaining = Lesson::withoutGlobalScopes()->count();
        $token = $this->ownerToken($year, ['action' => 'delete', 'exception' => $exception->ulid]);
        $this->asOwner()->deleteJson("/academic-years/{$year->ulid}/exceptions/{$exception->ulid}", ['reconcile' => true, 'plan_token' => $token])->assertRedirect();

        $this->assertDatabaseMissing('academic_calendar_exceptions', ['id' => $exception->id]);
        $this->assertSame($remaining, Lesson::withoutGlobalScopes()->count());
        $this->assertSame(0, CancelledLessonOccurrence::withoutGlobalScopes()->count());
    }

    #[Test]
    public function destroy_with_reconcile_and_a_stale_token_keeps_the_exception(): void
    {
        $this->world();
        $year = $this->year();
        $exception = $this->midTermBreak();
        $token = $this->ownerToken($year, ['action' => 'delete', 'exception' => $exception->ulid]);
        $this->inTenant($this->organization, fn () => $exception->update(['title' => 'Mudou']));
        $before = $this->state();

        $this->asOwner()->deleteJson("/academic-years/{$year->ulid}/exceptions/{$exception->ulid}", ['reconcile' => true, 'plan_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($before, $this->state());
        $this->assertDatabaseHas('academic_calendar_exceptions', ['id' => $exception->id]);
    }

    // ------------------------------------------------------------------
    // Aula individual
    // ------------------------------------------------------------------

    #[Test]
    public function the_lesson_page_flags_a_lesson_that_falls_on_a_non_teaching_day(): void
    {
        $this->world();
        $this->midTermBreak();

        $this->asTeacher()->get('/lessons/'.$this->lessonAt('2026-10-22')->ulid)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('calendar_conflict.title', 'Interrupção')
            ->where('calendar_conflict.type', 'holiday')
            ->where('calendar_conflict.starts_on', '2026-10-22')
            ->where('calendar_conflict.ends_on', '2026-10-29'));

        $this->asTeacher()->get('/lessons/'.$this->lessonAt('2026-10-15')->ulid)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('calendar_conflict', null));
    }

    // ------------------------------------------------------------------
    // Um colega, noutra turma da MESMA organização, com aulas já em dias de exceção
    // ------------------------------------------------------------------

    #[Test]
    public function a_colleagues_lessons_on_exception_days_are_left_intact_by_the_teacher_and_only_their_empty_ones_by_the_owner(): void
    {
        $this->world();
        $theirs = $this->otherClass();

        // As aulas do colega existem ANTES da exceção (a materialização precede-a):
        // 15/10 vazia (A), 22/10 lecionada (C) e 29/10 com sumário de preparação (B).
        $c = $this->colleagueLessonAt($theirs, '2026-10-22');
        $b = $this->colleagueLessonAt($theirs, '2026-10-29');
        $a = $this->colleagueLessonAt($theirs, '2026-10-15');
        $this->inTenant($this->organization, function () use ($c, $b): void {
            Lesson::query()->whereKey($c->id)->update(['status' => LessonStatus::Taught->value, 'outcome' => LessonOutcome::Taught->value]);
            LessonSummary::create(['lesson_id' => $c->id, 'content' => 'SENTINELA-SUMARIO', 'private_notes' => 'SENTINELA-NOTAS']);
            LessonSummary::create(['lesson_id' => $b->id, 'content' => 'SENTINELA-B']);
        });

        // A interrupção de 15 a 29 de outubro cobre as quintas 15 (A), 22 e 29 nas duas turmas.
        $this->inTenant($this->organization, fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)->for($this->year())
            ->create(['type' => AcademicCalendarExceptionType::Holiday, 'title' => 'Interrupção', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-29']));

        $theirsBefore = $this->classLessons($theirs);
        $theirsSummariesBefore = LessonSummary::withoutGlobalScopes()->whereIn('lesson_id', [$a->id, $b->id, $c->id])->orderBy('id')->get()->map->getAttributes()->all();

        // O aviso do professor X não lista nada da turma do colega — nem A, nem B, nem C.
        $index = $this->asTeacher()->getJson('/lessons/calendar-conflicts');
        $index->assertOk()
            // A turma de X: 15/10 e 22/10 vazias (A), 29/10 com sumário (B) e 08/10 lecionada (C).
            ->assertJsonPath('counts', ['A' => 2, 'B' => 1, 'C' => 1]);
        $this->assertSame([$this->lessonAt('2026-10-08')->ulid], array_column($index->json('review_lessons'), 'ulid'));
        $this->assertNotContains($c->ulid, array_column($index->json('review_lessons'), 'ulid'));
        $this->assertSame([$this->schoolClass->ulid], array_column($index->json('classes'), 'class'));

        // O professor X regulariza: as suas A e B saem, as do colega ficam 100% intactas (A, B e C).
        $token = $this->teacherPreview()->json('plan_token');
        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $token])->assertOk();

        $this->assertSame([], $this->classDays($this->schoolClass, ['2026-10-15', '2026-10-22', '2026-10-29']));
        $this->assertSame($theirsBefore, $this->classLessons($theirs));
        $this->assertSame($theirsSummariesBefore, LessonSummary::withoutGlobalScopes()->whereIn('lesson_id', [$a->id, $b->id, $c->id])->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame('SENTINELA-B', LessonSummary::withoutGlobalScopes()->where('lesson_id', $b->id)->value('content'));
        $this->assertSame('SENTINELA-SUMARIO', LessonSummary::withoutGlobalScopes()->where('lesson_id', $c->id)->value('content'));
        $this->assertSame(0, CancelledLessonOccurrence::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_owner_who_does_not_teach_a_class_retires_its_empty_lessons_but_never_moves_its_planning(): void
    {
        $this->world();
        $theirs = $this->otherClass();
        $b = $this->colleagueLessonAt($theirs, '2026-10-29');
        $c = $this->colleagueLessonAt($theirs, '2026-10-22');
        $a = $this->colleagueLessonAt($theirs, '2026-10-15');
        $this->inTenant($this->organization, function () use ($c, $b): void {
            Lesson::query()->whereKey($c->id)->update(['status' => LessonStatus::Taught->value, 'outcome' => LessonOutcome::Taught->value]);
            LessonSummary::create(['lesson_id' => $b->id, 'content' => 'SENTINELA-B']);
        });

        $year = $this->year();
        $payload = $this->payload(['starts_on' => '2026-10-15', 'ends_on' => '2026-10-29']);
        $token = $this->ownerToken($year, ['action' => 'create'] + $payload);

        $this->asOwner()->postJson("/academic-years/{$year->ulid}/exceptions", $payload + ['reconcile' => true, 'plan_token' => $token])->assertRedirect();

        // A turma do colega: a A (15/10) saiu; a B (29/10, com sumário) e a C (22/10, lecionada) ficam, com o conteúdo.
        $this->assertSame(0, Lesson::withoutGlobalScopes()->whereKey($a->id)->count());
        $this->assertSame(1, Lesson::withoutGlobalScopes()->whereKey($b->id)->count());
        $this->assertSame(1, Lesson::withoutGlobalScopes()->whereKey($c->id)->count());
        $this->assertSame('SENTINELA-B', LessonSummary::withoutGlobalScopes()->where('lesson_id', $b->id)->value('content'));
        // E o planeamento da B não foi levado para a aula seguinte (05/11) do colega.
        $this->assertNull(LessonSummary::withoutGlobalScopes()->where('lesson_id', $this->colleagueLessonAt($theirs, '2026-11-05')->id)->first());
        $this->assertSame(0, CancelledLessonOccurrence::withoutGlobalScopes()->count());
    }

    // ------------------------------------------------------------------
    // O responsável só toca nos dias da exceção que grava
    // ------------------------------------------------------------------

    #[Test]
    public function the_owner_only_retires_lessons_on_the_days_of_the_exception_being_saved_and_never_on_delete(): void
    {
        $this->world();
        $year = $this->year();
        $url = "/academic-years/{$year->ulid}/exceptions";

        // E1, gravada ANTES «sem alterar as aulas»: a quinta 15/10 (vazia) fica lá.
        $this->asOwner()->postJson($url, $this->payload(['title' => 'E1', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-15']))->assertRedirect();
        $e1 = AcademicCalendarException::withoutGlobalScopes()->where('title', 'E1')->firstOrFail();
        $fifteenth = $this->lessonAt('2026-10-15');

        // E2 (22 a 29/10), com «Confirmar e aplicar às aulas»: só os dias de E2.
        $impact = $this->asOwner()->postJson("{$url}/impact", ['action' => 'create'] + $this->payload(['title' => 'E2']));
        $impact->assertOk();
        $this->assertSame(['A' => 1, 'B' => 1, 'C' => 0], $impact->json('counts'));
        $this->asOwner()->postJson($url, $this->payload(['title' => 'E2']) + ['reconcile' => true, 'plan_token' => $impact->json('plan_token')])->assertRedirect();

        $this->assertSame([], $this->classDays($this->schoolClass, ['2026-10-22']));
        $this->assertNotNull(Lesson::withoutGlobalScopes()->find($fifteenth->id), 'A vazia de E1 não é retirada ao gravar E2.');

        // Alterar E2 também só olha para o intervalo novo de E2.
        $e2 = AcademicCalendarException::withoutGlobalScopes()->where('title', 'E2')->firstOrFail();
        $update = $this->asOwner()->postJson("{$url}/impact", ['action' => 'update', 'exception' => $e2->ulid] + $this->payload(['title' => 'E2', 'ends_on' => '2026-10-30']));
        $this->assertSame(['A' => 0, 'B' => 1, 'C' => 0], $update->json('counts'));

        // Eliminar E1 com «Confirmar»: nenhuma aula é retirada — só se libertam dias.
        $delete = $this->asOwner()->postJson("{$url}/impact", ['action' => 'delete', 'exception' => $e1->ulid]);
        $this->assertSame(['A' => 0, 'B' => 0, 'C' => 0], $delete->json('counts'));
        $this->assertFalse($delete->json('changes_anything'));
        $count = Lesson::withoutGlobalScopes()->count();
        $this->asOwner()->deleteJson("{$url}/{$e1->ulid}", ['reconcile' => true, 'plan_token' => $delete->json('plan_token')])->assertRedirect();
        $this->assertSame($count, Lesson::withoutGlobalScopes()->count());
        $this->assertNotNull(Lesson::withoutGlobalScopes()->find($fifteenth->id));
    }

    #[Test]
    public function the_teacher_regularizes_every_exception_of_the_year_in_their_classes(): void
    {
        $this->world();
        $year = $this->year();
        $url = "/academic-years/{$year->ulid}/exceptions";
        $this->asOwner()->postJson($url, $this->payload(['title' => 'E1', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-15']))->assertRedirect();
        $this->asOwner()->postJson($url, $this->payload(['title' => 'E2']))->assertRedirect();

        $preview = $this->teacherPreview();

        // 15/10 (E1) e 22/10 (E2) vazias, 29/10 com planeamento, 08/10 lecionada.
        $this->assertSame(['A' => 2, 'B' => 1, 'C' => 1], $preview->json('counts'));
        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $preview->json('plan_token')])->assertOk();
        $this->assertSame([], $this->classDays($this->schoolClass, ['2026-10-15', '2026-10-22', '2026-10-29']));
    }

    // ------------------------------------------------------------------
    // Sessão de suporte e módulo das aulas
    // ------------------------------------------------------------------

    #[Test]
    public function reconcile_is_refused_during_a_support_session_and_writes_nothing(): void
    {
        $this->world();
        $year = $this->year();
        $exception = $this->midTermBreak();
        $url = "/academic-years/{$year->ulid}/exceptions";
        $impersonating = fn () => $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => 999]);

        // A pré-visualização só lê: como as outras pré-visualizações, não é recusada.
        $token = $impersonating()->postJson("{$url}/impact", ['action' => 'create'] + $this->payload(['title' => 'Outra', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05']))
            ->assertOk()->json('plan_token');
        $before = $this->state();

        $impersonating()->postJson($url, $this->payload(['title' => 'Outra', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05']) + ['reconcile' => true, 'plan_token' => $token])->assertForbidden();
        $impersonating()->putJson("{$url}/{$exception->ulid}", $this->payload(['ends_on' => '2026-11-05']) + ['reconcile' => true, 'plan_token' => $token])->assertForbidden();
        $impersonating()->deleteJson("{$url}/{$exception->ulid}", ['reconcile' => true, 'plan_token' => $token])->assertForbidden();

        $this->assertSame($before, $this->state());
    }

    #[Test]
    public function without_the_lessons_module_the_impact_is_empty_and_reconcile_is_refused(): void
    {
        $this->world();
        $year = $this->year();
        $this->subscribeTo('base');
        $url = "/academic-years/{$year->ulid}/exceptions";
        $before = $this->state();

        $impact = $this->asOwner()->postJson("{$url}/impact", ['action' => 'create'] + $this->payload());
        $impact->assertOk();
        $this->assertSame(['A' => 0, 'B' => 0, 'C' => 0], $impact->json('counts'));
        $this->assertFalse($impact->json('changes_anything'));
        $this->assertSame($before, $this->state());

        $this->asOwner()->postJson($url, $this->payload() + ['reconcile' => true, 'plan_token' => $impact->json('plan_token')])->assertForbidden();
        $this->assertSame($before, $this->state(), 'Nada escrito — nem a exceção.');

        // Sem `reconcile`, gravar a exceção é o de sempre.
        $this->asOwner()->postJson($url, $this->payload())->assertRedirect();
        $this->assertSame(1, AcademicCalendarException::withoutGlobalScopes()->where('title', 'Interrupção')->count());
        $this->assertSame($before['lessons'], $this->state()['lessons']);
    }

    // ------------------------------------------------------------------
    // O aviso, a pré-visualização e a página da aula dizem o mesmo
    // ------------------------------------------------------------------

    #[Test]
    public function the_light_list_and_the_preview_count_the_same_after_every_chain_and_destination_check(): void
    {
        $this->world();
        $this->midTermBreak();
        // A aula a seguir à B (05/11) tem um número indicado à mão: o planeamento
        // de 29/10 não pode descer por ela, e a B passa a C.
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($this->lessonAt('2026-11-05')->id)
            ->update(['lesson_number' => 9, 'lesson_number_manual' => true]));

        $index = $this->asTeacher()->getJson('/lessons/calendar-conflicts');
        $preview = $this->teacherPreview();

        $this->assertSame(['A' => 1, 'B' => 0, 'C' => 2], $index->json('counts'));
        $this->assertSame($index->json('counts'), $preview->json('counts'));
        $reasons = array_merge(...array_map(fn (array $lesson): array => $lesson['reasons'], $index->json('review_lessons')));
        $protected = collect($reasons)->firstWhere('code', 'protected_lesson_after');
        $this->assertNotNull($protected);
        $this->assertNotSame('protected_lesson_after', $protected['label'], 'O motivo chega dito em português.');
    }

    #[Test]
    public function the_light_list_already_knows_a_planned_lesson_with_nowhere_to_go(): void
    {
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-15']);
        $this->materialize($this->schoolClass);
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $this->lessonAt('2026-10-15')->id, 'content' => 'SENTINELA-B']));
        $this->inTenant($this->organization, fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)->for($this->year())
            ->create(['type' => AcademicCalendarExceptionType::Holiday, 'title' => 'Feriado', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-15']));

        $index = $this->asTeacher()->getJson('/lessons/calendar-conflicts');
        $preview = $this->teacherPreview();

        $this->assertSame(['A' => 0, 'B' => 0, 'C' => 1], $index->json('counts'));
        $this->assertSame($index->json('counts'), $preview->json('counts'));
        $this->assertSame('no_destination', $index->json('review_lessons.0.reasons.0.code'));
    }

    #[Test]
    public function the_lesson_page_says_whether_the_lesson_would_really_be_retired(): void
    {
        $this->world();
        $this->midTermBreak();

        $conflict = fn (string $day) => AssertableInertia::fromTestResponse($this->asTeacher()->get('/lessons/'.$this->lessonAt($day)->ulid))->toArray()['props']['calendar_conflict'];

        $this->assertSame(['A', true, []], [$conflict('2026-10-22')['kind'], $conflict('2026-10-22')['retirable'], $conflict('2026-10-22')['reasons']]);
        $this->assertSame(['B', true], [$conflict('2026-10-29')['kind'], $conflict('2026-10-29')['retirable']]);
        $this->assertSame(['C', false, 'taught'], [$conflict('2026-10-08')['kind'], $conflict('2026-10-08')['retirable'], $conflict('2026-10-08')['reasons'][0]['code']]);

        // A cadeia da B atravessa agora uma aula com faltas: deixa de ser retirável.
        $enrollment = $this->enroll('Ana Silva');
        $this->inTenant($this->organization, fn () => LessonAttendance::create([
            'lesson_id' => $this->lessonAt('2026-11-05')->id, 'enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id, 'status' => AttendanceStatus::Absent,
        ]));

        $after = $conflict('2026-10-29');
        $this->assertSame(['C', false, 'protected_lesson_after'], [$after['kind'], $after['retirable'], $after['reasons'][0]['code']]);
        $this->assertNotSame('', $after['reasons'][0]['label']);
    }

    /**
     * O limite de planeamentos por execução conta as B de TODAS as turmas do
     * professor: a página da aula classifica as mesmas turmas que «Ver e
     * corrigir», e não diz «pode ser retirada» de uma B que a regularização
     * deixa para a execução seguinte.
     */
    #[Test]
    public function the_lesson_page_counts_the_planning_limit_across_all_of_the_teachers_classes(): void
    {
        config(['lapis.calendar_reconciliation_max_planned' => 1]);
        $this->world();
        $second = $this->inTenant($this->organization, function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });
        $this->makeSlot(['class_id' => $second->id, 'day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->materialize($second);
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $this->colleagueLessonAt($second, '2026-10-29')->id, 'content' => 'SENTINELA-B']));
        $this->midTermBreak();

        $conflict = AssertableInertia::fromTestResponse($this->asTeacher()->get('/lessons/'.$this->lessonAt('2026-10-29')->ulid))->toArray()['props']['calendar_conflict'];

        $this->assertSame(['C', false, 'batch_limit', true], [$conflict['kind'], $conflict['retirable'], $conflict['reasons'][0]['code'], $conflict['in_scope']]);
        $index = $this->asTeacher()->getJson('/lessons/calendar-conflicts');
        $this->assertSame(['A' => 2, 'B' => 1, 'C' => 2], $index->json('counts'));
    }

    #[Test]
    public function regularize_works_when_the_plan_materializes_lessons_for_two_planned_ones(): void
    {
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $this->schoolClass, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-29'), $this->teacher,
        ));
        $this->inTenant($this->organization, function (): void {
            LessonSummary::create(['lesson_id' => $this->lessonAt('2026-10-22')->id, 'content' => 'SENTINELA-B']);
            LessonSummary::create(['lesson_id' => $this->lessonAt('2026-10-29')->id, 'content' => 'SENTINELA-SUMARIO']);
        });
        $this->midTermBreak();

        $preview = $this->teacherPreview();
        $preview->assertOk();
        $this->assertNoSentinel($preview);
        $moves = collect($preview->json('classes.0.lessons'))->pluck('moves')->flatten(1);
        $this->assertTrue($moves->every(fn (array $move): bool => $move['to_lesson'] === null && $move['new_lesson'] === true));

        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $preview->json('plan_token')])
            ->assertOk()
            ->assertJsonPath('totals.shifted', 2);
        $this->assertSame('SENTINELA-B', $this->summaryOf('2026-11-05')?->content);
        $this->assertSame('SENTINELA-SUMARIO', $this->summaryOf('2026-11-12')?->content);
    }

    // ------------------------------------------------------------------
    // 3.ª revisão: o aviso, a página da aula e a pré-visualização coincidem
    // ------------------------------------------------------------------

    /**
     * A próxima ocorrência da T2 (22/10) não nasce: um tempo da turma inteira,
     * de id menor, faz nascer primeiro a sua aula à mesma hora. O aviso e a
     * página da aula já o sabem — antes contavam duas aulas «corrigíveis» (B=2,
     * `retirable`) que «Ver e corrigir» nunca corrigia, e o aviso pedia a
     * correção para sempre. Agora as três respostas coincidem, e o aviso só
     * pede revisão.
     */
    #[Test]
    public function the_light_list_and_the_lesson_page_agree_with_the_preview_when_a_destination_cannot_be_created(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Lisbon'));
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $key = 'k-'.$this->schoolClass->id;
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-10-22', 'ends_on' => '2027-06-30']);
        $this->makeSlot(['class_group_id' => $t1->id, 'day_of_week' => 1, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key]);
        $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key]);
        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $this->schoolClass, CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-18'), $this->teacher,
        ));
        $this->inTenant($this->organization, function (): void {
            LessonSummary::create(['lesson_id' => Lesson::query()->where('starts_at', '2026-10-12 09:30:00')->firstOrFail()->id, 'content' => 'SENTINELA-B']);
            LessonSummary::create(['lesson_id' => Lesson::query()->where('starts_at', '2026-10-15 09:30:00')->firstOrFail()->id, 'content' => 'SENTINELA-SUMARIO']);
        });
        $this->inTenant($this->organization, fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)->for($this->year())
            ->create(['type' => AcademicCalendarExceptionType::Holiday, 'title' => 'Ponte', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-15']));

        $index = $this->asTeacher()->getJson('/lessons/calendar-conflicts');
        $preview = $this->teacherPreview();
        $t2Lesson = $this->inTenant($this->organization, fn (): Lesson => Lesson::query()->where('starts_at', '2026-10-15 09:30:00')->firstOrFail());
        $page = AssertableInertia::fromTestResponse($this->asTeacher()->get('/lessons/'.$t2Lesson->ulid))->toArray()['props']['calendar_conflict'];

        $this->assertSame(['A' => 0, 'B' => 0, 'C' => 2], $index->json('counts'));
        $this->assertSame($index->json('counts'), $preview->json('counts'));
        $this->assertCount(2, $index->json('review_lessons'));
        $this->assertSame(['C', false, 'no_destination'], [$page['kind'], $page['retirable'], $page['reasons'][0]['code']]);
        $this->assertFalse($preview->json('changes_anything'));

        // E «Ver e corrigir» não tem nada a corrigir: o aviso continua a dizer o mesmo, sem prometer correção.
        $done = $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $preview->json('plan_token')]);
        $done->assertOk();
        $this->assertStringStartsWith('Nenhuma aula foi alterada.', $done->json('message'));
        $this->assertSame(['A' => 0, 'B' => 0, 'C' => 2], $this->asTeacher()->getJson('/lessons/calendar-conflicts')->json('counts'));
    }

    /** O cabeçalho do aviso diz as interrupções de TODAS as aulas contadas, e não só das que ficam para rever. */
    #[Test]
    public function the_light_list_names_the_exceptions_of_every_counted_lesson(): void
    {
        $this->world();
        $this->midTermBreak();

        $this->asTeacher()->getJson('/lessons/calendar-conflicts')->assertOk()
            ->assertJsonPath('exception_titles', ['Pré-existente', 'Interrupção'])
            ->assertJsonPath('review_lessons.0.exception_titles', ['Pré-existente']);
    }

    /**
     * Um token malformado é recusado pela validação — antes de a execução
     * prender o ano e as turmas e recalcular o plano — e a regularização tem um
     * limite de pedidos próprio, mais estreito que o da pré-visualização.
     */
    #[Test]
    public function a_malformed_token_is_refused_up_front_and_regularize_is_throttled(): void
    {
        $this->world();
        $this->midTermBreak();
        $before = $this->state();

        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => str_repeat('z', 64)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.plan_token.0', 'A pré-visualização é inválida. Pede-a de novo.');

        for ($attempt = 2; $attempt <= 10; $attempt++) {
            $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => str_repeat('z', 64)])->assertUnprocessable();
        }

        $this->asTeacher()->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => str_repeat('z', 64)])->assertTooManyRequests();
        $this->assertSame($before, $this->state());
    }

    /**
     * «Precisa de revisão» do responsável: por turma, o número de aulas por
     * razão, em português e sem conteúdo — com o nome do outro grupo da lição
     * partilhada.
     */
    #[Test]
    public function the_owner_impact_counts_the_review_lessons_by_reason_and_names_the_other_group(): void
    {
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $key = 'k-'.$this->schoolClass->id;
        $this->makeSlot(['class_group_id' => $t1->id, 'day_of_week' => 1, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key]);
        $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 2, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30', 'split_lesson_key' => $key]);
        $this->materialize($this->schoolClass);
        $year = $this->year();

        $response = $this->asOwner()->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'create'] + $this->payload(['starts_on' => '2026-10-19', 'ends_on' => '2026-10-19']));

        $response->assertOk()
            ->assertJsonPath('counts', ['A' => 0, 'B' => 0, 'C' => 1])
            ->assertJsonPath('classes.0.review', [['count' => 1, 'label' => 'faz parte de uma lição partilhada com o grupo T2, que continua a ter aula']]);
        $this->assertNoSentinel($response);
    }

    /**
     * A pré-visualização do professor diz ONDE FICA cada planeamento — o das
     * aulas retiradas e, à parte, o que já estava nas aulas seguintes e avança
     * —, só com datas.
     */
    #[Test]
    public function the_teacher_preview_says_where_each_planning_ends_up(): void
    {
        $this->world();
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $this->lessonAt('2026-11-05')->id, 'content' => 'SENTINELA-PLANO']));
        $this->midTermBreak();

        $preview = $this->teacherPreview();

        $preview->assertOk()->assertJsonPath('classes.0.planning', [
            ['origin' => 'retired', 'from_date' => '2026-10-29', 'to_date' => '2026-11-05', 'new_lesson' => false, 'pending' => false],
            ['origin' => 'advanced', 'from_date' => '2026-11-05', 'to_date' => '2026-11-12', 'new_lesson' => false, 'pending' => false],
        ]);
        $this->assertNoSentinel($preview);
    }

    // ------------------------------------------------------------------
    // Ajudantes
    // ------------------------------------------------------------------

    private function subscribeTo(string $planKey): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $this->organization->id)->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'plan_id' => Plan::query()->where('key', $planKey)->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => CarbonImmutable::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    /** Horário às quintas, 9 aulas materializadas, e o feriado de 08/10 sobre uma aula lecionada (C). */
    private function world(): void
    {
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->materialize($this->schoolClass);

        $c = $this->lessonAt('2026-10-08');
        $this->inTenant($this->organization, function () use ($c): void {
            Lesson::query()->whereKey($c->id)->update(['status' => LessonStatus::Taught->value, 'outcome' => LessonOutcome::Taught->value]);
            LessonSummary::create(['lesson_id' => $c->id, 'content' => 'SENTINELA-SUMARIO', 'private_notes' => 'SENTINELA-NOTAS']);
            LessonPlan::create(['lesson_id' => $c->id, 'planned_summary' => 'SENTINELA-PLANO', 'created_by' => $this->teacher->id]);
        });

        $b = $this->lessonAt('2026-10-29');
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $b->id, 'content' => 'SENTINELA-B']));

        $this->inTenant($this->organization, fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)->for($this->year())
            ->create(['type' => AcademicCalendarExceptionType::Holiday, 'title' => 'Pré-existente', 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-08']));
    }

    /** A interrupção de 22 a 29 de outubro: a quinta 22 vazia (A) e a 29 com sumário (B). */
    private function midTermBreak(): AcademicCalendarException
    {
        return $this->inTenant($this->organization, fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)->for($this->year())
            ->create(['type' => AcademicCalendarExceptionType::Holiday, 'title' => 'Interrupção', 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-29']));
    }

    /** Uma segunda turma do mesmo ano, de outro professor, com o mesmo horário e as mesmas 9 aulas (sem conteúdo). */
    private function otherClass(): SchoolClass
    {
        $other = $this->colleague(false);
        $class = $this->inTenant($this->organization, function () use ($other): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id]);
            $class->teachers()->attach($other, ['role' => 'owner']);

            return $class;
        });
        $this->makeSlot(['class_id' => $class->id, 'day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20', 'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30']);
        $this->materialize($class);

        return $class;
    }

    private function materialize(SchoolClass $class): void
    {
        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $class,
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-11-30'),
            $this->teacher,
        ));
    }

    private function colleagueLessonAt(SchoolClass $class, string $day): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::query()
            ->where('class_id', $class->id)->where('starts_at', $day.' 09:30:00')->firstOrFail());
    }

    private function year(): AcademicYear
    {
        return $this->inTenant($this->organization, fn (): AcademicYear => AcademicYear::query()->findOrFail($this->schoolClass->academic_year_id));
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'holiday',
            'title' => 'Interrupção',
            'starts_on' => '2026-10-22',
            'ends_on' => '2026-10-29',
            'note' => null,
        ], $overrides);
    }

    private function asOwner(): self
    {
        return $this->asTeacher();
    }

    private function teacherPreview(): TestResponse
    {
        return $this->asTeacher()->postJson('/lessons/calendar-conflicts/preview');
    }

    /** @param  array<string, mixed>  $body */
    private function ownerToken(AcademicYear $year, array $body): string
    {
        $response = $this->asOwner()->postJson("/academic-years/{$year->ulid}/exceptions/impact", $body);
        $response->assertOk();

        return (string) $response->json('plan_token');
    }

    private function lessonAt(string $day): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::query()
            ->where('class_id', $this->schoolClass->id)->where('starts_at', $day.' 09:30:00')->firstOrFail());
    }

    private function summaryOf(string $day): ?LessonSummary
    {
        return LessonSummary::withoutGlobalScopes()->where('lesson_id', $this->lessonAt($day)->id)->first();
    }

    /**
     * @param  list<string>  $days
     * @return list<string>
     */
    private function classDays(SchoolClass $class, array $days): array
    {
        return Lesson::withoutGlobalScopes()->where('class_id', $class->id)
            ->whereIn('starts_at', array_map(fn (string $day): string => $day.' 09:30:00', $days))
            ->orderBy('starts_at')->get()->map(fn (Lesson $lesson): string => $lesson->starts_at->format('Y-m-d'))->all();
    }

    /** @return list<array<string, mixed>> */
    private function classLessons(SchoolClass $class): array
    {
        return Lesson::withoutGlobalScopes()->where('class_id', $class->id)->orderBy('id')->get()->map->getAttributes()->all();
    }

    /** O mundo que estes pedidos podem tocar, para provar que nada foi escrito. */
    private function state(): array
    {
        return [
            'lessons' => Lesson::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            'summaries' => LessonSummary::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            'plans' => LessonPlan::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            'exceptions' => AcademicCalendarException::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            'marks' => CancelledLessonOccurrence::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            'audit' => AuditEvent::withoutGlobalScopes()->count(),
        ];
    }

    private function assertNoSentinel(TestResponse $response): void
    {
        $body = (string) $response->getContent();

        foreach (self::SENTINELS as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $body);
        }
    }

    private function colleague(bool $teaches): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization, ['joined_at' => now()]);

        if ($teaches) {
            $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($user, ['role' => 'co_teacher']));
        }

        return $user;
    }
}
