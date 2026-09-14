<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\DeleteLesson;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Services\Lessons\LessonNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.145.2 — a numeração pertence à TURMA, e a equivalência entre aulas de
 * grupos é EXPLÍCITA: tempos com o mesmo `split_lesson_key` são a mesma lição,
 * e cada aula fica ligada a uma lição (`lesson_unit_key`) uma vez e para sempre.
 *
 * Calendário de outubro de 2026: segunda 05, terça 06, quarta 07, quinta 08;
 * segunda 12, terça 13; segunda 19, terça 20.
 */
class SplitGroupLessonNumberingTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    private ClassGroup $t1;

    private ClassGroup $t2;

    private RecurringLessonSlot $t1Slot;

    private RecurringLessonSlot $t2Slot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
        $this->t1 = $this->makeGroup('T1');
        $this->t2 = $this->makeGroup('T2');

        $key = (string) Str::ulid();
        $this->t1Slot = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 1, 'split_lesson_key' => $key]);
        $this->t2Slot = $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 2, 'split_lesson_key' => $key]);
    }

    /** A) + H) inteiro 1,2,3 · T1/T2 ligados 4,4 · inteiro 5. */
    #[Test]
    public function linked_group_lessons_share_one_number_of_the_class_sequence(): void
    {
        $lessons = [
            $this->whole('2026-10-01 09:30:00'),
            $this->whole('2026-10-02 09:30:00'),
            $this->whole('2026-10-03 09:30:00'),
            $this->onSlot($this->t1Slot, '2026-10-05'),
            $this->onSlot($this->t2Slot, '2026-10-06'),
            $this->whole('2026-10-07 09:30:00'),
        ];

        $this->resequence();

        $this->assertNumbers([1, 2, 3, 4, 4, 5], $lessons);
    }

    /** B) T1 à segunda e T2 à quinta — dias diferentes, o mesmo vínculo. */
    #[Test]
    public function linked_slots_on_different_days_share_the_number(): void
    {
        $key = $this->t1Slot->split_lesson_key;
        $this->inTenant($this->organization, fn () => $this->t2Slot->update(['split_lesson_key' => null]));
        $thursday = $this->makeSlot(['class_group_id' => $this->t2->id, 'day_of_week' => 4, 'starts_at' => '14:00', 'ends_at' => '14:50', 'split_lesson_key' => $key]);

        $lessons = [
            $this->onSlot($this->t1Slot, '2026-10-05'),
            $this->whole('2026-10-06 15:00:00'),
            $this->onSlot($thursday, '2026-10-08', '14:00'),
        ];

        $this->resequence();

        $this->assertNumbers([1, 2, 1], $lessons);
    }

    /**
     * C) + D) feriado de T2 na primeira semana: a T2 seguinte é a lição que
     * ficou por dar, e não a da semana em que acontece.
     */
    #[Test]
    public function a_holiday_in_one_group_does_not_break_the_lesson_identity(): void
    {
        $t1First = $this->onSlot($this->t1Slot, '2026-10-05');
        $t1Second = $this->onSlot($this->t1Slot, '2026-10-12');
        $t2First = $this->onSlot($this->t2Slot, '2026-10-13');

        $this->resequence();
        $this->assertNumbers([1, 2, 1], [$t1First, $t1Second, $t2First]);

        $t2Second = $this->onSlot($this->t2Slot, '2026-10-20');
        $this->resequence();
        $this->assertNumbers([1, 2, 1, 2], [$t1First, $t1Second, $t2First, $t2Second]);

        $this->inTenant($this->organization, function () use ($t1First, $t2First): void {
            $this->assertNotNull($t1First->refresh()->lesson_unit_key);
            $this->assertSame($t1First->lesson_unit_key, $t2First->refresh()->lesson_unit_key);
        });
    }

    /** C) cancelar a T2 não tira a T1 da sua lição. */
    #[Test]
    public function cancelling_one_group_keeps_the_other_in_its_lesson(): void
    {
        $t1 = $this->onSlot($this->t1Slot, '2026-10-05');
        $t2 = $this->onSlot($this->t2Slot, '2026-10-06');
        $whole = $this->whole('2026-10-08 09:30:00');
        $this->resequence();

        $unit = $this->inTenant($this->organization, fn () => $t1->refresh()->lesson_unit_key);

        $this->inTenant($this->organization, fn () => app(DeleteLesson::class)->execute($t2, $this->teacher));

        $this->assertNumbers([1, 2], [$t1, $whole]);
        $this->inTenant($this->organization, fn () => $this->assertSame($unit, $t1->refresh()->lesson_unit_key));
    }

    /** E) duas aulas de T1 e uma de T2: só as dos tempos ligados partilham. */
    #[Test]
    public function an_extra_unlinked_group_lesson_is_its_own_lesson(): void
    {
        $extra = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 4]);

        $lessons = [
            $this->onSlot($this->t1Slot, '2026-10-05'),
            $this->onSlot($this->t2Slot, '2026-10-06'),
            $this->onSlot($extra, '2026-10-08'),
        ];

        $this->resequence();

        $this->assertNumbers([1, 1, 2], $lessons);
    }

    /** F) três grupos ligados partilham o mesmo número. */
    #[Test]
    public function three_linked_groups_share_the_number(): void
    {
        $t3 = $this->makeGroup('T3');
        $t3Slot = $this->makeSlot(['class_group_id' => $t3->id, 'day_of_week' => 3, 'split_lesson_key' => $this->t1Slot->split_lesson_key]);

        $lessons = [
            $this->onSlot($this->t1Slot, '2026-10-05'),
            $this->onSlot($this->t2Slot, '2026-10-06'),
            $this->onSlot($t3Slot, '2026-10-07'),
            $this->whole('2026-10-08 09:30:00'),
        ];

        $this->resequence();

        $this->assertNumbers([1, 1, 1, 2], $lessons);
    }

    /** G) sem vínculo, a mesma semana NÃO emparelha nada. */
    #[Test]
    public function unlinked_group_lessons_never_share_a_number(): void
    {
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::query()->update(['split_lesson_key' => null]));

        $lessons = [
            $this->onSlot($this->t1Slot, '2026-10-05'),
            $this->onSlot($this->t2Slot, '2026-10-06'),
        ];

        $this->resequence();

        $this->assertNumbers([1, 2], $lessons);
    }

    /** I) uma turma de apoio é outra SchoolClass e tem sequência própria. */
    #[Test]
    public function a_support_class_keeps_an_independent_sequence(): void
    {
        $support = $this->inTenant($this->organization, fn (): SchoolClass => SchoolClass::factory()
            ->recycle($this->organization)
            ->support()
            ->create(['academic_year_id' => $this->schoolClass->academic_year_id]));

        $main = [$this->whole('2026-10-05 15:00:00'), $this->whole('2026-10-06 15:00:00')];
        $supportLessons = [
            $this->makeLesson(['class_id' => $support->id, 'starts_at' => '2026-10-05 16:00:00']),
            $this->makeLesson(['class_id' => $support->id, 'starts_at' => '2026-10-07 16:00:00']),
        ];

        $this->resequence();
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $support->id));

        $this->assertNumbers([1, 2], $main);
        $this->assertNumbers([1, 2], $supportLessons);
    }

    /** Inserir em T1 pelo fluxo real: cada aula leva a sua lição consigo. */
    #[Test]
    public function inserting_into_one_group_keeps_each_lesson_identity(): void
    {
        $t1a = $this->onSlot($this->t1Slot, '2026-10-05');
        $t2a = $this->onSlot($this->t2Slot, '2026-10-06');
        $t1b = $this->onSlot($this->t1Slot, '2026-10-12');
        $t2b = $this->onSlot($this->t2Slot, '2026-10-13');
        $this->resequence();

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

            // A aula nova é uma lição nova; a T1 deslocada continua a lição da T2a.
            $this->assertSame(1, $new->lesson_number);
            $this->assertSame('2026-10-12', $t1a->refresh()->starts_at->toDateString());
            $this->assertSame($t2a->refresh()->lesson_unit_key, $t1a->lesson_unit_key);
            $this->assertSame(2, $t1a->lesson_number);
            $this->assertSame(2, $t2a->lesson_number);
            $this->assertSame(3, $t1b->refresh()->lesson_number);
            $this->assertSame(3, $t2b->refresh()->lesson_number);
        });
    }

    /** A pré-visualização recusa o que a execução recusaria (caso entre grupos). */
    #[Test]
    public function the_insert_preview_refuses_what_the_class_numbering_would_refuse(): void
    {
        $taught = $this->makeLesson([
            'class_group_id' => $this->t1->id,
            'starts_at' => '2026-10-14 09:30:00',
            'lesson_number' => 1,
            'status' => LessonStatus::Taught,
        ]);

        $payload = [
            'class' => $this->schoolClass->ulid,
            'class_group_id' => $this->t2->id,
            'insert_at' => '2026-10-06',
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

    /** A pré-visualização é só leitura: nada gravado, auditado, enfileirado. */
    #[Test]
    public function the_insert_preview_leaves_no_persistent_trace(): void
    {
        Queue::fake();
        Notification::fake();

        $lessons = [
            $this->onSlot($this->t1Slot, '2026-10-05'),
            $this->onSlot($this->t2Slot, '2026-10-06'),
        ];
        $this->resequence();

        $snapshot = fn (): string => DB::table('lessons')->orderBy('id')->get(['id', 'starts_at', 'lesson_number', 'lesson_unit_key', 'status', 'updated_at'])->toJson();
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
            fn (string $sql): bool => (bool) preg_match('/^\s*(insert|update|delete)\b/', $sql),
        )));
        Queue::assertNothingPushed();
        Notification::assertNothingSent();

        $next = $this->onSlot($this->t1Slot, '2026-10-19');
        $this->assertSame($maxIdBefore + 1, $next->id);
        $this->assertNumbers([1, 1], $lessons);
    }

    /** DeleteLesson toma o bloqueio da TURMA antes de escrever ou renumerar. */
    #[Test]
    public function deleting_locks_the_class_before_resequencing(): void
    {
        $lesson = $this->onSlot($this->t1Slot, '2026-10-05');
        $this->onSlot($this->t2Slot, '2026-10-06');
        $this->resequence();

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
     * não renumera o histórico, mantém o par ligado e fica registado.
     */
    #[Test]
    public function the_out_of_order_materialization_fallback_is_logged_and_keeps_pairs(): void
    {
        Log::spy();

        $taught = $this->whole('2026-10-15 15:00:00', ['lesson_number' => 1, 'status' => LessonStatus::Taught]);

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

    /** Materializar semanas novas continua a sequência e os pares. */
    #[Test]
    public function materializing_new_weeks_continues_the_class_sequence(): void
    {
        $this->makeSlot(['day_of_week' => 4, 'starts_at' => '15:00', 'ends_at' => '15:50']);

        $this->materialize('2026-10-05', '2026-10-11');
        $this->materialize('2026-10-12', '2026-10-18');
        // K) reabrir as mesmas semanas não mexe em nada.
        $this->materialize('2026-10-05', '2026-10-18');

        $this->inTenant($this->organization, function (): void {
            $numbers = Lesson::query()->orderBy('starts_at')->pluck('lesson_number')->all();
            $this->assertSame([1, 1, 2, 3, 3, 4], $numbers);
        });
    }

    /**
     * 8.º F / J) o caso real: 1,2,3 lecionadas; T1 e T2 (um tempo cada, sem
     * vínculo) ambos em «Lição 1». O comando reconhece o emparelhamento como
     * inequívoco, só escreve com --apply, e reexecutar não muda nada.
     */
    #[Test]
    public function the_command_fixes_the_production_case_only_with_apply(): void
    {
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::query()->update(['split_lesson_key' => null]));
        $taught = ['status' => LessonStatus::Taught];
        $lessons = [
            $this->whole('2026-10-01 09:30:00', $taught + ['lesson_number' => 1]),
            $this->whole('2026-10-02 09:30:00', $taught + ['lesson_number' => 2]),
            $this->whole('2026-10-03 09:30:00', $taught + ['lesson_number' => 3]),
            $this->onSlot($this->t1Slot, '2026-10-05', '09:30', $taught + ['lesson_number' => 1]),
            $this->onSlot($this->t2Slot, '2026-10-06', '09:30', ['lesson_number' => 1]),
        ];

        $this->artisan('lapis:renumber-lessons')
            ->expectsOutputToContain('EMPARELHAMENTO INEQUÍVOCO')
            ->expectsOutputToContain('Lição 1 -> Lição 4')
            ->assertSuccessful();
        $this->assertNumbers([1, 2, 3, 1, 1], $lessons);
        $this->inTenant($this->organization, fn () => $this->assertSame(0, RecurringLessonSlot::query()->whereNotNull('split_lesson_key')->count()));

        $this->artisan('lapis:renumber-lessons', ['--apply' => true])
            ->expectsOutputToContain('Renumeradas: 1 turma(s), 2 aula(s).')
            ->assertSuccessful();
        $this->assertNumbers([1, 2, 3, 4, 4], $lessons);

        // K) reexecutar é idempotente.
        $this->artisan('lapis:renumber-lessons', ['--apply' => true])
            ->expectsOutputToContain('Renumeradas: 0 turma(s), 0 aula(s).')
            ->assertSuccessful();

        // A próxima aula da turma inteira é a 5.
        $next = $this->whole('2026-10-07 09:30:00');
        $this->resequence();
        $this->assertNumbers([5], [$next]);
    }

    /** J) um grupo com dois tempos semanais é ambíguo: nada é escrito. */
    #[Test]
    public function the_command_never_writes_an_ambiguous_class(): void
    {
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::query()->update(['split_lesson_key' => null]));
        $secondT1 = $this->makeSlot(['class_group_id' => $this->t1->id, 'day_of_week' => 4]);

        $lessons = [
            $this->onSlot($this->t1Slot, '2026-10-05', '09:30', ['lesson_number' => 1]),
            $this->onSlot($this->t2Slot, '2026-10-06', '09:30', ['lesson_number' => 1]),
            $this->onSlot($secondT1, '2026-10-08', '09:30', ['lesson_number' => 2]),
        ];

        $this->artisan('lapis:renumber-lessons', ['--apply' => true])
            ->expectsOutputToContain('AMBÍGUO')
            ->expectsOutputToContain('Ambíguas (não tocadas): 1.')
            ->assertSuccessful();

        $this->assertNumbers([1, 1, 2], $lessons);
        $this->inTenant($this->organization, function (): void {
            $this->assertSame(0, RecurringLessonSlot::query()->whereNotNull('split_lesson_key')->count());
            $this->assertSame(0, Lesson::query()->whereNotNull('lesson_unit_key')->count());
        });
    }

    /** K) a correção histórica é idempotente, vínculos incluídos. */
    #[Test]
    public function the_rebuild_is_idempotent(): void
    {
        $this->whole('2026-10-01 09:30:00', ['lesson_number' => 7]);
        $this->onSlot($this->t1Slot, '2026-10-05', '09:30', ['lesson_number' => 1]);
        $this->onSlot($this->t2Slot, '2026-10-06', '09:30', ['lesson_number' => 1]);

        $first = $this->rebuild();
        $this->assertNotSame([], $first['numbers']);
        $this->assertCount(2, $first['links']);

        $second = $this->rebuild();
        $this->assertSame([], $second['numbers']);
        $this->assertSame([], $second['links']);
    }

    /** O horário: «Mesma lição que…» liga os tempos sem o professor ver chaves. */
    #[Test]
    public function the_schedule_form_links_and_refuses_same_group_links(): void
    {
        $this->inTenant($this->organization, fn () => RecurringLessonSlot::query()->update(['split_lesson_key' => null]));

        $this->asTeacher()->post('/lesson-slots', [
            'class_id' => $this->schoolClass->id,
            'class_group_id' => $this->t2->id,
            'day_of_week' => 3,
            'starts_at' => '11:00',
            'ends_at' => '11:50',
            'same_lesson_as' => $this->t1Slot->ulid,
        ])->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function (): void {
            $created = RecurringLessonSlot::query()->where('day_of_week', 3)->sole();
            $this->assertNotNull($created->split_lesson_key);
            $this->assertSame($created->split_lesson_key, $this->t1Slot->refresh()->split_lesson_key);
        });

        $this->asTeacher()->post('/lesson-slots', [
            'class_id' => $this->schoolClass->id,
            'class_group_id' => $this->t1->id,
            'day_of_week' => 5,
            'starts_at' => '11:00',
            'ends_at' => '11:50',
            'same_lesson_as' => $this->t1Slot->ulid,
        ])->assertSessionHasErrors('same_lesson_as');
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
    private function onSlot(RecurringLessonSlot $slot, string $date, string $time = '09:30', array $attributes = []): Lesson
    {
        return $this->makeLesson([
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => "{$date} {$time}:00",
        ] + $attributes);
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

    private function resequence(): void
    {
        $this->inTenant(
            $this->organization,
            fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id),
        );
    }

    /**
     * @return array{numbers: array<int, mixed>, links: array<int, string>}
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
