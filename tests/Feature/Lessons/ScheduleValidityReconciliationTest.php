<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\ReconcileLessonsWithSlotValidity;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.146.2 — a vigência de um tempo do horário manda também nas aulas que ele
 * já produziu. Editar `starts_on`/`ends_on` deixava para trás uma ocorrência
 * stale (§13.3 do runbook); estes testes cobrem A–L do plano de hotfix.
 *
 * Caso real: 8F-AP, sexta 12:20–13:10, `starts_on` passa a 21/09 depois de
 * 18/09 já ter sido materializada — a "Lição 2" que não devia continuar a
 * existir.
 */
class ScheduleValidityReconciliationTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    /**
     * As datas deste ficheiro são absolutas (14–27/09/2026) e foram escritas
     * contra o relógio de então. O código de produção compara-as com "hoje"
     * — o `ends_on` que trava um tempo é `now()->subDay()` —, pelo que o
     * resultado dependia do dia em que a suite corresse: a 22/09/2026 esse
     * `ends_on` passou a cair exactamente sobre 21/09 e a suite ficou
     * vermelha sem que nada no produto tivesse mudado. Fixar o relógio no dia
     * para que os casos foram escritos torna-os determinísticos, e é aqui o
     * único ponto por onde o tempo entra.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
    }

    /** A) + F) — 8F-AP: starts_on introduzido depois de Lessons futuras já existirem. */
    #[Test]
    public function support_class_removes_the_prepared_lesson_before_the_new_starts_on(): void
    {
        $support = $this->inTenant($this->organization, function (): SchoolClass {
            $support = SchoolClass::factory()
                ->recycle($this->organization)
                ->support()
                ->create(['academic_year_id' => $this->schoolClass->academic_year_id]);
            $support->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $support;
        });

        $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create([
            'class_id' => $support->id,
            'day_of_week' => 5,
            'starts_at' => '12:20',
            'ends_at' => '13:10',
        ]));

        $stale = $this->makeLesson(['class_id' => $support->id, 'recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-18 12:20:00', 'ends_at' => '2026-09-18 13:10:00']);
        $kept = $this->makeLesson(['class_id' => $support->id, 'recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-25 12:20:00', 'ends_at' => '2026-09-25 13:10:00']);

        $this->inTenant($this->organization, function () use ($support): void {
            app(LessonNumbering::class)->resequence($support->id);
        });

        $this->asTeacher()
            ->put("/lesson-slots/{$slot->ulid}", [
                'class_id' => $support->id,
                'day_of_week' => 5,
                'starts_at' => '12:20',
                'ends_at' => '13:10',
                'starts_on' => '2026-09-21',
                'ends_on' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('lessons', ['id' => $stale->id]);
        $this->inTenant($this->organization, function () use ($kept): void {
            $kept->refresh();
            $this->assertSame(1, $kept->lesson_number);
        });
    }

    /** B) ends_on encurtado — aula futura fora do novo fim desaparece. */
    #[Test]
    public function shortening_ends_on_removes_the_lesson_left_outside(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 5, 'starts_on' => '2026-06-01']);

        $kept = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-06-05 09:30:00']);
        $stale1 = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-06-12 09:30:00']);
        $stale2 = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-06-19 09:30:00']);

        $this->asTeacher()
            ->put("/lesson-slots/{$slot->ulid}", [
                'class_id' => $this->schoolClass->id,
                'day_of_week' => 5,
                'starts_at' => '09:30',
                'ends_at' => '10:20',
                'starts_on' => '2026-06-01',
                'ends_on' => '2026-06-11',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('lessons', ['id' => $kept->id]);
        $this->assertDatabaseMissing('lessons', ['id' => $stale1->id]);
        $this->assertDatabaseMissing('lessons', ['id' => $stale2->id]);
    }

    /**
     * C) + D) — prepared fora da vigência é removida; finalizada é preservada.
     *
     * Testado ao nível da Action, não do controlador: uma aula lecionada dá
     * ao tempo `hasRelevantPedagogicalHistory()` e a edição do controlador
     * passaria pelo ramo versionado (ReviseRecurringLessonSlot cria uma linha
     * nova); a regra de reconciliação em si — «o que sai, o que fica» — é a
     * mesma nos dois ramos, e é ela que está a ser verificada aqui.
     */
    #[Test]
    public function a_finalized_lesson_outside_the_new_validity_is_preserved(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 5]);

        $taught = $this->makeLesson([
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => '2026-09-18 09:30:00',
            'status' => LessonStatus::Taught,
            'outcome' => LessonOutcome::Taught,
        ]);
        $prepared = $this->makeLesson([
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => '2026-09-25 09:30:00',
        ]);

        $this->inTenant($this->organization, function () use ($slot): void {
            $slot->update(['starts_on' => '2026-10-01']);
        });

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        // Lecionada: histórico intocável.
        $this->assertDatabaseHas('lessons', ['id' => $taught->id]);
        // Preparada, fora da vigência: removida.
        $this->assertDatabaseMissing('lessons', ['id' => $prepared->id]);
    }

    /** Sumário, plano ou faltas contam como conteúdo pedagógico e não se perdem. */
    #[Test]
    public function a_prepared_lesson_with_a_summary_is_preserved(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 5]);
        $lesson = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-18 09:30:00']);
        $this->inTenant($this->organization, fn () => $lesson->summary()->create(['content' => 'Revisões.']));

        $this->inTenant($this->organization, function () use ($slot): void {
            $slot->update(['starts_on' => '2026-10-01']);
        });

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->assertDatabaseHas('lessons', ['id' => $lesson->id]);
    }

    /** Removida por fora de vigência não cria um cancelamento artificial. */
    #[Test]
    public function removing_a_stale_lesson_does_not_create_a_cancelled_occurrence(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 5]);
        $lesson = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-18 09:30:00']);

        $this->inTenant($this->organization, function () use ($slot): void {
            $slot->update(['starts_on' => '2026-09-21']);
        });

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->assertDatabaseMissing('lessons', ['id' => $lesson->id]);
        $this->assertDatabaseMissing('cancelled_lesson_occurrences', [
            'recurring_lesson_slot_id' => $slot->id,
        ]);
    }

    /** E) + J) renumeração e idempotência: reexecutar não muda nada nem falha. */
    #[Test]
    public function reconciliation_is_idempotent_and_renumbers(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 5]);
        $stale = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-18 09:30:00']);
        $kept = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-25 09:30:00']);

        $this->inTenant($this->organization, function () use ($slot): void {
            $slot->update(['starts_on' => '2026-09-21']);
        });

        $first = $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );
        $second = $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->assertSame(1, $first['removed']);
        $this->assertSame(0, $second['removed']);
        $this->assertDatabaseMissing('lessons', ['id' => $stale->id]);
        $this->inTenant($this->organization, function () use ($kept): void {
            $this->assertSame(1, $kept->refresh()->lesson_number);
        });
    }

    /** K) a materialização seguinte não recria a aula fora da vigência. */
    #[Test]
    public function materialization_after_reconciliation_does_not_recreate_the_stale_lesson(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 5, 'starts_on' => '2026-09-21']);
        $stale = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-18 09:30:00']);

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->asTeacher()->post("/classes/{$this->schoolClass->ulid}/lessons/materialize", [
            'from' => '2026-09-14',
            'to' => '2026-09-20',
        ])->assertRedirect();

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(0, Lesson::query()->whereDate('starts_at', '2026-09-18')->count());
        });
    }

    /** T1/T2: metade do par fora da vigência não quebra a equivalência. */
    #[Test]
    public function half_of_a_split_pair_outside_validity_preserves_numbering_invariants(): void
    {
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');
        $key = (string) Str::ulid();

        $t1Slot = $this->makeSlot(['class_group_id' => $t1->id, 'day_of_week' => 1, 'split_lesson_key' => $key]);
        $t2Slot = $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 2, 'split_lesson_key' => $key]);

        $t1a = $this->makeLesson(['recurring_lesson_slot_id' => $t1Slot->id, 'class_group_id' => $t1->id, 'starts_at' => '2026-09-14 09:30:00']);
        $t2a = $this->makeLesson(['recurring_lesson_slot_id' => $t2Slot->id, 'class_group_id' => $t2->id, 'starts_at' => '2026-09-15 09:30:00']);
        $t1b = $this->makeLesson(['recurring_lesson_slot_id' => $t1Slot->id, 'class_group_id' => $t1->id, 'starts_at' => '2026-09-21 09:30:00']);
        $t2b = $this->makeLesson(['recurring_lesson_slot_id' => $t2Slot->id, 'class_group_id' => $t2->id, 'starts_at' => '2026-09-22 09:30:00']);

        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence($this->schoolClass->id));

        // Só o T1 muda de vigência — a aula T1a fica de fora, T2a fica.
        $this->asTeacher()
            ->put("/lesson-slots/{$t1Slot->ulid}", [
                'class_id' => $this->schoolClass->id,
                'class_group_id' => $t1->id,
                'day_of_week' => 1,
                'starts_at' => '09:30',
                'ends_at' => '10:20',
                'starts_on' => '2026-09-21',
                'ends_on' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('lessons', ['id' => $t1a->id]);
        // T2a perde o par mas continua a existir — não é uma aula de T1.
        $this->assertDatabaseHas('lessons', ['id' => $t2a->id]);
        $this->assertDatabaseHas('lessons', ['id' => $t1b->id]);
        $this->assertDatabaseHas('lessons', ['id' => $t2b->id]);
    }

    /** L) "Atualizar aulas desta semana" não recria stale occurrences. */
    #[Test]
    public function update_week_does_not_recreate_stale_occurrences(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 1, 'starts_on' => '2026-09-21']);
        $stale = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-14 09:30:00']);

        $this->inTenant(
            $this->organization,
            fn () => app(ReconcileLessonsWithSlotValidity::class)->execute($this->schoolClass->id),
        );

        $this->asTeacher()->post('/lessons/materialize-week', [
            'from' => '2026-09-14',
            'to' => '2026-09-20',
        ])->assertRedirect();

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(0, Lesson::query()->whereDate('starts_at', '2026-09-14')->count());
        });
    }

    /**
     * REGRESSÃO — a materialização (auto, `strict: false`) nunca pode falhar
     * por causa da reconciliação, nem quando fechar a sequência normal
     * mudaria o número de uma aula já lecionada. Cenário: uma turma já com o
     * plano B aplicado (uma lecionada com número "fora de ordem") e, na
     * mesma turma, uma aula stale por remover. Abrir a semana não pode
     * rebentar — usa o mesmo plano B não-recusante que a numeração já tem.
     */
    #[Test]
    public function materialization_never_throws_when_reconciliation_would_renumber_taught_history(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 1]);

        // Lecionada, numerada 1 — a materialização normal por cima dela teria
        // de lhe mudar o número se uma aula mais antiga aparecesse; aqui o
        // gatilho é a stale a seguir, cuja remoção force resequence().
        $taught = $this->makeLesson([
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => '2026-09-14 09:30:00',
            'status' => LessonStatus::Taught,
            'outcome' => LessonOutcome::Taught,
            'lesson_number' => 5,
        ]);

        $stale = $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-07 09:30:00']);

        $this->inTenant($this->organization, function () use ($slot): void {
            $slot->update(['starts_on' => '2026-09-14']);
        });

        // Não deve lançar: a chamada HTTP não pode rebentar com 500.
        $this->asTeacher()->post("/classes/{$this->schoolClass->ulid}/lessons/materialize", [
            'from' => '2026-09-01',
            'to' => '2026-09-20',
        ])->assertRedirect();

        $this->assertDatabaseMissing('lessons', ['id' => $stale->id]);
        $this->inTenant($this->organization, function () use ($taught): void {
            // A lecionada nunca perde o número que já tinha — o plano B
            // nunca reescreve um número já escrito.
            $this->assertSame(5, $taught->refresh()->lesson_number);
        });
    }

    /**
     * REGRESSÃO — eliminar um tempo já em vigor (`starts_on` null) que só
     * produziu aulas vazias tem de fechar de facto a rotina, e não deixar
     * `ends_on` a `null` como um no-op silencioso.
     */
    #[Test]
    public function destroying_an_always_in_vigor_slot_with_only_empty_lessons_actually_stops_it(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 1]);
        $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-14 09:30:00']);

        $this->asTeacher()->delete("/lesson-slots/{$slot->ulid}")->assertRedirect();

        $this->inTenant($this->organization, function () use ($slot): void {
            $slot->refresh();
            $this->assertNotNull($slot->ends_on, 'O tempo continua sem ends_on — a rotina não foi fechada.');
        });

        // Materializar a semana seguinte já não produz nada a partir dele.
        $this->asTeacher()->post("/classes/{$this->schoolClass->ulid}/lessons/materialize", [
            'from' => '2026-09-21',
            'to' => '2026-09-27',
        ])->assertRedirect();

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(0, Lesson::query()->whereDate('starts_at', '2026-09-21')->count());
        });
    }

    /** REGRESSÃO — destroy() bloqueia a turma ANTES do tempo, como update(). */
    #[Test]
    public function destroying_locks_the_class_before_the_slot(): void
    {
        $slot = $this->makeSlot(['day_of_week' => 1]);
        $this->makeLesson(['recurring_lesson_slot_id' => $slot->id, 'starts_at' => '2026-09-14 09:30:00']);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->asTeacher()->delete("/lesson-slots/{$slot->ulid}")->assertRedirect();

        $classLockPattern = '/^select .* from "?`?classes"?`? .*where/';
        $slotWritePattern = '/^(update|delete) .*"?`?recurring_lesson_slots"?`?/';

        $classLock = null;
        $slotWrite = null;

        foreach ($queries as $index => $sql) {
            if ($classLock === null && preg_match($classLockPattern, $sql) === 1) {
                $classLock = $index;
            }

            if ($slotWrite === null && preg_match($slotWritePattern, $sql) === 1) {
                $slotWrite = $index;
            }
        }

        $this->assertNotNull($classLock, 'A turma não foi lida para bloqueio.');
        $this->assertNotNull($slotWrite);
        $this->assertLessThan($slotWrite, $classLock);
    }
}
