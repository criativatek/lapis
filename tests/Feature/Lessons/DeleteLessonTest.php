<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * §42 — eliminar a ocorrência criada por engano, sem levar atrás o horário
 * recorrente, as outras aulas ou o histórico.
 */
class DeleteLessonTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    #[Test]
    public function a_lesson_in_preparation_is_deleted(): void
    {
        $lesson = $this->makeLesson();

        $this->asTeacher()->delete("/lessons/{$lesson->ulid}")->assertRedirect();

        $this->assertDatabaseMissing('lessons', ['id' => $lesson->id]);
    }

    #[Test]
    public function a_prepared_lesson_with_a_summary_is_deleted_with_its_summary(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Prepared]);
        $summary = $this->inTenant(
            $this->organization,
            fn () => $lesson->summary()->create(['content' => 'Equações do 1.º grau.']),
        );

        $this->asTeacher()->delete("/lessons/{$lesson->ulid}")->assertRedirect();

        $this->assertDatabaseMissing('lessons', ['id' => $lesson->id]);
        $this->assertDatabaseMissing('lesson_summaries', ['id' => $summary->id]);
    }

    /**
     * O coração de §4: a rotina sobrevive à ocorrência. Eliminar a aula de uma
     * quinta-feira não desmarca «Matemática às quintas».
     */
    #[Test]
    public function the_recurring_slot_and_the_other_lessons_survive(): void
    {
        $slot = $this->makeSlot();
        $doomed = $this->makeLesson([
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => '2026-10-08 09:30:00',
            'ends_at' => '2026-10-08 10:20:00',
        ]);
        $survivor = $this->makeLesson([
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => '2026-10-15 09:30:00',
            'ends_at' => '2026-10-15 10:20:00',
        ]);

        $this->asTeacher()->delete("/lessons/{$doomed->ulid}")->assertRedirect();

        $this->assertDatabaseMissing('lessons', ['id' => $doomed->id]);
        $this->assertDatabaseHas('lessons', ['id' => $survivor->id]);
        $this->assertDatabaseHas('recurring_lesson_slots', ['id' => $slot->id, 'ends_on' => null]);
    }

    /**
     * O caso que o QA no browser apanhou e que nenhum teste de unidade teria
     * apanhado: abrir a semana materializa-a, pelo que sem uma marca de
     * cancelamento a aula eliminada renascia no recarregamento seguinte.
     */
    #[Test]
    public function a_deleted_occurrence_is_not_recreated_when_the_week_is_opened_again(): void
    {
        $slot = $this->makeSlot();
        $this->materializeWeek();

        $lesson = $this->inTenant(
            $this->organization,
            fn (): Lesson => Lesson::query()->whereDate('starts_at', '2026-10-08')->sole(),
        );

        $this->asTeacher()->delete("/lessons/{$lesson->ulid}")->assertRedirect();
        $this->materializeWeek();

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(
                0,
                Lesson::query()->whereDate('starts_at', '2026-10-08')->count(),
            );
        });

        $this->assertDatabaseHas('cancelled_lesson_occurrences', [
            'class_id' => $this->schoolClass->id,
            'recurring_lesson_slot_id' => $slot->id,
        ]);
        $this->assertDatabaseHas('recurring_lesson_slots', ['id' => $slot->id, 'ends_on' => null]);
    }

    /** A ocorrência seguinte da mesma rotina continua a nascer. */
    #[Test]
    public function cancelling_one_occurrence_does_not_cancel_the_routine(): void
    {
        $this->makeSlot();
        $this->materializeWeek();
        $lesson = $this->inTenant(
            $this->organization,
            fn (): Lesson => Lesson::query()->whereDate('starts_at', '2026-10-08')->sole(),
        );

        $this->asTeacher()->delete("/lessons/{$lesson->ulid}")->assertRedirect();
        $this->materializeWeek('2026-10-12', '2026-10-18');

        $this->inTenant($this->organization, fn () => $this->assertSame(
            1,
            Lesson::query()->whereDate('starts_at', '2026-10-15')->count(),
        ));
    }

    #[Test]
    public function a_taught_lesson_cannot_be_deleted(): void
    {
        $lesson = $this->makeLesson(['status' => LessonStatus::Taught]);

        $this->asTeacher()
            ->from('/lessons')
            ->delete("/lessons/{$lesson->ulid}")
            ->assertSessionHasErrors('lesson');

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden(): void
    {
        $lesson = $this->makeLesson();
        $other = User::factory()->create();
        $this->organization->members()->attach($other, ['joined_at' => now()]);

        $this->actingAs($other)
            ->withSession(['organization_id' => $this->organization->id])
            ->delete("/lessons/{$lesson->ulid}")
            ->assertForbidden();

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
    }

    #[Test]
    public function a_lesson_from_another_organization_is_not_resolved(): void
    {
        $otherTeacher = User::factory()->create();
        $otherOrganization = $otherTeacher->personalOrganization();
        $this->subscribeToPro($otherOrganization);

        $foreign = $this->inTenant($otherOrganization, function () use ($otherOrganization, $otherTeacher): Lesson {
            $class = SchoolClass::factory()
                ->recycle($otherOrganization)
                ->create([
                    'academic_year_id' => AcademicYear::factory()
                        ->recycle($otherOrganization)
                        ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])
                        ->id,
                ]);
            $class->teachers()->attach($otherTeacher, ['role' => 'owner']);

            return Lesson::create([
                'class_id' => $class->id,
                'starts_at' => '2026-10-08 09:30:00',
                'ends_at' => '2026-10-08 10:20:00',
                'status' => LessonStatus::Preparation,
                'created_by' => $otherTeacher->id,
            ]);
        });

        $this->asTeacher()->delete("/lessons/{$foreign->ulid}")->assertNotFound();

        $this->assertDatabaseHas('lessons', ['id' => $foreign->id]);
    }

    #[Test]
    public function impersonation_blocks_deleting_a_lesson(): void
    {
        $lesson = $this->makeLesson();

        $this->actingAs($this->teacher)
            ->withSession([
                'organization_id' => $this->organization->id,
                'impersonator_id' => 999,
            ])
            ->delete("/lessons/{$lesson->ulid}")
            ->assertForbidden();

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
    }

    private function materializeWeek(string $from = '2026-10-05', string $to = '2026-10-11'): void
    {
        $this->asTeacher()
            ->post("/classes/{$this->schoolClass->ulid}/lessons/materialize", compact('from', 'to'))
            ->assertRedirect();
    }

    /** §13-F: a sequência fecha-se depois de eliminar. */
    #[Test]
    public function deleting_closes_the_gap_in_the_numbering(): void
    {
        $first = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'ends_at' => '2026-10-01 10:20:00', 'lesson_number' => 1]);
        $middle = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00', 'lesson_number' => 2]);
        $last = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00', 'ends_at' => '2026-10-15 10:20:00', 'lesson_number' => 3]);

        $this->asTeacher()->delete("/lessons/{$middle->ulid}")->assertRedirect();

        $this->inTenant($this->organization, function () use ($first, $last): void {
            $this->assertSame(1, $first->refresh()->lesson_number);
            $this->assertSame(2, $last->refresh()->lesson_number);
        });
    }

    /**
     * §13, §14: se fechar o buraco exigisse renumerar uma aula já lecionada, a
     * operação inteira é recusada — e a aula NÃO é eliminada.
     */
    #[Test]
    public function deleting_is_refused_when_it_would_renumber_a_taught_lesson(): void
    {
        $early = $this->makeLesson([
            'starts_at' => '2026-10-01 09:30:00', 'ends_at' => '2026-10-01 10:20:00',
            'lesson_number' => 1,
        ]);
        $taught = $this->makeLesson([
            'starts_at' => '2026-10-15 09:30:00', 'ends_at' => '2026-10-15 10:20:00',
            'lesson_number' => 2, 'status' => LessonStatus::Taught,
        ]);

        $this->asTeacher()
            ->from('/lessons')
            ->delete("/lessons/{$early->ulid}")
            ->assertSessionHasErrors('lesson_number');

        $this->assertDatabaseHas('lessons', ['id' => $early->id]);
        $this->inTenant($this->organization, fn () => $this->assertSame(
            2,
            $taught->refresh()->lesson_number,
        ));
    }
}
