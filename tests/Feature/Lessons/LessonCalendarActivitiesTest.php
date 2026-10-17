<?php

namespace Tests\Feature\Lessons;

use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\CalendarEventType;
use App\Models\Lesson;
use App\Models\LessonSequence;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Module;
use App\Models\OrganizationModuleOverride;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Lessons\LessonDayEvents;
use App\Support\Entitlements\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.171.0 — as atividades do calendário nas aulas: a coincidência das HORAS
 * (LessonDayEvents), a pré-visualização da sequência com os acontecimentos que
 * coincidem, e «Incluir no sumário» (que só escreve texto).
 *
 * Quinta-feira, 08/10/2026, das 10:00 às 10:50 é a aula dos casos de horas.
 */
class LessonCalendarActivitiesTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RELÓGIO FIXO antes de criar dados: nunca datas absolutas contra now().
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    #[Test]
    public function only_the_teachers_own_events_of_the_lessons_class_are_listed(): void
    {
        $lesson = $this->tenLesson();
        $otherClass = $this->otherClass();
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($colleague, ['role' => 'co_teacher']));

        $this->event(['title' => 'Da turma'], $this->schoolClass);
        $this->event(['title' => 'De outra turma'], $otherClass);
        $this->event(['title' => 'Do colega'], $this->schoolClass, $colleague);

        $this->assertSame(['Da turma'], array_column($this->dayEventsOf($lesson), 'title'));
    }

    #[Test]
    public function hours_decide_the_coverage_and_a_non_overlapping_event_is_still_listed(): void
    {
        $lesson = $this->tenLesson();

        $this->event(['title' => 'Todo o dia'], $this->schoolClass);
        $this->event(['title' => 'Parte', 'starts_at' => '10:30', 'ends_at' => '11:30'], $this->schoolClass);
        $this->event(['title' => 'Toda', 'starts_at' => '09:00', 'ends_at' => '12:00'], $this->schoolClass);
        $this->event(['title' => 'Depois', 'starts_at' => '14:00', 'ends_at' => '15:00'], $this->schoolClass);
        // Encosta no fim da aula: a sobreposição é ESTRITA.
        $this->event(['title' => 'Encosta', 'starts_at' => '10:50', 'ends_at' => '11:30'], $this->schoolClass);
        // Sem hora de fim conta como um minuto, e este cai dentro da aula.
        $this->event(['title' => 'Sem fim', 'starts_at' => '10:20'], $this->schoolClass);

        $byTitle = collect($this->dayEventsOf($lesson))->keyBy('title');

        $this->assertTrue($byTitle['Todo o dia']['coincides']);
        $this->assertSame('all_day', $byTitle['Todo o dia']['coverage']);
        $this->assertTrue($byTitle['Todo o dia']['all_day']);
        $this->assertSame('partial', $byTitle['Parte']['coverage']);
        $this->assertSame('whole_lesson', $byTitle['Toda']['coverage']);
        $this->assertFalse($byTitle['Depois']['coincides']);
        $this->assertNull($byTitle['Depois']['coverage']);
        $this->assertFalse($byTitle['Encosta']['coincides']);
        $this->assertTrue($byTitle['Sem fim']['coincides']);
        $this->assertSame('partial', $byTitle['Sem fim']['coverage']);
        // Os campos de sempre mantêm-se.
        $this->assertSame('10:30', $byTitle['Parte']['starts_at']);
        $this->assertFalse($byTitle['Parte']['all_day']);
        $this->assertCount(6, $byTitle);
    }

    #[Test]
    public function a_multi_day_event_with_hours_is_a_daily_window(): void
    {
        $lesson = $this->tenLesson();
        $earlier = $this->tenLesson(['starts_at' => '2026-10-08 08:00:00', 'ends_at' => '2026-10-08 08:50:00']);
        $this->event(['title' => 'Semana de testes', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-09', 'starts_at' => '10:30', 'ends_at' => '11:30'], $this->schoolClass);

        $this->assertTrue($this->dayEventsOf($lesson)[0]['coincides']);
        $this->assertFalse($this->dayEventsOf($earlier)[0]['coincides']);
    }

    #[Test]
    public function the_lesson_page_carries_the_coincidence(): void
    {
        $lesson = $this->tenLesson();
        $this->event(['title' => 'Visita', 'starts_at' => '09:00', 'ends_at' => '12:00'], $this->schoolClass);

        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('day_events.0.coincides', true)
                ->where('day_events.0.coverage', 'whole_lesson'));
    }

    #[Test]
    public function coinciding_event_resolution_validates_owner_class_and_hours(): void
    {
        $lesson = $this->tenLesson();
        $own = $this->event(['title' => 'Coincide', 'starts_at' => '10:30', 'ends_at' => '11:30'], $this->schoolClass);
        $apart = $this->event(['title' => 'Não coincide', 'starts_at' => '14:00', 'ends_at' => '15:00'], $this->schoolClass);
        $colleague = User::factory()->create();
        $foreign = $this->event(['title' => 'Alheio'], $this->schoolClass, $colleague);

        $this->inTenant($this->organization, function () use ($lesson, $own, $apart, $foreign): void {
            $service = app(LessonDayEvents::class);

            $this->assertSame($own->getKey(), $service->coincidingEvent($lesson, $this->teacher, $own->ulid)?->getKey());
            $this->assertNull($service->coincidingEvent($lesson, $this->teacher, $apart->ulid));
            $this->assertNull($service->coincidingEvent($lesson, $this->teacher, $foreign->ulid));
            $this->assertNull($service->coincidingEvent($lesson, $this->teacher, 'inexistente'));
        });
    }

    /**
     * Os MESMOS casos de `lessonDayEvents.test.ts`: as duas implementações de
     * «a linha que Incluir no sumário acrescenta» têm de dar o mesmo texto.
     *
     * @param  array{title: string, notes: string|null}  $event
     */
    #[Test]
    #[DataProvider('summaryLineCases')]
    public function the_summary_line_matches_the_client_rule(array $event, string $expected): void
    {
        $this->assertSame($expected, LessonDayEvents::summaryLine($event));
    }

    /**
     * @return array<string, array{array{title: string, notes: string|null}, string}>
     */
    public static function summaryLineCases(): array
    {
        return [
            'só o título sem notas' => [['title' => 'Reunião de departamento', 'notes' => null], 'Reunião de departamento'],
            'notas vazias' => [['title' => 'Reunião de departamento', 'notes' => ''], 'Reunião de departamento'],
            'título e notas' => [['title' => 'Visita a Évora', 'notes' => 'Levar o material de campo.'], 'Visita a Évora — Levar o material de campo.'],
            'quebras nas notas' => [['title' => 'Visita a Évora', 'notes' => "Linha um.\nLinha dois.\n\nLinha três."], 'Visita a Évora — Linha um. Linha dois. Linha três.'],
            'quebras no título' => [['title' => "Visita\nde estudo", 'notes' => null], 'Visita de estudo'],
            'espaços à volta' => [['title' => '  Teste  ', 'notes' => "  Sala 3 \n"], 'Teste — Sala 3'],
        ];
    }

    #[Test]
    public function the_summary_line_accepts_the_model(): void
    {
        $event = $this->event(['title' => 'Visita', 'description' => "Autocarro\nàs 9h"], $this->schoolClass);

        $this->assertSame('Visita — Autocarro às 9h', LessonDayEvents::summaryLine($event));
    }

    #[Test]
    public function the_sequence_preview_lists_only_the_coinciding_events_of_each_lesson(): void
    {
        $first = $this->tenLesson();
        $second = $this->tenLesson(['starts_at' => '2026-10-15 10:00:00', 'ends_at' => '2026-10-15 10:50:00']);
        $this->event(['title' => 'Coincide', 'ends_on' => '2026-10-15', 'starts_at' => '10:30', 'ends_at' => '11:30'], $this->schoolClass);
        $this->event(['title' => 'Só a segunda', 'starts_on' => '2026-10-15', 'ends_on' => '2026-10-15'], $this->schoolClass);
        $this->event(['title' => 'Não coincide', 'starts_at' => '14:00', 'ends_at' => '15:00'], $this->schoolClass);
        $this->event(['title' => 'De outra turma'], $this->otherClass());
        $sequence = $this->sequence(['Um', 'Dois']);

        $response = $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $this->payload())->assertOk();

        $this->assertSame($first->ulid, $response->json('steps.0.lesson.ulid'));
        $this->assertSame(['Coincide'], array_column($response->json('steps.0.calendar_events'), 'title'));
        $this->assertSame('partial', $response->json('steps.0.calendar_events.0.coverage'));
        $this->assertSame($second->ulid, $response->json('steps.1.lesson.ulid'));
        // A ordem de sempre: «todo o dia» primeiro.
        $this->assertSame(['Só a segunda', 'Coincide'], array_column($response->json('steps.1.calendar_events'), 'title'));
        $this->assertSame(2, $response->json('coinciding_lessons'));
        // Só o necessário — nunca as notas.
        $this->assertSame(['ulid', 'title', 'type_label', 'all_day', 'starts_at', 'ends_at', 'coverage'], array_keys($response->json('steps.0.calendar_events.0')));
    }

    #[Test]
    public function the_sequence_preview_has_no_events_without_calendar_access_and_the_token_ignores_them(): void
    {
        $this->tenLesson();
        $sequence = $this->sequence(['Um']);
        $withoutEvents = $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $this->payload())->assertOk()->json('plan_token');

        $this->event(['title' => 'Coincide'], $this->schoolClass);
        $withEvents = $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $this->payload())->assertOk();
        $this->assertSame($withoutEvents, $withEvents->json('plan_token'));
        $this->assertSame(1, $withEvents->json('coinciding_lessons'));

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'module_id' => Module::where('key', 'calendar')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Teste de entitlement.',
        ]);
        app(Entitlements::class)->flush();

        $locked = $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $this->payload())->assertOk();
        $this->assertSame([], $locked->json('steps.0.calendar_events'));
        $this->assertSame(0, $locked->json('coinciding_lessons'));
    }

    #[Test]
    public function the_sequence_preview_writes_nothing(): void
    {
        $lesson = $this->tenLesson();
        $this->event(['title' => 'Coincide'], $this->schoolClass);
        $sequence = $this->sequence(['Um']);
        $version = $this->summaryVersion($lesson);

        $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $this->payload())->assertOk();

        $this->assertSame($version, $this->summaryVersion($lesson));
        $this->assertSame(0, LessonSummary::withoutGlobalScopes()->count());
        $this->assertSame(1, Lesson::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditEvent::query()->where('event', 'like', 'lesson%')->count());
    }

    #[Test]
    public function including_the_activity_in_the_summary_keeps_the_plan_state_number_and_provenance(): void
    {
        $lesson = $this->tenLesson();
        $event = $this->event(['title' => 'Visita', 'description' => 'Autocarro às 9h'], $this->schoolClass);
        $sequence = $this->sequence(['Plano do elemento 1']);
        $token = $this->asTeacher()->postJson("/lessons/sequences/{$sequence->ulid}/preview", $this->payload())->json('plan_token');
        $this->asTeacher()->post("/lessons/sequences/{$sequence->ulid}/apply", $this->payload() + ['plan_token' => $token])->assertSessionHasNoErrors();
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($lesson->getKey())->update(['lesson_number' => 4]));
        $before = $this->inTenant($this->organization, fn () => $lesson->summary()->sole());

        // O que o ecrã envia: o plano inteiro MAIS a linha que o botão acrescenta.
        $content = $before->content."\n".LessonDayEvents::summaryLine($event);
        $this->asTeacher()->patch("/lessons/{$lesson->ulid}/summary/content", [
            'content' => $content,
            'summary_version' => $this->summaryVersion($lesson),
        ])->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($lesson, $before, $content): void {
            $lesson->refresh();
            $summary = $lesson->summary()->sole();

            $this->assertSame($content, $summary->content);
            $this->assertStringContainsString('Plano do elemento 1', $summary->content);
            $this->assertSame(LessonStatus::Prepared, $lesson->status);
            $this->assertNull($lesson->outcome);
            $this->assertSame(4, $lesson->lesson_number);
            $this->assertNotNull($before->lesson_sequence_item_id);
            $this->assertSame($before->lesson_sequence_id, $summary->lesson_sequence_id);
            $this->assertSame($before->lesson_sequence_item_id, $summary->lesson_sequence_item_id);
        });

        // REABERTA, a aula mostra o plano e a atividade incluída, por preparar
        // e com a atividade ainda à vista para o professor decidir.
        $this->asTeacher()->get("/lessons/{$lesson->ulid}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lesson.summary.content', $content)
                ->where('lesson.status', 'prepared')
                ->where('lesson.outcome', null)
                ->where('lesson.lesson_number', 4)
                ->where('lesson.can_record_outcome', true)
                ->where('day_events.0.title', 'Visita'));
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tenLesson(array $attributes = []): Lesson
    {
        return $this->makeLesson($attributes + ['origin' => 'manual', 'starts_at' => '2026-10-08 10:00:00', 'ends_at' => '2026-10-08 10:50:00']);
    }

    private function otherClass(): SchoolClass
    {
        return $this->inTenant($this->organization, function (): SchoolClass {
            $schoolClass = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $this->schoolClass->academic_year_id,
            ]);
            $schoolClass->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dayEventsOf(Lesson $lesson): array
    {
        return $this->inTenant($this->organization, fn (): array => app(LessonDayEvents::class)->for($lesson, $this->teacher));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes, ?SchoolClass $schoolClass, ?User $owner = null): CalendarEvent
    {
        return $this->inTenant($this->organization, function () use ($attributes, $schoolClass, $owner): CalendarEvent {
            $event = CalendarEvent::create(array_merge([
                'user_id' => ($owner ?? $this->teacher)->getKey(),
                'type' => CalendarEventType::Meeting,
                'title' => 'Um acontecimento',
                'starts_on' => '2026-10-08',
                'ends_on' => '2026-10-08',
            ], $attributes));

            if ($schoolClass !== null) {
                $event->schoolClasses()->sync([$schoolClass->getKey()]);
            }

            return $event;
        });
    }

    /**
     * @param  list<string>  $summaries
     */
    private function sequence(array $summaries): LessonSequence
    {
        return $this->inTenant($this->organization, function () use ($summaries): LessonSequence {
            $sequence = LessonSequence::create([
                'user_id' => $this->teacher->id,
                'subject_id' => $this->schoolClass->subject_id,
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'grade_level' => null,
                'name' => 'Sequência de teste',
            ]);

            foreach ($summaries as $index => $summary) {
                $sequence->items()->create(['position' => $index + 1, 'summary' => $summary]);
            }

            return $sequence;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return ['class_id' => $this->schoolClass->id, 'from' => '2026-10-01', 'summary' => true];
    }
}
