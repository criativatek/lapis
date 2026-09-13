<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\RecurringLessonSlot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * §45 — inserir uma aula no meio e deslocar as seguintes para as PRÓXIMAS
 * OCORRÊNCIAS VÁLIDAS do horário (e nunca «+1 dia»).
 *
 * O horário destes testes é «quintas-feiras, 09:30–10:20»: 01, 08, 15, 22 e 29
 * de outubro de 2026 são todas quintas-feiras.
 */
class InsertLessonIntoSequenceTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    #[Test]
    public function inserting_at_the_start_shifts_everything_forward_by_one_occurrence(): void
    {
        $slot = $this->makeSlot();
        $a = $this->lessonOn($slot, '2026-10-01');
        $b = $this->lessonOn($slot, '2026-10-08');
        $c = $this->lessonOn($slot, '2026-10-15');

        $this->insert('2026-10-01')->assertRedirect();

        $this->inTenant($this->organization, function () use ($a, $b, $c): void {
            // Cada uma vai para a ocorrência seguinte do horário real.
            $this->assertSame('2026-10-08', $a->refresh()->starts_at->toDateString());
            $this->assertSame('2026-10-15', $b->refresh()->starts_at->toDateString());
            $this->assertSame('2026-10-22', $c->refresh()->starts_at->toDateString());
            $this->assertSame(4, Lesson::query()->count());
            // E a nova ocupa a que ficou livre.
            $this->assertSame(
                1,
                Lesson::query()->whereDate('starts_at', '2026-10-01')->sole()->lesson_number,
            );
        });
    }

    #[Test]
    public function inserting_in_the_middle_leaves_the_earlier_lessons_alone(): void
    {
        $slot = $this->makeSlot();
        $a = $this->lessonOn($slot, '2026-10-01');
        $b = $this->lessonOn($slot, '2026-10-08');

        $this->insert('2026-10-08')->assertRedirect();

        $this->inTenant($this->organization, function () use ($a, $b): void {
            $this->assertSame('2026-10-01', $a->refresh()->starts_at->toDateString());
            $this->assertSame('2026-10-15', $b->refresh()->starts_at->toDateString());
        });
    }

    /** §16: a próxima ocorrência VÁLIDA, e não o dia seguinte no calendário. */
    #[Test]
    public function the_shift_uses_real_schedule_occurrences_and_not_plus_one_day(): void
    {
        $slot = $this->makeSlot();
        $a = $this->lessonOn($slot, '2026-10-01');

        $this->insert('2026-10-01')->assertRedirect();

        $this->inTenant($this->organization, fn () => $this->assertSame(
            // Uma quinta-feira, e não 02/10 (sexta-feira, sem aula desta turma).
            '2026-10-08',
            $a->refresh()->starts_at->toDateString(),
        ));
    }

    /** §18: encontrar uma aula lecionada recusa a operação INTEIRA. */
    #[Test]
    public function meeting_a_taught_lesson_refuses_the_whole_operation(): void
    {
        $slot = $this->makeSlot();
        $prepared = $this->lessonOn($slot, '2026-10-01');
        $taught = $this->lessonOn($slot, '2026-10-08', ['status' => LessonStatus::Taught]);

        $this->insert('2026-10-01')->assertSessionHasErrors('insert_at');

        $this->inTenant($this->organization, function () use ($prepared, $taught): void {
            $this->assertSame('2026-10-01', $prepared->refresh()->starts_at->toDateString());
            $this->assertSame('2026-10-08', $taught->refresh()->starts_at->toDateString());
            // §19: nada foi escrito — nem sequer a aula nova.
            $this->assertSame(2, Lesson::query()->count());
        });
    }

    #[Test]
    public function the_preview_reports_the_same_refusal_before_anything_is_written(): void
    {
        $slot = $this->makeSlot();
        $this->lessonOn($slot, '2026-10-08', ['status' => LessonStatus::Taught]);

        $this->asTeacher()
            ->postJson('/lessons/insert/preview', [
                'class' => $this->schoolClass->ulid,
                'insert_at' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('insert_at');
    }

    /** §20: a pré-visualização diz quantas e para onde, sem escrever. */
    #[Test]
    public function the_preview_lists_the_moves_without_writing(): void
    {
        $slot = $this->makeSlot();
        $this->lessonOn($slot, '2026-10-01');
        $this->lessonOn($slot, '2026-10-08');

        $this->asTeacher()
            ->postJson('/lessons/insert/preview', [
                'class' => $this->schoolClass->ulid,
                'insert_at' => '2026-10-01',
            ])
            ->assertOk()
            ->assertJsonPath('shifted_count', 2)
            ->assertJsonCount(2, 'moves');

        $this->inTenant($this->organization, fn () => $this->assertSame(2, Lesson::query()->count()));
    }

    /** §17: uma sequência de T1 não toca nas aulas de T2 nem da turma inteira. */
    #[Test]
    public function inserting_in_one_group_leaves_the_other_sequences_untouched(): void
    {
        $first = $this->makeGroup('T1');
        $second = $this->makeGroup('T2');
        $slotOne = $this->makeSlot(['class_group_id' => $first->id, 'starts_at' => '09:30', 'ends_at' => '10:20']);
        $slotTwo = $this->makeSlot(['class_group_id' => $second->id, 'starts_at' => '11:00', 'ends_at' => '11:50']);

        $t1 = $this->lessonOn($slotOne, '2026-10-01', ['class_group_id' => $first->id]);
        $t2 = $this->lessonOn($slotTwo, '2026-10-01', [
            'class_group_id' => $second->id,
            'starts_at' => '2026-10-01 11:00:00',
            'ends_at' => '2026-10-01 11:50:00',
        ]);

        $this->asTeacher()
            ->post('/lessons/insert', [
                'class' => $this->schoolClass->ulid,
                'class_group_id' => $first->id,
                'insert_at' => '2026-10-01',
            ])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($t1, $t2): void {
            $this->assertSame('2026-10-08', $t1->refresh()->starts_at->toDateString());
            // T2 não se mexeu.
            $this->assertSame('2026-10-01', $t2->refresh()->starts_at->toDateString());
        });
    }

    #[Test]
    public function a_class_without_remaining_occurrences_is_refused(): void
    {
        // Um tempo que termina no próprio dia: não há ocorrência nenhuma depois.
        $slot = $this->makeSlot(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-01']);
        $this->lessonOn($slot, '2026-10-01');

        $this->insert('2026-10-01')->assertSessionHasErrors('insert_at');

        $this->inTenant($this->organization, fn () => $this->assertSame(1, Lesson::query()->count()));
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_is_forbidden(): void
    {
        $this->makeSlot();
        $other = User::factory()->create();
        $this->organization->members()->attach($other, ['joined_at' => now()]);

        $this->actingAs($other)
            ->withSession(['organization_id' => $this->organization->id])
            ->post('/lessons/insert', [
                'class' => $this->schoolClass->ulid,
                'insert_at' => '2026-10-01',
            ])
            ->assertForbidden();

        $this->inTenant($this->organization, fn () => $this->assertSame(0, Lesson::query()->count()));
    }

    #[Test]
    public function impersonation_blocks_inserting_a_lesson(): void
    {
        $this->makeSlot();

        $this->actingAs($this->teacher)
            ->withSession([
                'organization_id' => $this->organization->id,
                'impersonator_id' => 999,
            ])
            ->post('/lessons/insert', [
                'class' => $this->schoolClass->ulid,
                'insert_at' => '2026-10-01',
            ])
            ->assertForbidden();

        $this->inTenant($this->organization, fn () => $this->assertSame(0, Lesson::query()->count()));
    }

    private function insert(string $date): TestResponse
    {
        return $this->asTeacher()
            ->from('/lessons')
            ->post('/lessons/insert', [
                'class' => $this->schoolClass->ulid,
                'insert_at' => $date,
            ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lessonOn(
        RecurringLessonSlot $slot,
        string $date,
        array $attributes = [],
    ): Lesson {
        return $this->makeLesson(array_merge([
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => "{$date} 09:30:00",
            'ends_at' => "{$date} 10:20:00",
        ], $attributes));
    }
}
