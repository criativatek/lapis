<?php

namespace Tests\Feature\Lessons;

use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * §48 — marcar aulas como lecionadas em lote, pelos quatro modos, sem alterações
 * parciais silenciosas.
 *
 * O relógio é congelado numa quinta-feira (08/10/2026) para que «Hoje» e «Semana
 * apresentada» sejam perguntas com resposta estável — sem isso, estes testes
 * passariam a falhar sozinhos consoante o dia em que corressem, como já
 * aconteceu com LessonScheduleTest (0.136.1).
 */
class BatchTaughtTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 11:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_today_mode_marks_only_the_real_current_day(): void
    {
        $today = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00']);
        $otherDay = $this->makeLesson(['starts_at' => '2026-10-07 09:30:00', 'ends_at' => '2026-10-07 10:20:00']);

        $this->asTeacher()->post('/lessons/batch-taught', ['mode' => 'today'])->assertRedirect();

        $this->inTenant($this->organization, function () use ($today, $otherDay): void {
            $this->assertSame(LessonStatus::Taught, $today->refresh()->status);
            $this->assertSame(LessonStatus::Preparation, $otherDay->refresh()->status);
        });
    }

    /** §31: «Semana apresentada» segue a vista, e não o dia de hoje. */
    #[Test]
    public function the_week_mode_follows_the_displayed_week(): void
    {
        $inPreviousWeek = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'ends_at' => '2026-10-01 10:20:00']);
        $inCurrentWeek = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00']);

        $this->asTeacher()
            ->post('/lessons/batch-taught', ['mode' => 'week', 'week' => '2026-09-28'])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($inPreviousWeek, $inCurrentWeek): void {
            $this->assertSame(LessonStatus::Taught, $inPreviousWeek->refresh()->status);
            $this->assertSame(LessonStatus::Preparation, $inCurrentWeek->refresh()->status);
        });
    }

    #[Test]
    public function the_selection_mode_marks_exactly_the_chosen_lessons(): void
    {
        $chosen = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00']);
        $untouched = $this->makeLesson(['starts_at' => '2026-10-08 11:00:00', 'ends_at' => '2026-10-08 11:50:00']);

        $this->asTeacher()
            ->post('/lessons/batch-taught', ['mode' => 'selection', 'ulids' => [$chosen->ulid]])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($chosen, $untouched): void {
            $this->assertSame(LessonStatus::Taught, $chosen->refresh()->status);
            $this->assertSame(LessonStatus::Preparation, $untouched->refresh()->status);
        });
    }

    #[Test]
    public function the_range_mode_marks_everything_inside_it_and_nothing_outside(): void
    {
        $before = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'ends_at' => '2026-10-01 10:20:00']);
        $inside = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00']);
        $after = $this->makeLesson(['starts_at' => '2026-10-22 09:30:00', 'ends_at' => '2026-10-22 10:20:00']);

        $this->asTeacher()
            ->post('/lessons/batch-taught', ['mode' => 'range', 'from' => '2026-10-05', 'to' => '2026-10-15'])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($before, $inside, $after): void {
            $this->assertSame(LessonStatus::Preparation, $before->refresh()->status);
            $this->assertSame(LessonStatus::Taught, $inside->refresh()->status);
            $this->assertSame(LessonStatus::Preparation, $after->refresh()->status);
        });
    }

    #[Test]
    public function an_over_long_range_is_refused(): void
    {
        $this->asTeacher()
            ->from('/lessons')
            ->post('/lessons/batch-taught', ['mode' => 'range', 'from' => '2026-09-01', 'to' => '2027-06-30'])
            ->assertSessionHasErrors('to');
    }

    /** §34: o que não é elegível não é tocado, e o motivo é devolvido. */
    #[Test]
    public function already_taught_lessons_are_reported_as_ineligible_and_left_alone(): void
    {
        $this->makeLesson([
            'starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00',
            'status' => LessonStatus::Taught,
        ]);
        $this->makeLesson(['starts_at' => '2026-10-08 11:00:00', 'ends_at' => '2026-10-08 11:50:00']);

        $this->asTeacher()
            ->postJson('/lessons/batch-taught/preview', ['mode' => 'today'])
            ->assertOk()
            ->assertJsonPath('found', 2)
            ->assertJsonPath('eligible', 1)
            ->assertJsonCount(1, 'ineligible');
    }

    /** §37: T1 e T2 são ocorrências reais distintas — nenhuma se funde na outra. */
    #[Test]
    public function lessons_of_different_groups_are_marked_separately(): void
    {
        $first = $this->makeGroup('T1');
        $second = $this->makeGroup('T2');
        $t1 = $this->makeLesson(['class_group_id' => $first->id, 'starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00']);
        $t2 = $this->makeLesson(['class_group_id' => $second->id, 'starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00']);

        $this->asTeacher()->post('/lessons/batch-taught', ['mode' => 'today'])->assertRedirect();

        $this->inTenant($this->organization, function () use ($t1, $t2): void {
            $this->assertSame(LessonStatus::Taught, $t1->refresh()->status);
            $this->assertSame(LessonStatus::Taught, $t2->refresh()->status);
            $this->assertSame(2, Lesson::query()->count());
        });
    }

    /** §48-K: um ulid de outro professor numa seleção não passa. */
    #[Test]
    public function a_lesson_of_another_teacher_cannot_be_smuggled_into_the_selection(): void
    {
        $mine = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00']);

        $otherTeacher = User::factory()->create();
        $foreign = $this->inTenant($this->organization, function () use ($otherTeacher): Lesson {
            $class = SchoolClass::factory()
                ->recycle($this->organization)
                ->create(['academic_year_id' => $this->schoolClass->academic_year_id]);
            $class->teachers()->attach($otherTeacher, ['role' => 'owner']);

            return Lesson::create([
                'class_id' => $class->id,
                'starts_at' => '2026-10-08 09:30:00',
                'ends_at' => '2026-10-08 10:20:00',
                'status' => LessonStatus::Preparation,
                'created_by' => $otherTeacher->id,
            ]);
        });

        $this->asTeacher()
            ->post('/lessons/batch-taught', [
                'mode' => 'selection',
                'ulids' => [$mine->ulid, $foreign->ulid],
            ])
            ->assertRedirect();

        $this->inTenant($this->organization, function () use ($mine, $foreign): void {
            $this->assertSame(LessonStatus::Taught, $mine->refresh()->status);
            $this->assertSame(LessonStatus::Preparation, $foreign->refresh()->status);
        });
    }

    #[Test]
    public function a_batch_with_nothing_eligible_reports_it_instead_of_succeeding_silently(): void
    {
        $this->makeLesson([
            'starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00',
            'status' => LessonStatus::Taught,
        ]);

        $this->asTeacher()
            ->from('/lessons')
            ->post('/lessons/batch-taught', ['mode' => 'today'])
            ->assertSessionHasErrors('batch');
    }

    #[Test]
    public function impersonation_blocks_the_batch(): void
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00']);

        $this->actingAs($this->teacher)
            ->withSession([
                'organization_id' => $this->organization->id,
                'impersonator_id' => 999,
            ])
            ->post('/lessons/batch-taught', ['mode' => 'today'])
            ->assertForbidden();

        $this->inTenant($this->organization, fn () => $this->assertSame(
            LessonStatus::Preparation,
            $lesson->refresh()->status,
        ));
    }
}
