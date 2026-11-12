# Anular «Lecionada» — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que o professor que fechou uma aula como «Lecionada» (ou a direção de uma organização institucional) a corrija para «Aula não realizada», com número, sumário, assiduidade, planeamento e histórico tratados como a spec define.

**Architecture:** Uma ação nova `App\Actions\Lessons\AnnulTaughtLesson` faz tudo numa transação com a turma bloqueada, reutilizando `ShiftLessonPlanning` (descida do planeamento), `LessonNumbering` (renumeração estrita ou aceitando lacunas), `RecordLessonOutcome::guardDetails`/`cleanNote` (coerência da causa) e `LessonOutcomeRectification` (histórico, com `kind = annulment`). Uma rota `PATCH lessons/{lesson}/outcome/annul` serve o professor (página da aula) e a direção (lista nova «Aulas fechadas» em «Retificações»); a policy `LessonPolicy::annulTaught` decide quem.

**Tech Stack:** Laravel 12 / PHP 8.4 (Herd: `export PATH="/c/Users/PCUser/.config/herd/bin/php84:$PATH"`), PHPUnit 12, Pint, PHPStan; Inertia + Vue 3 + TypeScript, vitest (jsdom), eslint, vue-tsc (`npm run build` antes, por causa do Wayfinder). Worktree `D:/worktrees/lapis-draft-recovery`, branch `feat/annul-taught-lesson`, partida da 0.193.0 (26c9df9b).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-11-12-anular-lecionada-design.md`.
- Só com `config('lapis.lesson_not_held_enabled')` ligada (em testes: `config(['lapis.lesson_not_held_enabled' => true])`).
- Quem: o titular do fecho (regra de `LessonOutcomeDetails::mayReadReason`, não observador) **ou** a direção (`Gate::allows('reviewRectifications', $class)`: dono de organização institucional).
- Recusa com `lesson_unit_key` não nulo. Motivo 3 a 200 caracteres (`RectifyLessonOutcome::MOTIVE_MIN/MAX`).
- `outcome_recorded_by` NÃO muda na anulação: o titular continua a ser quem fechou a aula, para continuar a ler o seu histórico; quem anulou fica em `rectified_by`.
- Assiduidade apaga-se sempre; sem cópia no histórico.
- Sem `closedLessonAfter`: sumário desce (`ShiftLessonPlanning::from`) e renumeração estrita; com: sumário para o histórico e `resequenceAcceptingGaps`.
- Texto novo diz «Lapispro»; nada de «LÁPIS».
- Versão 0.194.0; só código; zero migrations (JSON `previous`/`current` ganham chaves).
- Comandos: `php artisan test --filter=X`, `npx vitest run caminho`, `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npx eslint resources/js`, `npx vue-tsc --noEmit`.
- Não correr suites PHP em paralelo (SQLite partilhado).
- Antes da PR: correr `php artisan test --filter="BasePagesCharacterizationTest|BaseSurfacesUnchangedTest"` (a Pauta não muda; se alguma fixture partir, regenerar e dizê-lo).

---

## Estrutura de ficheiros

| Ficheiro | Responsabilidade |
|---|---|
| `app/Actions/Lessons/AnnulTaughtLesson.php` (novo) | A ação: guardas, efeitos, histórico, auditoria |
| `app/Models/LessonOutcomeRectification.php` | `KIND_ANNULMENT` |
| `app/Policies/LessonPolicy.php` | `annulTaught(User, Lesson)` |
| `app/Http/Requests/Lessons/AnnulTaughtLessonRequest.php` (novo) | Validação do pedido |
| `app/Http/Controllers/LessonController.php` | `annulTaught()`; prop `annul_taught` em `show()` |
| `app/Support/Lessons/LessonOutcomeRectificationView.php` | Linhas de histórico da anulação (n.º, sumário removido, «pela direção») |
| `routes/web.php` | `PATCH lessons/{lesson}/outcome/annul`; `GET classes/{class}/retificacoes/aulas` |
| `app/Http/Controllers/LessonAnnulmentController.php` (novo) | Lista «Aulas fechadas» da direção |
| `app/Http/Controllers/ClassificationRectificationController.php` | `index()` passa também `lessonClasses` |
| `resources/js/lib/lessonOutcomeOptions.ts` | Tipos `AnnulTaught`, histórico com `by_direction` |
| `resources/js/components/lessons/LessonOutcomeDialog.vue` | Modo anulação |
| `resources/js/components/lessons/LessonOutcomePanel.vue` | Botão «Corrigir: a aula não se realizou» |
| `resources/js/pages/lessons/Show.vue` | Passa `annul_taught` |
| `resources/js/pages/classifications/rectifications/Index.vue`, `Lessons.vue` (novo) | Entrada e lista da direção |
| Testes | `tests/Feature/Lessons/AnnulTaughtLessonTest.php`, `AnnulTaughtLessonHttpTest.php`, `LessonAnnulmentControllerTest.php`; `LessonNotHeldUiPropsTest` (+2); `LessonServiceReportTest` (+1); Vue: `LessonOutcomeDialog.annul.test.ts`, `LessonOutcomePanel.test.ts` (+2), `rectifications/Lessons.test.ts` |
| `config/app.php`, `CHANGELOG.md`, `docs/lesson-outcome-recovery.md` | 0.194.0 e documentação |

---

### Task 1: A ação `AnnulTaughtLesson`

**Files:**
- Create: `app/Actions/Lessons/AnnulTaughtLesson.php`
- Modify: `app/Models/LessonOutcomeRectification.php` (constante `KIND_ANNULMENT`)
- Test: `tests/Feature/Lessons/AnnulTaughtLessonTest.php`

**Interfaces:**
- Consumes: `RecordLessonOutcome::guardDetails(LessonOutcome, ?TeacherAbsenceReason, ?LessonNotHeldCause, ?TeacherStrikeSituation, ?string): void` e `cleanNote(?string): ?string`; `RectifyLessonOutcome::snapshot(Lesson): array` (os seis campos) e `MOTIVE_MIN`/`MOTIVE_MAX`; `ShiftLessonPlanning::closedLessonAfter(Lesson): ?Lesson` e `from(Lesson, User): array{shifted:int, materialized:bool, pending:bool, …}`; `LessonNumbering::guardSnapshot(int): array`, `resequence(int, ?array): array`, `resequenceAcceptingGaps(int): array`, `numberMaterializedLessons(int): array`, `handOverManualNumber(Lesson, int): bool`; `AuditLog::record(string, ?Model, ?User, ?string, array)`.
- Produces: `AnnulTaughtLesson::execute(Lesson $lesson, User $actor, LessonNotHeldCause $cause, string $motive, ?string $causeNote = null, ?TeacherStrikeSituation $situation = null): array{lesson: Lesson, planning: array|null, summary_kept_in_history: bool, renumbered: array<int,int|null>}`; `LessonOutcomeRectification::KIND_ANNULMENT = 'annulment'`; no `previous` da linha de histórico, além dos seis campos: `lesson_number` (int|null) e, quando o sumário foi para o histórico, `summary_content`, `summary_resources`, `summary_homework`, `summary_private_notes` (string|null).

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\AnnulTaughtLesson;
use App\Actions\Lessons\MarkLessonAsTaught;
use App\Models\AttendanceStatus;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Services\Lessons\LessonNumbering;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * Anular um «Lecionada» dado por engano: a aula não se realizou. A ação é a
 * única que desfaz um fecho, e faz tudo o que o registo normal de «Aula não
 * realizada» faria — sem reescrever nenhuma aula lecionada depois dela.
 */
class AnnulTaughtLessonTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        config(['lapis.lesson_not_held_enabled' => true]);
    }

    /** @return list<Lesson> quatro quintas-feiras numeradas 1–4, todas abertas */
    private function fourNumbered(): array
    {
        $slot = $this->makeSlot();
        $lessons = array_map(fn (string $day): Lesson => $this->makeLesson([
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => "2026-10-{$day} {$slot->starts_at}",
        ]), ['08', '15', '22', '29']);
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));

        return $lessons;
    }

    /** Fecha pela via real: estado, resultado, titular e assiduidade consolidada. */
    private function teach(Lesson $lesson, array $absentUlids = []): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => app(MarkLessonAsTaught::class)->execute($lesson->refresh(), $this->teacher, $absentUlids));
    }

    private function summary(Lesson $lesson, string $content): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => $content, 'homework' => 'TPC '.$content]));
    }

    private function annul(Lesson $lesson, LessonNotHeldCause $cause = LessonNotHeldCause::WeatherClosure, string $motive = 'Não houve aula: escola encerrada por mau tempo.'): array
    {
        return $this->inTenant($this->organization, fn (): array => app(AnnulTaughtLesson::class)->execute($lesson->refresh(), $this->teacher, $cause, $motive));
    }

    #[Test]
    public function without_closed_lessons_after_the_summary_moves_on_and_the_open_ones_renumber_without_a_gap(): void
    {
        [$a, $b, $c, $d] = $this->fourNumbered();
        $this->enroll('Aluno Um');
        $this->teach($a);
        $this->teach($b);
        $this->summary($b, 'Elementos da narrativa');

        $result = $this->annul($b);

        $this->inTenant($this->organization, function () use ($a, $b, $c, $d, $result): void {
            $b->refresh();
            $this->assertSame(LessonOutcome::NotHeld, $b->outcome);
            $this->assertSame(LessonNotHeldCause::WeatherClosure, $b->outcome_cause);
            $this->assertSame(LessonStatus::Preparation, $b->status);
            $this->assertNull($b->lesson_number);
            $this->assertNull($b->attendance_recorded_at);
            $this->assertSame(0, $b->attendances()->count());
            $this->assertNull($b->summary()->first());
            // O titular continua a ser quem fechou: é ele que lê o histórico.
            $this->assertSame($this->teacher->id, $b->outcome_recorded_by);

            // O sumário desceu para a aula seguinte, e a numeração fechou sem lacuna.
            $this->assertSame('Elementos da narrativa', $c->refresh()->summary()->first()?->content);
            $this->assertSame(1, $a->refresh()->lesson_number);
            $this->assertSame(2, $c->lesson_number);
            $this->assertSame(3, $d->refresh()->lesson_number);
            $this->assertFalse($result['summary_kept_in_history']);
            $this->assertSame(1, $result['planning']['shifted']);

            $rectification = LessonOutcomeRectification::query()->where('lesson_id', $b->id)->sole();
            $this->assertSame(LessonOutcomeRectification::KIND_ANNULMENT, $rectification->kind);
            $this->assertSame('taught', $rectification->previous['outcome']);
            $this->assertSame(2, $rectification->previous['lesson_number']);
            $this->assertArrayNotHasKey('summary_content', $rectification->previous);
            $this->assertSame('not_held', $rectification->current['outcome']);
            $this->assertSame('weather_closure', $rectification->current['cause']);
            $this->assertSame($this->teacher->id, $rectification->rectified_by);
        });
    }

    #[Test]
    public function with_a_closed_lesson_after_the_summary_goes_to_the_history_and_the_gap_stays(): void
    {
        [$a, $b, $c, $d] = $this->fourNumbered();
        $this->teach($a);
        $this->teach($b);
        $this->summary($b, 'Texto da aula que não houve');
        $this->teach($c);
        $this->summary($c, 'Aula seguinte, lecionada');

        $result = $this->annul($b);

        $this->inTenant($this->organization, function () use ($a, $b, $c, $d, $result): void {
            $this->assertNull($b->refresh()->lesson_number);
            $this->assertNull($b->summary()->first());
            $this->assertTrue($result['summary_kept_in_history']);
            // Nenhuma lecionada muda; a lacuna fica no 2; a aberta a seguir continua 4.
            $this->assertSame(1, $a->refresh()->lesson_number);
            $this->assertSame(3, $c->refresh()->lesson_number);
            $this->assertSame('Aula seguinte, lecionada', $c->summary()->first()?->content);
            $this->assertSame(4, $d->refresh()->lesson_number);

            $rectification = LessonOutcomeRectification::query()->where('lesson_id', $b->id)->sole();
            $this->assertSame('Texto da aula que não houve', $rectification->previous['summary_content']);
            $this->assertSame('TPC Texto da aula que não houve', $rectification->previous['summary_homework']);

            $event = AuditEvent::query()->where('event', 'lesson.taught_annulled')->where('subject_id', $b->id)->sole();
            $this->assertTrue($event->properties['summary_kept_in_history']);
            $this->assertSame([2], $event->properties['gaps']);
            $this->assertSame(2, $event->properties['from_lesson_number']);
            $this->assertStringNotContainsString('Texto da aula', json_encode($event->properties));
        });
    }

    #[Test]
    public function the_attendance_is_deleted_absences_included_and_only_counted_in_the_audit(): void
    {
        [$a] = $this->fourNumbered();
        $um = $this->enroll('Aluno Um');
        $this->enroll('Aluno Dois');
        $this->teach($a, [$um->ulid]);

        $this->inTenant($this->organization, fn () => $this->assertSame(1, $a->refresh()->attendances()->where('status', AttendanceStatus::Absent)->count()));

        $this->annul($a);

        $this->inTenant($this->organization, function () use ($a): void {
            $this->assertSame(0, $a->refresh()->attendances()->count());
            $this->assertNull($a->attendance_recorded_at);
            $this->assertNull($a->attendance_recorded_by);
            $event = AuditEvent::query()->where('event', 'lesson.taught_annulled')->where('subject_id', $a->id)->sole();
            $this->assertSame(2, $event->properties['attendance_rows_deleted']);
            $this->assertSame(1, $event->properties['absences_deleted']);
        });
    }

    #[Test]
    public function a_manual_number_is_handed_over_to_the_next_free_unit(): void
    {
        [$a, $b, $c] = $this->fourNumbered();
        $this->teach($a);
        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($b->id)->update(['lesson_number' => 7, 'lesson_number_manual' => true]));
        $this->teach($b);

        $this->annul($b);

        $this->inTenant($this->organization, function () use ($b, $c): void {
            $this->assertNull($b->refresh()->lesson_number);
            $this->assertFalse($b->lesson_number_manual);
            $this->assertSame(7, $c->refresh()->lesson_number);
            $this->assertTrue($c->lesson_number_manual);
        });
    }

    #[Test]
    public function refuses_a_lesson_that_is_not_taught_a_shared_unit_and_an_other_cause_without_a_note_and_writes_nothing(): void
    {
        [$a, $b] = $this->fourNumbered();
        $this->teach($b);

        foreach ([
            [$a, LessonNotHeldCause::WeatherClosure, null, 'outcome'],
            [$b, LessonNotHeldCause::Other, null, 'cause_note'],
        ] as [$lesson, $cause, $note, $key]) {
            try {
                $this->inTenant($this->organization, fn () => app(AnnulTaughtLesson::class)->execute($lesson->refresh(), $this->teacher, $cause, 'Motivo válido', $note));
                $this->fail('devia recusar');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
            }
        }

        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($b->id)->update(['lesson_unit_key' => '01JABCDEFGHJKMNPQRSTVWXYZ0']));
        try {
            $this->annul($b);
            $this->fail('devia recusar');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('outcome', $exception->errors());
        }

        $this->inTenant($this->organization, function () use ($b): void {
            $this->assertSame(LessonOutcome::Taught, $b->refresh()->outcome);
            $this->assertSame(2, $b->lesson_number);
            $this->assertSame(0, LessonOutcomeRectification::query()->count());
        });
    }

    #[Test]
    public function a_short_motive_is_refused(): void
    {
        [$a] = $this->fourNumbered();
        $this->teach($a);

        $this->expectException(ValidationException::class);
        $this->annul($a, LessonNotHeldCause::WeatherClosure, 'ok');
    }
}
```

