<?php

namespace Tests\Feature\ClassGroups;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Services\Import\Timetable\DetectSlotConflicts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;

/**
 * O HORÁRIO, A MATERIALIZAÇÃO E O SUMÁRIO quando a turma se desdobra.
 *
 * O caso do briefing (§38) inteiro: 8.º F com três tempos de turma inteira e
 * dois de grupo, sexta-feira seguida.
 */
class ClassGroupScheduleTest extends ClassGroupsTestCase
{
    #[Test]
    public function a_slot_without_a_group_still_means_the_whole_class(): void
    {
        $class = $this->schoolClassFor($this->teacher);

        $this->asTeacher()
            ->post('/lesson-slots', [
                'class_id' => $class->id,
                'day_of_week' => 3,
                'starts_at' => '08:30',
                'ends_at' => '09:20',
                'starts_on' => null,
                'ends_on' => null,
            ])
            ->assertRedirect();

        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::query()->sole());

        $this->assertNull($slot->class_group_id);
    }

    #[Test]
    public function a_slot_can_be_created_for_one_group_and_the_lesson_keeps_it_as_a_snapshot(): void
    {
        [$class, $groups] = $this->classWithTwoGroups();

        $this->asTeacher()
            ->post('/lesson-slots', $this->slotPayload($class, 5, '08:30', '09:20', $groups['T1']->id))
            ->assertRedirect();
        $this->asTeacher()
            ->post('/lesson-slots', $this->slotPayload($class, 5, '09:20', '10:10', $groups['T2']->id))
            ->assertRedirect();

        $lessons = $this->materialize($class, '2026-10-16', '2026-10-16');

        $this->assertCount(2, $lessons);
        $this->assertSame(
            [$groups['T1']->id, $groups['T2']->id],
            $lessons->pluck('class_group_id')->all(),
        );
        $this->assertSame(
            ['8.º F · T1', '8.º F · T2'],
            $this->inTenant(
                $this->organization,
                fn (): array => $lessons->map(fn (Lesson $lesson): string => $lesson->contextLabel())->all(),
            ),
        );
    }

    #[Test]
    public function revising_an_in_force_slot_to_a_group_leaves_past_lessons_alone(): void
    {
        [$class, $groups] = $this->classWithTwoGroups();

        // Um tempo de turma inteira já em vigor, e uma aula dele já nascida.
        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create([
            'class_id' => $class->id,
            'class_group_id' => null,
            'day_of_week' => 5,
            'starts_at' => '08:30',
            'ends_at' => '09:20',
            'starts_on' => self::YEAR_STARTS_ON,
            'ends_on' => null,
        ]));

        $past = $this->materialize($class, '2026-10-09', '2026-10-09')->sole();

        $this->assertNull($past->class_group_id);

        // A partir de amanhã passa a ser só T1.
        $tomorrow = CarbonImmutable::parse(self::FROZEN_NOW, self::TIMEZONE)->addDay()->toDateString();

        $this->asTeacher()
            ->put("/lesson-slots/{$slot->ulid}", [
                ...$this->slotPayload($class, 5, '08:30', '09:20', $groups['T1']->id),
                'starts_on' => self::YEAR_STARTS_ON,
                'effective_from' => $tomorrow,
            ])
            ->assertRedirect();

        // A AULA DE 9 DE OUTUBRO NÃO SE MEXEU.
        $this->assertSame(
            $groups['T1']->id,
            $this->inTenant($this->organization, fn () => $past->fresh()->class_group_id),
            'Uma aula passada sem histórico pedagógico segue a nova fotografia vazia do horário.',
        );

        // E as novas nascem já com o grupo.
        $future = $this->materialize($class, '2026-10-23', '2026-10-23')->sole();

        $this->assertSame($groups['T1']->id, $future->class_group_id);
    }

    #[Test]
    public function materializing_twice_never_rewrites_the_group_of_an_existing_lesson(): void
    {
        [$class, $groups] = $this->classWithTwoGroups();

        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create([
            'class_id' => $class->id,
            'class_group_id' => null,
            'day_of_week' => 5,
            'starts_at' => '08:30',
            'ends_at' => '09:20',
            'starts_on' => self::YEAR_STARTS_ON,
            'ends_on' => null,
        ]));

        $lesson = $this->materialize($class, '2026-10-16', '2026-10-16')->sole();

        // Alguém edita o slot em bruto (o caminho que não versiona) e volta a
        // materializar o MESMO dia: a aula já existe, e o firstOrCreate não
        // pode tocar-lhe — o grupo é um instantâneo, não uma leitura.
        $this->inTenant($this->organization, fn () => $slot->update(['class_group_id' => $groups['T2']->id]));

        $again = $this->materialize($class, '2026-10-16', '2026-10-16')->sole();

        $this->assertSame($lesson->id, $again->id, 'Não podia ter nascido uma segunda aula.');
        $this->assertNull($again->class_group_id);
        $this->assertSame(1, $this->inTenant($this->organization, fn () => Lesson::query()->count()));
    }

    #[Test]
    public function the_previous_summary_of_a_group_only_ever_looks_at_that_group(): void
    {
        [$class, $groups] = $this->classWithTwoGroups();

        $firstT1 = $this->lessonFor($class, $groups['T1'], '2026-10-09 08:30:00');
        $t2 = $this->lessonFor($class, $groups['T2'], '2026-10-09 09:20:00');
        $secondT1 = $this->lessonFor($class, $groups['T1'], '2026-10-16 08:30:00');

        $this->summaryFor($firstT1, 'Aula de T1: os números racionais.');
        $this->summaryFor($t2, 'Aula de T2: revisão de frações.');

        $response = $this->asTeacher()->get("/lessons/{$secondT1->ulid}/previous-summary");

        $response->assertOk();
        $this->assertSame('Aula de T1: os números racionais.', $response->json('content'));
    }

    #[Test]
    public function a_group_with_no_earlier_lesson_of_its_own_gets_nothing_rather_than_another_groups(): void
    {
        [$class, $groups] = $this->classWithTwoGroups();

        $t2 = $this->lessonFor($class, $groups['T2'], '2026-10-09 09:20:00');
        $firstT1 = $this->lessonFor($class, $groups['T1'], '2026-10-16 08:30:00');

        $this->summaryFor($t2, 'Aula de T2: revisão de frações.');

        // SEM RECURSO AO OUTRO GRUPO. «Não há anterior» é a resposta certa.
        $this->asTeacher()
            ->get("/lessons/{$firstT1->ulid}/previous-summary")
            ->assertNoContent();
    }

    #[Test]
    public function a_whole_class_lesson_never_picks_up_a_groups_summary(): void
    {
        [$class, $groups] = $this->classWithTwoGroups();

        $t1 = $this->lessonFor($class, $groups['T1'], '2026-10-09 08:30:00');
        $wholeClass = $this->lessonFor($class, null, '2026-10-14 14:10:00');

        $this->summaryFor($t1, 'Aula de T1: os números racionais.');

        $this->asTeacher()
            ->get("/lessons/{$wholeClass->ulid}/previous-summary")
            ->assertNoContent();
    }

    #[Test]
    public function a_group_lesson_never_picks_up_the_whole_class_summary(): void
    {
        [$class, $groups] = $this->classWithTwoGroups();

        $wholeClass = $this->lessonFor($class, null, '2026-10-14 14:10:00');
        $t1 = $this->lessonFor($class, $groups['T1'], '2026-10-16 08:30:00');

        $this->summaryFor($wholeClass, 'Aula da turma inteira.');

        $this->asTeacher()
            ->get("/lessons/{$t1->ulid}/previous-summary")
            ->assertNoContent();
    }

    #[Test]
    public function the_lesson_screen_and_the_week_both_say_the_group(): void
    {
        [$class, $groups] = $this->classWithTwoGroups();

        $lesson = $this->lessonFor($class, $groups['T1'], '2026-10-16 08:30:00');
        $wholeClass = $this->lessonFor($class, null, '2026-10-14 14:10:00');

        $props = $this->asTeacher()->get("/lessons/{$lesson->ulid}")->viewData('page')['props'];

        $this->assertSame('8.º F · T1', $props['lesson']['context_label']);
        $this->assertSame('T1', $props['lesson']['class_group_label']);

        $props = $this->asTeacher()->get("/lessons/{$wholeClass->ulid}")->viewData('page')['props'];

        $this->assertSame('8.º F', $props['lesson']['context_label']);
        $this->assertNull($props['lesson']['class_group_label']);
    }

    // ------------------------------------------------ sobreposições (§21)

    #[Test]
    public function two_different_groups_may_share_an_hour_but_nothing_else_may(): void
    {
        [$class, $groups] = $this->classWithTwoGroups();

        $wholeClass = $this->rawSlot($class, null, 5, '08:30', '09:20');
        $t1 = $this->rawSlot($class, $groups['T1'], 4, '08:30', '09:20');

        $conflicts = app(DetectSlotConflicts::class);

        // T1 + T2 à mesma hora: permitido, é o desdobramento.
        $this->assertSame(
            DetectSlotConflicts::STATUS_NEW,
            $conflicts->status([$t1], 4, '08:30', '09:20', $groups['T2']->id),
        );

        // T1 + T1 à mesma hora: já lá está.
        $this->assertSame(
            DetectSlotConflicts::STATUS_EXISTS,
            $conflicts->status([$t1], 4, '08:30', '09:20', $groups['T1']->id),
        );

        // Turma inteira + T1 à mesma hora: conflito nos dois sentidos.
        $this->assertSame(
            DetectSlotConflicts::STATUS_CONFLICT,
            $conflicts->status([$t1], 4, '08:30', '09:20', null),
        );
        $this->assertSame(
            DetectSlotConflicts::STATUS_CONFLICT,
            $conflicts->status([$wholeClass], 5, '08:30', '09:20', $groups['T1']->id),
        );

        // Turma inteira + turma inteira: o mesmo de sempre.
        $this->assertSame(
            DetectSlotConflicts::STATUS_EXISTS,
            $conflicts->status([$wholeClass], 5, '08:30', '09:20', null),
        );
        $this->assertSame(
            DetectSlotConflicts::STATUS_CONFLICT,
            $conflicts->status([$wholeClass], 5, '09:00', '09:50', null),
        );
    }

    #[Test]
    public function the_timetable_import_behaves_exactly_as_it_did(): void
    {
        [$class] = $this->classWithTwoGroups();
        $existing = $this->rawSlot($class, null, 3, '08:30', '09:20');

        $conflicts = app(DetectSlotConflicts::class);

        // Os dois chamadores da importação não passam grupo nenhum — v1 ainda
        // não sabe lê-los —, e o resultado é o de sempre.
        $this->assertSame(
            DetectSlotConflicts::STATUS_EXISTS,
            $conflicts->status([$existing], 3, '08:30', '09:20'),
        );
        $this->assertSame(
            DetectSlotConflicts::STATUS_CONFLICT,
            $conflicts->status([$existing], 3, '09:00', '09:50'),
        );
        $this->assertSame(
            DetectSlotConflicts::STATUS_NEW,
            $conflicts->status([$existing], 4, '08:30', '09:20'),
        );
    }

    // ------------------------------------------------------ o caso do 8.º F

    #[Test]
    public function the_real_world_case_of_the_eighth_f(): void
    {
        [$class, $groups] = $this->classWithTwoGroups();

        // Terça 14:10 e duas quartas: turma inteira. Sexta: T1 e T2 seguidos.
        $this->rawSlot($class, null, 2, '14:10', '15:00');
        $this->rawSlot($class, null, 3, '08:30', '09:20');
        $this->rawSlot($class, null, 3, '09:20', '10:10');
        $this->rawSlot($class, $groups['T1'], 5, '08:30', '09:20');
        $this->rawSlot($class, $groups['T2'], 5, '09:20', '10:10');

        // A semana de 12 a 18 de outubro de 2026 (segunda a domingo).
        $lessons = $this->materialize($class, '2026-10-12', '2026-10-18');

        // CINCO TEMPOS, cinco aulas: neste módulo uma «aula» é uma ocorrência
        // de um tempo do horário, e cada um dos dois tempos de sexta produz a
        // sua. Contado a partir do que o sistema faz, e não presumido.
        $this->assertCount(5, $lessons);

        // Os rótulos lidos DENTRO do tenant: `contextLabel()` percorre
        // `schoolClass` e `classGroup`, e o global scope da organização exige
        // um tenant resolvido para os carregar (§ ADR-0002).
        $this->assertSame(
            ['8.º F', '8.º F', '8.º F', '8.º F · T1', '8.º F · T2'],
            $this->inTenant(
                $this->organization,
                fn (): array => $lessons->map(fn (Lesson $lesson): string => $lesson->contextLabel())->all(),
            ),
        );

        // Sexta tem os dois grupos, em sequência.
        $friday = $lessons->filter(
            fn (Lesson $lesson): bool => $lesson->starts_at->toDateString() === '2026-10-16',
        )->values();

        $this->assertCount(2, $friday);
        $this->assertSame($groups['T1']->id, $friday[0]->class_group_id);
        $this->assertSame($groups['T2']->id, $friday[1]->class_group_id);
        $this->assertTrue($friday[0]->ends_at->lessThanOrEqualTo($friday[1]->starts_at));
    }

    // ------------------------------------------------------------- ajudantes

    /**
     * @return array{0: SchoolClass, 1: array<string, ClassGroup>}
     */
    protected function classWithTwoGroups(): array
    {
        $class = $this->schoolClassFor($this->teacher);

        $groups = $this->inTenant($this->organization, fn (): array => [
            'T1' => ClassGroup::create(['class_id' => $class->id, 'label' => 'T1', 'position' => 0]),
            'T2' => ClassGroup::create(['class_id' => $class->id, 'label' => 'T2', 'position' => 1]),
        ]);

        return [$class, $groups];
    }

    /**
     * @return array<string, mixed>
     */
    protected function slotPayload(
        SchoolClass $class,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        ?int $classGroupId,
    ): array {
        return [
            'class_id' => $class->id,
            'class_group_id' => $classGroupId,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'starts_on' => null,
            'ends_on' => null,
        ];
    }

    protected function rawSlot(
        SchoolClass $class,
        ?ClassGroup $group,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
    ): RecurringLessonSlot {
        return $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create([
            'class_id' => $class->id,
            'class_group_id' => $group?->id,
            'day_of_week' => $dayOfWeek,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'starts_on' => self::YEAR_STARTS_ON,
            'ends_on' => null,
        ]));
    }

    protected function lessonFor(SchoolClass $class, ?ClassGroup $group, string $startsAt): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::create([
            'class_id' => $class->id,
            'class_group_id' => $group?->id,
            'recurring_lesson_slot_id' => null,
            'starts_at' => $startsAt,
            'ends_at' => null,
            'status' => 'preparation',
            'created_by' => $this->teacher->id,
        ]));
    }

    protected function summaryFor(Lesson $lesson, string $content): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $lesson->id,
            'content' => $content,
        ]));
    }

    /**
     * @return Collection<int, Lesson>
     */
    protected function materialize(SchoolClass $class, string $from, string $to): Collection
    {
        return $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
            $class,
            CarbonImmutable::parse($from, self::TIMEZONE),
            CarbonImmutable::parse($to, self::TIMEZONE),
            $this->teacher,
        ));
    }
}
