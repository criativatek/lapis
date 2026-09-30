<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CalendarEventType;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Acontecimentos do dia» na página de uma aula (lessons.show) — os
 * acontecimentos do Calendário do Ano Letivo que cobrem o DIA LOCAL da aula,
 * da MESMA turma, e só os do próprio professor (CalendarEventPolicy: um
 * acontecimento é pessoal e nunca partilhado entre colegas de turma).
 *
 * NÃO É SOBREPOSIÇÃO DE HORAS: um acontecimento «todo o dia», ou cuja janela
 * não toca a da aula, conta na mesma desde que o DIA — no fuso da
 * organização — esteja dentro de `starts_on`..`ends_on`.
 */
class LessonDayEventsTest extends TestCase
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

        $this->academicYear = $this->inTenant($this->organization, fn (): AcademicYear => AcademicYear::factory()
            ->recycle($this->organization)
            ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-07-31']));
    }

    /**
     * O caso de aceitação: o acontecimento de 7.º A · Português, a 02/10,
     * das 14:10 às 15:00, aparece na lição 11 desse dia, das 15:10 às 16:00 —
     * as duas janelas NÃO se sobrepõem, e é precisamente isso que se prova.
     */
    #[Test]
    public function the_lesson_page_shows_the_days_own_event_even_when_the_times_do_not_overlap(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $this->eventFor($this->teacher, $schoolClass, [
            'title' => 'Reunião de departamento',
            'starts_on' => '2026-10-02',
            'ends_on' => '2026-10-02',
            'starts_at' => '14:10',
            'ends_at' => '15:00',
        ]);
        $lesson = $this->lessonFor($schoolClass, [
            // `config('app.timezone')` desta aplicação É Europe/Lisbon (§24.4
            // em config/app.php) — `starts_at` fica gravado como a própria
            // hora local, e não convertido para UTC. É por isso que este
            // valor, e não um deslocado, é a lição das 15:10 de Lisboa.
            'starts_at' => '2026-10-02 15:10:00',
            'ends_at' => '2026-10-02 16:00:00',
            'lesson_number' => 11,
        ]);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lesson.lesson_number', 11)
                ->where('lesson.school_class.label', '7.º A')
                ->where('lesson.school_class.subject', 'Português')
                ->has('day_events', 1)
                ->where('day_events.0.title', 'Reunião de departamento')
                ->where('day_events.0.starts_at', '14:10')
                ->where('day_events.0.ends_at', '15:00')
                ->where('day_events.0.all_day', false));
    }

    #[Test]
    public function an_event_of_the_previous_or_next_day_is_not_included(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $this->eventFor($this->teacher, $schoolClass, ['title' => 'Véspera', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-01']);
        $this->eventFor($this->teacher, $schoolClass, ['title' => 'Seguinte', 'starts_on' => '2026-10-03', 'ends_on' => '2026-10-03']);
        $lesson = $this->lessonFor($schoolClass, ['starts_at' => '2026-10-02 14:10:00']);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('day_events', 0));
    }

    #[Test]
    public function a_multi_day_event_covering_the_day_is_included(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $this->eventFor($this->teacher, $schoolClass, [
            'title' => 'Visita a Évora',
            'starts_on' => '2026-09-30',
            'ends_on' => '2026-10-05',
        ]);
        $lesson = $this->lessonFor($schoolClass, ['starts_at' => '2026-10-02 14:10:00']);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('day_events', 1)
                ->where('day_events.0.title', 'Visita a Évora'));
    }

    #[Test]
    public function an_all_day_event_is_included_and_marked_all_day(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $this->eventFor($this->teacher, $schoolClass, [
            'title' => 'Data relevante',
            'starts_on' => '2026-10-02',
            'ends_on' => '2026-10-02',
            'starts_at' => null,
            'ends_at' => null,
        ]);
        $lesson = $this->lessonFor($schoolClass, ['starts_at' => '2026-10-02 14:10:00']);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('day_events', 1)
                ->where('day_events.0.all_day', true)
                ->where('day_events.0.starts_at', null)
                ->where('day_events.0.ends_at', null));
    }

    /**
     * A FRONTEIRA DO DIA LOCAL É A DA ORGANIZAÇÃO, E NÃO A DE LISBOA POR
     * OMISSÃO. `config('app.timezone')` desta aplicação já É Europe/Lisbon
     * (§24.4), e `Lesson::starts_at` fica gravado como a própria hora local
     * — não há UTC nenhum a converter aqui. O que este teste prova é outra
     * coisa: que `LessonDayEvents` lê o dia pelo FUSO DA ORGANIZAÇÃO
     * (`ClassArchivalWindow::timezoneFor`, a mesma fonte que `WeeklyLessonsQuery`
     * já usa), e não por um Europe/Lisbon escrito uma segunda vez algures.
     *
     * Esta organização está configurada em UTC: a mesma aula às 00:30
     * (hora local da aplicação, Lisboa) é, em UTC, ainda 23:30 do dia
     * anterior — e é esse dia anterior, e não o de Lisboa, que a turma desta
     * organização deve ver.
     */
    #[Test]
    public function the_local_day_boundary_follows_the_organizations_own_timezone(): void
    {
        $this->organization->update(['timezone' => 'UTC']);

        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $this->eventFor($this->teacher, $schoolClass, ['title' => 'Do dia 2', 'starts_on' => '2026-10-02', 'ends_on' => '2026-10-02']);
        $this->eventFor($this->teacher, $schoolClass, ['title' => 'Do dia 1', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-01']);

        // 00:30 de 2026-10-02, hora local da aplicação (Lisboa, WEST) — em
        // UTC, o fuso desta organização, ainda são 23:30 de 2026-10-01.
        $lesson = $this->lessonFor($schoolClass, ['starts_at' => '2026-10-02 00:30:00']);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('day_events', 1)
                ->where('day_events.0.title', 'Do dia 1'));
    }

    /**
     * Uma ordem estável e não a de inserção: «todo o dia» primeiro, depois
     * por hora de início, e por título quando a hora empata.
     */
    #[Test]
    public function events_are_ordered_all_day_first_then_by_start_time_then_by_title(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $this->eventFor($this->teacher, $schoolClass, ['title' => 'Tarde', 'starts_at' => '16:00', 'ends_at' => '17:00']);
        $this->eventFor($this->teacher, $schoolClass, ['title' => 'Manhã B', 'starts_at' => '09:00', 'ends_at' => '10:00']);
        $this->eventFor($this->teacher, $schoolClass, ['title' => 'Manhã A', 'starts_at' => '09:00', 'ends_at' => '09:30']);
        $this->eventFor($this->teacher, $schoolClass, ['title' => 'Todo o dia', 'starts_at' => null, 'ends_at' => null]);
        $lesson = $this->lessonFor($schoolClass, ['starts_at' => '2026-10-02 15:10:00']);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('day_events', 4)
                ->where('day_events.0.title', 'Todo o dia')
                ->where('day_events.1.title', 'Manhã A')
                ->where('day_events.2.title', 'Manhã B')
                ->where('day_events.3.title', 'Tarde'));
    }

    #[Test]
    public function an_event_of_another_turma_the_same_day_is_not_included(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $otherClass = $this->schoolClassFor($this->teacher, '7.º B', 'Português');
        $this->eventFor($this->teacher, $otherClass, ['title' => 'Da outra turma', 'starts_on' => '2026-10-02', 'ends_on' => '2026-10-02']);
        $lesson = $this->lessonFor($schoolClass, ['starts_at' => '2026-10-02 14:10:00']);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('day_events', 0));
    }

    #[Test]
    public function an_event_with_no_turma_attached_is_not_included(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $this->eventFor($this->teacher, null, ['title' => 'Sem turma', 'starts_on' => '2026-10-02', 'ends_on' => '2026-10-02']);
        $lesson = $this->lessonFor($schoolClass, ['starts_at' => '2026-10-02 14:10:00']);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('day_events', 0));
    }

    #[Test]
    public function a_colleagues_own_event_on_the_same_turma_and_day_is_not_shown(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $this->inTenant($this->organization, function () use ($schoolClass, $colleague): void {
            $schoolClass->teachers()->attach($colleague, ['role' => 'co_teacher']);
        });
        $this->eventFor($colleague, $schoolClass, ['title' => 'Do colega', 'starts_on' => '2026-10-02', 'ends_on' => '2026-10-02']);

        $lesson = $this->lessonFor($schoolClass, ['starts_at' => '2026-10-02 14:10:00']);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('day_events', 0));
    }

    #[Test]
    public function a_lesson_from_another_organization_is_not_resolved(): void
    {
        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrganization);

        $foreign = $this->inTenant($strangerOrganization, function () use ($strangerOrganization, $stranger): Lesson {
            $academicYear = AcademicYear::factory()->recycle($strangerOrganization)
                ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-07-31']);
            $subject = Subject::query()->firstOrCreate(['code' => 'MAT'], ['name' => 'Matemática']);
            $schoolClass = SchoolClass::factory()->recycle($strangerOrganization)->create([
                'label' => 'Turma de outra organização',
                'academic_year_id' => $academicYear->getKey(),
                'subject_id' => $subject->getKey(),
            ]);
            $schoolClass->teachers()->attach($stranger, ['role' => 'owner']);
            $this->eventFor($stranger, $schoolClass, [
                'title' => 'Do outro colégio',
                'starts_on' => '2026-10-02',
                'ends_on' => '2026-10-02',
            ]);

            return Lesson::create([
                'class_id' => $schoolClass->id,
                'starts_at' => '2026-10-02 14:10:00',
                'ends_at' => null,
                'status' => LessonStatus::Preparation,
                'created_by' => $stranger->id,
            ]);
        });

        $this->asTeacher()->get("/lessons/{$foreign->ulid}")->assertNotFound();
    }

    #[Test]
    public function without_calendar_entitlement_day_events_is_empty(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $this->eventFor($this->teacher, $schoolClass, ['title' => 'Reunião', 'starts_on' => '2026-10-02', 'ends_on' => '2026-10-02']);
        $lesson = $this->lessonFor($schoolClass, ['starts_at' => '2026-10-02 14:10:00']);

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'module_id' => Module::where('key', 'calendar')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de entitlement.',
        ]);
        app(Entitlements::class)->flush();

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('day_events', 0));
    }

    #[Test]
    public function viewing_the_lesson_creates_or_changes_nothing(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º A', 'Português');
        $lesson = $this->lessonFor($schoolClass, ['starts_at' => '2026-10-02 14:10:00']);
        $this->inTenant($this->organization, function () use ($lesson): void {
            LessonSummary::create(['lesson_id' => $lesson->id, 'content' => 'Sumário já existente.']);
        });

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk();

        $this->assertDatabaseCount('lesson_summaries', 1);
        $this->assertDatabaseCount('lessons', 1);
        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame('Sumário já existente.', $lesson->summary()->sole()->content);
        });
    }

    // ------------------------------------------------------------- helpers

    private function asTeacher(): self
    {
        return $this->actingAs($this->teacher)->withSession([
            'organization_id' => $this->organization->id,
            'academic_year_id' => $this->academicYear->id,
        ]);
    }

    private function schoolClassFor(User $teacher, string $label, string $subjectName): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($teacher, $label, $subjectName): SchoolClass {
            $subject = Subject::query()->firstOrCreate(['code' => strtoupper(substr($subjectName, 0, 3))], ['name' => $subjectName]);

            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create([
                'label' => $label,
                'academic_year_id' => $this->academicYear->getKey(),
                'subject_id' => $subject->getKey(),
            ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lessonFor(SchoolClass $schoolClass, array $attributes = []): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::create(array_merge([
            'class_id' => $schoolClass->id,
            'starts_at' => '2026-10-02 14:10:00',
            'ends_at' => null,
            'status' => LessonStatus::Preparation,
            'created_by' => $this->teacher->id,
        ], $attributes)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eventFor(User $owner, ?SchoolClass $schoolClass, array $attributes = []): CalendarEvent
    {
        return $this->inTenant($this->organization, function () use ($owner, $schoolClass, $attributes): CalendarEvent {
            $event = CalendarEvent::create(array_merge([
                'user_id' => $owner->getKey(),
                'type' => CalendarEventType::Meeting,
                'title' => 'Um acontecimento',
                'starts_on' => '2026-10-02',
                'ends_on' => '2026-10-02',
            ], $attributes));

            if ($schoolClass !== null) {
                $event->schoolClasses()->sync([$schoolClass->getKey()]);
            }

            return $event;
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