Nota sobre `makeSlot()`: devolve um `RecurringLessonSlot` com `class_group_id` e `starts_at` (hora); é o que `LessonNotHeldRetroactiveTest::thursdays()` já usa. `enroll()` devolve um `Enrollment` com `ulid`.

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test --filter=AnnulTaughtLessonTest`
Expected: FAIL — classe `AnnulTaughtLesson` inexistente.

- [ ] **Step 3: Constante no modelo** — em `app/Models/LessonOutcomeRectification.php`, logo depois de `KIND_ADMINISTRATIVE`:

```php
    /** Anulação de um «Lecionada»: a aula não se realizou. `previous` leva o n.º e, quando removido, o sumário. */
    public const string KIND_ANNULMENT = 'annulment';
```

- [ ] **Step 4: A ação**

```php
<?php

namespace App\Actions\Lessons;

use App\Models\AttendanceStatus;
use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\SchoolClass;
use App\Models\TeacherStrikeSituation;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Lessons\LessonNumbering;
use App\Services\Lessons\ShiftLessonPlanning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ANULAR UM «LECIONADA»: a aula não se realizou (0.194.0).
 *
 * O registo normal de «Aula não realizada» recusa aulas fechadas e o retroativo
 * só aceita aulas vazias; esta é a única porta para desfazer um fecho dado por
 * engano. Faz, numa transação com a turma bloqueada, o que o registo normal
 * faria — e nunca reescreve uma aula lecionada depois desta:
 *
 *  - a assiduidade é apagada (não há aula, não há faltas);
 *  - sem aulas fechadas a seguir, o sumário desce para a aula seguinte
 *    (ShiftLessonPlanning) e a numeração fecha sem lacuna; com aulas fechadas a
 *    seguir, o sumário sai da aula para o histórico e a numeração aceita a
 *    lacuna (as fechadas são fixas);
 *  - o número manual passa à próxima unidade livre, como no registo normal;
 *  - o TITULAR DO FECHO NÃO MUDA (`outcome_recorded_by`): é ele que lê o seu
 *    histórico; quem anulou fica na linha do histórico (`rectified_by`).
 *
 * Quem pode é a policy (`LessonPolicy::annulTaught`): o titular, ou a direção
 * de uma organização institucional, que nunca lê o sumário — o texto só viaja
 * para o histórico, que só o titular lê (LessonOutcomeRectificationView).
 */
final class AnnulTaughtLesson
{
    /** Os campos do sumário que vão para o histórico quando o texto não pode descer. */
    private const array SUMMARY_FIELDS = ['content', 'resources', 'homework', 'private_notes'];

    public function __construct(
        protected RecordLessonOutcome $recorder,
        protected RectifyLessonOutcome $rectifier,
        protected LessonNumbering $numbering,
        protected ShiftLessonPlanning $planning,
        protected AuditLog $audit,
    ) {}

