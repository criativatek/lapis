<?php

namespace Tests\Feature\Timetable;

use App\Actions\Accounts\AnonymiseClosedAccount;
use App\Actions\Lessons\MaterializeLessonsForWeek;
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
use App\Models\TimetableActivityKind;
use App\Models\User;
use App\Services\Lessons\LessonNumbering;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Atividades não letivas do horário do professor (0.168.0): direção de turma,
 * apoio, reuniões, coordenação. NÃO SÃO AULAS — a garantia central deste
 * ficheiro é que, por mais atividades que existam, nenhum `Lesson` nasce, nada
 * é numerado e «Aulas de hoje» não as vê.
 *
 * O relógio é fixado ANTES de qualquer dado (quarta-feira, 14/10/2026): criar
 * dados com `now()` e congelar depois é a bomba-relógio conhecida deste repo.
 */
class TimetableActivityTest extends TestCase
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

    // ------------------------------------------------------------------ CRUD

    #[Test]
    public function a_teacher_adds_edits_and_deletes_an_activity(): void
    {
        $sixthD = $this->schoolClassFor($this->teacher, '6.º D');

        $this->save('post', '/timetable-activities', $this->payload([
            'kind' => 'class_direction',
            'title' => 'DT 6.º D',
            'class_ulid' => $sixthD->ulid,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $activity = $this->inTenant($this->organization, fn (): TimetableActivity => TimetableActivity::query()->sole());

        $this->assertSame(TimetableActivityKind::ClassDirection, $activity->kind);
        $this->assertSame('DT 6.º D', $activity->title);
        $this->assertSame($sixthD->id, $activity->class_id);
        $this->assertSame($this->teacher->id, $activity->user_id);
        $this->assertSame($this->organization->id, $activity->organization_id);
        $this->assertSame(2, $activity->day_of_week);

        $this->save('put', "/timetable-activities/{$activity->ulid}", $this->payload([
            'kind' => 'meeting',
            'title' => 'Reunião de DT',
            'starts_at' => '11:00',
            'ends_at' => '11:50',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $activity->refresh();
        $this->assertSame(TimetableActivityKind::Meeting, $activity->kind);
        $this->assertSame('Reunião de DT', $activity->title);
        $this->assertNull($activity->class_id);
        $this->assertStringStartsWith('11:00', $activity->starts_at);

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->delete("/timetable-activities/{$activity->ulid}")
            ->assertRedirect();

        $this->assertDatabaseCount('timetable_activities', 0);
    }

    // -------------------------------------------------------------- validation

    #[Test]
    public function the_request_is_validated(): void
    {
        $this->save('post', '/timetable-activities', $this->payload(['kind' => 'nonsense', 'title' => '']))
            ->assertSessionHasErrors(['kind', 'title']);

        $this->save('post', '/timetable-activities', $this->payload(['title' => str_repeat('a', 61)]))
            ->assertSessionHasErrors('title');

        $this->save('post', '/timetable-activities', $this->payload(['day_of_week' => 8]))
            ->assertSessionHasErrors('day_of_week');

        $this->save('post', '/timetable-activities', $this->payload(['starts_at' => '11:00', 'ends_at' => '10:00']))
            ->assertSessionHasErrors('ends_at');

        $this->save('post', '/timetable-activities', $this->payload(['starts_on' => '2026-11-10', 'ends_on' => '2026-11-01']))
            ->assertSessionHasErrors('ends_on');

        $this->assertDatabaseCount('timetable_activities', 0);
    }

    #[Test]
    public function a_referenced_turma_must_be_taught_by_the_teacher(): void
    {
        $colleague = User::factory()->create();
        $this->attachToOrganization($colleague, $this->organization);
        $notMine = $this->schoolClassFor($colleague, '9.º A');

        $this->save('post', '/timetable-activities', $this->payload(['class_ulid' => $notMine->ulid]))
            ->assertSessionHasErrors('class_ulid');

        $this->assertDatabaseCount('timetable_activities', 0);
    }

    #[Test]
    public function a_turma_of_another_organization_is_refused(): void
    {
        $outsider = User::factory()->create();
        $foreign = $this->schoolClassFor($outsider, '6.º D', $outsider->personalOrganization());

        $this->save('post', '/timetable-activities', $this->payload(['class_ulid' => $foreign->ulid]))
            ->assertSessionHasErrors('class_ulid');

        $this->assertDatabaseCount('timetable_activities', 0);
    }

    #[Test]
    public function two_activities_of_the_same_teacher_cannot_overlap_in_time_and_vigency(): void
    {
        $this->activity(['title' => 'RTC', 'starts_at' => '14:20', 'ends_at' => '15:10']);

        $this->save('post', '/timetable-activities', $this->payload(['title' => 'Outra', 'starts_at' => '15:00', 'ends_at' => '15:50']))
            ->assertSessionHasErrors('starts_at');

        $this->assertStringContainsString(
            'RTC',
            (string) session('errors')->first('starts_at'),
        );

        // Imediatamente a seguir não é sobreposição.
        $this->save('post', '/timetable-activities', $this->payload(['title' => 'A seguir', 'starts_at' => '15:10', 'ends_at' => '16:00']))
            ->assertSessionHasNoErrors();

        // Outro dia também não.
        $this->save('post', '/timetable-activities', $this->payload(['title' => 'Quinta', 'day_of_week' => 4, 'starts_at' => '14:20', 'ends_at' => '15:10']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('timetable_activities', 3);
    }

    #[Test]
    public function the_same_hour_is_allowed_when_the_vigencies_do_not_cross(): void
    {
        $this->activity(['title' => 'Antes', 'starts_on' => null, 'ends_on' => '2026-10-31']);

        $this->save('post', '/timetable-activities', $this->payload(['title' => 'Depois', 'starts_on' => '2026-11-01']))
            ->assertSessionHasNoErrors();

        $this->save('post', '/timetable-activities', $this->payload(['title' => 'Cruza', 'starts_on' => '2026-10-20', 'ends_on' => '2026-11-05']))
            ->assertSessionHasErrors('starts_at');
    }

    #[Test]
    public function editing_an_activity_does_not_clash_with_itself(): void
    {
        $activity = $this->activity(['title' => 'RTC']);

        $this->save('put', "/timetable-activities/{$activity->ulid}", $this->payload(['title' => 'RTC 2']))
            ->assertSessionHasNoErrors();

        $this->assertSame('RTC 2', $activity->refresh()->title);
    }

    #[Test]
    public function an_overlap_with_a_lesson_is_not_blocked(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::create([
            'class_id' => $schoolClass->id,
            'day_of_week' => 2,
            'starts_at' => '10:30',
            'ends_at' => '11:20',
        ]));

        $this->save('post', '/timetable-activities', $this->payload(['starts_at' => '10:30', 'ends_at' => '11:20']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('timetable_activities', 1);
    }

    // ----------------------------------------------------------------- policy

    #[Test]
    public function another_teacher_of_the_same_organization_cannot_edit_or_delete_it_and_does_not_see_it(): void
    {
        $activity = $this->activity(['title' => 'Minha']);

        $colleague = User::factory()->create();
        $this->attachToOrganization($colleague, $this->organization);

        $this->actingAs($colleague)
            ->withSession($this->tenantSession())
            ->put("/timetable-activities/{$activity->ulid}", $this->payload(['title' => 'Roubada']))
            ->assertNotFound();

        $this->actingAs($colleague)
            ->withSession($this->tenantSession())
            ->delete("/timetable-activities/{$activity->ulid}")
            ->assertNotFound();

        $this->assertSame('Minha', $activity->refresh()->title);

        $this->actingAs($colleague)
            ->withSession($this->tenantSession())
            ->get('/timetable?week=2026-10-12')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('activities', 0)->etc());
    }

    #[Test]
    public function an_activity_of_another_organization_is_invisible_and_untouchable(): void
    {
        $outsider = User::factory()->create();
        $outsiderOrganization = $outsider->personalOrganization();
        $this->subscribeToPro($outsiderOrganization);

        $foreign = $this->inTenant($outsiderOrganization, fn (): TimetableActivity => TimetableActivity::create([
            'user_id' => $outsider->id,
            'kind' => 'other',
            'title' => 'Alheia',
            'day_of_week' => 2,
            'starts_at' => '10:00',
            'ends_at' => '10:50',
        ]));

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->delete("/timetable-activities/{$foreign->ulid}")
            ->assertNotFound();

        $this->assertDatabaseCount('timetable_activities', 1);

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable?week=2026-10-12')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('activities', 0)->etc());
    }

    #[Test]
    public function impersonation_refuses_every_write(): void
    {
        $activity = $this->activity(['title' => 'Minha']);
        $session = [...$this->tenantSession(), 'impersonator_id' => 999];

        $this->actingAs($this->teacher)->withSession($session)
            ->post('/timetable-activities', $this->payload())->assertForbidden();
        $this->actingAs($this->teacher)->withSession($session)
            ->put("/timetable-activities/{$activity->ulid}", $this->payload(['title' => 'X']))->assertForbidden();
        $this->actingAs($this->teacher)->withSession($session)
            ->delete("/timetable-activities/{$activity->ulid}")->assertForbidden();

        $this->assertDatabaseCount('timetable_activities', 1);
        $this->assertSame('Minha', $activity->refresh()->title);
    }

    #[Test]
    public function without_the_lessons_module_nothing_can_be_written(): void
    {
        $base = User::factory()->create();

        $this->actingAs($base)
            ->withSession(['organization_id' => $base->personalOrganization()->id])
            ->post('/timetable-activities', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('timetable_activities', 0);
    }

    // ---------------------------------------------------------- the timetable

    #[Test]
    public function the_timetable_shows_an_activity_only_in_the_weeks_where_it_is_in_force(): void
    {
        $this->activity([
            'title' => 'DT 6.º D',
            'kind' => 'class_direction',
            'starts_on' => '2026-10-19',
            'ends_on' => '2026-10-27',
        ]);

        // Semana de 12/10: a terça é 13/10, antes da vigência.
        $this->timetable('2026-10-12')->assertInertia(fn (AssertableInertia $page) => $page->has('activities', 0)->etc());

        // Semana de 19/10: a terça é 20/10.
        $this->timetable('2026-10-19')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('activities', 1)
            ->where('activities.0.title', 'DT 6.º D')
            ->where('activities.0.kind', 'class_direction')
            ->where('activities.0.kind_label', 'Direção de turma')
            ->where('activities.0.starts_at', '10:30')
            ->where('activities.0.ends_at', '11:20')
            ->etc());

        // Semana de 26/10: a terça é 27/10, o último dia (inclusivo).
        $this->timetable('2026-10-26')->assertInertia(fn (AssertableInertia $page) => $page->has('activities', 1)->etc());

        // Semana de 2/11: já depois.
        $this->timetable('2026-11-02')->assertInertia(fn (AssertableInertia $page) => $page->has('activities', 0)->etc());
    }

    #[Test]
    public function the_timetable_offers_the_kinds_with_their_portuguese_labels(): void
    {
        $this->timetable('2026-10-12')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('activityKinds', 5)
            ->where('activityKinds.0', ['value' => 'class_direction', 'label' => 'Direção de turma'])
            ->where('activityKinds.1', ['value' => 'student_support', 'label' => 'Apoio a alunos'])
            ->where('activityKinds.2', ['value' => 'meeting', 'label' => 'Reunião'])
            ->where('activityKinds.3', ['value' => 'coordination', 'label' => 'Coordenação / cargo'])
            ->where('activityKinds.4', ['value' => 'other', 'label' => 'Outra']));
    }

    // ------------------------------------------------ the central guarantee

    #[Test]
    public function activities_never_create_a_lesson_never_number_one_and_are_invisible_to_aulas_de_hoje(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '6.º D');

        // Só atividades: nenhuma aula-horário existe para a turma.
        foreach ([[2, '10:30', '11:20'], [2, '11:30', '12:20'], [4, '09:25', '10:15'], [5, '11:30', '12:20']] as [$day, $start, $end]) {
            $this->activity([
                'title' => "A{$day}{$start}",
                'class_id' => $schoolClass->id,
                'day_of_week' => $day,
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
        }

        $from = CarbonImmutable::parse('2026-10-12', self::TIMEZONE);
        $to = CarbonImmutable::parse('2026-10-18', self::TIMEZONE);

        $this->inTenant($this->organization, function () use ($from, $to): void {
            app(MaterializeLessonsForWeek::class)->execute($this->teacher, $this->academicYear, $from, $to);
        });

        // Abrir «Aulas e Sumários» materializa a semana — e mesmo assim nada nasce.
        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/lessons?week=2026-10-12')
            ->assertOk();

        $this->assertDatabaseCount('lessons', 0);

        $this->inTenant($this->organization, function () use ($schoolClass): void {
            $this->assertSame(0, Lesson::query()->count());
            // A numeração não tem nada para numerar.
            $result = app(LessonNumbering::class)->resequence((int) $schoolClass->id);
            $this->assertSame(0, Lesson::query()->count());
            $this->assertIsArray($result);
        });

        // «Aulas de hoje» (o próximo dia com aula) não as vê.
        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->getJson('/lessons/next-day?after=2026-10-14')
            ->assertOk()
            ->assertJson(['date' => null]);
    }

    #[Test]
    public function with_a_real_lesson_slot_beside_the_activities_only_the_slot_produces_lessons(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');

        $this->inTenant($this->organization, fn () => RecurringLessonSlot::create([
            'class_id' => $schoolClass->id,
            'day_of_week' => 3,
            'starts_at' => '08:30',
            'ends_at' => '09:20',
        ]));
        $this->activity(['day_of_week' => 3, 'starts_at' => '09:30', 'ends_at' => '10:20', 'class_id' => $schoolClass->id]);
        $this->activity(['day_of_week' => 4, 'starts_at' => '09:30', 'ends_at' => '10:20']);

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/lessons?week=2026-10-12')
            ->assertOk();

        $this->inTenant($this->organization, function (): void {
            $lessons = Lesson::query()->get();
            $this->assertCount(1, $lessons);
            $this->assertSame('2026-10-14', CarbonImmutable::parse($lessons->first()->starts_at, self::TIMEZONE)->toDateString());
        });

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->getJson('/lessons/next-day?after=2026-10-14')
            ->assertOk()
            // A quarta seguinte (21/10) — nunca a quinta, que só tem atividade.
            ->assertJson(['date' => '2026-10-21']);
    }

    // -------------------------------------------------------------- lifecycle

    #[Test]
    public function deleting_the_referenced_turma_keeps_the_activity_without_a_class(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '6.º D');
        $activity = $this->activity(['title' => 'DT 6.º D', 'class_id' => $schoolClass->id]);

        DB::table('classes')->where('id', $schoolClass->id)->delete();

        $this->assertNull($activity->refresh()->class_id);
        $this->assertSame('DT 6.º D', $activity->title);
    }

    #[Test]
    public function anonymising_a_closed_account_removes_its_activities_and_only_its_own(): void
    {
        $this->activity(['title' => 'Minha']);

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $theirs = $this->inTenant($this->organization, fn (): TimetableActivity => TimetableActivity::create([
            'user_id' => $colleague->id,
            'kind' => 'other',
            'title' => 'Do colega',
            'day_of_week' => 3,
            'starts_at' => '10:00',
            'ends_at' => '10:50',
        ]));

        $removed = app(AnonymiseClosedAccount::class)->execute($this->teacher);

        $this->assertSame(1, $removed['timetable_activities']);
        $this->assertDatabaseCount('timetable_activities', 1);
        $this->assertDatabaseHas('timetable_activities', ['id' => $theirs->id]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'kind' => 'other',
            'title' => 'RTC',
            'class_ulid' => null,
            'day_of_week' => 2,
            'starts_at' => '14:20',
            'ends_at' => '15:10',
            'starts_on' => null,
            'ends_on' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function save(string $method, string $url, array $payload): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->{$method}($url, $payload);
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

    private function timetable(string $week): TestResponse
    {
        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get("/timetable?week={$week}")
            ->assertOk();
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

    private function attachToOrganization(User $user, Organization $organization): void
    {
        $user->organizations()->attach($organization, ['joined_at' => now()]);
    }

    private function schoolClassFor(User $teacher, string $label, ?Organization $organization = null): SchoolClass
    {
        $organization ??= $this->organization;

        return $this->inTenant($organization, function () use ($organization, $teacher, $label): SchoolClass {
            $academicYear = $organization->is($this->organization)
                ? $this->academicYear
                : AcademicYear::factory()->recycle($organization)->create();

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
