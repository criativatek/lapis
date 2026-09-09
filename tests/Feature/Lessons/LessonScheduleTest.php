<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Http\Controllers\LessonScheduleController;
use App\Models\AcademicCalendarException;
use App\Models\AcademicCalendarExceptionType;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LessonScheduleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O RELÓGIO DESTE FICHEIRO. Nenhum caso aqui dentro lê a data real.
     *
     * Os casos são de duas famílias que só coexistem se o "hoje" for uma
     * decisão e não um acidente: uns fixam a semana que materializam
     * (2026-09-07 a 2026-09-13) e o ano letivo em que ela cai (2026-09-01 a
     * 2027-06-30); outros precisam de um `starts_on` que seja mesmo "hoje",
     * ou de um `effective_from` a tantos dias de distância, porque é isso
     * que a validação de versionamento compara.
     *
     * Com o relógio real as duas famílias contradizem-se assim que a data de
     * execução ultrapassa a semana fixa: o `starts_on` de `slotPayload()` —
     * "hoje" — passa para depois de segunda-feira 2026-09-07 e a ocorrência
     * dessa segunda deixa de nascer. Foi o que aconteceu a 2026-09-08, com
     * três casos a falhar sem nenhuma alteração de código.
     *
     * 2026-09-01 (terça) é o primeiro dia do ano letivo fixo — logo "hoje"
     * cai dentro dele — e fica antes da semana fixa, pelo que as duas
     * ocorrências dessa semana são posteriores ao `starts_on` do slot.
     */
    private const FROZEN_NOW = '2026-09-01 12:00:00';

    private const TIMEZONE = 'Europe/Lisbon';

    private Organization $organization;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // Depois do parent::setUp() — é ele que arranca a aplicação — e antes
        // de qualquer factory, para que nada neste caso (dados, validação ou
        // materialização) veja outra data que não esta. O relógio é reposto
        // no tearDown por InteractsWithTestCaseLifecycle.
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::FROZEN_NOW, self::TIMEZONE));

        Route::middleware(['web', 'auth', 'organization'])->group(function (): void {
            Route::post('/_test/lesson-slots', [LessonScheduleController::class, 'store']);
            Route::put('/_test/lesson-slots/{recurringLessonSlot}', [LessonScheduleController::class, 'update']);
            Route::delete('/_test/lesson-slots/{recurringLessonSlot}', [LessonScheduleController::class, 'destroy']);
            Route::post('/_test/classes/{class}/lessons/materialize', [LessonScheduleController::class, 'materialize']);
        });

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);
    }

    #[Test]
    public function an_assigned_teacher_can_create_and_update_a_slot_for_their_class(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->post('/_test/lesson-slots', $this->slotPayload($schoolClass))
            ->assertRedirect();

        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::query()->sole());

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 3,
                'starts_at' => '10:00',
                'ends_at' => '10:50',
            ]))
            ->assertRedirect();

        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());

        $this->assertSame($schoolClass->id, $slot->class_id);
        $this->assertSame(3, $slot->day_of_week);
        $this->assertStringStartsWith('10:00', $slot->starts_at);
        $this->assertStringStartsWith('10:50', $slot->ends_at);
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $unassignedTeacher = User::factory()->create();
        $this->organization->members()->attach($unassignedTeacher, ['joined_at' => now()]);

        $this->actingAs($unassignedTeacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->post('/_test/lesson-slots', $this->slotPayload($schoolClass))
            ->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function a_class_from_another_organization_is_forbidden(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $otherClass = $this->schoolClassFor($otherTeacher, $otherOrganization);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->post('/_test/lesson-slots', $this->slotPayload($otherClass))
            ->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function invalid_weekdays_and_non_increasing_times_are_rejected(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);

        foreach ([0, 8] as $invalidWeekday) {
            $this->actingAs($this->teacher)
                ->withSession(['organization_id' => $this->organization->id])
                ->post('/_test/lesson-slots', $this->slotPayload($schoolClass, ['day_of_week' => $invalidWeekday]))
                ->assertSessionHasErrors('day_of_week');
        }

        foreach (['09:30', '09:00'] as $invalidEndTime) {
            $this->actingAs($this->teacher)
                ->withSession(['organization_id' => $this->organization->id])
                ->post('/_test/lesson-slots', $this->slotPayload($schoolClass, ['ends_at' => $invalidEndTime]))
                ->assertSessionHasErrors('ends_at');
        }

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->post('/_test/lesson-slots', $this->slotPayload($schoolClass, [
                'starts_on' => '2026-10-01',
                'ends_on' => '2026-09-30',
            ]))
            ->assertSessionHasErrors('ends_on');

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function materialization_refuses_a_range_outside_the_class_academic_year(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);

        $this->expectException(ValidationException::class);

        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse('2026-08-31'),
            CarbonImmutable::parse('2026-09-07'),
            $this->teacher,
        ));
    }

    #[Test]
    public function materialization_is_idempotent_for_multiple_slots(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);

        $this->inTenant($this->organization, function () use ($schoolClass): void {
            RecurringLessonSlot::create($this->slotAttributes($schoolClass, ['day_of_week' => 1]));
            RecurringLessonSlot::create($this->slotAttributes($schoolClass, [
                'day_of_week' => 3,
                'starts_at' => '14:00',
                'ends_at' => '14:50',
            ]));

            $action = app(MaterializeLessonsForRange::class);
            $from = CarbonImmutable::parse('2026-09-07');
            $to = CarbonImmutable::parse('2026-09-13');

            $this->assertCount(2, $action->execute($schoolClass, $from, $to, $this->teacher));
            $this->assertCount(2, $action->execute($schoolClass, $from, $to, $this->teacher));

            $this->assertSame(2, Lesson::query()->count());
            $this->assertSame(2, Lesson::query()->where('status', LessonStatus::Preparation)->count());
            $this->assertSame(
                ['2026-09-07 09:30:00', '2026-09-09 14:00:00'],
                Lesson::query()->orderBy('starts_at')->get()->map(
                    fn (Lesson $lesson): string => $lesson->starts_at->format('Y-m-d H:i:s'),
                )->all(),
            );
        });
    }

    /**
     * Reversão deliberada do princípio anterior ("ler nunca materializa"):
     * abrir a semana passa a criar as aulas dessa semana, sem passo manual.
     * A escrita continua estritamente limitada à semana pedida e é
     * idempotente — ler a mesma semana duas vezes não duplica nada.
     */
    #[Test]
    public function reading_the_week_materializes_only_that_weeks_lessons(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::create($this->slotAttributes($schoolClass)));
        $academicYearId = $this->inTenant(
            $this->organization,
            fn (): int => AcademicYear::query()->sole()->id,
        );
        $session = [
            'organization_id' => $this->organization->id,
            'academic_year_id' => $academicYearId,
        ];

        $this->actingAs($this->teacher)->withSession($session)
            ->get('/lessons?week=2026-09-07')
            ->assertOk();

        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseHas('lessons', [
            'class_id' => $schoolClass->id,
            'starts_at' => '2026-09-07 09:30:00',
        ]);

        $this->actingAs($this->teacher)->withSession($session)
            ->get('/lessons?week=2026-09-07')
            ->assertOk();

        $this->assertDatabaseCount('lessons', 1);
    }

    #[Test]
    public function reading_one_week_never_materializes_an_adjacent_week(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::create($this->slotAttributes($schoolClass)));
        $academicYearId = $this->inTenant(
            $this->organization,
            fn (): int => AcademicYear::query()->sole()->id,
        );

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id, 'academic_year_id' => $academicYearId])
            ->get('/lessons?week=2026-09-07')
            ->assertOk();

        $this->assertDatabaseCount('lessons', 1);
        $this->assertDatabaseMissing('lessons', ['starts_at' => '2026-09-14 09:30:00']);
        $this->assertDatabaseMissing('lessons', ['starts_at' => '2026-08-31 09:30:00']);
    }

    #[Test]
    public function reading_the_week_during_impersonation_never_materializes_lessons(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::create($this->slotAttributes($schoolClass)));
        $academicYearId = $this->inTenant(
            $this->organization,
            fn (): int => AcademicYear::query()->sole()->id,
        );

        $this->actingAs($this->teacher)->withSession([
            'organization_id' => $this->organization->id,
            'academic_year_id' => $academicYearId,
            'impersonator_id' => 999,
        ])->get('/lessons?week=2026-09-07')->assertOk();

        $this->assertDatabaseCount('lessons', 0);
    }

    #[Test]
    public function impersonation_blocks_every_schedule_mutation(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant(
            $this->organization,
            fn (): RecurringLessonSlot => RecurringLessonSlot::create($this->slotAttributes($schoolClass)),
        );
        $session = ['organization_id' => $this->organization->id, 'impersonator_id' => 999];

        $this->actingAs($this->teacher)->withSession($session)
            ->post('/_test/lesson-slots', $this->slotPayload($schoolClass))
            ->assertForbidden();
        $this->actingAs($this->teacher)->withSession($session)
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, ['day_of_week' => 2]))
            ->assertForbidden();
        $this->actingAs($this->teacher)->withSession($session)
            ->delete("/_test/lesson-slots/{$slot->ulid}")
            ->assertForbidden();
        $this->actingAs($this->teacher)->withSession($session)
            ->post("/_test/classes/{$schoolClass->ulid}/lessons/materialize", [
                'from' => '2026-09-01',
                'to' => '2026-09-30',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $this->assertDatabaseCount('lessons', 0);
    }

    // ------------------------------------------------- effective-dating

    #[Test]
    public function editing_a_slot_before_it_starts_is_a_plain_in_place_update(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => $this->inDays(30), 'ends_on' => null]),
        ));

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 3,
                'starts_at' => '10:00',
                'ends_at' => '10:50',
                'starts_on' => $this->inDays(30),
                'ends_on' => null,
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);

        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());
        $this->assertSame(3, $slot->day_of_week);
        $this->assertStringStartsWith('10:00', $slot->starts_at);
        $this->assertStringStartsWith('10:50', $slot->ends_at);
    }

    #[Test]
    public function editing_an_already_in_vigor_slot_closes_it_and_creates_a_new_version(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => null, 'ends_on' => null]),
        ));
        $this->lessonForSlot($slot, LessonStatus::Taught);
        $effectiveFrom = $this->inDays(10);
        $newEndsOn = $this->inDays(300);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 3,
                'starts_at' => '10:00',
                'ends_at' => '10:50',
                'starts_on' => null,
                'ends_on' => $newEndsOn,
                'effective_from' => $effectiveFrom,
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 2);

        [$old, $new] = $this->inTenant($this->organization, fn (): array => [
            $slot->refresh(),
            RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole(),
        ]);

        // The OLD row keeps its own pattern completely untouched — only
        // ends_on moves, closing it the day before the new version starts.
        $this->assertSame(1, $old->day_of_week);
        $this->assertStringStartsWith('09:30', $old->starts_at);
        $this->assertStringStartsWith('10:20', $old->ends_at);
        $this->assertNull($old->starts_on);
        $this->assertSame(
            CarbonImmutable::parse($effectiveFrom)->subDay()->toDateString(),
            $old->ends_on->toDateString(),
        );

        // The NEW row carries the submitted pattern, starting exactly on
        // effective_from and bounded by whatever ends_on was submitted.
        $this->assertSame(3, $new->day_of_week);
        $this->assertStringStartsWith('10:00', $new->starts_at);
        $this->assertStringStartsWith('10:50', $new->ends_at);
        $this->assertSame($effectiveFrom, $new->starts_on->toDateString());
        $this->assertSame($newEndsOn, $new->ends_on->toDateString());
    }

    /**
     * (a) do enunciado de integridade: o limite entre a versão antiga e a
     * nova é CONTÍGUO — o último dia da antiga é exatamente a véspera do
     * primeiro dia da nova — e não deixa nem sobrepõe nem abre um buraco por
     * omissão.
     */
    #[Test]
    public function revising_a_slot_produces_a_contiguous_boundary_between_old_and_new(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => null, 'ends_on' => null]),
        ));
        $this->lessonForSlot($slot, LessonStatus::Taught);
        $effectiveFrom = $this->inDays(5);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'starts_on' => null,
                'ends_on' => null,
                'effective_from' => $effectiveFrom,
            ]))
            ->assertRedirect();

        [$old, $new] = $this->inTenant($this->organization, fn (): array => [
            $slot->refresh(),
            RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole(),
        ]);

        $this->assertSame(
            $new->starts_on->toDateString(),
            $old->ends_on->addDay()->toDateString(),
        );
    }

    /**
     * (b) O caso-limite agora RESOLVIDO: um slot cujo próprio starts_on é
     * HOJE mas que ainda não produziu nenhuma Lesson não tem histórico
     * nenhum a proteger — comporta-se exatamente como um slot futuro, e por
     * isso é editado no próprio lugar, sem exigir effective_from.
     */
    #[Test]
    public function editing_a_today_starting_slot_with_no_lessons_yet_is_a_plain_in_place_update(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $today = $this->today();
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => $today, 'ends_on' => null]),
        ));

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 3,
                'starts_at' => '10:00',
                'ends_at' => '10:50',
                'starts_on' => $today,
                'ends_on' => null,
                // Deliberadamente SEM effective_from — a prova de que este
                // caso não o exige.
            ]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());
        $this->assertSame(3, $slot->day_of_week);
        $this->assertStringStartsWith('10:00', $slot->starts_at);
        $this->assertNull($slot->ends_on);
    }

    #[Test]
    public function a_materialized_preparation_lesson_without_records_does_not_require_versioning(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->slotWithLesson($schoolClass);
        $past = $this->inDays(-1);
        $group = $this->groupFor($schoolClass);
        $slot->update(['starts_on' => $past]);

        $this->putSlot($slot, $schoolClass, [
            'starts_on' => $past,
            'class_group_id' => $group->id,
        ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());
        $this->assertSame($group->id, $slot->class_group_id);
    }

    #[Test]
    public function a_taught_lesson_requires_versioning(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->slotWithLesson($schoolClass, LessonStatus::Taught);

        $this->putSlot($slot, $schoolClass, [
            'effective_from' => $this->inDays(1),
        ])->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 2);
    }

    /**
     * A materialized preparation Lesson without a summary or plan is empty
     * history even when its slot starts today.
     */
    #[Test]
    public function a_today_starting_preparation_lesson_without_records_does_not_require_versioning(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $today = $this->today();
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => $today, 'ends_on' => null]),
        ));
        $this->inTenant($this->organization, fn (): Lesson => Lesson::create([
            'class_id' => $schoolClass->id,
            // Explícito: estas aulas são da TURMA INTEIRA. É o que o
            // instantâneo de `class_group_id` guarda, e é o que estes
            // casos comparam antes e depois de rever ou fechar o tempo
            // do horário.
            'class_group_id' => null,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => $today.' 09:30:00',
            'ends_at' => $today.' 10:20:00',
            'status' => LessonStatus::Preparation,
            'created_by' => $this->teacher->id,
        ]));

        $this->putSlot($slot, $schoolClass, [
            'day_of_week' => 3,
            'starts_at' => '10:00',
            'ends_at' => '10:50',
            'starts_on' => $today,
            'ends_on' => null,
        ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());
        $this->assertNull($slot->ends_on);
        $this->assertSame(3, $slot->day_of_week);
        $this->assertStringStartsWith('10:00', $slot->starts_at);
        $this->assertSame($today, $slot->starts_on->toDateString());
        $this->assertDatabaseHas('lessons', [
            'recurring_lesson_slot_id' => $slot->id,
            'class_group_id' => null,
        ]);
    }

    #[Test]
    public function editing_an_empty_whole_class_lesson_to_a_group_aligns_its_snapshot_without_versioning(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->slotWithLesson($schoolClass, LessonStatus::Preparation, null);
        $group = $this->groupFor($schoolClass);
        $lesson = $this->inTenant($this->organization, fn (): Lesson => $slot->lessons()->sole());

        $this->putSlot($slot, $schoolClass, ['class_group_id' => $group->id])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $this->assertDatabaseHas('recurring_lesson_slots', [
            'id' => $slot->id,
            'class_group_id' => $group->id,
        ]);
        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'class_group_id' => $group->id,
        ]);
    }

    #[Test]
    public function editing_an_empty_lesson_from_one_group_to_another_aligns_its_snapshot_without_versioning(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $firstGroup = $this->groupFor($schoolClass);
        $secondGroup = $this->groupFor($schoolClass, 'T2');
        $slot = $this->slotWithLesson($schoolClass, LessonStatus::Preparation, $firstGroup->id);
        $lesson = $this->inTenant($this->organization, fn (): Lesson => $slot->lessons()->sole());
        $startsOn = $slot->starts_on?->toDateString();

        $this->putSlot($slot, $schoolClass, ['class_group_id' => $secondGroup->id])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $this->assertDatabaseHas('recurring_lesson_slots', [
            'id' => $slot->id,
            'class_group_id' => $secondGroup->id,
        ]);
        $this->assertSame(
            $startsOn,
            $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh())
                ->starts_on?->toDateString(),
        );
        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'class_group_id' => $secondGroup->id,
        ]);
    }

    #[Test]
    public function mixed_empty_and_relevant_lessons_version_without_aligning_any_snapshot(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->slotWithLesson($schoolClass, LessonStatus::Preparation, null);
        $empty = $this->inTenant($this->organization, fn (): Lesson => $slot->lessons()->sole());
        $relevant = $this->inTenant($this->organization, fn (): Lesson => Lesson::create([
            'class_id' => $slot->class_id,
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => $this->today().' 11:00:00',
            'ends_at' => $this->today().' 11:50:00',
            'status' => LessonStatus::Taught,
            'created_by' => $this->teacher->id,
        ]));
        $beforeRelevant = $this->byColumn($relevant->getAttributes());
        $group = $this->groupFor($schoolClass);

        $this->putSlot($slot, $schoolClass, [
            'class_group_id' => $group->id,
            'effective_from' => $this->inDays(1),
        ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 2);
        $this->assertDatabaseHas('lessons', [
            'id' => $empty->id,
            'class_group_id' => null,
        ]);
        $this->assertSame($beforeRelevant, $this->inTenant(
            $this->organization,
            fn (): array => $this->byColumn(Lesson::query()->findOrFail($relevant->id)->getAttributes()),
        ));
    }

    #[Test]
    public function a_lock_recheck_versions_when_history_is_acquired_after_the_initial_direct_decision(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->slotWithLesson($schoolClass);
        $lesson = $this->inTenant($this->organization, fn (): Lesson => $slot->lessons()->sole());
        $group = $this->groupFor($schoolClass);
        $effectiveFrom = $this->inDays(1);
        $historyAdded = false;

        DB::listen(function ($query) use (&$historyAdded, $lesson): void {
            if ($historyAdded
                || ! str_contains($query->sql, 'recurring_lesson_slots')
                || ! str_contains($query->sql, '"id"')
                || ! str_contains($query->sql, 'limit 1')) {
                return;
            }

            $historyAdded = true;
            LessonSummary::create([
                'lesson_id' => $lesson->id,
                'content' => 'Histórico adquirido durante o recheck.',
            ]);
        });

        $this->putSlot($slot, $schoolClass, [
            'class_group_id' => $group->id,
            'effective_from' => $effectiveFrom,
        ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertTrue($historyAdded);
        $this->assertDatabaseCount('recurring_lesson_slots', 2);
        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'class_group_id' => null,
        ]);
        $this->assertDatabaseHas('recurring_lesson_slots', [
            'id' => $slot->id,
            'class_group_id' => null,
        ]);
        $new = $this->inTenant(
            $this->organization,
            fn (): RecurringLessonSlot => RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole(),
        );
        $this->assertSame($group->id, $new->class_group_id);
        $this->assertSame($effectiveFrom, $new->starts_on->toDateString());
    }

    #[Test]
    public function a_lock_recheck_without_effective_date_returns_a_controlled_validation_error(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->slotWithLesson($schoolClass);
        $lesson = $this->inTenant($this->organization, fn (): Lesson => $slot->lessons()->sole());
        $group = $this->groupFor($schoolClass);
        $historyAdded = false;

        DB::listen(function ($query) use (&$historyAdded, $lesson): void {
            if ($historyAdded
                || ! str_contains($query->sql, 'recurring_lesson_slots')
                || ! str_contains($query->sql, '"id"')
                || ! str_contains($query->sql, 'limit 1')) {
                return;
            }

            $historyAdded = true;
            LessonSummary::create([
                'lesson_id' => $lesson->id,
                'content' => 'Histórico adquirido durante o recheck.',
            ]);
        });

        $response = $this->putSlot($slot, $schoolClass, ['class_group_id' => $group->id])
            ->assertRedirect()
            ->assertSessionHasErrors('effective_from');

        $this->assertTrue($historyAdded);
        $this->assertStringContainsString(
            'adquiriu histórico',
            session('errors')->get('effective_from')[0],
        );
        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $this->assertDatabaseHas('recurring_lesson_slots', [
            'id' => $slot->id,
            'class_group_id' => null,
        ]);
        $this->assertDatabaseHas('lessons', [
            'id' => $lesson->id,
            'class_group_id' => null,
        ]);
    }

    #[Test]
    public function a_future_slot_without_lessons_is_edited_directly_without_versioning(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $future = $this->inDays(10);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, [
                'starts_on' => $future,
                'ends_on' => null,
            ]),
        ));

        $this->putSlot($slot, $schoolClass, [
            'day_of_week' => 4,
            'starts_at' => '13:00',
            'ends_at' => '13:50',
            'starts_on' => $future,
            'ends_on' => null,
        ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $updated = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());
        $this->assertSame(4, $updated->day_of_week);
        $this->assertStringStartsWith('13:00', $updated->starts_at);
        $this->assertSame($future, $updated->starts_on->toDateString());
    }

    #[Test]
    public function a_lesson_summary_requires_versioning(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->slotWithLesson($schoolClass);
        $lesson = $this->inTenant($this->organization, fn (): Lesson => $slot->lessons()->sole());
        $this->inTenant($this->organization, fn (): LessonSummary => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => 'Sumário escrito.',
        ]));

        $this->putSlot($slot, $schoolClass, ['effective_from' => $this->inDays(1)])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 2);
        $this->assertTrue($slot->refresh()->ends_on->isSameDay(Carbon::parse($this->today())));
        $new = $this->inTenant(
            $this->organization,
            fn (): RecurringLessonSlot => RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole(),
        );
        $this->assertSame($this->inDays(1), $new->starts_on->toDateString());
    }

    #[Test]
    public function a_lesson_plan_requires_versioning(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->slotWithLesson($schoolClass);
        $lesson = $this->inTenant($this->organization, fn (): Lesson => $slot->lessons()->sole());
        $this->inTenant($this->organization, fn (): LessonPlan => LessonPlan::create([
            'lesson_id' => $lesson->id,
            'planned_summary' => 'Plano de aula.',
            'created_by' => $this->teacher->id,
        ]));

        $this->putSlot($slot, $schoolClass, ['effective_from' => $this->inDays(1)])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 2);
        $this->assertTrue($slot->refresh()->ends_on->isSameDay(Carbon::parse($this->today())));
        $new = $this->inTenant(
            $this->organization,
            fn (): RecurringLessonSlot => RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole(),
        );
        $this->assertSame($this->inDays(1), $new->starts_on->toDateString());
    }

    /**
     * (b) E a data que a rejeição acima deixa passar: amanhã fecha a versão
     * de hoje exatamente EM hoje (não em "hoje - 1", que produziria
     * ends_on < starts_on) e abre a nova a partir de amanhã — e a Lesson já
     * materializada para hoje, sob a versão antiga, fica completamente
     * intocada.
     */
    #[Test]
    public function revising_a_today_starting_slot_with_a_lesson_today_succeeds_from_tomorrow(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $today = $this->today();
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => $today, 'ends_on' => null]),
        ));
        $lesson = $this->inTenant($this->organization, fn (): Lesson => Lesson::create([
            'class_id' => $schoolClass->id,
            // Explícito: estas aulas são da TURMA INTEIRA. É o que o
            // instantâneo de `class_group_id` guarda, e é o que estes
            // casos comparam antes e depois de rever ou fechar o tempo
            // do horário.
            'class_group_id' => null,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => $today.' 09:30:00',
            'ends_at' => $today.' 10:20:00',
            'status' => LessonStatus::Taught,
            'created_by' => $this->teacher->id,
        ]));
        $before = $this->byColumn($lesson->getAttributes());
        $tomorrow = $this->inDays(1);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 3,
                'starts_at' => '10:00',
                'ends_at' => '10:50',
                'starts_on' => $today,
                'ends_on' => null,
                'effective_from' => $tomorrow,
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 2);

        [$old, $new] = $this->inTenant($this->organization, fn (): array => [
            $slot->refresh(),
            RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole(),
        ]);

        $this->assertSame($today, $old->ends_on->toDateString());
        $this->assertSame($tomorrow, $new->starts_on->toDateString());

        $this->assertSame($before, $this->inTenant(
            $this->organization,
            fn (): array => $this->byColumn(Lesson::query()->findOrFail($lesson->id)->getAttributes()),
        ));
    }

    /**
     * (c) effective_from cai DEPOIS do ends_on original da versão atual: o
     * limite antigo fica exatamente onde já estava — nunca esticado até
     * effective_from - 1 dia —, o que abre de propósito um intervalo em que
     * nenhuma das duas versões está em vigor.
     */
    #[Test]
    public function revising_a_slot_past_its_original_ends_on_leaves_the_gap_instead_of_extending_it(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $originalEndsOn = $this->inDays(60);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => null, 'ends_on' => $originalEndsOn]),
        ));
        $this->lessonForSlot($slot, LessonStatus::Taught);
        $effectiveFrom = $this->inDays(120);
        $newEndsOn = $this->inDays(300);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'starts_on' => null,
                'ends_on' => $newEndsOn,
                'effective_from' => $effectiveFrom,
            ]))
            ->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 2);

        [$old, $new] = $this->inTenant($this->organization, fn (): array => [
            $slot->refresh(),
            RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole(),
        ]);

        $this->assertSame($originalEndsOn, $old->ends_on->toDateString());
        $this->assertSame($effectiveFrom, $new->starts_on->toDateString());
        $this->assertSame($newEndsOn, $new->ends_on->toDateString());
    }

    #[Test]
    public function revising_a_bounded_slot_without_touching_ends_on_keeps_the_new_version_bounded(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $boundedEndsOn = $this->inDays(200);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => null, 'ends_on' => $boundedEndsOn]),
        ));
        $this->lessonForSlot($slot, LessonStatus::Taught);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 4,
                // A UI já pré-preenche `ends_on` com o valor existente do slot
                // (LessonScheduleEditor.vue::edit()) — isto simula um envio
                // sem o campo ter sido tocado, e não um valor escolhido de
                // propósito para o teste.
                'ends_on' => $boundedEndsOn,
                'starts_on' => null,
                'effective_from' => $this->inDays(10),
            ]))
            ->assertRedirect();

        $new = $this->inTenant(
            $this->organization,
            fn (): RecurringLessonSlot => RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole(),
        );

        $this->assertNotNull($new->ends_on);
        $this->assertSame($boundedEndsOn, $new->ends_on->toDateString());
    }

    #[Test]
    public function removing_an_already_in_vigor_slot_closes_it_instead_of_deleting(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => null, 'ends_on' => null]),
        ));
        $this->lessonForSlot($slot, LessonStatus::Taught);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->delete("/_test/lesson-slots/{$slot->ulid}")
            ->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());
        $this->assertSame(
            CarbonImmutable::now('Europe/Lisbon')->subDay()->toDateString(),
            $slot->ends_on->toDateString(),
        );
    }

    /**
     * Um slot cujo starts_on é HOJE mas sem nenhuma Lesson ainda não tem
     * histórico nenhum a proteger — removê-lo é um apagar verdadeiro, tal e
     * qual um slot futuro.
     */
    #[Test]
    public function removing_a_today_starting_slot_with_no_lessons_yet_is_hard_deleted(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => $this->today(), 'ends_on' => null]),
        ));

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->delete("/_test/lesson-slots/{$slot->ulid}")
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    /**
     * A outra metade: um slot cujo starts_on é HOJE mas que já produziu uma
     * Lesson TEM histórico a proteger. Fecha em vez de apagar — e fecha
     * exatamente EM hoje (não em "hoje - 1", que produziria ends_on menor
     * que starts_on, já que os dois são iguais). A Lesson de hoje, já
     * materializada sob esta versão, fica intocada, e materializar de novo o
     * próprio hoje continua idempotente; nada nasce a partir de amanhã.
     */
    #[Test]
    public function removing_a_today_starting_slot_with_a_lesson_today_closes_it_at_today_instead_of_deleting(): void
    {
        // A wide academic year — the fixed 2026-09-01/2027-06-30 window used
        // everywhere else in this file would throw when today happens to
        // fall outside it, and this test's whole point is materializing
        // AROUND the real "today".
        $schoolClass = $this->schoolClassFor($this->teacher, null, $this->inDays(-30), $this->inDays(400));
        $today = $this->today();
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            // day_of_week pinned to today's own real weekday, so the
            // re-materialization check below lands on the same day instead
            // of silently matching nothing when today isn't a Monday.
            $this->slotAttributes($schoolClass, [
                'day_of_week' => $this->todayDayOfWeek(),
                'starts_on' => $today,
                'ends_on' => null,
            ]),
        ));
        $lesson = $this->inTenant($this->organization, fn (): Lesson => Lesson::create([
            'class_id' => $schoolClass->id,
            // Explícito: estas aulas são da TURMA INTEIRA. É o que o
            // instantâneo de `class_group_id` guarda, e é o que estes
            // casos comparam antes e depois de rever ou fechar o tempo
            // do horário.
            'class_group_id' => null,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => $today.' 09:30:00',
            'ends_at' => $today.' 10:20:00',
            'status' => LessonStatus::Preparation,
            'created_by' => $this->teacher->id,
        ]));
        $before = $this->byColumn($lesson->getAttributes());

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->delete("/_test/lesson-slots/{$slot->ulid}")
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());
        $this->assertSame($today, $slot->ends_on->toDateString());

        $this->assertSame($before, $this->inTenant(
            $this->organization,
            fn (): array => $this->byColumn(Lesson::query()->findOrFail($lesson->id)->getAttributes()),
        ));

        // Re-materializar o próprio hoje continua idempotente (não duplica,
        // não rebenta), e nada nasce a partir de amanhã — a versão fechada
        // não produz mais nenhuma ocorrência.
        $lessons = $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse($today),
            CarbonImmutable::parse($this->inDays(7)),
            $this->teacher,
        ));

        $this->assertCount(1, $lessons);
        $this->assertSame($lesson->id, $lessons->sole()->id);
        $this->assertSame(1, Lesson::query()->count());
    }

    #[Test]
    public function a_future_slot_with_no_lessons_is_still_hard_deleted(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => $this->inDays(30), 'ends_on' => null]),
        ));

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->delete("/_test/lesson-slots/{$slot->ulid}")
            ->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 0);
    }

    #[Test]
    public function a_future_slot_with_existing_lessons_is_closed_instead_of_deleted(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $startsOn = $this->inDays(30);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => $startsOn, 'ends_on' => null]),
        ));
        $lesson = $this->inTenant($this->organization, fn (): Lesson => Lesson::create([
            'class_id' => $schoolClass->id,
            // Explícito: estas aulas são da TURMA INTEIRA. É o que o
            // instantâneo de `class_group_id` guarda, e é o que estes
            // casos comparam antes e depois de rever ou fechar o tempo
            // do horário.
            'class_group_id' => null,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => $startsOn.' 09:30:00',
            'ends_at' => $startsOn.' 10:20:00',
            'status' => LessonStatus::Preparation,
            'created_by' => $this->teacher->id,
        ]));
        $before = $this->byColumn($lesson->getAttributes());

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->delete("/_test/lesson-slots/{$slot->ulid}")
            ->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());
        $this->assertSame($startsOn, $slot->ends_on->toDateString());

        $this->assertSame($before, $this->inTenant(
            $this->organization,
            fn (): array => $this->byColumn(Lesson::query()->findOrFail($lesson->id)->getAttributes()),
        ));
    }

    #[Test]
    public function revising_an_already_in_vigor_slot_never_touches_its_existing_materialized_lessons(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['day_of_week' => 1, 'starts_on' => null, 'ends_on' => null]),
        ));
        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-07'),
            $this->teacher,
        ));
        $lesson = $this->inTenant($this->organization, fn (): Lesson => Lesson::query()->sole());
        $before = $this->byColumn($lesson->getAttributes());

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 2,
                'starts_on' => null,
                'ends_on' => null,
                'effective_from' => $this->inDays(400),
            ]))
            ->assertRedirect();
        $this->assertSame($before, $this->inTenant(
            $this->organization,
            fn (): array => $this->byColumn(Lesson::query()->findOrFail($lesson->id)->getAttributes()),
        ));
    }

    #[Test]
    public function removing_an_already_in_vigor_slot_never_touches_its_existing_materialized_lessons(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['day_of_week' => 1, 'starts_on' => null, 'ends_on' => null]),
        ));
        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-07'),
            $this->teacher,
        ));
        $lesson = $this->inTenant($this->organization, fn (): Lesson => Lesson::query()->sole());
        $before = $this->byColumn($lesson->getAttributes());

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->delete("/_test/lesson-slots/{$slot->ulid}")
            ->assertRedirect();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $this->assertSame($before, $this->inTenant(
            $this->organization,
            fn (): array => $this->byColumn(Lesson::query()->findOrFail($lesson->id)->getAttributes()),
        ));
    }

    /**
     * Mudança de dia a meio do ano: terça às 10:00 passa a quarta às 11:00 a
     * partir de 2026-09-15. Materializar um intervalo que atravessa a
     * fronteira prova as duas metades ao mesmo tempo — o dia/hora certos de
     * cada lado, e o recurring_lesson_slot_id certo (o antigo, agora
     * fechado, do lado de cá; o novo do lado de lá).
     */
    #[Test]
    public function revising_a_slots_weekday_splits_materialization_at_the_boundary(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, [
                'day_of_week' => 2,
                'starts_at' => '10:00',
                'ends_at' => '10:50',
                'starts_on' => null,
                'ends_on' => null,
            ]),
        ));
        $history = $this->lessonForSlot($slot, LessonStatus::Taught);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 3,
                'starts_at' => '11:00',
                'ends_at' => '11:50',
                'starts_on' => null,
                'ends_on' => null,
                'effective_from' => '2026-09-15',
            ]))
            ->assertRedirect();
        $this->inTenant($this->organization, fn () => $history->delete());

        $new = $this->inTenant(
            $this->organization,
            fn (): RecurringLessonSlot => RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole(),
        );

        $lessons = $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-21'),
            $this->teacher,
        ));

        $this->assertCount(2, $lessons);
        $byDate = $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Lesson $lesson): array => [
                'starts_at' => $lesson->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $lesson->ends_at->format('Y-m-d H:i:s'),
                'recurring_lesson_slot_id' => $lesson->recurring_lesson_slot_id,
            ])
            ->all());

        $this->assertSame([
            [
                'starts_at' => '2026-09-08 10:00:00',
                'ends_at' => '2026-09-08 10:50:00',
                'recurring_lesson_slot_id' => $slot->id,
            ],
            [
                'starts_at' => '2026-09-16 11:00:00',
                'ends_at' => '2026-09-16 11:50:00',
                'recurring_lesson_slot_id' => $new->id,
            ],
        ], $byDate);
    }

    /**
     * Uma mudança só de hora — o mesmo dia da semana dos dois lados — divide
     * exatamente da mesma forma: a terça de antes do limite fica com a hora
     * antiga, a de depois com a nova.
     */
    #[Test]
    public function revising_only_the_time_of_a_slot_also_splits_materialization_at_the_boundary(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, [
                'day_of_week' => 2,
                'starts_at' => '09:00',
                'ends_at' => '09:50',
                'starts_on' => null,
                'ends_on' => null,
            ]),
        ));
        $history = $this->lessonForSlot($slot, LessonStatus::Taught);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 2,
                'starts_at' => '14:00',
                'ends_at' => '14:50',
                'starts_on' => null,
                'ends_on' => null,
                'effective_from' => '2026-09-15',
            ]))
            ->assertRedirect();
        $this->inTenant($this->organization, fn () => $history->delete());

        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-21'),
            $this->teacher,
        ));

        $this->assertSame(
            ['2026-09-08 09:00:00', '2026-09-15 14:00:00'],
            $this->inTenant($this->organization, fn (): array => Lesson::query()
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Lesson $lesson): string => $lesson->starts_at->format('Y-m-d H:i:s'))
                ->all()),
        );
    }

    /**
     * A fronteira das duas versões, do lado da nova: nada antes do seu
     * `starts_on`, e nada depois do `ends_on` que lhe foi dado — nem sequer
     * a antiga, que já ficou fechada antes disso, contribui algo ali.
     */
    #[Test]
    public function materializing_after_a_revision_respects_the_new_slots_own_boundary(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, [
                'day_of_week' => 2,
                'starts_at' => '09:00',
                'ends_at' => '09:50',
                'starts_on' => null,
                'ends_on' => null,
            ]),
        ));
        $history = $this->lessonForSlot($slot, LessonStatus::Taught);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 2,
                'starts_at' => '14:00',
                'ends_at' => '14:50',
                'starts_on' => null,
                // Fecha a versão nova ao fim de UMA só terça (2026-09-15).
                'ends_on' => '2026-09-15',
                'effective_from' => '2026-09-15',
            ]))
            ->assertRedirect();
        $this->inTenant($this->organization, fn () => $history->delete());

        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-29'),
            $this->teacher,
        ));

        // 08 (versão antiga) e 15 (versão nova, o único dia dentro do seu
        // ends_on) sim; 22 e 29 — depois do ends_on da nova — não, apesar de
        // ainda serem terças-feiras dentro do intervalo materializado.
        $this->assertSame(
            ['2026-09-08 09:00:00', '2026-09-15 14:00:00'],
            $this->inTenant($this->organization, fn (): array => Lesson::query()
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Lesson $lesson): string => $lesson->starts_at->format('Y-m-d H:i:s'))
                ->all()),
        );
    }

    /**
     * Num feriado não há aula — Fase 5.5 — continua verdadeiro debaixo do
     * PADRÃO NOVO de um horário revisto: o feriado bloqueia a ocorrência da
     * quarta que criou, e a rotina sobrevive intacta para a quarta seguinte.
     */
    #[Test]
    public function non_teaching_days_are_still_respected_under_a_revised_schedule(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $academicYear = $this->inTenant(
            $this->organization,
            fn (): AcademicYear => AcademicYear::query()->findOrFail($schoolClass->academic_year_id),
        );
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, [
                'day_of_week' => 2,
                'starts_at' => '10:00',
                'ends_at' => '10:50',
                'starts_on' => null,
                'ends_on' => null,
            ]),
        ));
        $history = $this->lessonForSlot($slot, LessonStatus::Taught);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 3,
                'starts_at' => '11:00',
                'ends_at' => '11:50',
                'starts_on' => null,
                'ends_on' => null,
                'effective_from' => '2026-09-15',
            ]))
            ->assertRedirect();
        $this->inTenant($this->organization, fn () => $history->delete());

        $this->inTenant($this->organization, fn (): AcademicCalendarException => AcademicCalendarException::factory()
            ->recycle($this->organization)
            ->for($academicYear)
            ->create([
                'type' => AcademicCalendarExceptionType::Holiday,
                'title' => 'Feriado municipal',
                'starts_on' => '2026-09-16',
                'ends_on' => '2026-09-16',
            ]));

        $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $schoolClass,
            CarbonImmutable::parse('2026-09-07'),
            CarbonImmutable::parse('2026-09-23'),
            $this->teacher,
        ));

        // 08 (padrão antigo, terça) sim; 16 (padrão novo, quarta, feriado)
        // não; 23 (padrão novo, quarta seguinte) sim — a rotina nova
        // sobrevive ao feriado exatamente como a antiga sempre sobreviveu.
        $this->assertSame(
            ['2026-09-08 10:00:00', '2026-09-23 11:00:00'],
            $this->inTenant($this->organization, fn (): array => Lesson::query()
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Lesson $lesson): string => $lesson->starts_at->format('Y-m-d H:i:s'))
                ->all()),
        );
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden_from_revising_an_in_vigor_slot(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $unassignedTeacher = User::factory()->create();
        $this->organization->members()->attach($unassignedTeacher, ['joined_at' => now()]);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['starts_on' => null, 'ends_on' => null]),
        ));

        $this->actingAs($unassignedTeacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 2,
                'starts_on' => null,
                'ends_on' => null,
                'effective_from' => $this->inDays(10),
            ]))
            ->assertForbidden();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $slot->refresh());
        $this->assertSame(1, $slot->day_of_week);
    }

    /**
     * Uma versão já em vigor revista uma vez gera uma versão nova que ainda
     * não começou. Enquanto essa versão nova continuar no futuro, revê-la de
     * novo é, pela regra 1, uma edição simples no lugar — e não uma terceira
     * linha: o mecanismo de versionamento só entra em jogo quando há mesmo
     * histórico para proteger.
     */
    #[Test]
    public function revising_an_already_future_slot_a_second_time_is_still_a_plain_in_place_update(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher);
        $original = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['day_of_week' => 1, 'starts_on' => null, 'ends_on' => null]),
        ));
        $history = $this->lessonForSlot($original, LessonStatus::Taught);
        $firstEffectiveFrom = $this->inDays(30);

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$original->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 2,
                'starts_on' => null,
                'ends_on' => null,
                'effective_from' => $firstEffectiveFrom,
            ]))
            ->assertRedirect();
        $this->inTenant($this->organization, fn () => $history->delete());

        $this->assertDatabaseCount('recurring_lesson_slots', 2);
        $future = $this->inTenant(
            $this->organization,
            fn (): RecurringLessonSlot => RecurringLessonSlot::query()->where('id', '!=', $original->id)->sole(),
        );

        $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$future->ulid}", $this->slotPayload($schoolClass, [
                'day_of_week' => 4,
                'starts_at' => '13:00',
                'ends_at' => '13:50',
                'starts_on' => $firstEffectiveFrom,
                'ends_on' => null,
            ]))
            ->assertRedirect();

        // Ainda só duas linhas — a original, já fechada, e a `future`
        // atualizada no próprio lugar, e não uma terceira.
        $this->assertDatabaseCount('recurring_lesson_slots', 2);
        $future = $this->inTenant($this->organization, fn (): RecurringLessonSlot => $future->refresh());
        $this->assertSame(4, $future->day_of_week);
        $this->assertStringStartsWith('13:00', $future->starts_at);
        $this->assertSame($firstEffectiveFrom, $future->starts_on->toDateString());
    }

    /**
     * O "hoje" deste ficheiro — self::FROZEN_NOW, e não a data de execução.
     */
    private function today(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->toDateString();
    }

    private function inDays(int $days): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->addDays($days)->toDateString();
    }

    /**
     * ISO day-of-week (1 = Monday .. 7 = Sunday) of this file's "today" — for
     * a test that needs its slot's own `day_of_week` to actually match today,
     * so that re-materializing today's own date hits the same weekday
     * instead of silently matching nothing.
     */
    private function todayDayOfWeek(): int
    {
        return CarbonImmutable::now(self::TIMEZONE)->dayOfWeekIso;
    }

    /**
     * Os atributos por nome de coluna — a mesma disciplina que
     * MaterializeLessonsCalendarExceptionsTest já usa — para que a
     * comparação seja sobre o que a linha VALE, e não sobre a ordem por que
     * o Eloquent a hidratou.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function byColumn(array $attributes): array
    {
        ksort($attributes);

        return $attributes;
    }

    /**
     * The academic year bounds default to the same fixed 2026-09-01 /
     * 2027-06-30 every other test in this file already relies on. A test
     * that needs "today" (the real clock, not a fixed date) to fall inside
     * the academic year — e.g. to call MaterializeLessonsForRange with a
     * `from` of today — passes its own wide-enough bounds instead of relying
     * on the fixed ones, which would throw outside of a narrow window of the
     * calendar.
     */
    private function schoolClassFor(
        User $teacher,
        ?Organization $organization = null,
        string $academicYearStartsOn = '2026-09-01',
        string $academicYearEndsOn = '2027-06-30',
    ): SchoolClass {
        $organization ??= $this->organization;

        return $this->inTenant(
            $organization,
            function () use ($organization, $teacher, $academicYearStartsOn, $academicYearEndsOn): SchoolClass {
                $schoolClass = SchoolClass::factory()
                    ->recycle($organization)
                    ->create([
                        'academic_year_id' => AcademicYear::factory()
                            ->recycle($organization)
                            ->create(['starts_on' => $academicYearStartsOn, 'ends_on' => $academicYearEndsOn])
                            ->id,
                    ]);
                $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

                return $schoolClass;
            },
        );
    }

    private function groupFor(SchoolClass $schoolClass, string $label = 'T1'): ClassGroup
    {
        return $this->inTenant($this->organization, fn (): ClassGroup => ClassGroup::factory()
            ->recycle($this->organization)
            ->create(['class_id' => $schoolClass->id, 'label' => $label]));
    }

    private function lessonForSlot(
        RecurringLessonSlot $slot,
        LessonStatus $status = LessonStatus::Preparation,
    ): Lesson {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::create([
            'class_id' => $slot->class_id,
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => $this->today().' 09:30:00',
            'ends_at' => $this->today().' 10:20:00',
            'status' => $status,
            'created_by' => $this->teacher->id,
        ]));
    }

    private function slotWithLesson(
        SchoolClass $schoolClass,
        LessonStatus $status = LessonStatus::Preparation,
        ?int $classGroupId = null,
    ): RecurringLessonSlot {
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            $this->slotAttributes($schoolClass, ['class_group_id' => $classGroupId]),
        ));
        $this->lessonForSlot($slot, $status);

        return $slot;
    }

    private function putSlot(RecurringLessonSlot $slot, SchoolClass $schoolClass, array $overrides = [])
    {
        return $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id])
            ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function slotPayload(SchoolClass $schoolClass, array $overrides = []): array
    {
        return array_merge([
            'class_id' => $schoolClass->id,
            'day_of_week' => 1,
            'starts_at' => '09:30',
            'ends_at' => '10:20',
            // HOJE, E NÃO UMA DATA ESCRITA À MÃO. O que estes casos querem é um
            // horário que ainda não começou — o único que
            // `RecurringLessonSlotRequest` deixa alterar sem `effective_from`.
            // Uma data escrita à mão aqui deixa de ser "hoje" no dia seguinte:
            // a validação passa a exigir a data de entrada em vigor, o PUT
            // volta com erros, e o `assertRedirect()` não distingue um redirect
            // de sucesso de um redirect de validação falhada.
            //
            // "Hoje" é self::FROZEN_NOW (ver a constante): fixo, e escolhido
            // para ficar dentro do ano letivo destes casos e antes da semana
            // que eles materializam. Ligado ao relógio real, este `starts_on`
            // ultrapassava essa semana e apagava-lhe a primeira ocorrência.
            'starts_on' => $this->today(),
            'ends_on' => CarbonImmutable::now(self::TIMEZONE)->addYear()->toDateString(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function slotAttributes(SchoolClass $schoolClass, array $overrides = []): array
    {
        return array_merge($this->slotPayload($schoolClass), $overrides);
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