    /**
     * @return array{lesson: Lesson, planning: array<string, mixed>|null, summary_kept_in_history: bool, renumbered: array<int, int|null>}
     */
    public function execute(
        Lesson $lesson,
        User $actor,
        LessonNotHeldCause $cause,
        string $motive,
        ?string $causeNote = null,
        ?TeacherStrikeSituation $situation = null,
    ): array {
        return DB::transaction(function () use ($lesson, $actor, $cause, $motive, $causeNote, $situation): array {
            SchoolClass::query()->whereKey($lesson->class_id)->lockForUpdate()->first();

            /** @var Lesson $locked */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            $motive = $this->guard($locked, $cause, $motive, $causeNote, $situation);

            $fromNumber = $locked->lesson_number;
            $closedAfter = $this->planning->closedLessonAfter($locked);
            $snapshot = $this->numbering->guardSnapshot($locked->class_id);
            $previous = $this->rectifier->snapshot($locked) + ['lesson_number' => $fromNumber];

            // 1. Assiduidade: não há aula, não há faltas. Só contagens para a auditoria.
            $absences = $locked->attendances()->where('status', AttendanceStatus::Absent)->count();
            $rows = $locked->attendances()->delete();
            $locked->attendance_recorded_at = null;
            $locked->attendance_recorded_by = null;

            // 2. Resultado. O titular do fecho fica.
            $locked->outcome = LessonOutcome::NotHeld;
            $locked->outcome_reason = null;
            $locked->outcome_note = null;
            $locked->outcome_cause = $cause;
            $locked->outcome_teacher_situation = $cause === LessonNotHeldCause::Strike ? $situation : null;
            $locked->outcome_cause_note = $cause === LessonNotHeldCause::Other ? $this->recorder->cleanNote($causeNote) : null;
            $locked->outcome_recorded_at = Carbon::now();
            $locked->status = LessonStatus::Preparation;

            // 3. Número: um manual passa à frente; a aula fica sem número.
            $wasManual = $locked->lesson_number_manual && $locked->lesson_number !== null;
            $locked->lesson_number = null;
            $locked->lesson_number_end = null;
            $locked->lesson_number_manual = false;
            $locked->save();

            $manualHandedOver = $wasManual && $fromNumber !== null
                ? $this->numbering->handOverManualNumber($locked, (int) $fromNumber)
                : false;

            // 4. Sumário e planeamento.
            $planning = null;
            $summaryKept = null;

            if ($closedAfter === null) {
                $planning = $this->planning->from($locked, $actor);
            } else {
                $summaryKept = $this->takeSummaryIntoHistory($locked);
            }

            // 5. Renumeração: as fechadas são fixas; sem fechadas a seguir, a
            // sequência fecha-se como em RecordLessonOutcome::renumber.
            $renumbered = match (true) {
                $closedAfter !== null => $this->numbering->resequenceAcceptingGaps($locked->class_id),
                (bool) ($planning['materialized'] ?? false) => $this->numbering->numberMaterializedLessons($locked->class_id),
                default => $this->numbering->resequence($locked->class_id, $snapshot),
            };

            $locked->refresh();
            $current = $this->rectifier->snapshot($locked);

            LessonOutcomeRectification::create([
                'lesson_id' => $locked->getKey(),
                'rectified_by' => $actor->getKey(),
                'rectified_at' => Carbon::now(),
                'kind' => LessonOutcomeRectification::KIND_ANNULMENT,
                'previous' => $summaryKept === null ? $previous : $previous + $summaryKept,
                'current' => $current,
                'motive' => $motive,
            ]);

            // SÓ CÓDIGOS E CONTAGENS: nem o texto do sumário nem o motivo.
            $this->audit->record(
                'lesson.taught_annulled',
                $locked,
                $actor,
                'Marcação de lecionada anulada: a aula não se realizou.',
                [
                    'cause' => $cause->value,
                    'from_lesson_number' => $fromNumber,
                    'attendance_rows_deleted' => $rows,
                    'absences_deleted' => $absences,
                    'summary_shifted' => $planning !== null && ($planning['shifted'] ?? 0) > 0,
                    'summary_kept_in_history' => $summaryKept !== null,
                    'planning_shifted' => $planning['shifted'] ?? 0,
                    'planning_materialized' => (bool) ($planning['materialized'] ?? false),
                    'planning_pending' => (bool) ($planning['pending'] ?? false),
                    'manual_number_handed_over' => $manualHandedOver,
                    'renumbered_lessons' => count($renumbered),
                    'gaps' => $this->gapsIn($locked->class_id),
                    'by_direction' => (int) $locked->outcome_recorded_by !== (int) $actor->getKey(),
                ],
            );

            return [
                'lesson' => $locked->refresh(),
                'planning' => $planning,
                'summary_kept_in_history' => $summaryKept !== null,
                'renumbered' => $renumbered,
            ];
        });
    }

    /**
     * @throws ValidationException
     */
    private function guard(Lesson $locked, LessonNotHeldCause $cause, string $motive, ?string $causeNote, ?TeacherStrikeSituation $situation): string
    {
        if (! $locked->isTaught()) {
            throw ValidationException::withMessages(['outcome' => __('Só uma aula marcada como lecionada pode ser anulada.')]);
        }

        if ($locked->lesson_unit_key !== null) {
            throw ValidationException::withMessages(['outcome' => __('Esta aula partilha a lição com outro grupo: ainda não se anula por aqui.')]);
        }

        $motive = trim($motive);

        if (mb_strlen($motive) < RectifyLessonOutcome::MOTIVE_MIN || mb_strlen($motive) > RectifyLessonOutcome::MOTIVE_MAX) {
            throw ValidationException::withMessages([
                'rectification_note' => __('Indica o motivo da correção (entre :min e :max carateres).', ['min' => RectifyLessonOutcome::MOTIVE_MIN, 'max' => RectifyLessonOutcome::MOTIVE_MAX]),
            ]);
        }

        $this->recorder->guardDetails(LessonOutcome::NotHeld, null, $cause, $situation, $causeNote);

        return $motive;
    }

    /**
     * O texto do sumário sai da aula (uma aula que não houve não fica a dizer
     * que houve) e vai para o histórico, que só o titular lê. Apagado pelo
     * MODELO para subir `summary_version` — um cartão aberto antes não grava
     * por cima. `null` quando não havia texto nenhum.
     *
     * @return array<string, string|null>|null
     */
    private function takeSummaryIntoHistory(Lesson $locked): ?array
    {
        /** @var LessonSummary|null $summary */
        $summary = $locked->summary()->lockForUpdate()->first();

        if ($summary === null) {
            return null;
        }

        $values = [];

        foreach (self::SUMMARY_FIELDS as $field) {
            $value = $summary->getAttribute($field);
            $values['summary_'.$field] = is_string($value) && trim($value) !== '' ? $value : null;
        }

        $summary->delete();

        return array_filter($values) === [] ? null : $values;
    }

    /**
     * As lacunas da numeração da turma depois da anulação (só para a auditoria).
     *
     * @return list<int>
     */
    private function gapsIn(int $classId): array
    {
        $numbers = Lesson::query()->where('class_id', $classId)->whereNotNull('lesson_number')->pluck('lesson_number')->map(fn ($n): int => (int) $n)->unique()->sort()->values();

        if ($numbers->isEmpty()) {
            return [];
        }

        return array_values(array_diff(range((int) $numbers->first(), (int) $numbers->last()), $numbers->all()));
    }
}
```

Se `RectifyLessonOutcome::snapshot()` não for público, torná-lo (`public function snapshot(Lesson $lesson): array`) — é só leitura dos seis campos. Se `ShiftLessonPlanning::from()` recusar uma aula cujo `status` não é «fechado» (verificar o início de `shift()`), chamar `from()` ANTES de mudar `status` para `Preparation` e deixar um comentário a explicar a ordem.

- [ ] **Step 5: Correr e ver passar**

Run: `php artisan test --filter=AnnulTaughtLessonTest`
Expected: PASS (6). Se o teste do número manual falhar por `handOverManualNumber` exigir que a unidade seguinte seja livre e ela já ter o 3 derivado, é correto que o 7 se perca: verificar com `assertNull`/`assertSame(3, …)` e **ajustar o teste**, documentando no commit que o manual se perde quando a seguinte é fixa (regra existente).

- [ ] **Step 6: Pint, PHPStan, commit**

```bash
vendor/bin/pint app/Actions/Lessons/AnnulTaughtLesson.php app/Models/LessonOutcomeRectification.php tests/Feature/Lessons/AnnulTaughtLessonTest.php
vendor/bin/phpstan analyse app/Actions/Lessons/AnnulTaughtLesson.php --memory-limit=1G
git add app/Actions/Lessons/AnnulTaughtLesson.php app/Models/LessonOutcomeRectification.php tests/Feature/Lessons/AnnulTaughtLessonTest.php
git commit -m "feat(lessons): AnnulTaughtLesson — desfazer um «Lecionada» dado por engano, com número, sumário, assiduidade e histórico tratados"
```

---

### Task 2: Policy, pedido, rota e controlador

**Files:**
- Modify: `app/Policies/LessonPolicy.php` (depois de `rectifyOutcomeAsAdministrator`)
- Create: `app/Http/Requests/Lessons/AnnulTaughtLessonRequest.php`
- Modify: `app/Http/Controllers/LessonController.php` (depois de `rectifyOutcomeAsAdministrator`)
- Modify: `routes/web.php` (depois da rota `lessons.outcome.rectify-administrative`)
- Test: `tests/Feature/Lessons/AnnulTaughtLessonHttpTest.php`

**Interfaces:**
- Consumes: `AnnulTaughtLesson::execute(...)` (Task 1); `LessonOutcomeDetails::mayReadReason(Lesson, User): bool`; `Gate::forUser($user)->allows('reviewRectifications', SchoolClass)`; `ShiftLessonPlanning::destinationSentence(array $trace, Lesson $closed): string`.
- Produces: `LessonPolicy::annulTaught(User, Lesson): bool`; `PATCH /lessons/{lesson}/outcome/annul` (nome `lessons.outcome.annul`) com campos `cause`, `situation?`, `cause_note?`, `rectification_note`; resposta `back()` com toast.

- [ ] **Step 1: Testes HTTP que falham**

```php
<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MarkLessonAsTaught;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\OrganizationType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

class AnnulTaughtLessonHttpTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        config(['lapis.lesson_not_held_enabled' => true]);
    }

    private function taughtLesson(): Lesson
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-11-11 10:30:00']);

        return $this->inTenant($this->organization, fn (): Lesson => app(MarkLessonAsTaught::class)->execute($lesson, $this->teacher));
    }

    /** @return array<string, string> */
    private function payload(): array
    {
        return ['cause' => 'weather_closure', 'rectification_note' => 'Escola encerrada por mau tempo.'];
    }

    private function patch(User $user, Lesson $lesson, array $payload)
    {
        return $this->actingAs($user)->withSession(['organization_id' => $this->organization->id])
            ->patch("/lessons/{$lesson->ulid}/outcome/annul", $payload);
    }

    /** Outro professor na turma, com o papel dado. */
    private function colleague(string $role = 'teacher'): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->inTenant($this->organization, fn () => $this->schoolClass->teachers()->attach($user, ['role' => $role]));

        return $user;
    }

    /** A organização passa a instituição com uma direção que não leciona a turma. */
    private function head(): User
    {
        $head = User::factory()->create();
        DB::table('organizations')->where('id', $this->organization->id)->update([
            'type' => OrganizationType::Institutional->value,
            'owner_id' => $head->getKey(),
        ]);
        DB::table('organization_memberships')->insertOrIgnore([
            'organization_id' => $this->organization->id,
            'user_id' => $head->getKey(),
            'joined_at' => now(),
        ]);

        return $head;
    }

    #[Test]
    public function the_teacher_who_closed_the_lesson_annuls_it_and_is_told_where_the_summary_went(): void
    {
        $lesson = $this->taughtLesson();

        $response = $this->patch($this->teacher, $lesson, $this->payload());

        $response->assertRedirect();
        $this->inTenant($this->organization, function () use ($lesson): void {
            $this->assertSame(LessonOutcome::NotHeld, $lesson->refresh()->outcome);
            $this->assertSame($this->teacher->id, LessonOutcomeRectification::query()->where('lesson_id', $lesson->id)->sole()->rectified_by);
        });
    }

    #[Test]
    public function another_teacher_of_the_class_and_an_observer_are_refused(): void
    {
        $lesson = $this->taughtLesson();

        $this->patch($this->colleague(), $lesson, $this->payload())->assertForbidden();
        $this->patch($this->colleague('observer'), $lesson, $this->payload())->assertForbidden();
        $this->inTenant($this->organization, fn () => $this->assertSame(LessonOutcome::Taught, $lesson->refresh()->outcome));
    }

    #[Test]
    public function the_head_of_an_institution_annuls_a_lesson_of_a_class_they_do_not_teach(): void
    {
        $lesson = $this->taughtLesson();
        $head = $this->head();

        $this->patch($head, $lesson, $this->payload())->assertRedirect();

        $this->inTenant($this->organization, function () use ($lesson, $head): void {
            $this->assertSame(LessonOutcome::NotHeld, $lesson->refresh()->outcome);
            // O titular do fecho continua a ser o professor; a direção fica no histórico.
            $this->assertSame($this->teacher->id, $lesson->outcome_recorded_by);
            $this->assertSame($head->id, LessonOutcomeRectification::query()->where('lesson_id', $lesson->id)->sole()->rectified_by);
        });
    }

    #[Test]
    public function the_owner_of_a_personal_organization_who_does_not_teach_is_refused(): void
    {
        $lesson = $this->taughtLesson();
        $owner = User::factory()->create();
        DB::table('organizations')->where('id', $this->organization->id)->update(['owner_id' => $owner->getKey()]);
        DB::table('organization_memberships')->insertOrIgnore(['organization_id' => $this->organization->id, 'user_id' => $owner->getKey(), 'joined_at' => now()]);

        $this->patch($owner, $lesson, $this->payload())->assertForbidden();
    }

    #[Test]
    public function with_the_feature_off_nobody_annuls(): void
    {
        $lesson = $this->taughtLesson();
        config(['lapis.lesson_not_held_enabled' => false]);

        $this->patch($this->teacher, $lesson, $this->payload())->assertForbidden();
    }

    #[Test]
    public function the_request_validates_cause_and_motive(): void
    {
        $lesson = $this->taughtLesson();

        $this->patch($this->teacher, $lesson, ['rectification_note' => 'Motivo válido'])->assertSessionHasErrors('cause');
        $this->patch($this->teacher, $lesson, ['cause' => 'weather_closure'])->assertSessionHasErrors('rectification_note');
        $this->patch($this->teacher, $lesson, ['cause' => 'other', 'rectification_note' => 'Motivo válido'])->assertSessionHasErrors('cause_note');
    }

    #[Test]
    public function a_guest_goes_to_login(): void
    {
        $lesson = $this->taughtLesson();

        $this->patch(User::factory()->make(), $lesson, $this->payload()); // só para construir a URL
        $this->patch = null;
        $this->post('/logout');
        $this->patch("/lessons/{$lesson->ulid}/outcome/annul", $this->payload())->assertRedirect('/login');
    }
}
```

Corrigir o último teste para a forma simples (o rascunho acima tem duas linhas a mais): basta `$this->patch("/lessons/{$lesson->ulid}/outcome/annul", $this->payload())->assertRedirect('/login');` sem `actingAs`. Se a sessão `organization_id` não for a chave usada por `BuildsLessonFixtures::asTeacher()`, copiar a forma de `LessonNotHeldUiPropsTest::props()`.

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test --filter=AnnulTaughtLessonHttpTest`
Expected: FAIL (405/404 na rota).

- [ ] **Step 3: Policy** — em `LessonPolicy`, depois de `rectifyOutcomeAsAdministrator`:

```php
    /**
     * ANULAR UM «LECIONADA» (0.194.0): a aula não se realizou. Quem fechou a aula (o titular, pela regra
     * de LessonOutcomeDetails::mayReadReason — e nunca um observador), ou a direção da instituição que
     * detém a turma, pela competência que já tem em «Retificações». Desligada com a funcionalidade.
     */
    public function annulTaught(User $user, Lesson $lesson): bool
    {
        if (! config('lapis.lesson_not_held_enabled', false) || ! $lesson->isTaught()) {
            return false;
        }

        $ownClosing = $this->recordOutcome($user, $lesson) && LessonOutcomeDetails::mayReadReason($lesson, $user);

        return $ownClosing || Gate::forUser($user)->allows('reviewRectifications', $lesson->schoolClass);
    }
```

(`Gate` e `LessonOutcomeDetails` já estão importados no ficheiro — confirmar com `grep -n "^use" app/Policies/LessonPolicy.php`.)

- [ ] **Step 4: Pedido**

```php
<?php

namespace App\Http\Requests\Lessons;

use App\Models\Lesson;
use App\Models\LessonNotHeldCause;
use App\Models\TeacherStrikeSituation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnulTaughtLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson && $this->user()?->can('annulTaught', $lesson) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'cause' => ['required', 'string', Rule::enum(LessonNotHeldCause::class)],
            'situation' => ['nullable', 'string', Rule::in(array_column(TeacherStrikeSituation::available(), 'value'))],
            'cause_note' => [Rule::requiredIf(fn (): bool => $this->input('cause') === LessonNotHeldCause::Other->value), 'nullable', 'string', 'max:160'],
            'rectification_note' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cause.required' => __('Indica a causa da aula não realizada.'),
            'cause_note.required' => __('Descreve a outra causa.'),
            'rectification_note.required' => __('Indica o motivo da correção.'),
            'rectification_note.min' => __('Indica o motivo da correção (pelo menos 3 carateres).'),
            'rectification_note.max' => __('O motivo da correção tem no máximo 200 carateres.'),
        ];
    }

    public function cause(): LessonNotHeldCause
    {
        return LessonNotHeldCause::from($this->string('cause')->toString());
    }

    public function situation(): ?TeacherStrikeSituation
    {
        return $this->filled('situation') ? TeacherStrikeSituation::from($this->string('situation')->toString()) : null;
    }

    public function causeNote(): ?string
    {
        return $this->filled('cause_note') ? $this->string('cause_note')->toString() : null;
    }

    public function motive(): string
    {
        return $this->string('rectification_note')->toString();
    }
}
```

- [ ] **Step 5: Controlador e rota** — em `LessonController`, depois de `rectifyOutcomeAsAdministrator` (injetar `AnnulTaughtLesson $annulTaughtLesson` no construtor, como os outros):

```php
    /**
     * ANULAR UM «LECIONADA»: a aula não se realizou (AnnulTaughtLesson). Serve o professor (página da
     * aula) e a direção (lista «Aulas fechadas»); a policy decide. O toast diz o destino real do sumário.
     */
    public function annulTaught(AnnulTaughtLessonRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $result = $this->annulTaughtLesson->execute(
            $lesson,
            $this->user($request),
            $request->cause(),
            $request->motive(),
            $request->causeNote(),
            $request->situation(),
        );

        $destination = $result['summary_kept_in_history']
            ? __('O sumário ficou no histórico desta aula.')
            : ($result['planning'] === null ? '' : ShiftLessonPlanning::destinationSentence($result['planning'], $result['lesson']));

        Inertia::flash('toast', ['type' => 'success', 'message' => trim(__('Marcação de lecionada anulada.').' '.$destination)]);

        return back();
    }
```

Rota, depois de `lessons.outcome.rectify-administrative`:

