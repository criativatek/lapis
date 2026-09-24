<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\ReconcileLessonsWithSlotValidity;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Services\Lessons\WeeklyLessonsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * Caso real: o professor retirou "AE 8.º F Experiência" do Horário do
 * Professor. Na semana seguinte, a atividade continuou a aparecer em "Aulas e
 * Sumários" — "Por preparar" — apesar de já não existir no Horário.
 *
 * DIAGNÓSTICO: `LessonScheduleController::destroy()` lia `$hasLessons` FORA da
 * transação e sem bloqueio. Se outro separador materializasse a semana
 * (`LessonWeekController::index()` fá-lo automaticamente) entre essa leitura
 * e o `$recurringLessonSlot->delete()`, o hard delete corria na mesma — e a
 * FK `nullOnDelete` deixava a aula recém-nascida com
 * `recurring_lesson_slot_id = NULL`. Órfã, essa aula nunca mais era vista por
 * `ReconcileLessonsWithSlotValidity` (que só itera aulas de slots que ainda
 * existem) e continuava a ser pintada para sempre por `WeeklyLessonsQuery`
 * (que lê `lessons` sem qualquer join ao slot).
 *
 * Estes testes provam a consequência diretamente — uma aula com
 * `recurring_lesson_slot_id = NULL`, `origin = schedule`, futura e vazia — em
 * vez de tentar simular a corrida de threads, que o PHPUnit síncrono não
 * consegue reproduzir de forma limpa.
 */
class OrphanedLessonReconciliationTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    /**
     * A consequência direta do defeito: uma aula órfã (FK nula), de origem
     * `schedule`, futura e vazia, sobrevive a uma reconciliação da turma e
     * continua a ser devolvida por WeeklyLessonsQuery — exatamente o "Por
     * preparar" que o professor via depois de apagar o tempo do horário.
     */
    #[Test]
    public function an_orphaned_future_empty_schedule_lesson_is_never_touched_before_the_fix_demonstration(): void
    {
        $orphan = $this->makeLesson([
            'recurring_lesson_slot_id' => null,
            'origin' => 'schedule',
            'starts_at' => '2026-09-25 12:20:00',
        ]);

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        // ANTES da correção do Passo 2.3, isto falha: a órfã fica.
        $this->assertDatabaseMissing('lessons', ['id' => $orphan->id]);
    }

    /** A mesma órfã não devia continuar a aparecer no cartão semanal. */
    #[Test]
    public function an_orphaned_lesson_stops_appearing_in_the_weekly_query_after_reconciliation(): void
    {
        $orphan = $this->makeLesson([
            'recurring_lesson_slot_id' => null,
            'origin' => 'schedule',
            'starts_at' => '2026-09-25 12:20:00',
        ]);

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $rows = $this->inTenant($this->organization, fn () => app(WeeklyLessonsQuery::class)->for(
            $this->teacher,
            $this->schoolClass->academicYear()->firstOrFail(),
            CarbonImmutable::parse('2026-09-21', 'Europe/Lisbon'),
        ));

        $this->assertEmpty(array_filter($rows, fn (array $row): bool => $row['ulid'] === $orphan->ulid));
    }

    /** Uma aula de outro slot, no mesmo dia/hora, não pode ser afetada. */
    #[Test]
    public function reconciliation_never_touches_a_lesson_belonging_to_a_different_slot(): void
    {
        $otherSlot = $this->makeSlot(['day_of_week' => 5, 'starts_at' => '12:20', 'ends_at' => '13:10']);
        $untouched = $this->makeLesson([
            'recurring_lesson_slot_id' => $otherSlot->id,
            'origin' => 'schedule',
            'starts_at' => '2026-09-25 12:20:00',
        ]);

        $orphan = $this->makeLesson([
            'recurring_lesson_slot_id' => null,
            'origin' => 'schedule',
            'starts_at' => '2026-09-25 13:30:00',
        ]);

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->assertDatabaseHas('lessons', ['id' => $untouched->id]);
        $this->assertDatabaseMissing('lessons', ['id' => $orphan->id]);
    }

    /**
     * A CORRIDA FECHADA: destroy() já não pode hard-deletar um slot cujo
     * `hasLessons` tenha sido lido antes do bloqueio. Simula-se a corrida
     * criando a aula ENTRE a chamada e nada mais — a leitura de `$hasLessons`
     * já corre dentro da transação, depois do bloqueio, pelo que uma aula
     * criada por outro pedido imediatamente antes de destroy() correr é vista.
     */
    #[Test]
    public function destroy_sees_a_lesson_materialized_just_before_it_runs_and_closes_instead_of_hard_deleting(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 5, 'starts_at' => '12:20', 'ends_at' => '13:10']);

        // A aula "chega" mesmo antes do pedido de destroy() — o cenário que
        // antes escapava porque `$hasLessons` era lido fora da transação.
        $justMaterialized = $this->makeLesson([
            'recurring_lesson_slot_id' => $slot->id,
            'origin' => 'schedule',
            'starts_at' => '2026-09-25 12:20:00',
        ]);

        $this->asTeacher()->delete("/lesson-slots/{$slot->ulid}")->assertRedirect();

        // O QUE IMPORTA: o slot é FECHADO, nunca hard-deletado, pelo que
        // nenhuma aula sua fica órfã. A aula futura e vazia desaparece — mas
        // pela reconciliação, que sabe o que está a apagar, e não por um
        // `nullOnDelete` que a deixaria para sempre invisível ao horário.
        $this->inTenant($this->organization, function () use ($slot, $justMaterialized): void {
            $slot->refresh();
            $this->assertNotNull($slot->ends_on, 'O slot foi apagado em vez de fechado.');

            $this->assertSame(
                0,
                Lesson::query()
                    ->where('class_id', $this->schoolClass->id)
                    ->whereNull('recurring_lesson_slot_id')
                    ->count(),
                'O destroy() deixou uma aula órfã para trás.',
            );
            $this->assertDatabaseMissing('lessons', ['id' => $justMaterialized->id]);
        });
    }

    /** Preservação: aula órfã com sumário nunca é apagada pela reconciliação. */
    #[Test]
    public function an_orphaned_lesson_with_a_summary_is_preserved(): void
    {
        $orphan = $this->makeLesson([
            'recurring_lesson_slot_id' => null,
            'origin' => 'schedule',
            'starts_at' => '2026-09-25 12:20:00',
        ]);
        $this->inTenant($this->organization, fn () => $orphan->summary()->create(['content' => 'Revisões.']));

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->assertDatabaseHas('lessons', ['id' => $orphan->id]);
    }

    /** Preservação: aula órfã com faltas registadas nunca é apagada. */
    #[Test]
    public function an_orphaned_lesson_with_attendance_recorded_is_preserved(): void
    {
        $orphan = $this->makeLesson([
            'recurring_lesson_slot_id' => null,
            'origin' => 'schedule',
            'starts_at' => '2026-09-25 12:20:00',
            'attendance_recorded_at' => CarbonImmutable::parse('2026-09-20 10:00:00'),
        ]);

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->assertDatabaseHas('lessons', ['id' => $orphan->id]);
    }

    /** Preservação: aula órfã com preparação/plano guardado nunca é apagada. */
    #[Test]
    public function an_orphaned_lesson_with_a_saved_plan_is_preserved(): void
    {
        $orphan = $this->makeLesson([
            'recurring_lesson_slot_id' => null,
            'origin' => 'schedule',
            'starts_at' => '2026-09-25 12:20:00',
        ]);
        $this->inTenant($this->organization, fn () => $orphan->plan()->create(['planned_summary' => 'Plano.', 'created_by' => $this->teacher->id]));

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->assertDatabaseHas('lessons', ['id' => $orphan->id]);
    }

    /** Preservação: aula órfã lecionada nunca é apagada. */
    #[Test]
    public function an_orphaned_lesson_already_taught_is_preserved(): void
    {
        $orphan = $this->makeLesson([
            'recurring_lesson_slot_id' => null,
            'origin' => 'schedule',
            'starts_at' => '2026-09-18 12:20:00',
            'status' => LessonStatus::Taught,
            'outcome' => LessonOutcome::Taught,
        ]);

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->assertDatabaseHas('lessons', ['id' => $orphan->id]);
    }

    /** Preservação: uma aula manual (origin=manual), órfã ou não, fica sempre. */
    #[Test]
    public function a_manual_orphaned_lesson_is_always_preserved(): void
    {
        $manual = $this->makeLesson([
            'recurring_lesson_slot_id' => null,
            'origin' => 'manual',
            'starts_at' => '2026-09-25 12:20:00',
        ]);

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->assertDatabaseHas('lessons', ['id' => $manual->id]);
    }

    /** Preservação: uma órfã PASSADA (histórica) não é apagada só por estar sem slot. */
    #[Test]
    public function a_past_orphaned_lesson_is_preserved_even_when_empty(): void
    {
        $orphan = $this->makeLesson([
            'recurring_lesson_slot_id' => null,
            'origin' => 'schedule',
            'starts_at' => '2026-09-14 12:20:00',
        ]);

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->assertDatabaseHas('lessons', ['id' => $orphan->id]);
    }

    /** Navegar/reabrir a semana não ressuscita a órfã que a reconciliação removeu. */
    #[Test]
    public function reopening_the_week_does_not_resurrect_the_removed_orphan(): void
    {
        // SEM slot nenhum a esta hora: é exatamente o cenário do professor —
        // o tempo já não existe no horário, só ficou a aula.
        $orphan = $this->makeLesson([
            'recurring_lesson_slot_id' => null,
            'origin' => 'schedule',
            'starts_at' => '2026-09-25 12:20:00',
        ]);

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );
        $this->assertDatabaseMissing('lessons', ['id' => $orphan->id]);

        $this->asTeacher()->get('/lessons?week=2026-09-21')
            ->assertOk();

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(
                0,
                Lesson::query()
                    ->where('class_id', $this->schoolClass->id)
                    ->whereDate('starts_at', '2026-09-25')
                    ->whereTime('starts_at', '12:20:00')
                    ->count(),
                'A materialização automática ao reabrir a semana recriou a aula removida.',
            );
        });
    }
}
