<?php

namespace Tests\Feature\Lessons;

use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * A contenção da recuperação da 0.169.0 (`LAPIS_LESSON_NUMBERING_FROZEN`,
 * docs/lesson-numbering-recovery.md): com ela ligada nenhum número de lição que
 * já exista muda — nem pelo professor, nem pela renumeração automática, nem pela
 * passagem do número numa ausência. As aulas novas (sem número) recebem a
 * sugestão, e o sumário grava-se na mesma.
 *
 * Calendário de 2026: o tempo é às quintas às 09:30 (08, 15, 22 e 29/10).
 */
class LessonNumberingFreezeTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    private const FROZEN_MESSAGE = 'A numeração das lições está temporariamente bloqueada. Pode guardar o sumário sem alterar o número.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        $this->makeSlot();
    }

    #[Test]
    public function the_teacher_cannot_set_or_reset_a_number_but_can_still_save_the_summary(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $first = $this->on('2026-10-08');
        config(['lapis.lesson_numbering_frozen' => true]);
        $before = DB::table('lessons')->orderBy('id')->get(['id', 'lesson_number', 'lesson_number_end', 'lesson_number_manual'])->toJson();

        // A pré-visualização devolve o mesmo 422.
        $this->asTeacher()->postJson("/lessons/{$first->ulid}/number/preview", ['mode' => 'manual', 'start' => 11])
            ->assertStatus(422)->assertJsonValidationErrors('lesson_number');
        $this->assertSame(self::FROZEN_MESSAGE, $this->asTeacher()->postJson("/lessons/{$first->ulid}/number/preview", ['mode' => 'automatic'])->json('errors.lesson_number.0'));

        // Gravar com número: recusado, e o sumário não é gravado.
        $this->save($first, ['lesson_number' => 11])->assertSessionHasErrors(['lesson_number' => self::FROZEN_MESSAGE]);
        $this->inTenant($this->organization, fn () => $this->assertNull($first->fresh()->summary));

        // Gravar o sumário sem tocar no número: funciona.
        $this->save($first)->assertSessionHasNoErrors();
        $this->inTenant($this->organization, fn () => $this->assertSame('Texto do sumário.', $first->fresh()->summary->content));

        $this->assertSame($before, DB::table('lessons')->orderBy('id')->get(['id', 'lesson_number', 'lesson_number_end', 'lesson_number_manual'])->toJson());

        // O ecrã sabe: o campo aparece só de leitura.
        $this->asTeacher()->get("/lessons/{$first->ulid}")
            ->assertInertia(fn ($page) => $page->where('lesson.numbering_frozen', true)->etc());
    }

    #[Test]
    public function the_screens_say_nothing_is_frozen_by_default(): void
    {
        $this->weeks('2026-10-08');

        $this->asTeacher()->get("/lessons/{$this->on('2026-10-08')->ulid}")
            ->assertOk()->assertInertia(fn ($page) => $page->where('lesson.numbering_frozen', false)->etc());
    }

    #[Test]
    public function new_lessons_get_the_suggestion_but_numbered_lessons_are_never_rewritten(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $this->save($this->on('2026-10-08'), ['lesson_number' => 11])->assertSessionHasNoErrors();
        // Um número gravado que a derivação não daria (o que o código antigo
        // deixaria por baixo): com a contenção ligada fica como está.
        DB::table('lessons')->where('id', $this->on('2026-10-15')->id)->update(['lesson_number' => 99]);

        config(['lapis.lesson_numbering_frozen' => true]);
        $this->weeks('2026-10-22', '2026-10-29');

        $this->assertSame(
            ['2026-10-08' => '11', '2026-10-15' => '99', '2026-10-22' => '13', '2026-10-29' => '14'],
            $this->numbers(),
        );
    }

    #[Test]
    public function the_automatic_renumbering_paths_do_not_touch_a_numbered_lesson(): void
    {
        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22');
        DB::table('lessons')->where('id', $this->on('2026-10-22')->id)->update(['lesson_number' => 40]);
        config(['lapis.lesson_numbering_frozen' => true]);

        $this->inTenant($this->organization, function (): void {
            $numbering = app(LessonNumbering::class);
            $classId = (int) $this->schoolClass->id;

            $this->assertSame([], $numbering->resequence($classId));
            $this->assertSame([], $numbering->numberMaterializedLessons($classId));
            $this->assertSame([], $numbering->previewResequence($classId));
            $this->assertSame([], $numbering->rebuild($classId)['numbers']);
        });

        $this->assertSame(['2026-10-08' => '1', '2026-10-15' => '2', '2026-10-22' => '40'], $this->numbers());
    }

    #[Test]
    public function a_teacher_absence_does_not_hand_the_manual_number_over_while_frozen(): void
    {
        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22');
        $this->save($this->on('2026-10-08'), ['lesson_number' => 11])->assertSessionHasNoErrors();
        $this->assertSame(['2026-10-08' => '11', '2026-10-15' => '12', '2026-10-22' => '13'], $this->numbers());

        config(['lapis.lesson_numbering_frozen' => true]);

        $this->asTeacher()
            ->post("/lessons/{$this->on('2026-10-08')->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasNoErrors();

        // A ausente fica sem número (regra da ausência); as outras não mudam.
        $this->assertSame(['2026-10-08' => null, '2026-10-15' => '12', '2026-10-22' => '13'], $this->numbers());
        $this->inTenant($this->organization, function (): void {
            $absent = Lesson::query()->where('outcome', LessonOutcome::TeacherAbsent->value)->sole();
            $this->assertFalse($absent->lesson_number_manual);
            $this->assertFalse(Lesson::query()->where('lesson_number_manual', true)->exists());
            $event = AuditEvent::query()->where('event', 'lesson.outcome_recorded')->latest('id')->firstOrFail();
            $this->assertFalse($event->properties['manual_number_handed_over']);
        });
    }

    #[Test]
    public function restoring_a_backup_is_not_blocked_by_the_freeze(): void
    {
        // Os restauros copiam o triplo tal como está (WriteLessons), não derivam:
        // a contenção não os toca. Aqui, só a prova de que o interruptor não é
        // lido pelo escritor do restauro.
        $this->assertStringNotContainsString(
            'LessonNumberingFreeze',
            (string) file_get_contents(base_path('app/Actions/DataImports/WriteLessons.php')),
        );
        $this->assertStringNotContainsString(
            'LessonNumberingFreeze',
            (string) file_get_contents(base_path('app/Actions/DataImports/ExecuteDataImport.php')),
        );
    }

    private function weeks(string ...$dates): void
    {
        foreach ($dates as $date) {
            $this->asTeacher()
                ->post("/classes/{$this->schoolClass->ulid}/lessons/materialize", ['from' => $date, 'to' => $date])
                ->assertRedirect();
        }
    }

    private function on(string $date): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::query()->whereDate('starts_at', $date)->where('class_id', $this->schoolClass->id)->sole());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function save(Lesson $lesson, array $extra = []): TestResponse
    {
        return $this->asTeacher()->from('/lessons')->put("/lessons/{$lesson->ulid}/summary", [
            'content' => 'Texto do sumário.',
            'summary_version' => $this->summaryVersion($lesson),
        ] + $extra);
    }

    /** @return array<string, string|null> */
    private function numbers(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('class_id', $this->schoolClass->id)->orderBy('starts_at')->get()
            ->mapWithKeys(fn (Lesson $lesson): array => [$lesson->starts_at->format('Y-m-d') => $lesson->lessonNumberLabel()])
            ->all());
    }
}