```php
        // Anular um «Lecionada» (0.194.0): o titular do fecho ou a direção da instituição. Ver AnnulTaughtLesson.
        Route::patch('lessons/{lesson}/outcome/annul', [LessonController::class, 'annulTaught'])->name('lessons.outcome.annul');
```

Importar `App\Actions\Lessons\AnnulTaughtLesson` e `App\Http\Requests\Lessons\AnnulTaughtLessonRequest` no controlador.

- [ ] **Step 6: Correr e ver passar**

Run: `php artisan test --filter="AnnulTaughtLessonHttpTest|AnnulTaughtLessonTest"`
Expected: PASS.

- [ ] **Step 7: Pint, PHPStan, commit**

```bash
vendor/bin/pint --dirty && vendor/bin/phpstan analyse app/Policies/LessonPolicy.php app/Http/Requests/Lessons/AnnulTaughtLessonRequest.php app/Http/Controllers/LessonController.php --memory-limit=1G
git add app/Policies/LessonPolicy.php app/Http/Requests/Lessons/AnnulTaughtLessonRequest.php app/Http/Controllers/LessonController.php routes/web.php tests/Feature/Lessons/AnnulTaughtLessonHttpTest.php
git commit -m "feat(lessons): PATCH lessons/{lesson}/outcome/annul — o titular do fecho ou a direção anulam um «Lecionada»"
```

---

### Task 3: A página da aula sabe que pode anular, e o histórico mostra a anulação

**Files:**
- Modify: `app/Http/Controllers/LessonController.php` (`show()`, dentro de `'lesson' => [...]`, a seguir a `'outcome_rectification'`)
- Modify: `app/Support/Lessons/LessonOutcomeRectificationView.php` (`for()`, `changes()`)
- Test: `tests/Feature/Lessons/LessonNotHeldUiPropsTest.php` (+3)

**Interfaces:**
- Produces: prop `lesson.annul_taught: null | { summary_destination: 'next'|'history', lesson_number: int|null, closed_after: string|null }` (só quando `can('annulTaught')`); nas entradas de `outcome_rectification.history`, chave nova `by_direction: bool`, e em `changes` as linhas `lesson_number` («N.º da lição») e `summary` («Sumário removido», só para quem lê detalhes reservados — a vista já só chega ao titular).

- [ ] **Step 1: Testes que falham** — em `LessonNotHeldUiPropsTest`:

```php
    #[Test]
    public function a_taught_lesson_tells_its_closer_it_can_be_annulled_and_where_the_summary_would_go(): void
    {
        config(['lapis.lesson_not_held_enabled' => true]);
        $lesson = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
        $later = $this->makeLesson(['starts_at' => '2026-10-16 09:30:00']);
        $this->inTenant($this->organization, function () use ($lesson, $later): void {
            app(\App\Actions\Lessons\MarkLessonAsTaught::class)->execute($lesson, $this->teacher);
            app(\App\Actions\Lessons\MarkLessonAsTaught::class)->execute($later, $this->teacher);
        });

        $props = $this->props($this->teacher, "/lessons/{$lesson->ulid}")['lesson'];

        $this->assertSame('history', $props['annul_taught']['summary_destination']);
        $this->assertSame('16/10/2026', $props['annul_taught']['closed_after']);
        $this->assertNull($props['outcome_rectification']);

        $alone = $this->props($this->teacher, "/lessons/{$later->ulid}")['lesson'];
        $this->assertSame('next', $alone['annul_taught']['summary_destination']);
        $this->assertNull($alone['annul_taught']['closed_after']);
    }

    #[Test]
    public function an_observer_and_the_feature_off_see_no_annulment(): void
    {
        $lesson = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
        $this->inTenant($this->organization, fn () => app(\App\Actions\Lessons\MarkLessonAsTaught::class)->execute($lesson, $this->teacher));

        $this->assertNull($this->props($this->teacher, "/lessons/{$lesson->ulid}")['lesson']['annul_taught']);

        config(['lapis.lesson_not_held_enabled' => true]);
        $this->assertNull($this->props($this->observer(), "/lessons/{$lesson->ulid}")['lesson']['annul_taught']);
    }

    #[Test]
    public function after_the_annulment_the_history_names_the_number_and_the_removed_summary(): void
    {
        config(['lapis.lesson_not_held_enabled' => true]);
        $lesson = $this->makeLesson(['starts_at' => '2026-10-14 09:30:00']);
        $later = $this->makeLesson(['starts_at' => '2026-10-16 09:30:00']);
        $this->inTenant($this->organization, function () use ($lesson, $later): void {
            app(\App\Actions\Lessons\MarkLessonAsTaught::class)->execute($lesson, $this->teacher);
            \App\Models\LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => 'Texto removido']);
            app(\App\Actions\Lessons\MarkLessonAsTaught::class)->execute($later, $this->teacher);
            app(\App\Actions\Lessons\AnnulTaughtLesson::class)->execute($lesson->refresh(), $this->teacher, \App\Models\LessonNotHeldCause::WeatherClosure, 'Mau tempo.');
        });

        $history = $this->props($this->teacher, "/lessons/{$lesson->ulid}")['lesson']['outcome_rectification']['history'];

        $this->assertCount(1, $history);
        $this->assertSame('annulment', $history[0]['kind']);
        $this->assertFalse($history[0]['by_direction']);
        $fields = array_column($history[0]['changes'], 'from', 'field');
        $this->assertSame('Lecionada', $fields['outcome']);
        $this->assertArrayHasKey('lesson_number', $fields);
        $this->assertSame('Texto removido', $fields['summary']);
    }
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test --filter=LessonNotHeldUiPropsTest`
Expected: FAIL — `annul_taught` ausente.

- [ ] **Step 3: Controlador** — em `show()`, logo a seguir à linha `'outcome_rectification' => …,`:

```php
                // Anular um «Lecionada» (0.194.0): só para quem pode (policy), com o destino
                // do sumário já decidido pelo servidor, para o diálogo dizer o que vai acontecer.
                'annul_taught' => $this->user($request)->can('annulTaught', $lesson)
                    ? [
                        'summary_destination' => ($closedAfter = $this->lessonPlanning->closedLessonAfter($lesson)) === null ? 'next' : 'history',
                        'lesson_number' => $lesson->lesson_number,
                        'closed_after' => $closedAfter?->starts_at->setTimezone('Europe/Lisbon')->format('d/m/Y'),
                    ]
                    : null,
```

- [ ] **Step 4: Vista do histórico** — em `LessonOutcomeRectificationView::for()`, no `map`, acrescentar à entrada:

```php
                    'by_direction' => $rectification->kind === LessonOutcomeRectification::KIND_ANNULMENT
                        && (int) $rectification->rectified_by !== (int) $lesson->outcome_recorded_by,
```

e em `changes()`, antes do `return $changes;`:

```php
        if ($rectification->kind === LessonOutcomeRectification::KIND_ANNULMENT) {
            $number = $rectification->previous['lesson_number'] ?? null;
            $changes[] = ['field' => 'lesson_number', 'label' => (string) __('N.º da lição'), 'from' => $number === null ? '—' : (string) $number, 'to' => '—'];

            $summary = $rectification->previous['summary_content'] ?? null;

            if ($includeSummary && is_string($summary) && $summary !== '') {
                $changes[] = ['field' => 'summary', 'label' => (string) __('Sumário removido'), 'from' => $summary, 'to' => '—'];
            }
        }
```

com a assinatura `public static function changes(LessonOutcomeRectification $rectification, bool $includeSummary = true): array`. A `for()` chama com `true` (só o titular a recebe); a lista da direção (Task 5) chama com `false`.

- [ ] **Step 5: Correr e ver passar**

Run: `php artisan test --filter="LessonNotHeldUiPropsTest|LessonOutcomeRectificationTest"`
Expected: PASS.

- [ ] **Step 6: Pint, PHPStan, commit**

```bash
vendor/bin/pint --dirty && vendor/bin/phpstan analyse app/Http/Controllers/LessonController.php app/Support/Lessons/LessonOutcomeRectificationView.php --memory-limit=1G
git add app/Http/Controllers/LessonController.php app/Support/Lessons/LessonOutcomeRectificationView.php tests/Feature/Lessons/LessonNotHeldUiPropsTest.php
git commit -m "feat(lessons): a página da aula diz se se pode anular o «Lecionada» e o histórico mostra a anulação"
```

---

### Task 4: Diálogo e painel na página da aula (Vue)

**Files:**
- Modify: `resources/js/lib/lessonOutcomeOptions.ts`
- Modify: `resources/js/components/lessons/LessonOutcomeDialog.vue`
- Modify: `resources/js/components/lessons/LessonOutcomePanel.vue`
- Modify: `resources/js/pages/lessons/Show.vue` (tipo e binding)
- Test: `resources/js/components/lessons/LessonOutcomeDialog.annul.test.ts` (novo), `resources/js/components/lessons/LessonOutcomePanel.test.ts` (+2)

**Interfaces:**
- Consumes: prop `lesson.annul_taught` (Task 3); `PATCH /lessons/{ulid}/outcome/annul` (Task 2).
- Produces: tipo `AnnulTaught = { summary_destination: 'next' | 'history'; lesson_number: number | null; closed_after: string | null }`; `LessonOutcomeDialog` prop `annul?: AnnulTaught | null`; `LessonOutcomePanel` prop `annulTaught?: AnnulTaught | null`; botão `[data-testid="open-annul-dialog"]`; aviso `[data-testid="annul-warning"]`; `OutcomeRectification.history[].by_direction`.

