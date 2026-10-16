<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MarkLessonAsTaught;
use App\Actions\Lessons\ReconcileLessonsWithSlotValidity;
use App\Actions\Lessons\RecordLessonOutcome;
use App\Domain\Reporting\SectionKey;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TeacherAbsenceReason;
use App\Models\User;
use App\Services\Lessons\ClassLessonRecord;
use App\Services\Lessons\LessonNumbering;
use App\Services\Reporting\Export\SectionTables;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * 0.169.0 — o professor indica o número da lição.
 *
 * Calendário de 2026: as quintas-feiras de setembro/outubro são 17 e 24/09 e
 * 01, 08, 15, 22 e 29/10; as segundas 05, 12 e 19/10, as terças 06 e 13/10.
 * O tempo do horário é às quintas às 09:30, e cada aula nasce pela via normal
 * (o endpoint de materialização), tal como na aplicação.
 */
class ManualLessonNumberTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // RELÓGIO FIXO ANTES de criar qualquer dado: a numeração liga T1/T2 a
        // partir de `slot.created_at`, e a vigência do horário depende do dia.
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        $this->makeSlot();
    }

    // 1) ---------------------------------------------------------------------

    #[Test]
    public function the_first_registered_summary_can_be_lesson_eleven_and_everything_after_it_follows(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $this->assertSame(['2026-10-08' => '1', '2026-10-15' => '2'], $this->numbers());

        $this->save($this->on('2026-10-08'), ['lesson_number' => 11])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Sumário guardado.')
                && str_contains($message, 'Lição 11.')
                && str_contains($message, 'a partir da Lição 12'));

        $this->assertSame(['2026-10-08' => '11', '2026-10-15' => '12'], $this->numbers());

        // Uma semana nova, materializada depois, continua a sequência.
        $this->weeks('2026-10-22', '2026-10-29');
        $this->assertSame(['2026-10-08' => '11', '2026-10-15' => '12', '2026-10-22' => '13', '2026-10-29' => '14'], $this->numbers());

        $this->asTeacher()->get("/lessons/{$this->on('2026-10-08')->ulid}")
            ->assertInertia(fn ($page) => $page
                ->where('lesson.lesson_number', 11)
                ->where('lesson.lesson_number_end', null)
                ->where('lesson.lesson_number_manual', true)
                ->where('lesson.can_set_number', true)
                ->etc());
    }

    // 2) ---------------------------------------------------------------------

    #[Test]
    public function a_lesson_that_is_two_lessons_makes_the_next_one_start_after_it_and_reads_as_a_range(): void
    {
        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22');

        $this->save($this->on('2026-10-08'), ['lesson_number' => 11, 'lesson_number_end' => 12])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Lições 11–12.'));

        $this->assertSame(['2026-10-08' => '11–12', '2026-10-15' => '13', '2026-10-22' => '14'], $this->numbers());

        // A apresentação e a exportação devolvem «11–12».
        $this->asTeacher()->get('/lessons?week=2026-10-05')
            ->assertInertia(fn ($page) => $page
                ->where('lessons.0.lesson_number', 11)
                ->where('lessons.0.lesson_number_end', 12)
                ->where('lessons.0.lesson_number_manual', true)
                ->etc());

        $cells = $this->inTenant($this->organization, function (): array {
            $tables = SectionTables::for(
                SectionKey::ClassLessons->value,
                app(ClassLessonRecord::class)->for($this->schoolClass->fresh() ?? $this->schoolClass, null, null, CarbonImmutable::parse('2026-12-01 10:00:00', 'Europe/Lisbon')),
            );

            return collect($tables)->pluck('rows')->flatten()->all();
        });
        $this->assertContains('11–12', $cells);
    }

    // 3) ---------------------------------------------------------------------

    #[Test]
    public function a_later_correction_renumbers_only_the_open_lessons_around_it_and_never_the_recorded_ones(): void
    {
        $taught = $this->makeLesson(['starts_at' => '2026-10-01 09:30:00', 'ends_at' => '2026-10-01 10:20:00', 'status' => LessonStatus::Taught, 'lesson_number' => 1]);
        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22', '2026-10-29');
        $this->assertSame(['2026-10-01' => '1', '2026-10-08' => '2', '2026-10-15' => '3', '2026-10-22' => '4', '2026-10-29' => '5'], $this->numbers());

        $this->save($this->on('2026-10-22'), ['lesson_number' => 9])->assertSessionHasNoErrors();

        // As do intervalo mantêm as suas; a seguinte continua na 10.
        $this->assertSame(['2026-10-01' => '1', '2026-10-08' => '2', '2026-10-15' => '3', '2026-10-22' => '9', '2026-10-29' => '10'], $this->numbers());

        // Sem lugar entre a Lição 1 e a 3: a do meio fica SEM número, em vez de repetir.
        $this->save($this->on('2026-10-22'), ['lesson_number' => 3])->assertSessionHasNoErrors();
        $this->assertSame(['2026-10-01' => '1', '2026-10-08' => '2', '2026-10-15' => null, '2026-10-22' => '3', '2026-10-29' => '4'], $this->numbers());

        $this->inTenant($this->organization, fn () => $this->assertSame(1, $taught->refresh()->lesson_number));
    }

    // 4) ---------------------------------------------------------------------

    #[Test]
    public function a_manual_number_is_never_erased_by_anything_that_happens_around_it(): void
    {
        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22');
        $manual = $this->on('2026-10-08');
        $this->save($manual, ['lesson_number' => 11])->assertSessionHasNoErrors();

        // Reabrir e voltar a guardar o texto SEM campos de numeração.
        $this->save($manual, [], 'Segunda versão do texto.')->assertSessionHasNoErrors();
        $this->assertManual('2026-10-08', 11);

        // Voltar a guardar com o mesmo número: nada muda, continua manual.
        $this->save($manual, ['lesson_number' => 11], 'Terceira versão do texto.')->assertSessionHasNoErrors();
        $this->assertManual('2026-10-08', 11);

        // Materializar uma semana nova.
        $this->weeks('2026-10-29');
        $this->assertManual('2026-10-08', 11);
        $this->assertSame(['2026-10-08' => '11', '2026-10-15' => '12', '2026-10-22' => '13', '2026-10-29' => '14'], $this->numbers());

        // Eliminar OUTRA aula.
        $this->asTeacher()->delete("/lessons/{$this->on('2026-10-15')->ulid}")->assertSessionHasNoErrors();
        $this->assertManual('2026-10-08', 11);
        $this->assertSame(['2026-10-08' => '11', '2026-10-22' => '12', '2026-10-29' => '13'], $this->numbers());

        // Inserir uma aula noutro ponto.
        $this->asTeacher()->post('/lessons/insert', [
            'class' => $this->schoolClass->ulid,
            'insert_at' => '2026-10-22',
        ])->assertSessionHasNoErrors();
        $this->assertManual('2026-10-08', 11);
        $this->assertSame([11, 12, 13, 14], array_map('intval', array_values($this->numbers())));

        // Reconciliar o horário (a vigência acaba a 22/10: saem as vazias a seguir).
        $this->inTenant($this->organization, function (): void {
            RecurringLessonSlot::query()->update(['ends_on' => '2026-10-22']);
            $this->assertSame(2, app(ReconcileLessonsWithSlotValidity::class)->execute((int) $this->schoolClass->id)['removed']);
        });
        $this->assertManual('2026-10-08', 11);
        $this->assertSame(['2026-10-08' => '11', '2026-10-22' => '12'], $this->numbers());

        // Marcar a própria aula como lecionada.
        $this->inTenant($this->organization, fn () => app(MarkLessonAsTaught::class)->execute($manual->refresh(), $this->teacher, consolidateAttendance: false));
        $this->assertManual('2026-10-08', 11);
        $this->inTenant($this->organization, fn () => $this->assertTrue($manual->refresh()->isTaught()));
    }

    // 5) ---------------------------------------------------------------------

    #[Test]
    public function editing_an_old_taught_number_does_not_renumber_the_others_but_warns_about_the_incoherence(): void
    {
        $taught = [];

        foreach (['2026-09-17', '2026-09-24', '2026-10-01', '2026-10-08', '2026-10-15'] as $index => $date) {
            $taught[] = $this->makeLesson([
                'starts_at' => "{$date} 09:30:00", 'ends_at' => "{$date} 10:20:00",
                'status' => LessonStatus::Taught, 'lesson_number' => $index + 1,
            ]);
        }

        $this->save($taught[2], ['lesson_number' => 13])
            ->assertSessionHasNoErrors()
            // O aviso chega ao ecrã no toast (a flash de sessão não é mostrada).
            ->assertSessionHas('inertia.flash_data', fn ($flash): bool => ($flash['toast']['type'] ?? null) === 'warning'
                && str_contains((string) ($flash['toast']['message'] ?? ''), 'vem depois desta e tem um número inferior'));

        $this->assertSame([1, 2, 13, 4, 5], $this->inTenant($this->organization, fn (): array => Lesson::query()->orderBy('starts_at')->pluck('lesson_number')->all()));

        // E o plano diz o mesmo à pré-visualização, sem efeitos sobre as outras.
        $this->asTeacher()->postJson("/lessons/{$taught[3]->ulid}/number/preview", ['mode' => 'manual', 'start' => 20])
            ->assertOk()
            ->assertJsonPath('effects', [])
            ->assertJsonPath('requires_confirmation', false);
    }

    // 6) ---------------------------------------------------------------------

    #[Test]
    public function a_retroactive_week_before_a_manual_lesson_takes_the_numbers_just_before_it(): void
    {
        $this->weeks('2026-10-08');
        $this->save($this->on('2026-10-08'), ['lesson_number' => 11])->assertSessionHasNoErrors();

        $this->weeks('2026-09-24', '2026-10-01');

        $this->assertSame(['2026-09-24' => '9', '2026-10-01' => '10', '2026-10-08' => '11'], $this->numbers());
    }

    #[Test]
    public function a_retroactive_week_before_taught_history_stays_unnumbered_and_the_history_intact(): void
    {
        $taught = $this->makeLesson(['starts_at' => '2026-10-15 09:30:00', 'ends_at' => '2026-10-15 10:20:00', 'status' => LessonStatus::Taught, 'lesson_number' => 1]);

        $this->weeks('2026-10-01', '2026-10-08');

        $this->assertSame(['2026-10-01' => null, '2026-10-08' => null, '2026-10-15' => '1'], $this->numbers());
        $this->inTenant($this->organization, fn () => $this->assertSame(1, $taught->refresh()->lesson_number));
    }

    #[Test]
    public function inserting_a_lesson_before_a_manual_one_keeps_the_manual_number(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $this->save($this->on('2026-10-08'), ['lesson_number' => 11])->assertSessionHasNoErrors();

        $this->asTeacher()->post('/lessons/insert', [
            'class' => $this->schoolClass->ulid,
            'insert_at' => '2026-10-08',
        ])->assertSessionHasNoErrors();

        // A aula nova antes da manual é a 10; a manual (agora a 15/10) mantém a 11.
        $this->assertSame(['2026-10-08' => '10', '2026-10-15' => '11', '2026-10-22' => '12'], $this->numbers());
        $this->assertManual('2026-10-15', 11);
    }

    // 7) ---------------------------------------------------------------------

    #[Test]
    public function a_number_already_assigned_to_another_lesson_is_refused_and_nothing_is_written(): void
    {
        $this->makeLesson(['starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00', 'status' => LessonStatus::Taught, 'lesson_number' => 12]);
        $this->weeks('2026-10-15', '2026-10-22');
        $target = $this->on('2026-10-15');
        $before = $this->numbers();

        $this->save($target, ['lesson_number' => 12], 'Texto que não pode ficar.')
            ->assertSessionHasErrors('lesson_number');
        $this->assertStringContainsString('A Lição 12 já está atribuída à aula de 08/10/2026', $this->firstError('lesson_number'));

        $this->save($target, ['lesson_number' => 11, 'lesson_number_end' => 13], 'Texto que não pode ficar.')
            ->assertSessionHasErrors('lesson_number');
        $this->assertStringContainsString('As lições 11–13 sobrepõem-se à Lição 12', $this->firstError('lesson_number'));

        // Entre uma manual e uma manual.
        $this->save($this->on('2026-10-22'), ['lesson_number' => 20])->assertSessionHasNoErrors();
        $this->save($target, ['lesson_number' => 19, 'lesson_number_end' => 21])->assertSessionHasErrors('lesson_number');

        $this->assertSame(['2026-10-08' => '12', '2026-10-15' => '13', '2026-10-22' => '20'], $this->numbers());
        $this->assertNotSame($before, $this->numbers());
        $this->inTenant($this->organization, fn () => $this->assertSame(0, LessonSummary::query()->where('lesson_id', $target->id)->count()));
    }

    // 8) ---------------------------------------------------------------------

    #[Test]
    public function a_manual_number_in_one_class_changes_nothing_in_the_others(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');

        $others = $this->inTenant($this->organization, function (): array {
            $otherYear = AcademicYear::factory()->recycle($this->organization)->create(['starts_on' => '2027-09-01', 'ends_on' => '2028-06-30']);
            $otherSubject = Subject::factory()->recycle($this->organization)->create();

            return [
                // Outra turma, mesma disciplina e ano. O rótulo é fixo e diferente:
                // um rótulo aleatório da factory pode coincidir com o da turma de
                // base e violar a chave única (organização, ano, disciplina, rótulo).
                SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id, 'subject_id' => $this->schoolClass->subject_id, 'label' => $this->schoolClass->label.' (outra)']),
                // A mesma etiqueta de turma, outra disciplina.
                SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $this->schoolClass->academic_year_id, 'subject_id' => $otherSubject->id, 'label' => $this->schoolClass->label]),
                // A mesma etiqueta e disciplina, outro ano letivo.
                SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $otherYear->id, 'subject_id' => $this->schoolClass->subject_id, 'label' => $this->schoolClass->label]),
            ];
        });

        foreach ($others as $class) {
            foreach (['2026-10-08', '2026-10-15'] as $date) {
                $this->makeLesson(['class_id' => $class->id, 'starts_at' => "{$date} 09:30:00", 'ends_at' => "{$date} 10:20:00"]);
            }

            $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $class->id));
        }

        $snapshot = fn (): array => $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->whereIn('class_id', collect($others)->pluck('id'))->orderBy('id')
            ->get(['id', 'lesson_number', 'lesson_number_end', 'lesson_number_manual'])->toArray());
        $before = $snapshot();
        $this->assertSame([1, 2, 1, 2, 1, 2], array_column($before, 'lesson_number'));

        $this->save($this->on('2026-10-08'), ['lesson_number' => 11])->assertSessionHasNoErrors();

        $this->assertSame($before, $snapshot());
        $this->assertSame(['2026-10-08' => '11', '2026-10-15' => '12'], $this->numbers());
    }

    // 9) ---------------------------------------------------------------------

    #[Test]
    public function without_any_manual_intervention_materializing_deleting_inserting_and_absences_number_as_before(): void
    {
        $this->weeks('2026-10-01', '2026-10-08', '2026-10-15', '2026-10-22', '2026-10-29');
        $this->assertSame([1, 2, 3, 4, 5], $this->plainNumbers());

        $this->asTeacher()->delete("/lessons/{$this->on('2026-10-15')->ulid}")->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 3, 4], $this->plainNumbers());

        $this->asTeacher()->post('/lessons/insert', ['class' => $this->schoolClass->ulid, 'insert_at' => '2026-10-08'])->assertSessionHasNoErrors();
        $this->assertSame([1, 2, 3, 4, 5], $this->plainNumbers());

        $this->asTeacher()
            ->post("/lessons/{$this->lessonAt(2)->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasNoErrors();
        $this->assertSame([1, 2, null, 3, 4], $this->plainNumbers());

        $this->inTenant($this->organization, function (): void {
            $this->assertSame(0, Lesson::query()->where('lesson_number_manual', true)->count());
            $this->assertSame(0, Lesson::query()->whereNotNull('lesson_number_end')->count());
        });
    }

    // 10) --------------------------------------------------------------------

    #[Test]
    public function changing_the_number_of_a_prepared_lesson_needs_the_confirmation_of_the_preview(): void
    {
        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22', '2026-10-29');
        // A aula de 22/10 está preparada (tem sumário); as outras estão por preparar.
        $this->save($this->on('2026-10-22'), [], 'Aula já preparada.')->assertSessionHasNoErrors();
        $first = $this->on('2026-10-08');

        $before = $this->auditAndLessons();
        $preview = $this->asTeacher()->postJson("/lessons/{$first->ulid}/number/preview", ['mode' => 'manual', 'start' => 5])
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('conflicts', [])
            ->assertJsonPath('requires_confirmation', true)
            ->assertJsonPath('following_from', 6)
            ->assertJsonCount(3, 'effects');
        $this->assertSame($before, $this->auditAndLessons(), 'A pré-visualização não escreve nada.');

        $recorded = collect($preview->json('effects'))->where('recorded', true)->all();
        $this->assertCount(1, $recorded);
        $this->assertSame($this->on('2026-10-22')->ulid, array_values($recorded)[0]['ulid']);
        $confirmation = $preview->json('confirmation');
        $this->assertIsString($confirmation);

        // Sem confirmação (ou com outra): recusado, nada escrito.
        $this->save($first, ['lesson_number' => 5])->assertSessionHasErrors('lesson_number');
        $this->save($first, ['lesson_number' => 5, 'lesson_number_confirmation' => hash('sha256', 'outra')])->assertSessionHasErrors('lesson_number');
        $this->assertSame(['2026-10-08' => '1', '2026-10-15' => '2', '2026-10-22' => '3', '2026-10-29' => '4'], $this->numbers());

        // Com a confirmação da pré-visualização: grava, e a preparada passa ao número novo.
        $this->save($first, ['lesson_number' => 5, 'lesson_number_confirmation' => $confirmation])->assertSessionHasNoErrors();
        $this->assertSame(['2026-10-08' => '5', '2026-10-15' => '6', '2026-10-22' => '7', '2026-10-29' => '8'], $this->numbers());

        // Uma aula POR PREPARAR muda sem confirmação: a última (sem sumário) passa a 20.
        $this->save($this->on('2026-10-29'), ['lesson_number' => 20])->assertSessionHasNoErrors();
        $this->assertSame('20', $this->numbers()['2026-10-29']);
    }

    // 11) --------------------------------------------------------------------

    #[Test]
    public function resetting_to_automatic_returns_the_suggestion_and_the_following_lessons_follow(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $first = $this->on('2026-10-08');
        $this->save($first, ['lesson_number' => 11])->assertSessionHasNoErrors();
        $this->assertSame(['2026-10-08' => '11', '2026-10-15' => '12'], $this->numbers());

        $this->save($first, ['lesson_number_automatic' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Lição 1.'));

        $this->assertSame(['2026-10-08' => '1', '2026-10-15' => '2'], $this->numbers());
        $this->inTenant($this->organization, function () use ($first): void {
            $this->assertFalse($first->refresh()->lesson_number_manual);
            $event = AuditEvent::query()->where('event', 'lesson.number_set')->latest('id')->firstOrFail();
            $this->assertSameJsonPayload([
                'from' => 11, 'from_end' => null, 'to' => 1, 'to_end' => null,
                'manual' => false, 'mode' => 'automatic', 'affected_lessons' => 1, 'affected_recorded_lessons' => 0,
            ], (array) $event->properties);
        });
    }

    // 12) --------------------------------------------------------------------

    #[Test]
    public function the_number_of_a_linked_group_lesson_is_shared_with_its_partner_and_inherited_by_a_later_one(): void
    {
        [$t1Slot, $t2Slot] = $this->splitSlots();

        $t1 = $this->groupLesson($t1Slot, '2026-10-05');
        $t2 = $this->groupLesson($t2Slot, '2026-10-06');
        $this->resequence();
        $this->inTenant($this->organization, fn () => LessonSummary::query()->create(['lesson_id' => $t2->id, 'content' => 'O que o T2 já deu.']));

        // O parceiro T2 tem registo: a mudança do número da lição pede confirmação.
        $preview = $this->asTeacher()->postJson("/lessons/{$t1->ulid}/number/preview", ['mode' => 'manual', 'start' => 11])->assertOk();
        $this->assertSame([$t2->ulid], collect($preview->json('effects'))->pluck('ulid')->all());
        $this->assertTrue($preview->json('effects.0.recorded'));

        $this->save($t1, ['lesson_number' => 11])->assertSessionHasErrors('lesson_number');
        $this->save($t1, ['lesson_number' => 11, 'lesson_number_confirmation' => $preview->json('confirmation')])->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($t1, $t2): void {
            $this->assertSame([11, true], [$t1->refresh()->lesson_number, $t1->lesson_number_manual]);
            $this->assertSame([11, true], [$t2->refresh()->lesson_number, $t2->lesson_number_manual]);
        });

        // Um T2 que nasça DEPOIS herda o número e a marca da lição.
        $t1b = $this->groupLesson($t1Slot, '2026-10-12');
        $this->resequence();
        $this->save($t1b, ['lesson_number' => 21])->assertSessionHasNoErrors();
        $t2b = $this->groupLesson($t2Slot, '2026-10-13');
        $this->resequence();

        $this->inTenant($this->organization, function () use ($t1b, $t2b): void {
            $this->assertSame($t1b->refresh()->lesson_unit_key, $t2b->refresh()->lesson_unit_key);
            $this->assertSame([21, true], [$t2b->lesson_number, $t2b->lesson_number_manual]);
        });
    }

    // 13) --------------------------------------------------------------------

    #[Test]
    public function a_teacher_absence_on_a_manual_lesson_passes_the_lesson_on_without_leaving_a_gap(): void
    {
        $this->weeks('2026-10-08', '2026-10-15', '2026-10-22');
        $this->save($this->on('2026-10-08'), ['lesson_number' => 11])->assertSessionHasNoErrors();
        $this->assertSame(['2026-10-08' => '11', '2026-10-15' => '12', '2026-10-22' => '13'], $this->numbers());

        $this->asTeacher()
            ->post("/lessons/{$this->on('2026-10-08')->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['2026-10-08' => null, '2026-10-15' => '11', '2026-10-22' => '12'], $this->numbers());
        $this->assertManual('2026-10-15', 11);

        $this->inTenant($this->organization, function (): void {
            $absent = Lesson::query()->where('outcome', LessonOutcome::TeacherAbsent->value)->sole();
            $this->assertNull($absent->lesson_number_end);
            $this->assertFalse($absent->lesson_number_manual);

            $event = AuditEvent::query()->where('event', 'lesson.outcome_recorded')->latest('id')->firstOrFail();
            $this->assertTrue($event->properties['manual_number_handed_over']);
        });
    }

    #[Test]
    public function a_teacher_absence_in_a_group_lesson_makes_the_manual_number_travel_with_the_lesson_key(): void
    {
        [$t1Slot, $t2Slot] = $this->splitSlots();

        $t1a = $this->groupLesson($t1Slot, '2026-10-05');
        $t2a = $this->groupLesson($t2Slot, '2026-10-06');
        $t1b = $this->groupLesson($t1Slot, '2026-10-12');
        $t2b = $this->groupLesson($t2Slot, '2026-10-13');
        $this->resequence();
        $this->save($t1a, ['lesson_number' => 11])->assertSessionHasNoErrors();
        $this->assertSame([11, 11, 12, 12], $this->lessonNumbers([$t1a, $t2a, $t1b, $t2b]));

        $this->asTeacher()
            ->post("/lessons/{$t1a->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasNoErrors();

        // A lição 11 passou à T1 seguinte (a chave levou o número); a T2a continua nela.
        $this->assertSame([null, 11, 11, 12], $this->lessonNumbers([$t1a, $t2a, $t1b, $t2b]));
        $this->inTenant($this->organization, function () use ($t1b, $t2a): void {
            $this->assertSame($t2a->refresh()->lesson_unit_key, $t1b->refresh()->lesson_unit_key);
            $this->assertTrue($t1b->lesson_number_manual);
        });
    }

    // 14) --------------------------------------------------------------------

    #[Test]
    public function invalid_numbers_are_refused_by_the_server(): void
    {
        $this->weeks('2026-10-08');
        $lesson = $this->on('2026-10-08');

        foreach ([0, -3, 1.5, 'abc', 10000] as $bad) {
            $this->save($lesson, ['lesson_number' => $bad])->assertSessionHasErrors('lesson_number');
        }

        $this->save($lesson, ['lesson_number' => 5, 'lesson_number_end' => 4])->assertSessionHasErrors('lesson_number_end');
        $this->save($lesson, ['lesson_number' => 5, 'lesson_number_end' => 15])->assertSessionHasErrors('lesson_number_end');
        $this->assertStringContainsString('no máximo, a 10 lições', $this->firstError('lesson_number_end'));
        $this->save($lesson, ['lesson_number' => 5, 'lesson_number_end' => 14])->assertSessionHasNoErrors();
        $this->save($lesson, ['lesson_number_end' => 14])->assertSessionHasErrors('lesson_number');

        // O mesmo na pré-visualização, em JSON.
        $this->asTeacher()->postJson("/lessons/{$lesson->ulid}/number/preview", ['mode' => 'manual', 'start' => 0])->assertJsonValidationErrors('start');
        $this->asTeacher()->postJson("/lessons/{$lesson->ulid}/number/preview", ['mode' => 'manual'])->assertJsonValidationErrors('start');
        $this->asTeacher()->postJson("/lessons/{$lesson->ulid}/number/preview", ['mode' => 'manual', 'start' => 5, 'end' => 3])->assertJsonValidationErrors('end');
        $this->asTeacher()->postJson("/lessons/{$lesson->ulid}/number/preview", ['mode' => 'manual', 'start' => 5, 'end' => 20])->assertJsonValidationErrors('end');
        $this->asTeacher()->postJson("/lessons/{$lesson->ulid}/number/preview", ['mode' => 'outro'])->assertJsonValidationErrors('mode');
    }

    #[Test]
    public function a_lesson_with_the_teacher_absent_has_no_number_to_set(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $absent = $this->on('2026-10-08');
        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute($absent, LessonOutcome::TeacherAbsent, $this->teacher, TeacherAbsenceReason::Training));

        $this->asTeacher()->postJson("/lessons/{$absent->ulid}/number/preview", ['mode' => 'manual', 'start' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lesson_number');

        $this->asTeacher()->put("/lessons/{$absent->ulid}/summary", [
            'content' => 'Texto.', 'summary_version' => $this->summaryVersion($absent), 'lesson_number' => 5,
        ])->assertSessionHasErrors('lesson_number');
        $this->assertStringContainsString('o professor esteve ausente', $this->firstError('lesson_number'));

        $this->asTeacher()->get("/lessons/{$absent->ulid}")->assertInertia(fn ($page) => $page->where('lesson.can_set_number', false)->etc());
    }

    #[Test]
    public function the_card_endpoint_accepts_the_number_with_the_text_in_one_transaction(): void
    {
        $this->weeks('2026-10-08', '2026-10-15');
        $first = $this->on('2026-10-08');

        $this->asTeacher()->patch("/lessons/{$first->ulid}/summary/content", [
            'content' => 'Do cartão.', 'summary_version' => $this->summaryVersion($first), 'lesson_number' => 11,
        ])->assertSessionHasNoErrors();
        $this->assertSame(['2026-10-08' => '11', '2026-10-15' => '12'], $this->numbers());

        // Uma versão de sumário velha reverte também o número.
        $this->asTeacher()->patch("/lessons/{$first->ulid}/summary/content", [
            'content' => 'Velha.', 'summary_version' => 999, 'lesson_number' => 30,
        ])->assertSessionHasErrors('summary_version');
        $this->assertSame(['2026-10-08' => '11', '2026-10-15' => '12'], $this->numbers());
    }

    // 15) --------------------------------------------------------------------

    #[Test]
    public function the_number_endpoints_respect_the_organization_the_policy_and_impersonation(): void
    {
        $this->weeks('2026-10-08');
        $lesson = $this->on('2026-10-08');

        // Outra organização: a aula nem se resolve.
        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrganization);
        $foreign = $this->inTenant($strangerOrganization, function () use ($stranger, $strangerOrganization): Lesson {
            $class = SchoolClass::factory()->recycle($strangerOrganization)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($strangerOrganization)->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])->id,
            ]);
            $class->teachers()->attach($stranger, ['role' => 'owner']);

            return Lesson::create([
                'class_id' => $class->id, 'starts_at' => '2026-10-08 09:30:00', 'ends_at' => '2026-10-08 10:20:00',
                'status' => LessonStatus::Preparation, 'created_by' => $stranger->id,
            ]);
        });

        $this->asTeacher()->postJson("/lessons/{$foreign->ulid}/number/preview", ['mode' => 'manual', 'start' => 5])->assertNotFound();
        $this->asTeacher()->put("/lessons/{$foreign->ulid}/summary", ['content' => 'x', 'summary_version' => 0, 'lesson_number' => 5])->assertNotFound();
        $this->inTenant($strangerOrganization, fn () => $this->assertNull($foreign->refresh()->lesson_number));

        // Um colega que não é professor desta turma: 403, nos dois sítios.
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $asColleague = $this->actingAs($colleague)->withSession(['organization_id' => $this->organization->id]);
        $asColleague->postJson("/lessons/{$lesson->ulid}/number/preview", ['mode' => 'manual', 'start' => 5])->assertForbidden();
        $asColleague->put("/lessons/{$lesson->ulid}/summary", ['content' => 'x', 'summary_version' => $this->summaryVersion($lesson), 'lesson_number' => 5])->assertForbidden();

        // Durante o acesso técnico a escrita é recusada; a pré-visualização só lê.
        $impersonating = $this->actingAs($this->teacher)->withSession(['organization_id' => $this->organization->id, 'impersonator_id' => 999]);
        $impersonating->put("/lessons/{$lesson->ulid}/summary", ['content' => 'x', 'summary_version' => $this->summaryVersion($lesson), 'lesson_number' => 5])->assertForbidden();
        $impersonating->postJson("/lessons/{$lesson->ulid}/number/preview", ['mode' => 'manual', 'start' => 5])->assertOk();

        $this->assertSame(['2026-10-08' => '1'], $this->numbers());
        $this->inTenant($this->organization, fn () => $this->assertSame(0, AuditEvent::query()->where('event', 'lesson.number_set')->count()));
    }

    // Revisão ----------------------------------------------------------------

    #[Test]
    public function the_manual_number_of_the_last_displaced_lesson_key_passes_to_the_next_free_unit(): void
    {
        [$t1Slot] = $this->splitSlots();

        $a = $this->groupLesson($t1Slot, '2026-10-05');
        $b = $this->groupLesson($t1Slot, '2026-10-12');
        $c = $this->groupLesson($t1Slot, '2026-10-19');
        $d = $this->groupLesson($t1Slot, '2026-10-26');
        $this->resequence();
        $this->save($c, ['lesson_number' => 20])->assertSessionHasNoErrors();
        $this->assertSame([18, 19, 20, 21], $this->lessonNumbers([$a, $b, $c, $d]));

        $this->asTeacher()
            ->post("/lessons/{$a->ulid}/outcome", ['outcome' => 'teacher_absent', 'reason' => 'training'])
            ->assertSessionHasNoErrors();

        // A Lição 20 não desapareceu: passou à aula depois da última religada.
        $numbers = $this->lessonNumbers([$a, $b, $c, $d]);
        $this->assertSame([null, 18, 19, 20], $numbers);
        $this->inTenant($this->organization, function () use ($c, $d): void {
            $this->assertFalse($c->refresh()->lesson_number_manual);
            $this->assertTrue($d->refresh()->lesson_number_manual);
            $this->assertSame(1, Lesson::query()->where('lesson_number', 20)->count());
        });
    }

    #[Test]
    public function legacy_out_of_order_numbers_without_manual_numbers_do_not_block_a_deletion(): void
    {
        // Dados do antigo «maior + 1»: 5 e 6 abertas antes de uma história 1–3.
        $lessons = [];

        foreach ([['stored', 5], ['stored', 6], ['taught', 1], ['taught', 2], ['taught', 3], ['stored', 4], ['stored', 7]] as $index => [$kind, $number]) {
            $day = CarbonImmutable::parse('2026-10-01')->addDays($index)->toDateString();
            $lessons[] = $this->makeLesson([
                'starts_at' => "{$day} 09:30:00", 'ends_at' => "{$day} 10:20:00", 'lesson_number' => $number,
            ] + ($kind === 'taught' ? ['status' => LessonStatus::Taught] : []));
        }

        $this->asTeacher()->delete("/lessons/{$lessons[6]->ulid}")->assertSessionHasNoErrors();

        $this->assertSame([5, 6, 1, 2, 3, 4], $this->plainNumbers());
    }
    // ------------------------------------------------------------------------

    /**
     * Materializa cada quinta-feira pedida pela via normal da aplicação.
     */
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

    /** A aula na n-ésima posição cronológica (a partir de 0). */
    private function lessonAt(int $position): Lesson
    {
        return $this->inTenant($this->organization, fn (): Lesson => Lesson::query()->where('class_id', $this->schoolClass->id)->orderBy('starts_at')->get()[$position]);
    }

    /**
     * Grava o sumário pela rota normal, com a versão que o ecrã teria visto.
     *
     * @param  array<string, mixed>  $extra
     */
    private function save(Lesson $lesson, array $extra = [], string $content = 'Texto do sumário.'): TestResponse
    {
        return $this->asTeacher()->from('/lessons')->put("/lessons/{$lesson->ulid}/summary", [
            'content' => $content,
            'summary_version' => $this->summaryVersion($lesson),
        ] + $extra);
    }

    /**
     * «dia => 11–12», só das aulas da turma principal, por ordem cronológica.
     *
     * @return array<string, string|null>
     */
    private function numbers(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('class_id', $this->schoolClass->id)->orderBy('starts_at')->get()
            ->mapWithKeys(fn (Lesson $lesson): array => [$lesson->starts_at->format('Y-m-d') => $lesson->lessonNumberLabel()])
            ->all());
    }

    /** @return list<int|null> */
    private function plainNumbers(): array
    {
        return $this->inTenant($this->organization, fn (): array => Lesson::query()
            ->where('class_id', $this->schoolClass->id)->orderBy('starts_at')->get()
            ->map(fn (Lesson $lesson): ?int => $lesson->lesson_number)->all());
    }

    private function assertManual(string $date, int $number): void
    {
        $lesson = $this->on($date);
        $this->assertSame($number, $lesson->lesson_number);
        $this->assertTrue($lesson->lesson_number_manual, "A aula de {$date} devia continuar manual.");
    }

    private function firstError(string $key): string
    {
        return (string) session('errors')?->first($key);
    }

    /** @return array<string, mixed> */
    private function auditAndLessons(): array
    {
        return [
            'audit' => DB::table('audit_events')->count(),
            'lessons' => json_decode((string) DB::table('lessons')->orderBy('id')->get(['id', 'lesson_number', 'lesson_number_end', 'lesson_number_manual', 'updated_at'])->toJson(), true),
        ];
    }

    /** @return array{0: RecurringLessonSlot, 1: RecurringLessonSlot} */
    private function splitSlots(): array
    {
        $key = (string) Str::ulid();
        $t1 = $this->makeGroup('T1');
        $t2 = $this->makeGroup('T2');

        return [
            $this->makeSlot(['class_group_id' => $t1->id, 'day_of_week' => 1, 'split_lesson_key' => $key]),
            $this->makeSlot(['class_group_id' => $t2->id, 'day_of_week' => 2, 'split_lesson_key' => $key]),
        ];
    }

    private function groupLesson(RecurringLessonSlot $slot, string $date): Lesson
    {
        return $this->makeLesson([
            'class_group_id' => $slot->class_group_id,
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => "{$date} 09:30:00",
        ]);
    }

    private function resequence(): void
    {
        $this->inTenant($this->organization, fn () => app(LessonNumbering::class)->resequence((int) $this->schoolClass->id));
    }

    /**
     * @param  list<Lesson>  $lessons
     * @return list<int|null>
     */
    private function lessonNumbers(array $lessons): array
    {
        return $this->inTenant($this->organization, fn (): array => array_map(fn (Lesson $lesson): ?int => $lesson->fresh()?->lesson_number, $lessons));
    }
}
