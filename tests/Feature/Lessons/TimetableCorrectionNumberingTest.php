<?php

namespace Tests\Feature\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * Correções do horário (0.168.0) contra números de lição indicados pelo
 * professor (0.169.0), pelas rotas reais `lesson-slots`.
 *
 * A REGRA QUE ESTES CASOS PROTEGEM: um número indicado pelo professor conta como
 * CONTEÚDO. Uma aula com `lesson_number_manual = true` e sem sumário existe — é a
 * parceira T2 de uma lição fixada em T1, a que ficou depois de «Limpar sumário»,
 * a que recebeu o número numa ausência. Nenhuma correção do horário a apaga, e
 * nenhuma muda um número fixado (manual ou lecionado).
 *
 * Calendário de 2026 (relógio fixo na quarta-feira 14/10, ANTES de qualquer
 * dado): segundas 28/09, 05, 12, 19, 26/10 e 02, 09/11; terças 06, 13, 20, 27/10;
 * quartas 21 e 28/10; quintas 01, 08, 15, 22, 29/10.
 */
class TimetableCorrectionNumberingTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    private const TIMEZONE = 'Europe/Lisbon';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', self::TIMEZONE));
        $this->bootLessonFixtures();
    }

    // ------------------------------------------------ alterar horas, no lugar

    #[Test]
    public function an_in_place_hours_edit_keeps_a_mismatched_manual_lesson_without_summary_and_its_number(): void
    {
        // O histórico fica num tempo antigo; o tempo editado ainda não entrou em
        // vigor (por isso não pede versionamento) mas já tem aulas materializadas.
        $past = $this->slot(1, '09:30', '10:20', ['ends_on' => '2026-10-12']);
        $taught1 = $this->lesson($past, '2026-10-05 09:30:00', ['status' => LessonStatus::Taught, 'lesson_number' => 1]);
        $taught2 = $this->lesson($past, '2026-10-12 09:30:00', ['status' => LessonStatus::Taught, 'lesson_number' => 2]);
        $this->summary($taught1);
        $this->summary($taught2);

        $edited = $this->slot(3, '09:30', '10:20', ['starts_on' => '2026-10-28']);
        // Manual, SEM sumário (uma parceira T2, ou depois de «Limpar sumário»).
        $manual = $this->lesson($edited, '2026-10-21 09:30:00', ['lesson_number' => 11, 'lesson_number_manual' => true]);
        $empty = $this->lesson($edited, '2026-10-28 09:30:00');
        $this->renumber();
        $this->assertSame([1, 2, 11, 12], $this->numbers());

        $this->putSlot($edited, ['starts_at' => '10:30', 'ends_at' => '11:20', 'starts_on' => '2026-10-28'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($manual, $empty, $taught1, $taught2): void {
            // A manual desencontrada NÃO é apagada e mantém o número e a marca.
            $kept = Lesson::query()->findOrFail($manual->id);
            $this->assertSame([11, null, true], [$kept->lesson_number, $kept->lesson_number_end, $kept->lesson_number_manual]);
            // A vazia desencontrada sai (a materialização refá-la à hora certa).
            $this->assertNull(Lesson::query()->find($empty->id));
            // Nada lecionado mudou.
            $this->assertSame([1, 2], [$taught1->fresh()->lesson_number, $taught2->fresh()->lesson_number]);
        });

        // O aviso persistente do horário (0.168.0) lista-a, e pelo motivo certo.
        $this->assertScheduleNoticeNames('21/10');
    }

    #[Test]
    public function a_manual_lesson_without_summary_makes_the_slot_require_versioning(): void
    {
        $slot = $this->slot(1, '09:30', '10:20', ['starts_on' => '2026-09-14']);
        $manual = $this->lesson($slot, '2026-10-19 09:30:00', ['lesson_number' => 11, 'lesson_number_manual' => true]);

        $this->inTenant($this->organization, function () use ($slot): void {
            $this->assertTrue($slot->fresh()->hasRelevantPedagogicalHistory());
            $this->assertTrue($slot->fresh()->requiresVersioning(self::TIMEZONE));
            $this->assertTrue($slot->fresh()->hasLessonsCarryingContent());
        });

        // Sem a data de aplicação a edição é recusada: nada foi tocado.
        $this->putSlot($slot, ['starts_at' => '10:30', 'ends_at' => '11:20'])
            ->assertSessionHasErrors('effective_from');

        $this->inTenant($this->organization, function () use ($slot, $manual): void {
            $this->assertStringStartsWith('09:30', $slot->fresh()->starts_at);
            $this->assertSame(11, Lesson::query()->findOrFail($manual->id)->lesson_number);
        });
    }

    // --------------------------------------------- alterar horas, versionando

    #[Test]
    public function a_versioned_hours_change_keeps_a_prepared_manual_lesson_after_the_date_and_says_so(): void
    {
        $slot = $this->slot(1, '09:30', '10:20', ['starts_on' => '2026-09-14']);
        $taught = $this->lesson($slot, '2026-10-12 09:30:00', ['status' => LessonStatus::Taught, 'lesson_number' => 1]);
        $this->summary($taught);
        $manual = $this->lesson($slot, '2026-10-26 09:30:00', ['lesson_number' => 11, 'lesson_number_manual' => true]);
        $empty = $this->lesson($slot, '2026-11-02 09:30:00');
        $this->renumber();
        $this->assertSame([1, 11, 12], $this->numbers());

        $this->putSlot($slot, ['starts_at' => '10:30', 'ends_at' => '11:20', 'effective_from' => '2026-10-19'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data', fn ($flash): bool => str_contains($flash['toast']['message'] ?? '', 'foram mantidas')
                && ($flash['toast']['type'] ?? null) === 'success');

        $this->inTenant($this->organization, function () use ($slot, $taught, $manual, $empty): void {
            $this->assertSame('2026-10-18', $slot->fresh()->ends_on->toDateString());

            $kept = Lesson::query()->findOrFail($manual->id);
            $this->assertSame([11, true], [$kept->lesson_number, $kept->lesson_number_manual]);
            $this->assertSame($slot->id, $kept->recurring_lesson_slot_id);

            $this->assertNull(Lesson::query()->find($empty->id));
            $this->assertSame(1, $taught->fresh()->lesson_number);
        });

        $this->assertScheduleNoticeNames('26/10');
    }

    // ------------------------------------------------------------ mudar turma

    #[Test]
    public function changing_the_class_of_a_slot_with_a_manual_lesson_versions_and_each_class_numbers_itself(): void
    {
        $other = $this->otherClass();
        $slot = $this->slot(1, '09:30', '10:20', ['starts_on' => '2026-09-14']);
        // Nada mais carrega conteúdo: só o número indicado pelo professor.
        $manual = $this->lesson($slot, '2026-10-26 09:30:00', ['lesson_number' => 11, 'lesson_number_manual' => true]);
        $empty = $this->lesson($slot, '2026-11-02 09:30:00');
        $this->renumber();

        $this->putSlot($slot, ['class_id' => $other->id, 'effective_from' => '2026-10-19'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($slot, $manual, $empty, $other): void {
            // Versionou: o tempo antigo fechou-se e há um novo na turma de destino.
            $this->assertSame('2026-10-18', $slot->fresh()->ends_on->toDateString());
            $this->assertSame(2, RecurringLessonSlot::query()->count());
            $new = RecurringLessonSlot::query()->where('id', '!=', $slot->id)->sole();
            $this->assertSame($other->id, $new->class_id);

            // A manual ficou na turma de origem, com o número.
            $kept = Lesson::query()->findOrFail($manual->id);
            $this->assertSame([$this->schoolClass->id, 11, true], [$kept->class_id, $kept->lesson_number, $kept->lesson_number_manual]);
            // A vazia saiu.
            $this->assertNull(Lesson::query()->find($empty->id));
        });

        // A turma de destino numera-se sozinha, a começar em 1.
        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/lessons?week=2026-10-19')->assertOk();

        $this->inTenant($this->organization, function () use ($other, $manual): void {
            $destination = Lesson::query()->where('class_id', $other->id)->orderBy('starts_at')->get();
            $this->assertNotEmpty($destination);
            $this->assertSame(1, $destination->first()->lesson_number);
            $this->assertFalse($destination->contains(fn (Lesson $lesson): bool => $lesson->lesson_number_manual));
            $this->assertSame(11, Lesson::query()->findOrFail($manual->id)->lesson_number);
        });
    }

    // ---------------------------------------------------------- eliminar bloco

    #[Test]
    public function stopping_a_slot_from_a_date_keeps_manual_and_prepared_lessons_and_removes_the_empty_ones(): void
    {
        $slot = $this->slot(1, '09:30', '10:20', ['starts_on' => '2026-09-14']);
        $taught = $this->lesson($slot, '2026-10-12 09:30:00', ['status' => LessonStatus::Taught, 'lesson_number' => 1]);
        $this->summary($taught);
        $manual = $this->lesson($slot, '2026-10-26 09:30:00', ['lesson_number' => 11, 'lesson_number_manual' => true]);
        $prepared = $this->lesson($slot, '2026-11-02 09:30:00');
        $this->inTenant($this->organization, fn () => LessonPlan::create([
            'lesson_id' => $prepared->id, 'planned_summary' => 'Plano.', 'created_by' => $this->teacher->id,
        ]));
        $empty = $this->lesson($slot, '2026-11-09 09:30:00');
        $this->renumber();
        $this->assertSame([1, 11, 12, 13], $this->numbers());

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->delete("/lesson-slots/{$slot->ulid}", ['effective_from' => '2026-10-19'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data', fn ($flash): bool => str_contains($flash['toast']['message'] ?? '', 'foram mantidas'));

        $this->inTenant($this->organization, function () use ($taught, $manual, $prepared, $empty): void {
            $this->assertSame(1, $taught->fresh()->lesson_number);
            $this->assertSame([11, true], [$manual->fresh()->lesson_number, $manual->fresh()->lesson_number_manual]);
            $this->assertNotNull(Lesson::query()->find($prepared->id));
            $this->assertNull(Lesson::query()->find($empty->id));
        });
    }

    #[Test]
    public function the_group_partner_of_a_fixed_lesson_survives_stopping_its_slot(): void
    {
        $key = (string) Str::ulid();
        $t1 = $this->slot(1, '09:30', '10:20', ['class_group_id' => $this->makeGroup('T1')->id, 'split_lesson_key' => $key]);
        $t2 = $this->slot(2, '09:30', '10:20', ['class_group_id' => $this->makeGroup('T2')->id, 'split_lesson_key' => $key]);

        $t1Lesson = $this->lesson($t1, '2026-10-19 09:30:00', ['class_group_id' => $t1->class_group_id]);
        $t2Lesson = $this->lesson($t2, '2026-10-20 09:30:00', ['class_group_id' => $t2->class_group_id]);
        $t2Later = $this->lesson($t2, '2026-10-27 09:30:00', ['class_group_id' => $t2->class_group_id]);
        $this->renumber();
        // O professor fixa a lição em T1; a parceira T2 herda-a e NÃO tem sumário.
        $this->asTeacher()->from('/lessons')->put("/lessons/{$t1Lesson->ulid}/summary", [
            'content' => 'Texto.',
            'summary_version' => $this->summaryVersion($t1Lesson),
            'lesson_number' => 11,
        ])->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($t2Lesson): void {
            $this->assertSame([11, true], [$t2Lesson->fresh()->lesson_number, $t2Lesson->fresh()->lesson_number_manual]);
        });

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->delete("/lesson-slots/{$t2->ulid}", ['effective_from' => '2026-10-15'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($t1Lesson, $t2Lesson, $t2Later): void {
            // A parceira fixada fica (e com o número); a posterior, vazia e livre, sai.
            $this->assertSame(11, $t2Lesson->fresh()->lesson_number);
            $this->assertNull(Lesson::query()->find($t2Later->id));
            $this->assertSame(11, $t1Lesson->fresh()->lesson_number);
        });
    }

    // --------------------------------------------------------------- ausência

    #[Test]
    public function a_teacher_absence_on_a_manual_lesson_after_a_timetable_revision_passes_the_number_to_the_new_version(): void
    {
        $slot = $this->slot(1, '09:30', '10:20', ['starts_on' => '2026-09-14']);
        $taught = $this->lesson($slot, '2026-10-12 09:30:00', ['status' => LessonStatus::Taught, 'lesson_number' => 1]);
        $this->summary($taught);
        $manual = $this->lesson($slot, '2026-10-19 09:30:00', ['lesson_number' => 11, 'lesson_number_manual' => true]);
        $this->renumber();

        // A revisão do horário: a segunda-feira passa às 10:30 a partir de 26/10.
        $this->putSlot($slot, ['starts_at' => '10:30', 'ends_at' => '11:20', 'effective_from' => '2026-10-26'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->get('/lessons?week=2026-10-26')->assertOk();

        $next = $this->inTenant($this->organization, fn (): Lesson => Lesson::query()
            ->where('class_id', $this->schoolClass->id)->whereDate('starts_at', '2026-10-26')->sole());
        $this->assertSame(12, $next->lesson_number);

        $this->actingAs($this->teacher)->withSession($this->tenantSession())
            ->post("/lessons/{$manual->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($manual, $next, $taught): void {
            $absent = $manual->fresh();
            $this->assertSame(LessonOutcome::TeacherAbsent, $absent->outcome);
            $this->assertNull($absent->lesson_number);
            $this->assertFalse($absent->lesson_number_manual);

            // O número passa à aula seguinte — a da versão nova do tempo —, sem saltos.
            $this->assertSame([11, true], [$next->fresh()->lesson_number, $next->fresh()->lesson_number_manual]);
            $this->assertSame(1, $taught->fresh()->lesson_number);
        });
    }

    // ------------------------------------------- correção manual fora de ordem

    #[Test]
    public function a_class_with_an_out_of_order_manual_correction_still_accepts_a_timetable_edit(): void
    {
        $past = $this->slot(1, '09:30', '10:20', ['ends_on' => '2026-10-12']);
        // Manual 11 seguida de lecionadas 2..5: incoerência criada pelo professor.
        $manual = $this->lesson($past, '2026-09-28 09:30:00', ['status' => LessonStatus::Taught, 'lesson_number' => 11, 'lesson_number_manual' => true]);
        $taught = [];

        foreach (['2026-10-05' => 2, '2026-10-12' => 3] as $date => $number) {
            $taught[] = $this->lesson($past, "{$date} 09:30:00", ['status' => LessonStatus::Taught, 'lesson_number' => $number]);
        }

        // Um tempo sem história com uma aula vazia desencontrada depois delas.
        $edited = $this->slot(3, '09:30', '10:20');
        $empty = $this->lesson($edited, '2026-10-21 09:30:00', ['lesson_number' => 4]);

        $this->putSlot($edited, ['starts_at' => '10:30', 'ends_at' => '11:20'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($manual, $taught, $empty): void {
            $this->assertNull(Lesson::query()->find($empty->id));
            $this->assertSame([11, true], [$manual->fresh()->lesson_number, $manual->fresh()->lesson_number_manual]);
            $this->assertSame([2, 3], array_map(fn (Lesson $lesson): ?int => $lesson->fresh()->lesson_number, $taught));
        });
    }

    // ------------------------------------------------------- avisos (toast)

    #[Test]
    public function the_toast_says_when_the_suggested_number_of_lessons_with_a_summary_followed_the_timetable(): void
    {
        $past = $this->slot(1, '09:30', '10:20', ['ends_on' => '2026-10-12']);
        $taught = $this->lesson($past, '2026-10-12 09:30:00', ['status' => LessonStatus::Taught, 'lesson_number' => 1]);
        $this->summary($taught);

        // A aula vazia que sai, e depois dela uma preparada COM sumário.
        $edited = $this->slot(3, '09:30', '10:20');
        $empty = $this->lesson($edited, '2026-10-21 09:30:00');
        $withSummary = $this->lesson($this->slot(1, '14:30', '15:20', ['starts_on' => '2026-10-26']), '2026-10-26 14:30:00');
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $withSummary->id, 'content' => 'Já escrevi isto.']));
        $this->renumber();
        $this->assertSame([1, 2, 3], $this->numbers());

        $this->putSlot($edited, ['starts_at' => '10:30', 'ends_at' => '11:20'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data', fn ($flash): bool => str_contains(
                $flash['toast']['message'] ?? '',
                'A numeração sugerida de 1 aula com sumário foi atualizada para acompanhar o horário.',
            ));

        $this->inTenant($this->organization, function () use ($empty, $withSummary, $taught): void {
            $this->assertNull(Lesson::query()->find($empty->id));
            $this->assertSame(2, $withSummary->fresh()->lesson_number);
            $this->assertSame(1, $taught->fresh()->lesson_number);
        });
    }

    #[Test]
    public function fixed_numbers_never_enter_the_renumbering_report_of_a_timetable_edit(): void
    {
        $past = $this->slot(1, '09:30', '10:20', ['ends_on' => '2026-10-12']);
        $taught = $this->lesson($past, '2026-10-12 09:30:00', ['status' => LessonStatus::Taught, 'lesson_number' => 1]);
        $this->summary($taught);

        $edited = $this->slot(3, '09:30', '10:20');
        $this->lesson($edited, '2026-10-21 09:30:00');
        // Depois da aula vazia: um número indicado pelo professor, com sumário.
        $manual = $this->lesson($this->slot(1, '14:30', '15:20', ['starts_on' => '2026-10-26']), '2026-10-26 14:30:00', ['lesson_number' => 11, 'lesson_number_manual' => true]);
        $this->summary($manual);
        $this->renumber();

        $response = $this->putSlot($edited, ['starts_at' => '10:30', 'ends_at' => '11:20'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Nada a dizer sobre numeração: o 11 e o 1 não mudaram.
        $response->assertSessionMissing('inertia.flash_data.toast');
        $this->inTenant($this->organization, function () use ($manual, $taught): void {
            $this->assertSame([11, true], [$manual->fresh()->lesson_number, $manual->fresh()->lesson_number_manual]);
            $this->assertSame(1, $taught->fresh()->lesson_number);
        });
    }

    // ---------------------------------------------------------------- helpers

    private function tenantSession(): array
    {
        return ['organization_id' => $this->organization->id, 'academic_year_id' => $this->schoolClass->academic_year_id];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function slot(int $day, string $startsAt, string $endsAt, array $attributes = []): RecurringLessonSlot
    {
        return $this->makeSlot(['day_of_week' => $day, 'starts_at' => $startsAt, 'ends_at' => $endsAt, ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lesson(RecurringLessonSlot $slot, string $startsAt, array $attributes = []): Lesson
    {
        return $this->makeLesson([
            'class_id' => $slot->class_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => $startsAt,
            ...$attributes,
        ]);
    }

    private function summary(Lesson $lesson): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => 'Sumário lecionado.']));
    }

    private function renumber(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));
    }

    /** @return list<int|null> */
    private function numbers(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('class_id', $this->schoolClass->id)->orderBy('starts_at')->get()
            ->map(fn (Lesson $lesson): ?int => $lesson->lesson_number)->all());
    }

    private function otherClass(): SchoolClass
    {
        return $this->inTenant($this->organization, function (): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => AcademicYear::query()->findOrFail($this->schoolClass->academic_year_id)->id,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function putSlot(RecurringLessonSlot $slot, array $overrides = []): TestResponse
    {
        $current = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::query()->findOrFail($slot->id));

        return $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->put("/lesson-slots/{$slot->ulid}", [
                'class_id' => $current->class_id,
                'day_of_week' => $current->day_of_week,
                'starts_at' => substr($current->starts_at, 0, 5),
                'ends_at' => substr($current->ends_at, 0, 5),
                'starts_on' => $current->starts_on?->toDateString(),
                'ends_on' => $current->ends_on?->toDateString(),
                ...$overrides,
            ]);
    }

    /**
     * O aviso persistente que a última resposta deixou (`scheduleNotice`, 0.168.0)
     * nomeia a aula preservada e dá o número indicado como motivo possível —
     * uma aula pode ter ficado SÓ por ter número indicado pelo professor.
     */
    private function assertScheduleNoticeNames(string $date): void
    {
        $notice = (session('inertia.flash_data') ?? [])['scheduleNotice']['message'] ?? null;

        $this->assertIsString($notice);
        $this->assertStringContainsString($date, $notice);
        $this->assertStringContainsString('número da lição indicado pelo professor', $notice);
    }
}
