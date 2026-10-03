<?php

namespace Tests\Feature\Lessons;

use App\Models\AttendanceStatus;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\TeacherAbsenceReason;
use App\Services\Lessons\LessonNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * «Inserir aula» em outubro recusada por mexer na numeração de setembro.
 *
 * Reportado a 2026-10-03 (8.º F, turma inteira, a partir de 06/10): «Esta
 * operação mudaria o número da aula de 16/09/2026, que já foi lecionada (Lição
 * 3 → Lição 4).» Uma inserção em outubro não pode mexer em setembro.
 *
 * A CAUSA: uma ausência do professor (0.146.0) não numera e fica FORA da
 * sequência — `LessonNumbering::sequence()` exclui-a. A pré-visualização da
 * inserção montava o estado hipotético da turma com TODAS as aulas, ausências
 * incluídas, e `previewHypotheticalSequence()` contava cada ausência como uma
 * lição: todas as lecionadas depois dela «passavam» a ter mais um número, e a
 * proteção do histórico recusava. A execução (`resequence()`) nunca se
 * enganou; mas a pré-visualização recusava, e o botão ficava desativado.
 *
 * O horário é «quintas-feiras, 09:30–10:20». 03, 10, 17 e 24 de setembro e
 * 01, 08 e 15 de outubro de 2026 são quintas-feiras; 06/10 é uma terça.
 */
class InsertLessonAfterTeacherAbsenceTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLessonFixtures();
    }

    #[Test]
    public function a_teacher_absence_in_september_does_not_block_an_october_insertion(): void
    {
        $slot = $this->makeSlot(['starts_on' => '2026-09-01']);
        $enrollment = $this->enroll('Aluno Fictício');

        $first = $this->taught($slot, '2026-09-03', 1);
        $second = $this->taught($slot, '2026-09-10', 2);
        $absence = $this->teacherAbsent($slot, '2026-09-17');
        $third = $this->taught($slot, '2026-09-24', 3);
        $october = $this->lessonOn($slot, '2026-10-01', ['lesson_number' => 4]);
        $shifted = $this->lessonOn($slot, '2026-10-08', ['lesson_number' => 5]);

        $this->summaryFor($third, 'Frações equivalentes.');
        $this->attendanceFor($third, $enrollment, AttendanceStatus::Absent);
        $history = $this->snapshot([$first, $second, $absence, $third, $october]);

        // A pré-visualização: a inserção cai na quinta 08/10, a primeira
        // ocorrência a partir de terça 06/10, e só desloca a aula de 08/10.
        $this->asTeacher()
            ->postJson('/lessons/insert/preview', [
                'class' => $this->schoolClass->ulid,
                'insert_at' => '2026-10-06',
            ])
            ->assertOk()
            ->assertJsonPath('shifted_count', 1)
            ->assertJsonPath('moves.0.ulid', $shifted->ulid)
            ->assertJsonPath('moves.0.lesson_number', 5)
            ->assertJsonPath('moves.0.lesson_number_to', 6);

        // A execução, pelo mesmo caminho.
        $this->asTeacher()
            ->from('/lessons')
            ->post('/lessons/insert', [
                'class' => $this->schoolClass->ulid,
                'insert_at' => '2026-10-06',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($history, $shifted): void {
            // Tudo o que está antes da ocorrência de inserção ficou como estava:
            // datas, números, estados, resultado, sumário e assiduidade.
            $this->assertSame($history, $this->snapshot(array_map(
                fn (array $row): Lesson => Lesson::query()->findOrFail($row['id']),
                $history,
            )));

            $inserted = Lesson::query()->whereDate('starts_at', '2026-10-08')->sole();
            $this->assertSame(5, $inserted->lesson_number);
            $this->assertSame(LessonStatus::Preparation, $inserted->status);

            $shifted->refresh();
            $this->assertSame('2026-10-15', $shifted->starts_at->toDateString());
            $this->assertSame(6, $shifted->lesson_number);
        });
    }

    #[Test]
    public function the_preview_and_the_execution_agree_on_the_whole_numbering(): void
    {
        $slot = $this->makeSlot(['starts_on' => '2026-09-01']);
        $this->taught($slot, '2026-09-03', 1);
        $this->teacherAbsent($slot, '2026-09-10');
        $this->taught($slot, '2026-09-17', 2);
        $a = $this->lessonOn($slot, '2026-10-01', ['lesson_number' => 3]);
        $b = $this->lessonOn($slot, '2026-10-08', ['lesson_number' => 4]);

        $preview = $this->asTeacher()
            ->postJson('/lessons/insert/preview', [
                'class' => $this->schoolClass->ulid,
                'insert_at' => '2026-10-01',
            ])
            ->assertOk()
            ->json('moves');

        $this->asTeacher()->from('/lessons')->post('/lessons/insert', [
            'class' => $this->schoolClass->ulid,
            'insert_at' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        // O que a pré-visualização prometeu é exatamente o que ficou gravado.
        $this->inTenant($this->organization, function () use ($a, $b, $preview): void {
            $promised = collect($preview)->mapWithKeys(fn (array $move): array => [$move['ulid'] => $move['lesson_number_to']]);

            $this->assertSame([$a->ulid => 4, $b->ulid => 5], $promised->all());
            $this->assertSame(4, $a->refresh()->lesson_number);
            $this->assertSame(5, $b->refresh()->lesson_number);
            $this->assertSame(3, Lesson::query()->whereDate('starts_at', '2026-10-01')->sole()->lesson_number);
        });
    }

    /** O mesmo engano numa sequência de grupo: inserir em T1 depois de uma ausência em T1. */
    #[Test]
    public function a_teacher_absence_in_a_group_does_not_block_an_insertion_in_that_group(): void
    {
        $first = $this->makeGroup('T1');
        $second = $this->makeGroup('T2');
        $slotOne = $this->makeSlot(['class_group_id' => $first->id, 'starts_on' => '2026-09-01']);
        $slotTwo = $this->makeSlot(['class_group_id' => $second->id, 'starts_on' => '2026-09-01', 'starts_at' => '11:00', 'ends_at' => '11:50']);

        $t1Taught = $this->taught($slotOne, '2026-09-03', 1, $first);
        $t2Taught = $this->taught($slotTwo, '2026-09-03', 2, $second, '11:00');
        $t1Absent = $this->teacherAbsent($slotOne, '2026-09-10', $first);
        $t2AfterAbsence = $this->taught($slotTwo, '2026-09-10', 3, $second, '11:00');
        $t1Prepared = $this->lessonOn($slotOne, '2026-10-01', ['class_group_id' => $first->id, 'lesson_number' => 4]);
        $t2Prepared = $this->lessonOn($slotTwo, '2026-10-01', [
            'class_group_id' => $second->id,
            'starts_at' => '2026-10-01 11:00:00',
            'ends_at' => '2026-10-01 11:50:00',
            'lesson_number' => 5,
        ]);

        $history = $this->snapshot([$t1Taught, $t2Taught, $t1Absent, $t2AfterAbsence]);

        $this->asTeacher()
            ->postJson('/lessons/insert/preview', [
                'class' => $this->schoolClass->ulid,
                'class_group_id' => $first->id,
                'insert_at' => '2026-10-01',
            ])
            ->assertOk()
            ->assertJsonPath('shifted_count', 1)
            ->assertJsonPath('moves.0.ulid', $t1Prepared->ulid);

        $this->asTeacher()->from('/lessons')->post('/lessons/insert', [
            'class' => $this->schoolClass->ulid,
            'class_group_id' => $first->id,
            'insert_at' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $this->inTenant($this->organization, function () use ($first, $history, $t1Prepared, $t2Prepared): void {
            $this->assertSame($history, $this->snapshot(array_map(
                fn (array $row): Lesson => Lesson::query()->findOrFail($row['id']),
                $history,
            )));

            // Só a sequência de T1 se deslocou; T2 ficou no seu dia.
            $this->assertSame('2026-10-08', $t1Prepared->refresh()->starts_at->toDateString());
            $this->assertSame('2026-10-01', $t2Prepared->refresh()->starts_at->toDateString());

            // A numeração é da turma, pela ordem cronológica real.
            $inserted = Lesson::query()->where('class_group_id', $first->id)->whereDate('starts_at', '2026-10-01')->sole();
            $this->assertSame(4, $inserted->lesson_number);
            $this->assertSame(5, $t2Prepared->lesson_number);
            $this->assertSame(6, $t1Prepared->lesson_number);
        });
    }

    /** A população da numeração é do serviço: quem lhe passar uma ausência não a vê contada. */
    #[Test]
    public function the_hypothetical_preview_ignores_teacher_absences_like_the_real_sequence(): void
    {
        $slot = $this->makeSlot(['starts_on' => '2026-09-01']);
        $this->taught($slot, '2026-09-03', 1);
        $this->teacherAbsent($slot, '2026-09-10');
        $this->taught($slot, '2026-09-17', 2);

        $this->inTenant($this->organization, function (): void {
            $numbering = app(LessonNumbering::class);
            $lessons = Lesson::query()->where('class_id', $this->schoolClass->id)->get();

            $this->assertSame([], $numbering->previewResequence($this->schoolClass->id));
            $this->assertSame([], $numbering->previewHypotheticalSequence($this->schoolClass->id, $lessons));
        });
    }

    private function taught(RecurringLessonSlot $slot, string $date, int $number, ?ClassGroup $group = null, string $time = '09:30'): Lesson
    {
        return $this->lessonOn($slot, $date, [
            'class_group_id' => $group?->id,
            'starts_at' => "{$date} {$time}:00",
            'ends_at' => null,
            'status' => LessonStatus::Taught,
            'outcome' => LessonOutcome::Taught,
            'lesson_number' => $number,
        ]);
    }

    /** O estado que `RecordLessonOutcome` deixa: fechada, sem número e sem lição. */
    private function teacherAbsent(RecurringLessonSlot $slot, string $date, ?ClassGroup $group = null): Lesson
    {
        return $this->lessonOn($slot, $date, [
            'class_group_id' => $group?->id,
            'outcome' => LessonOutcome::TeacherAbsent,
            'outcome_reason' => TeacherAbsenceReason::Training,
            'outcome_recorded_at' => "{$date} 12:00:00",
            'outcome_recorded_by' => $this->teacher->id,
            'lesson_number' => null,
        ]);
    }

    private function summaryFor(Lesson $lesson, string $content): void
    {
        $this->inTenant($this->organization, fn () => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => $content]));
    }

    private function attendanceFor(Lesson $lesson, Enrollment $enrollment, AttendanceStatus $status): void
    {
        $this->inTenant($this->organization, fn () => LessonAttendance::create([
            'lesson_id' => $lesson->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'status' => $status,
            'updated_by' => $this->teacher->id,
        ]));
    }

    /**
     * Tudo o que «manter o histórico» quer dizer, aula a aula.
     *
     * @param  list<Lesson>  $lessons
     * @return list<array<string, mixed>>
     */
    private function snapshot(array $lessons): array
    {
        return $this->inTenant($this->organization, fn (): array => array_map(function (Lesson $lesson): array {
            $lesson = Lesson::query()->with(['summary', 'attendances'])->findOrFail($lesson->id);

            return [
                'id' => $lesson->id,
                'starts_at' => $lesson->starts_at->toDateTimeString(),
                'ends_at' => $lesson->ends_at->toDateTimeString(),
                'slot' => $lesson->recurring_lesson_slot_id,
                'group' => $lesson->class_group_id,
                'number' => $lesson->lesson_number,
                'unit' => $lesson->lesson_unit_key,
                'status' => $lesson->status->value,
                'outcome' => $lesson->outcome?->value,
                'summary' => $lesson->summary?->content,
                'attendance' => $lesson->attendances->map(fn (LessonAttendance $row): string => $row->enrollment_id.':'.$row->status->value)->sort()->values()->all(),
            ];
        }, $lessons));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lessonOn(RecurringLessonSlot $slot, string $date, array $attributes = []): Lesson
    {
        $attributes = array_merge([
            'recurring_lesson_slot_id' => $slot->id,
            'starts_at' => "{$date} 09:30:00",
        ], $attributes);

        if (($attributes['ends_at'] ?? null) === null) {
            unset($attributes['ends_at']);
        }

        return $this->makeLesson($attributes);
    }
}