- [ ] **Step 1: Tipos** — em `lessonOutcomeOptions.ts`, acrescentar `by_direction?: boolean;` às entradas de `history` e, no fim:

```ts
/** Anular um «Lecionada» (0.194.0): só chega a quem pode; o destino do sumário é decidido no servidor. */
export type AnnulTaught = {
    summary_destination: 'next' | 'history';
    lesson_number: number | null;
    closed_after: string | null;
};
```

- [ ] **Step 2: Testes Vue que falham** — `LessonOutcomeDialog.annul.test.ts` (copiar o arranque de `LessonOutcomeDialog.notHeld.test.ts`: o mock de `useForm` com `patch`/`post`/`transform`, as `notHeldOptions` de exemplo):

```ts
describe('LessonOutcomeDialog — anular «Lecionada»', () => {
    it('abre só com «Aula não realizada», pede causa e motivo, e avisa do que vai acontecer', async () => {
        const wrapper = mountDialog({ annul: { summary_destination: 'history', lesson_number: 34, closed_after: '13/11/2026' }, open: true });
        await nextTick();

        expect(wrapper.find('[data-testid="outcome-teacher-absent"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="outcome-not-held"]').exists()).toBe(false);
        const warning = wrapper.find('[data-testid="annul-warning"]').text().replace(/\s+/g, ' ');
        expect(warning).toContain('perde o n.º 34');
        expect(warning).toContain('faltas registadas nesta aula são apagadas');
        expect(warning).toContain('13/11/2026');
        expect(warning).toContain('fica no histórico');
        expect(wrapper.find('#outcome-cause').exists()).toBe(true);
        expect(wrapper.find('#rectification-note').exists()).toBe(true);
    });

    it('sem aulas fechadas a seguir diz que o sumário passa para a aula seguinte', async () => {
        const wrapper = mountDialog({ annul: { summary_destination: 'next', lesson_number: 12, closed_after: null }, open: true });
        await nextTick();

        expect(wrapper.find('[data-testid="annul-warning"]').text()).toContain('passa para a aula seguinte');
    });

    it('envia PATCH para a rota de anulação com causa e motivo', async () => {
        const wrapper = mountDialog({ annul: { summary_destination: 'next', lesson_number: 12, closed_after: null }, open: true });
        await nextTick();
        const form = lastForm();
        form.cause = 'weather_closure';
        form.rectification_note = 'Escola encerrada por mau tempo.';

        await wrapper.find('form').trigger('submit');

        expect(form.patch).toHaveBeenCalledWith('/lessons/lesson-1/outcome/annul', expect.anything());
        expect(form.transform).toHaveBeenCalled();
    });
});
```

(`mountDialog` e `lastForm` são os helpers que o ficheiro `.notHeld.test.ts` já tem com outro nome — reutilizar os desse ficheiro, importando ou copiando; `lessonUlid` do helper é `'lesson-1'`.) Em `LessonOutcomePanel.test.ts`:

```ts
    it('mostra «Corrigir: a aula não se realizou» quando a página diz que se pode anular', () => {
        const wrapper = mountPanel({ outcome: 'taught', outcomeLabel: 'Lecionada', annulTaught: { summary_destination: 'next', lesson_number: 34, closed_after: null } });

        expect(wrapper.find('[data-testid="open-annul-dialog"]').text()).toContain('Corrigir: a aula não se realizou');
    });

    it('não o mostra sem a prop', () => {
        const wrapper = mountPanel({ outcome: 'taught', outcomeLabel: 'Lecionada' });

        expect(wrapper.find('[data-testid="open-annul-dialog"]').exists()).toBe(false);
    });
```

- [ ] **Step 3: Diálogo** — em `LessonOutcomeDialog.vue`:
  - prop `annul?: AnnulTaught | null;` (importar o tipo);
  - no `watch(open)`, antes do ramo `rectify`: `if (value && props.annul) { form.outcome = 'not_held'; form.cause = ''; form.situation = ''; form.cause_note = ''; form.rectification_note = ''; form.clearErrors(); return; }`;
  - em `submit()`: a condição de motivo curto passa a `(props.rectify || props.annul) && form.rectification_note.trim().length < 3`; o `motive()` inclui `props.annul`; o envio:

```ts
    const url = props.annul ? `/lessons/${props.lessonUlid}/outcome/annul` : `/lessons/${props.lessonUlid}/outcome`;
    const method = props.rectify || props.annul ? 'patch' : 'post';
    form.transform((data) => {
        if (props.annul) {
            return {
                cause: data.cause,
                ...(data.cause === 'strike' && data.situation !== '' ? { situation: data.situation } : {}),
                ...(data.cause === 'other' ? { cause_note: data.cause_note.trim() } : {}),
                rectification_note: data.rectification_note.trim(),
            };
        }
        // … os três ramos de hoje, inalterados …
    })[method](url, { … });
```

  - template: título `annul ? 'Corrigir: a aula não se realizou' : …`; o `fieldset` dos resultados fica `v-if="!annul"` (em modo anulação não há escolha); antes dos campos da causa, o aviso:

```html
                <div v-if="annul" class="space-y-1 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" data-testid="annul-warning">
                    <p>
                        Esta aula deixa de contar como lecionada<template v-if="annul.lesson_number !== null"> e perde o n.º {{ annul.lesson_number }}</template>.
                        As faltas registadas nesta aula são apagadas.
                    </p>
                    <p v-if="annul.summary_destination === 'next'">O sumário e a preparação passam para a aula seguinte.</p>
                    <p v-else>
                        A aula de {{ annul.closed_after }}, depois desta, já está fechada: o sumário é removido (fica no histórico desta aula)
                        e a numeração fica com uma lacuna. Nenhuma aula lecionada muda de número.
                    </p>
                </div>
```

  - os campos da causa (`#outcome-cause`, especificação, descrição) já são mostrados quando `form.outcome === 'not_held' && notHeldOptions`; garantir que isso vale também em modo anulação (o `v-if` desse bloco deve ser `(form.outcome === 'not_held' || annul) && notHeldOptions`);
  - o campo do motivo (`#rectification-note`) mostra-se com `rectify || annul`.

- [ ] **Step 4: Painel** — em `LessonOutcomePanel.vue`: prop `annulTaught?: AnnulTaught | null;`, `const annulOpen = ref(false);`, e dentro da `<section>` do resultado, depois do bloco `outcomeRectification`:

```html
        <div v-if="annulTaught && notHeldOptions" class="pt-2" data-testid="lesson-annul-taught">
            <LessonOutcomeDialog
                v-model:open="annulOpen"
                :lesson-ulid="lessonUlid"
                :reasons="reasons"
                :not-held-options="notHeldOptions"
                :annul="annulTaught"
                :lesson-context="lessonContext ?? null"
                @submitting="emit('submitting', $event)"
            >
                <Button type="button" variant="outline" class="min-h-11" data-testid="open-annul-dialog" @click="annulOpen = true">
                    <Undo2 class="size-4" /> Corrigir: a aula não se realizou
                </Button>
            </LessonOutcomeDialog>
        </div>
```

  (importar `Undo2` de `@lucide/vue`). No histórico, na linha do autor: `<span v-if="entry.by_direction"> · pela direção</span>`.

- [ ] **Step 5: Página** — em `pages/lessons/Show.vue`: no tipo de `lesson`, `annul_taught?: AnnulTaught | null;` (importar o tipo) e no `<LessonOutcomePanel …>` a prop `:annul-taught="lesson.annul_taught ?? null"`.

- [ ] **Step 6: Correr e ver passar**

Run: `npx vitest run resources/js/components/lessons resources/js/pages/lessons && npx vue-tsc --noEmit && npx eslint --fix resources/js/components/lessons resources/js/pages/lessons resources/js/lib/lessonOutcomeOptions.ts`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/js/lib/lessonOutcomeOptions.ts resources/js/components/lessons/ resources/js/pages/lessons/Show.vue
git commit -m "feat(lessons): «Corrigir: a aula não se realizou» na página da aula — diálogo de anulação com causa, motivo e aviso"
```

---

### Task 5: A direção — lista «Aulas fechadas» em «Retificações»

**Files:**
- Create: `app/Http/Controllers/LessonAnnulmentController.php`
- Modify: `app/Http/Controllers/ClassificationRectificationController.php` (`index()`: prop `lessonClasses`)
- Modify: `routes/web.php` (depois de `classifications.rectifications.show`)
- Create: `resources/js/pages/classifications/rectifications/Lessons.vue`
- Modify: `resources/js/pages/classifications/rectifications/Index.vue`
- Test: `tests/Feature/Lessons/LessonAnnulmentControllerTest.php`, `resources/js/pages/classifications/rectifications/Lessons.test.ts`

**Interfaces:**
- Consumes: `Gate::authorize('reviewRectifications', $class)`; `LessonOutcomeRectificationView::changes($rectification, false)` (Task 3); `LessonOutcomeDialog` com `annul` (Task 4).
- Produces: `GET /classes/{class}/retificacoes/aulas` → página `classifications/rectifications/Lessons` com props `schoolClass: {ulid,label,subject,academic_year}`, `lessons: list<{ulid, date, time, context_label, outcome, outcome_label, lesson_number, closed_by, can_annul}>`, `annulments: list<{at, by, lesson_date, lesson_number, motive}>`; `Index` recebe `lessonClasses: list<{ulid,label,subject,academic_year}>`.

- [ ] **Step 1: Teste PHP que falha**

```php
<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\AnnulTaughtLesson;
use App\Actions\Lessons\MarkLessonAsTaught;
use App\Models\LessonNotHeldCause;
use App\Models\LessonSummary;
use App\Models\OrganizationType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

class LessonAnnulmentControllerTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        config(['lapis.lesson_not_held_enabled' => true]);
    }

    private function head(): User
    {
        $head = User::factory()->create();
        DB::table('organizations')->where('id', $this->organization->id)->update(['type' => OrganizationType::Institutional->value, 'owner_id' => $head->getKey()]);
        DB::table('organization_memberships')->insertOrIgnore(['organization_id' => $this->organization->id, 'user_id' => $head->getKey(), 'joined_at' => now()]);

        return $head;
    }

    private function props(User $user, string $url): array
    {
        $response = $this->actingAs($user)->withSession(['organization_id' => $this->organization->id])->get($url)->assertOk();

        return AssertableInertia::fromTestResponse($response)->toArray()['props'];
    }

    #[Test]
    public function the_head_sees_the_closed_lessons_without_summary_or_attendance_and_may_annul_the_taught_ones(): void
    {
        $head = $this->head();
        $lesson = $this->makeLesson(['starts_at' => '2026-11-11 10:30:00']);
        $open = $this->makeLesson(['starts_at' => '2026-11-18 10:30:00']);
        $this->inTenant($this->organization, function () use ($lesson): void {
            app(MarkLessonAsTaught::class)->execute($lesson, $this->teacher);
            LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => 'Segredo do professor']);
        });

        $props = $this->props($head, "/classes/{$this->schoolClass->ulid}/retificacoes/aulas");

        $this->assertCount(1, $props['lessons']);
        $row = $props['lessons'][0];
        $this->assertSame($lesson->ulid, $row['ulid']);
        $this->assertSame('taught', $row['outcome']);
        $this->assertSame('Lecionada', $row['outcome_label']);
        $this->assertSame($this->teacher->name, $row['closed_by']);
        $this->assertTrue($row['can_annul']);
        $this->assertStringNotContainsString('Segredo', json_encode($props));
        $this->assertSame([], $props['annulments']);
        $this->assertArrayNotHasKey('summary', $row);
        $this->assertArrayNotHasKey('attendance', $row);
        $this->assertNotContains($open->ulid, array_column($props['lessons'], 'ulid'));
    }

    #[Test]
    public function after_an_annulment_the_list_shows_it_without_the_summary_text(): void
    {
        $head = $this->head();
        $lesson = $this->makeLesson(['starts_at' => '2026-11-11 10:30:00']);
        $later = $this->makeLesson(['starts_at' => '2026-11-13 10:30:00']);
        $this->inTenant($this->organization, function () use ($lesson, $later, $head): void {
            app(MarkLessonAsTaught::class)->execute($lesson, $this->teacher);
            LessonSummary::query()->create(['lesson_id' => $lesson->id, 'content' => 'Segredo do professor']);
            app(MarkLessonAsTaught::class)->execute($later, $this->teacher);
            app(AnnulTaughtLesson::class)->execute($lesson->refresh(), $head, LessonNotHeldCause::WeatherClosure, 'Mau tempo.');
        });

        $props = $this->props($head, "/classes/{$this->schoolClass->ulid}/retificacoes/aulas");

        $this->assertCount(1, $props['annulments']);
        $this->assertSame('Mau tempo.', $props['annulments'][0]['motive']);
        $this->assertSame($head->name, $props['annulments'][0]['by']);
        $this->assertStringNotContainsString('Segredo', json_encode($props));
    }

    #[Test]
    public function a_teacher_who_is_not_the_head_gets_403_and_the_index_lists_the_classes_for_the_head(): void
    {
        $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id])
            ->get("/classes/{$this->schoolClass->ulid}/retificacoes/aulas")->assertForbidden();

        $head = $this->head();
        $props = $this->props($head, '/retificacoes');
        $this->assertSame($this->schoolClass->ulid, $props['lessonClasses'][0]['ulid']);
    }
}
```

Se `/retificacoes` exigir o módulo `institution_admin` ligado (ver `config/navigation.php` e `Gate::authorize('viewRectifications', $organization)`), copiar de `tests/Feature/Assessment/RectificationsPageTest.php` a forma de o ligar no `setUp`.

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test --filter=LessonAnnulmentControllerTest`
Expected: FAIL (404).

- [ ] **Step 3: Controlador novo**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonOutcomeRectification;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Aulas fechadas» de uma turma, para a direção da instituição (0.194.0): a porta por onde anula um
 * «Lecionada» de uma turma que não leciona. CORRIGIR ≠ CONSULTAR: sem sumário, sem faltas, sem causa
 * detalhada nem dado reservado — só o que a semana já mostra a qualquer colega. A página da aula
 * continua 403 para quem não leciona.
 */
class LessonAnnulmentController extends Controller
{
    public function index(SchoolClass $class): Response
    {
        Gate::authorize('reviewRectifications', $class);

        $lessons = Lesson::query()
            ->where('class_id', $class->id)
            ->where(fn ($query) => $query->whereNotNull('outcome')->orWhere('status', LessonStatus::Taught))
            ->with(['classGroup', 'outcomeRecorder'])
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->limit(200)
            ->get();

        $annulments = LessonOutcomeRectification::query()
            ->where('kind', LessonOutcomeRectification::KIND_ANNULMENT)
            ->whereIn('lesson_id', Lesson::query()->where('class_id', $class->id)->select('id'))
            ->with(['rectifier', 'lesson'])
            ->orderByDesc('rectified_at')->orderByDesc('id')
            ->get();

        return Inertia::render('classifications/rectifications/Lessons', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject?->name,
                'academic_year' => $class->academicYear->label,
            ],
            'lessons' => $lessons->map(fn (Lesson $lesson): array => [
                'ulid' => $lesson->ulid,
                'date' => $lesson->starts_at->setTimezone('Europe/Lisbon')->format('d/m/Y'),
                'time' => $lesson->starts_at->setTimezone('Europe/Lisbon')->format('H:i'),
                'context_label' => $lesson->contextLabel(),
                'outcome' => $lesson->outcome?->value ?? LessonOutcome::Taught->value,
                'outcome_label' => $lesson->outcome?->label() ?? LessonOutcome::Taught->label(),
                'lesson_number' => $lesson->lesson_number,
                'closed_by' => $lesson->outcomeRecorder?->name,
                'can_annul' => $lesson->isTaught() && $lesson->lesson_unit_key === null,
            ])->values()->all(),
            'annulments' => $annulments->map(fn (LessonOutcomeRectification $rectification): array => [
                'at' => $rectification->rectified_at->toIso8601String(),
                'by' => $rectification->rectifier?->name,
                'lesson_date' => $rectification->lesson?->starts_at->setTimezone('Europe/Lisbon')->format('d/m/Y'),
                'lesson_number' => $rectification->previous['lesson_number'] ?? null,
                'motive' => $rectification->motive,
            ])->values()->all(),
        ]);
    }
}
```

Se `Lesson` não tiver a relação `outcomeRecorder()`, acrescentá-la: `public function outcomeRecorder(): BelongsTo { return $this->belongsTo(User::class, 'outcome_recorded_by'); }`. Se `LessonOutcomeRectification` não tiver `lesson()`, acrescentar `belongsTo(Lesson::class)`.

- [ ] **Step 4: Rota e índice**

```php
        // A direção: as aulas fechadas de uma turma, para anular um «Lecionada» (0.194.0). Sem detalhes reservados.
        Route::get('classes/{class}/retificacoes/aulas', [LessonAnnulmentController::class, 'index'])->name('lessons.annulments.index');
```

(colocar ANTES de `classes/{class}/retificacoes/{period}`, senão «aulas» é lido como um período). Em `ClassificationRectificationController::index()`, acrescentar ao `Inertia::render`:

```php
            // Todas as turmas da instituição, para a lista «Aulas fechadas» (0.194.0) — a de cima só
            // tem as que têm períodos encerrados com classificações publicadas.
            'lessonClasses' => $classes->map(fn (SchoolClass $class): array => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject?->name,
                'academic_year' => $class->academicYear->label,
            ])->values()->all(),
```

- [ ] **Step 5: Páginas Vue** — `Index.vue`: prop `lessonClasses: LessonClassEntry[]` (tipo `{ulid; label; subject: string | null; academic_year: string}`) e, depois da lista das classificações, uma secção:

```html
        <section class="space-y-2" data-testid="annulment-classes">
            <h2 class="text-base font-semibold">Aulas fechadas</h2>
            <p class="text-sm text-muted-foreground">
                Anular uma aula marcada como lecionada por engano (a aula não se realizou). Sem acesso ao sumário nem às faltas.
            </p>
            <ul class="divide-y divide-border rounded-xl border border-border">
                <li v-for="schoolClass in lessonClasses" :key="schoolClass.ulid">
                    <Link :href="`/classes/${schoolClass.ulid}/retificacoes/aulas`" class="flex min-h-11 items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-muted/40">
                        <span class="font-medium">{{ schoolClass.label }}<span v-if="schoolClass.subject" class="font-normal text-muted-foreground"> — {{ schoolClass.subject }}</span></span>
                        <span class="text-muted-foreground">{{ schoolClass.academic_year }}</span>
                    </Link>
                </li>
            </ul>
        </section>
