<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Services\Lessons\LessonNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * §44 — a numeração sequencial: cronológica, única, por turma (T1/T2 partilham o número), e nunca
 * a renumerar o que já foi lecionado.
 */
class LessonNumberingTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    #[Test]
    public function the_first_materialized_lesson_is_lesson_one(): void
    {
        $this->makeSlot();

        $this->materialize('2026-10-01', '2026-10-08');

        $this->inTenant($this->organization, function (): void {
            $numbers = Lesson::query()->orderBy('starts_at')->pluck('lesson_number')->all();
            $this->assertSame([1, 2], $numbers);
        });
    }

    /**
     * §11: a ordem é `starts_at`, e NUNCA `id` nem `created_at`. Aqui a aula
     * mais antiga é criada por último de propósito.
     */
    #[Test]
    public function the_numbering_follows_chronology_and_not_creation_order(): void
    {
        $later = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00', 'ends_at' => '2026-10-15 10:20:00']);
        $earlier = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'ends_at' => '2026-10-01 10:20:00']);

        $this->resequence();

        $this->inTenant($this->organization, function () use ($earlier, $later): void {
            $this->assertSame(1, $earlier->refresh()->lesson_number);
            $this->assertSame(2, $later->refresh()->lesson_number);
        });
    }

    #[Test]
    public function numbers_are_unique_within_a_sequence(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'ends_at' => '2026-10-01 10:20:00']);
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00']);
        $this->makeLesson(['starts_at' => '2026-10-15 09:30:00', 'ends_at' => '2026-10-15 10:20:00']);

        $this->resequence();

        $this->inTenant($this->organization, function (): void {
            $numbers = Lesson::query()->pluck('lesson_number')->all();
            $this->assertSame([1, 2, 3], collect($numbers)->sort()->values()->all());
            $this->assertCount(3, array_unique($numbers));
        });
    }

    /** §12: inserir no meio renumera o futuro permitido. */
    #[Test]
    public function inserting_earlier_renumbers_the_lessons_after_it(): void
    {
        $tenth = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'ends_at' => '2026-10-01 10:20:00', 'lesson_number' => 1]);
        $eleventh = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00', 'ends_at' => '2026-10-15 10:20:00', 'lesson_number' => 2]);

        $inserted = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00']);

        $this->resequence();

        $this->inTenant($this->organization, function () use ($tenth, $inserted, $eleventh): void {
            $this->assertSame(1, $tenth->refresh()->lesson_number);
            $this->assertSame(2, $inserted->refresh()->lesson_number);
            $this->assertSame(3, $eleventh->refresh()->lesson_number);
        });
    }

    /** §14: uma aula lecionada não muda de número em silêncio — nem de todo. */
    #[Test]
    public function a_taught_lesson_never_changes_number(): void
    {
        $this->makeLesson([
            'starts_at' => '2026-10-15 09:30:00', 'ends_at' => '2026-10-15 10:20:00',
            'lesson_number' => 1, 'status' => LessonStatus::Taught,
        ]);
        $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'ends_at' => '2026-10-01 10:20:00']);

        $this->expectException(ValidationException::class);

        $this->resequence();
    }

    #[Test]
    public function a_blocked_resequence_writes_nothing_at_all(): void
    {
        $taught = $this->makeLesson([
            'starts_at' => '2026-10-15 09:30:00', 'ends_at' => '2026-10-15 10:20:00',
            'lesson_number' => 3, 'status' => LessonStatus::Taught,
        ]);
        $newcomer = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'ends_at' => '2026-10-01 10:20:00']);

        try {
            $this->resequence();
        } catch (ValidationException) {
            // Esperado.
        }

        $this->inTenant($this->organization, function () use ($taught, $newcomer): void {
            $this->assertSame(3, $taught->refresh()->lesson_number);
            // A aula nova não recebeu número: a operação não foi executada até meio.
            $this->assertNull($newcomer->refresh()->lesson_number);
        });
    }

    /**
     * O caminho automático não pode partir a página: materializar uma semana
     * antiga atribui números sem renumerar o histórico (LessonNumbering::
     * numberMaterializedLessons).
     */
    #[Test]
    public function materializing_an_earlier_week_never_breaks_on_taught_history(): void
    {
        $slot = $this->makeSlot();
        $taught = $this->makeLesson([
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => '2026-10-15 09:30:00', 'ends_at' => '2026-10-15 10:20:00',
            'lesson_number' => 1, 'status' => LessonStatus::Taught,
        ]);

        $this->materialize('2026-10-01', '2026-10-01');

        $this->inTenant($this->organization, function () use ($taught): void {
            $this->assertSame(1, $taught->refresh()->lesson_number);
            $created = Lesson::query()->whereDate('starts_at', '2026-10-01')->sole();
            $this->assertNotNull($created->lesson_number);
            $this->assertNotSame(1, $created->lesson_number);
        });
    }

    /** §44-K: a lista e o horário mostram o mesmo número porque é o mesmo campo. */
    #[Test]
    public function the_weekly_payload_carries_the_lesson_number(): void
    {
        $this->makeSlot();

        $this->asTeacher()
            ->get('/lessons?week=2026-10-05')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('lessons.0.lesson_number', 1)
                ->has('today')
                ->etc());
    }

    private function resequence(): void
    {
        $this->inTenant(
            $this->organization,
            fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id),
        );
    }

    private function materialize(string $from, string $to): void
    {
        $this->asTeacher()
            ->post("/classes/{$this->schoolClass->ulid}/lessons/materialize", compact('from', 'to'))
            ->assertRedirect();
    }
}
