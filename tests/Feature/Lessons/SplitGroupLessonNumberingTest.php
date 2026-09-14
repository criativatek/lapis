<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\DeleteLesson;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Services\Lessons\LessonNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.145.2 — a numeração pertence à TURMA. T1 e T2 da mesma lição partilham um
 * número; a turma inteira continua a sequência a seguir.
 *
 * Calendário de outubro de 2026 usado aqui: segunda 05, terça 06, quarta 07,
 * quinta 08, sexta 09; segunda 12, terça 13, quinta 15.
 */
class SplitGroupLessonNumberingTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    private ClassGroup $t1;

    private ClassGroup $t2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
        $this->t1 = $this->makeGroup('T1');
        $this->t2 = $this->makeGroup('T2');
    }

    /** A) inteiro 1,2,3 · T1/T2 4,4 · inteiro 5. */
    #[Test]
    public function a_split_pair_consumes_one_number_of_the_class_sequence(): void
    {
        $lessons = [
            $this->whole('2026-10-05 09:30:00'),
            $this->whole('2026-10-06 09:30:00'),
            $this->whole('2026-10-07 09:30:00'),
            $this->group($this->t1, '2026-10-08 09:30:00'),
            $this->group($this->t2, '2026-10-08 11:00:00'),
            $this->whole('2026-10-09 09:30:00'),
        ];

        $this->resequence();

        $this->assertNumbers([1, 2, 3, 4, 4, 5], $lessons);
    }

    /** B) T1 à terça e T2 à quinta — horários diferentes, a mesma lição. */
    #[Test]
    public function groups_at_different_times_of_the_same_week_share_the_number(): void
    {
        $lessons = [
            $this->whole('2026-10-05 09:30:00'),
            $this->group($this->t1, '2026-10-06 09:30:00'),
            $this->group($this->t2, '2026-10-08 14:00:00'),
            $this->whole('2026-10-09 09:30:00'),
        ];

        $this->resequence();

        $this->assertNumbers([1, 2, 2, 3], $lessons);
    }

    /** C) dois pares seguidos: 4,4 · 5,5 — na mesma semana e em semanas seguidas. */
    #[Test]
    public function consecutive_pairs_get_consecutive_shared_numbers(): void
    {
        $lessons = [
            $this->group($this->t1, '2026-10-05 09:30:00'),
            $this->group($this->t2, '2026-10-06 09:30:00'),
            $this->group($this->t1, '2026-10-07 09:30:00'),
            $this->group($this->t2, '2026-10-08 09:30:00'),
            $this->group($this->t1, '2026-10-12 09:30:00'),
            $this->group($this->t2, '2026-10-13 09:30:00'),
        ];

        $this->resequence();

        $this->assertNumbers([1, 1, 2, 2, 3, 3], $lessons);
    }

    /** D) uma turma de apoio é outra SchoolClass e tem sequência própria. */
    #[Test]
    public function a_support_class_keeps_an_independent_sequence(): void
    {
        $support = $this->inTenant($this->organization, fn (): SchoolClass => SchoolClass::factory()
            ->recycle($this->organization)
            ->support()
            ->create(['academic_year_id' => $this->schoolClass->academic_year_id]));

        $main = [$this->whole('2026-10-05 09:30:00'), $this->whole('2026-10-06 09:30:00')];
        $supportLessons = [
            $this->makeLesson(['class_id' => $support->id, 'starts_at' => '2026-10-05 15:00:00']),
            $this->makeLesson(['class_id' => $support->id, 'starts_at' => '2026-10-07 15:00:00']),
        ];

        $this->resequence();
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $support->id));

        $this->assertNumbers([1, 2], $main);
        $this->assertNumbers([1, 2], $supportLessons);
    }

    /** E) inserir uma aula da turma inteira antes do par desloca os dois por igual. */
    #[Test]
    public function inserting_before_a_pair_moves_both_groups_together(): void
    {
        $pair = [
            $this->group($this->t1, '2026-10-06 09:30:00', ['lesson_number' => 1]),
            $this->group($this->t2, '2026-10-07 09:30:00', ['lesson_number' => 1]),
        ];
        $after = $this->whole('2026-10-08 09:30:00', ['lesson_number' => 2]);

        $inserted = $this->whole('2026-10-05 09:30:00');
        $this->resequence();

        $this->assertNumbers([1, 2, 2, 3], [$inserted, ...$pair, $after]);
    }

    /** E) inserir em T1 pelo fluxo real: T1 e T2 continuam alinhados. */
    #[Test]
    public function inserting_into_one_group_keeps_the_pairs_aligned(): void
    {
        $t1Slot = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 1]);
        $t2Slot = $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 2]);

        $t1a = $this->group($this->t1, '2026-10-05 09:30:00', ['recurring_lesson_slot_id' => $t1Slot->id, 'lesson_number' => 1]);
        $t2a = $this->group($this->t2, '2026-10-06 09:30:00', ['recurring_lesson_slot_id' => $t2Slot->id, 'lesson_number' => 1]);
        $t1b = $this->group($this->t1, '2026-10-12 09:30:00', ['recurring_lesson_slot_id' => $t1Slot->id, 'lesson_number' => 2]);
        $t2b = $this->group($this->t2, '2026-10-13 09:30:00', ['recurring_lesson_slot_id' => $t2Slot->id, 'lesson_number' => 2]);

        $this->asTeacher()
            ->from('/lessons')
            ->post('/lessons/insert', [
                'class' => $this->schoolClass->ulid,
                'class_group_id' => $this->t1->id,
                'insert_at' => '2026-10-05',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($t1a, $t1b, $t2a, $t2b): void {
            $new = Lesson::query()->where('class_group_id', $this->t1->id)->whereDate('starts_at', '2026-10-05')->sole();

            $this->assertSame(1, $new->lesson_number);
            $this->assertSame(1, $t2a->refresh()->lesson_number);
            $this->assertSame('2026-10-12', $t1a->refresh()->starts_at->toDateString());
            $this->assertSame(2, $t1a->lesson_number);
            $this->assertSame(2, $t2b->refresh()->lesson_number);
            $this->assertSame(3, $t1b->refresh()->lesson_number);
        });
    }

    /**
     * A pré-visualização não pode prometer o que a execução recusa: inserir em
     * T2 antes de aulas lecionadas de T1 mudaria os números delas.
     */
    #[Test]
    public function the_insert_preview_refuses_what_the_class_numbering_would_refuse(): void
    {
        $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 1]);
        $taught = $this->group($this->t1, '2026-10-13 09:30:00', ['lesson_number' => 1, 'status' => LessonStatus::Taught]);

        $payload = [
            'class' => $this->schoolClass->ulid,
            'class_group_id' => $this->t2->id,
            'insert_at' => '2026-10-05',
        ];

        $this->asTeacher()->postJson('/lessons/insert/preview', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lesson_number');
        $this->asTeacher()->from('/lessons')->post('/lessons/insert', $payload)->assertSessionHasErrors('lesson_number');

        $this->inTenant($this->organization, function () use ($taught): void {
            $this->assertSame(1, Lesson::query()->count());
            $this->assertSame(1, $taught->refresh()->lesson_number);
        });
    }

    /**
     * A pré-visualização é só leitura: nem aulas, nem datas, nem números, nem
     * auditoria, nem jobs ou notificações — e nem sequer um id consumido.
     */
    #[Test]
    public function the_insert_preview_leaves_no_persistent_trace(): void
    {
        Queue::fake();
        Notification::fake();

        $t1Slot = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 1]);
        $t2Slot = $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 2]);
        $lessons = [
            $this->group($this->t1, '2026-10-05 09:30:00', ['recurring_lesson_slot_id' => $t1Slot->id, 'lesson_number' => 1]),
            $this->group($this->t2, '2026-10-06 09:30:00', ['recurring_lesson_slot_id' => $t2Slot->id, 'lesson_number' => 1]),
        ];
        $snapshot = fn (): string => DB::table('lessons')->orderBy('id')->get(['id', 'starts_at', 'lesson_number', 'status', 'updated_at'])->toJson();
        $before = $snapshot();
        $auditBefore = DB::table('audit_events')->count();
        $maxIdBefore = DB::table('lessons')->max('id');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->asTeacher()->postJson('/lessons/insert/preview', [
            'class' => $this->schoolClass->ulid,
            'class_group_id' => $this->t1->id,
            'insert_at' => '2026-10-05',
        ])->assertOk()
            ->assertJsonPath('shifted_count', 1)
            ->assertJsonPath('moves.0.lesson_number', 1)
            ->assertJsonPath('moves.0.lesson_number_to', 2);

        $this->assertSame($before, $snapshot());
        $this->assertSame($auditBefore, DB::table('audit_events')->count());
        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql): bool => (bool) preg_match('/^\s*(insert|update|delete)\b.*\b(lessons|audit_events)\b/', $sql),
        )));
        Queue::assertNothingPushed();
        Notification::assertNothingSent();

        // O id seguinte continua a ser o seguinte: nada foi inserido e desfeito.
        $next = $this->group($this->t1, '2026-10-19 09:30:00');
        $this->assertSame($maxIdBefore + 1, $next->id);
        $this->assertNumbers([1, 1], $lessons);
    }

    /** DeleteLesson toma o bloqueio da TURMA antes de escrever ou renumerar. */
    #[Test]
    public function deleting_locks_the_class_before_resequencing(): void
    {
        $lesson = $this->group($this->t1, '2026-10-05 09:30:00', ['lesson_number' => 1]);
        $this->group($this->t2, '2026-10-06 09:30:00', ['lesson_number' => 1]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->inTenant($this->organization, fn () => app(DeleteLesson::class)->execute($lesson, $this->teacher));

        $classLock = $this->firstIndex($queries, '/^select .* from "?`?classes"?`? .*where/');
        $firstLessonWrite = $this->firstIndex($queries, '/^(update|delete) .*"?`?lessons"?`?/');

        $this->assertNotNull($classLock, 'A turma não foi lida para bloqueio.');
        $this->assertNotNull($firstLessonWrite);
        $this->assertLessThan($firstLessonWrite, $classLock);

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertStringContainsString('for update', $queries[$classLock]);
        }
    }

    /**
     * Plano B documentado: materializar uma semana ANTERIOR a aulas lecionadas
     * não renumera o histórico — o par novo recebe o número a seguir ao maior,
     * T1 e T2 continuam juntos, e o desvio fica registado (não é silencioso).
     */
    #[Test]
    public function the_out_of_order_materialization_fallback_is_logged_and_keeps_pairs(): void
    {
        Log::spy();

        $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 1]);
        $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 2]);
        $taught = $this->whole('2026-10-15 09:30:00', ['lesson_number' => 1, 'status' => LessonStatus::Taught]);

        $this->materialize('2026-10-05', '2026-10-11');

        $this->inTenant($this->organization, function () use ($taught): void {
            $this->assertSame(1, $taught->refresh()->lesson_number);
            $pair = Lesson::query()->whereNotNull('class_group_id')->orderBy('starts_at')->pluck('lesson_number')->all();
            $this->assertSame([2, 2], $pair);
        });

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'lessons.numbering.out_of_order_fallback'
                && $context['class_id'] === $this->schoolClass->id
                && count($context['assigned']) === 2)
            ->once();
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstIndex(array $queries, string $pattern): ?int
    {
        foreach ($queries as $index => $sql) {
            if (preg_match($pattern, $sql) === 1) {
                return $index;
            }
        }

        return null;
    }

    /** F) eliminar a aula de T1 de um par não duplica nem salta números. */
    #[Test]
    public function deleting_half_of_a_pair_neither_duplicates_nor_skips(): void
    {
        $t1 = $this->group($this->t1, '2026-10-05 09:30:00', ['lesson_number' => 1]);
        $t2 = $this->group($this->t2, '2026-10-06 09:30:00', ['lesson_number' => 1]);
        $whole = $this->whole('2026-10-08 09:30:00', ['lesson_number' => 2]);
        $next = [
            $this->group($this->t1, '2026-10-12 09:30:00', ['lesson_number' => 3]),
            $this->group($this->t2, '2026-10-13 09:30:00', ['lesson_number' => 3]),
        ];

        $this->inTenant($this->organization, fn () => app(DeleteLesson::class)->execute($t1, $this->teacher));

        $this->assertNumbers([1, 2, 3, 3], [$t2, $whole, ...$next]);
    }

    /** G) materializar semanas novas continua a sequência da turma. */
    #[Test]
    public function materializing_new_weeks_continues_the_class_sequence(): void
    {
        $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 1]);
        $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 2]);
        $this->makeSlot(['day_of_week' => 4]);

        $this->materialize('2026-10-05', '2026-10-11');
        $this->materialize('2026-10-12', '2026-10-18');

        $this->inTenant($this->organization, function (): void {
            $numbers = Lesson::query()->orderBy('starts_at')->pluck('lesson_number')->all();
            $this->assertSame([1, 1, 2, 3, 3, 4], $numbers);
        });
    }

    /**
     * 16/H) o caso real da 8.º F: 1,2,3 lecionadas, T1 e T2 ambos em «Lição 1».
     * A renumeração normal recusa (histórico); a correção histórica acerta.
     */
    #[Test]
    public function the_historical_rebuild_fixes_the_production_case(): void
    {
        $taught = ['status' => LessonStatus::Taught];
        $lessons = [
            $this->whole('2026-10-05 09:30:00', $taught + ['lesson_number' => 1]),
            $this->whole('2026-10-06 09:30:00', $taught + ['lesson_number' => 2]),
            $this->whole('2026-10-07 09:30:00', $taught + ['lesson_number' => 3]),
            $this->group($this->t1, '2026-10-08 09:30:00', $taught + ['lesson_number' => 1]),
            $this->group($this->t2, '2026-10-08 11:00:00', $taught + ['lesson_number' => 1]),
        ];

        try {
            $this->resequence();
            $this->fail('A renumeração normal não pode mexer em aulas lecionadas.');
        } catch (ValidationException) {
            // Esperado: o funcionamento normal não renumera histórico.
        }
        $this->assertNumbers([1, 2, 3, 1, 1], $lessons);

        $changes = $this->rebuild();

        $this->assertCount(2, $changes);
        $this->assertNumbers([1, 2, 3, 4, 4], $lessons);

        // A próxima aula da turma inteira é a 5.
        $next = $this->whole('2026-10-09 09:30:00');
        $this->resequence();
        $this->assertNumbers([5], [$next]);

        // Nada além do número mudou.
        $this->inTenant($this->organization, function () use ($lessons): void {
            foreach ($lessons as $lesson) {
                $fresh = $lesson->fresh();
                $this->assertSame(LessonStatus::Taught, $fresh?->status);
                $this->assertSame($lesson->class_group_id, $fresh?->class_group_id);
                $this->assertTrue($lesson->starts_at->equalTo($fresh?->starts_at));
            }
        });
    }

    /** I) reexecutar a correção não muda nada. */
    #[Test]
    public function the_rebuild_is_idempotent(): void
    {
        $this->whole('2026-10-05 09:30:00', ['lesson_number' => 7]);
        $this->group($this->t1, '2026-10-06 09:30:00', ['lesson_number' => 1]);
        $this->group($this->t2, '2026-10-07 09:30:00', ['lesson_number' => 1]);

        $this->assertNotSame([], $this->rebuild());
        $this->assertSame([], $this->rebuild());
    }

    #[Test]
    public function the_command_reports_by_default_and_writes_only_with_apply(): void
    {
        $taught = ['status' => LessonStatus::Taught];
        $lessons = [
            $this->whole('2026-10-05 09:30:00', $taught + ['lesson_number' => 1]),
            $this->group($this->t1, '2026-10-06 09:30:00', $taught + ['lesson_number' => 1]),
            $this->group($this->t2, '2026-10-07 09:30:00', $taught + ['lesson_number' => 1]),
        ];

        $this->artisan('lapis:renumber-lessons')->assertSuccessful();
        $this->assertNumbers([1, 1, 1], $lessons);

        $this->artisan('lapis:renumber-lessons', ['--apply' => true])
            ->expectsOutputToContain('Renumeradas: 1 turma(s), 2 aula(s).')
            ->assertSuccessful();
        $this->assertNumbers([1, 2, 2], $lessons);

        $this->artisan('lapis:renumber-lessons', ['--apply' => true])
            ->expectsOutputToContain('Renumeradas: 0 turma(s), 0 aula(s).')
            ->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function whole(string $startsAt, array $attributes = []): Lesson
    {
        return $this->makeLesson(['starts_at' => $startsAt] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function group(ClassGroup $group, string $startsAt, array $attributes = []): Lesson
    {
        return $this->makeLesson(['class_group_id' => $group->id, 'starts_at' => $startsAt] + $attributes);
    }

    /**
     * @param  list<int>  $expected
     * @param  list<Lesson>  $lessons
     */
    private function assertNumbers(array $expected, array $lessons): void
    {
        $this->inTenant($this->organization, function () use ($expected, $lessons): void {
            $this->assertSame($expected, array_map(fn (Lesson $lesson): ?int => $lesson->fresh()?->lesson_number, $lessons));
        });
    }

    private function resequence(): void
    {
        $this->inTenant(
            $this->organization,
            fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id),
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function rebuild(): array
    {
        return $this->inTenant(
            $this->organization,
            fn (): array => app(LessonNumbering::class)->rebuild((int) $this->schoolClass->id),
        );
    }

    private function materialize(string $from, string $to): void
    {
        $this->asTeacher()
            ->post("/classes/{$this->schoolClass->ulid}/lessons/materialize", compact('from', 'to'))
            ->assertRedirect();
    }
}