```

`Lessons.vue` (novo): `Head`, `Heading` «Aulas fechadas — {turma}», ligação «← Retificações», tabela com data, hora, turma/grupo, resultado, n.º, quem fechou; na linha com `can_annul`, botão «Anular lecionada» que abre UM `LessonOutcomeDialog` da página (`v-model:open`, `lessonUlid` da linha escolhida, `annul: { summary_destination: 'history', lesson_number, closed_after: null }` — a direção não sabe o destino; o aviso usa a forma genérica: ver nota abaixo), `notHeldOptions` vindas de `NotHeldOptions::forPage()` (passar na prop `notHeldOptions` do controlador: acrescentar `'notHeldOptions' => NotHeldOptions::forPage()`), `reasons: []`. Por baixo, a tabela «Anulações» com data, quem, aula, n.º anterior e motivo. **Nota:** para a direção, o diálogo recebe `annul.summary_destination = 'history'` e `closed_after = null`; no aviso, quando `closed_after === null` e `summary_destination === 'history'`, o texto é «O sumário do professor passa para a aula seguinte ou, se já houver aulas fechadas depois desta, fica no histórico desta aula.» — acrescentar esse terceiro ramo ao aviso do diálogo (Task 4).

- [ ] **Step 6: Teste Vue** — `Lessons.test.ts`: monta a página com duas linhas (uma `taught` com `can_annul: true`, uma `not_held` com `false`), afirma que só a primeira tem o botão «Anular lecionada», que não há texto de sumário em lado nenhum, e que ao clicar abre o diálogo com `[data-testid="annul-warning"]`. Mock de `@inertiajs/vue3` como em `rectifications/Index.test.ts`.

- [ ] **Step 7: Correr e ver passar**

Run: `php artisan test --filter="LessonAnnulmentControllerTest|RectificationsPageTest" && npx vitest run resources/js/pages/classifications/rectifications && npx vue-tsc --noEmit && npx eslint --fix resources/js/pages/classifications`
Expected: PASS.

- [ ] **Step 8: Pint, PHPStan, commit**

```bash
vendor/bin/pint --dirty && vendor/bin/phpstan analyse app/Http/Controllers/LessonAnnulmentController.php app/Http/Controllers/ClassificationRectificationController.php --memory-limit=1G
git add app/Http/Controllers/LessonAnnulmentController.php app/Http/Controllers/ClassificationRectificationController.php routes/web.php resources/js/pages/classifications/rectifications/ app/Models/Lesson.php app/Models/LessonOutcomeRectification.php tests/Feature/Lessons/LessonAnnulmentControllerTest.php resources/js/components/lessons/LessonOutcomeDialog.vue
git commit -m "feat(rectifications): «Aulas fechadas» — a direção anula um «Lecionada» de uma turma que não leciona, sem ler sumário nem faltas"
```

---

### Task 6: O serviço letivo passa a contar a aula como não realizada

**Files:**
- Test: `tests/Feature/Lessons/LessonServiceReportTest.php` (+1)

- [ ] **Step 1: O teste** (usar os helpers que o ficheiro já tem para criar e fechar aulas; o nome do helper de fecho vê-se em `setUp`/no primeiro teste):

```php
    #[Test]
    public function an_annulled_lesson_counts_as_not_held_and_no_longer_as_given(): void
    {
        config(['lapis.lesson_not_held_enabled' => true]);
        $lesson = $this->makeLesson(['starts_at' => '2026-11-11 10:30:00']);
        $this->inTenant($this->organization, fn () => app(\App\Actions\Lessons\MarkLessonAsTaught::class)->execute($lesson, $this->teacher));

        $before = $this->report($this->teacher)['report']['totals'];

        $this->inTenant($this->organization, fn () => app(\App\Actions\Lessons\AnnulTaughtLesson::class)->execute($lesson->refresh(), $this->teacher, \App\Models\LessonNotHeldCause::WeatherClosure, 'Mau tempo.'));

        $after = $this->report($this->teacher)['report']['totals'];

        $this->assertSame($before['not_held'] + 1, $after['not_held']);
        $this->assertSame($before['counted_as_taught'] - 1, $after['counted_as_taught']);
    }
```

Adaptar as chaves (`not_held`, `counted_as_taught`) ao que `TeacherLessonServiceReport::for()` devolve de facto (ver linhas 60–70 do serviço).

- [ ] **Step 2: Correr e ver passar**

Run: `php artisan test --filter=LessonServiceReportTest`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Lessons/LessonServiceReportTest.php
git commit -m "test(lessons): uma aula anulada conta como não realizada no serviço letivo"
```

---

### Task 7: Versão, changelog, documentação e verificação completa

**Files:**
- Modify: `config/app.php` (`'version' => '0.194.0'`), `CHANGELOG.md`, `docs/lesson-outcome-recovery.md` (secção «Correções do resultado»)

- [ ] **Step 1: Versão e changelog** — no topo do `CHANGELOG.md`, antes de `## [0.193.0]`:

```markdown
## [0.194.0] — 2026-11-12

> **Anular um «Lecionada»: a aula não se realizou.** Uma aula fechada por
> engano como lecionada (sumário, número, assiduidade) não tinha volta: a
> correção do resultado recusava-a e o registo retroativo só aceita aulas
> vazias. Passa a haver «Corrigir: a aula não se realizou» na página da aula,
> para quem a fechou, e «Anular lecionada» na área «Retificações», para a
> direção de uma organização institucional, sem ler sumário nem faltas. A aula
> passa a «Aula não realizada» com causa e motivo e perde o número; a
> assiduidade é apagada; sem aulas fechadas a seguir, o sumário passa para a
> aula seguinte e as abertas renumeram-se; com aulas fechadas a seguir, o
> sumário fica no histórico da aula (só o professor o lê) e a numeração fica
> com a lacuna — nenhuma aula lecionada muda de número. Tudo no histórico de
> correções e na auditoria. Sem migrations.

### Adicionado

- `AnnulTaughtLesson`, `PATCH lessons/{lesson}/outcome/annul`,
  `LessonPolicy::annulTaught`, `LessonOutcomeRectification::KIND_ANNULMENT`.
- Página «Aulas fechadas» por turma em «Retificações» (`classes/{turma}/retificacoes/aulas`).
```

- [ ] **Step 2: Documentação** — em `docs/lesson-outcome-recovery.md`, no fim de «## Correções do resultado», acrescentar:

```markdown
- **Anular «Lecionada» (0.194.0):** `AnnulTaughtLesson` (`PATCH /lessons/{lesson}/outcome/annul`),
  pelo titular do fecho ou pela direção da instituição (`LessonPolicy::annulTaught`). Apaga a
  assiduidade, tira o número (manual passa à frente), desce o sumário para a aula seguinte quando não
  há fechadas a seguir (senão guarda-o em `previous.summary_*` da linha `kind = annulment`, que só o
  titular lê) e renumera sem tocar em lecionadas. O titular (`outcome_recorded_by`) não muda. Rollback
  de código: a aula fica como qualquer «não realizada»; a linha de histórico com `kind = annulment`
  é ignorada pelo código antigo, que só itera os seis campos.
```

- [ ] **Step 3: Verificação completa**

```bash
export PATH="/c/Users/PCUser/.config/herd/bin/php84:$PATH"
vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G
php artisan test --filter="ReleaseVersion|AnnulTaught|LessonAnnulment|LessonNotHeld|LessonOutcome|LessonServiceReport|RectificationsPage|MarkLessonAsTaught|BatchTaught|BasePagesCharacterizationTest|BaseSurfacesUnchangedTest"
npm run build && npx vue-tsc --noEmit && npx eslint resources/js && npx vitest run
```

- [ ] **Step 4: Commit, push, PR**

```bash
git add config/app.php CHANGELOG.md docs/lesson-outcome-recovery.md
git commit -m "chore(release): 0.194.0 — anular um «Lecionada»: a aula não se realizou"
git push -u origin feat/annul-taught-lesson
gh pr create --base main --title "Anular um «Lecionada»: a aula não se realizou (0.194.0)" --body-file -
```

QA no browser isolado (`lapispro-qa-browser-isolado`): ligar a flag no env do QA (`LAPIS_LESSON_NOT_HELD_ENABLED=true`), criar uma aula, marcá-la lecionada com sumário, fechar uma seguinte, abrir a primeira, «Corrigir: a aula não se realizou», confirmar o aviso de lacuna e o resultado; repetir sem aula fechada a seguir e ver o sumário na seguinte.

---

## Self-review

- **Cobertura da spec:** §5 guardas/efeitos → Task 1; §5 policy → Task 2; §6 histórico → Tasks 1 e 3; §7 auditoria → Task 1; §8.1 página da aula → Tasks 3 e 4; §8.2 direção → Task 5; §9 contagens → Task 6; §11 entrega → Task 7. **Desvio declarado da spec:** a página da aula recebe uma prop própria `annul_taught` em vez de `outcome_rectification.annul`, porque `outcome_rectification` é nula numa aula lecionada (exige `rectifyOutcome`) e misturar as duas obrigaria a mudar a regra de quem a recebe; o conteúdo é o da spec (`summary_destination`, n.º, aula fechada a seguir).
- **Placeholders:** nenhum «TBD»; os pontos em que o implementador tem de confirmar um nome existente (helper de fecho no `LessonServiceReportTest`, chave da sessão, relação `outcomeRecorder`) dizem onde olhar e o que fazer.
- **Consistência:** `AnnulTaughtLesson::execute(Lesson, User, LessonNotHeldCause, string, ?string, ?TeacherStrikeSituation)` igual nas Tasks 1, 2, 3, 5 e 6; `KIND_ANNULMENT = 'annulment'` nas Tasks 1, 3 e 5; `annul_taught`/`AnnulTaught` com as mesmas três chaves nas Tasks 3, 4 e 5; `data-testid` `open-annul-dialog` e `annul-warning` nas Tasks 4 e 5; a rota `lessons/{lesson}/outcome/annul` nas Tasks 2, 4 e 5.
