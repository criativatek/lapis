<?php

namespace Tests\Feature\Lessons;

use App\Actions\Lessons\MaterializeLessonsForRange;
use App\Actions\Lessons\RecordLessonOutcome;
use App\Models\AcademicCalendarException;
use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\AuditEvent;
use App\Models\CancelledLessonOccurrence;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOutcome;
use App\Models\LessonPlan;
use App\Models\LessonSequence;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TeacherAbsenceReason;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Lessons\Concerns\BuildsLessonFixtures;
use Tests\TestCase;

/**
 * O cenário real «as férias de Natal esquecidas»: o professor configurou o ano
 * letivo e o horário e esqueceu-se das interrupções, por isso o Lapispro mostra
 * aulas previstas durante o Natal. Reproduz-se aqui, em fixtures, de ponta a
 * ponta e SÓ PELA CAMADA HTTP (a que o ecrã usa).
 *
 * O RELÓGIO ESTÁ FIXO (14/12/2026) e as datas são absolutas. Calendário de
 * referência: segundas 14, 21, 28/12 e 04, 11/01; terças 15, 22, 29/12 e 05,
 * 12/01; quartas 16, 23, 30/12 e 06/01; sextas 18, 25/12 e 01/01. A
 * interrupção de Natal vai de sábado 19/12/2026 a domingo 03/01/2027.
 *
 * Turmas (semanas materializadas de 14/09/2026 a 15/01/2027):
 *  - «split» (responsável): T1 às segundas 09:30, T2 às terças 09:30, com a
 *    lição partilhada;
 *  - «apoio» (responsável): sextas 11:30;
 *  - «normal» (colega): terças 11:00 e quartas 10:30, com a marca antiga de uma
 *    aula eliminada a 04/11.
 *
 * Os textos «SENTINELA-…» servem para afirmar que NENHUMA resposta JSON devolve
 * conteúdo pedagógico.
 */
class ChristmasBreakForgottenScenarioTest extends TestCase
{
    use BuildsLessonFixtures, RefreshDatabase;

    private const FROM = '2026-09-14';

    private const TO = '2027-01-15';

    private const NO_CONTENT = 'SENTINELA';

    private User $colleague;

    private SchoolClass $support;

    private SchoolClass $normal;

    private int $t1;

    private int $t2;

    /** @var list<array{0: int, 1: int|null, 2: int, 3: string}> classe, grupo, dia ISO da semana, hora */
    private array $slots = [];

    private string $cancelledKey = '';

    /** @var list<string> */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-12-14 10:00:00', 'Europe/Lisbon'));
        $this->bootLessonFixtures();
        $this->buildWorld();
    }

    #[Test]
    public function the_forgotten_christmas_break_is_regularised_without_losing_a_thing(): void
    {
        $year = $this->year();
        $baseline = $this->lessonKeys();
        $this->assertSame($this->expectedKeys([]), $baseline, 'O horário inteiro está materializado, Natal incluído.');
        $this->assertCount(10, $this->windowKeys('2026-12-19', '2027-01-03'));

        $closedBefore = $this->closedSnapshot();
        $contentsBefore = $this->contentMultiset();
        $oldMarks = $this->marks();
        $this->assertCount(1, $oldMarks);
        $this->assertSame(5, count($this->contentMultiset()), 'P1, P2, U1, Q e N1.');

        // ------------------------------------------------------------------
        // (1) O responsável pré-visualiza: nada é escrito
        // ------------------------------------------------------------------
        $payload = $this->christmas();
        $worldBefore = $this->state();
        $preview = $this->record($this->http($this->teacher)->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'create'] + $payload));

        $preview->assertOk();
        // A: as 3 vazias que saem sozinhas. B: P1, P2, U1 e N1 (ficam para o professor). C: o número à mão, a lecionada por
        // engano e a terça 22/12 do split — vazia, mas partilha a lição com a segunda 21/12 (B), por isso o responsável não a toca.
        $this->assertSame(['A' => 3, 'B' => 4, 'C' => 3], $preview->json('counts'));
        $this->assertSame(3, $preview->json('totals.retired'));
        $this->assertSame(0, $preview->json('totals.shifted'));
        $this->assertSame(4, $preview->json('totals.awaiting_teacher'));
        $this->assertSame(3, $preview->json('totals.flagged'));
        $this->assertCount(3, $preview->json('classes'));
        $this->assertSame([], $preview->json('freed_days'));
        // Lacuna: a terça 22/12 da turma normal (vazia) antecede a quarta 23/12 (lecionada, fechada).
        $gaps = $preview->json('gaps');
        $this->assertCount(1, $gaps, 'Só a turma normal tem lacuna.');
        $this->assertSame($this->normal->ulid, $gaps[0]['class']);
        $this->assertCount(1, $gaps[0]['numbers']);
        $this->assertSame($this->numberAt($this->normal, '2026-12-22 11:00'), $gaps[0]['numbers'][0]);
        $this->assertSame($worldBefore, $this->state(), 'A pré-visualização não escreve nada, nem a exceção.');
        $this->assertSame(0, AcademicCalendarException::withoutGlobalScopes()->count());
        $token = (string) $preview->json('plan_token');

        // ------------------------------------------------------------------
        // (2) O responsável confirma: só as vazias saem, sem marca
        // ------------------------------------------------------------------
        $this->http($this->teacher)->postJson("/academic-years/{$year->ulid}/exceptions", $payload + ['reconcile' => true, 'plan_token' => $token])->assertRedirect();

        $christmas = AcademicCalendarException::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('2026-12-19', $christmas->starts_on->toDateString());
        $this->assertSame('2027-01-03', $christmas->ends_on->toDateString());
        $retiredEmpty = [
            [$this->support, '2027-01-01 11:30'],
            [$this->normal, '2026-12-22 11:00'],
            [$this->normal, '2026-12-29 11:00'],
        ];

        foreach ($retiredEmpty as [$class, $at]) {
            $this->assertNull($this->lessonOrNull($class, $at), "A vazia {$at} devia ter saído.");
        }

        $this->assertCount(7, $this->windowKeys('2026-12-19', '2027-01-03'), 'Das 10 aulas do Natal saem 3; ficam 4 B, a terça 22/12 do split e 2 C.');
        $this->assertNotNull($this->lessonOrNull($this->schoolClass, '2026-12-22 09:30'), 'A vazia que partilha a lição com uma B espera pelo professor.');
        foreach ([[$this->schoolClass, '2026-12-21 09:30'], [$this->schoolClass, '2026-12-28 09:30'], [$this->schoolClass, '2026-12-29 09:30'], [$this->normal, '2026-12-30 10:30']] as [$class, $at]) {
            $this->assertNotNull($this->lessonOrNull($class, $at), "A B {$at} fica para o professor.");
        }
        $this->assertSame($oldMarks, $this->marks(), 'Nenhuma marca nova; a antiga está intacta.');
        $this->assertSame($closedBefore, $this->closedSnapshot(), 'As lecionadas e fechadas não mudaram.');
        $this->assertSame($contentsBefore, $this->contentMultiset());

        // ------------------------------------------------------------------
        // (4, parte 1) As sinalizadas estão em review_lessons e intactas
        // ------------------------------------------------------------------
        $manualNumber = $this->lesson($this->support, '2026-12-25 11:30')->lesson_number;
        $manualBefore = $this->attributesOf($this->lesson($this->support, '2026-12-25 11:30'));
        $this->assertReview($this->teacher, [$this->lesson($this->support, '2026-12-25 11:30')->ulid], ['A' => 1, 'B' => 3, 'C' => 1], 'manual_number');
        $this->assertReview($this->colleague, [$this->lesson($this->normal, '2026-12-23 10:30')->ulid], ['A' => 0, 'B' => 1, 'C' => 1], 'taught');

        // ------------------------------------------------------------------
        // (3) Cada professor regulariza as suas turmas
        // ------------------------------------------------------------------
        $this->regularize($this->colleague, shifted: 1);
        $this->regularize($this->teacher, shifted: 3);
        $this->assertNull($this->lessonOrNull($this->schoolClass, '2026-12-22 09:30'), 'A vazia sai com a sua B quando o professor regulariza.');

        $this->assertSame([
            '2027-01-04 09:30' => 'SENTINELA-P1',
            '2027-01-05 09:30' => 'SENTINELA-U1',
            '2027-01-05 11:00' => 'SENTINELA-N1',
            '2027-01-11 09:30' => 'SENTINELA-P2',
            '2027-01-12 09:30' => 'SENTINELA-Q',
        ], $this->contentByStart(), 'Ordem preservada (P1, P2 / U1, Q), cada grupo no seu.');
        // T1 e T2 nunca se misturam.
        $this->assertSame($this->t1, $this->lesson($this->schoolClass, '2027-01-04 09:30')->class_group_id);
        $this->assertSame($this->t1, $this->lesson($this->schoolClass, '2027-01-11 09:30')->class_group_id);
        $this->assertSame($this->t2, $this->lesson($this->schoolClass, '2027-01-05 09:30')->class_group_id);
        $this->assertSame($this->t2, $this->lesson($this->schoolClass, '2027-01-12 09:30')->class_group_id);
        // A proveniência da sequência viajou com o P1.
        $moved = LessonSummary::withoutGlobalScopes()->where('lesson_id', $this->lesson($this->schoolClass, '2027-01-04 09:30')->id)->firstOrFail();
        $this->assertNotNull($moved->lesson_sequence_id);
        $this->assertSame($moved->contentFingerprint(), $moved->sequence_content_hash);
        // Nada se perdeu: o mesmo multiset de conteúdos (texto, recursos, TPC e notas).
        $this->assertSame($contentsBefore, $this->contentMultiset());
        $this->assertSame(0, LessonPlan::withoutGlobalScopes()->count());
        // Só as duas sinalizadas ficam no Natal.
        $flagged = [$this->key($this->normal, null, '2026-12-23 10:30'), $this->key($this->support, null, '2026-12-25 11:30')];
        sort($flagged);
        $this->assertSame($flagged, $this->windowKeys('2026-12-19', '2027-01-03'));
        $this->assertSame($oldMarks, $this->marks());
        $this->assertSame($closedBefore, $this->closedSnapshot());

        // (4, parte 2) continuam sinalizadas e intactas
        $this->assertSame($manualBefore, $this->attributesOf($this->lesson($this->support, '2026-12-25 11:30')));
        $this->assertSame($manualNumber, $this->lesson($this->support, '2026-12-25 11:30')->lesson_number);
        $this->assertTrue((bool) $this->lesson($this->support, '2026-12-25 11:30')->lesson_number_manual);
        $this->assertReview($this->teacher, [$this->lesson($this->support, '2026-12-25 11:30')->ulid], ['A' => 0, 'B' => 0, 'C' => 1], 'manual_number');
        $this->assertReview($this->colleague, [$this->lesson($this->normal, '2026-12-23 10:30')->ulid], ['A' => 0, 'B' => 0, 'C' => 1], 'taught');
        $this->assertSame(1, LessonAttendance::withoutGlobalScopes()->count());

        // ------------------------------------------------------------------
        // (8) Idempotência
        // ------------------------------------------------------------------
        $stable = $this->state();

        foreach ([$this->teacher, $this->colleague] as $user) {
            $again = $this->record($this->http($user)->postJson('/lessons/calendar-conflicts/preview'));
            $this->assertFalse($again->json('changes_anything'));
            $this->assertSame(['A' => 0, 'B' => 0, 'C' => 1], $again->json('counts'));
            $done = $this->record($this->http($user)->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $again->json('plan_token')]));
            $done->assertOk();
            $this->assertSame(0, $done->json('totals.retired'));
            $this->assertSame(0, $done->json('totals.shifted'));
        }

        $this->assertSame($stable, $this->state(), 'Repetir não escreve nada.');
        // O responsável só vê (e só toca) os dias da exceção que está a gravar: as
        // duas C do Natal não são do Carnaval, e já estão no aviso dos professores.
        $ownerAgain = $this->record($this->http($this->teacher)->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'create'] + $this->carnival()));
        $this->assertSame(['A' => 0, 'B' => 0, 'C' => 0], $ownerAgain->json('counts'));

        // ------------------------------------------------------------------
        // (9) Um token antigo é recusado e não escreve nada
        // ------------------------------------------------------------------
        $exceptionsBefore = AcademicCalendarException::withoutGlobalScopes()->count();
        $this->http($this->teacher)->postJson("/academic-years/{$year->ulid}/exceptions", $payload + ['reconcile' => true, 'plan_token' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($exceptionsBefore, AcademicCalendarException::withoutGlobalScopes()->count());

        $fresh = (string) $this->record($this->http($this->teacher)->postJson('/lessons/calendar-conflicts/preview'))->json('plan_token');
        $this->inTenant($this->organization, fn () => LessonSummary::create([
            'lesson_id' => $this->lesson($this->support, '2026-12-25 11:30')->id,
            'content' => 'SENTINELA-NOVO',
        ]));
        $afterEdit = $this->state();
        $this->http($this->teacher)->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $fresh])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_token');
        $this->assertSame($afterEdit, $this->state());
        $this->deleteSentinel();

        // ------------------------------------------------------------------
        // (5) Abrir as semanas de Natal outra vez: nada renasce
        // ------------------------------------------------------------------
        $beforeReopen = $this->lessonKeys();
        $this->materializeAll();
        $this->assertSame($beforeReopen, $this->lessonKeys(), 'Nenhuma aula renasce nos dias de exceção.');
        $this->assertSame($this->expectedKeys([['2026-12-19', '2027-01-03']], keep: [
            $this->key($this->normal, null, '2026-12-23 10:30'),
            $this->key($this->support, null, '2026-12-25 11:30'),
        ]), $this->lessonKeys());

        // ------------------------------------------------------------------
        // (6) Encurtar a interrupção: 01/01 a 03/01 voltam a ser letivos
        // ------------------------------------------------------------------
        $short = $payload;
        $short['ends_on'] = '2026-12-31';
        $shorten = $this->record($this->http($this->teacher)->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'update', 'exception' => $christmas->ulid] + $short));
        $shorten->assertOk();
        $this->assertSame(['2027-01-01', '2027-01-02', '2027-01-03'], $shorten->json('freed_days'));
        $this->assertSame(0, $shorten->json('existing_on_freed_days.count'));
        $before = $this->allLessonAttributes();
        $this->http($this->teacher)->putJson("/academic-years/{$year->ulid}/exceptions/{$christmas->ulid}", $short + ['reconcile' => true, 'plan_token' => $shorten->json('plan_token')])->assertRedirect();
        $this->assertSame('2026-12-31', AcademicCalendarException::withoutGlobalScopes()->firstOrFail()->ends_on->toDateString());
        $this->assertSame($before, $this->allLessonAttributes(), 'Encurtar não toca nas aulas existentes.');

        $this->materializeAll();
        $born = array_values(array_diff($this->lessonKeys(), $this->keysOf($before)));
        $this->assertSame([$this->key($this->support, null, '2027-01-01 11:30')], $born, 'Nasce só a de sexta 01/01, sem duplicados.');
        $this->assertSame($this->lessonKeys(), array_values(array_unique($this->lessonKeys())));
        $this->assertLessonsUnchanged($before, 'Encurtar e abrir as semanas não toca nas aulas existentes.');
        $this->assertSame($oldMarks, $this->marks());

        // ------------------------------------------------------------------
        // (7) Eliminar a interrupção: tudo regressa, sem duplicados
        // ------------------------------------------------------------------
        $delete = $this->record($this->http($this->teacher)->postJson("/academic-years/{$year->ulid}/exceptions/impact", ['action' => 'delete', 'exception' => $christmas->ulid]));
        $delete->assertOk();
        $this->assertCount(13, $delete->json('freed_days'));
        $this->assertSame(2, $delete->json('existing_on_freed_days.count'));
        $beforeDelete = $this->allLessonAttributes();
        $this->http($this->teacher)->deleteJson("/academic-years/{$year->ulid}/exceptions/{$christmas->ulid}", ['reconcile' => true, 'plan_token' => $delete->json('plan_token')])->assertRedirect();
        $this->assertSame(0, AcademicCalendarException::withoutGlobalScopes()->count());

        $this->materializeAll();
        $keys = $this->lessonKeys();
        $this->assertSame($this->expectedKeys([]), $keys, 'O horário inteiro está de volta: nada em falta e nenhuma aula a mais.');
        $this->assertSame($keys, array_values(array_unique($keys)));

        $this->assertLessonsUnchanged($beforeDelete, 'Eliminar a interrupção não toca nas aulas existentes.');

        $this->assertSame($closedBefore, $this->closedSnapshot(), 'Os números das lecionadas nunca mudaram, do princípio ao fim.');
        $this->assertSame($contentsBefore, $this->contentMultiset());
        $this->assertSame($oldMarks, $this->marks());

        // Nunca texto pedagógico nas respostas JSON.
        $this->assertNotEmpty($this->bodies);

        foreach ($this->bodies as $body) {
            $this->assertStringNotContainsString(self::NO_CONTENT, $body);
        }
    }

    // ------------------------------------------------------------------
    // O mundo
    // ------------------------------------------------------------------

    private function buildWorld(): void
    {
        $this->colleague = User::factory()->create();
        $this->colleague->organizations()->attach($this->organization, ['joined_at' => now()]);

        $groupOne = $this->makeGroup('T1');
        $groupTwo = $this->makeGroup('T2');
        $this->t1 = $groupOne->id;
        $this->t2 = $groupTwo->id;
        $yearId = $this->schoolClass->academic_year_id;

        $this->support = $this->inTenant($this->organization, function () use ($yearId): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->support()->create(['academic_year_id' => $yearId, 'label' => 'Apoio 7.º A']);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });
        $this->normal = $this->inTenant($this->organization, function () use ($yearId): SchoolClass {
            $class = SchoolClass::factory()->recycle($this->organization)->create(['academic_year_id' => $yearId, 'label' => '8.º B']);
            $class->teachers()->attach($this->colleague, ['role' => 'owner']);

            return $class;
        });

        $unit = (string) Str::ulid();
        $this->slot($this->schoolClass->id, $groupOne->id, 1, '09:30', ['split_lesson_key' => $unit]);
        $this->slot($this->schoolClass->id, $groupTwo->id, 2, '09:30', ['split_lesson_key' => $unit]);
        $this->slot($this->support->id, null, 5, '11:30');
        $this->slot($this->normal->id, null, 2, '11:00');
        $wednesday = $this->slot($this->normal->id, null, 3, '10:30');

        // A marca antiga: uma aula que o professor eliminou de propósito (sem motivo), fora do Natal.
        $this->cancelledKey = $this->key($this->normal, null, '2026-11-04 10:30');
        $this->inTenant($this->organization, fn () => CancelledLessonOccurrence::create([
            'class_id' => $this->normal->id,
            'class_group_id' => null,
            'recurring_lesson_slot_id' => $wednesday->id,
            'occurs_at' => '2026-11-04 10:30:00',
            'cancelled_by' => $this->teacher->id,
        ]));

        $this->materializeAll();

        // Setembro a 11/12: lecionadas, e uma de professor ausente (registada ANTES, como na vida real).
        $absent = $this->lesson($this->normal, '2026-11-11 10:30');
        $this->inTenant($this->organization, fn () => app(RecordLessonOutcome::class)->execute($absent, LessonOutcome::TeacherAbsent, $this->colleague, TeacherAbsenceReason::Training));
        $this->inTenant($this->organization, fn () => Lesson::query()
            ->where('starts_at', '<', '2026-12-14 00:00:00')->whereKeyNot($absent->id)
            ->update(['status' => LessonStatus::Taught->value, 'outcome' => LessonOutcome::Taught->value]));

        // Planeamento em preparação (B).
        $subject = $this->inTenant($this->organization, fn (): Subject => Subject::factory()->recycle($this->organization)->create());
        $sequence = $this->inTenant($this->organization, function () use ($subject): LessonSequence {
            $sequence = LessonSequence::create([
                'user_id' => $this->teacher->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $this->schoolClass->academic_year_id,
                'name' => 'Unidade de Natal',
            ]);
            $sequence->items()->create(['position' => 1, 'summary' => 'SENTINELA-ITEM']);

            return $sequence;
        });
        $summary = $this->prepare($this->schoolClass, '2026-12-21 09:30', 'SENTINELA-P1', ['resources' => 'SENTINELA-R1', 'homework' => 'SENTINELA-TPC1', 'private_notes' => 'SENTINELA-NP1']);
        $this->inTenant($this->organization, fn () => $summary->forceFill([
            'lesson_sequence_id' => $sequence->id,
            'lesson_sequence_item_id' => $sequence->items()->firstOrFail()->id,
            'sequence_content_hash' => $summary->contentFingerprint(),
        ])->save());
        $this->prepare($this->schoolClass, '2026-12-28 09:30', 'SENTINELA-P2');
        $this->prepare($this->schoolClass, '2026-12-29 09:30', 'SENTINELA-U1');
        $this->prepare($this->schoolClass, '2027-01-05 09:30', 'SENTINELA-Q');
        $this->prepare($this->normal, '2026-12-30 10:30', 'SENTINELA-N1');

        // Um número indicado à mão (C) e uma lecionada por engano a 23/12 com assiduidade (C).
        $this->inTenant($this->organization, fn () => Lesson::query()
            ->whereKey($this->lesson($this->support, '2026-12-25 11:30')->id)
            ->update(['lesson_number_manual' => true]));
        $mistake = $this->lesson($this->normal, '2026-12-23 10:30');
        $enrollment = $this->enroll('Ana Silva', [], $this->normal);
        $this->inTenant($this->organization, function () use ($mistake, $enrollment): void {
            Lesson::query()->whereKey($mistake->id)->update([
                'status' => LessonStatus::Taught->value,
                'outcome' => LessonOutcome::Taught->value,
                'attendance_recorded_at' => now(),
                'attendance_recorded_by' => $this->colleague->id,
            ]);
            LessonAttendance::create([
                'lesson_id' => $mistake->id, 'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id, 'status' => AttendanceStatus::Present,
            ]);
        });
    }

    /** @param  array<string, mixed>  $extra */
    private function slot(int $classId, ?int $groupId, int $weekday, string $time, array $extra = []): RecurringLessonSlot
    {
        $this->slots[] = [$classId, $groupId, $weekday, $time];

        return $this->makeSlot([
            'class_id' => $classId,
            'class_group_id' => $groupId,
            'day_of_week' => $weekday,
            'starts_at' => $time,
            'ends_at' => CarbonImmutable::parse($time)->addMinutes(50)->format('H:i'),
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ] + $extra);
    }

    /** @param  array<string, mixed>  $extra */
    private function prepare(SchoolClass $class, string $at, string $content, array $extra = []): LessonSummary
    {
        $lesson = $this->lesson($class, $at);

        $this->inTenant($this->organization, fn () => Lesson::query()->whereKey($lesson->id)->update(['status' => LessonStatus::Prepared->value]));

        return $this->inTenant($this->organization, fn (): LessonSummary => LessonSummary::create(['lesson_id' => $lesson->id, 'content' => $content] + $extra));
    }

    private function materializeAll(): void
    {
        foreach ([$this->schoolClass, $this->support, $this->normal] as $class) {
            $this->inTenant($this->organization, fn () => app(MaterializeLessonsForRange::class)->execute(
                $class,
                CarbonImmutable::parse(self::FROM),
                CarbonImmutable::parse(self::TO),
                $this->teacher,
            ));
        }
    }

    private function year(): AcademicYear
    {
        return $this->inTenant($this->organization, fn (): AcademicYear => AcademicYear::query()->findOrFail($this->schoolClass->academic_year_id));
    }

    /** @return array<string, mixed> */
    private function christmas(): array
    {
        return ['type' => 'holiday', 'title' => 'Interrupção de Natal', 'starts_on' => '2026-12-19', 'ends_on' => '2027-01-03', 'note' => null];
    }

    /** @return array<string, mixed> */
    private function carnival(): array
    {
        return ['type' => 'holiday', 'title' => 'Carnaval', 'starts_on' => '2027-02-08', 'ends_on' => '2027-02-10', 'note' => null];
    }

    // ------------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------------

    private function http(User $user): self
    {
        return $this->actingAs($user)->withSession(['organization_id' => $this->organization->id]);
    }

    private function record(TestResponse $response): TestResponse
    {
        $this->bodies[] = (string) $response->getContent();

        return $response;
    }

    /**
     * @param  list<string>  $reviewUlids
     * @param  array{A: int, B: int, C: int}  $counts
     */
    private function assertReview(User $user, array $reviewUlids, array $counts, string $reason): void
    {
        $index = $this->record($this->http($user)->getJson('/lessons/calendar-conflicts'));
        $index->assertOk();
        $this->assertSame($counts, $index->json('counts'));
        $this->assertSame($reviewUlids, array_column($index->json('review_lessons'), 'ulid'));
        $this->assertContains($reason, array_column($index->json('review_lessons.0.reasons'), 'code'));
        $this->assertSame(['Interrupção de Natal'], $index->json('review_lessons.0.exception_titles'));
    }

    private function regularize(User $user, int $shifted): void
    {
        $preview = $this->record($this->http($user)->postJson('/lessons/calendar-conflicts/preview'));
        $preview->assertOk();
        $this->assertSame($shifted, $preview->json('totals.shifted'));

        $done = $this->record($this->http($user)->postJson('/lessons/calendar-conflicts/regularize', ['plan_token' => $preview->json('plan_token')]));
        $done->assertOk();
        $this->assertSame($shifted, $done->json('totals.shifted'));
    }

    // ------------------------------------------------------------------
    // Leituras
    // ------------------------------------------------------------------

    private function lesson(SchoolClass $class, string $at): Lesson
    {
        return $this->lessonOrNull($class, $at) ?? throw new \RuntimeException('Sem aula em '.$at);
    }

    private function lessonOrNull(SchoolClass $class, string $at): ?Lesson
    {
        return Lesson::withoutGlobalScopes()->where('class_id', $class->id)->where('starts_at', $at.':00')->first();
    }

    private function numberAt(SchoolClass $class, string $at): ?int
    {
        return $this->lesson($class, $at)->lesson_number;
    }

    private function key(SchoolClass $class, ?int $groupId, string $at): string
    {
        return $class->id.'|'.($groupId ?? '-').'|'.$at;
    }

    /** @return list<string> */
    private function lessonKeys(): array
    {
        $keys = Lesson::withoutGlobalScopes()->get()->map(
            fn (Lesson $lesson): string => $lesson->class_id.'|'.($lesson->class_group_id ?? '-').'|'.$lesson->starts_at->format('Y-m-d H:i'),
        )->all();
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private function windowKeys(string $from, string $to): array
    {
        return array_values(array_filter($this->lessonKeys(), function (string $key) use ($from, $to): bool {
            $day = substr(explode('|', $key)[2], 0, 10);

            return $day >= $from && $day <= $to;
        }));
    }

    /**
     * O horário inteiro, tal como devia estar materializado.
     *
     * @param  list<array{0: string, 1: string}>  $excludedRanges
     * @param  list<string>  $keep
     * @return list<string>
     */
    private function expectedKeys(array $excludedRanges, array $keep = []): array
    {
        $keys = $keep;

        for ($day = CarbonImmutable::parse(self::FROM); $day->toDateString() <= self::TO; $day = $day->addDay()) {
            foreach ($excludedRanges as [$from, $to]) {
                if ($day->toDateString() >= $from && $day->toDateString() <= $to) {
                    continue 2;
                }
            }

            foreach ($this->slots as [$classId, $groupId, $weekday, $time]) {
                if ($day->dayOfWeekIso === $weekday) {
                    $keys[] = $classId.'|'.($groupId ?? '-').'|'.$day->toDateString().' '.$time;
                }
            }
        }

        $keys = array_values(array_unique(array_diff($keys, [$this->cancelledKey])));
        sort($keys);

        return $keys;
    }

    /**
     * As aulas que já existiam ficam como estavam. A única exceção são os números
     * (e o carimbo) das ABERTAS: quando nasce uma aula antes delas, a derivação
     * renumera-as — as fechadas nunca.
     *
     * @param  array<int, array<string, mixed>>  $before
     */
    private function assertLessonsUnchanged(array $before, string $message): void
    {
        $now = $this->allLessonAttributes();

        foreach ($before as $id => $was) {
            $is = $now[$id] ?? null;
            $this->assertNotNull($is, 'A aula '.$id.' desapareceu. '.$message);

            if ($was['outcome'] === null && $was['status'] !== LessonStatus::Taught->value) {
                unset($was['lesson_number'], $is['lesson_number'], $was['updated_at'], $is['updated_at']);
            }

            $this->assertSame($was, $is, 'A aula '.$id.' mudou. '.$message);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function allLessonAttributes(): array
    {
        return Lesson::withoutGlobalScopes()->orderBy('id')->get()->mapWithKeys(
            fn (Lesson $lesson): array => [$lesson->id => $lesson->getAttributes()],
        )->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $attributes
     * @return list<string>
     */
    private function keysOf(array $attributes): array
    {
        $ids = array_keys($attributes);
        $keys = Lesson::withoutGlobalScopes()->whereIn('id', $ids)->get()->map(
            fn (Lesson $lesson): string => $lesson->class_id.'|'.($lesson->class_group_id ?? '-').'|'.$lesson->starts_at->format('Y-m-d H:i'),
        )->all();
        sort($keys);

        return $keys;
    }

    /** @return array<string, mixed> */
    private function attributesOf(Lesson $lesson): array
    {
        return Lesson::withoutGlobalScopes()->findOrFail($lesson->id)->getAttributes();
    }

    /** As lecionadas e fechadas, por inteiro — o histórico nunca muda. */
    private function closedSnapshot(): array
    {
        return array_filter(
            $this->allLessonAttributes(),
            fn (array $attributes): bool => $attributes['outcome'] !== null || $attributes['status'] === LessonStatus::Taught->value,
        );
    }

    /** @return list<string> */
    private function contentMultiset(): array
    {
        $rows = LessonSummary::withoutGlobalScopes()->get()->map(
            fn (LessonSummary $summary): string => (string) json_encode([$summary->content, $summary->resources, $summary->homework, $summary->private_notes]),
        )->all();
        sort($rows);

        return $rows;
    }

    /** @return array<string, string> */
    private function contentByStart(): array
    {
        $map = [];

        foreach (LessonSummary::withoutGlobalScopes()->get() as $summary) {
            $map[Lesson::withoutGlobalScopes()->findOrFail($summary->lesson_id)->starts_at->format('Y-m-d H:i')] = $summary->content;
        }

        ksort($map);

        return $map;
    }

    /** @return list<array<string, mixed>> */
    private function marks(): array
    {
        return CancelledLessonOccurrence::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all();
    }

    private function state(): array
    {
        return [
            'lessons' => $this->allLessonAttributes(),
            'summaries' => LessonSummary::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            'plans' => LessonPlan::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            'exceptions' => AcademicCalendarException::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            'marks' => $this->marks(),
            'attendances' => LessonAttendance::withoutGlobalScopes()->orderBy('id')->get()->map->getAttributes()->all(),
            'audit' => AuditEvent::withoutGlobalScopes()->count(),
        ];
    }

    private function deleteSentinel(): void
    {
        LessonSummary::withoutGlobalScopes()->where('content', 'SENTINELA-NOVO')->delete();
    }
}
